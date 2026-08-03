<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\CheckoutSessionDto;
use App\DataTransferObjects\Billing\ExternalBillingRedirect;
use App\DataTransferObjects\Billing\SubscriptionEntitlementDto;
use App\Enums\Billing\EntitlementDeniedReason;
use App\Enums\Billing\PlanPriceKind;
use App\Enums\Billing\ScheduleSetupStatus;
use App\Enums\Billing\SignupFundingChoice;
use App\Enums\Billing\SubscriptionState;
use App\Enums\CheckoutIntent;
use App\Enums\CheckoutSessionStatus;
use App\Enums\PlanCode;
use App\Exceptions\Billing\CheckoutInProgressException;
use App\Exceptions\Billing\StaleCheckoutAttemptException;
use App\Exceptions\Billing\StripePriceNotSyncedException;
use App\Exceptions\Billing\SubscriptionAttemptPlanMismatchException;
use App\Models\Billing\BillingCheckoutSession;
use App\Models\Billing\Plan;
use App\Models\Billing\PlanPrice;
use App\Models\Billing\Subscription;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * Subscription (契約) の状態管理サービス。
 *
 * Stripe への I/O は Gateway 経由のみで、本クラスは entitlement の導出・webhook 受信時の
 * 状態同期・checkout の前処理に責務を絞る。
 */
class SubscriptionService
{
    /** organizations.plan_code を同期する subscription status (それ以外では既存値を維持する) */
    private const array ACTIVE_SUBSCRIPTION_STATUSES = ['active', 'trialing'];

    public function __construct(
        private readonly StripeGatewayInterface $gateway,
        private readonly TicketLedgerService $tickets,
    ) {}

    /**
     * paid サブスク成立 (customer.subscription.created) 時の初回無償チケット付与。
     *
     * 付与は「org 単位で生涯 1 回」: 真実源は `organizations.signup_tickets_granted_at` で、
     * org 行 lock 下の条件付き UPDATE を先取できた経路のみ grant する
     * (free 有効化経路 PersonalPlanService::activate と共用の真実源・同型の claim パターン)。
     * 解約→再契約 (別 subscription id) でも marker が立っているため再付与されない。
     *
     * claim と grant は同一 transaction に閉じる。grant が失敗したら marker ごと rollback され、
     * 「marker だけ立って永久に付与されない org」を作らない。
     *
     * 冪等キー `signup_grant:{stripeSubId}` は監査上の由来表現であり、二重付与の防波堤は
     * marker (主) と ticket_ledger_entries の部分 UNIQUE index
     * (organization_id WHERE idempotency_key LIKE 'signup_grant:%') (保険) の二重防御。
     *
     * subscription 行側の marker は持たない (D30): AI-CUE では subscriptions 行の作成は Cashier の
     * WebhookController が担い、本経路 (WebhookReceived listener) はそれより先に走るため
     * created 時点で行が存在せず、列を足しても恒久 NULL にしかならない。
     */
    public function grantSignupInitialTickets(Organization $org, string $stripeSubId): void
    {
        Assert::stringNotEmpty($stripeSubId);

        DB::transaction(function () use ($org, $stripeSubId): void {
            // org 行 lock で free 有効化経路 (PersonalPlanService::activate) との付与競合を直列化。
            DB::table('organizations')->where('id', $org->getKey())->lockForUpdate()->get();

            $claimed = DB::table('organizations')
                ->where('id', $org->getKey())
                ->whereNull('signup_tickets_granted_at')
                ->update(['signup_tickets_granted_at' => CarbonImmutable::now()]);

            if ($claimed === 1) {
                $this->tickets->grantSignupGrant($org, 'signup_grant:'.$stripeSubId);
            }
        });
    }

