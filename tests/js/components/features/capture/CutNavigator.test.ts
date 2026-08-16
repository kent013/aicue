import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, render, screen } from "@testing-library/svelte";
import CutNavigator from "@/components/features/capture/CutNavigator.svelte";
import type { CaptureCut } from "@/types/capture";

function makeCut(overrides: Partial<CaptureCut> = {}): CaptureCut {
    return {
        id: 1,
        type: "step",
        parent_cut_id: null,
        scene: "コーヒーメーカー全体を映し、作業者が電源ボタンに手を伸ばして押す一連",
        shot_type: "hiki",
        shooting_point: "電源ボタンとランプが画面中央に大きく映るように寄って撮影",
        narration: "",
        subtitle_primary: null,
        subtitle_secondary: "",
        adopted_take_id: null,
        adopted_ready_take_id: null,
        takes: [],
        ...overrides,
    };
}

afterEach(() => cleanup());

describe("CutNavigator 狭幅 truncate 構造 (H13/F-1-3)", () => {
    // Codex R1 Critical 反映: cut を一度だけ生成し render/getByText で同一参照を使う
    it("scene 行は truncate を保つ (grid 是正で親幅が確定すれば効く。構造変更は不要)", () => {
        const cut = makeCut();
        render(CutNavigator, { props: { cuts: [cut], selectedCutId: null, onSelect: vi.fn() } });
        expect(screen.getByText(cut.scene).className).toContain("truncate");
    });

    it("shooting_point 行は <p>min-w-0 + <span>truncate、MapPin は shrink-0 で ellipsis 可能", () => {
        const cut = makeCut();
        render(CutNavigator, { props: { cuts: [cut], selectedCutId: null, onSelect: vi.fn() } });

        const sp = screen.getByText(cut.shooting_point!);
        // 2 段検証: span 自身と親 <p> 行で役割分担を固定（付与先ずれ検出）。
        // span は min-w-0/flex-1/truncate を全て要求（どれが欠けても red。Codex R2 Warning 反映）
        expect(sp.tagName).toBe("SPAN");
        expect(sp).toHaveClass("min-w-0", "flex-1", "truncate");
        const row = sp.closest("p");
        expect(row).not.toBeNull();
        expect(row).toHaveClass("flex", "min-w-0");
        // アイコン非圧縮（shrink-0）を仕様として固定
        const icon = row!.querySelector("svg");
        expect(icon).not.toBeNull();
        expect(icon).toHaveClass("shrink-0");
    });
});
