<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\TicketCheckoutRedirect;
use App\DataTransferObjects\Billing\TicketVolumeTier;
use App\Enums\Billing\TicketCheckoutSessionStatus;
use App\Exceptions\Billing\CheckoutInProgressException;
use App\Exceptions\Billing\StaleCheckoutAttemptException;
use App\Models\Billing\TicketCheckoutSession;
use App\Models\Billing\TicketVolumePrice;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Webmozart\Assert\Assert;

/**
 * チケットスポット購入の冪等 Checkout 開始 (二重課金防止の冪等マシン)。
 *
 * 防御 4 層 (概念設計 §C):
 * 1. attempt_token 冪等 (UNIQUE(org, attempt_token) + Stripe idempotency key purchase:{token})
 * 2. live pending dedup (同 (org, user) の決済待ち session を 1 本に収束)
 * 3. INSERT unique 違反の re-read 収束 (並行 race を 500 にしない)
 * 4. webhook 冪等 + 台帳 idempotency_key UNIQUE (StripeWebhookProcessor / TicketLedgerService)
 *
 * crash 復旧特性: Stripe 作成成功後・DB 保存前に落ちても、同一 attempt の再試行は
 * Stripe idempotency key で同一 session に収束し、その時点で DB 行が記録される。
 * DB 行が引けない completed webhook は付与しない (fail-closed) ため、
 * 未追跡 session が黙って付与されることはない。
 */
class TicketCheckoutService
{
    /** org 単位直列化ロックの保持秒数 (Stripe 呼び出し込みの上限) */
    private const int LOCK_SECONDS = 10;

    /** ロック取得の待機秒数 (超過は fail-closed でエラー着地) */
    private const int LOCK_WAIT_SECONDS = 5;

    public function __construct(private readonly TicketCheckoutGateway $gateway) {}

    /**
     * 冪等 checkout 開始。
     *
     * 業務エラーは CheckoutInProgressException / StaleCheckoutAttemptException /
     * TicketVolumeTierUnavailableException → controller が back()->with('error') に変換する。
     */
    public function startCheckout(Organization $organization, User $user, int $count, string $attemptToken): TicketCheckoutRedirect
    {
        // tier 解決はサーバが権威 (fail-closed / floor 強制 / production 未 sync 拒否)
        $tier = TicketVolumePrice::currentTierFor($count);

        try {
            $redirect = Cache::lock("billing:ticket-checkout:{$organization->id}", self::LOCK_SECONDS)
                ->block(
                    self::LOCK_WAIT_SECONDS,
                    fn (): TicketCheckoutRedirect => $this->startCheckoutLocked($organization, $user, $count, $tier, $attemptToken),
                );
            Assert::isInstanceOf($redirect, TicketCheckoutRedirect::class); // block() の返り値は callback の返り値 (mixed 宣言のため絞り込む)

            return $redirect;
        } catch (LockTimeoutException $e) {
            // fail-closed: ロックなし実行へフォールバックしない
            throw new CheckoutInProgressException('直前の購入手続きが進行中です。数秒おいて再度お試しください。', previous: $e);
        }
    }

    /**
     * Stripe success_url 帰還の purchased 表示を検証する (表示専用・fail-closed)。
     *
     * session_id が current org の checkout 行と一致する時のみ true。org 切替中の帰還や
     * 任意 query (?purchased=1) では購入完了バナーを出さない (課金付与は webhook が真実源
     * のため、ここは表示の正確性のみを守る)。
     */
    public function confirmsPurchaseReturn(Organization $organization, ?string $sessionId): bool
    {
        if ($sessionId === null || $sessionId === '') {
            return false;
        }

        return TicketCheckoutSession::query()
            ->where('organization_id', $organization->id)
            ->where('stripe_session_id', $sessionId)
            ->exists();
    }

    /**
     * P8b (tc-5): 購入画面の状態機械が読む「復帰可能な購入」を解決する。
     *
     * 2 段取得 (会計には一切触れない):
     *   (1) live pending (Pending / expires_at 未来 / checkout_url 非空) の最新 → resume
     *   (2) 窓内 completed (completed_at > now - window) の最新 → completed
     *   (3) いずれも無ければ null → normal
     *
     * いずれも organization_id + initiated_by_user_id スコープ。resumeUrl は Stripe Checkout の
     * 直リンクで購入 gate を迂回しうるため、他 user の session を絶対に露出させない。
     */
    public function resolveResumablePurchase(Organization $organization, int $userId, int $windowMinutes): ?TicketCheckoutSession
    {
        $now = CarbonImmutable::now();

        $livePending = TicketCheckoutSession::query()
            ->where('organization_id', $organization->id)
            ->where('initiated_by_user_id', $userId)
            ->where('status', TicketCheckoutSessionStatus::Pending)
            ->where('expires_at', '>', $now)
            ->where('checkout_url', '<>', '')
            ->latest('id')
            ->first();

        if ($livePending !== null) {
            return $livePending;
        }

        return TicketCheckoutSession::query()
            ->where('organization_id', $organization->id)
            ->where('initiated_by_user_id', $userId)
            ->where('status', TicketCheckoutSessionStatus::Completed)
            ->where('completed_at', '>', $now->subMinutes($windowMinutes))
            ->latest('id')
            ->first();
    }

