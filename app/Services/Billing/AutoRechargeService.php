<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\AutoRechargeConsentDto;
use App\DataTransferObjects\Billing\AutoRechargeConsentTermsDto;
use App\DataTransferObjects\Billing\AutoRechargeSettingsDto;
use App\Enums\Billing\AutoRechargeAttemptStatus;
use App\Enums\Billing\AutoRechargeDisabledReason;
use App\Enums\Billing\BillingNotificationType;
use App\Enums\Billing\SignupFundingChoice;
use App\Enums\CheckoutIntent;
use App\Enums\CheckoutSessionStatus;
use App\Enums\Security\ExternalCallKind;
use App\Exceptions\Billing\CheckoutInProgressException;
use App\Jobs\Billing\ExecuteAutoRechargeAttemptJob;
use App\Models\Billing\BillingCheckoutSession;
use App\Models\Billing\TicketAutoRecharge;
use App\Models\Billing\TicketAutoRechargeAttempt;
use App\Models\Billing\TicketVolumePrice;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\Billing\AutoRechargeActionRequiredNotification;
use App\Notifications\Billing\AutoRechargeDisabledNotification;
use App\Notifications\Billing\AutoRechargeEnabledNotification;
use App\Notifications\Billing\AutoRechargeFailedNotification;
use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
use App\Support\JobExecution\AttemptOwnershipPreflight;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * P8a: オートリチャージ (裏チャージ) の中核サービス。**opt-in・既定 off**。
 *
 * 責務境界: ledger (TicketLedgerEntry) = 残高の唯一の真実源 (返金逆引きの正本も同一台帳の
 * payment_intent_id / purchase_amount。D30 で `ticket_purchases` の両建ては作らない) /
 * attempt = リチャージ試行の状態機械 (本サービスの管轄)。
 *
 * 課金の不変条件 (AGENTS.md セキュリティ不変条件 #7):
 *  - quantity は attempt 作成 (org 行ロック TX 内) で一度だけ確定する
 *  - org に pending attempt は 1 つまで (アプリロック + DB partial unique の二層)
 *  - failed / canceled への遷移は invoice の終端 (void/delete) 成功後のみ (遅延成功の二重課金排除)
 *  - SCA (authentication_required) は終端させない (pending 維持 + 復旧導線。期限切れはリコンサイル)
 *  - 付与は `recharge:{invoiceId}` の ledger 冪等 (webhook / 同期 pay / リコンサイルのどれが先でも 1 回)
 *
 * 閾値判定・数量確定は **`TicketLedgerService::availableTrueBalance()`** を使う
 * (表示用 `balance()->totalAvailable()` は clamp 済みで判定に使うと過剰補充になる)。
 */
final class AutoRechargeService
{
    /**
     * org 単位 `Cache::lock` の TTL (秒)。updateSettings (cancelPendingAttempts の
     * terminateInvoice) と executeAttempt (invoice create/pay) の両方が lock 内で外向き
     * Stripe API を呼ぶため、Stripe client timeout より十分長く統一する
     * (TTL 失効による直列化の破れを防ぐ)。block 待機は短いまま
     * (競合時は no-op / リコンサイル再試行)。
     *
     * ★これは**入口の排他**であり、結果の一回性を保証しない (裁定 AG-082)。
     *   保証は (a) 外部呼び出し直前の preflight、(b) `where status=pending` の条件付き UPDATE、
     *   (c) Stripe idempotency key が担う。
     * ★したがって値は「保証を代替できる長さ」ではなく**短い側**に倒す。
     *   `JobExclusionOrderingInvariantTest` が
     *   `LOCK_TTL_SECONDS < queue.connections.database.retry_after` を CI 固定する
     *   (鍵の残留が正当な再実行を封鎖する時間が、キューの再配送間隔を超えない)。
     *   **可視性が public なのは不変条件の契約としての意図的な公開**である
     *   (T127 で既定キュー接続が分割されたら、上記テストの比較先を差し替えること)。
     */
    public const int LOCK_TTL_SECONDS = 180;

    public function __construct(
        private readonly TicketLedgerService $tickets,
        private readonly TicketPricingService $pricing,
        private readonly AutoRechargeGatewayInterface $gateway,
        private readonly BillingNotificationDispatcher $notifications,
        private readonly AttemptOwnershipPreflight $preflight,
    ) {}

    // ------------------------------------------------------------------
    // 設定 (Inertia props / upsert)
    // ------------------------------------------------------------------

    /**
     * 設定行の enabled のみの軽量解決 (PM 状態は見ない)。購入ページの転換バナー出し分け用途 —
     * props 構築で settingsFor のカタログ解決コストを払わない。
     */
    public function isEnabledFor(Organization $organization): bool
    {
        return TicketAutoRecharge::query()
            ->where('organization_id', $organization->getKey())
            ->where('enabled', true)
            ->exists();
    }

    public function settingsFor(Organization $organization, bool $canManage): AutoRechargeSettingsDto
    {
        $config = $this->configFor($organization);

        $pmBrand = null;
        $pmLast4 = null;
        $hasPm = false;
        if ($config?->stripe_payment_method_id !== null) {
            $hasPm = true;
            // brand/last4 は organizations の Cashier snapshot (pm_type/pm_last_four) を第一出典に
            // する (props 構築で Stripe API を撃たない)。
            $pmBrand = $organization->pm_type;
            $pmLast4 = $organization->pm_last_four;
        }

        // 再同意要否 (共通判定 reconsentRequiredFor): version 改定・価格改定・上限超過のいずれか。
        // true の間は createAttemptLocked の同一判定により自動購入が停止している。
        $requiresReconsent = $config !== null && $config->enabled
            && $this->reconsentRequiredFor($config, $config->max_count);

        // 有効な事前同意が待機中 (= カード登録が完了すれば applySetupCompletion が自動有効化する
        // 状態)。PM 有無は必ず local snapshot (stripe_payment_method_id) で判定する — gateway 側
        // default PM を参照すると setDefaultPaymentMethod 後〜snapshot 反映前の窓で false になり、
        // フォールバック同意ダイアログが誤オープンする。
        $pendingAutoEnable = $config !== null
            && $config->stripe_payment_method_id === null
            && $this->autoEnableEligible($config);

        // 「処理中」判定:
        //  (a) P8a: カード登録 (mode=setup) Checkout 完了済みだが PM snapshot 未反映
        //  (b) P9/T1004: funding=auto_recharge の有償契約が決済確定し、PM 流用 Job の収束待ち
        // (b) は **pendingAutoEnable=true のときだけ** 効かせる (v1 失効・再同意が必要な org で
        // 30 分間カード登録 CTA / 再同意導線を隠さないため)。
        $setupPending = ! $hasPm && (
            $this->hasRecentCompletedSetup($organization)
            || ($pendingAutoEnable && $this->hasRecentAutoRechargeFundedSignup($organization))
        );

        return new AutoRechargeSettingsDto(
            enabled: $config !== null && $config->enabled,
            thresholdCount: $config !== null ? $config->threshold_count : $this->defaultThreshold(),
            maxCount: $config !== null ? $config->max_count : $this->defaultMax(),
            minCount: TicketVolumePrice::PURCHASE_MIN_COUNT,
            maxCountLimit: $this->maxCountLimit(),
            canManage: $canManage,
            hasPaymentMethod: $hasPm,
            paymentMethodBrand: $pmBrand,
            paymentMethodLast4: $pmLast4,
            setupPending: $setupPending,
            requiresReconsent: $requiresReconsent,
            pendingAutoEnable: $pendingAutoEnable,
            disabledReason: $config?->disabled_reason?->value,
            failureCount: $config !== null ? $config->failure_count : 0,
            consentVersion: $this->currentConsentVersion(),
            baseUnitAmountJpy: $this->pricing->spotUnitAmount(),
            tiers: $this->pricing->volumeTiersForDisplay(),
        );
    }

    /**
     * 設定 upsert。有効化は fail-closed (default PM 必須 + 同意必須)。無効化は常に成功する。
     */
    public function updateSettings(
        Organization $organization,
        User $user,
        bool $enabled,
        int $threshold,
        int $max,
        ?AutoRechargeConsentDto $consent,
    ): TicketAutoRecharge {
        Assert::greaterThan($max, $threshold, 'リチャージ上限は閾値より大きい必要があります');
        Assert::lessThanEq($max, $this->maxCountLimit());
        Assert::greaterThanEq($threshold, 0);

        $lock = Cache::lock($this->lockName($organization), self::LOCK_TTL_SECONDS);

        try {
            /** @var TicketAutoRecharge $result */
            $result = $lock->block(5, function () use ($organization, $user, $enabled, $threshold, $max, $consent): TicketAutoRecharge {
                if ($enabled) {
                    // 有効化 fail-closed: default PM が存在しなければ拒否 (422)。
                    if (! $this->gateway->getDefaultPaymentMethodState($organization)->exists()) {
                        throw ValidationException::withMessages([
                            'enabled' => 'オートリチャージを有効にするには、先にお支払いカードを登録してください。',
                        ]);
                    }
                }

                $config = DB::transaction(function () use ($organization, $user, $enabled, $threshold, $max, $consent): TicketAutoRecharge {
                    $config = $this->lockedConfigFor($organization);

                    $attrs = [
                        'enabled' => $enabled,
                        'threshold_count' => $threshold,
                        'max_count' => $max,
                    ];

                    if ($enabled) {
                        // 同意判定: 共通判定 reconsentRequiredFor (version 改定/上限超過/価格改定)。
                        // 新しい $max で評価する (Max 引き上げは同意時上限超過として検出される)。
                        $needsConsent = $config === null || $this->reconsentRequiredFor($config, $max);

                        if ($needsConsent) {
                            if ($consent === null || $consent->version !== $this->currentConsentVersion()) {
                                throw ValidationException::withMessages([
                                    'consent_version' => '自動購入の同意内容が更新されています。内容を確認して再度同意してください。',
                                ]);
                            }
                            // 同意金額はサーバ再計算 (client hidden は信用しない)。
                            $attrs['consented_at'] = CarbonImmutable::now();
                            $attrs['consent_version'] = $consent->version;
                            $attrs['consented_max_count'] = $max;
                            $attrs['consented_max_amount'] = $this->maxChargeAmountFor($max);
                        }

                        // 再有効化で失敗状態をリセット。
                        $attrs['failure_count'] = 0;
                        $attrs['disabled_reason'] = null;
                        // PM snapshot が空なら gateway の現状で補完 (setup Job 未達の間に有効化された場合)。
                        if ($config?->stripe_payment_method_id === null) {
                            $attrs['stripe_payment_method_id'] = $this->gateway->getDefaultPaymentMethodState($organization)->paymentMethodId;
                        }
                    } elseif ($config?->enabled === true) {
                        // **停止操作のときだけ** User を刻む (稼働中 → 停止の遷移)。
                        // カード未登録時の「設定を保存」(enabled=false の upsert) で刻むと、
                        // 事前同意済み行 (disabled_reason=null) が自動有効化の適格性
                        // (autoEnableEligible) を失い、カード登録完了しても有効にならない。
                        // 非遷移の保存では既存の理由 (payment_failures 等) をそのまま保つ。
                        $attrs['disabled_reason'] = AutoRechargeDisabledReason::User;
                    }

                    return $this->persistConfig($organization, $config, $attrs, $user);
                });

                if (! $enabled) {
                    // 停止後課金の禁止。停止時点の pending attempt を同一 lock 下でキャンセルする
                    // (invoice 終端成功後のみ canceled 遷移。既に paid の invoice は終端できず
                    // pending 維持 → リコンサイルが付与して収束 = 回収済み資金は必ずチケットになる)。
                    $this->cancelPendingAttempts($organization);
                }

                return $config;
            });

            return $result;
        } catch (LockTimeoutException $e) {
            // ユーザーの明示操作のみ UX エラーへ変換 (background trigger は structured no-op)。
            throw new CheckoutInProgressException('別の変更操作が進行中です。数秒お待ちください。', previous: $e);
        }
    }

    /**
     * カード登録 (Checkout mode=setup) を開始する。attempt_token 冪等は purchase-tickets と同型。
     *
     * @return array{id: string, url: string|null}
     */
    public function startSetupCheckout(
        Organization $organization,
        User $user,
        string $successUrl,
        string $cancelUrl,
        string $attemptToken,
    ): array {
        Assert::stringNotEmpty($attemptToken);

        $idempotencyKey = 'auto-recharge-setup:'.$attemptToken;

        $result = $this->gateway->createSetupCheckout(
            $organization,
            $successUrl,
            $cancelUrl,
            [
                'purpose' => 'auto_recharge_setup',
                'organization_id' => (string) $this->orgId($organization),
            ],
            $idempotencyKey,
        );

        // 台帳記録 (webhook の intent 照合 / setupPending 判定の出典)。attempt_token unique で
        // 二重 submit は冪等 (unique violation は既存行の再利用として握る)。
        // insert は DB::transaction (= 外側 TX 下では savepoint) で包む — unique violation が
        // 呼び出し元 TX を abort させない (pgsql の 25P02 連鎖を避ける)。
        try {
            DB::transaction(function () use ($organization, $user, $result, $attemptToken, $idempotencyKey): void {
                $session = new BillingCheckoutSession;
                // tenant / actor キーは relation 経由で明示代入する (mass assignment しない)
                $session->organization()->associate($organization);
                $session->initiated_by_user_id = $user->id;
                $session->fill([
                    'intent' => CheckoutIntent::SetupPaymentMethod->value,
                    'plan_code' => null,
                    'stripe_session_id' => $result['id'],
                    'status' => CheckoutSessionStatus::Pending->value,
                    'idempotency_key' => $idempotencyKey,
                    'attempt_token' => $attemptToken,
                    'checkout_url' => $result['url'],
                ]);
                $session->save();
            });
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }
            // 同一 attempt_token の replay — Stripe 側も同一冪等キーで同一 session を返している。
        }

        return $result;
    }

    /**
     * D29(i): オンボーディング同意提示 + 事前同意記録が共有する「同意条件」の単一計算源。
     * ここで返す値がそのまま画面に表示され、recordPreConsent がそのまま記録する。
     */
    public function consentTermsFor(): AutoRechargeConsentTermsDto
    {
        $threshold = $this->defaultThreshold();
        $max = $this->defaultMax();
        $tier = TicketVolumePrice::currentTierFor($max);

        return new AutoRechargeConsentTermsDto(
            thresholdCount: $threshold,
            maxCount: $max,
            maxAmountJpy: $tier->unitAmount * $max,
            unitAmountJpy: $tier->unitAmount,
            consentVersion: $this->currentConsentVersion(),
        );
    }

    /**
     * D29(i): オンボーディングでの事前同意を記録する (enabled=false のまま)。
     *
     * fail-closed: client から受けるのは consent_version のみで、現在版と完全一致しなければ 422
     * (画面表示と異なる条件での同意記録を排除)。記録する枚数・金額はサーバ再計算値のみ
     * (consentTermsFor と同一計算源)。enabled 済み設定は上書きしない (運用値と同意の保全)。
     * 既存 row が disabled_reason を持つ場合もそれを消さない (自動有効化は autoEnableEligible で
     * 止まる)。既に PM snapshot がある row への同意も enabled にしない (pendingAutoEnable も
     * false — 有効化は請求ページの既存 UI に委ねる)。
     */
    public function recordPreConsent(Organization $organization, User $user, AutoRechargeConsentDto $consent): TicketAutoRecharge
    {
        if ($consent->version !== $this->currentConsentVersion()) {
            throw ValidationException::withMessages([
                'consent_version' => '自動購入の同意内容が更新されています。ページを再読み込みして内容を確認してください。',
            ]);
        }

        $terms = $this->consentTermsFor();
        $lock = Cache::lock($this->lockName($organization), self::LOCK_TTL_SECONDS);

        try {
            /** @var TicketAutoRecharge $result */
            $result = $lock->block(5, fn (): TicketAutoRecharge => DB::transaction(
                function () use ($organization, $user, $terms): TicketAutoRecharge {
                    $config = $this->lockedConfigFor($organization);

                    if ($config !== null && $config->enabled) {
                        return $config; // 稼働中設定は上書きしない
                    }

                    return $this->persistConfig($organization, $config, [
                        'enabled' => false,
                        'threshold_count' => $terms->thresholdCount,
                        'max_count' => $terms->maxCount,
                        'consented_at' => CarbonImmutable::now(),
                        'consent_version' => $terms->consentVersion,
                        'consented_max_count' => $terms->maxCount,
                        'consented_max_amount' => $terms->maxAmountJpy,
                    ], $user);
                },
            ));

            return $result;
        } catch (LockTimeoutException $e) {
            throw new CheckoutInProgressException('別の変更操作が進行中です。数秒お待ちください。', previous: $e);
        }
    }

    /**
     * org の pending attempt を全てキャンセル試行する (ユーザー停止時)。
     * 終端 (void/delete) に失敗した attempt は pending 維持 — 遅延成功はリコンサイル (ii) が
     * 付与で収束し、未回収はリコンサイル (iv) が期限切れ終端する。
     */
    private function cancelPendingAttempts(Organization $organization): void
    {
        $pendings = TicketAutoRechargeAttempt::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', AutoRechargeAttemptStatus::Pending->value)
            ->get();

        foreach ($pendings as $attempt) {
            $this->terminateAndCancel($attempt);
        }
    }

    // ------------------------------------------------------------------
    // attempt 起票 (トリガ/リコンサイル共通の唯一の起票口)
    // ------------------------------------------------------------------

    /**
     * 閾値判定 + attempt 起票。作られなかったら null (無効/閾値以上/pending あり/ロック競合)。
     * lock 取得失敗は structured no-op — バックグラウンドトリガの競合は次回 reserve /
     * リコンサイルが拾うため UX エラーにしない。
     */
    public function maybeCreateAttempt(Organization $organization): ?TicketAutoRechargeAttempt
    {
        $lock = Cache::lock($this->lockName($organization), self::LOCK_TTL_SECONDS);

        try {
            /** @var TicketAutoRechargeAttempt|null $attempt */
            $attempt = $lock->block(3, fn (): ?TicketAutoRechargeAttempt => $this->createAttemptLocked($organization));

            return $attempt;
        } catch (LockTimeoutException) {
            Log::info('auto-recharge: lock busy, skipping trigger (background no-op)', [
                'organization_id' => $organization->getKey(),
            ]);

            return null;
        }
    }

    private function createAttemptLocked(Organization $organization): ?TicketAutoRechargeAttempt
    {
        try {
            return DB::transaction(function () use ($organization): ?TicketAutoRechargeAttempt {
                // reserve() と同順の organizations 行ロックで残高評価〜起票を直列化する
                // (ロック順序の交差を作らない)。
                $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->first();
                Assert::isInstanceOf($locked, Organization::class);

                $config = $this->configFor($locked);
                if ($config === null || ! $config->enabled) {
                    return null;
                }

                $pendingExists = TicketAutoRechargeAttempt::query()
                    ->where('organization_id', $locked->getKey())
                    ->where('status', AutoRechargeAttemptStatus::Pending->value)
                    ->exists();
                if ($pendingExists) {
                    return null;
                }

                // 真値残高 (与信と同一意味論) で再評価。閾値以上に回復していれば no-op。
                // **表示用 balance() ではなく availableTrueBalance()** — clamp 済みの表示値で
                // 判定すると返金債務を隠して過剰補充する。
                $balance = $this->tickets->availableTrueBalance($locked);
                if ($balance >= $config->threshold_count) {
                    return null;
                }

                // quantity はこの一点で確定し、以降 attempt.quantity が真実源。
                // availableTrueBalance は構造的に >= 0 (per-source max(...,0)) のため
                // quantity <= max_count。これが同意上限の不変条件になるのは、下の tier pin
                // (単価を max_count の tier に固定) と**セット**のときのみ (単価を quantity で
                // 引き直すと総額が同意上限を超え得る)。
                $quantity = min($config->max_count - $balance, TicketVolumePrice::PURCHASE_MAX_COUNT);
                Assert::greaterThan($quantity, 0);

                // 同意の hard invariant。UI の requiresReconsent / updateSettings の needsConsent と
                // **同一の共通判定** (version 改定・上限超過・価格改定) で評価し、再同意が必要な間は
                // 起票しない (UI 文言「再同意まで自動購入は行われません」と完全に一致する。設定上の
                // max_count 基準 — quantity <= max_count かつ総額は数量に単調のため、これが binding)。
                if ($this->reconsentRequiredFor($config, $config->max_count)) {
                    Log::warning('auto-recharge: skipping attempt, re-consent required', [
                        'organization_id' => $locked->getKey(),
                        'consent_version' => $config->consent_version,
                        'consented_max_count' => $config->consented_max_count,
                        'consented_max_amount' => $config->consented_max_amount,
                    ]);

                    return null;
                }

                // 適用単価は **quantity ではなく同意した max_count の tier** で pin する。
                // 逐減単価表では単価が数量に対して減少するため総額 (q × unit(q)) は数量に単調でなく、
                // quantity < max_count のとき単価が上がって **同意上限額 (unit(max) × max) を超過し得る**
                // (既定 threshold=5 / max=50 でも quantity=46..49 は 1 段上の単価に落ちる)。
                // 同意時 tier で pin すれば実請求額 = quantity × unit(max) <= max_count × unit(max)
                // = consented_max_amount が **単価表の形状に依存せず無条件で成立**する。
                // UI (AutoRechargeCard の「1 枚あたり」表示) も同じ Max 枚 tier 単価を提示しており、
                // 表示・同意・実請求の 3 者がこれで一致する。
                $tier = TicketVolumePrice::currentTierFor($config->max_count);

                $attempt = new TicketAutoRechargeAttempt;
                $attempt->organization()->associate($locked);
                $attempt->fill([
                    'attempt_ulid' => strtolower((string) Str::ulid()),
                    'status' => AutoRechargeAttemptStatus::Pending->value,
                    'quantity' => $quantity,
                    'unit_amount' => $tier->unitAmount,
                    'stripe_price_id' => $tier->stripePriceId,
                ]);
                $attempt->save();

                return $attempt;
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                // DB partial unique (tar_attempts_org_pending_unique) が最終防衛。並行起票は no-op。
                return null;
            }

            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // attempt 実行 (課金)
    // ------------------------------------------------------------------

    /**
     * pending attempt を実行する: invoice 作成 → invoice_id 永続化 (pay より前・必達) → 課金。
     *
     * updateSettings (停止 + pending キャンセル) と**同一の org lock**で直列化する。lock 内では
     * disable が割り込めないため、「enabled 確認 → invoice 作成 → invoice_id 保存 → pay」の
     * 全区間で停止後課金が構造的に起こらない。
     * lock 取得失敗は structured no-op — リコンサイル (i) が再実行する。
     */
    public function executeAttempt(TicketAutoRechargeAttempt $attempt): void
    {
        $organization = $attempt->organization;
        Assert::isInstanceOf($organization, Organization::class);

        $lock = Cache::lock($this->lockName($organization), self::LOCK_TTL_SECONDS);

        try {
            $lock->block(10, function () use ($organization, $attempt): void {
                $this->executeAttemptLocked($organization, $attempt);
            });
        } catch (LockTimeoutException) {
            Log::info('auto-recharge: lock busy, skipping execution (reconcile will retry)', [
                'attempt_ulid' => $attempt->attempt_ulid,
            ]);
        }
    }

    private function executeAttemptLocked(Organization $organization, TicketAutoRechargeAttempt $attempt): void
    {
        // lock 取得後に fresh 再読込 (停止側のキャンセルが先行していたら no-op)。
        $attempt->refresh();
        if ($attempt->status !== AutoRechargeAttemptStatus::Pending) {
            return;
        }

        // 停止後課金の禁止: lock 内で enabled を確認 (以降 disable は本実行の完了まで割り込めない)。
        if (! $this->isEnabledFor($organization)) {
            $this->terminateAndCancel($attempt);

            return;
        }

        $keyBase = $this->idempotencyKeyBase($attempt);

        $invoiceId = $attempt->stripe_invoice_id;
        if ($invoiceId === null) {
            // ★ preflight 1: invoice 作成の直前。org lock は TTL 180 秒で切れうるため
            //   (lock は best-effort。保証は本再検証と条件付き UPDATE と Stripe 冪等キー)。
            if (! $this->preflight->stillPending($attempt, ExternalCallKind::StripeInvoiceCreate)) {
                return; // invoice 未作成なので収束は自明 (残す open invoice が無い)
            }

            $invoiceId = $this->gateway->createAutoRechargeInvoice(
                $organization,
                $attempt->stripe_price_id,
                $attempt->quantity,
                $this->metadataFor($organization, $attempt),
                $keyBase,
            );

            // invoice_id の永続化は pay より必ず前 (プロセス死でも迷子 invoice を作らない)。
            // ★ **条件付き UPDATE** にする: 素の save() だと停止側が先に canceled 化した
            //   terminal 行へ invoice_id を後から書き込むことになり、状態機械の例外を作る。
            //   0 行なら「attempt へ紐付けられなかった invoice」であり、
            //   DB の値に依存せずローカルの $invoiceId で終端する。
            $attached = TicketAutoRechargeAttempt::query()
                ->whereKey($attempt->id)
                ->where('status', AutoRechargeAttemptStatus::Pending->value)
                ->update([
                    'stripe_invoice_id' => $invoiceId,
                    'updated_at' => CarbonImmutable::now(),
                ]);

            if ($attached !== 1) {
                // ★ attach 失敗は **status を問わず**終端する。
                //   この invoice ID を知っているのは自分だけであり、
                //   terminal 化させた側は stripe_invoice_id === null を見ているため終端できない。
                $this->terminateUnattachedInvoice($attempt->refresh(), $invoiceId);

                return;
            }
            // in-memory 同期 (再 save しない)
            $attempt->forceFill(['stripe_invoice_id' => $invoiceId])->syncOriginal();
        }

        // ★ preflight 2: pay の直前。**直前に自前の書き込み (invoice_id の永続化) を挟んだため
        //   必ずもう一度検証する** (裁定 AG-082: 検証の後に自前の書き込みを挟むと、
        //   接続断で旧担当が送信できる窓が開く)。
        //   既存 invoice を再利用する経路 (上の if を通らない場合) でもここが唯一の関門になる。
        if (! $this->preflight->stillPending($attempt, ExternalCallKind::StripeInvoicePay)) {
            $this->terminateInvoiceAfterOwnershipLost($attempt, $invoiceId);

            return;
        }

        $result = $this->gateway->payOffSessionInvoice($invoiceId, $keyBase);

        if ($result->paid) {
            $amountPaid = $result->amountPaid;
            $amountDue = $result->amountDue;
            Assert::integer($amountPaid);
            Assert::integer($amountDue);
            $this->recordSuccessfulCharge($organization, $attempt, $invoiceId, $amountPaid, $amountDue, $result->paymentIntentId);

            return;
        }

        $this->handleChargeFailure($organization, $attempt, $result->failureCode, $result->requiresAction());
    }

    /**
     * preflight 2 で中断したときの invoice 後始末。
     *
     * **canceled のときだけ**終端する:
     *  - paid  … void できない (付与経路の管轄)
     *  - failed… `terminateAndFail()` が **`stripe_invoice_id` を DB 経由で見えている状態**で
     *    終端済み (attach 済みだからこの分岐に来ている)
     *  - canceled … 停止側の `tryTerminateInvoice()` は `stripe_invoice_id === null` を
     *    「invoice 未作成」と解釈して素通りするため、こちらの永続化が停止より後だと
     *    **誰も void しない open invoice が残る**。ここで拾う。
     *
     * ★ attach に失敗した invoice は本メソッドではなく `terminateUnattachedInvoice()` の担当
     *   (あちらは status を問わず終端する)。
     */
    private function terminateInvoiceAfterOwnershipLost(
        TicketAutoRechargeAttempt $attempt,
        string $invoiceId,
    ): void {
        if ($attempt->status !== AutoRechargeAttemptStatus::Canceled) {
            return; // アーリーリターン
        }

        $this->terminateInvoiceBestEffort($attempt, $invoiceId);
    }

    /**
     * attempt 行へ紐付けられなかった (条件付き UPDATE が 0 行だった) invoice の後始末。
     *
     * ★ **status を問わず終端を試みる**。この invoice ID を知っているのは自分だけであり、
     *   terminal 化させた側は `stripe_invoice_id === null` を見ているため終端できない。
     *   canceled 限定にすると failed 経路で**誰も終端しない open invoice**が残る。
     * ★ `paid` の可能性は `CashierAutoRechargeGateway::terminateInvoice()` の状態検査が
     *   `Assert` で fail-closed に分類する (例外 → `terminated=false` としてログに残る)。
     */
    private function terminateUnattachedInvoice(
        TicketAutoRechargeAttempt $attempt,
        string $invoiceId,
    ): void {
        $this->terminateInvoiceBestEffort($attempt, $invoiceId);
    }

    /**
     * invoice の best-effort 終端 + 固定 event 名でのログ (上 2 つの共通部)。
     *
     * ★ `$invoiceId` を**引数で受ける**。attempt 行に永続化できなかった invoice も
     *   終端したいため、DB の値に依存しない。
     * ★ `tryTerminateInvoice($attempt)` を再利用しない理由: あちらは
     *   `$attempt->stripe_invoice_id` を読むため「永続化できなかった invoice」を扱えず、
     *   かつ独自の warning を出すのでログが二重になる。ここは固定 event の 1 行に閉じる。
     * ★ `CashierAutoRechargeGateway::terminateInvoice()` は Stripe から retrieve して
     *   void/deleted/404 → 成功扱い、paid → `Assert` で明示的な非成功、draft → delete、
     *   open/uncollectible → void と**状態検査で冪等化**されている
     *   (idempotency key より強い — 期限が無い)。
     * ★ 失敗しても**課金処理へは進まない** (呼び出し側が無条件に return する)。
     *   残った open invoice は reconcile の母集団外なので、運用契約 (docs/architecture.md) の
     *   手動収束に委ねる。
     * ★ **cleanup 専用の event 名**を使う。送信抑止の記録 (`LOG_EVENT`) は最小 7 キー schema を
     *   持つ契約であり、キー集合の違うログを同じ event 名に混ぜない。
     * ★ `error` に入れるのは**例外クラス名だけ**である (impl-review Round 2/3 反映)。
     *   Stripe SDK の例外メッセージは**外部サービスが生成する可変文字列**であり、
     *   いま既知の内容が invoice id と status だけでも、将来の SDK / API 応答で
     *   何が混ざるかの契約は無い。構造化ログには**アプリが決めた有界な語彙**だけを載せる。
     * ★ 例外報告も**原例外を渡さない** (impl-review Round 3 反映)。
     *   標準の exception handler は message とスタックトレースを記録するため、
     *   `report($exception)` では「保存場所を移しただけ」で外部生成文字列が残る。
     *   ここでは invoice id と例外クラス名だけを持つ**サニタイズ済み例外**を報告し、
     *   原例外は `previous` にも**繋がない** (reporter が previous chain を出力しうるため)。
     *   トリアージに必要な情報 (どの invoice が / どの種類の失敗か) は保たれる。
     */
    private function terminateInvoiceBestEffort(
        TicketAutoRechargeAttempt $attempt,
        string $invoiceId,
    ): void {
        $terminated = true;
        $error = null;
        try {
            $this->gateway->terminateInvoice($invoiceId);
        } catch (Throwable $exception) {
            $terminated = false;
            // paid 等の「明示的な非成功」もここに落ちる。分類できる有界な値 (クラス名) のみ記録する。
            $error = $exception::class;
            // 原例外は報告しない (外部生成メッセージ / previous chain をログ基盤へ流さない)。
            report(new RuntimeException(
                "auto-recharge: invoice {$invoiceId} の終端に失敗しました ({$error})",
            ));
        }

        Log::warning('auto-recharge: 所有権喪失後の invoice 終端', [
            'event' => ExternalCallKind::CLEANUP_LOG_EVENT,
            'job_type' => TicketAutoRechargeAttempt::class,
            'job_id' => $attempt->id,
            'attempt_ulid' => $attempt->attempt_ulid,
            'invoice_id' => $invoiceId,
            'terminated' => $terminated,
            'error' => $error,
        ]);
    }

    /**
     * 課金成功の確定: 冪等付与 + attempt paid 遷移 + failure_count リセット。
     * webhook (invoice.paid) / 同期 pay / リコンサイル (ii) の全経路がここに合流する。
     */
    public function recordSuccessfulCharge(
        Organization $organization,
        TicketAutoRechargeAttempt $attempt,
        string $invoiceId,
        int $amountPaid,
        int $amountDue,
        ?string $paymentIntentId,
    ): void {
        // amount cross-check (fail-closed): attempt に pin した単価 × 数量 = 請求額 (amount_due)。
        // 実回収額 (amount_paid) は customer credit balance の適用で amount_due より小さくなり得る
        // 正当ケースがあるため照合対象にしない。台帳の purchase_amount には実回収額を記録する。
        $expected = $attempt->unit_amount * $attempt->quantity;
        if ($amountDue !== $expected) {
            throw new RuntimeException(
                "auto-recharge amount mismatch for invoice {$invoiceId}: expected due {$expected}, got {$amountDue}",
            );
        }

        DB::transaction(function () use ($organization, $attempt, $invoiceId, $amountPaid, $paymentIntentId): void {
            $this->tickets->grantAutoRecharge($organization, $attempt->quantity, $invoiceId, $amountPaid, $paymentIntentId);

            $updated = TicketAutoRechargeAttempt::query()
                ->whereKey($attempt->id)
                ->where('status', AutoRechargeAttemptStatus::Pending->value)
                ->update([
                    'status' => AutoRechargeAttemptStatus::Paid->value,
                    'stripe_payment_intent_id' => $paymentIntentId,
                    'resolved_at' => CarbonImmutable::now(),
                    'updated_at' => CarbonImmutable::now(),
                ]);

            if ($updated === 1) {
                TicketAutoRecharge::query()
                    ->where('organization_id', $organization->getKey())
                    ->update(['failure_count' => 0, 'updated_at' => CarbonImmutable::now()]);
            }
        });
    }

    /**
     * 課金失敗の処理。SCA (authentication_required) は終端させない —
     * pending 維持 + failure_code 記録 + 復旧導線通知 (期限切れ終端はリコンサイル (iv) の管轄)。
     * それ以外 (card_declined 等の再試行不能失敗) は invoice 終端 → failed 遷移 + failure_count+1。
     */
    public function handleChargeFailure(
        Organization $organization,
        TicketAutoRechargeAttempt $attempt,
        ?string $failureCode,
        bool $requiresAction,
    ): void {
        // failure_code は観測のため常に記録 (pending のまま)。
        TicketAutoRechargeAttempt::query()
            ->whereKey($attempt->id)
            ->where('status', AutoRechargeAttemptStatus::Pending->value)
            ->update(['failure_code' => $failureCode, 'updated_at' => CarbonImmutable::now()]);

        if ($requiresAction) {
            $this->notifyActionRequired($organization, $attempt);

            return;
        }

        $this->terminateAndFail($organization, $attempt);
    }

    /**
     * invoice 終端 → failed 遷移 (+failure_count/自動停止)。終端失敗時は pending 維持で
     * リコンサイルが再試行する (終端保証を破らない)。
     */
    public function terminateAndFail(Organization $organization, TicketAutoRechargeAttempt $attempt): void
    {
        if (! $this->tryTerminateInvoice($attempt)) {
            return; // pending 維持 → リコンサイル再試行
        }

        if ($this->transitionToTerminal($attempt, AutoRechargeAttemptStatus::Failed)) {
            $this->notifyFailed($organization, $attempt);
        }
    }

    /**
     * invoice 終端 → canceled 遷移 (決済手段の問題ではない破棄。failure_count 増分なし)。
     */
    public function terminateAndCancel(TicketAutoRechargeAttempt $attempt): void
    {
        if (! $this->tryTerminateInvoice($attempt)) {
            return;
        }

        $this->transitionToTerminal($attempt, AutoRechargeAttemptStatus::Canceled);
    }

    private function tryTerminateInvoice(TicketAutoRechargeAttempt $attempt): bool
    {
        if ($attempt->stripe_invoice_id === null) {
            return true; // invoice 未作成 = 課金され得ない
        }

        try {
            $this->gateway->terminateInvoice($attempt->stripe_invoice_id);

            return true;
        } catch (Throwable $e) {
            Log::warning('auto-recharge: invoice termination failed, keeping attempt pending', [
                'attempt_ulid' => $attempt->attempt_ulid,
                'invoice_id' => $attempt->stripe_invoice_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * failed / canceled への唯一の遷移口。WHERE status='pending' ガードで 1 attempt = 1 遷移。
     * failed のときのみ failure_count+1 (= 1 attempt で複数の payment_failed イベントが来ても
     * 多重加算しない) し、連続失敗上限で自動停止する。
     *
     * @return bool 遷移が起きたか (false = 既に終端済みの再送)
     */
    private function transitionToTerminal(TicketAutoRechargeAttempt $attempt, AutoRechargeAttemptStatus $terminal): bool
    {
        Assert::true(
            $terminal === AutoRechargeAttemptStatus::Failed || $terminal === AutoRechargeAttemptStatus::Canceled,
            'transitionToTerminal は failed / canceled のみ',
        );

        // 自動停止の通知は **commit 後**に送る (TX 内で送ると通知系の例外で状態遷移ごと
        // ロールバックし、invoice 終端済みなのに attempt が pending に戻る = 収束先が変わる)。
        $disabledOrganization = null;

        $transitioned = DB::transaction(function () use ($attempt, $terminal, &$disabledOrganization): bool {
            $updated = TicketAutoRechargeAttempt::query()
                ->whereKey($attempt->id)
                ->where('status', AutoRechargeAttemptStatus::Pending->value)
                ->update([
                    'status' => $terminal->value,
                    'resolved_at' => CarbonImmutable::now(),
                    'updated_at' => CarbonImmutable::now(),
                ]);

            if ($updated !== 1) {
                return false;
            }

            if ($terminal === AutoRechargeAttemptStatus::Failed) {
                $config = TicketAutoRecharge::query()
                    ->where('organization_id', $attempt->organization_id)
                    ->lockForUpdate()
                    ->first();

                if ($config !== null) {
                    $config->failure_count += 1;
                    if ($config->enabled && $config->failure_count >= $this->maxFailures()) {
                        $config->enabled = false;
                        $config->disabled_reason = AutoRechargeDisabledReason::PaymentFailures;
                        $organization = $config->organization;
                        Assert::isInstanceOf($organization, Organization::class);
                        $disabledOrganization = $organization;
                    }
                    $config->save();
                }
            }

            return true;
        });

        if ($disabledOrganization instanceof Organization) {
            // 通知失敗で確定済みの停止をなかったことにしない (dedup は attempt 単位)。
            try {
                $this->notifyDisabled($disabledOrganization, $attempt);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $transitioned;
    }

    // ------------------------------------------------------------------
    // リコンサイル (scheduler)
    // ------------------------------------------------------------------

    /**
     * pending attempt の回収と取りこぼし起票 (5 分岐)。
     * webhook が terminal-ack で恒久 drop した「課金済み・付与なし」の唯一のセーフティネット。
     *
     * @return array{recovered_paid: int, retried: int, sca_reminded: int, expired: int, triggered: int}
     */
    public function reconcile(): array
    {
        $stats = ['recovered_paid' => 0, 'retried' => 0, 'sca_reminded' => 0, 'expired' => 0, 'triggered' => 0];
        $now = CarbonImmutable::now();
        $expiryHours = $this->pendingExpiryHours();

        $pendings = TicketAutoRechargeAttempt::query()
            ->where('status', AutoRechargeAttemptStatus::Pending->value)
            ->orderBy('id')
            ->get();

        foreach ($pendings as $attempt) {
            $organization = $attempt->organization;
            Assert::isInstanceOf($organization, Organization::class);
            $createdAt = $attempt->created_at;
            Assert::notNull($createdAt);
            $age = CarbonImmutable::instance($createdAt);

            try {
                if ($attempt->stripe_invoice_id === null) {
                    // (i) invoice 未作成: scheduler 周期 (15 分) 超で再実行。同一 key base で
                    // Stripe 冪等が効くため二重課金しない。
                    if ($age->addMinutes(15) <= $now) {
                        $this->executeAttempt($attempt);
                        $stats['retried']++;
                    }

                    continue;
                }

                $state = $this->gateway->retrieveInvoiceState($attempt->stripe_invoice_id);

                if ($state->status === 'paid') {
                    // (ii) webhook 未着 / terminal drop の回収。付与は ledger 冪等。
                    $amountPaid = $state->amountPaid;
                    $amountDue = $state->amountDue;
                    Assert::integer($amountPaid);
                    Assert::integer($amountDue);
                    $this->recordSuccessfulCharge($organization, $attempt, $attempt->stripe_invoice_id, $amountPaid, $amountDue, $state->paymentIntentId);
                    $stats['recovered_paid']++;

                    continue;
                }

                if ($state->status === 'void' || $state->status === 'deleted') {
                    // invoice は既に課金不能 — attempt を canceled で閉じる (終端保証は満たされている)。
                    $this->transitionToTerminal($attempt, AutoRechargeAttemptStatus::Canceled);
                    $stats['expired']++;

                    continue;
                }

                // SCA 判定は Stripe 側 PaymentIntent 状態 (state) を第一出典、attempt の
                // failure_code (同期 pay の CardException 記録) を補助にする (webhook 到着順に依存しない)。
                $isSca = $state->requiresAction || $attempt->failure_code === 'authentication_required';

                if ($age->addHours($expiryHours) <= $now) {
                    // (iv) 期限切れ終端。SCA 放置は failed (+failure_count) — 放置ループ防止。
                    // それ以外 (draft のまま等、決済手段の問題ではない) は canceled。
                    if ($isSca) {
                        $this->terminateAndFail($organization, $attempt);
                    } else {
                        $this->terminateAndCancel($attempt);
                    }
                    $stats['expired']++;

                    continue;
                }

                if ($isSca) {
                    // (iii) SCA 待ち: 日次リマインダ (dedup は JST date bucket)。
                    $this->notifyActionRequired($organization, $attempt);
                    $stats['sca_reminded']++;
                }
            } catch (Throwable $e) {
                // 1 attempt の失敗が他 org の回収を止めないよう隔離 (次周期で再試行)。
                Log::warning('auto-recharge reconcile: attempt processing failed', [
                    'attempt_ulid' => $attempt->attempt_ulid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // (v) 取りこぼし起票: enabled な org で閾値割れ・pending なし (job 消失の回収)。
        $configs = TicketAutoRecharge::query()->where('enabled', true)->orderBy('id')->get();
        foreach ($configs as $config) {
            $organization = $config->organization;
            Assert::isInstanceOf($organization, Organization::class);

            try {
                $attempt = $this->maybeCreateAttempt($organization);
                if ($attempt !== null) {
                    ExecuteAutoRechargeAttemptJob::dispatch($attempt->id);
                    $stats['triggered']++;
                }
            } catch (Throwable $e) {
                Log::warning('auto-recharge reconcile: trigger failed', [
                    'organization_id' => $organization->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    // ------------------------------------------------------------------
    // webhook 連携ヘルパ
    // ------------------------------------------------------------------

    public function findPendingAttemptByUlid(string $attemptUlid): ?TicketAutoRechargeAttempt
    {
        return TicketAutoRechargeAttempt::query()
            ->where('attempt_ulid', $attemptUlid)
            ->where('status', AutoRechargeAttemptStatus::Pending->value)
            ->first();
    }

    public function findAttemptByUlid(string $attemptUlid): ?TicketAutoRechargeAttempt
    {
        return TicketAutoRechargeAttempt::query()->where('attempt_ulid', $attemptUlid)->first();
    }

    /**
     * D29(i): setup Checkout 完了の適用 (SetDefaultPaymentMethodJob から): PM snapshot 更新 +
     * 有効な事前同意があれば自動有効化。
     *
     * PM snapshot と enabled=true は同一 DB TX で確定する — 「PM あり && enabled=false」の
     * 中間状態を props (settingsFor) に見せない。updateSettings / executeAttempt と同一の
     * org lock で直列化する (停止操作との交錯を防ぐ)。
     *
     * @return bool 今回の呼び出しで enabled に遷移したか (カード差し替えの再 setup では false)
     */
    public function applySetupCompletion(Organization $organization, string $paymentMethodId): bool
    {
        Assert::stringNotEmpty($paymentMethodId);

        $lock = Cache::lock($this->lockName($organization), self::LOCK_TTL_SECONDS);

        try {
            /** @var bool $enabledNow */
            $enabledNow = $lock->block(10, fn (): bool => DB::transaction(
                function () use ($organization, $paymentMethodId): bool {
                    $config = $this->lockedConfigFor($organization);

                    if ($config === null) {
                        // 事前同意なしの手動カード登録: snapshot のみ。
                        $this->persistConfig($organization, null, [
                            'enabled' => false,
                            'stripe_payment_method_id' => $paymentMethodId,
                            'threshold_count' => $this->defaultThreshold(),
                            'max_count' => $this->defaultMax(),
                        ], null);

                        return false;
                    }

                    $wasEnabled = $config->enabled;
                    $config->stripe_payment_method_id = $paymentMethodId;

                    if ($this->autoEnableEligible($config)) {
                        // 自動有効化: default PM は直前に gateway::setDefaultPaymentMethod 済み
                        // (SetDefaultPaymentMethodJob の呼び出し順で保証)。
                        $config->enabled = true;
                        $config->failure_count = 0;
                    }

                    $config->save();

                    return $config->enabled && ! $wasEnabled;
                },
            ));
        } catch (LockTimeoutException $e) {
            // webhook Job (tries=3, backoff=30) の再試行に乗せる — snapshot 未反映のまま握り潰さない。
            throw new RuntimeException('auto-recharge setup completion lock busy for org '.$this->orgId($organization), previous: $e);
        }

        if ($enabledNow) {
            // 通知失敗で webhook Job を失敗させない (enabled は commit 済み。Job retry では
            // enabled 遷移が再発しないため通知は再送されず、失敗だけが残る — ここで握って report)。
            try {
                $this->notifyAutoEnabled($organization, $paymentMethodId);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $enabledNow;
    }

    /**
     * P9 (T1004): サブスク決済カードをオートリチャージへ流用する。
     *
     * setup 経路 (`applySetupCompletion`) との違い: **ユーザーは「オートリチャージ用のカード登録」を
     * 明示していない**ため、適格性 (`autoEnableEligible`) を**先に**確認し、不適格なら
     * customer default PM もローカル snapshot も一切変更しない完全 no-op にする (fail-closed)。
     *
     * 適格時の副作用 (customer の `invoice_settings.default_payment_method` 更新) は
     * v2 同意文言 (契約のお支払いカードをオートリチャージにも使う) で開示済み。
     * updateSettings / applySetupCompletion / recordPreConsent / executeAttempt と
     * **同一 org lock** で直列化するため、lock 保持中に適格性が変化する経路は構造的に存在しない。
     *
     * @return bool 今回の呼び出しで enabled に遷移したか
     */
    public function applyReusedPaymentMethod(Organization $organization, string $paymentMethodId): bool
    {
        Assert::stringNotEmpty($paymentMethodId); // fake/将来呼び出しの空文字混入防御

        $lock = Cache::lock($this->lockName($organization), self::LOCK_TTL_SECONDS);

        try {
            /** @var bool $enabledNow */
            $enabledNow = $lock->block(10, function () use ($organization, $paymentMethodId): bool {
                // 適格性の先行確認 (lock 内・TX 外): 不適格なら Stripe にも DB にも触らない。
                $config = $this->configFor($organization);
                if ($config === null || ! $this->autoEnableEligible($config)) {
                    Log::info('auto-recharge: subscription PM reuse skipped (not eligible)', [
                        'organization_id' => $this->orgId($organization),
                        'reason' => $config === null ? 'no_config' : 'not_eligible',
                    ]);

                    return false;
                }

                // 適格 → default PM を設定 (Cashier 冪等実装) してから有効化を確定する。
                $this->gateway->setDefaultPaymentMethod($organization, $paymentMethodId);

                return DB::transaction(function () use ($organization, $paymentMethodId): bool {
                    $config = $this->lockedConfigFor($organization);
                    // ここで不適格になる経路は上記 lock 直列化により到達不能のはず。到達した場合は
                    // 「Stripe だけ変更済みの部分適用」なので silent no-op にせず例外で顕在化させる
                    // (Job retry → 適格なら収束 / 不適格が続くなら failed_jobs で検知)。
                    if ($config === null || ! $this->autoEnableEligible($config)) {
                        throw new RuntimeException(
                            'auto-recharge PM reuse: eligibility lost after default PM update (org '
                            .$this->orgId($organization).') — partial application detected',
                        );
                    }

                    $wasEnabled = $config->enabled;
                    $config->stripe_payment_method_id = $paymentMethodId;
                    $config->enabled = true;
                    $config->failure_count = 0;
                    $config->save();

                    return ! $wasEnabled;
                });
            });
        } catch (LockTimeoutException $e) {
            // webhook Job (tries=3, backoff=30) の再試行に乗せる (握り潰さない)。
            throw new RuntimeException(
                'auto-recharge PM reuse lock busy for org '.$this->orgId($organization),
                previous: $e,
            );
        }

        if ($enabledNow) {
            // 通知失敗で Job を失敗させない (applySetupCompletion と同型)。
            try {
                $this->notifyAutoEnabled($organization, $paymentMethodId);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $enabledNow;
    }

    /**
     * 有効な事前同意が待機中か (= PM が届けば自動有効化される状態。settingsFor の
     * pendingAutoEnable と同一定義の共通判定)。
     */
    public function isAutoEnablePending(Organization $organization): bool
    {
        $config = $this->configFor($organization);

        return $config !== null
            && $config->stripe_payment_method_id === null
            && $this->autoEnableEligible($config);
    }

    /**
     * 自動有効化の成立条件 (fail-closed)。同意証跡の完全性 + 既存共通判定
     * reconsentRequiredFor (version 改定・価格改定・上限超過 — consented_* の null もここで
     * 検出される) + 停止状態 (ユーザー停止/連続失敗停止) でないこと。
     */
    private function autoEnableEligible(TicketAutoRecharge $config): bool
    {
        if ($config->enabled || $config->disabled_reason !== null) {
            return false;
        }
        if ($config->consented_at === null) {
            return false;
        }

        return ! $this->reconsentRequiredFor($config, $config->max_count);
    }

    // ------------------------------------------------------------------
    // 通知
    // ------------------------------------------------------------------

    private function notifyFailed(Organization $organization, TicketAutoRechargeAttempt $attempt): void
    {
        // dedup は attempt 単位 (同一 attempt の webhook 再送で再通知しない)。
        $dedupKey = 'auto_recharge_failed:'.$attempt->attempt_ulid;

        $this->notifications->sendReminderOnce(
            $organization,
            BillingNotificationType::AutoRechargeFailed,
            $dedupKey,
            new AutoRechargeFailedNotification(
                $dedupKey,
                $organization->name,
                route('billing.index'),
                route('billing.tickets.show'),
            ),
        );
    }

    private function notifyDisabled(Organization $organization, TicketAutoRechargeAttempt $attempt): void
    {
        $dedupKey = 'auto_recharge_disabled:'.$attempt->attempt_ulid;

        $this->notifications->sendReminderOnce(
            $organization,
            BillingNotificationType::AutoRechargeDisabled,
            $dedupKey,
            new AutoRechargeDisabledNotification(
                $dedupKey,
                $organization->name,
                route('billing.index'),
            ),
        );
    }

    /**
     * 自動有効化の事後通知 (同意の代替ではない — 同意成立はオンボーディング画面の
     * affirmative action)。金額は保存済みの同意値 (consented_max_amount) — ユーザーが同意した
     * 金額そのものを通知する (現行 tier の再計算ではない)。
     */
    private function notifyAutoEnabled(Organization $organization, string $paymentMethodId): void
    {
        $config = $this->configFor($organization);
        if ($config === null) {
            report(new RuntimeException('auto-recharge enabled notification: config missing for org '.$this->orgId($organization)));

            return;
        }

        // dedup は org + PM 単位 (同一 setup 完了 webhook の再送で二重送信しない)。
        $dedupKey = 'auto_recharge_enabled:'.$this->orgId($organization).':'.$paymentMethodId;

        $this->notifications->sendReminderOnce(
            $organization,
            BillingNotificationType::AutoRechargeEnabled,
            $dedupKey,
            new AutoRechargeEnabledNotification(
                $dedupKey,
                $organization->name,
                $config->threshold_count,
                $config->max_count,
                $config->consented_max_amount,
                $organization->pm_last_four,
                route('billing.index'),
            ),
        );
    }

    private function notifyActionRequired(Organization $organization, TicketAutoRechargeAttempt $attempt): void
    {
        $invoiceId = $attempt->stripe_invoice_id;
        if ($invoiceId === null) {
            return;
        }

        // 復旧リンクは invoice の hosted_invoice_url (Stripe ホストページで認証完了できる)。
        $hostedUrl = $this->gateway->retrieveInvoiceState($invoiceId)->hostedInvoiceUrl;

        // dedup は JST date bucket (日次で再通知を許す — 放置での失効を防ぐ)。
        $bucket = CarbonImmutable::now('Asia/Tokyo')->format('Y-m-d');
        $dedupKey = 'auto_recharge_sca:'.$invoiceId.':'.$bucket;

        $this->notifications->sendReminderOnce(
            $organization,
            BillingNotificationType::AutoRechargeActionRequired,
            $dedupKey,
            new AutoRechargeActionRequiredNotification(
                $dedupKey,
                $organization->name,
                $hostedUrl ?? route('billing.index'),
            ),
        );
    }

    // ------------------------------------------------------------------
    // 内部ヘルパ
    // ------------------------------------------------------------------

    /**
     * 再同意が必要か (UI 表示 / 設定更新 / 自動有効化 / attempt 起票停止の **4 箇所で共有**
     * する単一述語)。$max は評価対象の上限 (設定更新時は新値、それ以外は現設定の max_count)。
     *
     * - 同意文言 version の不一致 (文言改定で既存同意を失効させる)
     * - 同意記録の欠落
     * - $max が同意時上限を超過
     * - 現行カタログでの最大請求額が同意時金額を超過 (価格改定)
     */
    private function reconsentRequiredFor(TicketAutoRecharge $config, int $max): bool
    {
        if ($config->consent_version !== $this->currentConsentVersion()) {
            return true;
        }
        if ($config->consented_max_count === null || $max > $config->consented_max_count) {
            return true;
        }
        if ($config->consented_max_amount === null) {
            return true;
        }

        return $this->maxChargeAmountFor($max) > $config->consented_max_amount;
    }

    /** 同意上限額のサーバ再計算 (client hidden の金額は信用しない)。 */
    private function maxChargeAmountFor(int $max): int
    {
        return TicketVolumePrice::currentTierFor($max)->unitAmount * $max;
    }

    private function configFor(Organization $organization): ?TicketAutoRecharge
    {
        return TicketAutoRecharge::query()->where('organization_id', $organization->getKey())->first();
    }

    private function lockedConfigFor(Organization $organization): ?TicketAutoRecharge
    {
        return TicketAutoRecharge::query()
            ->where('organization_id', $organization->getKey())
            ->lockForUpdate()
            ->first();
    }

    /**
     * 設定行の upsert。tenant / actor キー (organization_id / created_by_user_id) は
     * $fillable に無いため relation 経由で明示代入する (mass assignment しない)。
     *
     * @param  array<string, mixed>  $attrs
     */
    private function persistConfig(
        Organization $organization,
        ?TicketAutoRecharge $config,
        array $attrs,
        ?User $user,
    ): TicketAutoRecharge {
        if ($config === null) {
            $config = new TicketAutoRecharge;
            $config->organization()->associate($organization);
        }

        if ($user !== null) {
            $config->createdByUser()->associate($user);
        }

        $config->fill($attrs);
        $config->save();

        return $config;
    }

    private function lockName(Organization $organization): string
    {
        return 'billing:auto-recharge:'.$this->orgId($organization);
    }

    /** Organization の主キー (PHPStan level 10 で mixed を持ち回らないための narrowing 点)。 */
    private function orgId(Organization $organization): int
    {
        $id = $organization->getKey();
        Assert::integer($id, 'Organization の主キーは整数を想定しています');

        return $id;
    }

    private function idempotencyKeyBase(TicketAutoRechargeAttempt $attempt): string
    {
        return 'auto-recharge:'.$attempt->attempt_ulid;
    }

    /**
     * @return array<string, string>
     */
    private function metadataFor(Organization $organization, TicketAutoRechargeAttempt $attempt): array
    {
        return [
            'purpose' => 'auto_recharge',
            'organization_id' => (string) $this->orgId($organization),
            'recharge_attempt_ulid' => $attempt->attempt_ulid,
        ];
    }

    private function hasRecentCompletedSetup(Organization $organization): bool
    {
        // stale 対策: completed から 30 分以内の setup session のみ「処理中」判定の対象にする
        // (SetDefaultPaymentMethodJob の恒久失敗で永続「処理中」表示にならない)。
        $windowMinutes = config()->integer('billing.auto_recharge.setup_pending_window_minutes');

        return BillingCheckoutSession::query()
            ->where('organization_id', $organization->getKey())
            ->where('intent', CheckoutIntent::SetupPaymentMethod->value)
            ->where('status', CheckoutSessionStatus::Completed->value)
            ->where('updated_at', '>=', CarbonImmutable::now()->subMinutes($windowMinutes))
            ->exists();
    }

    /**
     * P9 (T1004): 「PM 流用 Job の収束待ち」の窓に入っているか。
     *
     * 基準は **`pm_reuse_dispatched_at`** (dispatch した事実の永続マーカー)。
     * `updated_at` / `completed_at` は完了後の別更新・未決済 completed で窓が誤って開くため使わない。
     */
    private function hasRecentAutoRechargeFundedSignup(Organization $organization): bool
    {
        $windowMinutes = config()->integer('billing.auto_recharge.setup_pending_window_minutes');

        return BillingCheckoutSession::query()
            ->where('organization_id', $organization->getKey())
            ->where('intent', CheckoutIntent::SubscriptionStart->value)
            ->where('funding_choice', SignupFundingChoice::AutoRecharge->value)
            ->where('status', CheckoutSessionStatus::Completed->value)
            ->where('pm_reuse_dispatched_at', '>=', CarbonImmutable::now()->subMinutes($windowMinutes))
            ->exists();
    }

    private function defaultThreshold(): int
    {
        return config()->integer('billing.auto_recharge.default_threshold');
    }

    private function defaultMax(): int
    {
        return config()->integer('billing.auto_recharge.default_max');
    }

    private function maxCountLimit(): int
    {
        // tier 解決の PURCHASE_MAX_COUNT Assert と単一真実源 (超過設定は attempt 起票で例外死する)。
        return min(config()->integer('billing.auto_recharge.max_count'), TicketVolumePrice::PURCHASE_MAX_COUNT);
    }

    private function maxFailures(): int
    {
        return config()->integer('billing.auto_recharge.max_failures');
    }

    private function pendingExpiryHours(): int
    {
        return config()->integer('billing.auto_recharge.pending_expiry_hours');
    }

    private function currentConsentVersion(): string
    {
        $version = config()->string('billing.auto_recharge.consent_version');
        Assert::stringNotEmpty($version, 'config billing.auto_recharge.consent_version は非空で設定してください');

        return $version;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // driver 差吸収 (23505 = pgsql / 23000 = sqlite・mysql)。
        $sqlState = $e->errorInfo[0] ?? null;

        return $sqlState === '23505' || $sqlState === '23000';
    }
}
