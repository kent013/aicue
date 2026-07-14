import { describe, expect, it } from "vitest";
import {
    boundHistory,
    parseHistorySnapshot,
    pushHistory,
} from "@/lib/manual/scenario-history";
import type { DraftPoint, DraftStep } from "@/types/manual";

/**
 * scenario-history util の純関数テスト。
 * - pushHistory / boundHistory は破壊的 in-place (同一参照)。
 * - parseHistorySnapshot は unknown → type predicate → 検証済み SerializedStep[] | null。
 */

/** 本文 8 フィールド + clientKey/id を備えた DraftPoint を作る */
function makeRow(clientKey: string, overrides: Partial<DraftPoint> = {}): DraftPoint {
    return {
        clientKey,
        id: null,
        scene: "シーン",
        shot_type: "hiki",
        shooting_point: null,
        narration: "ナレーション",
        subtitle_primary: null,
        subtitle_secondary: "字幕",
        material_type: null,
        static_display_seconds: null,
        ...overrides,
    };
}

/** step (row + points) を作る */
function makeStep(clientKey: string, points: DraftPoint[] = []): DraftStep {
    return { ...makeRow(clientKey), points };
}

/** 履歴 1 エントリ (serialize 済み文字列) を作る */
function serialize(steps: DraftStep[]): string {
    return JSON.stringify(steps);
}

describe("pushHistory", () => {
    it("before ≠ current では before を push して true を返す", () => {
        const stack: string[] = [];
        const pushed = pushHistory(stack, "A", "B");

        expect(pushed).toBe(true);
        expect(stack).toEqual(["A"]);
    });

    it("before == current では no-op で false を返す", () => {
        const stack: string[] = ["X"];
        const pushed = pushHistory(stack, "same", "same");

        expect(pushed).toBe(false);
        expect(stack).toEqual(["X"]);
    });

    // redo スタックのクリアは呼び出し側 (ScenarioEditor) の責務であり、util は関知しない。
    // pushHistory は undo スタックへの追加のみを行う。
    it("push は undo スタックのみを対象とし redo には触れない (呼び出し側責務)", () => {
        const undo: string[] = [];
        pushHistory(undo, "A", "B");

        expect(undo).toEqual(["A"]);
    });
});

describe("boundHistory", () => {
    it("件数上限超過で最古から打ち切る", () => {
        const stack = ["1", "2", "3", "4", "5"];
        boundHistory(stack, 3, Number.MAX_SAFE_INTEGER);

        expect(stack).toEqual(["3", "4", "5"]);
    });

    it("総文字数上限超過で最古から打ち切る", () => {
        // 各エントリ 3 文字。maxChars=7 なら合計 <= 7 になるよう最古から捨てる
        const stack = ["aaa", "bbb", "ccc"];
        boundHistory(stack, Number.MAX_SAFE_INTEGER, 7);

        expect(stack).toEqual(["bbb", "ccc"]); // 6 文字 <= 7
    });

    it("件数超過かつ文字数超過の複合ケースでも while 条件が正しく打ち切る", () => {
        const stack = ["aa", "bb", "cc", "dd", "ee"];
        // maxEntries=4 と maxChars=5 の両方を同時に満たすまで最古から捨てる
        boundHistory(stack, 4, 5);

        // 4 件以下 かつ 5 文字以下 → ["cc","dd","ee"] は 3件6文字なので dd/ee=4文字まで削る
        expect(stack).toEqual(["dd", "ee"]); // 2件4文字 <= 5
    });

    it("単一巨大エントリは上限超でも空にしない (ソフト上限)", () => {
        const stack = ["超巨大なエントリ"];
        boundHistory(stack, 100, 1);

        expect(stack).toEqual(["超巨大なエントリ"]);
    });

    it("上限内なら変化なし", () => {
        const stack = ["1", "2"];
        boundHistory(stack, 100, 1000);

        expect(stack).toEqual(["1", "2"]);
    });

    it("同一参照を破壊的に返す (in-place)", () => {
        const stack = ["1", "2", "3"];
        const returned = boundHistory(stack, 2, Number.MAX_SAFE_INTEGER);

        expect(returned).toBe(stack);
    });
});

describe("parseHistorySnapshot", () => {
    it("正常な serialize 文字列を SerializedStep[] に復元する (clientKey/points 保持)", () => {
        const steps = [makeStep("ck-1", [makeRow("ck-2", { id: 21 })])];
        const parsed = parseHistorySnapshot(serialize(steps));

        expect(parsed).not.toBeNull();
        expect(parsed?.[0].clientKey).toBe("ck-1");
        expect(parsed?.[0].points[0].clientKey).toBe("ck-2");
        expect(parsed?.[0].points[0].id).toBe(21);
    });

    it("不正 JSON は null", () => {
        expect(parseHistorySnapshot("{")).toBeNull();
    });

    it("非配列は null", () => {
        expect(parseHistorySnapshot("{}")).toBeNull();
    });

    it("必須フィールド (scene) 欠落は null", () => {
        const broken = JSON.stringify([
            { clientKey: "ck-1", id: null, shot_type: "hiki", points: [] },
        ]);
        expect(parseHistorySnapshot(broken)).toBeNull();
    });

    it("clientKey 欠落は null", () => {
        const step = makeStep("ck-1");
        const raw = JSON.parse(serialize([step])) as Record<string, unknown>[];
        delete raw[0].clientKey;
        expect(parseHistorySnapshot(JSON.stringify(raw))).toBeNull();
    });

    it("points が非配列は null", () => {
        const raw = JSON.parse(serialize([makeStep("ck-1")])) as Record<string, unknown>[];
        raw[0].points = "not-array";
        expect(parseHistorySnapshot(JSON.stringify(raw))).toBeNull();
    });

    it("clientKey が空文字は null", () => {
        expect(parseHistorySnapshot(serialize([makeStep("")]))).toBeNull();
    });

    it("clientKey 重複 (step 同士) は null", () => {
        const steps = [makeStep("dup"), makeStep("dup")];
        expect(parseHistorySnapshot(serialize(steps))).toBeNull();
    });

    it("clientKey 重複 (point 同士) は null", () => {
        const steps = [makeStep("ck-1", [makeRow("dup"), makeRow("dup")])];
        expect(parseHistorySnapshot(serialize(steps))).toBeNull();
    });

    it("clientKey 重複 (step × point) は null", () => {
        const steps = [makeStep("shared", [makeRow("shared")])];
        expect(parseHistorySnapshot(serialize(steps))).toBeNull();
    });
});
