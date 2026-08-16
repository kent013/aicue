import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
    createPointerDrag,
    type PointerDragController,
    type PointerDragState,
} from "@/lib/dnd/pointer-drag";
import { withoutPointerCapture } from "../../support/pointer-capture";

/*
 * ポインタの生死 (層 2)。開始・確定・取消・解放が漏れないことを jsdom 上で固定する。
 * 行の実測 (getBoundingClientRect) は spy で固定値へ差し替え、座標だけを入力にする。
 *
 * **保証範囲を誇張しない**: ここが緑でも iOS Safari の実挙動 (タッチの取りこぼし・
 * 慣性スクロールとの競合) は保証しない。それは実機確認 (受け入れ条件 A3) が担う。
 */

/** 行 3 つ。top = index * 100, height = 100 (中点は 50 / 150 / 250) */
let rects: Array<{ top: number; height: number }> = [];

let container: HTMLDivElement;
let rows: HTMLElement[] = [];
let handles: HTMLButtonElement[] = [];
/** ハンドルの pointerdown で start() が返した値 (受理/拒否) の履歴 */
let startResults: boolean[] = [];

let ctl: PointerDragController;
const onState = vi.fn<(state: PointerDragState) => void>();
const onCommit = vi.fn<(from: number, to: number) => void>();
const onCancel = vi.fn<() => void>();

function setRects(next: Array<{ top: number; height: number }>): void {
    rects = next;
}

function pointerEvent(
    type: string,
    init: { pointerId?: number; clientY?: number; button?: number; pointerType?: string } = {},
): PointerEvent {
    return new PointerEvent(type, {
        bubbles: true,
        cancelable: true,
        pointerId: init.pointerId ?? 1,
        clientY: init.clientY ?? 0,
        button: init.button ?? 0,
        pointerType: init.pointerType ?? "touch",
    });
}

/** index 行のハンドルで pointerdown する (currentTarget = ハンドル要素になる) */
function down(index: number, clientY: number, pointerId = 1): void {
    handles[index]?.dispatchEvent(pointerEvent("pointerdown", { clientY, pointerId }));
}

function move(clientY: number, pointerId = 1): void {
    window.dispatchEvent(pointerEvent("pointermove", { clientY, pointerId }));
}

function up(clientY: number, pointerId = 1): void {
    window.dispatchEvent(pointerEvent("pointerup", { clientY, pointerId }));
}

function cancel(pointerId = 1): void {
    window.dispatchEvent(pointerEvent("pointercancel", { pointerId }));
}

function pressEscape(): void {
    window.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }));
}

/** onState の最終通知 */
function lastState(): PointerDragState | undefined {
    return onState.mock.calls[onState.mock.calls.length - 1]?.[0];
}

beforeEach(() => {
    setRects([
        { top: 0, height: 100 },
        { top: 100, height: 100 },
        { top: 200, height: 100 },
    ]);
    container = document.createElement("div");
    rows = [];
    handles = [];
    startResults = [];
    for (let i = 0; i < 3; i += 1) {
        const row = document.createElement("div");
        row.dataset.rowIndex = String(i);
        const handle = document.createElement("button");
        handle.type = "button";
        handle.addEventListener("pointerdown", (event) => {
            startResults.push(ctl.start(i, event));
        });
        row.append(handle);
        container.append(row);
        rows.push(row);
        handles.push(handle);
    }
    document.body.append(container);

    // 行の実測は data-row-index から rects を引く (座標だけを入力にする)
    vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockImplementation(function (
        this: HTMLElement,
    ): DOMRect {
        const index = Number(this.dataset.rowIndex ?? "-1");
        const rect = rects[index] ?? { top: 0, height: 0 };
        return {
            top: rect.top,
            height: rect.height,
            bottom: rect.top + rect.height,
            left: 0,
            right: 0,
            width: 0,
            x: 0,
            y: rect.top,
            toJSON: () => ({}),
        } as DOMRect;
    });

    onState.mockReset();
    onCommit.mockReset();
    onCancel.mockReset();
    ctl = createPointerDrag({
        rows: () => rows,
        onState,
        onCommit,
        onCancel,
    });
});

