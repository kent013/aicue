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
