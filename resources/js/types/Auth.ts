/**
 * 認証系ページの Inertia props。
 * PHP 側 (App\Enums\PlanCode / FortifyServiceProvider の registerView) と exact 対。
 */

/**
 * PHP: App\Enums\PlanCode の 5 case と exact 対。
 *
 * 表示名 (プラン名) はここに置かない — 真実源は `plans.name` (サーバ確定値) であり、
 * フロントに二重台帳を作らない。
 */
export type PlanCode = "personal" | "starter" | "standard" | "business" | "enterprise";

/** Auth/Register ページの props */
export interface RegisterPageProps {
    /**
     * 料金表 `/register?plan={code}` 由来の選択意図 (サーバで allowlist 照合済み)。
     * `enterprise` はセルフサーブ契約フローに乗らないため常に null で届く。
     */
    readonly intendedPlan: PlanCode | null;
    readonly socialProviders: string[];
    readonly invitationEmail: string | null;
}
