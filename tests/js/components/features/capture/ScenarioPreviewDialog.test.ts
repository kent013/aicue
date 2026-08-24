import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
import ScenarioPreviewDialog from "@/components/features/capture/ScenarioPreviewDialog.svelte";
import type { CaptureCut } from "@/types/capture";

/*
 * ScenarioPreviewDialog (通し再生 / T191)。
 *
 * jsdom は実メディア再生を行わないため、ここで固定できるのは **DOM 契約とイベント配線**まで
 * である (実機での連続再生の滑らかさは実機確認の領域)。逆に言えば、次の構造的な不変条件は
 * すべてここで固定する:
 *   - 先読み済み要素をそのまま本再生へ引き継ぐ (同じ動画を 2 回取得しない)
 *   - missing を挟む並びでも次の clip に必ず src が入る (再生不能を作らない)
 *   - 世代 / 割り当て世代により、旧要素・teardown 後の遅延イベントが状態を変えない
 *   - programmatic pause と利用者 pause を slot 単位で区別する
 *   - 1 本の失敗で通し再生が止まらない (停滞監視が有限時間で回収する)
 */

const TARGET = { projectId: 1, manualId: 5 };

function cut(id: number, readyTakeId: number | null): CaptureCut {
    return {
        id,
        type: "step",
        parent_cut_id: null,
        scene: `scene-${id}`,
        shot_type: "hiki",
        shooting_point: null,
        narration: "",
        subtitle_primary: null,
        subtitle_secondary: `字幕 ${id}`,
        material_type: null,
        adopted_take_id: readyTakeId,
        adopted_ready_take_id: readyTakeId,
        takes: [],
    };
}

const LABELS: Record<number, string> = { 101: "手順 1", 102: "手順 2", 103: "手順 3" };

function playbackUrl(cutId: number, takeId: number): string {
    return `/organizations/test-org/app/projects/1/manuals/5/cuts/${cutId}/takes/${takeId}/playback`;
}

function renderDialog(cuts: CaptureCut[], onClose = vi.fn()): { onClose: ReturnType<typeof vi.fn> } {
    render(ScenarioPreviewDialog, {
        open: true,
        projectId: TARGET.projectId,
        manualId: TARGET.manualId,
        cuts,
        labels: LABELS,
        placeholderSeconds: 3,
        onClose,
    });

    return { onClose };
}

function video(slot: 0 | 1): HTMLVideoElement {
    return screen.getByTestId(`scenario-preview-video-${slot}`) as HTMLVideoElement;
}

function body(): HTMLElement {
    return screen.getByTestId("scenario-preview-body");
}

/** 要素が「再生中」であるかのように見せる (jsdom の paused は常に true のため) */
function markPlaying(element: HTMLVideoElement): void {
    Object.defineProperty(element, "paused", { value: false, configurable: true });
}

let playMock: ReturnType<typeof vi.fn>;

beforeEach(() => {
    playMock = vi.fn().mockResolvedValue(undefined);
    vi.spyOn(HTMLMediaElement.prototype, "play").mockImplementation(
        playMock as unknown as () => Promise<void>,
    );
    vi.spyOn(HTMLMediaElement.prototype, "pause").mockImplementation(() => undefined);
    vi.spyOn(HTMLMediaElement.prototype, "load").mockImplementation(() => undefined);
});

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    vi.useRealTimers();
});

