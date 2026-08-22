/**
 * ダッシュボードの Inertia props 型。
 * PHP 側 App\DataTransferObjects\Dashboard\DashboardPageData::toArray() と対で保守する。
 */
import type { BillingStateValue } from "@/types/billing";
import type { VideoManualStatus } from "@/types/manual";

export type DashboardState = "no_project" | "ready";
export type DashboardRole = "editor" | "shooter" | "viewer";
export type DashboardJobStatus = "queued" | "running"; // 進行中のみ (terminal は出ない)

export interface InProgressManual {
    manual_id: number;
    title: string;
    manual_status: Extract<VideoManualStatus, "analyzing" | "rendering">;
    job_status: DashboardJobStatus | null; // null = 過渡状態 (「準備中」表示)
    progress: number | null;
    job_updated_at: string | null;
}

export interface RecentManual {
    id: number;
    title: string;
    status: VideoManualStatus;
    category_name: string | null;
    updated_at: string;
}

export interface ShootingTarget {
    manual_id: number;
    title: string;
    cuts_count: number;
    pending_cuts_count: number;
}

export interface BillingSummary {
    ticket_balance: number;
    is_low_balance: boolean;
    storage_used_bytes: number;
    storage_limit_bytes: number | null;
    storage_usage_percent: number | null;
    /** PHP: BillingSummaryData::$billingState (OnboardingBillingState)。真偽値に潰さない */
    billing_state: BillingStateValue;
}

export interface DashboardData {
    state: DashboardState;
    role: DashboardRole | null;
    can_create_project: boolean;
    organization_name: string | null;
    project: { id: number; name: string } | null;
    in_progress: InProgressManual[];
    recent_manuals: RecentManual[];
    shooting_targets: ShootingTarget[];
    billing: BillingSummary | null;
}

/** ページ props (Inertia)。共有 props は SharedProps を合成して参照する (契約 1 本化) */
export interface DashboardProps {
    dashboard: DashboardData;
}

/**
 * 課金状態ごとのダッシュボード callout。**null = callout を出さない**。
 *
 * 未契約 (no_subscription) と支払い不健全 (expired_checkout) は次の一手が違う
 * (bug-hunt 20260811-003230 F-2-01: 新規登録直後の全ユーザーに支払い失敗の文言が出ていた)。
 * `satisfies Record<BillingStateValue, …>` により、state が増えたときの
 * キー漏れ = 無言の描画漏れを `pnpm typecheck` が検出する。
 *
 * **`.svelte` ではなくここに置く理由**: `pnpm typecheck` は `tsc --noEmit` であり
 * `.svelte` を型検査しない。page 内に書くと `satisfies` が一度も評価されず、
 * 「コンパイル時に守っているつもり」の空振りになる (T150 の mutation 3 で実測)。
 * `types/manual.ts` の VIDEO_MANUAL_STATUS_LABELS / CAPTURE_NAVIGABLE_BY_STATUS と同じ所在。
 *
 * **CTA の行き先を権限で分岐させない**: onboarding.checkout は契約済みなら billing.index、
 * manageBilling なしなら onboarding.billing-required へサーバが捌く
 * (OnboardingController::show)。フロントで認可を再判定しないし、押せないボタンも作らない
 * (禁止事項 8)。
 *
 * ★値は**組織相対パス** (`path`) である。組織 URL への写像は利用側が `currentOrgUrl()` で行う
 *   (家系裁定 AG-037: 業務 route は組織 URL 配下にある)。
 */
export const BILLING_CALLOUTS = {
    subscribed: null,
    active_free_plan: null,
    no_subscription: {
        body: "ご利用にはプランの選択が必要です。プランを選ぶと機能をご利用いただけます。",
        cta: { label: "プランを選ぶ", path: "/onboarding/checkout" },
    },
    pending_checkout: {
        body: "お支払いのお手続きが完了していません。ご利用を開始するには、プラン選択からお手続きください。",
        cta: { label: "プラン選択へ", path: "/onboarding/checkout" },
    },
    expired_checkout: {
        body: "サブスクリプションのお支払いが確認できないため、一部機能を一時停止しています。お支払い方法をご確認ください。",
        cta: { label: "お支払い方法を確認", path: "/billing" },
    },
} as const satisfies Record<
    BillingStateValue,
    { body: string; cta: { label: string; path: string } } | null
>;
