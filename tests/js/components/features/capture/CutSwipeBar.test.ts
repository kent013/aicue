import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
import CutSwipeBar from "@/components/features/capture/CutSwipeBar.svelte";
import {
    SWIPE_EDGE_EXCLUSION_PX,
    SWIPE_MIN_DISTANCE_PX,
} from "@/lib/capture/landscape-capture";

/*
 * 横持ち全画面の上部カット名スワイプバー (T186 施策 B)。
 *
 * スワイプ判定そのものは landscape-capture.test.ts が網羅する。
 * ここで固定するのは **配線** (どのイベント系列で onNavigate が何回呼ばれるか) と
 * 禁止事項 8 (端でも disabled にしない) である。
 */

const VIEWPORT_WIDTH = 800;
const CENTER_X = 400;
const CENTER_Y = 60;

function renderBar(onNavigate = vi.fn()): { onNavigate: ReturnType<typeof vi.fn> } {
    render(CutSwipeBar, {
        props: {
            label: "手順 2",
            scene: "ネジを締める",
            position: { index: 2, total: 12 },
            onNavigate,
        },
    });

    return { onNavigate };
}

/** pointerdown → pointerup の系列を発火する (始点・終点を px で指定)。 */
async function swipe(
    target: Element,
    from: { x: number; y: number },
    to: { x: number; y: number },
    pointerId = 1,
): Promise<void> {
    await fireEvent.pointerDown(target, { pointerId, clientX: from.x, clientY: from.y });
    await fireEvent.pointerUp(target, { pointerId, clientX: to.x, clientY: to.y });
}

