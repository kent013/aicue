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
}
