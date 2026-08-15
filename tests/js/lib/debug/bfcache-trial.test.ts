import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import {
    TRIAL_SCHEMA_VERSION,
    TRIAL_STORAGE_KEY,
    DEVICE_MODEL_MAX_LENGTH,
    STORAGE_FAILURE_REASON_MAX_LENGTH,
    VERIFIED_OS_VERSION_MAX_LENGTH,
    appendEvent,
    canAppend,
    deriveGuardVerdict,
    deriveOverallVerdict,
    deriveTrialPhase,
    deriveTrialVerdict,
    expectedGuardVerdict,
    groupByTrialId,
    hasSingleTrialId,
    loadTrials,
    nextSequence,
    parseTrialEvent,
    parseTrialLog,
    probeStorageWritable,
    type GuardState,
    type TrialEvent,
} from "@/lib/debug/bfcache-trial";

/**
 * 観測ライブラリの真理値表テスト (詳細設計 施策 5)。
 *
 * **最終形だけでなく逐次適用も検証する**。listener の追記可否は
 * deriveTrialPhase() の結果で決まるため、正常な遷移 prefix で phase が
 * complete に落ちると実機で観測が途中停止する。最終形のテストだけでは
 * この回帰を検出できない。
 */

const TRIAL = "trial-a";
const TOKEN = "token-a";

let sequence = 0;

function base(trialId = TRIAL): {
    schemaVersion: number;
    trialId: string;
    sequence: number;
    timestamp: string;
} {
    sequence += 1;
    return {
        schemaVersion: TRIAL_SCHEMA_VERSION,
        trialId,
        sequence,
        timestamp: `2026-08-14T00:00:${String(sequence).padStart(2, "0")}.000Z`,
    };
}

function started(trialId = TRIAL, contextToken = TOKEN): TrialEvent {
    return {
        ...base(trialId),
        type: "trial-started",
        scenario: "expired-session",
        contextToken,
        userAgent: "test-agent",
        uaReportedOs: "iOS",
        displayMode: "standalone",
        navigatorStandalone: true,
        deviceModel: "iPhone 15 Pro",
        verifiedOsVersion: "18.2",
    };
}

function away(trialId = TRIAL): TrialEvent {
    return { ...base(trialId), type: "away-navigation-started" };
}

function awayFailed(trialId = TRIAL): TrialEvent {
    return {
        ...base(trialId),
        type: "away-navigation-failed",
        observationMethod: "manual",
    };
}

/**
 * `guardState` は「離脱時点で実際に秘匿されていたか」のスナップショット。
 * 軸 2 の「秘匿維持のまま離脱」判定はここを見るので、既定の null に頼らず
 * リダイレクト離脱のケースでは明示的に "verifying" を渡すこと。
 */
function hide(
    persisted: boolean,
    trialId = TRIAL,
    guardState: GuardState = null,
): TrialEvent {
    return { ...base(trialId), type: "page-hide", persisted, guardState };
}

function show(
    persisted: boolean,
    contextToken = TOKEN,
    trialId = TRIAL,
): TrialEvent {
    return {
        ...base(trialId),
        type: "page-show",
        persisted,
        contextToken,
        displayMode: "standalone",
    };
}

function guard(state: GuardState, trialId = TRIAL): TrialEvent {
    return { ...base(trialId), type: "guard-state-changed", state };
}

function redirect(trialId = TRIAL): TrialEvent {
    return {
        ...base(trialId),
        type: "redirect-observed",
        observationMethod: "manual",
    };
}

function aborted(trialId = TRIAL): TrialEvent {
    return { ...base(trialId), type: "trial-aborted" };
}

beforeEach(() => {
    sequence = 0;
    sessionStorage.clear();
});

// ---------------------------------------------------------------------------