    private function startCheckoutLocked(
        Organization $organization,
        User $user,
        int $count,
        TicketVolumeTier $tier,
        string $attemptToken,
    ): TicketCheckoutRedirect {
        $now = CarbonImmutable::now();

        // (0) 期限切れ pending の回収: dedup の前に expired へ遷移
        //     (Stripe 側 24h expire 済みの死 URL を永続 replay しない。pin 値で決定的 = Stripe 照会不要)
        TicketCheckoutSession::query()
            ->where('organization_id', $organization->id)
            ->where('status', TicketCheckoutSessionStatus::Pending)
            ->where('expires_at', '<=', $now)
            ->update(['status' => TicketCheckoutSessionStatus::Expired->value]);

        // (1) 同一 attempt_token: 同 count live pending → replay / completed → 受付済み / それ以外 → stale
        $sameAttempt = TicketCheckoutSession::query()
            ->where('organization_id', $organization->id)
            ->where('attempt_token', $attemptToken)
            ->first();
        if ($sameAttempt !== null) {
            if ($sameAttempt->status === TicketCheckoutSessionStatus::Completed) {
                return new TicketCheckoutRedirect(url: null, alreadyCompleted: true);
            }
            if ($sameAttempt->isLivePending($now) && $sameAttempt->ticket_count === $count) {
                return new TicketCheckoutRedirect(url: $sameAttempt->checkout_url, alreadyCompleted: false);
            }

            throw new StaleCheckoutAttemptException('購入手続きの有効期限が切れました。画面を再読み込みして再度お試しください。');
        }

        // (2) live pending dedup (org, user): 同 count → replay / 別 count → Stripe expire 成功時のみ
        //     expired 化して続行 (別タブ・新 token でも live session は 1 本)
        $livePending = TicketCheckoutSession::query()
            ->where('organization_id', $organization->id)
            ->where('initiated_by_user_id', $user->id)
            ->where('status', TicketCheckoutSessionStatus::Pending)
            ->where('expires_at', '>', $now)
            ->latest('id')
            ->first();
        if ($livePending !== null) {
            if ($livePending->ticket_count === $count) {
                return new TicketCheckoutRedirect(url: $livePending->checkout_url, alreadyCompleted: false);
            }
            // expire 失敗 (gateway throw) は新規作成せずエラー着地 (二重 live session を作らない)
            $status = $this->gateway->expireCheckoutSession($livePending->stripe_session_id);
            if ($status === 'complete') {
                throw new CheckoutInProgressException('直前の購入が処理中です。数秒おいて再度お試しください。');
            }
            $livePending->status = TicketCheckoutSessionStatus::Expired;
            $livePending->save();
        }

        // (3) Stripe 作成 (idempotency key = purchase:{attemptToken}) → DB 記録。
        //     metadata は照合専用 (認可・org 解決の判断には一切使わない。真実源は ticket_checkout_sessions 行)。
        //     tenant キー不信の誤読を防ぐため organization_id ではなく非権限キー名 org_ref を使う。
        //     success_url の {CHECKOUT_SESSION_ID} は Stripe 側で実 session id に置換される
        //     (帰還時に confirmsPurchaseReturn() が current org の自 DB 行と照合し、
        //     org 切替中の誤バナー・任意 query による purchased 偽装を防ぐ)。
        $created = $this->gateway->createTicketCheckout(
            $organization,
            $tier->stripePriceId,
            $count,
            route('billing.tickets.show', ['purchased' => 1]).'&session_id={CHECKOUT_SESSION_ID}',
            route('billing.tickets.show'),
            'purchase:'.$attemptToken,
            [
                'purpose' => 'ticket_purchase',
                'org_ref' => (string) $organization->id,
                'count' => (string) $count,
            ],
        );

        try {
            $session = new TicketCheckoutSession;
            $session->organization()->associate($organization);
            $session->initiatedBy()->associate($user);
            $session->ticket_count = $count;
            $session->unit_amount = $tier->unitAmount;
            $session->currency = $tier->currency; // tier snapshot の currency pin (webhook 照合の出典)
            $session->stripe_session_id = $created->sessionId;
            $session->attempt_token = $attemptToken;
            $session->checkout_url = $created->url;
            $session->status = TicketCheckoutSessionStatus::Pending;
            $session->expires_at = $created->expiresAt;
            $session->save();
        } catch (UniqueConstraintViolationException) {
            // 並行 race / Stripe idempotency replay: 既存行 re-read で replay / stale に収束 (500 にしない)。
            // orWhere は使わず 2 段の確定クエリで引く (括弧化漏れによる cross-org 混線を構造的に防ぐ):
            //  (1) UNIQUE(org, attempt_token) スコープ → 高々 1 行
            //  (2) global UNIQUE(stripe_session_id) → 引けても自 org 行でなければ replay しない
            $existing = TicketCheckoutSession::query()
                ->where('organization_id', $organization->id)
                ->where('attempt_token', $attemptToken)
                ->first();
            if ($existing === null) {
                $existing = TicketCheckoutSession::query()
                    ->where('stripe_session_id', $created->sessionId)
                    ->first();
                if ($existing !== null && $existing->organization_id !== $organization->id) {
                    $existing = null; // 自 org 行以外は絶対に replay しない (fail-closed)
                }
            }
            if ($existing !== null
                && $existing->isLivePending(CarbonImmutable::now())
                && $existing->ticket_count === $count) {
                return new TicketCheckoutRedirect(url: $existing->checkout_url, alreadyCompleted: false);
            }

            throw new StaleCheckoutAttemptException('購入手続きをやり直してください。画面を再読み込みして再度お試しください。');
        }

        return new TicketCheckoutRedirect(url: $created->url, alreadyCompleted: false);
    }
}
