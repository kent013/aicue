/**
 * 課金オンボーディング (Onboarding/Checkout・Onboarding/BillingRequired) の Inertia props。
 * PHP 側 DTO (App\DataTransferObjects\Onboarding\* / App\DataTransferObjects\Billing\PlanDto) の
 * @phpstan-type shape と exact 対。全プロパティ readonly で accidental widening を防ぐ。
 *
 * フィールド名は移植元 (aigenba) と同一に保ち、後続フェーズ (P7 intendedPlanCode /
 * P8a funding・同意 / P9 attempt token) は additive に足すだけにする。
 */

/** PHP: PlanDto (PlanShape) と対 (currentBaseAmount null = 基本料金なし = 無料表示契約) */
export interface PlanShape {
    readonly code: string;
    readonly name: string;
    readonly currentBaseAmount: number | null;
    readonly isActive: boolean;
}

/** PHP: PersonalPlanEligibilityDto (PersonalPlanEligibilityShape) と対 */
export interface PersonalPlanEligibilityShape {
    /** Personal (無料) を有効化できるか。サーバー判定が唯一の権威 (client で組み立てない) */
    readonly eligible: boolean;
    /** PersonalPlanIneligibleReason の値 (eligible=true なら null) */
    readonly reason: string | null;
    /** 表示文言。サーバー側 enum label で確定済み (frontend に文言マッピングを散らさない) */
    readonly reasonLabel: string | null;
}

/** PHP: OnboardingCheckoutDto (OnboardingCheckoutShape) と対 */
export interface OnboardingCheckoutShape {
    /** is_active=true ∧ code ∈ {personal,starter,standard,business} を sort_order 昇順で */
    readonly plans: readonly PlanShape[];
    readonly recommendedPlanCode: string;
    readonly defaultPlanCode: string;
    readonly contactUrl: string;
    readonly personalEligibility: PersonalPlanEligibilityShape | null;
    /** 新規登録特典の無償チケット枚数 (無料開始 callout 用) */
    readonly signupGrantTickets: number;
    /**
     * 料金表 `?plan=` 由来の選択意図 (サーバで allowlist 照合済み)。
     * `plans` への包含は保証しない = 該当 code があるときだけ preselect する。
     */
    readonly intendedPlanCode: string | null;
}

/** PHP: BillingRequiredDto (BillingRequiredShape) と対 */
export interface BillingRequiredShape {
    readonly ownerName: string | null;
    readonly ownerEmail: string | null;
    readonly contactUrl: string;
}

/** 両ページ共通の organization props (Controller の organizationProps() と対) */
export interface OnboardingOrganizationShape {
    readonly id: number;
    readonly name: string;
    readonly slug: string;
}
