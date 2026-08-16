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

/**
 * 静止画撮影に必要な能力 (getUserMedia のみ。**MediaRecorder は要らない**)。
 * supportsMediaRecorder() を静止画にも流用すると、MediaRecorder 非対応端末で
 * 撮れるはずの写真まで file input へ落ちてしまう。
 */
export function supportsStillCapture(): boolean {
    return typeof navigator.mediaDevices?.getUserMedia === "function";
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

/** 前後カメラの facingMode (doc/05 §5.2 カメラ反転 in/out)。型の単一ソース。 */
export type FacingMode = "environment" | "user";

/** environment ⇄ user の反転。型の単一ソース化 + テスト容易性のため pure 関数化。 */
export function nextFacingMode(mode: FacingMode): FacingMode {
    return mode === "environment" ? "user" : "environment";
}

/**
 * 経過ミリ秒を mm:ss へ整形 (録画タイマー表示用。doc/05 §5.2「00:00」)。
 * 負値・NaN は 0 に丸め、60 分以上も mm が桁溢れして連続表示される (分を切り捨てない)。
 */
export function formatElapsed(ms: number): string {
    const totalSeconds = Number.isFinite(ms) && ms > 0 ? Math.floor(ms / 1000) : 0;
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;
    const mm = String(minutes).padStart(2, "0");
    const ss = String(seconds).padStart(2, "0");
    return `${mm}:${ss}`;
}

/**
 * MediaRecorder が pause()/resume() を提供するかの **存在確認のみ** (doc/05 §5.2 一時停止/再開)。
 * 注意: これは API の存在確認であって正常動作の保証ではない。実行時の InvalidStateError や
 * pause/resume イベント未到達への退行 (recorder.state からの phase 復旧) が最終防御。
 */
export function supportsPauseResume(): boolean {
    return (
        typeof window.MediaRecorder !== "undefined" &&
        typeof window.MediaRecorder.prototype?.pause === "function" &&
        typeof window.MediaRecorder.prototype?.resume === "function"
    );
}

/**
 * getUserMedia の video 制約を facingMode から組む (S6)。
 *
 * **呼出時点の facingMode を引数で受ける純関数**にしてある。
 * component 側でクロージャから読む形に戻したり、結果をキャッシュしたりしないこと
 * (flip 後の再取得で古い facing mode を使う後退になり、実機でしか気づけない)。
 *
 * ここに置く理由: 型専用 interface (`MediaTrackConstraints` = WebIDL dictionary) は
 * 実行時グローバルではないため .svelte 側では ESLint no-undef を解決できない。
 * .ts へ置けば tsc の型検査対象にもなる (eslint.config.js の globals 方針を参照)。
 */
export function videoConstraints(mode: FacingMode): MediaTrackConstraints {
    return { facingMode: mode };
}
