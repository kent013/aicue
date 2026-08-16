import { afterEach, describe, expect, it } from "vitest";
import { cleanup, render, screen } from "@testing-library/svelte";
import SubtitleOverlay from "@/components/molecules/SubtitleOverlay.svelte";

/*
 * SubtitleOverlay: 撮影中プレビューへ重畳する字幕ガイド (焼込ではない DOM overlay)。
 * primary=上部帯 / secondary=下部メイン。空判定は trim 後、描画は元文字列のまま。
 * visible=false または primary/secondary 両方空なら何も描画しない。
 */

afterEach(() => {
    cleanup();
});

describe("SubtitleOverlay", () => {
    it("visible=true + primary/secondary あり → overlay と両帯が表示される", () => {
        render(SubtitleOverlay, {
            props: { primary: "名称A", secondary: "メイン字幕", visible: true },
        });
        expect(screen.getByTestId("subtitle-overlay")).toBeInTheDocument();
        expect(screen.getByTestId("subtitle-primary")).toHaveTextContent("名称A");
        expect(screen.getByTestId("subtitle-secondary")).toHaveTextContent("メイン字幕");
    });

    it("visible=false → overlay 非表示 (primary/secondary あっても描画しない)", () => {
        render(SubtitleOverlay, {
            props: { primary: "名称A", secondary: "メイン字幕", visible: false },
        });
        expect(screen.queryByTestId("subtitle-overlay")).not.toBeInTheDocument();
        expect(screen.queryByTestId("subtitle-primary")).not.toBeInTheDocument();
        expect(screen.queryByTestId("subtitle-secondary")).not.toBeInTheDocument();
    });

    it("primary=null かつ secondary='' → 非表示", () => {
        render(SubtitleOverlay, {
            props: { primary: null, secondary: "", visible: true },
        });
        expect(screen.queryByTestId("subtitle-overlay")).not.toBeInTheDocument();
    });

    it("primary/secondary が空白のみ (空白・改行) → trim 後空扱いで非表示", () => {
        render(SubtitleOverlay, {
            props: { primary: "   ", secondary: "\n", visible: true },
        });
        expect(screen.queryByTestId("subtitle-overlay")).not.toBeInTheDocument();
    });

    it("primary のみ (secondary='') → primary 帯のみ表示、secondary 帯は非存在", () => {
        render(SubtitleOverlay, {
            props: { primary: "名称A", secondary: "", visible: true },
        });
        expect(screen.getByTestId("subtitle-overlay")).toBeInTheDocument();
        expect(screen.getByTestId("subtitle-primary")).toBeInTheDocument();
        expect(screen.queryByTestId("subtitle-secondary")).not.toBeInTheDocument();
    });

    it("secondary のみ (primary=null) → secondary 帯のみ表示、primary 帯は非存在", () => {
        render(SubtitleOverlay, {
            props: { primary: null, secondary: "メイン字幕", visible: true },
        });
        expect(screen.getByTestId("subtitle-overlay")).toBeInTheDocument();
        expect(screen.getByTestId("subtitle-secondary")).toBeInTheDocument();
        expect(screen.queryByTestId("subtitle-primary")).not.toBeInTheDocument();
    });

    it("長文 JP + 多数改行を同時に与えても両帯が別々に存在し line-clamp が付く (中央侵食しない構造)", () => {
        const longPrimary = Array.from({ length: 8 }, (_, i) => `名称行${i}`).join("\n");
        const longSecondary = Array.from({ length: 12 }, (_, i) => `本文行${i}`).join("\n");
        render(SubtitleOverlay, {
            props: { primary: longPrimary, secondary: longSecondary, visible: true },
        });
        const primary = screen.getByTestId("subtitle-primary");
        const secondary = screen.getByTestId("subtitle-secondary");
        expect(primary).toHaveClass("line-clamp-2");
        expect(secondary).toHaveClass("line-clamp-3");
    });

    it("描画文字列を trim で書き換えない: 前後空白を含む値でも描画され textContent に本体を含む", () => {
        render(SubtitleOverlay, {
            props: { primary: "  a  ", secondary: "  b  ", visible: true },
        });
        expect(screen.getByTestId("subtitle-primary").textContent).toContain("a");
        expect(screen.getByTestId("subtitle-secondary").textContent).toContain("b");
    });

    it("位置構造: overlay ルートが flex-col justify-between、primary が先頭・secondary が末尾スロット", () => {
        render(SubtitleOverlay, {
            props: { primary: "名称A", secondary: "メイン字幕", visible: true },
        });
        const overlay = screen.getByTestId("subtitle-overlay");
        expect(overlay).toHaveClass("flex-col", "justify-between");
        const primary = screen.getByTestId("subtitle-primary");
        const secondary = screen.getByTestId("subtitle-secondary");
        // primary スロットが secondary スロットより前に位置する (DOM 順)
        const position = primary.compareDocumentPosition(secondary);
        expect(position & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });
});