    /**
     * subscription の利用可否 (entitlement) を確定する **唯一の経路**。
     *
     * `SubscriptionState::fromSubscription`/`grantsAccess` を直接参照して可否を決めてはならない。
     * 本メソッドが state + PM 有無 + trial_ends_at + Stripe status snapshot を合成して最終確定する。
     *
     *   entitled = state.grantsAccess()
     *              AND NOT (trial_ends_at <= now AND !has_payment_method)   // trial 終了 & カード無し
     *              AND status != paused                                     // Stripe 確定の read-only
     *
     * - Paused: grantsAccess=false で否定 (reason=Paused)。
     * - trial 終了 & PM 無し: webhook 前 (Stripe がまだ paused 化していない) でも先回りで否定する
     *   (reason=TrialEndedWithoutPaymentMethod)。
     * - PastDue (PM 有): grantsAccess=true かつ trial 条件に該当しないため entitled=true (請求失敗中も利用継続)。
     * - PM 無し past_due (= trial 後カード無し dunning): trial_ends_at<=now & !has_payment_method で否定。
     */
    public function deriveEntitlement(Subscription $sub): SubscriptionEntitlementDto
    {
        $state = SubscriptionState::fromSubscription($sub);

        if (! $state->grantsAccess()) {
            $reason = $state === SubscriptionState::Paused
                ? EntitlementDeniedReason::Paused
                : EntitlementDeniedReason::NoActiveSubscription;

            return SubscriptionEntitlementDto::denied($state, $reason);
        }

        // trial 終了後カード未登録 → 利用不可 (webhook の paused 化前でも先回り遮断)。
        $now = CarbonImmutable::now();
        $trialEnded = $sub->trial_ends_at !== null
            && CarbonImmutable::instance($sub->trial_ends_at)->lessThanOrEqualTo($now);
        if ($trialEnded && ! $sub->has_payment_method) {
            return SubscriptionEntitlementDto::denied(
                $state,
                EntitlementDeniedReason::TrialEndedWithoutPaymentMethod,
            );
        }

        // status=paused は grantsAccess で既に弾かれているが、防御的に二重で確認する。
        if ($sub->stripe_status === 'paused') {
            return SubscriptionEntitlementDto::denied($state, EntitlementDeniedReason::Paused);
        }

        return SubscriptionEntitlementDto::granted($state);
    }

    /**
     * Webhook (customer.subscription.created/updated/deleted) 受信時、Stripe サブスクの
     * 最新スナップショットをローカル状態へ反映する **唯一の書込経路**。
     *
     * 列の所在差の吸収 (aigenba は subscriptions.plan_code に書くが、本アプリの権威は
     * organizations.plan_code):
     * - (a) base Price から plan が解決でき **かつ** status が active/trialing のときだけ
     *   `organizations.plan_code` を同期する (未知 Price は受理のみ)。
     * - (b) `subscriptions` 行が存在すれば lockForUpdate の上で Stripe 由来の列を更新する。
     *   **行の作成は行わない** (作成の権威は Cashier の WebhookController。WebhookReceived は
     *   Cashier のハンドラより先に発火するため created 時点では行が無いことがあり、ここで
     *   先に作ると Cashier 側の subscription_items 生成が永久に skip される)。
     * - (c) `$terminated` (customer.subscription.deleted) では `organizations.plan_code` を
     *   null に戻し、schedule ライフサイクル列を同一トランザクションで明示クリアする
     *   (「移行」ではなく「終了」。status だけ更新・schedule 残存の一時不整合を防ぐ)。
     *
     * seat drift / schedule out-of-band drift / period 巻き戻し guard は対象列
     * (additional_seats / pending_plan_code / current_period_start) が無いため移植しない。
     *
     * @param  bool  $terminated  終了系 (deleted) のとき true。
     */
    public function applySubscriptionSnapshot(
        Organization $org,
        SubscriptionSnapshot $snap,
        bool $terminated = false,
    ): void {
        DB::transaction(function () use ($org, $snap, $terminated): void {
            $sub = Subscription::query()
                ->where('stripe_id', $snap->stripeId)
                ->lockForUpdate()
                ->first();

            if ($sub instanceof Subscription) {
                $attrs = [
                    'stripe_status' => $snap->status,
                    'stripe_price' => $snap->basePriceId,
                    'quantity' => $snap->baseQuantity,
                    'trial_ends_at' => $snap->trialEndsAt,
                    'ends_at' => $snap->endsAt,
                ];

                // period 欠落 payload では既存の current_period_end を維持する (renewal reminder の
                // 真実源を null で塗り潰さない = 現行 syncSubscriptionPeriod の早期 return と同値)。
                if ($snap->currentPeriodEnd !== null) {
                    $attrs['current_period_end'] = $snap->currentPeriodEnd;
                }

                if ($terminated) {
                    $attrs['stripe_schedule_id'] = null;
                    $attrs['schedule_setup_status'] = ScheduleSetupStatus::None;
                }

                $sub->forceFill($attrs)->save();
            }

            if ($terminated) {
                // plan_code は状態キー: webhook 同期でのみ明示代入する
                $org->plan_code = null;
                $org->save();

                return;
            }

            $planCode = $this->resolvePlanCodeFromPriceId($snap->basePriceId);
            if ($planCode === null || ! in_array($snap->status, self::ACTIVE_SUBSCRIPTION_STATUSES, true)) {
                return; // 未知 Price / 非 active 系は受理のみ (既存 plan_code を維持)
            }

            $org->plan_code = $planCode->value;
            $org->save();
        });
    }