describe("軸 1: 試行成立判定", () => {
    it("#1 started → away → hide(true) → show(true, token 一致) は valid-bfcache", () => {
        expect(
            deriveTrialVerdict([started(), away(), hide(true), show(true)]),
        ).toBe("valid-bfcache");
    });

    it("#2 show(false) かつ token 不一致は invalid-not-bfcache (空振り)", () => {
        expect(
            deriveTrialVerdict([
                started(),
                away(),
                hide(true),
                show(false, "other-token"),
            ]),
        ).toBe("invalid-not-bfcache");
    });

    it("#3 hide(false) と show(true) の不一致は inconsistent", () => {
        expect(
            deriveTrialVerdict([started(), away(), hide(false), show(true)]),
        ).toBe("inconsistent");
    });

    it("#4 show(false) だが token 一致は inconsistent", () => {
        expect(
            deriveTrialVerdict([started(), away(), hide(true), show(false)]),
        ).toBe("inconsistent");
    });

    it("#5 show(true) だが token 不一致は inconsistent", () => {
        expect(
            deriveTrialVerdict([
                started(),
                away(),
                hide(true),
                show(true, "other-token"),
            ]),
        ).toBe("inconsistent");
    });

    it("#6 show が無ければ incomplete", () => {
        expect(deriveTrialVerdict([started(), away(), hide(true)])).toBe(
            "incomplete",
        );
    });

    it("#7 hide 後に aborted は incomplete", () => {
        expect(
            deriveTrialVerdict([started(), away(), hide(true), aborted()]),
        ).toBe("incomplete");
    });

    it("#8 away 後に hide が無いだけでは incomplete (時間差を失敗と見なさない)", () => {
        expect(deriveTrialVerdict([started(), away()])).toBe("incomplete");
    });

    it("#9 away-navigation-failed (手動記録) があれば invalid-wrong-route", () => {
        expect(deriveTrialVerdict([started(), away(), awayFailed()])).toBe(
            "invalid-wrong-route",
        );
    });

    it("#9-b away-navigation-started より前の failed は採用しない (順序を要求する)", () => {
        // 離脱を試していないのに離脱失敗が記録されている列は根拠にしない
        const s = started();
        const f = awayFailed();
        const a = away();
        expect(deriveTrialVerdict([s, f, a])).toBe("incomplete");
    });

    it("#9-c away-navigation-started が無い failed も採用しない", () => {
        expect(deriveTrialVerdict([started(), awayFailed()])).toBe("incomplete");
    });

    it("#10 started のみは incomplete", () => {
        expect(deriveTrialVerdict([started()])).toBe("incomplete");
    });

    it("#11 sequence 逆順 (show が hide より前) は inconsistent", () => {
        const s = started();
        const a = away();
        const sh = show(true);
        const h = hide(true);
        expect(deriveTrialVerdict([s, a, sh, h])).toBe("inconsistent");
    });

    it("#12 guard-state-changed のみは incomplete (invalid-wrong-route にしない)", () => {
        expect(deriveTrialVerdict([started(), guard("pending")])).toBe(
            "incomplete",
        );
    });

    it("#13 複数 trialId の混入は inconsistent", () => {
        expect(
            deriveTrialVerdict([started(), away(), away("trial-b")]),
        ).toBe("inconsistent");
    });

    it("#14 away 欠落 (started → hide → show) は inconsistent", () => {
        expect(deriveTrialVerdict([started(), hide(true), show(true)])).toBe(
            "inconsistent",
        );
    });

    it("#15 窓確定後に show(false, token 不一致) が追記されても valid-bfcache を維持", () => {
        expect(
            deriveTrialVerdict([
                started(),
                away(),
                hide(true),
                show(true),
                show(false, "fresh-token"),
            ]),
        ).toBe("valid-bfcache");
    });

    it("#16 窓確定後に redirect-observed が追記されても valid-bfcache を維持", () => {
        expect(
            deriveTrialVerdict([
                started(),
                away(),
                hide(true),
                show(true),
                redirect(),
            ]),
        ).toBe("valid-bfcache");
    });

    it("#17 窓確定後の復元後 page-hide は軸 1 に影響しない", () => {
        expect(
            deriveTrialVerdict([
                started(),
                away(),
                hide(true),
                show(true),
                guard("pending"),
                guard("verifying"),
                hide(true),
            ]),
        ).toBe("valid-bfcache");
    });
});

// ---------------------------------------------------------------------------

