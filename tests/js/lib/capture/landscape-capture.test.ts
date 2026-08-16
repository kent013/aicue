import { afterEach, describe, expect, it, vi } from "vitest";
import {
    CUT_EDGE_MESSAGES,
    LANDSCAPE_CAPTURE_MEDIA_QUERY,
    RECORDING_BLOCKS_NAVIGATION_MESSAGE,
    SWIPE_EDGE_EXCLUSION_PX,
    SWIPE_MIN_DISTANCE_PX,
    decideCutNavigation,
    lockBackgroundScroll,
    matchesLandscapeCapture,
    resolveSwipe,
    subscribeLandscapeCapture,
    swipeDirection,
} from "@/lib/capture/landscape-capture";

/*
 * 横持ち全画面撮影の判定・ジェスチャ解釈・移動判断・背景スクロール抑止 (T186 施策 A)。
 *
 * panel-navigation.ts と同じく **副作用ごと lib に置く**方針なので、
 * 述語だけでなく購読と class 操作もここで固定する。
 *
 * **legacy MediaQueryList (`addListener`) は対象外**である。撮影 PWA が要求する
 * MediaRecorder の最低版 (iOS Safari 14.5) の方が addEventListener の対応版 (14) より
 * 新しいため、二重の登録経路を持たない (AGENTS.md 思考原則 3)。
 */

/** matchMedia の最小 stub。change ハンドラを手動発火できるようにする。 */
function stubMatchMedia(initial: boolean): {
    setMatches: (next: boolean) => void;
    addEventListener: ReturnType<typeof vi.fn>;
    removeEventListener: ReturnType<typeof vi.fn>;
    queries: string[];
} {
    const handlers = new Set<(event: MediaQueryListEvent) => void>();
    const queries: string[] = [];
    let matches = initial;
    const addEventListener = vi.fn((type: string, handler: (event: MediaQueryListEvent) => void) => {
        if (type === "change") handlers.add(handler);
    });
    const removeEventListener = vi.fn(
        (type: string, handler: (event: MediaQueryListEvent) => void) => {
            if (type === "change") handlers.delete(handler);
        },
    );

    vi.stubGlobal(
        "matchMedia",
        vi.fn((query: string) => {
            queries.push(query);

            return {
                get matches() {
                    return matches;
                },
                media: query,
                addEventListener,
                removeEventListener,
            };
        }),
    );

    return {
        setMatches: (next: boolean) => {
            matches = next;
            for (const handler of handlers) {
                handler({ matches: next } as MediaQueryListEvent);
            }
        },
        addEventListener,
        removeEventListener,
        queries,
    };
}

afterEach(() => {
    vi.unstubAllGlobals();
    document.documentElement.classList.remove("overflow-hidden");
});

describe("LANDSCAPE_CAPTURE_MEDIA_QUERY", () => {
    it.each([
        ["向き", "(orientation: landscape)"],
        ["高さの上限", "(max-height: 540px)"],
        ["粗いポインタ", "(pointer: coarse)"],
    ])("%s の条件を含む (欠けるとデスクトップまで全画面になる)", (_label, condition) => {
        expect(LANDSCAPE_CAPTURE_MEDIA_QUERY).toContain(condition);
    });

    it("3 条件を and で結ぶ (or にすると単独条件で全画面になる)", () => {
        expect(LANDSCAPE_CAPTURE_MEDIA_QUERY).toBe(
            "(orientation: landscape) and (max-height: 540px) and (pointer: coarse)",
        );
    });
});

describe("matchesLandscapeCapture()", () => {
    it("matchMedia 非対応環境では false (= 全画面にしない安全側)", () => {
        vi.stubGlobal("matchMedia", undefined);

        expect(matchesLandscapeCapture()).toBe(false);
    });

    it("対象 query が真なら true", () => {
        const stub = stubMatchMedia(true);

        expect(matchesLandscapeCapture()).toBe(true);
        expect(stub.queries).toContain(LANDSCAPE_CAPTURE_MEDIA_QUERY);
    });

    it("対象 query が偽なら false", () => {
        stubMatchMedia(false);

        expect(matchesLandscapeCapture()).toBe(false);
    });
});