describe("ScenarioPreviewDialog: 起動と告知", () => {
    it("開くと先頭 entry の src が active 要素に入る", () => {
        renderDialog([cut(101, 900), cut(102, 901)]);

        expect(video(0)).toHaveAttribute("src", playbackUrl(101, 900));
        expect(body()).toHaveAttribute("data-active-slot", "0");
    });

    it("使用できる採用テイクが無いカットがあると事前告知を出す (ボタンは止めない)", () => {
        renderDialog([cut(101, 900), cut(102, null)]);

        expect(screen.getByTestId("scenario-preview-coverage-note")).toHaveTextContent(
            "1 / 2 件のカットに、撮影・処理が完了した採用テイクがありません",
        );
        expect(screen.getByTestId("scenario-preview-close")).not.toBeDisabled();
    });

    it("欠落が無ければ事前告知は出ない", () => {
        renderDialog([cut(101, 900)]);

        expect(screen.queryByTestId("scenario-preview-coverage-note")).not.toBeInTheDocument();
    });

    it("missing entry ではプレースホルダ文言を出し video に src を入れない", () => {
        renderDialog([cut(101, null), cut(102, 901)]);

        expect(screen.getByTestId("scenario-preview-placeholder")).toHaveTextContent(
            "手順 1: 撮影・処理が完了した採用テイクがありません",
        );
        expect(video(0)).not.toHaveAttribute("src");
        expect(video(1)).not.toHaveAttribute("src");
    });

    it("字幕は初期 ON で、トグルで隠せる", async () => {
        renderDialog([cut(101, 900)]);

        expect(screen.getByTestId("scenario-preview-subtitle-secondary")).toHaveTextContent("字幕 101");

        await fireEvent.click(screen.getByTestId("scenario-preview-subtitle-toggle"));

        expect(screen.queryByTestId("scenario-preview-subtitle-secondary")).not.toBeInTheDocument();
    });
});

describe("ScenarioPreviewDialog: 先読みと役割の入れ替え", () => {
    it("再生に入ると次のクリップが inactive 側へ先読みされる", async () => {
        renderDialog([cut(101, 900), cut(102, 901)]);

        await fireEvent(video(0), new Event("playing"));

        expect(video(1)).toHaveAttribute("src", playbackUrl(102, 901));
    });

    it("進むと役割が入れ替わり、先読み済み要素は作り直されない (二重取得を作らない)", async () => {
        renderDialog([cut(101, 900), cut(102, 901)]);
        await fireEvent(video(0), new Event("playing"));

        const assignmentBefore = video(1).getAttribute("data-assignment");

        await fireEvent(video(0), new Event("ended"));

        expect(body()).toHaveAttribute("data-active-slot", "1");
        expect(body()).toHaveAttribute("data-index", "1");
        expect(video(1)).toHaveAttribute("src", playbackUrl(102, 901));
        expect(video(1).getAttribute("data-assignment")).toBe(assignmentBefore);
    });

    it("次が missing なら先読みせず inactive 側を空のままにする", async () => {
        renderDialog([cut(101, 900), cut(102, null)]);

        await fireEvent(video(0), new Event("playing"));

        expect(video(1)).not.toHaveAttribute("src");
    });
});

describe("ScenarioPreviewDialog: 進んだ先の同期 (再生不能を作らない)", () => {
    it("missing → clip で次の clip に src が入る", async () => {
        vi.useFakeTimers();
        renderDialog([cut(101, null), cut(102, 901)]);

        await vi.advanceTimersByTimeAsync(4_000);

        expect(body()).toHaveAttribute("data-index", "1");
        expect(video(1)).toHaveAttribute("src", playbackUrl(102, 901));
    });

    it("clip → missing → clip で最後の clip に src が入る", async () => {
        vi.useFakeTimers();
        renderDialog([cut(101, 900), cut(102, null), cut(103, 902)]);

        await fireEvent(video(0), new Event("ended"));
        expect(body()).toHaveAttribute("data-index", "1");

        await vi.advanceTimersByTimeAsync(4_000);

        expect(body()).toHaveAttribute("data-index", "2");
        const activeSlot = body().getAttribute("data-active-slot");
        expect(video(activeSlot === "0" ? 0 : 1)).toHaveAttribute("src", playbackUrl(103, 902));
    });

    it("missing → missing → clip で最後の clip に src が入る", async () => {
        vi.useFakeTimers();
        renderDialog([cut(101, null), cut(102, null), cut(103, 902)]);

        await vi.advanceTimersByTimeAsync(4_000);
        await vi.advanceTimersByTimeAsync(4_000);

        expect(body()).toHaveAttribute("data-index", "2");
        const activeSlot = body().getAttribute("data-active-slot");
        expect(video(activeSlot === "0" ? 0 : 1)).toHaveAttribute("src", playbackUrl(103, 902));
    });
});

