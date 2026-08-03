import type { PurchaseTierShape } from "@/types/marketing";

/**
 * 課金ページの Inertia props。
 * PHP 側 DTO (App\DataTransferObjects\Billing\*) の @phpstan-type shape と exact 対。
 */

/** PHP: PurchaseTicketsPageDto (PurchaseTicketsPageShape) と対 */
export interface PurchaseTicketsPageProps {
    readonly tiers: readonly PurchaseTierShape[];
    readonly minCount: number;
    readonly maxCount: number;
    readonly defaultCount: number;
    readonly balance: number;
    readonly canManage: boolean;
    readonly attemptToken: string;
    readonly purchased: boolean;
    /** P8a: オートリチャージが有効か (既定 false) */
    readonly autoRechargeEnabled: boolean;
}

/** Billing/Index (課金ページ) の Inertia props */
export interface BillingIndexPlanPrice {
    readonly unitAmount: number;
    readonly currency: string;
}

export interface BillingIndexPlan {
    readonly code: string;
    readonly name: string;
    readonly price: BillingIndexPlanPrice | null;
}

export interface BillingIndexProps {
    readonly plans: readonly BillingIndexPlan[];
    readonly currentPlanCode: string | null;
    readonly ticketBalance: number;
    readonly canManageBilling: boolean;
    /**
     * 課金ゲートで中断された「元の画面」への復帰先 (same-origin 内部 path)。
     * 契約成立着地でのみ 1 回だけ非 null で届く (リロードでは null に戻る)。
     */
    readonly continueUrl: string | null;
    /** P8a: オートリチャージ設定 (常に非 null。既定は enabled=false の opt-in) */
    readonly autoRecharge: AutoRechargeProps;
    /** P8a: カード登録 (mode=setup) 開始 POST の attempt_token (render 単位) */
    readonly autoRechargeSetupToken: string;
}

/**
 * PHP: AutoRechargeSettingsDto (AutoRechargeShape) と exact 対。
 * P8a のオートリチャージ (裏チャージ) 設定カードの props。
 */
export interface AutoRechargeProps {
    readonly enabled: boolean;
    readonly thresholdCount: number;
    readonly maxCount: number;
    readonly minCount: number;
    readonly maxCountLimit: number;
    readonly canManage: boolean;
    readonly hasPaymentMethod: boolean;
    readonly paymentMethodBrand: string | null;
    readonly paymentMethodLast4: string | null;
    /** setup 完了 (30 分以内) だが PM snapshot 未反映 = 「カード登録処理中」表示 */
    readonly setupPending: boolean;
    /** 価格改定等で現行最大請求額が同意額を超過 = 再同意まで自動購入停止中 */
    readonly requiresReconsent: boolean;
    /** 有効な事前同意が待機中 (PM 未登録) = カード登録完了で自動有効化される */
    readonly pendingAutoEnable: boolean;
    readonly disabledReason: string | null;
    readonly failureCount: number;
    readonly consentVersion: string;
    readonly baseUnitAmountJpy: number;
    readonly tiers: readonly PurchaseTierShape[];
}

/** PHP: AutoRechargeConsentTermsDto (AutoRechargeConsentTermsShape) と exact 対 */
export interface AutoRechargeConsentTerms {
    readonly thresholdCount: number;
    readonly maxCount: number;
    readonly maxAmountJpy: number;
    readonly unitAmountJpy: number;
    readonly consentVersion: string;
}
