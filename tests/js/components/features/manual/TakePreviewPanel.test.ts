import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
import TakePreviewPanel from "@/components/features/manual/TakePreviewPanel.svelte";
import type { SelectableTake, TakeSelectionCut } from "@/types/manual";

/*
 * PC テイク選択画面の中央ペイン。静止画テイクは <video> ではなく <img> で出す。
 * 素材種別は**申告 Content-Type からの分類**であって実体の形式を保証しないため、
 * 読み込み失敗の受け皿を置き、「何も出ない」状態を作らない。
 * 失敗状態は take の切り替えだけでなく、**同じ take で URL だけが変わった場合**にも戻す。
 */

function makeTake(overrides: Partial<SelectableTake> = {}): SelectableTake {
    return {
        id: 101,
        status: "ready",
        material_type: "still",
        size_bytes: 120_000,
        duration_ms: null,
        comment: null,
        captured_at: null,
        sort_order: 0,
        downloaded: false,
        has_thumbnail: false,
        ...overrides,
    };
}

const cut: TakeSelectionCut = {
    id: 34,
    type: "step",
    label: "手順1",
    scene: "工具を準備する",
    narration: "はじめに工具を準備します。",
    subtitle_primary: null,
    subtitle_secondary: "工具を準備する",
    material_type: "still",
    adopted: null,
};

function baseProps(take: SelectableTake | null = makeTake()) {
    return {
        take,
        takeIndex: 0,
        cut,
        manualStatus: "ready" as const,
        projectId: 7,
        manualId: 12,
        onChanged: () => undefined,
    };
}

beforeEach(() => {
    vi.stubGlobal("fetch", vi.fn());
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe("TakePreviewPanel", () => {
    it("静止画テイクは <img> を出し <video> を出さない", () => {
        render(TakePreviewPanel, { props: baseProps() });

        expect(screen.getByTestId("take-preview-image")).toBeInTheDocument();
        expect(screen.queryByTestId("take-preview-video")).not.toBeInTheDocument();
    });

    it("動画テイクは従来どおり <video> (回帰)", () => {
        render(TakePreviewPanel, { props: baseProps(makeTake({ material_type: "video" })) });

        expect(screen.getByTestId("take-preview-video")).toBeInTheDocument();
        expect(screen.queryByTestId("take-preview-image")).not.toBeInTheDocument();
    });

    it("読み込み失敗で受け皿に差し替わる", async () => {
        render(TakePreviewPanel, { props: baseProps() });

        await fireEvent.error(screen.getByTestId("take-preview-image"));

        await waitFor(() =>
            expect(screen.getByTestId("take-preview-unavailable")).toHaveTextContent(
                "このテイクはプレビューできません。",
            ),
        );
    });

    it("テイクを切り替えると失敗状態がリセットされる", async () => {
        const { rerender } = render(TakePreviewPanel, { props: baseProps() });
        await fireEvent.error(screen.getByTestId("take-preview-image"));
        await screen.findByTestId("take-preview-unavailable");

        await rerender({ take: makeTake({ id: 202 }) });

        await waitFor(() => expect(screen.getByTestId("take-preview-image")).toBeInTheDocument());
    });

    it("同じテイクのまま URL だけが変わっても失敗表示が残らない", async () => {
        // 署名 URL の再取得のように「take は同一で playbackUrl だけが変わる」場面。
        // component は失敗を**真偽値ではなく失敗した URL** で持つので、URL が変われば
        // 購読の有無に関係なく失敗表示が外れる (リセット漏れという失敗様式が構造的に無い)。
        const sameTake = makeTake();
        const { rerender } = render(TakePreviewPanel, { props: baseProps(sameTake) });
        const first = screen.getByTestId("take-preview-image").getAttribute("src");
        await fireEvent.error(screen.getByTestId("take-preview-image"));
        await screen.findByTestId("take-preview-unavailable");

        // take prop には触れず cut.id だけを変える (takeUrl の path が変わる)
        await rerender({ cut: { ...cut, id: 99 } });

        await waitFor(() => expect(screen.getByTestId("take-preview-image")).toBeInTheDocument());
        expect(screen.getByTestId("take-preview-image").getAttribute("src")).not.toBe(first);
    });

    it("失敗した URL のままなら失敗表示が残る (失敗が URL に紐づいていることの裏)", async () => {
        // 上のテストが「無条件に失敗表示が消える実装」でも緑になってしまわないことを示す
        // 負のコントロール。URL が変わらない再描画では失敗表示が維持される。
        const sameTake = makeTake();
        const { rerender } = render(TakePreviewPanel, { props: baseProps(sameTake) });
        await fireEvent.error(screen.getByTestId("take-preview-image"));
        await screen.findByTestId("take-preview-unavailable");

        await rerender({ takeIndex: 1 }); // URL に影響しない prop だけ動かす

        await waitFor(() =>
            expect(screen.getByTestId("take-preview-unavailable")).toBeInTheDocument(),
        );
    });

    it("非 ready のテイクには URL を張らず、静止画向けの文言で理由を出す", () => {
        render(TakePreviewPanel, { props: baseProps(makeTake({ status: "processing" })) });

        expect(screen.queryByTestId("take-preview-image")).not.toBeInTheDocument();
        expect(screen.getByTestId("take-not-playable")).toHaveTextContent("まだ表示できません");
    });
});