describe("ScenarioPreviewDialog: 自動再生制限 (blocked)", () => {
    it("NotAllowedError の拒否で blocked 表示になり 3 つの出口が出る", async () => {
        playMock.mockRejectedValue(new DOMException("blocked", "NotAllowedError"));
        renderDialog([cut(101, 900), cut(102, 901)]);

        await vi.waitFor(() => {
            expect(screen.getByTestId("scenario-preview-blocked")).toBeInTheDocument();
        });
        expect(screen.getByTestId("scenario-preview-retry")).toBeInTheDocument();
        expect(screen.getByTestId("scenario-preview-skip")).toBeInTheDocument();
        expect(screen.getByTestId("scenario-preview-close")).toBeInTheDocument();
        expect(body()).toHaveAttribute("data-clip", "blocked");
    });

    it("blocked からスキップで次のカットへ進める", async () => {
        playMock.mockRejectedValue(new DOMException("blocked", "NotAllowedError"));
        renderDialog([cut(101, 900), cut(102, 901)]);
        await vi.waitFor(() => {
            expect(screen.getByTestId("scenario-preview-skip")).toBeInTheDocument();
        });

        await fireEvent.click(screen.getByTestId("scenario-preview-skip"));

        expect(body()).toHaveAttribute("data-index", "1");
    });

    it("拒否後もダイアログを閉じられる (未処理 rejection を残さない)", async () => {
        playMock.mockRejectedValue(new DOMException("blocked", "NotAllowedError"));
        const { onClose } = renderDialog([cut(101, 900)]);
        await vi.waitFor(() => {
            expect(screen.getByTestId("scenario-preview-blocked")).toBeInTheDocument();
        });

        await fireEvent.click(screen.getByTestId("scenario-preview-close"));

        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it("閉じて開き直した後に届く旧セッションの拒否は新セッションを blocked にしない (Codex R1-Critical)", async () => {
        // 1 本目の play() は保留したままにし、閉じた後に拒否させる
        const pending: { reject?: (reason: unknown) => void } = {};
        playMock.mockImplementationOnce(
            () =>
                new Promise<void>((_resolve, reject) => {
                    pending.reject = reject;
                }),
        );
        const onClose = vi.fn();
        const { unmount } = render(ScenarioPreviewDialog, {
            open: true,
            projectId: TARGET.projectId,
            manualId: TARGET.manualId,
            cuts: [cut(101, 900)],
            labels: LABELS,
            placeholderSeconds: 3,
            onClose,
        });
        await vi.waitFor(() => {
            expect(pending.reject).toBeTypeOf("function");
        });

        await fireEvent.click(screen.getByTestId("scenario-preview-close"));
        unmount();

        // 開き直し (新しいセッション。世代は再び 0 から始まる)
        renderDialog([cut(101, 900)]);
        pending.reject?.(new DOMException("blocked", "NotAllowedError"));
        await Promise.resolve();

        expect(screen.queryByTestId("scenario-preview-blocked")).not.toBeInTheDocument();
        expect(body()).toHaveAttribute("data-clip", "loading");
    });

    it("同一インスタンスの close → reopen をまたぐ拒否も新セッションへ混入しない", async () => {
        const pending: { reject?: (reason: unknown) => void } = {};
        playMock.mockImplementationOnce(
            () =>
                new Promise<void>((_resolve, reject) => {
                    pending.reject = reject;
                }),
        );
        const { rerender } = render(ScenarioPreviewDialog, {
            open: true,
            projectId: TARGET.projectId,
            manualId: TARGET.manualId,
            cuts: [cut(101, 900)],
            labels: LABELS,
            placeholderSeconds: 3,
            onClose: vi.fn(),
        });
        await vi.waitFor(() => {
            expect(pending.reject).toBeTypeOf("function");
        });

        await rerender({ open: false });
        await rerender({ open: true });

        pending.reject?.(new DOMException("blocked", "NotAllowedError"));
        await Promise.resolve();

        expect(screen.queryByTestId("scenario-preview-blocked")).not.toBeInTheDocument();
        expect(body()).toHaveAttribute("data-clip", "loading");
    });

    it("もう一度再生の後に届く旧セッションの拒否も混入しない", async () => {
        const pending: { reject?: (reason: unknown) => void } = {};
        playMock.mockImplementationOnce(
            () =>
                new Promise<void>((_resolve, reject) => {
                    pending.reject = reject;
                }),
        );
        renderDialog([cut(101, 900)]);
        await vi.waitFor(() => {
            expect(pending.reject).toBeTypeOf("function");
        });

        await fireEvent(video(0), new Event("ended")); // 終端まで再生
        await fireEvent.click(screen.getByTestId("scenario-preview-replay"));

        pending.reject?.(new DOMException("blocked", "NotAllowedError"));
        await Promise.resolve();

        expect(screen.queryByTestId("scenario-preview-blocked")).not.toBeInTheDocument();
        expect(body()).toHaveAttribute("data-index", "0");
        expect(body()).toHaveAttribute("data-clip", "loading");
    });

    it("tick 待ちの間に前進した古い再生要求は、新しいクリップを再生しない (Codex R2-Critical)", async () => {
        const played: HTMLVideoElement[] = [];
        vi.spyOn(HTMLMediaElement.prototype, "play").mockImplementation(function (
            this: HTMLVideoElement,
        ) {
            played.push(this);

            return Promise.resolve();
        });

        // render は同期で startPreview まで進み、先頭クリップの playActive() は
        // await tick() で保留になる。その保留中に前のクリップが終端まで進む状況を作る。
        renderDialog([cut(101, 900), cut(102, 901)]);
        video(0).dispatchEvent(new Event("ended"));

        await vi.waitFor(() => {
            expect(played.length).toBeGreaterThan(0);
        });
        await Promise.resolve();

        // 再生要求は「進んだ先のクリップに対して 1 回だけ」であること
        // (古い呼び出しが activeSlot / 世代を読み直すと 2 回になる = 二重取得と誤 blocked の温床)
        expect(played).toEqual([video(1)]);
        expect(body()).toHaveAttribute("data-index", "1");
    });

    it("tick 待ちの間に非表示になったら再生要求を出さず、復帰で出し直す (Codex R3-Critical)", async () => {
        const played: HTMLVideoElement[] = [];
        vi.spyOn(HTMLMediaElement.prototype, "play").mockImplementation(function (
            this: HTMLVideoElement,
        ) {
            played.push(this);

            return Promise.resolve();
        });

        // render は同期で startPreview まで進み、playActive() は await tick() で保留になる。
        // その保留中にページが非表示になる状況を作る。
        renderDialog([cut(101, 900)]);
        Object.defineProperty(document, "visibilityState", { value: "hidden", configurable: true });
        document.dispatchEvent(new Event("visibilitychange"));
        // 保留中の playActive() が再開しきるまで待つ (macrotask で microtask を出し切る)
        await new Promise((resolve) => setTimeout(resolve, 0));

        // 非表示中に再生要求を出すと、直前の programmatic pause を打ち消して裏で再生され続ける
        expect(played).toEqual([]);
        expect(body()).toHaveAttribute("data-clip", "loading");

        // 復帰したら出し直す (誰も再生を出さないまま停滞監視の回収を待つ形にしない)
        Object.defineProperty(document, "visibilityState", { value: "visible", configurable: true });
        await fireEvent(document, new Event("visibilitychange"));

        await vi.waitFor(() => {
            expect(played).toEqual([video(0)]);
        });
    });

    it("NotAllowedError 以外の拒否は即 failed にせず、停滞監視が回収する", async () => {
        vi.useFakeTimers();
        playMock.mockRejectedValue(new Error("decode failure"));
        renderDialog([cut(101, 900), cut(102, 901)]);

        await vi.advanceTimersByTimeAsync(1_000);
        expect(body()).toHaveAttribute("data-clip", "loading");

        await vi.advanceTimersByTimeAsync(20_000);
        expect(body()).toHaveAttribute("data-clip", "failed");
        expect(screen.getByTestId("scenario-preview-failed")).toHaveTextContent(
            "手順 1: このカットは再生できませんでした",
        );

        await vi.advanceTimersByTimeAsync(4_000);
        expect(body()).toHaveAttribute("data-index", "1");
    });
});

describe("ScenarioPreviewDialog: 失敗表示の回収", () => {
    it("failed 中に progress が届き続けても placeholderSeconds で次へ進む", async () => {
        vi.useFakeTimers();
        renderDialog([cut(101, 900), cut(102, 901)]);

        await fireEvent(video(0), new Event("error"));
        expect(body()).toHaveAttribute("data-clip", "failed");

        // 失敗したクリップの要素がバッファリングを続けて progress を出し続ける状況
        for (let elapsed = 0; elapsed < 3; elapsed += 1) {
            await fireEvent(video(0), new Event("timeupdate"));
            await vi.advanceTimersByTimeAsync(1_000);
        }

        expect(body()).toHaveAttribute("data-index", "1");
    });
});

describe("ScenarioPreviewDialog: pause の抑止", () => {
    it("利用者操作の pause は paused になる", async () => {
        renderDialog([cut(101, 900)]);

        await fireEvent(video(0), new Event("pause"));

        expect(body()).toHaveAttribute("data-clip", "paused");
    });

    it("自分から止めた pause は paused を作らない (非表示での programmatic pause)", async () => {
        renderDialog([cut(101, 900)]);
        await fireEvent(video(0), new Event("playing"));
        markPlaying(video(0));

        // 非表示 → component が自分から pause() する (抑止が立つ)
        Object.defineProperty(document, "visibilityState", { value: "hidden", configurable: true });
        await fireEvent(document, new Event("visibilitychange"));
        await fireEvent(video(0), new Event("pause"));

        expect(body()).toHaveAttribute("data-clip", "playing");

        Object.defineProperty(document, "visibilityState", { value: "visible", configurable: true });
    });

    it("抑止は slot 別である (片方を止めても他方の利用者 pause は効く)", async () => {
        renderDialog([cut(101, 900), cut(102, 901)]);
        await fireEvent(video(0), new Event("playing")); // slot1 へ先読み
        markPlaying(video(0));

        Object.defineProperty(document, "visibilityState", { value: "hidden", configurable: true });
        await fireEvent(document, new Event("visibilitychange"));
        Object.defineProperty(document, "visibilityState", { value: "visible", configurable: true });
        await fireEvent(document, new Event("visibilitychange"));

        // slot0 の抑止が立っている状態で slot1 (先読み側) から pause が来ても握り潰さない
        await fireEvent(video(1), new Event("pause"));

        // slot1 の世代は先読み世代 (現在世代 + 1) なので reducer が捨てる = 状態は変わらない
        expect(body()).toHaveAttribute("data-clip", "playing");

        // slot0 の抑止は残っているので、こちらの pause は 1 度だけ握り潰される
        await fireEvent(video(0), new Event("pause"));
        expect(body()).toHaveAttribute("data-clip", "playing");
        // 抑止は消費済み。次の pause は利用者操作として通る
        await fireEvent(video(0), new Event("pause"));
        expect(body()).toHaveAttribute("data-clip", "paused");
    });

    it("既に paused の要素には抑止を立てない (後の利用者 pause を握り潰さない)", async () => {
        renderDialog([cut(101, 900)]);
        // jsdom の既定 paused=true のまま非表示にする (pause() は呼ばれない = 抑止も立たない)
        Object.defineProperty(document, "visibilityState", { value: "hidden", configurable: true });
        await fireEvent(document, new Event("visibilitychange"));
        Object.defineProperty(document, "visibilityState", { value: "visible", configurable: true });
        await fireEvent(document, new Event("visibilitychange"));

        await fireEvent(video(0), new Event("pause"));

        expect(body()).toHaveAttribute("data-clip", "paused");
    });
});

describe("ScenarioPreviewDialog: 遅延イベントの遮断", () => {
    it("旧 slot の遅延 error / ended が進んだ後のクリップを壊さない", async () => {
        renderDialog([cut(101, 900), cut(102, 901)]);
        await fireEvent(video(0), new Event("playing"));
        await fireEvent(video(0), new Event("ended"));

        expect(body()).toHaveAttribute("data-index", "1");

        await fireEvent(video(0), new Event("error"));
        await fireEvent(video(0), new Event("ended"));

        expect(body()).toHaveAttribute("data-index", "1");
        expect(body()).toHaveAttribute("data-clip", "loading");
    });

    it("同一 slot を作り直した後、旧要素からのイベントは届かない", async () => {
        vi.useFakeTimers();
        renderDialog([cut(101, 900), cut(102, null), cut(103, 902)]);
        const firstElement = video(0);

        await fireEvent(video(0), new Event("ended")); // → missing (slot1 が active)
        await vi.advanceTimersByTimeAsync(4_000); // → clip3 (slot0 を作り直して active)

        expect(body()).toHaveAttribute("data-index", "2");
        expect(video(0)).not.toBe(firstElement); // 要素ごと作り直されている

        await fireEvent(firstElement, new Event("ended"));
        await fireEvent(firstElement, new Event("error"));

        expect(body()).toHaveAttribute("data-index", "2");
        expect(body()).toHaveAttribute("data-clip", "loading");
    });

    it("非表示中は ended が起きても次へ進まない", async () => {
        renderDialog([cut(101, 900), cut(102, 901)]);
        Object.defineProperty(document, "visibilityState", { value: "hidden", configurable: true });
        await fireEvent(document, new Event("visibilitychange"));

        await fireEvent(video(0), new Event("ended"));

        expect(body()).toHaveAttribute("data-index", "0");

        Object.defineProperty(document, "visibilityState", { value: "visible", configurable: true });
    });
});

describe("ScenarioPreviewDialog: 終端と後始末", () => {
    it("最終 entry の ended で終端表示になり、もう一度再生できる", async () => {
        renderDialog([cut(101, 900)]);

        await fireEvent(video(0), new Event("ended"));

        expect(screen.getByTestId("scenario-preview-finished")).toHaveTextContent(
            "すべてのカットを再生しました。",
        );

        await fireEvent.click(screen.getByTestId("scenario-preview-replay"));

        expect(body()).toHaveAttribute("data-index", "0");
        expect(video(0)).toHaveAttribute("src", playbackUrl(101, 900));
    });

    it("終端では両方の要素が teardown され、時間駆動も止まる", async () => {
        vi.useFakeTimers();
        renderDialog([cut(101, 900)]);

        await fireEvent(video(0), new Event("ended"));

        expect(video(0)).not.toHaveAttribute("src");
        expect(video(1)).not.toHaveAttribute("src");

        // 終端後に時間が進んでも状態は動かない (interval を破棄している)
        await vi.advanceTimersByTimeAsync(60_000);
        expect(screen.getByTestId("scenario-preview-finished")).toBeInTheDocument();
    });

    it("閉じると両方の要素を teardown し onClose を 1 度だけ呼ぶ", async () => {
        const { onClose } = renderDialog([cut(101, 900), cut(102, 901)]);
        await fireEvent(video(0), new Event("playing"));
        expect(video(1)).toHaveAttribute("src", playbackUrl(102, 901));

        await fireEvent.click(screen.getByTestId("scenario-preview-close"));

        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it("teardown 後に届いた遅延イベントは状態を変えない", async () => {
        vi.useFakeTimers();
        renderDialog([cut(101, 900), cut(102, 901)]);
        const active = video(0);

        await fireEvent(active, new Event("ended")); // slot0 teardown → slot1 が active
        const indexAfterAdvance = body().getAttribute("data-index");

        await fireEvent(active, new Event("pause"));
        await fireEvent(active, new Event("error"));
        await fireEvent(active, new Event("ended"));

        expect(body().getAttribute("data-index")).toBe(indexAfterAdvance);
        expect(body()).toHaveAttribute("data-clip", "loading");
    });
});