describe("軸 2: guard 結果判定", () => {
    /**
     * 軸 1 window を成立させたうえで、復元後のイベントを足す。
     *
     * **thunk で受ける**のが要点。イベントを値で受けると JS の引数評価順により
     * 復元後イベントの sequence が window の page-show より小さくなり、
     * 軸 2 の境界フィルタで除外されてしまう (テストが意図しない列を検証することになる)。
     */
    function withWindow(...makeAfter: Array<() => TrialEvent>): TrialEvent[] {
        const events: TrialEvent[] = [
            started(),
            away(),
            hide(true),
            show(true),
        ];
        for (const make of makeAfter) events.push(make());
        return events;
    }

    it("#1 pending → verifying → null は authenticated-unhidden", () => {
        expect(
            deriveGuardVerdict(
                withWindow(() => guard("pending"), () => guard("verifying"), () => guard(null)),
            ),
        ).toBe("authenticated-unhidden");
    });

    it("#2 秘匿維持のまま復元後 hide + redirect-observed は unauthenticated-redirected", () => {
        expect(
            deriveGuardVerdict(
                withWindow(() => guard("pending"), () => guard("verifying"), () => hide(true, TRIAL, "verifying"), () => redirect()),
            ),
        ).toBe("unauthenticated-redirected");
    });

    it("#3 同じ列で redirect-observed が無ければ hidden-then-left", () => {
        expect(
            deriveGuardVerdict(
                withWindow(() => guard("pending"), () => guard("verifying"), () => hide(true, TRIAL, "verifying")),
            ),
        ).toBe("hidden-then-left");
    });

    it("#4 pending → verifying → retry は retry-hidden", () => {
        expect(
            deriveGuardVerdict(
                withWindow(() => guard("pending"), () => guard("verifying"), () => guard("retry")),
            ),
        ).toBe("retry-hidden");
    });

    it("#7 verifying を経ずに null は failed-transition (秘匿解除が早すぎる)", () => {
        expect(
            deriveGuardVerdict(withWindow(() => guard("pending"), () => guard(null))),
        ).toBe("failed-transition");
    });

    it("#8 往路 hide のみでは unauthenticated-redirected にしない", () => {
        // 復元後の hide が無い (往路 hide は軸 1 window の内側)
        expect(
            deriveGuardVerdict(
                withWindow(() => guard("pending"), () => guard("verifying"), () => redirect()),
            ),
        ).toBe("in-progress");
    });

    it("#9 軸 2 終端後に fresh load のイベントが追記されても判定が崩れない", () => {
        const events = withWindow(() => guard("pending"), () => guard("verifying"), () => guard(null), () => show(false, "fresh-token"));
        expect(deriveTrialVerdict(events)).toBe("valid-bfcache");
        expect(deriveGuardVerdict(events)).toBe("authenticated-unhidden");
    });

    it("#9-b 終端後に guard-state-changed が追記されても崩れない", () => {
        // 再ログイン後に A を開き直すと fresh load の guard 遷移が積まれる。
        // 終端でフィルタを閉じていないとここで failed-transition に崩れる
        const events = withWindow(
            () => guard("pending"),
            () => guard("verifying"),
            () => guard(null),
            () => show(false, "fresh-token"),
            () => guard("pending"),
            () => guard("verifying"),
            () => guard(null),
        );
        expect(deriveGuardVerdict(events)).toBe("authenticated-unhidden");
        expect(deriveTrialPhase(events)).toBe("complete");
    });

    it("#9-c hidden-then-left の後に guard イベントが追記されても崩れない", () => {
        const events = withWindow(
            () => guard("pending"),
            () => guard("verifying"),
            () => hide(true, TRIAL, "verifying"),
            () => show(false, "fresh-token"),
            () => guard("pending"),
        );
        expect(deriveGuardVerdict(events)).toBe("hidden-then-left");
        expect(deriveTrialPhase(events)).toBe("awaiting-manual-confirmation");
    });

    it("#9-d 上記に redirect-observed を足すと unauthenticated-redirected", () => {
        const events = withWindow(
            () => guard("pending"),
            () => guard("verifying"),
            () => hide(true, TRIAL, "verifying"),
            () => show(false, "fresh-token"),
            () => guard("pending"),
            () => redirect(),
        );
        expect(deriveGuardVerdict(events)).toBe("unauthenticated-redirected");
    });

    it("#3-b page-hide の guardState が null なら hidden-then-left にしない (証跡の矛盾)", () => {
        // 秘匿解除済みで離脱した列は「秘匿維持のまま離脱した」証拠にならない。
        // 合格側にも hidden-then-left にも倒さず failed-transition にする
        expect(
            deriveGuardVerdict(
                withWindow(
                    () => guard("pending"),
                    () => guard("verifying"),
                    () => hide(true, TRIAL, null),
                ),
            ),
        ).toBe("failed-transition");
    });

    it("#3-c guardState=null の離脱に redirect-observed を足しても合格にしない", () => {
        expect(
            deriveGuardVerdict(
                withWindow(
                    () => guard("pending"),
                    () => guard("verifying"),
                    () => hide(true, TRIAL, null),
                    () => redirect(),
                ),
            ),
        ).toBe("failed-transition");
    });

    it("#14 retry 終端後に guard イベントが追記されても崩れない", () => {
        const events = withWindow(
            () => guard("pending"),
            () => guard("verifying"),
            () => guard("retry"),
            () => guard("pending"),
        );
        expect(deriveGuardVerdict(events)).toBe("retry-hidden");
    });

    it("#10 復元直後で guard イベント無しは in-progress", () => {
        expect(deriveGuardVerdict(withWindow())).toBe("in-progress");
    });

    it("#11 pending のみは in-progress (停止をイベント列から判定しない)", () => {
        expect(deriveGuardVerdict(withWindow(() => guard("pending")))).toBe(
            "in-progress",
        );
    });

    it("#12 pending → verifying は in-progress", () => {
        expect(
            deriveGuardVerdict(withWindow(() => guard("pending"), () => guard("verifying"))),
        ).toBe("in-progress");
    });

    it("#13 verifying から始まる列は failed-transition", () => {
        expect(deriveGuardVerdict(withWindow(() => guard("verifying")))).toBe(
            "failed-transition",
        );
    });

    it("#15 guard イベント無しのまま aborted は not-observed", () => {
        expect(deriveGuardVerdict(withWindow(() => aborted()))).toBe("not-observed");
    });

    it("複数 trialId の混入は failed-transition", () => {
        expect(deriveGuardVerdict([started(), guard("pending", "trial-b")])).toBe(
            "failed-transition",
        );
    });
});