    /**
     * has_payment_method を subscription に記録する **独立 monotonic writer**。
     *
     * `applySubscriptionSnapshot` の中に置かない理由: 早期 return 経路 (行不在等) と無関係に
     * 「決済手段の有無」だけを独立した契約として書くため。
     *
     * - has_payment_method: monotonic (true から false に戻さない)。Stripe の payload は
     *   default_payment_method を expand しない周期があり、false 側を信じると trial 終了後の
     *   遮断判定 (deriveEntitlement) が誤発火するため。
     * - 行不在 (Cashier の WebhookController が行を作る前の customer.subscription.created 等) は
     *   早期 return で no-op。最初の権威 PM 書込は最初の customer.subscription.updated に載る。
     */
    public function recordPaymentMethodSnapshot(Subscription $sub, bool $hasPaymentMethod): void
    {
        DB::transaction(function () use ($sub, $hasPaymentMethod): void {
            $fresh = Subscription::query()->lockForUpdate()->find($sub->id);
            if (! $fresh instanceof Subscription) {
                return;
            }

            // PM 有無 (monotonic: 一度 true になったら下げない)。
            if ($hasPaymentMethod && ! $fresh->has_payment_method) {
                $fresh->forceFill(['has_payment_method' => true])->save();
            }
        });
    }

    /**
     * P9: Stripe Checkout (サブスク契約) を **冪等状態機械** として開始する。
     *
     * クエリは常に `intent=subscription_start` でスコープする (`UNIQUE(organization_id,
     * intent, attempt_token)` の intent 軸が P8a のカード登録 token 空間と分ける)。
     * live 判定は `BillingCheckoutSession` の述語 (C-1) だけを使い、独自の日付比較を書かない。
     *
     * 段 0: 事前 assert + 基準時刻 / 段 1: 既存 subscription guard /
     * 段 2: 同 token 行 (別 plan → 422 / replayable → 再生 / それ以外 → stale) /
     * 段 3: 同 plan の live pending dedup (org-wide) / 段 4: 別 plan の live pending を expire /
     * 段 5: Stripe 作成 → DB 記録 / 段 6: UNIQUE 違反の re-read 収束 (500 にしない)。
     *
     * @param  SignupFundingChoice|null  $funding  T1004: 行の funding_choice に記録する
     *                                             (null = 従来の契約 checkout = PM 流用しない)
     *
     * @throws SubscriptionAttemptPlanMismatchException 同 token・別 plan の再送 (Controller が 422)
     * @throws StaleCheckoutAttemptException 期限切れ / 終端済み token の再送
     * @throws CheckoutInProgressException lock 競合 / 別 plan session の整理失敗 / 決済処理中
     * @throws StripePriceNotSyncedException production runtime で未 sync の Price のとき
     * @throws ValidationException Stripe 決済対象外のプランのとき (422)
     * @throws \InvalidArgumentException 既に有効なサブスクリプションがあるとき
     */
    public function startCheckout(
        Organization $org,
        User $user,
        Plan $plan,
        string $successUrl,
        string $cancelUrl,
        string $attemptToken,
        ?SignupFundingChoice $funding,
    ): CheckoutSessionDto {
        // 段 0: 事前 assert (lock を取る前に確定できる guard は先に倒す)
        Assert::stringNotEmpty($attemptToken, '契約手続きトークンが不正です');
        $this->assertCheckoutReady($org);

        $basePrice = $plan->currentPrice(PlanPriceKind::Base);
        Assert::isInstanceOf($basePrice, PlanPrice::class, '基本 Price 未設定のプランです');
        $this->assertPriceSynced($basePrice);
        $this->assertStripeBillablePlan($plan);

        try {
            $result = Cache::lock("billing:checkout:start:{$org->id}", 10)->block(
                5,
                fn (): CheckoutSessionDto => $this->startCheckoutLocked(
                    $org, $user, $plan, $basePrice, $successUrl, $cancelUrl, $attemptToken, $funding,
                ),
            );
            // Cache::lock()->block() は mixed を返すため型を絞る (TicketCheckoutService と同型)。
            Assert::isInstanceOf($result, CheckoutSessionDto::class);

            return $result;
        } catch (LockTimeoutException $e) {
            // fail-closed: ロックなし実行へフォールバックしない (二重 subscription を作らない)
            throw new CheckoutInProgressException('直前の操作が進行中です。数秒お待ちください。', previous: $e);
        }
    }

