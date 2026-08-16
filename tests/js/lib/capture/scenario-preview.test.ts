/**
 * Tests for resources/js/lib/capture/scenario-preview.ts (T191)
 *
 * 固定する契約:
 * - 再生リストは「サーバが決めた adopted_ready_take_id」だけを見る (述語を持たない)
 * - 表示中かつ再生要求中なら**有限時間で必ず次へ進む** (停滞監視による回収)
 * - 一時停止 / 非表示 / blocked / failed の間は勝手に進まない
 * - 世代が一致しない非同期結果は 1 ビットも状態を変えない
 */
import { describe, expect, it } from "vitest";

import {
    buildPreviewEntries,
    initialPreviewState,
    missingCount,
    PREVIEW_STALL_TIMEOUT_MS,
    reducePreview,
    shouldWatchStall,
    type PreviewEntry,
    type PreviewEvent,
    type PreviewOptions,
    type PreviewState,
} from "@/lib/capture/scenario-preview";
import { takeUrl } from "@/lib/capture/take-endpoints";
import type { CaptureCut } from "@/types/capture";

const TARGET = { projectId: 1, manualId: 5 };

function cut(id: number, readyTakeId: number | null, type: "step" | "point" = "step"): CaptureCut {
    return {
        id,
        type,
        parent_cut_id: null,
        scene: `scene-${id}`,
        shot_type: "hiki",
        shooting_point: null,
        narration: "",
        subtitle_primary: null,
        subtitle_secondary: `字幕 ${id}`,
        adopted_take_id: readyTakeId,
        adopted_ready_take_id: readyTakeId,
        takes: [],
    };
}

function clipEntry(cutId: number, takeId: number): PreviewEntry {
    return {
        kind: "clip",
        cutId,
        takeId,
        label: `手順 ${cutId}`,
        subtitlePrimary: null,
        subtitleSecondary: "",
        src: takeUrl({ ...TARGET, cutId }, takeId, "/playback"),
    };
}

function missingEntry(cutId: number): PreviewEntry {
    return {
        kind: "missing",
        cutId,
        label: `手順 ${cutId}`,
        subtitlePrimary: null,
        subtitleSecondary: "",
    };
}

function options(entries: PreviewEntry[], overrides: Partial<PreviewOptions> = {}): PreviewOptions {
    return { entries, placeholderSeconds: 3, stallTimeoutMs: 1_000, ...overrides };
}

/** 連続適用のヘルパ (状態遷移の可読性のため) */
function apply(state: PreviewState, opts: PreviewOptions, events: PreviewEvent[]): PreviewState {
    return events.reduce((current, event) => reducePreview(current, event, opts), state);
}

describe("buildPreviewEntries", () => {
    it("adopted_ready_take_id が非 null なら clip、null なら missing になる", () => {
        const entries = buildPreviewEntries(
            [cut(101, 900), cut(102, null)],
            { 101: "手順 1", 102: "急所 1-1" },
            TARGET,
        );

        expect(entries[0]).toMatchObject({ kind: "clip", cutId: 101, takeId: 900, label: "手順 1" });
        expect(entries[1]).toMatchObject({ kind: "missing", cutId: 102, label: "急所 1-1" });
    });

    it("clip の src は takeUrl の /playback と一致する (URL 規則を再実装しない)", () => {
        const entries = buildPreviewEntries([cut(101, 900)], { 101: "手順 1" }, TARGET);

        expect(entries[0]).toHaveProperty(
            "src",
            takeUrl({ projectId: 1, manualId: 5, cutId: 101 }, 900, "/playback"),
        );
        expect(entries[0]).toHaveProperty("src", "/app/projects/1/manuals/5/cuts/101/takes/900/playback");
    });

    it("cuts の順序をそのまま保つ (手順 → 急所の並びを崩さない)", () => {
        const entries = buildPreviewEntries(
            [cut(1, 11), cut(2, null, "point"), cut(3, 33)],
            { 1: "手順 1", 2: "急所 1-1", 3: "手順 2" },
            TARGET,
        );

        expect(entries.map((entry) => entry.cutId)).toEqual([1, 2, 3]);
    });

    it("ラベルが無いカットは既定ラベルになる (buildCutLabels の結果をそのまま使う)", () => {
        const entries = buildPreviewEntries([cut(1, 11)], {}, TARGET);

        expect(entries[0]?.label).toBe("カット");
    });

    it("字幕は cut の値をそのまま運ぶ", () => {
        const entries = buildPreviewEntries([cut(7, 70)], { 7: "手順 1" }, TARGET);

        expect(entries[0]?.subtitleSecondary).toBe("字幕 7");
        expect(entries[0]?.subtitlePrimary).toBeNull();
    });
});