afterEach(() => {
    ctl.destroy();
    container.remove();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe("createPointerDrag", () => {
    it("閾値未満の移動はドラッグにならない (タップが並べ替えにならない)", () => {
        down(0, 50);
        move(53);
        up(53);

        expect(onCommit).not.toHaveBeenCalled();
        expect(
            onState.mock.calls.filter(([state]) => state.activeIndex !== null),
        ).toHaveLength(0);
    });

    it("閾値を超えると activeIndex / insertionIndex を通知する", () => {
        down(0, 50);
        move(160);

        expect(lastState()).toEqual({ activeIndex: 0, insertionIndex: 2 });
    });

    it("pointerup で onCommit(from, 最終 index) が 1 回だけ呼ばれる", () => {
        down(0, 50);
        move(260); // 最終行の中点より下 → 挿入 index 3
        up(260);

        expect(onCommit).toHaveBeenCalledTimes(1);
        expect(onCommit).toHaveBeenCalledWith(0, 2); // toFinalIndex(3, 0) = 2
        expect(lastState()).toEqual({ activeIndex: null, insertionIndex: null });
    });

    it("掴んだ行の直後の隙間へ落としても最終 index は変わらない", () => {
        down(1, 150);
        move(190); // 行 1 の中点より下・行 2 の中点より上 → 挿入 index 2
        up(190);

        expect(onCommit).not.toHaveBeenCalled();
        expect(onCancel).toHaveBeenCalledTimes(1);
    });

    it("位置が変わらない drop は onCommit ではなく onCancel", () => {
        down(0, 50);
        move(10); // 挿入 index 0 → 最終 index 0 = from
        up(10);

        expect(onCommit).not.toHaveBeenCalled();
        expect(onCancel).toHaveBeenCalledTimes(1);
    });

    it("pointercancel は onCommit を呼ばず onCancel を呼ぶ", () => {
        down(0, 50);
        move(260);
        cancel();

        expect(onCommit).not.toHaveBeenCalled();
        expect(onCancel).toHaveBeenCalledTimes(1);
        expect(lastState()).toEqual({ activeIndex: null, insertionIndex: null });
    });

    it("Escape は onCommit を呼ばず onCancel を呼ぶ", () => {
        down(0, 50);
        move(260);
        pressEscape();

        expect(onCommit).not.toHaveBeenCalled();
        expect(onCancel).toHaveBeenCalledTimes(1);
    });

    it("異なる pointerId の move / up は無視する (2 本目の指で確定しない)", () => {
        down(0, 50, 1);
        move(260, 2); // 別の指
        up(260, 2);

        expect(onCommit).not.toHaveBeenCalled();
        expect(onCancel).not.toHaveBeenCalled();

        move(260, 1);
        up(260, 1);

        expect(onCommit).toHaveBeenCalledWith(0, 2);
    });

    it("start() は進行中に 2 回目を拒否し、1 本目の対象を保持する", () => {
        down(0, 50, 1);
        down(2, 250, 2); // 2 本目の指: 拒否される

        expect(startResults).toEqual([true, false]);

        move(260, 1);
        up(260, 1);

        expect(onCommit).toHaveBeenCalledWith(0, 2); // 1 本目 (from=0) のまま
    });

    it("マウスの左ボタン以外では開始しない", () => {
        handles[0]?.dispatchEvent(
            pointerEvent("pointerdown", { clientY: 50, pointerType: "mouse", button: 2 }),
        );

        expect(startResults).toEqual([false]);

        move(260);
        up(260);

        expect(onCommit).not.toHaveBeenCalled();
    });

    it("destroy() 後は listener が外れ callback が来ない", () => {
        down(0, 50);
        ctl.destroy();
        onState.mockReset();

        move(260);
        up(260);

        expect(onState).not.toHaveBeenCalled();
        expect(onCommit).not.toHaveBeenCalled();
    });

    it("ドラッグ中の destroy() は onCommit も onCancel も呼ばず onState だけをリセットする", () => {
        down(0, 50);
        move(260);
        onState.mockReset();

        ctl.destroy();

        expect(onCommit).not.toHaveBeenCalled();
        expect(onCancel).not.toHaveBeenCalled();
        expect(onState).toHaveBeenCalledTimes(1);
        expect(lastState()).toEqual({ activeIndex: null, insertionIndex: null });
    });

    it("pointer capture が無い環境でも開始 → 移動 → 確定まで完走する", async () => {
        await withoutPointerCapture(() => {
            down(0, 50);
            move(260);
            up(260);

            expect(onCommit).toHaveBeenCalledWith(0, 2);
        });
    });

    describe("端の自動スクロール", () => {
        let frame: FrameRequestCallback | null = null;

        beforeEach(() => {
            frame = null;
            // rAF を「callback を保存するだけ」の fake にする。同期即時実行にすると
            // tickAutoScroll が末尾で次フレームを登録するため無限再帰になる (design-review R2)。
            vi.stubGlobal("requestAnimationFrame", (cb: FrameRequestCallback) => {
                frame = cb;
                return 1;
            });
            vi.stubGlobal("cancelAnimationFrame", () => {
                frame = null;
            });
            vi.spyOn(window, "scrollBy").mockImplementation(() => undefined);
        });

        it("指を止めたまま端でスクロールしても挿入位置を採り直す", () => {
            down(0, 50);
            move(750); // 画面下端 (innerHeight=768 - 64 = 704 より下)

            expect(lastState()).toEqual({ activeIndex: 0, insertionIndex: 3 });
            expect(frame).not.toBeNull();

            // スクロールで行が下へずれた状態を作る (pointermove は出さない)
            setRects([
                { top: 600, height: 100 },
                { top: 700, height: 100 },
                { top: 800, height: 100 },
            ]);
            frame?.(0);

            expect(window.scrollBy).toHaveBeenCalledWith(0, 12);
            expect(lastState()).toEqual({ activeIndex: 0, insertionIndex: 2 });
        });

        it("端から離れるとスクロールを止める", () => {
            down(0, 50);
            move(750);
            expect(frame).not.toBeNull();

            move(400); // 端から離れる

            expect(frame).toBeNull();
        });
    });
});