    /**
     * 要件 7: (org, user) スコープ外に同 token 行が在るか。
     * true なら Controller が **Gate より前に 404** を返す (存在オラクル封じ)。
     */
    public function attemptTokenIsForeign(string $attemptToken, Organization $org, User $user): bool
    {
        if ($attemptToken === '') {
            return false;
        }

        return BillingCheckoutSession::query()
            ->where('intent', CheckoutIntent::SubscriptionStart->value)
            ->where('attempt_token', $attemptToken)
            ->where(function (Builder $q) use ($org, $user): void {
                /** @var Builder<BillingCheckoutSession> $q */
                $q->where('organization_id', '!=', $org->getKey())
                    ->orWhereNull('initiated_by_user_id')
                    ->orWhere('initiated_by_user_id', '!=', $user->getKey());
            })
            ->exists();
    }

    /**
     * 指定 session id の自 org 行が Completed か (Controller の `?replayed=1` 分岐の判定源)。
     */
    public function isAttemptCompleted(Organization $org, string $stripeSessionId): bool
    {
        return BillingCheckoutSession::query()
            ->where('organization_id', $org->getKey())
            ->where('intent', CheckoutIntent::SubscriptionStart->value)
            ->where('stripe_session_id', $stripeSessionId)
            ->where('status', CheckoutSessionStatus::Completed->value)
            ->exists();
    }

