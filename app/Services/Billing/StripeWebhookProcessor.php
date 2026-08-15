<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\StaleWebhookClaimDto;
use App\DataTransferObjects\Billing\WebhookRecoveryResultDto;
use App\Enums\Billing\BillingNotificationType;
use App\Enums\Billing\HandledStripeWebhookEvent;
use App\Enums\Billing\SignupFundingChoice;
use App\Enums\Billing\TicketCheckoutSessionStatus;
use App\Enums\Billing\WebhookEventStatus;
use App\Enums\Billing\WebhookRecoveryReason;
use App\Enums\Billing\WebhookReplaySafety;
use App\Enums\Billing\WebhookStaleClaimOutcome;
use App\Enums\CheckoutIntent;
use App\Enums\CheckoutSessionStatus;
use App\Jobs\Billing\HandleAutoRechargeChargeFailureJob;
use App\Jobs\Billing\ReuseSubscriptionPaymentMethodJob;
use App\Jobs\Billing\SetDefaultPaymentMethodJob;
use App\Models\Billing\BillingCheckoutSession;
use App\Models\Billing\Plan;
use App\Models\Billing\PlanPrice;
use App\Models\Billing\StripeWebhookEvent;
use App\Models\Billing\Subscription;
use App\Models\Billing\TicketCheckoutSession;
use App\Models\Organization;
use App\Notifications\Billing\PaymentFailedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Events\WebhookReceived;
use RuntimeException;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * Stripe webhook の冪等マシン (Cashier の WebhookReceived listener)。
 *
 * 1. stripe_webhook_events に event_id UNIQUE で冪等記録 (二重処理 skip)
 * 2. type 別 handler:
 *    - customer.subscription.created/updated: payload → SubscriptionSnapshot を
 *      SubscriptionService::applySubscriptionSnapshot へ渡して状態同期 +
 *      recordPaymentMethodSnapshot で決済手段有無を記録。
 *      **created のみ**、状態同期のあとに初回無償チケット (signup grant) を
 *      SubscriptionService::grantSignupInitialTickets で付与する (P6/F2 の paid 側付与契機)
 *    - customer.subscription.deleted: 同上 (terminated=true。plan_code 解除 + schedule クリア)
 *    - invoice.paid: プランの monthly_ticket_grant を月次付与 (signup grant には関与しない)
 *    - invoice.payment_failed: 支払い失敗通知 (BillingNotificationDispatcher 経由の send-once)
 *    - charge.refunded: 買い切りチケットの返金逆仕訳 (clawback)
 * 3. 失敗時は status=failed + failure_reason 記録 + report して再 throw (Cashier 既定どおり
 *    200 を返さず Stripe の再送を促す。failed は再送時に received へ復帰して再処理される)
 * 4. 再送上限: failed→received 復帰のたびに attempts をインクリメントし、
 *    MAX_PROCESSING_ATTEMPTS 到達後は処理せず skip (= 200 terminal-ack) して
 *    恒久失敗イベントの無限 500 ストームを打ち切る (運用は failure_reason で調査する)
 * 5. 滞留回収: 本処理中にプロセスが落ちて received のまま残った行を
 *    recoverStale() が拾い直す (cron: billing:recover-stale-webhook-events)。
 *    再実行してよい種類かは HandledStripeWebhookEvent::replaySafety() が決め、
 *    対象外・上限到達は recovery_pending + recovery_reason へ置いて止める。
 *    終局書き込みは受理した世代 (attempts) を握っている実行だけが行う条件付き UPDATE。
 *    運用契約の正本は docs/architecture.md の「Stripe webhook の滞留回収」。
 *
 * subscriptions **行の作成** (updateOrCreate) は Cashier の WebhookController が唯一の
 * writer。本クラス (WebhookReceived listener) は Cashier のハンドラより先に走るため、
 * 行が無い間の状態同期は no-op に落ちる (直後の updated で追随する)。ここで行を作ると
 * Cashier 側の subscription_items 生成が永久に skip されるため作らない。
 *
 * plan_code 不変条件: `organizations.plan_code` は Stripe Price を持つ有償プランの
 * 契約 (active/trialing) 時のみ SubscriptionService が set し、`customer.subscription.deleted` で
 * null に戻す状態キー。**用途は quota の解決のみ** (null = config/quota.php の fallback_plan が
 * 適用される、それだけの意味)。利用可否 (entitlement) は plan_code を一切見ず
 * BillingAccess::state() が決める (無料枠は organizations.free_plan_code='personal')。
 */
class StripeWebhookProcessor
{
    /**
     * webhook 処理の試行上限。`attempts` がこれに到達したら terminal とみなす。
     *
     * `attempts` を増やす経路は 2 つある — `claim()` (Stripe 再送による failed→received 復帰) と
     * `claimStale()` (滞留回収による受理)。上限は共通で、到達後は HTTP 経路なら処理せず
     * 200 ack、回収経路なら `recovery_pending` + `AttemptsExhausted` へ置いて止める。
     *
     * **`claim()` の直列化は本処理までは覆わない** (守るのは状態遷移だけで `process()` は
     * トランザクションの外で走る)。そこで落ちた行は `received` のまま残り、Stripe の再送も
     * `claim()` に弾かれて 200 で終わるため付与が無音で失われる。これを塞ぐのが
     * `recoverStale()` である。運用契約の正本は `docs/architecture.md`
     * の「Stripe webhook の滞留回収」。
     *
     * Stripe の自動再送窓 (~3 日) に対し 8 回で十分。
     */
    public const int MAX_PROCESSING_ATTEMPTS = 8;

    /** 月次付与の対象となる invoice billing_reason */
    private const array GRANTING_BILLING_REASONS = ['subscription_create', 'subscription_cycle'];

    public function __construct(
        private readonly TicketLedgerService $tickets,
        private readonly BillingNotificationDispatcher $notifications,
        private readonly SubscriptionService $subscriptions,
        private readonly AutoRechargeService $autoRecharge,
    ) {}

