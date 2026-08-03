import { describe, expect, it } from "vitest";
import { parseTicketCount } from "@/pages/Billing/ticketCount";

/*
 * 購入枚数の解釈は「符号付き整数のみ」。指数・16進・小数・Infinity・空文字は null に倒し、
 * clamp / floor の暗黙補正をしない (範囲検証は呼び出し側 + サーバ validation の責務)。
 */

describe("parseTicketCount", () => {
    it("符号付き整数を数値へ変換する", () => {
        expect(parseTicketCount("10")).toBe(10);
        expect(parseTicketCount("-5")).toBe(-5);
        expect(parseTicketCount(" 42 ")).toBe(42);
        expect(parseTicketCount("0")).toBe(0);
    });

    it("整数以外の表記は null に倒す (暗黙補正しない)", () => {
        expect(parseTicketCount("1e3")).toBeNull();
        expect(parseTicketCount("0x10")).toBeNull();
        expect(parseTicketCount("1.5")).toBeNull();
        expect(parseTicketCount("Infinity")).toBeNull();
        expect(parseTicketCount("-")).toBeNull();
        expect(parseTicketCount("1.")).toBeNull();
        expect(parseTicketCount("")).toBeNull();
        expect(parseTicketCount("abc")).toBeNull();
    });

    it("number が渡っても防御的に処理する (String 経由)", () => {
        expect(parseTicketCount(10)).toBe(10);
        expect(parseTicketCount(1.5)).toBeNull();
    });
});
