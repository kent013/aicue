/**
 * 撮影 PWA の型定義。PHP 側 App\DataTransferObjects\Capture\* と対で保守する
 * (キー集合の契約は tests/Feature/Capture/CaptureManualBrowsingTest が固定する)。
 */

export type TakeStatus = "uploading" | "processing" | "ready" | "failed";

export interface CaptureTake {
    id: number;
    client_take_id: string;
    status: TakeStatus;
    size_bytes: number;
    duration_ms: number | null;
    comment: string | null;
    captured_at: string | null;
    sort_order: number;
    downloaded: boolean;
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
    adopted_take_id: number | null;
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
