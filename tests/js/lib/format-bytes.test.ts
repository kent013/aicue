import { describe, expect, it } from "vitest";
import { formatBytes } from "@/lib/format-bytes";

/*
 * バイト数の可読表記 (Dashboard の残容量タイル / 課金画面の quota カードで共有)。
 * 1024 進法の境界で単位が切り替わることを固定する (表示専用。判定には使わない)。
 */

describe("formatBytes", () => {
    it("1 KB 未満はバイトのまま出す", () => {
        expect(formatBytes(0)).toBe("0 B");
        expect(formatBytes(1023)).toBe("1023 B");
    });

    it("1024 の各境界で単位が切り替わる", () => {
        expect(formatBytes(1024)).toBe("1.0 KB");
        expect(formatBytes(1024 ** 2)).toBe("1.0 MB");
        expect(formatBytes(1024 ** 3)).toBe("1.0 GB");
    });

    it("小数第 1 位まで丸めて表示する", () => {
        expect(formatBytes(1536)).toBe("1.5 KB");
        expect(formatBytes(Math.round(2.25 * 1024 ** 3))).toBe("2.3 GB");
    });
});