describe("subscribeLandscapeCapture()", () => {
    it("登録直後に現在値で 1 回呼ぶ (change を待つと初期表示が縦持ち扱いになる)", () => {
        stubMatchMedia(true);
        const onChange = vi.fn();

        subscribeLandscapeCapture(onChange);

        expect(onChange).toHaveBeenCalledTimes(1);
        expect(onChange).toHaveBeenCalledWith(true);
    });

    it("change イベントで呼ばれる", () => {
        const stub = stubMatchMedia(false);
        const onChange = vi.fn();

        subscribeLandscapeCapture(onChange);
        stub.setMatches(true);

        expect(onChange).toHaveBeenLastCalledWith(true);
        expect(onChange).toHaveBeenCalledTimes(2);
    });

    it("解除関数で removeEventListener される (以降の change では呼ばれない)", () => {
        const stub = stubMatchMedia(false);
        const onChange = vi.fn();

        const unsubscribe = subscribeLandscapeCapture(onChange);
        unsubscribe();
        stub.setMatches(true);

        expect(stub.removeEventListener).toHaveBeenCalledTimes(1);
        expect(onChange).toHaveBeenCalledTimes(1); // 初期通知のみ
    });

    it("matchMedia 非対応環境では何も呼ばず no-op の解除関数を返す", () => {
        vi.stubGlobal("matchMedia", undefined);
        const onChange = vi.fn();

        const unsubscribe = subscribeLandscapeCapture(onChange);

        expect(onChange).not.toHaveBeenCalled();
        expect(() => unsubscribe()).not.toThrow();
    });
});

describe("resolveSwipe()", () => {
    const VIEWPORT = 800;
    const START_X = 400;
    const START_Y = 200;

    it("左へスワイプ = 次のカット", () => {
        expect(
            resolveSwipe({
                startX: START_X,
                startY: START_Y,
                endX: START_X - SWIPE_MIN_DISTANCE_PX,
                endY: START_Y,
                viewportWidth: VIEWPORT,
            }),
        ).toBe("next");
    });

    it("右へスワイプ = 前のカット", () => {
        expect(
            resolveSwipe({
                startX: START_X,
                startY: START_Y,
                endX: START_X + SWIPE_MIN_DISTANCE_PX,
                endY: START_Y,
                viewportWidth: VIEWPORT,
            }),
        ).toBe("previous");
    });

    it("距離が閾値未満なら none (タップ・指ぶれを弾く)", () => {
        expect(
            resolveSwipe({
                startX: START_X,
                startY: START_Y,
                endX: START_X - (SWIPE_MIN_DISTANCE_PX - 1),
                endY: START_Y,
                viewportWidth: VIEWPORT,
            }),
        ).toBe("none");
    });

    it("縦方向のブレが大きいと none (縦スクロール意図)", () => {
        expect(
            resolveSwipe({
                startX: START_X,
                startY: START_Y,
                endX: START_X - 100,
                endY: START_Y + 100,
                viewportWidth: VIEWPORT,
            }),
        ).toBe("none");
    });

    it("画面左端から始まったスワイプは none (端末の戻るジェスチャへ譲る)", () => {
        expect(
            resolveSwipe({
                startX: SWIPE_EDGE_EXCLUSION_PX - 1,
                startY: START_Y,
                endX: SWIPE_EDGE_EXCLUSION_PX - 1 + 200,
                endY: START_Y,
                viewportWidth: VIEWPORT,
            }),
        ).toBe("none");
    });

    it("画面右端から始まったスワイプは none (端末の進むジェスチャへ譲る)", () => {
        expect(
            resolveSwipe({
                startX: VIEWPORT - SWIPE_EDGE_EXCLUSION_PX + 1,
                startY: START_Y,
                endX: VIEWPORT - SWIPE_EDGE_EXCLUSION_PX + 1 - 200,
                endY: START_Y,
                viewportWidth: VIEWPORT,
            }),
        ).toBe("none");
    });

    it("viewport 幅が除外幅の 2 倍以下 (0 を含む) では常に none = 安全側へ倒れる", () => {
        for (const viewportWidth of [0, SWIPE_EDGE_EXCLUSION_PX, SWIPE_EDGE_EXCLUSION_PX * 2]) {
            expect(
                resolveSwipe({
                    startX: SWIPE_EDGE_EXCLUSION_PX + 1,
                    startY: START_Y,
                    endX: SWIPE_EDGE_EXCLUSION_PX + 1 - 200,
                    endY: START_Y,
                    viewportWidth,
                }),
            ).toBe("none");
        }
    });
});