    public function handle(WebhookReceived $event): void
    {
        /** @var array<mixed> $payload */
        $payload = $event->payload;
        $eventId = $this->stringAt($payload, 'id');
        $type = $this->stringAt($payload, 'type');
        if ($eventId === null || $type === null) {
            return; // 形式不正の payload は処理対象外 (署名検証は Cashier middleware 側)
        }

        $record = $this->claim($eventId, $type, $payload);
        if ($record === null) {
            return; // 同一 event_id を処理済み (冪等 skip)
        }

        // 受理したときの世代 (claim 直後の attempts)。以降の書き込みはこの世代を握っている
        // 実行だけが行える (滞留回収が attempts を進めた後の追い越し書き込みを防ぐ)。
        $claimedAttempts = $record->attempts;

        try {
            $this->process($type, $payload);
        } catch (Throwable $exception) {
            $finalized = $this->finalize($eventId, $claimedAttempts, WebhookEventStatus::Failed, $exception->getMessage());
            report($exception);

            if (! $finalized) {
                // 行は既に別の世代 (滞留回収など) が持っている。こちらから再送を促す理由が無い
                // — 再送しても claim() に弾かれて 200 で終わり、500 の運用ノイズだけが残る。
                // 成功経路と同じ扱いにする (世代を失った実行は行の決着に関与しない)。
                return;
            }

            throw $exception; // 200 を返さず Stripe の再送を促す (failed は再送で再処理)
        }

        $this->finalize($eventId, $claimedAttempts, WebhookEventStatus::Processed, null);
    }

    /**
     * 受理した世代を握っている実行だけが行える条件付き書き込み (CAS)。
     *
     * `status='received'` かつ `attempts=受理時の値` の 1 行だけを更新する。
     * 0 件のときは**別の実行がその行を先に進めている** (滞留回収が claim し直した等) ので
     * 何も書かずに記録だけ残す — 旧ワーカーが新しい世代の結果を上書きしない
     * (ドメイン規約 6 の「条件付き UPDATE」)。
     *
     * `recovery_reason` は必ず NULL を置く
     * (不変条件: 非 NULL ⟺ status = recovery_pending)。
     *
     * **保証範囲を誇張しない**: これが守るのは `stripe_webhook_events` 行の世代だけである。
     * 旧ワーカーと回収側の `process()` は並行し得るので、付与の一回性は台帳の
     * `idempotency_key` UNIQUE と各ハンドラの終局 guard が担う。
     *
     * @param  WebhookEventStatus  $status  Processed (終局) / Failed (HTTP 経路の失敗) /
     *                                      Received (回収経路の失敗 = 終局させず次の回収へ回す)
     * @return bool 書き込めたら true
     */
    private function finalize(
        string $eventId,
        int $claimedAttempts,
        WebhookEventStatus $status,
        ?string $failureReason,
    ): bool {
        $updated = StripeWebhookEvent::query()
            ->where('event_id', $eventId)
            ->where('status', WebhookEventStatus::Received->value)
            ->where('attempts', $claimedAttempts)
            ->update([
                'status' => $status->value,
                'failure_reason' => $failureReason,
                'recovery_reason' => null,
                'processed_at' => $status === WebhookEventStatus::Processed
                    ? CarbonImmutable::now()
                    : null,
            ]);

        if ($updated !== 1) {
            Log::warning('stripe webhook: 別の実行が先に進めたため終局書き込みを見送った', [
                'event_id' => $eventId,
                'attempts' => $claimedAttempts,
                'status' => $status->value,
            ]);

            return false;
        }

        return true;
    }

    /**
     * 処理中に滞留した webhook 記録の回収 (cron: billing:recover-stale-webhook-events)。
     *
     * 対象は `status=received` かつ `updated_at` が滞留の閾値より古い行**だけ**。
     * `failed` は Stripe の再送が再試行の駆動者なので拾わない。
     *
     * 作法は既存の滞留回収 (`RenderJobService::recoverStale` /
     * `TicketLedgerService::releaseStale`) と同じ = 対象を列挙 → 1 件ずつ行ロックで
     * 取り直して再検証 → 件数を返す。**共通の回収基盤は作らない** (ドメインごとの個別実装)。
     *
     * 通知 (`Log::warning` / `report()`) は**トランザクションの外**で出す
     * (状態が保存されていないのに通知だけ出る / 同じ行に複数回出るのを避ける)。
     * ただし commit 後に落ちれば 0 回になる = 送信を 1 回試みるだけで、
     * 厳密な一回配送は保証しない (常設の観測点は `recovery_pending` の件数のほう)。
     */
    public function recoverStale(): WebhookRecoveryResultDto
    {
        $threshold = CarbonImmutable::now()
            ->subMinutes(config()->integer('billing.webhook_stale_after_minutes'));

        /** @var list<string> $staleEventIds */
        $staleEventIds = StripeWebhookEvent::query()
            ->where('status', WebhookEventStatus::Received->value)
            ->where('updated_at', '<=', $threshold)
            ->orderBy('id')
            ->pluck('event_id')
            ->all();

        $replayed = 0;
        $retryScheduled = 0;
        $movedToRecoveryPending = 0;
        $skipped = 0;

        foreach ($staleEventIds as $eventId) {
            $claim = $this->claimStale($eventId, $threshold);
            if ($claim === null) {
                $skipped++; // 行が消えた / 別の実行が先に進めた

                continue;
            }

            if ($claim->outcome === WebhookStaleClaimOutcome::MovedToRecoveryPending) {
                $movedToRecoveryPending++;
                $this->reportRecoveryPending($claim);

                continue;
            }

            try {
                $this->process($claim->type, $claim->payload);
            } catch (Throwable $exception) {
                report($exception);
                // **終局させない**: failed にすると回収対象 (received) から外れ、
                // Stripe も配信成功と認識しているため二度と再試行されない。
                // received のまま失敗理由だけ書いて次回の回収へ回す (attempts は消費済み)。
                $this->finalize($claim->eventId, $claim->attempts, WebhookEventStatus::Received, $exception->getMessage())
                    ? $retryScheduled++
                    : $skipped++;

                continue;
            }

            $this->finalize($claim->eventId, $claim->attempts, WebhookEventStatus::Processed, null)
                ? $replayed++
                : $skipped++;
        }

        return new WebhookRecoveryResultDto(
            replayed: $replayed,
            retryScheduled: $retryScheduled,
            movedToRecoveryPending: $movedToRecoveryPending,
            skipped: $skipped,
        );
    }

