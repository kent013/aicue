/**
 * 動画マニュアル (VideoManual) / カテゴリ関連の Inertia props 型。
 * PHP 側の typed array PHPDoc (ProjectController::manualRows 等) と対で保守する。
 * status は PHP enum App\Enums\Manual\VideoManualStatus と値集合を一致させる
 * (literal union で UI 分岐漏れを検出する。乖離検知は当面手動確認)。
 */

export type VideoManualStatus = "draft" | "analyzing" | "ready" | "rendering" | "published";

/** VideoManualStatus の表示ラベル (UI 共通) */
export const VIDEO_MANUAL_STATUS_LABELS: Record<VideoManualStatus, string> = {
    draft: "下書き",
    analyzing: "解析中",
    ready: "準備完了",
    rendering: "書き出し中",
    published: "公開済み",
};

export interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface ManualListItem {
    id: number;
    title: string;
    status: VideoManualStatus;
    /** null = 未分類 */
    category: { id: number; name: string } | null;
    created_at: string;
}

export interface CategoryOption {
    id: number;
    name: string;
}

/** 一覧絞り込み条件 (GET クエリ)。category は id 文字列 | "uncategorized" | null */
export interface ManualFilters {
    category: string | null;
    status: string | null;
    q: string | null;
}

/**
 * PHP: App\DataTransferObjects\Manual\ScenarioPointData と対。
 * サーバ shape の id は常に number (確定 id)。未保存行 (id: null) は
 * 編集中の作業コピー専用型 DraftPoint / DraftStep で表現し、型を分離する。
 */
export interface ScenarioPoint {
    id: number;
    scene: string;
    shot_type: "hiki" | "yori";
    shooting_point: string | null;
    narration: string;
    subtitle_primary: string | null;
    subtitle_secondary: string;
    material_type: "video" | "still" | null;
    static_display_seconds: number | null;
}

/** PHP: ScenarioStepData と対 (step 行 + 配下の points) */
export interface ScenarioStep extends ScenarioPoint {
    points: ScenarioPoint[];
}

/** PHP: ScenarioDocumentData と対 (edit props / PUT 成功応答の共通 shape) */
export interface ScenarioDocument {
    scenario_version: number;
    steps: ScenarioStep[];
}

/** 編集中の作業コピー (未保存行は id: null)。PUT payload の steps はこの型を直列化する */
export type DraftPoint = Omit<ScenarioPoint, "id"> & { id: number | null };
export type DraftStep = Omit<ScenarioStep, "id" | "points"> & {
    id: number | null;
    points: DraftPoint[];
};

/** PHP: App\Enums\Manual\JobStatus と対 (値集合を一致させる) */
export type AnalysisJobStatus = "queued" | "running" | "succeeded" | "failed";

/** PHP: App\Enums\Manual\AnalysisStep と対 */
export type AnalysisStep = "extract" | "decompose" | "generate";

/** AnalysisStep の表示ラベル (AnalysisPanel の進捗表示) */
export const ANALYSIS_STEP_LABELS: Record<AnalysisStep, string> = {
    extract: "手順書を読み取り中",
    decompose: "作業を分解中",
    generate: "シナリオを生成中",
};

/** PHP: AnalysisJobData::toArray() と対 (show props / ポーリング / analyze 201 の共通 shape) */
export interface AnalysisJobProps {
    id: number;
    status: AnalysisJobStatus;
    step: AnalysisStep | null;
    progress: number | null;
    error: string | null;
    manual_status: VideoManualStatus;
}

/** PHP: VideoManualController::show の analysis props と対 */
export interface AnalysisProps {
    job: AnalysisJobProps | null;
    hasDocument: boolean;
}

/** PHP: App\Enums\Manual\AnalysisConflictType と対 */
export type AnalysisConflictType = "in_flight" | "status_not_analyzable";

/** PHP: AnalysisConflictResource と対 (analyze 409 ボディ。code 厳格一致) */
export interface AnalysisConflictBody {
    code: "analysis_conflict";
    conflict_type: AnalysisConflictType;
    message: string;
}

/** PHP: InsufficientTicketsResource と対 (analyze 402 ボディ。code 厳格一致) */
export interface InsufficientTicketsBody {
    code: "insufficient_tickets";
    message: string;
}

/** PHP: App\Enums\Manual\ScenarioConflictType と対 (discriminated union) */
export type ScenarioConflictType = "version_mismatch" | "rendering" | "analyzing";

/** PHP: ScenarioConflictResource と対 (409 ボディ。code 厳格一致で自分宛て応答のみ処理する) */
export interface ScenarioConflictBody {
    code: "scenario_conflict";
    conflict_type: ScenarioConflictType;
    message: string;
    current_version: number;
}