describe("missingCount", () => {
    it("使用できる採用テイクが無いカットの件数を数える", () => {
        expect(missingCount([clipEntry(1, 11), missingEntry(2), missingEntry(3)])).toBe(2);
        expect(missingCount([clipEntry(1, 11)])).toBe(0);
        expect(missingCount([])).toBe(0);
    });
});

describe("initialPreviewState", () => {
    it("先頭が clip なら loading、missing なら placeholder から始まる", () => {
        expect(initialPreviewState(options([clipEntry(1, 11)]), 0).clip).toBe("loading");
        expect(initialPreviewState(options([missingEntry(1)]), 0).clip).toBe("placeholder");
    });

    it("entries が空なら finished で始まる", () => {
        expect(initialPreviewState(options([]), 0).finished).toBe(true);
    });
});

describe("reducePreview: 停滞監視", () => {
    it("loading のまま閾値を超える tick で failed になり、さらに tick で次へ進む", () => {
        const opts = options([clipEntry(1, 11), clipEntry(2, 22)]);
        const start = initialPreviewState(opts, 0);

        const stalled = reducePreview(start, { type: "tick", at: 1_000 }, opts);
        expect(stalled.clip).toBe("failed");
        expect(stalled.index).toBe(0);

        // failed の表示は placeholderSeconds だけ見せてから次へ進む (有限時間で必ず前進する)
        const advanced = reducePreview(stalled, { type: "tick", at: 1_000 + 3_000 }, opts);
        expect(advanced.index).toBe(1);
        expect(advanced.clip).toBe("loading");
        expect(advanced.generation).toBe(1);
    });

    it("failed 中の progress / playing は待ちを延ばさない・復帰させない (Codex R1-Critical)", () => {
        const opts = options([clipEntry(1, 11), clipEntry(2, 22)]);
        const failed = reducePreview(initialPreviewState(opts, 0), { type: "tick", at: 1_000 }, opts);
        expect(failed.clip).toBe("failed");

        // 失敗したクリップの要素はバッファリングを続けて progress を出し続けうる。
        // 受け付けると progressAt が更新され続けて尺の満了判定が永久に成立しない。
        const stillFailed = apply(failed, opts, [
            { type: "progress", at: 2_000 },
            { type: "playing", at: 3_000 },
            { type: "progress", at: 3_900 },
        ]);
        expect(stillFailed).toEqual(failed);

        // 失敗表示の尺が満了したら必ず次へ進む
        expect(reducePreview(stillFailed, { type: "tick", at: 4_000 }, opts).index).toBe(1);
    });

    it("placeholder 中のメディア由来イベントも待ちを延ばさない", () => {
        const opts = options([missingEntry(1), clipEntry(2, 22)]);
        const start = initialPreviewState(opts, 0);

        const untouched = apply(start, opts, [
            { type: "progress", at: 1_000 },
            { type: "ended", at: 2_000 },
            { type: "error", at: 2_500 },
        ]);
        expect(untouched).toEqual(start);
        expect(reducePreview(untouched, { type: "tick", at: 3_000 }, opts).index).toBe(1);
    });

    it("既定の停滞閾値は PREVIEW_STALL_TIMEOUT_MS である", () => {
        const opts = options([clipEntry(1, 11)], { stallTimeoutMs: undefined });
        const start = initialPreviewState(opts, 0);

        expect(reducePreview(start, { type: "tick", at: PREVIEW_STALL_TIMEOUT_MS - 1 }, opts).clip).toBe(
            "loading",
        );
        expect(reducePreview(start, { type: "tick", at: PREVIEW_STALL_TIMEOUT_MS }, opts).clip).toBe(
            "failed",
        );
    });

    it("progress が来ている間は停滞にならない", () => {
        const opts = options([clipEntry(1, 11)]);
        const start = initialPreviewState(opts, 0);

        const state = apply(start, opts, [
            { type: "playing", at: 100 },
            { type: "progress", at: 900 },
            { type: "tick", at: 1_500 },
        ]);

        expect(state.clip).toBe("playing");
    });

    it("paused 中は tick をいくら送っても failed にならない", () => {
        const opts = options([clipEntry(1, 11), clipEntry(2, 22)]);
        const start = initialPreviewState(opts, 0);

        const state = apply(start, opts, [
            { type: "playing", at: 10 },
            { type: "paused", at: 20 },
            { type: "tick", at: 5_000 },
            { type: "tick", at: 50_000 },
        ]);

        expect(state.clip).toBe("paused");
        expect(state.index).toBe(0);
    });

    it("shouldWatchStall は表示中かつ loading/playing のときだけ真", () => {
        const opts = options([clipEntry(1, 11)]);
        const base = initialPreviewState(opts, 0);

        expect(shouldWatchStall(base)).toBe(true);
        expect(shouldWatchStall({ ...base, clip: "playing" })).toBe(true);
        expect(shouldWatchStall({ ...base, clip: "paused" })).toBe(false);
        expect(shouldWatchStall({ ...base, clip: "blocked" })).toBe(false);
        expect(shouldWatchStall({ ...base, clip: "failed" })).toBe(false);
        expect(shouldWatchStall({ ...base, visible: false })).toBe(false);
        expect(shouldWatchStall({ ...base, finished: true })).toBe(false);
    });
});

