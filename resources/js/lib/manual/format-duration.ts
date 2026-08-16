/**
 * 再生時間の可読表記 (動画一覧の再生時間表示)。
 *
 * ms → "M:SS"、1 時間以上は "H:MM:SS"。秒は**四捨五入**する
 * (表示専用であり、長さの比較・判定には使わない = format-bytes.ts と同じ位置づけ。
 * 切り捨てにしないのは、59.6 秒を "0:59" と書くより "1:00" と書く方が実尺に近いためで、
 * 差は 1 秒未満であり配布判断に影響しない)。
 * サーバ整形にしないのは、日時と違いタイムゾーンに依存しないため。
 *
 * null / 有限でない値 / 負値は「未確定」を表す DURATION_UNKNOWN を返す
 * (未確定を 0:00 と書くと「長さゼロの動画がある」という別の嘘になる)。
 */
export const DURATION_UNKNOWN = "—";

export function formatDurationMs(durationMs: number | null): string {
    if (durationMs === null || !Number.isFinite(durationMs) || durationMs < 0) {
        return DURATION_UNKNOWN;
    }

    const totalSeconds = Math.round(durationMs / 1000);
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    const ss = String(seconds).padStart(2, "0");

    if (hours > 0) {
        return `${hours}:${String(minutes).padStart(2, "0")}:${ss}`;
    }

    return `${minutes}:${ss}`;
}
