/**
 * 撮影 PWA の型定義。PHP 側 App\DataTransferObjects\Capture\* と対で保守する
 * (キー集合の契約は tests/Feature/Capture/CaptureManualBrowsingTest が固定する)。
 */

export type TakeStatus = "uploading" | "processing" | "ready" | "failed";

/** PHP: App\Enums\Manual\MaterialType と値集合を一致させる */
export type MaterialType = "video" | "still";

export interface CaptureTake {
    id: number;
    client_take_id: string;
    status: TakeStatus;
    /** 登録された素材の**実体** (NOT NULL)。UI はこの値で <video> と <img> を出し分ける */
    material_type: MaterialType;
    size_bytes: number;
    duration_ms: number | null;
    comment: string | null;
    captured_at: string | null;
    sort_order: number;
    downloaded: boolean;
    /** サムネイルが生成済みか。true のときだけ GET .../takes/{id}/thumbnail を表示に使う */
    has_thumbnail: boolean;
    /** 採用テイクのみ非 null (doc/10 §10.3) */
    playback_url: string | null;
    /** 採用テイクのみ非 null。DL 完了時に POST .../downloaded へ送る署名 ACK トークン (D6) */
    download_ack_token: string | null;
}

export interface CaptureCut {
    id: number;
    type: "step" | "point";
    parent_cut_id: number | null;
    scene: string;
    shot_type: "hiki" | "yori";
    shooting_point: string | null;
    narration: string;
    subtitle_primary: string | null;
    subtitle_secondary: string;
    /** カットの**計画** (未指定あり)。撮影 UI (シャッター / 録画) の出し分けに使う */
    material_type: MaterialType | null;
    adopted_take_id: number | null;
    /**
     * 通し再生が再生するテイクの id (サーバが `AdoptedReadyTakeCoverage` で決めた値)。
     * null = そのカットはプレースホルダになる。**クライアントでこの判定を組み立て直さない**
     * (`adopted_take_id` と take.status から導出するコードを書かない = T148)。
     */
    adopted_ready_take_id: number | null;
    takes: CaptureTake[];
}

export interface CaptureManualDetail {
    id: number;
    title: string;
    status: string;
    cuts: CaptureCut[];
}

export interface CaptureManualSummary {
    id: number;
    title: string;
    status: string;
    category_id: number | null;
    category_name: string | null;
    cuts_total: number;
    cuts_adopted: number;
    cuts_with_takes: number;
    updated_at: string | null;
    /** 作成者名。退会/削除で解決不可のときは null (UI は「不明」) */
    creator_name: string | null;
}

/** POST .../takes/upload-url の応答 (TakeUploadTicketResource と対) */
export interface UploadTicket {
    upload_url: string;
    headers: Record<string, string>;
    ticket: string;
    client_take_id: string;
    expires_at: string;
}

/** 422 quota 超過ボディ (QuotaExceededResource と対) */
export interface QuotaExceededBody {
    code: "quota_exceeded";
    message: string;
}

/** 409 登録競合ボディ (CaptureConflictResource と対) */
export interface CaptureConflictBody {
    code: "capture_conflict";
    conflict_type: "registration_in_flight" | "reservation_inconsistent";
    message: string;
}
