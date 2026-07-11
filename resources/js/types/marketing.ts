/**
 * マーケティングページ (LP / 料金表) の Inertia props。
 * PHP 側 DTO (App\DataTransferObjects\Marketing\*) の @phpstan-type shape と exact 対。
 * 全プロパティ readonly で accidental widening を防ぐ。
 */

/** PHP: LandingPageDto (LandingPageShape) と対 */
export interface LandingPageProps {
    readonly signupGrantTickets: number;
    readonly contactUrl: string;
    readonly contactIsExternal: boolean;
    readonly isAuthenticated: boolean;
}

/** PHP: PurchaseTierDto (PurchaseTierShape) と対 */
export interface PurchaseTierShape {
    readonly minCount: number;
    readonly unitAmount: number;
}

/** PHP: PricingPlanDto (PricingPlanShape) と対 (baseAmountJpy null = 無料表示) */
export interface PricingPlanShape {
    readonly code: string;
    readonly name: string;
    readonly baseAmountJpy: number | null;
    readonly monthlyTicketGrant: number;
    readonly maxProjects: number | null;
    readonly maxMembers: number | null;
    readonly maxStorageGb: number | null;
}

/** PHP: PricingPageDto (PricingPageShape) と対 */
export interface PricingPageProps {
    readonly plans: readonly PricingPlanShape[];
    readonly ticketTiers: readonly PurchaseTierShape[];
    readonly spotUnitAmountJpy: number;
    readonly signupGrantTickets: number;
    readonly signupGrantExpiryDays: number;
    readonly analysisTicketCost: number;
    readonly renderTicketCost: number;
    readonly isAuthenticated: boolean;
    readonly contactUrl: string;
    readonly contactIsExternal: boolean;
}
