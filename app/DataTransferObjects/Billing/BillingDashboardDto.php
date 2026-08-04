<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

use App\DataTransferObjects\Marketing\PricingPlanDto;
use App\Enums\Billing\OnboardingBillingState;

/**
 * 課金ダッシュボード (/billing) の Inertia page prop (P8b / bs-14)。
 *
 * プラン一覧は /billing/plans へ移設済み。ここは「現在のプラン / per-bucket 残高 /
 * 現在の quota 状態 (上限 + 使用量 + 超過次元) / 導線」に絞る。plan は表示用の解決結果 (ActiveFreePlan なら
 * free_plan_code、それ以外は plan_code。gate 判定には使わない)。
 *
 * P9: 着地 feedback (one-shot) と請求先連絡先を additive に足した。
 *
 * TS 側は resources/js/types/billing.ts の BillingDashboardProps と exact 対で保守する。
 *
 * @phpstan-import-type PricingPlanShape from PricingPlanDto
 * @phpstan-import-type TicketBalanceShape from TicketBalanceDto
 * @phpstan-import-type QuotaStatusShape from QuotaStatusDto
 * @phpstan-import-type AutoRechargeShape from AutoRechargeSettingsDto
 * @phpstan-import-type BillingFeedbackShape from BillingFeedbackDto
 * @phpstan-import-type BillingContactShape from BillingContactDto
 *
 * @phpstan-type BillingDashboardShape array{
 *   plan: PricingPlanShape|null,
 *   billingState: string,
 *   currentPeriodEnd: string|null,
 *   balance: TicketBalanceShape,
 *   quotas: QuotaStatusShape,
 *   canManageBilling: bool,
 *   continueUrl: string|null,
 *   autoRecharge: AutoRechargeShape,
 *   autoRechargeSetupToken: string,
 *   feedback: BillingFeedbackShape|null,
 *   billingContact: BillingContactShape
 * }
 */
final readonly class BillingDashboardDto
{
    public function __construct(
        public ?PricingPlanDto $plan,
        public OnboardingBillingState $billingState,
        public ?string $currentPeriodEnd,
        public TicketBalanceDto $balance,
        public QuotaStatusDto $quotas,
        public bool $canManageBilling,
        /**
         * 課金ゲートで中断された「元の画面」への復帰先。契約成立着地でのみ 1 回だけ非 null
         * (サーバが same-origin 内部 path に正規化済み)。
         */
        public ?string $continueUrl,
        /** P8a: オートリチャージ設定 (常に非 null。既定は enabled=false の opt-in) */
        public AutoRechargeSettingsDto $autoRecharge,
        /** P8a: カード登録 (mode=setup) 開始 POST の attempt_token (render 単位) */
        public string $autoRechargeSetupToken,
        /**
         * P9: 決済戻り着地の one-shot フィードバック (query を解釈済み。UI は raw query を見ない)。
         * T088 で PurchaseFormState::Completed を撤去したため、**購入完了をユーザーに知らせる
         * 唯一の経路**がこれ。該当しない着地では null。
         */
        public ?BillingFeedbackDto $feedback,
        /**
         * P9: 請求先連絡先 (未設定時は fallbackEmail = owner email が実際の宛先)。
         * **既定値を持たない** — 渡し忘れを型検査で落とす (silent に空表示へ倒さない)。
         */
        public BillingContactDto $billingContact,
    ) {}

    /**
     * @return BillingDashboardShape
     */
    public function toArray(): array
    {
        return [
            'plan' => $this->plan?->toArray(),
            'billingState' => $this->billingState->value,
            'currentPeriodEnd' => $this->currentPeriodEnd,
            'balance' => $this->balance->toArray(),
            'quotas' => $this->quotas->toArray(),
            'canManageBilling' => $this->canManageBilling,
            'continueUrl' => $this->continueUrl,
            'autoRecharge' => $this->autoRecharge->toArray(),
            'autoRechargeSetupToken' => $this->autoRechargeSetupToken,
            'feedback' => $this->feedback?->toArray(),
            'billingContact' => $this->billingContact->toArray(),
        ];
    }
}
