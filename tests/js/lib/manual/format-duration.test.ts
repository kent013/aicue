import { describe, expect, it } from "vitest";
import { DURATION_UNKNOWN, formatDurationMs } from "@/lib/manual/format-duration";

/*
 * 再生時間の整形 (表示専用)。
 * - 未確定 (null / 有限でない / 負値) は「—」= 0:00 と書かない
 * - 秒は四捨五入 (切り捨てにしない。差は 1 秒未満で配布判断に影響しない)
 */

describe("formatDurationMs", () => {
    it("未確定 (null) は DURATION_UNKNOWN を返す", () => {
        expect(formatDurationMs(null)).toBe(DURATION_UNKNOWN);
        expect(DURATION_UNKNOWN).toBe("—");
    });

    it("0 は 0:00 (長さゼロの動画という事実をそのまま書く)", () => {
        expect(formatDurationMs(0)).toBe("0:00");
    });

    it("分未満は M:SS で表示する", () => {
        expect(formatDurationMs(1_000)).toBe("0:01");
        expect(formatDurationMs(59_400)).toBe("0:59");
    });

    it("秒は四捨五入する (59.6 秒は 1:00 へ繰り上がる)", () => {
        expect(formatDurationMs(59_600)).toBe("1:00");
    });

    it("分・秒を 2 桁ゼロ埋めで表示する", () => {
        expect(formatDurationMs(185_000)).toBe("3:05");
    });

    it("1 時間以上は H:MM:SS で表示する", () => {
        expect(formatDurationMs(3_600_000)).toBe("1:00:00");
        expect(formatDurationMs(3_725_000)).toBe("1:02:05");
    });

    it("負値 / NaN / Infinity は未確定として扱う", () => {
        expect(formatDurationMs(-1)).toBe(DURATION_UNKNOWN);
        expect(formatDurationMs(Number.NaN)).toBe(DURATION_UNKNOWN);
        expect(formatDurationMs(Number.POSITIVE_INFINITY)).toBe(DURATION_UNKNOWN);
    });
});