// ---------------------------------------------------------------------------

describe("軸 3: 総合判定", () => {
    it("expired-session × valid-bfcache × unauthenticated-redirected は pass", () => {
        expect(
            deriveOverallVerdict(
                "expired-session",
                "valid-bfcache",
                "unauthenticated-redirected",
            ),
        ).toBe("pass");
    });

    it("active-session × valid-bfcache × authenticated-unhidden は pass", () => {
        expect(
            deriveOverallVerdict(
                "active-session",
                "valid-bfcache",
                "authenticated-unhidden",
            ),
        ).toBe("pass");
    });

    it("expired-session で authenticated-unhidden は expectation-mismatch", () => {
        expect(
            deriveOverallVerdict(
                "expired-session",
                "valid-bfcache",
                "authenticated-unhidden",
            ),
        ).toBe("expectation-mismatch");
    });

    it("hidden-then-left は undetermined (redirect-observed 待ち)", () => {
        expect(
            deriveOverallVerdict(
                "expired-session",
                "valid-bfcache",
                "hidden-then-left",
            ),
        ).toBe("undetermined");
    });

    it("in-progress は undetermined (観測途中を fail にしない)", () => {
        expect(
            deriveOverallVerdict(
                "expired-session",
                "valid-bfcache",
                "in-progress",
            ),
        ).toBe("undetermined");
    });

    it("not-observed は undetermined (guard 故障と中止を区別できない)", () => {
        expect(
            deriveOverallVerdict(
                "expired-session",
                "valid-bfcache",
                "not-observed",
            ),
        ).toBe("undetermined");
    });

    it("failed-transition は fail", () => {
        expect(
            deriveOverallVerdict(
                "expired-session",
                "valid-bfcache",
                "failed-transition",
            ),
        ).toBe("fail");
    });

    it("空振り (invalid-not-bfcache) は pass にも fail にもしない", () => {
        expect(
            deriveOverallVerdict(
                "expired-session",
                "invalid-not-bfcache",
                "authenticated-unhidden",
            ),
        ).toBe("undetermined");
    });

    it("incomplete は undetermined", () => {
        expect(
            deriveOverallVerdict("expired-session", "incomplete", "in-progress"),
        ).toBe("undetermined");
    });

    it("expectedGuardVerdict がシナリオごとの期待値を返す", () => {
        expect(expectedGuardVerdict("expired-session")).toBe(
            "unauthenticated-redirected",
        );
        expect(expectedGuardVerdict("active-session")).toBe(
            "authenticated-unhidden",
        );
    });
});

