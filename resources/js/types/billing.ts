import type { PricingPlanShape, PurchaseTierShape } from "@/types/marketing";

/**
 * 課金ページの Inertia props。
 * PHP 側 DTO (App\DataTransferObjects\Billing\*) の @phpstan-type shape と exact 対。
 */

/**
 * PHP: OnboardingBillingState (backed enum) の value 集合と exact 対。
 * 分岐退行を型で検知するため union を明示する (string にしない)。
 */
export type BillingStateValue =
    | "no_subscription"
    | "pending_checkout"
    | "expired_checkout"
    | "subscribed"
    | "active_free_plan";

/**
 * PHP: TicketBalanceDto (TicketBalanceShape) と対。
 * per-source の**表示値** (clamp 済み)。UI はこの値をそのまま描画し、再計算・clamp しない。
 * 債務 (負残高) の概念は持たない = 債務行を UI に足さないこと。
 */
export interface TicketBalanceShape {
    readonly monthlyRemaining: number;
    readonly purchasedRemaining: number;
    readonly totalAvailable: number;
    readonly activeReservations: number;
    readonly nextExpireAt: string | null;
}

/** PHP: QuotaLimitsDto (QuotaLimitsShape) と対 (null = 無制限) */
export interface QuotaLimitsShape {
    readonly maxProjects: number | null;
    readonly maxMembers: number | null;
    readonly maxStorageGb: number | null;
}

/** 購入フォームの状態 (PHP: PurchaseFormState) */
export type PurchaseFormStateValue = "normal" | "resume";

/** PHP: PurchaseTicketsPageDto (PurchaseTicketsPageShape) と対 */
export interface PurchaseTicketsPageProps {
    readonly tiers: readonly PurchaseTierShape[];
    readonly minCount: number;
    readonly maxCount: number;
    readonly defaultCount: number;
    readonly balance: TicketBalanceShape;
    readonly canManage: boolean;
    /** チケット決済専用の attempt token (サブスク checkout 用とは別 key 空間) */
    readonly ticketAttemptToken: string;
    readonly purchased: boolean;
    /** P8a: オートリチャージが有効か (既定 false) */
    readonly autoRechargeEnabled: boolean;
    readonly formState: PurchaseFormStateValue;
    /** resume で確定している枚数 (normal は null) */
    readonly boundCount: number | null;
    /** resume の「決済を続ける」遷移先 (Stripe Checkout URL) */
    readonly resumeUrl: string | null;
    /** 「新しく購入し直す」= ?fresh=1 の自画面 URL */
    readonly newPurchaseUrl: string;
}

/**
 * PHP: BillingFeedbackDto の SimpleBillingFeedbackKind と exact 対 (5 値)。
 * UI は raw query を見ず、この kind でバナー variant を決める。
 */
export type BillingFeedbackKind =
    | "purchase_received"
    | "purchase_processing"
    | "purchase_already_received"
    | "checkout_retry_required"
    | "portal_returned";

/** PHP: BillingFeedbackDto (BillingFeedbackShape) と対 */
export interface BillingFeedbackShape {
    readonly kind: BillingFeedbackKind;
    readonly message: string;
}

/** PHP: BillingContactDto (BillingContactShape) と対 */
export interface BillingContactShape {
    readonly email: string | null;
    readonly name: string | null;
    /** 未設定時に実際の通知宛先になる owner email */
    readonly fallbackEmail: string | null;
}

/** PHP: BillingPlansPageDto (BillingPlansPageShape) と対 */
export interface BillingPlansPageProps {
    readonly plans: readonly PricingPlanShape[];
    /** 表示用の現在プラン code (gate 判定には使わない) */
    readonly currentPlanCode: string | null;
    readonly billingState: BillingStateValue;
    readonly canManage: boolean;
    /** 契約 checkout の冪等 token (チケット購入 / カード登録とは別 key 空間) */
    readonly subscriptionAttemptToken: string;
}

/** PHP: BillingDashboardDto (BillingDashboardShape) と対 */
export interface BillingDashboardProps {
    readonly plan: PricingPlanShape | null;
    readonly billingState: BillingStateValue;
    readonly currentPeriodEnd: string | null;
    readonly balance: TicketBalanceShape;
    readonly quotas: QuotaLimitsShape;
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
    /**
     * P9: 決済戻り着地の one-shot フィードバック。該当しない着地では null。
     * **購入完了をユーザーに知らせる唯一の経路**なので、null と分岐を落とさないこと。
     */
    readonly feedback: BillingFeedbackShape | null;
    /** P9: 請求先連絡先 (未設定なら fallbackEmail が実際の宛先) */
    readonly billingContact: BillingContactShape;
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
