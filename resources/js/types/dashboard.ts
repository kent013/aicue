/**
 * ダッシュボードの Inertia props 型。
 * PHP 側 App\DataTransferObjects\Dashboard\DashboardPageData::toArray() と対で保守する。
 */
import type { VideoManualStatus } from "@/types/manual";

export type DashboardState = "no_organization" | "no_project" | "ready";
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
    has_billing_access: boolean;
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