// ---------------------------------------------------------------------------

describe("逐次適用: 各追記直後の verdict と phase", () => {
    it("正常な遷移 prefix で観測が停止しない", () => {
        const events: TrialEvent[] = [started(), away(), hide(true), show(true)];

        // 軸 1 window 確定直後
        expect(deriveGuardVerdict(events)).toBe("in-progress");
        expect(deriveTrialPhase(events)).toBe("collecting-axis2");

        events.push(guard("pending"));
        expect(deriveGuardVerdict(events)).toBe("in-progress");
        expect(deriveTrialPhase(events)).toBe("collecting-axis2");

        events.push(guard("verifying"));
        expect(deriveGuardVerdict(events)).toBe("in-progress");
        expect(deriveTrialPhase(events)).toBe("collecting-axis2");

        events.push(guard(null));
        expect(deriveGuardVerdict(events)).toBe("authenticated-unhidden");
        expect(deriveTrialPhase(events)).toBe("complete");
    });

    it("retry 終端は complete", () => {
        const events: TrialEvent[] = [
            started(),
            away(),
            hide(true),
            show(true),
            guard("pending"),
            guard("verifying"),
            guard("retry"),
        ];
        expect(deriveGuardVerdict(events)).toBe("retry-hidden");
        expect(deriveTrialPhase(events)).toBe("complete");
    });

    it("復元後 hide は awaiting-manual-confirmation、redirect 追記で complete", () => {
        const events: TrialEvent[] = [
            started(),
            away(),
            hide(true),
            show(true),
            guard("pending"),
            guard("verifying"),
            // 秘匿を維持したまま離脱した (guard が location.replace('/login') を呼んだ時点)
            hide(true, TRIAL, "verifying"),
        ];
        expect(deriveGuardVerdict(events)).toBe("hidden-then-left");
        expect(deriveTrialPhase(events)).toBe("awaiting-manual-confirmation");

        events.push(redirect());
        expect(deriveGuardVerdict(events)).toBe("unauthenticated-redirected");
        expect(deriveTrialPhase(events)).toBe("complete");
    });
});

// ---------------------------------------------------------------------------

describe("deriveTrialPhase の状態機械", () => {
    it("軸 1 未終端は collecting-axis1", () => {
        expect(deriveTrialPhase([started(), away()])).toBe("collecting-axis1");
    });

    it("軸 1 が invalid-not-bfcache で終端すると complete", () => {
        expect(
            deriveTrialPhase([
                started(),
                away(),
                hide(true),
                show(false, "other-token"),
            ]),
        ).toBe("complete");
    });

    it("trial-aborted は他の終端イベントと併存しても aborted が優先", () => {
        expect(
            deriveTrialPhase([
                started(),
                away(),
                hide(true),
                show(true),
                guard("pending"),
                guard("verifying"),
                guard(null),
                aborted(),
            ]),
        ).toBe("aborted");
    });

    it("複数 trialId の混入は invalid", () => {
        expect(deriveTrialPhase([started(), away("trial-b")])).toBe("invalid");
    });

    it("awaiting-manual-confirmation では自動イベントを追記できない", () => {
        expect(canAppend("awaiting-manual-confirmation", "page-show")).toBe(
            false,
        );
        expect(canAppend("awaiting-manual-confirmation", "guard-state-changed")).toBe(
            false,
        );
        expect(canAppend("awaiting-manual-confirmation", "redirect-observed")).toBe(
            true,
        );
        expect(canAppend("awaiting-manual-confirmation", "trial-aborted")).toBe(
            true,
        );
    });

    it("complete / aborted / invalid では一切追記できない", () => {
        for (const phase of ["complete", "aborted", "invalid"] as const) {
            expect(canAppend(phase, "page-show")).toBe(false);
            expect(canAppend(phase, "redirect-observed")).toBe(false);
        }
    });

    it("collecting-axis1 では離脱失敗の手動記録を許可する", () => {
        expect(canAppend("collecting-axis1", "away-navigation-failed")).toBe(
            true,
        );
    });
});

