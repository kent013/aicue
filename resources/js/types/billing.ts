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
