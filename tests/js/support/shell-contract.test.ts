/**
 * tests/js/support/shell-contract.ts の保証。
 *
 * ここに集めたのは shell スクリプトの契約テストが共有する純ヘルパであり、
 * **これらが空振りすると負のコントロールが全部無言で通ってしまう**。
 * 元は scripts/run-browser-test.contract.test.ts の中にあった describe を、
 * ヘルパの移設にあわせて (消さずに) こちらへ移した。
 */
import { describe, expect, it } from "vitest";
import { codeLines, lineIndexOf, mutate } from "./shell-contract";

describe("mutate()", () => {
    it("置換対象が存在しなければ throw", () => {
        expect(() => mutate("abc", "zzz", "yyy")).toThrow(/must appear exactly once \(found 0\)/);
    });

    it("置換対象が複数あれば throw", () => {
        expect(() => mutate("aa", "a", "b")).toThrow(/must appear exactly once \(found 2\)/);
    });

    it("1 箇所だけなら置換して返す", () => {
        expect(mutate("abc", "b", "X")).toBe("aXc");
    });
});

describe("codeLines()", () => {
    it("行頭 # のコメント行を落とす (先頭空白つきも)", () => {
        expect(codeLines("a\n# c\n   # d\nb")).toBe("a\nb");
    });

    it("行の途中の # は落とさない (実行行を消さない)", () => {
        expect(codeLines('echo "x" # 説明')).toBe('echo "x" # 説明');
    });
});

describe("lineIndexOf()", () => {
    it("最初に一致した行の 0 始まり index を返す", () => {
        expect(lineIndexOf("a\nb\nc\nb", "b")).toBe(1);
    });

    it("見つからなければ -1", () => {
        expect(lineIndexOf("a\nb", "zzz")).toBe(-1);
    });

    it("順序契約の比較に使える (前後関係が index の大小で出る)", () => {
        const source = "first\nsecond";
        expect(lineIndexOf(source, "first")).toBeLessThan(lineIndexOf(source, "second"));
    });
});
