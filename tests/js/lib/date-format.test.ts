import { describe, expect, it } from "vitest";
import { formatDate, formatDateTime } from "@/lib/date-format";

describe("date-format", () => {
    describe("formatDate", () => {
        it("Date を ja-JP の YYYY/MM/DD で整形する", () => {
            expect(formatDate(new Date(2026, 4, 4))).toBe("2026/05/04");
        });

        it("日付文字列・タイムスタンプも受け付ける", () => {
            // ローカルタイムゾーン依存を避けるため Date 経由の値と一致比較する
            const date = new Date(2026, 0, 15, 12, 0);
            expect(formatDate(date.toISOString())).toBe(formatDate(date));
            expect(formatDate(date.getTime())).toBe(formatDate(date));
        });

        it("null / undefined / 空文字 / 不正値は fallback (- ) を返す", () => {
            expect(formatDate(null)).toBe("-");
            expect(formatDate(undefined)).toBe("-");
            expect(formatDate("")).toBe("-");
            expect(formatDate("not-a-date")).toBe("-");
        });

        it("fallback を差し替えられる", () => {
            expect(formatDate(null, "未設定")).toBe("未設定");
        });
    });

    describe("formatDateTime", () => {
        it("Date を ja-JP の YYYY/MM/DD HH:MM で整形する", () => {
            expect(formatDateTime(new Date(2026, 4, 4, 10, 8))).toBe("2026/05/04 10:08");
        });

        it("null / 不正値は fallback (-) を返す", () => {
            expect(formatDateTime(null)).toBe("-");
            expect(formatDateTime("invalid")).toBe("-");
            expect(formatDateTime(undefined, "―")).toBe("―");
        });
    });
});