describe("swipeDirection()", () => {
    it.each([
        ["next", 1],
        ["previous", -1],
    ] as const)("%s は %s へ写像される", (outcome, expected) => {
        expect(swipeDirection(outcome)).toBe(expected);
    });

    it("none は移動しない (null)", () => {
        expect(swipeDirection("none")).toBeNull();
    });
});

describe("decideCutNavigation()", () => {
    const cuts = [{ id: 1 }, { id: 2 }, { id: 3 }];

    it("録画中は常に alert の告知 (端かどうかより先に評価される)", () => {
        // 「末尾で次へ」= 端の告知が出る入力でも、録画中なら録画中の文言が出ることで
        // captureActive が先頭で評価されていることを固定する。
        expect(
            decideCutNavigation({ captureActive: true, cuts, currentCutId: 3, direction: 1 }),
        ).toEqual({
            kind: "notice",
            tone: "alert",
            message: RECORDING_BLOCKS_NAVIGATION_MESSAGE,
        });
    });

    it("通常は次のカットへ移動する", () => {
        expect(
            decideCutNavigation({ captureActive: false, cuts, currentCutId: 2, direction: 1 }),
        ).toEqual({ kind: "move", cutId: 3 });
    });

    it("通常は前のカットへ移動する", () => {
        expect(
            decideCutNavigation({ captureActive: false, cuts, currentCutId: 2, direction: -1 }),
        ).toEqual({ kind: "move", cutId: 1 });
    });

    it("先頭で前へ = 最初のカットである告知 (status)", () => {
        expect(
            decideCutNavigation({ captureActive: false, cuts, currentCutId: 1, direction: -1 }),
        ).toEqual({ kind: "notice", tone: "status", message: CUT_EDGE_MESSAGES.first });
    });

    it("末尾で次へ = 最後のカットである告知 (status)", () => {
        expect(
            decideCutNavigation({ captureActive: false, cuts, currentCutId: 3, direction: 1 }),
        ).toEqual({ kind: "notice", tone: "status", message: CUT_EDGE_MESSAGES.last });
    });

    it.each([
        ["未選択", null, cuts],
        ["不在 id", 999, cuts],
        ["空配列", 1, [] as { id: number }[]],
    ])("%s は ignore (移動も告知もしない)", (_label, currentCutId, input) => {
        expect(
            decideCutNavigation({
                captureActive: false,
                cuts: input,
                currentCutId,
                direction: 1,
            }),
        ).toEqual({ kind: "ignore" });
    });
});

describe("lockBackgroundScroll()", () => {
    it("documentElement に overflow-hidden を付け、解除関数で外す", () => {
        const release = lockBackgroundScroll();

        expect(document.documentElement.classList.contains("overflow-hidden")).toBe(true);

        release();

        expect(document.documentElement.classList.contains("overflow-hidden")).toBe(false);
    });

    it("既に付いていたら付けも外しもしない (他所の抑止を横から解除しない)", () => {
        document.documentElement.classList.add("overflow-hidden");

        const release = lockBackgroundScroll();
        release();

        expect(document.documentElement.classList.contains("overflow-hidden")).toBe(true);
    });
});