describe("reducePreview: 一時停止と再開", () => {
    it("loading 中の paused を受け付け、以後 tick で failed にならない", () => {
        const opts = options([clipEntry(1, 11)]);
        const start = initialPreviewState(opts, 0);

        const paused = reducePreview(start, { type: "paused", at: 100 }, opts);
        expect(paused.clip).toBe("paused");
        expect(reducePreview(paused, { type: "tick", at: 90_000 }, opts).clip).toBe("paused");
    });

    it("resumed は loading へ戻し progressAt を引き直す (停止していた時間を停滞に数えない)", () => {
        const opts = options([clipEntry(1, 11)]);
        const start = initialPreviewState(opts, 0);

        const resumed = apply(start, opts, [
            { type: "paused", at: 100 },
            { type: "resumed", at: 60_000 },
        ]);
        expect(resumed.clip).toBe("loading");
        expect(resumed.progressAt).toBe(60_000);

        expect(reducePreview(resumed, { type: "playing", at: 60_100 }, opts).clip).toBe("playing");
    });

    it("paused でない状態の resumed は状態を変えない", () => {
        const opts = options([clipEntry(1, 11)]);
        const start = initialPreviewState(opts, 0);

        expect(reducePreview(start, { type: "resumed", at: 10 }, opts)).toEqual(start);
    });
});

describe("reducePreview: 可視性", () => {
    it("hidden 中は tick で進まず、shown で progressAt が引き直される", () => {
        const opts = options([clipEntry(1, 11), clipEntry(2, 22)]);
        const start = initialPreviewState(opts, 0);

        const hidden = apply(start, opts, [
            { type: "hidden", at: 10 },
            { type: "tick", at: 100_000 },
        ]);
        expect(hidden.clip).toBe("loading");
        expect(hidden.index).toBe(0);

        const shown = reducePreview(hidden, { type: "shown", at: 100_100 }, opts);
        expect(shown.visible).toBe(true);
        expect(shown.progressAt).toBe(100_100);
    });

    it("paused → hidden → shown で再生状態が変わらない", () => {
        const opts = options([clipEntry(1, 11)]);
        const start = initialPreviewState(opts, 0);

        const state = apply(start, opts, [
            { type: "playing", at: 10 },
            { type: "paused", at: 20 },
            { type: "hidden", at: 30 },
            { type: "shown", at: 40 },
        ]);

        expect(state.clip).toBe("paused");
    });

    it("非表示中はメディア由来イベントを受け付けない (ended / error / playing / paused)", () => {
        const opts = options([clipEntry(1, 11), clipEntry(2, 22)]);
        const hidden = reducePreview(initialPreviewState(opts, 0), { type: "hidden", at: 10 }, opts);

        for (const type of ["ended", "error", "playing", "paused"] as const) {
            const next = reducePreview(hidden, { type, at: 20 }, opts);
            expect(next).toEqual(hidden);
        }
    });

    it("非表示中でも利用者操作 (skip) は効く", () => {
        const opts = options([clipEntry(1, 11), clipEntry(2, 22)]);
        const hidden = reducePreview(initialPreviewState(opts, 0), { type: "hidden", at: 10 }, opts);

        const skipped = reducePreview(hidden, { type: "skip", at: 20 }, opts);
        expect(skipped.index).toBe(1);
        expect(skipped.generation).toBe(1);
    });
});