// ---------------------------------------------------------------------------

describe("validator の負のコントロール", () => {
    it("schemaVersion 不一致は破棄", () => {
        const event = { ...started(), schemaVersion: 99 };
        expect(parseTrialEvent(event)).toBeNull();
    });

    it("未知の type は破棄", () => {
        const event = { ...started(), type: "unknown-type" };
        expect(parseTrialEvent(event)).toBeNull();
    });

    it("許可外の余分なキーを持つイベントは破棄", () => {
        const event = { ...started(), extraKey: "x" };
        expect(parseTrialEvent(event)).toBeNull();
    });

    it("必須キーの欠落は破棄", () => {
        const event: Record<string, unknown> = { ...started() };
        delete event.contextToken;
        expect(parseTrialEvent(event)).toBeNull();
    });

    it("deviceModel が最大長超過なら破棄", () => {
        const event = {
            ...started(),
            deviceModel: "a".repeat(DEVICE_MODEL_MAX_LENGTH + 1),
        };
        expect(parseTrialEvent(event)).toBeNull();
    });

    it("deviceModel に許可外文字があれば破棄", () => {
        expect(parseTrialEvent({ ...started(), deviceModel: "山田太郎" })).toBeNull();
        expect(parseTrialEvent({ ...started(), deviceModel: "a@b.com" })).toBeNull();
    });

    it("verifiedOsVersion が最大長超過なら破棄", () => {
        const event = {
            ...started(),
            verifiedOsVersion: "1".repeat(VERIFIED_OS_VERSION_MAX_LENGTH + 1),
        };
        expect(parseTrialEvent(event)).toBeNull();
    });

    it("verifiedOsVersion に許可外文字があれば破棄", () => {
        expect(
            parseTrialEvent({ ...started(), verifiedOsVersion: "18.2 (実機)" }),
        ).toBeNull();
        expect(
            parseTrialEvent({ ...started(), verifiedOsVersion: "user@example.com" }),
        ).toBeNull();
    });

    it("prototype 由来のキーを type にしても例外化せず破棄する", () => {
        // `value in ALLOWED_KEYS` だと "toString" が真になり後段で例外化する
        for (const poisoned of ["toString", "constructor", "hasOwnProperty"]) {
            expect(() =>
                parseTrialEvent({ ...started(), type: poisoned }),
            ).not.toThrow();
            expect(parseTrialEvent({ ...started(), type: poisoned })).toBeNull();
        }
    });

    it("storage-failed の reason が最大長超過なら破棄", () => {
        const event = {
            ...base(),
            type: "storage-failed",
            reason: "x".repeat(STORAGE_FAILURE_REASON_MAX_LENGTH + 1),
        };
        expect(parseTrialEvent(event)).toBeNull();
    });

    it("observationMethod が manual 以外なら破棄", () => {
        const event = { ...redirect(), observationMethod: "auto" };
        expect(parseTrialEvent(event)).toBeNull();
    });

    it("JSON として壊れていれば null", () => {
        expect(parseTrialLog("{not json")).toBeNull();
    });

    it("配列でなければ null", () => {
        expect(parseTrialLog('{"a":1}')).toBeNull();
    });

    it("1 件だけ壊れていてもログ全体を破棄する (部分採用しない)", () => {
        const raw = JSON.stringify([started(), { broken: true }, away()]);
        expect(parseTrialLog(raw)).toBeNull();
    });

    it("正常なログはイベント数どおりパースされる", () => {
        const events = [started(), away(), hide(true)];
        const parsed = parseTrialLog(JSON.stringify(events));
        expect(parsed).not.toBeNull();
        expect(parsed).toHaveLength(3);
    });

    it("null / 空文字は null", () => {
        expect(parseTrialLog(null)).toBeNull();
        expect(parseTrialLog("")).toBeNull();
    });
});