    private function startCheckoutLocked(
        Organization $org,
        User $user,
        Plan $plan,
        PlanPrice $basePrice,
        string $successUrl,
        string $cancelUrl,
        string $attemptToken,
        ?SignupFundingChoice $funding,
    ): CheckoutSessionDto {
        // lock closure 先頭で基準時刻を 1 回だけ取り、段 2/3/4 の live 判定を共有述語へ通す (C-1)。
        $now = CarbonImmutable::now();
        $threshold = BillingCheckoutSession::staleThresholdAt($now);

        // 段 1: 既存 subscription guard
        $existing = $org->subscription('default');
        Assert::true(
            ! $existing instanceof Subscription || ! $existing->valid(),
            '既に有効なサブスクリプションがあります。プラン変更をご利用ください。'
        );

        // 段 2: 同 token 行 (intent=subscription_start スコープ)
        $sameAttempt = $this->subscriptionAttemptQuery($org)
            ->where('attempt_token', $attemptToken)
            ->latest('id')
            ->first();

        if ($sameAttempt instanceof BillingCheckoutSession) {
            // 要件 6 (N-1): plan 不一致は replay より **前** に判定する。
            if ($sameAttempt->plan_code !== $plan->code) {
                throw new SubscriptionAttemptPlanMismatchException(
                    'お手続きの内容が変わりました。画面を再読み込みして選び直してください。',
                );
            }
            if ($this->isReplayableCheckout($sameAttempt, $now)) {
                return $this->replayCheckout($sameAttempt);
            }

            throw new StaleCheckoutAttemptException(
                '契約手続きの有効期限が切れました。画面を再読み込みして再試行してください。',
            );
        }

        // 段 3: 同 plan の live pending dedup (**org-wide**。subscription は org 単位の singleton
        // であり、actor scope にすると同 org の 2 人が同時に live Checkout を持てて二重契約を許す)。
        $pending = $this->subscriptionAttemptQuery($org)
            ->where('plan_code', $plan->code)
            ->where('status', CheckoutSessionStatus::Pending->value)
            ->where('created_at', '>=', $threshold)
            ->latest('id')
            ->first();

        if ($pending instanceof BillingCheckoutSession) {
            return new CheckoutSessionDto(
                stripeSessionId: $pending->stripe_session_id,
                url: null,
                intent: CheckoutIntent::SubscriptionStart->value,
                planCode: $plan->code,
            );
        }

        // 段 4: 別 plan の live pending を expire する (stale な別 plan 行は Stripe 側で既に
        // expire 済みのため照会せず放置する = 無駄な外部 API を撃たない)。
        $otherPlanPending = $this->subscriptionAttemptQuery($org)
            ->where('status', CheckoutSessionStatus::Pending->value)
            ->where('created_at', '>=', $threshold)
            ->where(function (Builder $q) use ($plan): void {
                /** @var Builder<BillingCheckoutSession> $q */
                $q->whereNull('plan_code')->orWhere('plan_code', '!=', $plan->code);
            })
            ->get();

        foreach ($otherPlanPending as $row) {
            // Stripe 側 expire 失敗時は local を上書きせず停止する (remote session が open のまま
            // 新規 Checkout を作ると別 plan で二重完了しうる)。
            try {
                $expireResult = $this->gateway->expireCheckoutSession($row->stripe_session_id);
            } catch (Throwable $e) {
                Log::warning('startCheckout: failed to expire old pending, stopping', [
                    'organization_id' => $org->getKey(),
                    'stripe_session_id' => $row->stripe_session_id,
                ]);

                throw new CheckoutInProgressException(
                    '前回の決済セッションの整理に失敗しました。 数分後に再試行してください。',
                    previous: $e,
                );
            }

            if ($expireResult === 'complete') {
                // 決済完了済 (= webhook 未着)。新規 Checkout を作らず caller に通知する。
                throw new CheckoutInProgressException('直前の決済が処理中です。数分お待ちください。');
            }

            $row->status = CheckoutSessionStatus::Expired->value;
            $row->save();
        }

        // 段 5: Stripe 作成 → DB 記録。metadata は照合専用 (認可・org 解決には使わない)。
        $created = $this->gateway->createSubscriptionCheckout(
            $org,
            $basePrice->stripe_price_id,
            $successUrl,
            $cancelUrl,
            [
                'purpose' => 'subscription_start',
                'org_ref' => (string) $org->id,
                'plan_code' => $plan->code,
            ],
            'sub_start:'.$attemptToken,
        );

        try {
            // 失敗 INSERT が PostgreSQL で外側 transaction を abort させないよう savepoint で囲む。
            DB::transaction(function () use ($org, $user, $plan, $created, $attemptToken, $funding): void {
                $session = new BillingCheckoutSession;
                // tenant / actor キーは relation / 明示代入 (mass assignment しない)
                $session->organization()->associate($org);
                $session->initiated_by_user_id = $user->id;
                $session->fill([
                    'intent' => CheckoutIntent::SubscriptionStart->value,
                    'plan_code' => $plan->code,
                    'funding_choice' => $funding?->value,
                    'stripe_session_id' => $created->sessionId,
                    'idempotency_key' => 'sub_start:'.$attemptToken,
                    'attempt_token' => $attemptToken,
                    'checkout_url' => $created->url,
                    'status' => CheckoutSessionStatus::Pending->value,
                ]);
                $session->save();
            });
        } catch (UniqueConstraintViolationException $e) {
            // 段 6: 並行 race。unique(org, intent, attempt_token) 違反 → 既存を再読込して収束する
            // (attempt_token 以外の unique 違反は rethrow = 500 に落として調査対象にする)。
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            $row = $this->subscriptionAttemptQuery($org)
                ->where('attempt_token', $attemptToken)
                ->latest('id')
                ->first();

            if ($row instanceof BillingCheckoutSession && $this->isReplayableCheckout($row, CarbonImmutable::now())) {
                return $this->replayCheckout($row);
            }

            throw new StaleCheckoutAttemptException(
                '契約手続きの有効期限が切れました。画面を再読み込みして再試行してください。',
            );
        }

        return new CheckoutSessionDto(
            stripeSessionId: $created->sessionId,
            url: $created->url,
            intent: CheckoutIntent::SubscriptionStart->value,
            planCode: $plan->code,
        );
    }

    /**
     * `intent=subscription_start` に pin した org スコープのクエリ
     * (P8a の `setup_payment_method` 行を段 2/3/4 に混入させない唯一の出典)。
     *
     * @return Builder<BillingCheckoutSession>
     */
    private function subscriptionAttemptQuery(Organization $org): Builder
    {
        return BillingCheckoutSession::query()
            ->where('organization_id', $org->getKey())
            ->where('intent', CheckoutIntent::SubscriptionStart->value);
    }

