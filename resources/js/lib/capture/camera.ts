/**
 * カメラ対応判定 (doc/10 §10.8-3: MediaRecorder 非対応環境では
 * <input type="file" capture> フォールバックを必ず提供する)。
 */
export function supportsMediaRecorder(): boolean {
    return (
        typeof window.MediaRecorder !== "undefined" &&
        typeof navigator.mediaDevices?.getUserMedia === "function" &&
        ["video/mp4", "video/webm"].some(
            (type) => window.MediaRecorder.isTypeSupported?.(type) ?? false,
        )
    );
}

/** 録画に使う MIME type (mp4 優先。どちらも不可なら null) */
export function preferredRecordingMimeType(): string | null {
    if (typeof window.MediaRecorder === "undefined") return null;
    for (const type of ["video/mp4", "video/webm"]) {
        if (window.MediaRecorder.isTypeSupported?.(type)) return type;
    }
    return null;
}

/**
 * カメラが実行時に使えない理由 (F-03 対応。判別可能 union で保持し、
 * UI 文言の出し分け・将来の計測に使う)。
 * Permissions-Policy 拒否は NotAllowedError として観測されユーザー拒否と
 * 機械的に区別できないため permission_denied に含める。
 */
export type CameraUnavailableReason =
    | "permission_denied" // NotAllowedError / SecurityError (ユーザー拒否・Permissions-Policy 拒否)
    | "device_missing" // NotFoundError / OverconstrainedError (カメラ無し・制約不一致)
    | "mime_unsupported" // preferredRecordingMimeType() === null
    | "recorder_unsupported" // new MediaRecorder() の失敗 (NotSupportedError 等)
    | "unknown"; // 分類不能 (詰み回避のためフォールバック側に倒す)

/** getUserMedia() 失敗の分類結果。transient は再試行で回復し得る失敗 */
export type CameraErrorClassification =
    | { kind: "unavailable"; reason: CameraUnavailableReason }
    | { kind: "transient" };

/** reject 値から DOMException 名を安全に取り出す (ブラウザは任意値を reject し得る) */
function errorName(error: unknown): string | null {
    if (error instanceof DOMException) return error.name;
    // OverconstrainedError 等、実装により DOMException を継承しないオブジェクトに備える
    if (typeof error === "object" && error !== null && "name" in error) {
        const name = (error as { name: unknown }).name;
        return typeof name === "string" ? name : null;
    }
    return null;
}

/**
 * getUserMedia() の reject 値を分類する (W3C Media Capture の DOMException name ベース)。
 * - 恒久系 (権限拒否・デバイス無し) → unavailable: フォールバックへ切替
 * - 一時系 (デバイス使用中・中断) → transient: エラー表示 + 再試行可能のまま
 * - 分類不能 → unavailable/unknown: §10.8-3 の「詰みを作らない」要件に従い
 *   フォールバック側に倒す (誤フォールバックでもテイク投入は継続できる)
 */
export function classifyGetUserMediaError(error: unknown): CameraErrorClassification {
    switch (errorName(error)) {
        case "NotAllowedError":
        case "SecurityError":
            return { kind: "unavailable", reason: "permission_denied" };
        case "NotFoundError":
        case "OverconstrainedError":
            return { kind: "unavailable", reason: "device_missing" };
        case "NotReadableError":
        case "AbortError":
            return { kind: "transient" };
        default:
            return { kind: "unavailable", reason: "unknown" };
    }
}