// ---------------------------------------------------------------------------

describe("採番と trial 分離", () => {
    it("空配列では 1 を返す", () => {
        expect(nextSequence([], TRIAL)).toBe(1);
    });

    it("復元した進行中 trial に対して max+1 を返す", () => {
        const events = [started(), away(), hide(true)];
        expect(nextSequence(events, TRIAL)).toBe(4);
    });

    it("欠番・重複があっても max+1 を返す", () => {
        const events: TrialEvent[] = [
            { ...started(), sequence: 1 },
            { ...away(), sequence: 7 },
            { ...hide(true), sequence: 7 },
        ];
        expect(nextSequence(events, TRIAL)).toBe(8);
    });

    it("他 trial の sequence を混ぜない", () => {
        const events: TrialEvent[] = [
            { ...started(), sequence: 1 },
            { ...started("trial-b"), sequence: 99 },
        ];
        expect(nextSequence(events, TRIAL)).toBe(2);
    });

    it("hasSingleTrialId が単一で true、混入で false", () => {
        expect(hasSingleTrialId([started(), away()])).toBe(true);
        expect(hasSingleTrialId([started(), away("trial-b")])).toBe(false);
        expect(hasSingleTrialId([])).toBe(true);
    });

    it("groupByTrialId が trialId ごとに分離する", () => {
        const grouped = groupByTrialId([
            started(),
            away(),
            started("trial-b"),
        ]);
        expect(grouped.size).toBe(2);
        expect(grouped.get(TRIAL)).toHaveLength(2);
        expect(grouped.get("trial-b")).toHaveLength(1);
    });
});

// ---------------------------------------------------------------------------

describe("storage", () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it("probeStorageWritable が書き込み可能環境で true", () => {
        expect(probeStorageWritable()).toBe(true);
    });

    it("probeStorageWritable が setItem 例外環境で false", () => {
        vi.spyOn(Storage.prototype, "setItem").mockImplementation(() => {
            throw new Error("QuotaExceededError");
        });
        expect(probeStorageWritable()).toBe(false);
    });

    it("appendEvent が例外を伝播せず false を返す", () => {
        vi.spyOn(Storage.prototype, "setItem").mockImplementation(() => {
            throw new Error("QuotaExceededError");
        });
        expect(() => appendEvent(started())).not.toThrow();
        expect(appendEvent(started())).toBe(false);
    });

    it("appendEvent の read-back が書き戻し内容の不一致を検出する", () => {
        // setItem は成功するが保存内容が別物になる環境を模す
        vi.spyOn(Storage.prototype, "setItem").mockImplementation(() => {
            // 何も保存しない (getItem は null のまま)
        });
        expect(appendEvent(started())).toBe(false);
    });

    it("appendEvent が追記し、loadTrials が trialId ごとに返す", () => {
        expect(appendEvent(started())).toBe(true);
        expect(appendEvent(away())).toBe(true);
        expect(appendEvent(started("trial-b"))).toBe(true);

        const trials = loadTrials();
        expect(trials.size).toBe(2);
        expect(trials.get(TRIAL)).toHaveLength(2);
        expect(trials.get("trial-b")).toHaveLength(1);
    });

    it("保存済みログが壊れていれば loadTrials は空を返す", () => {
        sessionStorage.setItem(TRIAL_STORAGE_KEY, "{broken");
        expect(loadTrials().size).toBe(0);
    });
});

// ---------------------------------------------------------------------------

/*
 * T178: guard に「秘匿を維持したまま読み直す」状態 (reloading) が増えた。
 * 検証ページはこれを軸 2 の終端候補 stale-session-reloaded として扱う。
 * **新終端は単独では PASS にならない** (目視確認の記録が要る)。
 */
