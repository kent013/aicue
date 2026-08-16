import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
import { get } from "svelte/store";
import { tick } from "svelte";
import TakeHoverPreview from "@/components/features/manual/TakeHoverPreview.svelte";
import { clearToasts, toasts } from "@/lib/stores/toast";

/*
 * TakeHoverPreview: 採用テイクのサムネイルにマウスを載せている間だけ
 * 無音・ループで自動再生する。タッチ / ペン・押下中・reduced-motion では起動しない。
 * 失敗 (自動再生拒否 / 取得失敗) は静かに静止画へ戻す (文言・トーストを出さない)。
 */

// prefers-reduced-motion はテストごとに切り替えるためモックする (既定は false = 動かしてよい)
const { reducedMotionMock } = vi.hoisted(() => ({ reducedMotionMock: vi.fn(() => false) }));
vi.mock("@/lib/capture/panel-navigation", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@/lib/capture/panel-navigation")>()),
    prefersReducedMotion: reducedMotionMock,
}));

const THUMBNAIL_URL = "/app/projects/1/manuals/2/cuts/3/takes/9/thumbnail";
const PLAYBACK_URL = "/app/projects/1/manuals/2/cuts/3/takes/9/playback";

function makeProps(overrides: Record<string, unknown> = {}) {
    return {
        thumbnailUrl: THUMBNAIL_URL,
        playbackUrl: PLAYBACK_URL,
        href: "/projects/1/manuals/2/cuts/3/takes",
        label: "採用テイクを開く",
        testId: "preview",
        ...overrides,
    };
}

/** ポインタイベントを組み立てる (pointerType / buttons が起動条件) */
function pointerEvent(
    type: string,
    init: { pointerType?: string; buttons?: number } = {},
): PointerEvent {
    return new PointerEvent(type, {
        bubbles: true,
        cancelable: true,
        pointerId: 1,
        pointerType: init.pointerType ?? "mouse",
        buttons: init.buttons ?? 0,
    });
}

/** ラッパ (Link) へポインタイベントを送る */
async function sendPointer(
    type: string,
    init: { pointerType?: string; buttons?: number } = {},
): Promise<void> {
    await fireEvent(screen.getByTestId("preview"), pointerEvent(type, init));
}

/** 滞留時間を進めて Svelte の反映を待つ */
async function advance(ms: number): Promise<void> {
    vi.advanceTimersByTime(ms);
    await tick();
    await tick();
}

const DWELL_MS = 200;

beforeEach(() => {
    vi.useFakeTimers();
    reducedMotionMock.mockReturnValue(false);
    clearToasts();
    // jsdom は HTMLMediaElement の再生系メソッドを未実装
    vi.spyOn(HTMLMediaElement.prototype, "play").mockResolvedValue(undefined);
    vi.spyOn(HTMLMediaElement.prototype, "pause").mockImplementation(() => undefined);
    vi.spyOn(HTMLMediaElement.prototype, "load").mockImplementation(() => undefined);
});

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    vi.useRealTimers();
    clearToasts();
});

