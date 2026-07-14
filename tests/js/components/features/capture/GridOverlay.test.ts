import { afterEach, describe, expect, it } from "vitest";
import { cleanup, render, screen } from "@testing-library/svelte";
import GridOverlay from "@/components/features/capture/GridOverlay.svelte";

/*
 * GridOverlay: 撮影プレビューへ重畳する三分割グリッド (doc/05 §5.2)。
 * 構図補助の装飾 overlay (aria-hidden)。visible で描画を切り替える。
 */

afterEach(() => {
    cleanup();
});

describe("GridOverlay", () => {
    it("visible=true で grid-overlay が描画される", () => {
        render(GridOverlay, { props: { visible: true } });
        expect(screen.getByTestId("grid-overlay")).toBeInTheDocument();
    });

    it("visible=false では描画されない", () => {
        render(GridOverlay, { props: { visible: false } });
        expect(screen.queryByTestId("grid-overlay")).not.toBeInTheDocument();
    });

    it("罫線が 4 本 (縦 2・横 2) 描画される", () => {
        render(GridOverlay, { props: { visible: true } });
        const overlay = screen.getByTestId("grid-overlay");
        // 三分割線: overlay 直下の div (罫線) が 4 本
        expect(overlay.querySelectorAll(":scope > div")).toHaveLength(4);
    });

    it("装飾 overlay として aria-hidden で読み上げ対象外", () => {
        render(GridOverlay, { props: { visible: true } });
        expect(screen.getByTestId("grid-overlay")).toHaveAttribute("aria-hidden", "true");
    });
});