    /**
     * 同 attempt_token の既存 session が冪等再生可能か。
     * **stale pending は replay しない** (死んだ checkout_url へ収束させない = C-1)。
     */
    private function isReplayableCheckout(BillingCheckoutSession $session, CarbonImmutable $now): bool
    {
        if ($session->status === CheckoutSessionStatus::Completed->value) {
            return true;
        }

        return $session->isReplayablePending($now);
    }

    /**
     * replayable な既存 session を冪等再生する。
     *  - Pending → 同じ checkout_url に戻す
     *  - Completed → url=null (Controller が「受付済み」フィードバックを出す)
     */
    private function replayCheckout(BillingCheckoutSession $session): CheckoutSessionDto
    {
        $url = $session->status === CheckoutSessionStatus::Pending->value
            ? $session->checkout_url
            : null;

        return new CheckoutSessionDto(
            stripeSessionId: $session->stripe_session_id,
            url: $url,
            intent: CheckoutIntent::SubscriptionStart->value,
            planCode: $session->plan_code,
        );
    }

    /**
     * QueryException が attempt_token unique 制約違反か判定する (driver 差を吸収)。
     *
     * SQLSTATE は driver で異なる (MySQL/SQLite=23000, PostgreSQL=23505) ため両方許容し、
     * 識別子で attempt_token unique 違反だけを拾う (他制約を replay 分岐へ誤って流さない)。
     * MySQL/PostgreSQL は index 名、SQLite は構成列名で一致を見る。
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        if (! in_array($e->getCode(), ['23000', '23505'], true)) {
            return false;
        }

        $message = $e->getMessage();

        return str_contains($message, 'billing_checkout_sessions_org_intent_attempt_unique')
            || (str_contains($message, 'billing_checkout_sessions.organization_id')
                && str_contains($message, 'attempt_token'));
    }

    /**
     * 契約開始前の事前検証: 請求先メールが解決できること
     * (billing_contact_email 正本 → owner email fallback)。
     */
    public function assertCheckoutReady(Organization $org): void
    {
        $email = $org->billingContactEmail();
        Assert::stringNotEmpty($email, '請求先メールが未設定です');
        Assert::regex($email, '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', '請求先メールの形式が不正です');
    }

    /** Stripe Customer Portal セッション (支払い方法・解約の自己管理) の遷移先を返す。 */
    public function createPortalSession(Organization $org, string $returnUrl): ExternalBillingRedirect
    {
        return $this->gateway->createPortalSession($org, $returnUrl);
    }

    /**
     * Stripe Checkout の対象プランかを service 層で明示拒否する (validation 迂回対策)。
     * Personal (free) / Enterprise / 未知 code は fail-closed で 422。
     */
    private function assertStripeBillablePlan(Plan $plan): void
    {
        $planCode = PlanCode::tryFrom($plan->code);
        if ($planCode === null || ! $planCode->requiresStripeCheckout()) {
            throw ValidationException::withMessages([
                'plan_code' => 'このプランは Stripe 決済の対象外です。',
            ]);
        }
    }

    /**
     * production runtime で未 sync の test mode Price を checkout に使う事故を防ぐ DB レベル guard。
     */
    private function assertPriceSynced(PlanPrice $price): void
    {
        if (! app()->environment('production')) {
            return;
        }
        if (! $price->livemode || $price->synced_at === null) {
            $lookupKey = $price->lookup_key ?? "plan_id={$price->plan_id}:kind={$price->kind}";
            throw new StripePriceNotSyncedException($lookupKey);
        }
    }

    /** base Price ID からプラン (PlanCode) を逆引きする。未知 Price は null。 */
    private function resolvePlanCodeFromPriceId(?string $priceId): ?PlanCode
    {
        if ($priceId === null || $priceId === '') {
            return null;
        }

        $row = PlanPrice::query()
            ->where('stripe_price_id', $priceId)
            ->where('kind', PlanPriceKind::Base->value)
            ->first();

        if (! $row instanceof PlanPrice) {
            return null;
        }

        $plan = $row->plan;
        if (! $plan instanceof Plan) {
            return null;
        }

        return PlanCode::tryFrom($plan->code);
    }
}