describe("TakeHoverPreview", () => {
    it("既定は静止サムネイルを描画し <video> は存在しない", () => {
        render(TakeHoverPreview, makeProps());

        expect(screen.getByTestId("preview-image")).toHaveAttribute("src", THUMBNAIL_URL);
        expect(screen.queryByTestId("preview-video")).toBeNull();
    });

    it("マウスのホバーが滞留時間を超えると <video> が現れる", async () => {
        render(TakeHoverPreview, makeProps());

        await sendPointer("pointerenter");
        expect(screen.queryByTestId("preview-video")).toBeNull(); // 滞留前は静止画のまま

        await advance(DWELL_MS);

        const video = screen.getByTestId("preview-video");
        expect(video).toHaveAttribute("src", PLAYBACK_URL);
        expect(video).toHaveAttribute("poster", THUMBNAIL_URL);
        expect(video).toHaveAttribute("loop");
        expect(HTMLMediaElement.prototype.play).toHaveBeenCalled();
    });

    it("タッチのホバーでは再生しない (タップは遷移の意味だけを持つ)", async () => {
        render(TakeHoverPreview, makeProps());

        await sendPointer("pointerenter", { pointerType: "touch" });
        await advance(DWELL_MS);

        expect(screen.queryByTestId("preview-video")).toBeNull();
    });

    it("ボタン押下中のホバーでは再生しない", async () => {
        render(TakeHoverPreview, makeProps());

        await sendPointer("pointerenter", { buttons: 1 });
        await advance(DWELL_MS);

        expect(screen.queryByTestId("preview-video")).toBeNull();
    });

    it("滞留中に pointerdown が来たら再生しない (並べ替えドラッグとの競合を作らない)", async () => {
        render(TakeHoverPreview, makeProps());

        await sendPointer("pointerenter");
        await advance(100);
        await sendPointer("pointerdown");
        await advance(DWELL_MS);

        expect(screen.queryByTestId("preview-video")).toBeNull();
    });

    it("prefers-reduced-motion では再生しない", async () => {
        reducedMotionMock.mockReturnValue(true);
        render(TakeHoverPreview, makeProps());

        await sendPointer("pointerenter");
        await advance(DWELL_MS);

        expect(screen.queryByTestId("preview-video")).toBeNull();
    });

    it("滞留中に reduced-motion へ変わったら満了時に再生しない (満了時の再評価)", async () => {
        render(TakeHoverPreview, makeProps());

        await sendPointer("pointerenter");
        reducedMotionMock.mockReturnValue(true);
        await advance(DWELL_MS);

        expect(screen.queryByTestId("preview-video")).toBeNull();
    });

    it("pointerleave で <video> が消えて静止サムネイルへ戻る", async () => {
        render(TakeHoverPreview, makeProps());

        await sendPointer("pointerenter");
        await advance(DWELL_MS);
        expect(screen.getByTestId("preview-video")).toBeInTheDocument();

        await sendPointer("pointerleave");
        await tick();

        expect(screen.queryByTestId("preview-video")).toBeNull();
        expect(screen.getByTestId("preview-image")).toBeInTheDocument();
    });

    it("pointerleave を続けて 2 回受けても壊れない (停止は冪等)", async () => {
        render(TakeHoverPreview, makeProps());

        await sendPointer("pointerenter");
        await advance(DWELL_MS);
        await sendPointer("pointerleave");
        await sendPointer("pointerleave");
        await tick();

        expect(screen.queryByTestId("preview-video")).toBeNull();
        expect(screen.getByTestId("preview-image")).toBeInTheDocument();
    });

    it("タブが隠れたら <video> が消える (見えない場所で再生し続けない)", async () => {
        render(TakeHoverPreview, makeProps());

        await sendPointer("pointerenter");
        await advance(DWELL_MS);
        expect(screen.getByTestId("preview-video")).toBeInTheDocument();

        vi.spyOn(document, "visibilityState", "get").mockReturnValue("hidden");
        await fireEvent(document, new Event("visibilitychange"));
        await tick();

        expect(screen.queryByTestId("preview-video")).toBeNull();
    });

    it("自動再生が拒否されたら静止画へ戻り、トーストは 1 件も出ない", async () => {
        vi.spyOn(HTMLMediaElement.prototype, "play").mockRejectedValue(
            new DOMException("NotAllowedError"),
        );
        render(TakeHoverPreview, makeProps());

        await sendPointer("pointerenter");
        await advance(DWELL_MS);
        await tick();

        expect(screen.queryByTestId("preview-video")).toBeNull();
        expect(screen.getByTestId("preview-image")).toBeInTheDocument();
        expect(get(toasts)).toHaveLength(0);
    });

    it("取得・デコードに失敗したら静止画へ戻る", async () => {
        render(TakeHoverPreview, makeProps());

        await sendPointer("pointerenter");
        await advance(DWELL_MS);
        await fireEvent.error(screen.getByTestId("preview-video"));
        await tick();

        expect(screen.queryByTestId("preview-video")).toBeNull();
        expect(screen.getByTestId("preview-image")).toBeInTheDocument();
    });

    it("古い再生試行の失敗が、新しい <video> を止めない (世代判定)", async () => {
        // play() の決着をテスト側で握り、1 本目の rejection を 2 本目の mount 後に届かせる
        const pending: Array<(reason: unknown) => void> = [];
        vi.spyOn(HTMLMediaElement.prototype, "play").mockImplementation(
            () =>
                new Promise<void>((_resolve, reject) => {
                    pending.push(reject);
                }),
        );
        render(TakeHoverPreview, makeProps());

        await sendPointer("pointerenter");
        await advance(DWELL_MS);
        const first = screen.getByTestId("preview-video");

        await sendPointer("pointerleave");
        await tick();
        await sendPointer("pointerenter");
        await advance(DWELL_MS);
        const second = screen.getByTestId("preview-video");
        expect(second).not.toBe(first);

        // 1 本目の rejection が遅れて到着する
        pending[0](new DOMException("NotAllowedError"));
        await tick();
        await tick();

        expect(screen.getByTestId("preview-video")).toBe(second);
    });

    it("unmount 後の visibilitychange で例外が出ない (listener を同じ参照で外している)", async () => {
        const addSpy = vi.spyOn(document, "addEventListener");
        const removeSpy = vi.spyOn(document, "removeEventListener");

        const { unmount } = render(TakeHoverPreview, makeProps());

        const added = addSpy.mock.calls.filter(([type]) => type === "visibilitychange");
        expect(added).toHaveLength(1);

        unmount();
        await tick();

        const removed = removeSpy.mock.calls.filter(([type]) => type === "visibilitychange");
        expect(removed).toHaveLength(1);
        expect(removed[0][1]).toBe(added[0][1]);

        // 外れているので、破棄後に発火しても何も起きない
        document.dispatchEvent(new Event("visibilitychange"));
        await tick();
    });

    it("再生 URL が無い (非 ready) ときはホバーしても <video> を作らない", async () => {
        render(TakeHoverPreview, makeProps({ playbackUrl: null }));

        await sendPointer("pointerenter");
        await advance(DWELL_MS);

        expect(screen.queryByTestId("preview-video")).toBeNull();
        expect(screen.getByTestId("preview-image")).toBeInTheDocument();
    });

    it("サムネイル URL が無いときは画像ではなくプレースホルダを出す", () => {
        const { container } = render(TakeHoverPreview, makeProps({ thumbnailUrl: null }));

        expect(screen.queryByTestId("preview-image")).toBeNull();
        expect(container.querySelector("svg")).not.toBeNull();
    });
});