    /**
     * 滞留 1 件の受理。**状態遷移だけ**を 1 つのトランザクションで確定させ、
     * commit 後に要る値をスナップショットで返す (通知はここでは出さない)。
     *
     * `claim()` (Stripe 再送の受理) とは入口が別なので分けてある。
     * `claim()` は変更しない = `received` からの再受理は今までどおり起こらない。
     *
     * 滞留の再検証は**クエリの WHERE に入れる** (ロック取得後に PostgreSQL が述語を
     * 再評価するため、ロック待ちの間に他の実行が前進させた行は 1 行も返らない)。
     *
     * @return StaleWebhookClaimDto|null 処置をしなかったとき (行が無い / 条件を満たさない) は null
     */
    private function claimStale(string $eventId, CarbonImmutable $threshold): ?StaleWebhookClaimDto
    {
        return DB::transaction(function () use ($eventId, $threshold): ?StaleWebhookClaimDto {
            $record = StripeWebhookEvent::query()
                ->where('event_id', $eventId)
                ->where('status', WebhookEventStatus::Received->value)
                ->where('updated_at', '<=', $threshold)
                ->lockForUpdate()
                ->first();

            if (! $record instanceof StripeWebhookEvent) {
                return null;
            }

            $reason = $this->recoveryReasonFor($record);
            if ($reason !== null) {
                $record->status = WebhookEventStatus::RecoveryPending;
                $record->recovery_reason = $reason;
                $record->save();

                return StaleWebhookClaimDto::movedToRecoveryPending(
                    $record->event_id,
                    $record->type,
                    $record->attempts,
                    $reason,
                );
            }

            // 世代を 1 つ進める (status は received のまま = 状態機械を増やさない)。
            // updated_at も進むので、次の実行は閾値を超えるまでこの行を拾わない。
            $record->attempts += 1;
            $record->save();

            return StaleWebhookClaimDto::claimedForReplay(
                $record->event_id,
                $record->type,
                $record->attempts,
                $record->payload,
            );
        });
    }

    /**
     * 自動再実行の対象外と判定する理由 (無ければ null = 再実行してよい)。
     *
     * DB の `type` 文字列は **`tryFrom()`** で境界変換する (`from()` は未知値で例外になり
     * cron 全体を止める)。`null` (本アプリが処理しない種類) は**再実行してよい側**に落ちる —
     * `process()` の `null` arm は構造的に no-op で、通常経路でも `processed` になるため
     * (同じ事実に 2 通りの決着を与えない)。
     */
    private function recoveryReasonFor(StripeWebhookEvent $record): ?WebhookRecoveryReason
    {
        $event = HandledStripeWebhookEvent::tryFrom($record->type);

        // 本アプリが処理しない種類は**必ず**通常経路と同じ決着にする (再実行 → no-op → processed)。
        // 試行上限より前に返すのが要点 — no-op に上限を適用して回収待ちへ置くと、
        // 「未対応 type は通常経路と同じ」という契約が上限到達時だけ破れる。
        if ($event === null) {
            return null;
        }

        if ($event->replaySafety() === WebhookReplaySafety::OrderSensitive) {
            return WebhookRecoveryReason::OrderSensitive;
        }

        if ($record->attempts >= self::MAX_PROCESSING_ATTEMPTS) {
            return WebhookRecoveryReason::AttemptsExhausted;
        }

        return null;
    }

    /**
     * 回収待ちへ置いたことの可観測化 (commit 後に 1 回だけ送信を試みる)。
     * payload 本体は載せない (外部由来の可変データを運用ログへ流さない)。
     */
    private function reportRecoveryPending(StaleWebhookClaimDto $claim): void
    {
        Log::warning('stripe webhook: 滞留を回収待ちへ移した (自動再実行しない)', $claim->logContext());

        report(new RuntimeException(sprintf(
            'stripe webhook 回収待ち: %s (%s) reason=%s attempts=%d',
            $claim->eventId,
            $claim->type,
            // 回収待ち以外の DTO では reason が無い (呼び出し側で絞っているが型では閉じていない)
            $claim->reason->value ?? '',
            $claim->attempts,
        )));
    }

