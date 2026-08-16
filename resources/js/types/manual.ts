/**
 * 動画マニュアル (VideoManual) / カテゴリ関連の Inertia props 型。
 * PHP 側の typed array PHPDoc (ProjectController::manualRows 等) と対で保守する。
 * status は PHP enum App\Enums\Manual\VideoManualStatus と値集合を一致させる
 * (literal union で UI 分岐漏れを検出する。乖離検知は当面手動確認)。
 */

import type { BadgeTone } from "@/components/atoms/Badge.types";

export type VideoManualStatus = "draft" | "analyzing" | "ready" | "rendering" | "published";

/** VideoManualStatus の表示ラベル (UI 共通) */
export const VIDEO_MANUAL_STATUS_LABELS: Record<VideoManualStatus, string> = {
    draft: "下書き",
    analyzing: "解析中",
    ready: "準備完了",
    rendering: "書き出し中",
    published: "公開済み",
};

/**
 * 状態バッジの tone (結果表示の意味色。UI 共通)。
 * satisfies でキー漏れ (status 追加時) をコンパイル時検出する。
 */
export const STATUS_TONES = {
    draft: "neutral",
    analyzing: "tertiary",
    ready: "success",
    rendering: "warning",
    published: "primary",
} as const satisfies Record<VideoManualStatus, BadgeTone>;

/**
 * 撮影ナビ (capture.manuals.show) へ導線を出してよい状態か。
 * 撮影ナビ一覧 (CaptureManualController::index) が列挙する ready/published と一致させる
 * (draft/analyzing/rendering はシナリオ未確定でナビ画面が空になるため導線を出さない)。
 * satisfies で status 追加時のキー漏れをコンパイル時検出する (STATUS_TONES と同方針)。
 */
export const CAPTURE_NAVIGABLE_BY_STATUS = {
    draft: false,
    analyzing: false,
    ready: true,
    rendering: false,
    published: true,
} as const satisfies Record<VideoManualStatus, boolean>;

/** PC 編集/詳細から撮影ナビへ導線を出してよいか (型付き判定の単一ソース) */
export function isCaptureNavigable(status: VideoManualStatus): boolean {
    return CAPTURE_NAVIGABLE_BY_STATUS[status];
}

/**
 * シナリオが確定した「表示相」か (ready 以降)。
 * status がシナリオ確定相 (ready / rendering / published) かを表す **UI 表示判定** であり、
 * cuts の実在判定ではない (複製直後の draft+cuts はここでは false = 別症状)。
 * これにより確定相で「未生成」案内を出さない。
 * 注: CAPTURE_NAVIGABLE_BY_STATUS (撮影ナビ導線, rendering=false) とは別概念なので統合しない。
 * satisfies で status 追加時のキー漏れをコンパイル時検出する。
 */
export const SCENARIO_ESTABLISHED_BY_STATUS = {
    draft: false,
    analyzing: false,
    ready: true,
    rendering: true,
    published: true,
} as const satisfies Record<VideoManualStatus, boolean>;

/** status がシナリオ確定相 (ready 以降) の表示相か (型付き判定の単一ソース) */
export function isScenarioEstablished(status: VideoManualStatus): boolean {
    return SCENARIO_ESTABLISHED_BY_STATUS[status];
}

/**
 * AI 解析操作を適用できる状態か (サーバ AnalysisJobService の許可集合 = draft / ready と一致)。
 * これは **解析操作の適用可能状態** の判定であり、rendering / published / analyzing は
 * status_not_analyzable (409) となるため false。AI 解析ボタン (CTA) の表示可否に使う。
 * satisfies で status 追加時のキー漏れをコンパイル時検出する。
 */
export const SCENARIO_ANALYZABLE_BY_STATUS = {
    draft: true,
    analyzing: false,
    ready: true,
    rendering: false,
    published: false,
} as const satisfies Record<VideoManualStatus, boolean>;

/** AI 解析操作を適用できる状態か (draft / ready。型付き判定の単一ソース) */
export function isAnalyzable(status: VideoManualStatus): boolean {
    return SCENARIO_ANALYZABLE_BY_STATUS[status];
}

export interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

/** PHP App\Enums\Manual\ManualSortOption と値集合を一致させる (allowlist) */
export type ManualSortOption = "updated_desc" | "updated_asc" | "title_asc" | "title_desc";

/** PHP: App\DataTransferObjects\Manual\ManualListItemData::toArray() と対 */
export interface ManualListItem {
    id: number;
    title: string;
    status: VideoManualStatus;
    /** null = 未分類 */
    category: { id: number; name: string } | null;
    /** 作成者。退会/削除で解決不可のときは null (UI は「不明」) */
    creator: { id: number; name: string } | null;
    created_at: string;
    updated_at: string;
    /**
     * いま公開されている完成動画の長さ (ms)。**null = 未確定**
     * (published でない / 総尺が記録されていない)。
     * published が外れた行の古い総尺はサーバが null に畳んでいるため、
     * UI 側で status を見て再判定しない。
     */
    duration_ms: number | null;
    /**
     * 完成動画を受け取れるか。サーバが「download ability × published ×
     * 現行世代の succeeded render に output_path がある」を判定した結果そのもので、
     * **UI 側で条件を再判定しない**。download endpoint が 302 を返す条件と 1 対 1
     * (描画時点のスナップショットであり、ストレージ実体の存在確認ではない)。
     */
    downloadable: boolean;
    /** 削除できるか (サーバの delete ability 判定結果。撮影者は false) */
    deletable: boolean;
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
    /** 並べ替え。null = 既定 (作成日降順) */
    sort: ManualSortOption | null;
    /** 自分の作成分のみ */
    mine: boolean;
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

/**
 * 編集中の作業コピー (未保存行は id: null)。
 * clientKey は each の安定 key 用のクライアント専用識別子。
 * serializeSteps() には含めるが PUT payload (payloadSteps) には含めない (サーバ非公開)。
 */
export type DraftPoint = Omit<ScenarioPoint, "id"> & { id: number | null; clientKey: string };
export type DraftStep = Omit<ScenarioStep, "id" | "points"> & {
    id: number | null;
    clientKey: string;
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

/** PHP: App\Enums\Manual\RenderKind と対 (値集合同期テストあり = ManualEnumTsSyncInvariantTest) */
export type RenderKind = "render" | "preview";

/** PHP: App\Enums\Manual\RenderStep と対 (値集合同期テストあり) */
export type RenderStep = "compose" | "concat";

/** PHP: App\Enums\Manual\RenderErrorCode と対 (値集合同期テストあり。CTA 分岐はこの code で行う) */
export type RenderErrorCode = "scenario_version_changed" | "timeout" | "internal";

/** RenderStep の表示ラベル (RenderPanel の進捗表示) */
export const RENDER_STEP_LABELS: Record<RenderStep, string> = {
    compose: "カットを合成中",
    concat: "動画を連結中",
};

/** PHP: RenderJobData::toArray() と対 (show props / ポーリング / トリガー 201 の共通 shape) */
export interface RenderJobProps {
    id: number;
    kind: RenderKind;
    status: AnalysisJobStatus; // JobStatus 共用 (queued|running|succeeded|failed)
    step: RenderStep | null;
    progress: number | null;
    error: string | null;
    error_code: RenderErrorCode | null;
    manual_status: VideoManualStatus;
    /**
     * 生成物に含まれたプレースホルダ (黒背景) クリップ数。
     * null = その動画について言えることが無い (未完了 / T148 以前の succeeded 行)。
     * **null を 0 と同一視しない** (0 は「黒背景ゼロで生成された」という積極的な事実)。
     */
    placeholder_cut_count: number | null;
}

/** PHP: App\DataTransferObjects\Manual\TakeCoverageData::toProps() と対 */
export interface TakeCoverageProps {
    /** カット総数 */
    total_cuts: number;
    /** 使用できる採用テイクがないカット数 (**打ち切らない全件数**) */
    missing_count: number;
    /** 該当カットの表示ラベル (先頭 10 件で打ち切られる。件数は missing_count が正) */
    missing_labels: string[];
}

/** PHP: App\Enums\Manual\RenderConflictType と対 (値集合同期テストあり) */
export type RenderConflictType =
    | "in_flight"
    | "status_not_renderable"
    | "status_not_previewable"
    | "org_preview_limit";

/** PHP: RenderConflictResource と対 (render/preview 409 ボディ。code 厳格一致) */
export interface RenderConflictBody {
    code: "render_conflict";
    conflict_type: RenderConflictType;
    message: string;
}

/** PHP: VideoManualController::show の render props と対 */
export interface RenderProps {
    /** 最新 kind=render の job (無ければ null) */
    job: RenderJobProps | null;
    /** 最新 kind=preview の job (無ければ null) */
    previewJob: RenderJobProps | null;
    /**
     * 再生可能な最新 succeeded preview の job (無ければ null)。
     * 動画 URL と黒背景の注記が同一オブジェクトから出る (別世代の値で説明しないため)。
     */
    playbackJob: RenderJobProps | null;
    /**
     * 受け取れる完成動画の job (無ければ null)。
     * サーバが「published + download ability + 現行世代」を判定した結果そのものであり、
     * **UI 側で条件を再判定しない** (判断は props で 1 回)。
     */
    finishedJob: RenderJobProps | null;
    /**
     * 採用テイクの充足状況 (描画時点のスナップショット。常に最新ではない)。
     * 生成物の実績は playbackJob.placeholder_cut_count が語る (別概念なので混ぜない)。
     */
    coverage: TakeCoverageProps;
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

/**
 * PC テイク選択画面 (Manuals/Takes) の型。PHP 側 App\DataTransferObjects\Manual\
 * {TakeSelectionPageData, SelectableTakeData, CutTakeSummaryData} と対で保守する。
 * 撮影 PWA の types/capture.ts とは**別 shape** (PC は署名 URL の口を持たない)。
 */

/** PHP: App\Enums\Manual\TakeStatus と値集合を一致させる (literal union) */
export type SelectableTakeStatus = "uploading" | "processing" | "ready" | "failed";

/** テイクの状態ラベル (UI 共通)。satisfies でキー漏れをコンパイル時検出する */
export const TAKE_STATUS_LABELS = {
    uploading: "アップロード中",
    processing: "処理中",
    ready: "使用できます",
    failed: "失敗",
} as const satisfies Record<SelectableTakeStatus, string>;

/** 採用できる状態か (サーバ CaptureTakeService::adopt の ready 条件と一致させる) */
export const TAKE_ADOPTABLE_BY_STATUS = {
    uploading: false,
    processing: false,
    ready: true,
    failed: false,
} as const satisfies Record<SelectableTakeStatus, boolean>;

/** PHP: SelectableTakeData と対 */
export interface SelectableTake {
    id: number;
    status: SelectableTakeStatus;
    size_bytes: number;
    duration_ms: number | null;
    comment: string | null;
    captured_at: string | null;
    sort_order: number;
    /** DL 済み (削除できない。押下前に理由を説明するために出す) */
    downloaded: boolean;
    /** サムネイル生成済みか。true のときだけ GET .../takes/{id}/thumbnail を表示に使う */
    has_thumbnail: boolean;
}

/** PHP: TakeSelectionPageData の cut キーと対 */
export interface TakeSelectionCut {
    id: number;
    type: "step" | "point";
    label: string;
    scene: string;
    narration: string;
    subtitle_primary: string | null;
    subtitle_secondary: string;
    adopted: { id: number; status: SelectableTakeStatus } | null;
}

/** PHP: TakeSelectionPageData::toArray() 全体と対 (Manuals/Takes の props) */
export interface TakeSelectionPageProps {
    project: { id: number; name: string };
    manual: { id: number; title: string; status: VideoManualStatus };
    cut: TakeSelectionCut;
    takes: SelectableTake[];
}

/** PHP: CutTakeSummaryData と対 (シナリオ編集画面「動画」列の 1 カット分) */
export interface CutTakeSummary {
    cut_id: number;
    takes_count: number;
    adopted: { id: number; status: SelectableTakeStatus } | null;
}