describe("reducePreview: 世代", () => {
    it("advance 後に古い世代の error / blocked を送っても状態が変わらない", () => {
        const opts = options([clipEntry(1, 11), clipEntry(2, 22)]);
        const advanced = reducePreview(initialPreviewState(opts, 0), { type: "ended", at: 10 }, opts);

        expect(advanced.generation).toBe(1);
        expect(reducePreview(advanced, { type: "error", generation: 0, at: 20 }, opts)).toEqual(advanced);
        expect(reducePreview(advanced, { type: "blocked", generation: 0, at: 20 }, opts)).toEqual(
            advanced,
        );
    });

    it("現在世代のイベントは受理される", () => {
        const opts = options([clipEntry(1, 11), clipEntry(2, 22)]);
        const advanced = reducePreview(initialPreviewState(opts, 0), { type: "ended", at: 10 }, opts);

        expect(reducePreview(advanced, { type: "error", generation: 1, at: 20 }, opts).clip).toBe(
            "failed",
        );
    });
});

describe("reducePreview: blocked (自動再生制限)", () => {
    it("blocked → retry → blocked を繰り返しても failed にならない", () => {
        const opts = options([clipEntry(1, 11), clipEntry(2, 22)]);
        const start = initialPreviewState(opts, 0);

        const state = apply(start, opts, [
            { type: "blocked", at: 10 },
            { type: "tick", at: 90_000 },
            { type: "retry", at: 90_100 },
            { type: "blocked", at: 90_200 },
            { type: "tick", at: 180_000 },
        ]);

        expect(state.clip).toBe("blocked");
        expect(state.index).toBe(0);
    });

    it("blocked から skip で次へ進む (出口がある)", () => {
        const opts = options([clipEntry(1, 11), clipEntry(2, 22)]);
        const blocked = reducePreview(initialPreviewState(opts, 0), { type: "blocked", at: 10 }, opts);

        const skipped = reducePreview(blocked, { type: "skip", at: 20 }, opts);
        expect(skipped.index).toBe(1);
        expect(skipped.clip).toBe("loading");
    });
});

describe("reducePreview: プレースホルダ", () => {
    it("placeholder は placeholderSeconds 経過の tick で次へ進む", () => {
        const opts = options([missingEntry(1), clipEntry(2, 22)]);
        const start = initialPreviewState(opts, 0);

        expect(reducePreview(start, { type: "tick", at: 2_999 }, opts).index).toBe(0);
        const advanced = reducePreview(start, { type: "tick", at: 3_000 }, opts);
        expect(advanced.index).toBe(1);
        expect(advanced.clip).toBe("loading");
    });

    it("missing が連続しても順に進み最後は finished になる", () => {
        const opts = options([missingEntry(1), missingEntry(2)]);
        const start = initialPreviewState(opts, 0);

        const second = reducePreview(start, { type: "tick", at: 3_000 }, opts);
        expect(second.clip).toBe("placeholder");
        const finished = reducePreview(second, { type: "tick", at: 6_000 }, opts);
        expect(finished.finished).toBe(true);
    });
});

describe("reducePreview: 終端と空リスト", () => {
    it("最後の entry の ended で finished になり、以後どのイベントでも状態が変わらない", () => {
        const opts = options([clipEntry(1, 11)]);
        const finished = reducePreview(initialPreviewState(opts, 0), { type: "ended", at: 10 }, opts);

        expect(finished.finished).toBe(true);
        for (const type of ["tick", "skip", "retry", "error", "playing", "shown"] as const) {
            expect(reducePreview(finished, { type, at: 20 }, opts)).toEqual(finished);
        }
    });

    it("entries が 0 件ならどのイベントを送っても状態が変わらない (clip の値に依存しない)", () => {
        const opts = options([]);
        const start = initialPreviewState(opts, 0);

        for (const type of ["tick", "skip", "ended", "error", "hidden", "shown"] as const) {
            expect(reducePreview(start, { type, at: 10 }, opts)).toEqual(start);
        }
    });
});