beforeEach(() => {
    vi.stubGlobal("innerWidth", VIEWPORT_WIDTH);
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe("CutSwipeBar 表示", () => {
    it("ラベル・現在位置・カット内容を描画する", () => {
        renderBar();

        expect(screen.getByTestId("cut-swipe-label")).toHaveTextContent("手順 2");
        expect(screen.getByTestId("cut-swipe-label")).toHaveTextContent("2 / 12");
        expect(screen.getByTestId("cut-swipe-scene")).toHaveTextContent("ネジを締める");
    });

    it("端かどうかを知らないので前後ボタンは disabled にならない (禁止事項 8)", () => {
        renderBar();

        expect(screen.getByTestId("cut-swipe-previous")).not.toBeDisabled();
        expect(screen.getByTestId("cut-swipe-next")).not.toBeDisabled();
    });

    it("バー自体は Tab 停止にしない (停止するのは内側の 2 ボタンだけ)", () => {
        renderBar();

        const bar = screen.getByTestId("cut-swipe-bar");
        expect(bar).not.toHaveAttribute("tabindex");
        expect(bar.querySelectorAll("button")).toHaveLength(2);
    });
});

describe("CutSwipeBar ボタン操作", () => {
    it("「前のカット」は onNavigate(-1)", async () => {
        const { onNavigate } = renderBar();

        await fireEvent.click(screen.getByTestId("cut-swipe-previous"));

        expect(onNavigate).toHaveBeenCalledTimes(1);
        expect(onNavigate).toHaveBeenCalledWith(-1);
    });

    it("「次のカット」は onNavigate(1)", async () => {
        const { onNavigate } = renderBar();

        await fireEvent.click(screen.getByTestId("cut-swipe-next"));

        expect(onNavigate).toHaveBeenCalledTimes(1);
        expect(onNavigate).toHaveBeenCalledWith(1);
    });
});

describe("CutSwipeBar キー操作 (前後ボタンからバーへバブルする)", () => {
    it.each([
        ["ArrowLeft", -1],
        ["ArrowRight", 1],
    ] as const)("%s で onNavigate(%s) を呼び preventDefault する", async (key, direction) => {
        const { onNavigate } = renderBar();

        const notCancelled = await fireEvent.keyDown(screen.getByTestId("cut-swipe-previous"), {
            key,
        });

        expect(onNavigate).toHaveBeenCalledWith(direction);
        expect(notCancelled).toBe(false); // preventDefault された
    });

    it("他のキーでは移動しない", async () => {
        const { onNavigate } = renderBar();

        await fireEvent.keyDown(screen.getByTestId("cut-swipe-next"), { key: "Enter" });

        expect(onNavigate).not.toHaveBeenCalled();
    });
});

describe("CutSwipeBar スワイプ配線", () => {
    it("左へスワイプで onNavigate(1)", async () => {
        const { onNavigate } = renderBar();

        await swipe(
            screen.getByTestId("cut-swipe-bar"),
            { x: CENTER_X, y: CENTER_Y },
            { x: CENTER_X - 120, y: CENTER_Y },
        );

        expect(onNavigate).toHaveBeenCalledTimes(1);
        expect(onNavigate).toHaveBeenCalledWith(1);
    });

    it("右へスワイプで onNavigate(-1)", async () => {
        const { onNavigate } = renderBar();

        await swipe(
            screen.getByTestId("cut-swipe-bar"),
            { x: CENTER_X, y: CENTER_Y },
            { x: CENTER_X + 120, y: CENTER_Y },
        );

        expect(onNavigate).toHaveBeenCalledTimes(1);
        expect(onNavigate).toHaveBeenCalledWith(-1);
    });

    it.each([
        [
            "距離不足",
            { x: CENTER_X, y: CENTER_Y },
            { x: CENTER_X - (SWIPE_MIN_DISTANCE_PX - 1), y: CENTER_Y },
        ],
        ["縦優勢", { x: CENTER_X, y: CENTER_Y }, { x: CENTER_X - 100, y: CENTER_Y + 100 }],
        [
            "左端始まり",
            { x: SWIPE_EDGE_EXCLUSION_PX - 1, y: CENTER_Y },
            { x: SWIPE_EDGE_EXCLUSION_PX - 1 + 200, y: CENTER_Y },
        ],
        [
            "右端始まり",
            { x: VIEWPORT_WIDTH - SWIPE_EDGE_EXCLUSION_PX + 1, y: CENTER_Y },
            { x: VIEWPORT_WIDTH - SWIPE_EDGE_EXCLUSION_PX + 1 - 200, y: CENTER_Y },
        ],
    ])("%s では移動しない", async (_label, from, to) => {
        const { onNavigate } = renderBar();

        await swipe(screen.getByTestId("cut-swipe-bar"), from, to);

        expect(onNavigate).not.toHaveBeenCalled();
    });

    it("pointercancel の後の pointerup では移動しない (始点を捨てている)", async () => {
        const { onNavigate } = renderBar();
        const bar = screen.getByTestId("cut-swipe-bar");

        await fireEvent.pointerDown(bar, { pointerId: 1, clientX: CENTER_X, clientY: CENTER_Y });
        await fireEvent.pointerCancel(bar, { pointerId: 1 });
        await fireEvent.pointerUp(bar, {
            pointerId: 1,
            clientX: CENTER_X - 200,
            clientY: CENTER_Y,
        });

        expect(onNavigate).not.toHaveBeenCalled();
    });

    it("別 pointerId の pointerup は始点と対応しないので移動しない", async () => {
        const { onNavigate } = renderBar();
        const bar = screen.getByTestId("cut-swipe-bar");

        await fireEvent.pointerDown(bar, { pointerId: 1, clientX: CENTER_X, clientY: CENTER_Y });
        await fireEvent.pointerUp(bar, {
            pointerId: 2,
            clientX: CENTER_X - 200,
            clientY: CENTER_Y,
        });

        expect(onNavigate).not.toHaveBeenCalled();
    });
});

/*
 * スワイプと click の二重発火防止。
 *
 * click は jsdom / Testing Library の pointer event からは合成されないため、
 * **明示的に発火する**。pointerup だけのテストは「1 回しか起きない条件で緑になる」空振りになる。
 */
describe("CutSwipeBar ボタン上で始めた操作の二重発火防止", () => {
    it("ボタン上で pointerdown → 大きく動かして pointerup → click で合計 1 回だけ", async () => {
        const { onNavigate } = renderBar();
        const button = screen.getByTestId("cut-swipe-next");

        await fireEvent.pointerDown(button, {
            pointerId: 1,
            clientX: CENTER_X,
            clientY: CENTER_Y,
        });
        await fireEvent.pointerUp(button, {
            pointerId: 1,
            clientX: CENTER_X - 200,
            clientY: CENTER_Y,
        });
        await fireEvent.click(button);

        expect(onNavigate).toHaveBeenCalledTimes(1);
        expect(onNavigate).toHaveBeenCalledWith(1);
    });

    it("ボタン内のアイコン要素から始めても同じ (closest('button') が子孫から効く)", async () => {
        const { onNavigate } = renderBar();
        const button = screen.getByTestId("cut-swipe-next");
        const icon = button.querySelector("svg");
        expect(icon).not.toBeNull();

        await fireEvent.pointerDown(icon as SVGElement, {
            pointerId: 1,
            clientX: CENTER_X,
            clientY: CENTER_Y,
        });
        await fireEvent.pointerUp(icon as SVGElement, {
            pointerId: 1,
            clientX: CENTER_X - 200,
            clientY: CENTER_Y,
        });
        await fireEvent.click(button);

        expect(onNavigate).toHaveBeenCalledTimes(1);
        expect(onNavigate).toHaveBeenCalledWith(1);
    });
});