describe("軸 2: 読み直しに倒れた終端 (reloading)", () => {
    /** 軸 1 window を成立させたうえで、復元後のイベントを足す (thunk で sequence を保つ)。 */
    function afterRestore(...makeAfter: Array<() => TrialEvent>): TrialEvent[] {
        const events: TrialEvent[] = [started(), away(), hide(true), show(true)];
        for (const make of makeAfter) events.push(make());
        return events;
    }

    it("pending → reloading は stale-session-reloaded (同期判定で読み直した)", () => {
        expect(
            deriveGuardVerdict(afterRestore(() => guard("pending"), () => guard("reloading"))),
        ).toBe("stale-session-reloaded");
    });

    it("同じ列に redirect-observed が付くと unauthenticated-redirected", () => {
        expect(
            deriveGuardVerdict(
                afterRestore(
                    () => guard("pending"),
                    () => guard("reloading"),
                    () => redirect(),
                ),
            ),
        ).toBe("unauthenticated-redirected");
    });

    it("pending → verifying → reloading でも同じ終端 (プローブ経由の読み直し)", () => {
        expect(
            deriveGuardVerdict(
                afterRestore(
                    () => guard("pending"),
                    () => guard("verifying"),
                    () => guard("reloading"),
                ),
            ),
        ).toBe("stale-session-reloaded");
    });

    it("page-hide の guardState が reloading なら単独でも同じ終端 (取りこぼし時の裏取り)", () => {
        expect(
            deriveGuardVerdict(afterRestore(() => hide(true, TRIAL, "reloading"))),
        ).toBe("stale-session-reloaded");
    });

    it("reloading から始まる列 (先頭が pending でない) は failed-transition", () => {
        expect(deriveGuardVerdict(afterRestore(() => guard("reloading")))).toBe(
            "failed-transition",
        );
        expect(
            deriveGuardVerdict(afterRestore(() => guard("verifying"), () => guard("reloading"))),
        ).toBe("failed-transition");
    });

    it("読み直し終端の後に guard イベントが追記されても崩れない", () => {
        const events = afterRestore(
            () => guard("pending"),
            () => guard("reloading"),
            // 読み直した先の fresh load で観測される列
            () => guard("pending"),
            () => guard("verifying"),
            () => guard(null),
        );
        expect(deriveGuardVerdict(events)).toBe("stale-session-reloaded");
    });

    it("総合判定は undetermined、phase は awaiting-manual-confirmation (自動追記が止まる)", () => {
        const events = afterRestore(() => guard("pending"), () => guard("reloading"));

        expect(
            deriveOverallVerdict("expired-session", "valid-bfcache", "stale-session-reloaded"),
        ).toBe("undetermined");
        expect(deriveTrialPhase(events)).toBe("awaiting-manual-confirmation");
        expect(canAppend("awaiting-manual-confirmation", "guard-state-changed")).toBe(false);
        expect(canAppend("awaiting-manual-confirmation", "redirect-observed")).toBe(true);
    });

    it("合格終端は unauthenticated-redirected のまま (T085 の完了条件は変わらない)", () => {
        expect(expectedGuardVerdict("expired-session")).toBe("unauthenticated-redirected");
    });

    it("有効セッション経路 (pending → verifying → null) の判定は変わらない", () => {
        expect(
            deriveGuardVerdict(
                afterRestore(
                    () => guard("pending"),
                    () => guard("verifying"),
                    () => guard(null),
                ),
            ),
        ).toBe("authenticated-unhidden");
    });

    it("schemaVersion 1 の旧記録は読み捨てられる (状態語彙が違うため)", () => {
        expect(TRIAL_SCHEMA_VERSION).toBe(2);
        expect(parseTrialEvent({ ...started(), schemaVersion: 1 })).toBeNull();
        expect(
            parseTrialLog(JSON.stringify([{ ...started(), schemaVersion: 1 }])),
        ).toBeNull();
    });

    it("reloading は guard-state-changed / page-hide の許可値である", () => {
        expect(parseTrialEvent(guard("reloading"))).not.toBeNull();
        expect(parseTrialEvent(hide(true, TRIAL, "reloading"))).not.toBeNull();
    });
});