    /**
     * 冪等記録の獲得。処理すべきときだけ record を返す。
     * - 未受信: 新規 received で記録して返す
     * - processed / received (処理中): null (二重処理 skip)
     * - failed: attempts をインクリメントして received に戻して返す (Stripe 再送による再処理)。
     *   ただし attempts が MAX_PROCESSING_ATTEMPTS に到達済みなら null (terminal-ack:
     *   処理せず 200 を返し Stripe の自動再送を打ち切る)
     *
     * @param  array<mixed>  $payload
     */
    private function claim(string $eventId, string $type, array $payload): ?StripeWebhookEvent
    {
        return DB::transaction(function () use ($eventId, $type, $payload): ?StripeWebhookEvent {
            $existing = StripeWebhookEvent::query()
                ->where('event_id', $eventId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->status !== WebhookEventStatus::Failed) {
                    return null;
                }
                if ($existing->attempts >= self::MAX_PROCESSING_ATTEMPTS) {
                    // terminal: 構造化ログで可観測化し、運用側が failure_reason を見て手動対応する
                    Log::warning('stripe webhook: terminal failure, acking to stop Stripe retries', [
                        'event_id' => $eventId,
                        'type' => $type,
                        'attempts' => $existing->attempts,
                    ]);
                    // 付与系イベントの取りこぼしは「決済済み・未付与」を残すため運用アラート経路
                    // (report) にも載せる (failure_reason 参照 → 手動 grantPurchased 判断)
                    if (in_array($type, [
                        HandledStripeWebhookEvent::CheckoutSessionCompleted->value,
                        HandledStripeWebhookEvent::InvoicePaid->value,
                    ], true)) {
                        report(new RuntimeException("stripe webhook terminal failure (grant イベント): {$eventId} ({$type})"));
                    }

                    return null;
                }
                $existing->status = WebhookEventStatus::Received;
                $existing->attempts += 1;
                $existing->save();

                return $existing;
            }

            $record = new StripeWebhookEvent;
            // 全カラム明示代入 (クライアント入力は入らない)。
            // attempts は DB カラム default に依存せず INSERT 時に明示代入する —
            // 受理直後の世代 (finalize の条件付き UPDATE が握る値) を
            // 在メモリの instance から必ず読めるようにするため。
            $record->event_id = $eventId;
            $record->type = $type;
            $record->status = WebhookEventStatus::Received;
            $record->payload = $payload;
            $record->attempts = 0;
            $record->save();

            return $record;
        });
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function process(string $type, array $payload): void
    {
        // 処理イベント集合の単一出典は HandledStripeWebhookEvent (購読集合の導出元)。
        // case を足したらここに arm を足す (handled ⊆ subscribed は invariant test が担保)
        match (HandledStripeWebhookEvent::tryFrom($type)) {
            // P6 (T077): created / updated / deleted を 3 arm へ分割し、created のみ
            // signup grant を発火させる (syncSubscriptionState が enum で契機を判別する)。
            HandledStripeWebhookEvent::SubscriptionCreated => $this->syncSubscriptionState(
                $payload,
                HandledStripeWebhookEvent::SubscriptionCreated,
            ),
            HandledStripeWebhookEvent::SubscriptionUpdated => $this->syncSubscriptionState(
                $payload,
                HandledStripeWebhookEvent::SubscriptionUpdated,
            ),
            HandledStripeWebhookEvent::SubscriptionDeleted => $this->syncSubscriptionState(
                $payload,
                HandledStripeWebhookEvent::SubscriptionDeleted,
            ),
            // P8a (T079): invoice.paid は metadata.purpose で auto_recharge を分岐してから
            // 従来の月次付与経路へ委譲する (handleInvoicePaid が内部で振り分ける)。
            HandledStripeWebhookEvent::InvoicePaid => $this->handleInvoicePaid($payload),
            HandledStripeWebhookEvent::ChargeRefunded => $this->clawbackRefundedTickets($payload),
            HandledStripeWebhookEvent::InvoicePaymentFailed => $this->handleInvoicePaymentFailed($payload),
            // チケットスポット購入の冪等付与 (T007。真実源は ticket_checkout_sessions 行)
            HandledStripeWebhookEvent::CheckoutSessionCompleted => $this->handleCheckoutSessionCompleted($payload),
            null => null, // 未対応 type は受理のみ (processed として記録)
        };
    }

    /**
     * customer.subscription.created/updated/deleted: payload → SubscriptionSnapshot の写像 +
     * 組織解決 + 決済手段有無の抽出。**状態の書込は SubscriptionService に委譲する**
     * (Processor は写像と呼び出し順序だけを持つ)。
     *
     * subscriptions 行自体の作成は Cashier の WebhookController が行う。WebhookReceived は
     * Cashier の同期処理より先に発火するため、created イベント時点では行が無いことがある
     * (best-effort: 直後の customer.subscription.updated / 次周期の更新で追随する)。
     *
     * `customer.subscription.created` では状態同期のあとに初回無償チケット
     * (signup grant) を付与する (P6/F2)。付与の可否判定・冪等性は SubscriptionService が持つ。
     *
     * @param  array<mixed>  $payload
     * @param  HandledStripeWebhookEvent  $event  created / updated / deleted のいずれか
     *                                            (deleted のみ terminated = 終了契機)
     */
    private function syncSubscriptionState(array $payload, HandledStripeWebhookEvent $event): void
    {
        $terminated = $event === HandledStripeWebhookEvent::SubscriptionDeleted;

        $organization = $this->resolveOrganization($payload);
        if ($organization === null) {
            return;
        }

        // sub id は subscription object 本体の必須フィールド。取れない payload は fail-closed
        // (状態同期も signup grant も行わない)。
        $stripeId = $this->stringAt($payload, 'data.object.id');
        if ($stripeId === null) {
            return;
        }

        $snapshot = new SubscriptionSnapshot(
            stripeId: $stripeId,
            status: $this->stringAt($payload, 'data.object.status') ?? 'incomplete',
            basePriceId: $this->stringAt($payload, 'data.object.items.data.0.price.id'),
            baseQuantity: $this->intAt($payload, 'data.object.items.data.0.quantity'),
            currentPeriodEnd: $this->periodEnd($payload),
            trialEndsAt: $this->timestampToCarbon(data_get($payload, 'data.object.trial_end')),
            endsAt: $this->timestampToCarbon(
                data_get($payload, 'data.object.ended_at') ?? data_get($payload, 'data.object.cancel_at'),
            ),
        );

        $this->subscriptions->applySubscriptionSnapshot($organization, $snapshot, terminated: $terminated);

        // 初回無償チケットの付与契機 (paid 側)。順序 (snapshot → grant) は aigenba verbatim。
        if ($event === HandledStripeWebhookEvent::SubscriptionCreated) {
            $this->subscriptions->grantSignupInitialTickets($organization, $stripeId);
        }

        if ($terminated) {
            return; // 終了系では PM snapshot を記録しない (monotonic writer は契約中のみ)
        }

        $subscription = Subscription::query()->where('stripe_id', $stripeId)->first();
        if ($subscription instanceof Subscription) {
            $this->subscriptions->recordPaymentMethodSnapshot(
                $subscription,
                $this->subscriptionHasPaymentMethod($payload),
            );
        }
    }

    /**
     * subscription object が決済手段を持つか (default_payment_method / default_source)。
     * Stripe は string id か expanded object のいずれも取り得るため union helper で抽出する。
     *
     * @param  array<mixed>  $payload
     */
    private function subscriptionHasPaymentMethod(array $payload): bool
    {
        return $this->resolveStripeIdField(data_get($payload, 'data.object.default_payment_method')) !== null
            || $this->resolveStripeIdField(data_get($payload, 'data.object.default_source')) !== null;
    }

    /**
     * Stripe の id フィールド (string id または expanded object) から id を取り出す。
     */
    private function resolveStripeIdField(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value !== '' ? $value : null;
        }
        if (is_array($value)) {
            $id = $value['id'] ?? null;

            return is_string($id) && $id !== '' ? $id : null;
        }

