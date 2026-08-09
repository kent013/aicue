/**
 * Tests for resources/js/lib/capture/cut-labels.ts
 *
 * 公開契約 (詳細設計 施策 0):
 *   step は連番 (手順 1, 手順 2, ...)、point は直前 step の番号 + 枝番 (急所 N-M)。
 *
 * これは CutNavigator.svelte 内にあった $derived.by の**純粋な抽出**であり、
 * 撮影パネルの見出し (F-1-03) とテイクプレビューの aria-label (F-1-02) が
 * 同じ規則を共有するための唯一の導出元にする。
 *
 * したがって本テストの役割は「新しい仕様を決めること」ではなく
 * **現行挙動を固定してリファクタであることを証明すること**である。
 * 先頭が point のような端ケースも、良し悪しを問わず現行どおりに固定する。
 */
import { describe, expect, it } from "vitest";

import { buildCutLabels } from "@/lib/capture/cut-labels";
import type { CaptureCut } from "@/types/capture";

/** ラベル導出に効くのは id / type だけなので、それ以外は既定値で埋める。 */
function cut(id: number, type: "step" | "point"): CaptureCut {
    return {
        id,
        type,
        parent_cut_id: null,
        scene: `scene-${id}`,
        shot_type: "hiki",
        shooting_point: null,
        narration: "",
        subtitle_primary: null,
        subtitle_secondary: "",
        adopted_take_id: null,
        takes: [],
    };
}

describe("buildCutLabels", () => {
    it("step のみなら連番の手順ラベルになる", () => {
        expect(buildCutLabels([cut(10, "step"), cut(11, "step"), cut(12, "step")])).toEqual({
            10: "手順 1",
            11: "手順 2",
            12: "手順 3",
        });
    });

    it("point は直前 step の番号 + 枝番になる", () => {
        const labels = buildCutLabels([
            cut(1, "step"),
            cut(2, "point"),
            cut(3, "point"),
            cut(4, "step"),
            cut(5, "point"),
        ]);

        expect(labels).toEqual({
            1: "手順 1",
            2: "急所 1-1",
            3: "急所 1-2",
            4: "手順 2",
            5: "急所 2-1",
        });
    });

    it("step をまたぐと枝番がリセットされる", () => {
        const labels = buildCutLabels([
            cut(1, "step"),
            cut(2, "point"),
            cut(3, "step"),
            cut(4, "point"),
        ]);

        expect(labels[2]).toBe("急所 1-1");
        expect(labels[4]).toBe("急所 2-1");
    });

    it("先頭が point (親 step 無し) でも現行どおり 急所 0-1 になる", () => {
        // 仕様として良いかは問わない。抽出前の CutNavigator と同一挙動であることの固定。
        expect(buildCutLabels([cut(9, "point")])).toEqual({ 9: "急所 0-1" });
    });

    it("空配列なら空オブジェクトを返す", () => {
        expect(buildCutLabels([])).toEqual({});
    });
});
