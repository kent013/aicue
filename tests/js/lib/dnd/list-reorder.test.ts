import { describe, expect, it } from "vitest";
import {
    insertionIndexFromRects,
    moveItem,
    toFinalIndex,
    type RowBounds,
} from "@/lib/dnd/list-reorder";

/*
 * 並べ替えの意味論 (層 1)。DOM に触れない純関数なので、off-by-one をここで網羅する。
 * 「挿入 index (隙間 0..n)」と「最終 index (移動後の配列 0..n-1)」を混同しないことが本丸
 * (受け入れ条件 A5)。
 */

describe("moveItem", () => {
    it("前方へ動かす (index 2 → 0)", () => {
        expect(moveItem(["A", "B", "C"], 2, 0)).toEqual(["C", "A", "B"]);
    });

    it("後方へ動かす (index 0 → 2)", () => {
        expect(moveItem(["A", "B", "C"], 0, 2)).toEqual(["B", "C", "A"]);
    });

    it("端から端へ動かす", () => {
        expect(moveItem(["A", "B", "C", "D"], 0, 3)).toEqual(["B", "C", "D", "A"]);
        expect(moveItem(["A", "B", "C", "D"], 3, 0)).toEqual(["D", "A", "B", "C"]);
    });

    it("from === to は動かさない", () => {
        expect(moveItem(["A", "B", "C"], 1, 1)).toEqual(["A", "B", "C"]);
    });

    it("to が範囲外なら端へ丸める (throw しない)", () => {
        expect(moveItem(["A", "B", "C"], 0, 99)).toEqual(["B", "C", "A"]);
        expect(moveItem(["A", "B", "C"], 2, -5)).toEqual(["C", "A", "B"]);
    });

    it("from が範囲外なら動かさない", () => {
        expect(moveItem(["A", "B", "C"], 3, 0)).toEqual(["A", "B", "C"]);
        expect(moveItem(["A", "B", "C"], -1, 0)).toEqual(["A", "B", "C"]);
    });

    it("非整数は動かさない", () => {
        expect(moveItem(["A", "B", "C"], 0.5, 2)).toEqual(["A", "B", "C"]);
        expect(moveItem(["A", "B", "C"], 0, Number.NaN)).toEqual(["A", "B", "C"]);
    });

    it("空配列でも throw しない", () => {
        expect(moveItem([], 0, 0)).toEqual([]);
    });

    it("入力配列を変更しない (新しい配列を返す)", () => {
        const source = ["A", "B", "C"];
        const result = moveItem(source, 0, 2);

        expect(source).toEqual(["A", "B", "C"]);
        expect(result).not.toBe(source);
    });

    it("要素に undefined を含む配列でも正しく動く (値を存在判定に使っていない)", () => {
        const list: Array<number | undefined> = [undefined, 1, 2];

        expect(moveItem(list, 0, 2)).toEqual([1, 2, undefined]);
        expect(moveItem(list, 2, 0)).toEqual([2, undefined, 1]);
    });
});

describe("insertionIndexFromRects", () => {
    /** top = index * 100, height = 100 の等間隔リスト */
    const rows: RowBounds[] = [
        { top: 0, height: 100 },
        { top: 100, height: 100 },
        { top: 200, height: 100 },
    ];

    it("空配列は常に 0", () => {
        expect(insertionIndexFromRects([], 500)).toBe(0);
    });

    it("1 行目の中点より上なら 0", () => {
        expect(insertionIndexFromRects(rows, 10)).toBe(0);
        expect(insertionIndexFromRects(rows, 49)).toBe(0);
    });

    it("1 行目の中点より下なら 1", () => {
        expect(insertionIndexFromRects(rows, 51)).toBe(1);
    });

    it("最終行の中点より下なら rows.length", () => {
        expect(insertionIndexFromRects(rows, 260)).toBe(3);
        expect(insertionIndexFromRects(rows, 9999)).toBe(3);
    });

    it("行の高さが不揃いでも各行の中点で切り替わる", () => {
        const uneven: RowBounds[] = [
            { top: 0, height: 40 }, // 中点 20
            { top: 40, height: 200 }, // 中点 140
        ];

        expect(insertionIndexFromRects(uneven, 19)).toBe(0);
        expect(insertionIndexFromRects(uneven, 21)).toBe(1);
        expect(insertionIndexFromRects(uneven, 139)).toBe(1);
        expect(insertionIndexFromRects(uneven, 141)).toBe(2);
    });
});

describe("toFinalIndex", () => {
    it("insertion <= from は素通し", () => {
        expect(toFinalIndex(0, 2)).toBe(0);
        expect(toFinalIndex(2, 2)).toBe(2);
    });

    it("insertion > from は 1 減る (掴んだ行が抜けるぶん詰まる)", () => {
        expect(toFinalIndex(3, 1)).toBe(2);
        expect(toFinalIndex(4, 0)).toBe(3);
    });

    it("掴んだ行の前後の隙間 (from / from+1) はどちらも from になる", () => {
        expect(toFinalIndex(2, 2)).toBe(2);
        expect(toFinalIndex(3, 2)).toBe(2);
    });
});

describe("挿入 index → 最終 index → 並べ替え の合成 (off-by-one の恒久回帰)", () => {
    const LIST = ["A", "B", "C", "D"] as const;

    /** 手で並べた期待値 (from, insertion) → 結果 */
    const CASES: ReadonlyArray<readonly [number, number, readonly string[]]> = [
        // from = 0 (A を掴む)
        [0, 0, ["A", "B", "C", "D"]],
        [0, 1, ["A", "B", "C", "D"]],
        [0, 2, ["B", "A", "C", "D"]],
        [0, 3, ["B", "C", "A", "D"]],
        [0, 4, ["B", "C", "D", "A"]],
        // from = 1 (B を掴む)
        [1, 0, ["B", "A", "C", "D"]],
        [1, 1, ["A", "B", "C", "D"]],
        [1, 2, ["A", "B", "C", "D"]],
        [1, 3, ["A", "C", "B", "D"]],
        [1, 4, ["A", "C", "D", "B"]],
        // from = 2 (C を掴む)
        [2, 0, ["C", "A", "B", "D"]],
        [2, 1, ["A", "C", "B", "D"]],
        [2, 2, ["A", "B", "C", "D"]],
        [2, 3, ["A", "B", "C", "D"]],
        [2, 4, ["A", "B", "D", "C"]],
        // from = 3 (D を掴む)
        [3, 0, ["D", "A", "B", "C"]],
        [3, 1, ["A", "D", "B", "C"]],
        [3, 2, ["A", "B", "D", "C"]],
        [3, 3, ["A", "B", "C", "D"]],
        [3, 4, ["A", "B", "C", "D"]],
    ];

    it.each(CASES)("from=%i を隙間 %i へ落とす", (from, insertion, expected) => {
        expect(moveItem(LIST, from, toFinalIndex(insertion, from))).toEqual(expected);
    });

    it("全 (from, insertion) の組み合わせを網羅している", () => {
        expect(CASES).toHaveLength(LIST.length * (LIST.length + 1));
    });
});