        return null;
    }

    /**
     * 次回更新日時 (renewal reminder = billing:send-billing-reminders の真実源)。
     * 新 API (basil) は item 配下、旧 API は subscription top-level に持つため両系を fallback で拾う。
     *
     * @param  array<mixed>  $payload
     */
    private function periodEnd(array $payload): ?CarbonImmutable
    {
        return $this->timestampToCarbon(
            data_get($payload, 'data.object.items.data.0.current_period_end')
                ?? data_get($payload, 'data.object.current_period_end'),
        );
    }

    /** Stripe の epoch 秒を CarbonImmutable にする (非 int / 非正数は null)。 */
    private function timestampToCarbon(mixed $value): ?CarbonImmutable
    {
        return is_int($value) && $value > 0 ? CarbonImmutable::createFromTimestamp($value) : null;
    }

    /**
     * payload から int 値を安全に取り出す (それ以外の型は null)。
     *
     * @param  array<mixed>  $payload
     */
    private function intAt(array $payload, string $path): ?int
    {
        $value = data_get($payload, $path);

        return is_int($value) ? $value : null;
    }

    /**
     * invoice.paid の振り分け。
     *
     * P8a: `metadata.purpose === 'auto_recharge'` の invoice は**オートリチャージの付与経路**へ
     * 振る (billing_reason='manual' のため既存 GRANTING_BILLING_REASONS allowlist では月次付与に
     * 混入しないが、分岐を明示して意図を固定する)。それ以外は従来どおり月次付与。
     *
     * @param  array<mixed>  $payload
     */
    private function handleInvoicePaid(array $payload): void
    {
        if ($this->stringAt($payload, 'data.object.metadata.purpose') === 'auto_recharge') {
            $this->recordAutoRechargePaid($payload);

            return;
        }

        $this->grantMonthlyTickets($payload);
    }

    /**
     * P8a: オートリチャージ invoice の paid 確定 (冪等付与 + attempt paid 遷移)。
     *
     * **metadata は照合専用**で org 解決・認可には使わない (tenant キー不信 / 不変条件 #1)。
     * org は attempt 行 (自 DB) の relation から解決し、payload の customer と突き合わせる。
     * 付与は `recharge:{invoiceId}` の ledger UNIQUE で冪等 (webhook 再送・同期 pay・
     * リコンサイルのどれが先でも 1 回)。
     *
     * @param  array<mixed>  $payload
     */
    private function recordAutoRechargePaid(array $payload): void
    {
        $attemptUlid = $this->stringAt($payload, 'data.object.metadata.recharge_attempt_ulid');
        $invoiceId = $this->stringAt($payload, 'data.object.id');
        if ($attemptUlid === null || $invoiceId === null) {
            throw new RuntimeException('invoice.paid (auto_recharge): metadata.recharge_attempt_ulid / invoice id 欠落');
        }

        $attempt = $this->autoRecharge->findAttemptByUlid($attemptUlid);
        if ($attempt === null) {
            // 自 DB 行が真実源。crash 先着 webhook は Stripe の再送で本経路に収束する (retryable)。
            throw new RuntimeException("invoice.paid (auto_recharge): 未追跡 attempt {$attemptUlid} (DB 行なし、再送待ち)");
        }

        $organization = $attempt->organization;
        Assert::isInstanceOf($organization, Organization::class);

        // customer 照合 (tenant キー不信の fail-closed。metadata.organization_id は認可に使わない)
        $customerId = $this->stringAt($payload, 'data.object.customer');
        if ($customerId === null || $organization->stripe_id !== $customerId) {
            throw new RuntimeException("invoice.paid (auto_recharge): customer 照合不一致 (attempt {$attemptUlid})");
        }
        // attempt に pin 済みの invoice と一致すること (別 invoice の混入を弾く)
        if ($attempt->stripe_invoice_id !== null && $attempt->stripe_invoice_id !== $invoiceId) {
            throw new RuntimeException("invoice.paid (auto_recharge): invoice 照合不一致 (attempt {$attemptUlid})");
        }

        $amountPaid = data_get($payload, 'data.object.amount_paid');
        $amountDue = data_get($payload, 'data.object.amount_due');
        if (! is_int($amountPaid) || ! is_int($amountDue)) {
            throw new RuntimeException("invoice.paid (auto_recharge): amount 欠落 (invoice {$invoiceId})");
        }

        $this->autoRecharge->recordSuccessfulCharge(
            $organization,
            $attempt,
            $invoiceId,
            $amountPaid,
            $amountDue,
            $this->resolveStripeIdField(data_get($payload, 'data.object.payment_intent')),
        );
    }

    /**
     * invoice.paid: 契約プランの monthly_ticket_grant を月次付与する。
     *
     * **signup grant には一切関与しない (P6/D29)**。初回無償チケットの付与契機は
     * プラン有効化時 (free = PersonalPlanService::activate / paid =
     * customer.subscription.created) のみ。
     *
     * 冪等性は claim() の event_id UNIQUE に加え、台帳の idempotency_key
     * (monthly:{invoiceId}) が保証する (event_id 違いの同一 invoice 再通知でも二重付与しない)。
     *
     * @param  array<mixed>  $payload
     */
    private function grantMonthlyTickets(array $payload): void
    {
        $organization = $this->resolveOrganization($payload);
        if ($organization === null) {
            return;
        }

        $billingReason = $this->stringAt($payload, 'data.object.billing_reason');
        if (! in_array($billingReason, self::GRANTING_BILLING_REASONS, true)) {
            return; // サブスク以外の請求 (one-time 等) では付与しない
        }

        $plan = $this->resolveInvoicePlan($payload, $organization);
        if ($plan === null || $plan->monthly_ticket_grant <= 0) {
            return;
        }

        // invoice id が取れない payload では安定した冪等キーを作れないため付与しない (fail-closed)
        $invoiceId = $this->stringAt($payload, 'data.object.id');
        if ($invoiceId === null) {
            report(new RuntimeException('invoice.paid: invoice id 不明で月次付与 skip'));

            return;
        }

        $this->tickets->grantMonthly(
            $organization,
            $plan->monthly_ticket_grant,
            null, // 月次付与の期限はテンプレートでは無期限 (期限運用は派生アプリの判断で渡す)
            "monthly:{$invoiceId}",
            "月次チケット付与 (plan: {$plan->code} / invoice: {$invoiceId})",
        );
    }

    /**
     * invoice.payment_failed: 観測ログ + 支払い失敗通知 (dedup は通知台帳の (type, invoice_id))。
     * past_due 状態遷移・督促回数管理は派生アプリの拡張点。
     *
     * @param  array<mixed>  $payload
     */
    private function handleInvoicePaymentFailed(array $payload): void
    {
        $invoiceId = $this->stringAt($payload, 'data.object.id');
        $organization = $this->resolveOrganization($payload);

        Log::warning('stripe webhook: invoice payment failed', [
            'invoice_id' => $invoiceId,
            'customer_id' => $this->stringAt($payload, 'data.object.customer'),
            'attempt_count' => data_get($payload, 'data.object.attempt_count'),
        ]);

        // P8a: オートリチャージ invoice の失敗は専用 Job へ振る (SCA 判定に外向き Stripe API が
        // 要るため webhook 同期処理では判定しない)。汎用の支払い失敗通知は送らない
        // (専用の失敗 / SCA 通知が Job 経由で出る)。
        if ($this->stringAt($payload, 'data.object.metadata.purpose') === 'auto_recharge') {
            $attemptUlid = $this->stringAt($payload, 'data.object.metadata.recharge_attempt_ulid');
            $attempt = $attemptUlid === null ? null : $this->autoRecharge->findPendingAttemptByUlid($attemptUlid);
            if ($attempt !== null) {
                // ★ ここは tx で括らない (AG-114 確定 1 の対象外)。先行する自 DB 書き込みが無く、
                //   原子性の対象になる業務 tx が存在しないため (findPendingAttemptByUlid は読み取りのみ)。
                HandleAutoRechargeChargeFailureJob::dispatch($attempt->id);
            }

            return;
        }

        if ($invoiceId === null || $organization === null) {
            return;
        }

        $this->safelyNotify(fn () => $this->notifications->sendOnce(
            $organization,
            BillingNotificationType::PaymentFailed,
            $invoiceId,
            new PaymentFailedNotification($invoiceId, $organization->name, route('billing.index')),
        ));
    }

    /**
     * 請求通知の呼び出しを保護する最終防壁。通知失敗が webhook 本処理 (plan_code 同期/付与) を
     * 巻き込まないよう、想定外の例外を握りつぶす。通知失敗の一次吸収・記録は dispatcher 側の
     * 責務で、ここは dispatcher が想定外に throw した場合のみ拾う (report で可観測化)。
     */
    private function safelyNotify(callable $notify): void
    {
        try {
            $notify();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * checkout.session.completed: チケットスポット購入の冪等付与。
     *
     * - purpose ガード: metadata.purpose=ticket_purchase かつ mode=payment 以外は受理のみ
     *   (サブスク checkout / 他 purpose を failed にしない)
     * - 真実源は自 DB 行 (ticket_checkout_sessions)。payload の customer / metadata は照合のみ
     *   (tenant キー不信)。行不在・照合不一致・未決済・金額不一致は例外 throw =
     *   retryable failure (既存 handle() の catch で failed + Stripe 再送。恒久不整合は
     *   attempts 上限の terminal-ack + failure_reason で運用調査へ)
     * - 付与は TicketLedgerService::grantPurchased (idempotency_key purchase:{sessionId}
     *   UNIQUE) で冪等。event_id 違い再送でも二重付与しない
     *
     * @param  array<mixed>  $payload
     */
    private function handleCheckoutSessionCompleted(array $payload): void
    {
        // P8a: オートリチャージ用カード登録 (mode=setup) の着地。真実源は自 DB 行
        // (billing_checkout_sessions の intent=setup_payment_method)。
        if ($this->stringAt($payload, 'data.object.mode') === 'setup') {
            $this->completeAutoRechargeSetup($payload);

            return;
        }

        // P9: サブスク契約 Checkout (mode=subscription / purpose=subscription_start) の着地。
        // 真実源は自 DB 行 (billing_checkout_sessions の intent=subscription_start)。
        // 金銭の付与経路には一切触らない (付与は invoice.paid / plan_code 同期は
        // customer.subscription.* が真実源)。
        if ($this->stringAt($payload, 'data.object.metadata.purpose') === 'subscription_start') {
            $this->settleSubscriptionCheckout($payload);

            return;
        }

        $this->grantPurchasedTickets($payload);
    }

    /**
     * P9 (C-2): サブスク契約 Checkout の状態確定。**遷移条件はこの 1 定義のみ**。
     *
     * `status !== Completed` の行だけを payload の `payment_status` が確定した結果へ遷移させる。
     * `Completed` は終局 (再送・後続 payload は no-op = 冪等)。
     *   - paid / no_payment_required → Completed (+ completed_at)
     *   - unpaid                     → Failed
     *   - 上記以外 (null 等)         → 遷移しない (受理のみ)
     *
     * `Failed` / `Expired` からの遅延成功も受理する: これらは AI-CUE 側の都合で付く
     * **ローカルな見立て** (日次 sweeper が全 stale pending を Expired にする) であり、
     * 決済の終局は Stripe が持つ。金銭の付与は invoice.paid が真実源のため台帳は動かない。
     *
     * @param  array<mixed>  $payload
     */
    private function settleSubscriptionCheckout(array $payload): void
    {
        // (1) purpose ガード (呼び出し元で済) + mode ガード: mode≠subscription は受理のみ
        //     (既存 grantPurchasedTickets の mode=payment / P8a の mode=setup と相互排他)。
        if ($this->stringAt($payload, 'data.object.mode') !== 'subscription') {
            return;
        }

        $sessionId = $this->stringAt($payload, 'data.object.id');
        if ($sessionId === null) {
            throw new RuntimeException('checkout.session.completed: session id 欠落 (subscription_start)');
        }

        // (2) 真実源は自 DB 行。行不在は retryable failure (crash 先着 webhook は Stripe の
        //     再送で本経路に収束する)。
        $local = BillingCheckoutSession::query()
            ->where('stripe_session_id', $sessionId)
            ->where('intent', CheckoutIntent::SubscriptionStart->value)
            ->first();
        if ($local === null) {
            throw new RuntimeException("subscription checkout webhook: 未追跡 session {$sessionId} (DB 行なし、再送待ち)");
        }

        $organization = $local->organization;
        Assert::isInstanceOf($organization, Organization::class);

        // (3) tenant キー不信: payload の customer / metadata.org_ref は照合のみ (org 解決には使わない)
        $customerId = $this->stringAt($payload, 'data.object.customer');
        if ($customerId === null || $organization->stripe_id !== $customerId) {
            throw new RuntimeException("subscription checkout webhook: customer 照合不一致 (session {$sessionId})");
        }
        $metaOrgRef = $this->stringAt($payload, 'data.object.metadata.org_ref');
        if ($metaOrgRef !== (string) $organization->id) {
            throw new RuntimeException("subscription checkout webhook: metadata org_ref 照合不一致 (session {$sessionId})");
        }

        // (4) 遷移 (C-2 の 1 定義)
        if ($local->status === CheckoutSessionStatus::Completed->value) {
            return; // 終局 no-op (冪等)
        }

        $paymentStatus = $this->stringAt($payload, 'data.object.payment_status');
        if (in_array($paymentStatus, ['paid', 'no_payment_required'], true)) {
            $local->forceFill([
                'status' => CheckoutSessionStatus::Completed->value,
                'completed_at' => CarbonImmutable::now(),
            ])->save();
        } elseif ($paymentStatus === 'unpaid') {
            $local->forceFill(['status' => CheckoutSessionStatus::Failed->value])->save();

            return;
        } else {
            return; // 未知値 / 欠落は遷移しない (受理のみ = fail-closed)
        }

        // (5) T1004: 決済確定 + funding=auto_recharge のときだけ PM 流用 Job を dispatch する。
        //     dispatch の事実を session に永続化する — setupPending / 着地 flash の
        //     「自動的に有効になります」表示を決済確定済みの契約に限定する出典
        //     (未決済 completed への伝播防止)。再送は (4) の終局 no-op で到達しない。
        $subscriptionId = $this->subscriptionIdFrom($payload);
        if ($local->funding_choice === SignupFundingChoice::AutoRecharge->value && $subscriptionId !== null) {
            // 打刻と投入を同一 tx で括る (AG-114 確定 1)。
            // pm_reuse_dispatched_at は「自動的に有効になります」表示の出典であり、
            // 打刻だけ残って job が投入されない状態は**表示と実態の食い違い**になる。
            DB::transaction(function () use ($local, $subscriptionId): void {
                $local->forceFill(['pm_reuse_dispatched_at' => CarbonImmutable::now()])->save();
                ReuseSubscriptionPaymentMethodJob::dispatch($local->organization_id, $subscriptionId);
            });
        }
    }

    /**
     * `checkout.session.completed` の `data.object.subscription` から subscription id を取る。
     *
     * **string と array{id} の両方を受理する**: 当該フィールドは expandable で、
     * expand 指定の無い通常の payload では **string ID** (`"sub_xxx"`) で来る。
     * array を前提にすると本番で Job が一度も dispatch されない。
     * それ以外の型 / 空文字は null (fail-closed = dispatch しない)。
     *
     * @param  array<mixed>  $payload
     */
    private function subscriptionIdFrom(array $payload): ?string
    {
        $value = data_get($payload, 'data.object.subscription');
        if (is_array($value)) {
            $value = $value['id'] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * P8a: mode=setup Checkout の完了。台帳行を completed 化し、PM の default 設定 +
     * 事前同意の自動有効化を Job へ退避する (外向き Stripe API は webhook 同期処理で叩かない)。
     *
     * @param  array<mixed>  $payload
     */
    private function completeAutoRechargeSetup(array $payload): void
    {
        if ($this->stringAt($payload, 'data.object.metadata.purpose') !== 'auto_recharge_setup') {
            return; // 他 purpose の setup session は受理のみ
        }

        $sessionId = $this->stringAt($payload, 'data.object.id');
        if ($sessionId === null) {
            throw new RuntimeException('checkout.session.completed: session id 欠落 (auto_recharge_setup)');
        }

        // 真実源は自 DB 行 (crash 先着 webhook は Stripe の再送で収束する = retryable)
        $session = BillingCheckoutSession::query()
            ->where('stripe_session_id', $sessionId)
            ->where('intent', CheckoutIntent::SetupPaymentMethod->value)
            ->first();
        if ($session === null) {
            throw new RuntimeException("auto-recharge setup webhook: 未追跡 session {$sessionId} (DB 行なし、再送待ち)");
        }

        $organization = $session->organization;
        Assert::isInstanceOf($organization, Organization::class);

        // tenant キー不信: payload の customer は照合のみ (org 解決は DB 行 → relation)
        $customerId = $this->stringAt($payload, 'data.object.customer');
        if ($customerId === null || $organization->stripe_id !== $customerId) {
            throw new RuntimeException("auto-recharge setup webhook: customer 照合不一致 (session {$sessionId})");
        }

        $setupIntentId = $this->resolveStripeIdField(data_get($payload, 'data.object.setup_intent'));
        if ($setupIntentId === null) {
            throw new RuntimeException("auto-recharge setup webhook: setup_intent 欠落 (session {$sessionId})");
        }

        $organizationId = $organization->getKey();
        Assert::integer($organizationId);

        // 台帳の completed 化と PM 既定設定 job の投入を同一 tx で括る (AG-114 確定 1)。
        // status だけ completed になって job が投入されないと、PM が既定にならないまま
        // 「設定完了」の表示になる。
        DB::transaction(function () use ($session, $organizationId, $setupIntentId): void {
            if ($session->status !== CheckoutSessionStatus::Completed->value) {
                $session->status = CheckoutSessionStatus::Completed->value;
                $session->completed_at = now();
                $session->save();
            }

            SetDefaultPaymentMethodJob::dispatch($organizationId, $setupIntentId);
        });
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function grantPurchasedTickets(array $payload): void
    {
        // (1) purpose ガード: ticket_purchase 以外 (サブスク checkout / 他 purpose / mode≠payment) は受理のみ
        if ($this->stringAt($payload, 'data.object.metadata.purpose') !== 'ticket_purchase') {
            return;
        }
        if ($this->stringAt($payload, 'data.object.mode') !== 'payment') {
            return;
        }

        $sessionId = $this->stringAt($payload, 'data.object.id');
        if ($sessionId === null) {
            throw new RuntimeException('checkout.session.completed: session id 欠落 (ticket_purchase)');
        }

        // (2) 真実源は自 DB 行。行不在は retryable failure (crash 先着 webhook は同一 attempt の
        //     再試行で DB 行が記録された後、Stripe の event 再送で本経路に収束する)
        $session = TicketCheckoutSession::query()->where('stripe_session_id', $sessionId)->first();
        if ($session === null) {
            throw new RuntimeException("ticket purchase webhook: 未追跡 session {$sessionId} (DB 行なし、再送待ち)");
        }

        // (3) tenant キー不信: payload の customer / metadata.org_ref は照合のみ。不一致は throw (fail-closed)
        $organization = $session->organization;
        Assert::isInstanceOf($organization, Organization::class);
        $customerId = $this->stringAt($payload, 'data.object.customer');
        if ($customerId === null || $organization->stripe_id !== $customerId) {
            throw new RuntimeException("ticket purchase webhook: customer 照合不一致 (session {$sessionId})");
        }
        // org_ref は照合専用 (認可・org 解決には使わない。真実源は DB 行 → organization relation)
        $metaOrgRef = $this->stringAt($payload, 'data.object.metadata.org_ref');
        if ($metaOrgRef !== (string) $organization->id) {
            throw new RuntimeException("ticket purchase webhook: metadata org_ref 照合不一致 (session {$sessionId})");
        }

        // (4) payment_status=paid 必須 (card 固定下の防御線。未決済 completed を付与しない)
        if ($this->stringAt($payload, 'data.object.payment_status') !== 'paid') {
            throw new RuntimeException("ticket purchase webhook: payment_status が paid でない (session {$sessionId})");
        }

        // (5) 金額照合: amount_subtotal === count × pin 単価、currency === pin (欠落・不一致は throw)。
        //     amount_total は税・割引の運用設定ドリフトで壊れるため使わない
        //     (作成側でも promo / automatic tax を使わない構成に固定 = 二重防御)
        $amountSubtotal = data_get($payload, 'data.object.amount_subtotal');
        $currency = $this->stringAt($payload, 'data.object.currency');
        if (! is_int($amountSubtotal)
            || $amountSubtotal !== $session->ticket_count * $session->unit_amount
            || $currency !== $session->currency) {
            // expected/actual を記録する (failed 連鎖時の運用復旧を高速化)
            throw new RuntimeException(sprintf(
                'ticket purchase webhook: 金額/通貨照合不一致 (session %s, expected %d %s, actual %s %s)',
                $sessionId,
                $session->ticket_count * $session->unit_amount,
                $session->currency,
                is_int($amountSubtotal) ? (string) $amountSubtotal : 'missing',
                $currency ?? 'missing',
            ));
        }

        // (6) 冪等付与 (idempotency_key purchase:{sessionId} UNIQUE) + 行 completed 化 (同一 TX)
        $paymentIntentId = $this->stringAt($payload, 'data.object.payment_intent');
        DB::transaction(function () use ($organization, $session, $amountSubtotal, $paymentIntentId): void {
            $this->tickets->grantPurchased(
                $organization,
                $session->ticket_count,
                $session->stripe_session_id,
                $paymentIntentId,
                $amountSubtotal, // 返金按分の分母 (clawback が使う)
            );
            if ($session->status !== TicketCheckoutSessionStatus::Completed) {
                $session->status = TicketCheckoutSessionStatus::Completed;
                $session->completed_at = CarbonImmutable::now();
                $session->save();
            }
        });
    }

    /**
     * charge.refunded: 買い切りチケットを累積返金額に応じて逆仕訳 (clawback) する。
     * payment_intent が無い charge (手動 charge 等) は対象外。
     *
     * @param  array<mixed>  $payload
     */
    private function clawbackRefundedTickets(array $payload): void
    {
        $paymentIntentId = $this->stringAt($payload, 'data.object.payment_intent');
        if ($paymentIntentId === null) {
            return;
        }

        $amountRefunded = data_get($payload, 'data.object.amount_refunded');
        $this->tickets->clawbackPurchasedByPaymentIntent(
            $paymentIntentId,
            is_int($amountRefunded) ? $amountRefunded : 0,
        );
    }

    /**
     * invoice の対象プランを解決する。invoice 明細の price → plan_prices 逆引きを優先し、
     * 取れなければ organizations.plan_code に fallback (順序逆転への防御)。
     *
     * @param  array<mixed>  $payload
     */
    private function resolveInvoicePlan(array $payload, Organization $organization): ?Plan
    {
        $priceId = $this->stringAt($payload, 'data.object.lines.data.0.price.id')
            ?? $this->stringAt($payload, 'data.object.lines.data.0.pricing.price_details.price');

        if ($priceId !== null) {
            $plan = $this->planByStripePriceId($priceId);
            if ($plan !== null) {
                return $plan;
            }
        }

        $plan = $organization->plan;

        return $plan instanceof Plan ? $plan : null;
    }

    /**
     * payload の customer (stripe_id) から組織を解決する。
     * 不明な customer は受理のみで終わる (他環境の webhook 等)。
     *
     * @param  array<mixed>  $payload
     */
    private function resolveOrganization(array $payload): ?Organization
    {
        $customerId = $this->stringAt($payload, 'data.object.customer');
        if ($customerId === null) {
            return null;
        }

        return Organization::query()->where('stripe_id', $customerId)->first();
    }

    private function planByStripePriceId(string $priceId): ?Plan
    {
        $price = PlanPrice::query()->where('stripe_price_id', $priceId)->first();
        $plan = $price?->plan;

        return $plan instanceof Plan ? $plan : null;
    }

    /**
     * payload から string 値を安全に取り出す (それ以外の型は null)。
     *
     * @param  array<mixed>  $payload
     */
    private function stringAt(array $payload, string $path): ?string
    {
        $value = data_get($payload, $path);

        return is_string($value) ? $value : null;
    }
}
