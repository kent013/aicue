/**
 * Tests for resources/js/lib/bfcache-guard.ts
 *
 * 公開契約 (T178 で同期判定を前置した後の状態遷移表):
 *   1. pagehide                     → documentElement に秘匿属性を同期付与 (この DOM ごと bfcache に入る)
 *   2. pageshow (属性あり)          → まず同期判定 (通信を待たない)
 *   3. 同期判定が不一致・不明        → 秘匿のまま読み直し (プローブを呼ばない)
 *   4. 認証済み + 世代が一致        → 秘匿属性を外すだけ (DOM / フォーム / Inertia 履歴は温存)
 *   5. 認証済み + 世代が不一致      → 秘匿のまま読み直し (/login へは倒さない)
 *   6. セッション無効                → login へ hard navigation
 *   7. プローブ失敗                  → 秘匿維持 + 再試行ボタン表示 (自動再試行しない)
 *   8. 再試行押下                    → 現在 URL を hard reload
 *
 * 復元マーカーは documentElement の秘匿属性そのもの (sessionStorage は使わない:
 * タブ単位共有で別ページに漏れるため)。
 *
 * 負のコントロール 2 本:
 *   - 「秘匿ロジックを外す (guard 未登録 / dispose 済み) と pagehide 後に秘匿属性が付かない」
 *   - 「同期判定が一致しても、プローブを通さずに秘匿が解けることは無い」(開示の唯一の根拠)
 * vitest では実描画の露出は検証できないため属性の有無で責務を閉じる
 * (実描画は Browser E2E の責務)。
 */
import { beforeEach, describe, expect, it, vi } from "vitest";

import {
    BFCACHE_HIDDEN_ATTRIBUTE,
    BFCACHE_OVERLAY_ID,
    BFCACHE_RETRY_BUTTON_ID,
    BFCACHE_STATE_RELOADING,
    LOGIN_PATH,
    SESSION_EPOCH_HEADER,
    SESSION_STATUS_PATH,
    decideBySyncEpoch,
    probeSessionStatus,
    readSessionEpochCookie,
    registerBfcacheGuard,
    type GuardWindow,
    type ProbeFetch,
    type ProbeResponseLike,
} from "@/lib/bfcache-guard";

/** 試験で使う印 (32 文字の 16 進)。 */
const EPOCH = "0123456789abcdef0123456789abcdef";
const OTHER_EPOCH = "fedcba9876543210fedcba9876543210";

/** location を呼び出し記録可能にした最小 window スタブ (jsdom は実 navigation を持たない)。 */
function createWindowStub(): {
    win: GuardWindow;
    dispatch(event: Event): boolean;
    replace: ReturnType<typeof vi.fn>;
    reload: ReturnType<typeof vi.fn>;
} {
    const target = new EventTarget();
    const replace = vi.fn();
    const reload = vi.fn();

    return {
        win: {
            addEventListener: (type, listener) => target.addEventListener(type, listener),
            removeEventListener: (type, listener) =>
                target.removeEventListener(type, listener),
            location: { replace, reload },
        },
        dispatch: (event) => target.dispatchEvent(event),
        replace,
        reload,
    };
}

/** PageTransitionEvent 相当。persisted を省略すると「取得できない環境」を模す。 */
function transitionEvent(type: "pagehide" | "pageshow", persisted?: boolean): Event {
    const event = new Event(type);
    if (persisted !== undefined) {
        Object.defineProperty(event, "persisted", { value: persisted });
    }
    return event;
}

/** プローブ応答スタブ。 */
function probeResponse(
    body: unknown,
    { ok = true, contentType = "application/json" }: { ok?: boolean; contentType?: string | null } = {},
): ProbeResponseLike {
    return {
        ok,
        headers: { get: (name) => (name.toLowerCase() === "content-type" ? contentType : null) },
        json: () => Promise.resolve(body),
    };
}

/** 世代が一致している既定の配線 (同期判定を通過させる)。 */
function matchingEpochDeps(): {
    readRenderedEpoch: () => string | null;
    readCurrentEpoch: () => string | null;
} {
    return {
        readRenderedEpoch: () => EPOCH,
        readCurrentEpoch: () => EPOCH,
    };
}

function hiddenAttribute(): string | null {
    return document.documentElement.getAttribute(BFCACHE_HIDDEN_ATTRIBUTE);
}

/** 非同期プローブ (fetch → json) の解決を待つ。 */
async function flushProbe(): Promise<void> {
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
}

beforeEach(() => {
    document.documentElement.removeAttribute(BFCACHE_HIDDEN_ATTRIBUTE);
    document.body.innerHTML = "";
});

describe("負のコントロール (秘匿ロジックが無いとき)", () => {
    it("guard を登録していなければ pagehide で秘匿属性は付かない", () => {
        const { dispatch } = createWindowStub();

        dispatch(transitionEvent("pagehide", true));

        expect(hiddenAttribute()).toBeNull();
    });

    it("dispose 後は pagehide で秘匿属性が付かない", () => {
        const { win, dispatch } = createWindowStub();
        const dispose = registerBfcacheGuard({ win, isAuthenticated: () => true });

        dispose();
        dispatch(transitionEvent("pagehide", true));

        expect(hiddenAttribute()).toBeNull();
    });

    it("同期判定が一致してもプローブ抜きに秘匿は解けない (開示の唯一の根拠はプローブ)", async () => {
        const { win, dispatch } = createWindowStub();
        // 応答を返さない fetch = プローブが決着していない状態
        const fetchImpl = vi.fn<ProbeFetch>(() => new Promise<ProbeResponseLike>(() => {}));
        registerBfcacheGuard({
            win,
            fetchImpl,
            isAuthenticated: () => true,
            ...matchingEpochDeps(),
        });

        dispatch(transitionEvent("pagehide", true));
        dispatch(transitionEvent("pageshow", true));
        await flushProbe();

        expect(fetchImpl).toHaveBeenCalledTimes(1);
        expect(hiddenAttribute()).not.toBeNull();
    });
});

describe("decideBySyncEpoch (通信を待たない前置判定)", () => {
    it("一致なら undecided (= プローブへ進む。開示ではない)", () => {
        expect(decideBySyncEpoch(EPOCH, EPOCH)).toBe("undecided");
    });

    it("不一致・描画世代なし・現世代なしはすべて must-reload", () => {
        expect(decideBySyncEpoch(EPOCH, OTHER_EPOCH)).toBe("must-reload");
        expect(decideBySyncEpoch(null, EPOCH)).toBe("must-reload");
        expect(decideBySyncEpoch(EPOCH, null)).toBe("must-reload");
        expect(decideBySyncEpoch(null, null)).toBe("must-reload");
    });
});

describe("readSessionEpochCookie", () => {
    it("他 cookie と混在していても読める (前後の空白を許容)", () => {
        expect(readSessionEpochCookie(`foo=bar; session_epoch=${EPOCH}; baz=qux`)).toBe(EPOCH);
        expect(readSessionEpochCookie(`  session_epoch = ${EPOCH} `)).toBe(EPOCH);
    });

    it("URL エンコードされていても復号して読む", () => {
        expect(readSessionEpochCookie(`session_epoch=${encodeURIComponent(EPOCH)}`)).toBe(EPOCH);
    });

    it("不在・書式違いは null", () => {
        expect(readSessionEpochCookie("foo=bar")).toBeNull();
        expect(readSessionEpochCookie("session_epoch=")).toBeNull();
        expect(readSessionEpochCookie("session_epoch=NOT-HEX")).toBeNull();
        expect(readSessionEpochCookie(`session_epoch=${EPOCH}0`)).toBeNull();
        expect(readSessionEpochCookie(`session_epoch=${EPOCH.toUpperCase()}`)).toBeNull();
    });

    it("壊れた百分率エンコードでも例外を投げず null を返す", () => {
        expect(() => readSessionEpochCookie("session_epoch=%E0%A4%A")).not.toThrow();
        expect(readSessionEpochCookie("session_epoch=%E0%A4%A")).toBeNull();
    });
});

describe("probeSessionStatus (描画世代の運び方)", () => {
    it("描画世代が null のときはヘッダを付けない (空文字を送らない)", async () => {
        const fetchImpl = vi.fn<ProbeFetch>(() =>
            Promise.resolve(probeResponse({ authenticated: true, sessionEpochMatches: false })),
        );

        await probeSessionStatus(fetchImpl, null);

        expect(fetchImpl).toHaveBeenCalledWith(SESSION_STATUS_PATH, {
            credentials: "same-origin",
            cache: "no-store",
            headers: { Accept: "application/json" },
        });
    });

    it("応答の 2 つの boolean から 3 つの結論を作る", async () => {
        const outcomeFor = (body: unknown): Promise<string> =>
            probeSessionStatus(
                vi.fn<ProbeFetch>(() => Promise.resolve(probeResponse(body))),
                EPOCH,
            );

        expect(await outcomeFor({ authenticated: true, sessionEpochMatches: true })).toBe(
            "authenticated",
        );
        expect(await outcomeFor({ authenticated: true, sessionEpochMatches: false })).toBe("stale");
        expect(await outcomeFor({ authenticated: false, sessionEpochMatches: true })).toBe(
            "unauthenticated",
        );
        expect(await outcomeFor({ authenticated: true })).toBe("failed");
    });
});

describe("pagehide の秘匿判定", () => {
    it("persisted=true (bfcache 対象) では秘匿属性を付ける", () => {
        const { win, dispatch } = createWindowStub();
        registerBfcacheGuard({ win, isAuthenticated: () => true });

        dispatch(transitionEvent("pagehide", true));

        expect(hiddenAttribute()).not.toBeNull();
    });

    it("persisted=false (通常遷移) では秘匿しない (ちらつき回避)", () => {
        const { win, dispatch } = createWindowStub();
        registerBfcacheGuard({ win, isAuthenticated: () => true });

        dispatch(transitionEvent("pagehide", false));

        expect(hiddenAttribute()).toBeNull();
    });

    it("persisted が取れない環境では安全側 (秘匿する) へ倒す", () => {
        const { win, dispatch } = createWindowStub();
        registerBfcacheGuard({ win, isAuthenticated: () => true });

        dispatch(transitionEvent("pagehide"));

        expect(hiddenAttribute()).not.toBeNull();
    });

    it("未認証ページ (auth.user なし) では秘匿もプローブもしない", async () => {
        const { win, dispatch, reload } = createWindowStub();
        const fetchImpl = vi.fn<ProbeFetch>();
        registerBfcacheGuard({ win, fetchImpl, isAuthenticated: () => false });

        dispatch(transitionEvent("pagehide", true));
        dispatch(transitionEvent("pageshow", true));
        await flushProbe();

        expect(hiddenAttribute()).toBeNull();
        expect(fetchImpl).not.toHaveBeenCalled();
        expect(reload).not.toHaveBeenCalled();
    });
});

describe("pageshow の復元マーカー判定", () => {
    it("秘匿属性が無ければ (通常ロード) 何もしない", async () => {
        const { win, dispatch, reload } = createWindowStub();
        const fetchImpl = vi.fn<ProbeFetch>();
        registerBfcacheGuard({
            win,
            fetchImpl,
            isAuthenticated: () => true,
            ...matchingEpochDeps(),
        });

        dispatch(transitionEvent("pageshow", true));
        await flushProbe();

        expect(fetchImpl).not.toHaveBeenCalled();
        expect(reload).not.toHaveBeenCalled();
    });

    it("読み直し後の文書 (秘匿属性なし) では pageshow で何も起きない (ループしない)", async () => {
        const { win, dispatch, reload } = createWindowStub();
        const fetchImpl = vi.fn<ProbeFetch>();
        registerBfcacheGuard({
            win,
            fetchImpl,
            isAuthenticated: () => true,
            readRenderedEpoch: () => EPOCH,
            readCurrentEpoch: () => OTHER_EPOCH,
        });

        // 読み直した先の文書はサーバから来た新しい HTML なので秘匿属性を持たない
        dispatch(transitionEvent("pageshow", true));
        await flushProbe();

        expect(reload).not.toHaveBeenCalled();
        expect(fetchImpl).not.toHaveBeenCalled();
    });

    it("秘匿属性があれば persisted の値に依らずプローブする (属性が唯一のマーカー)", async () => {
        const { win, dispatch } = createWindowStub();
        const fetchImpl = vi.fn<ProbeFetch>(() =>
            Promise.resolve(probeResponse({ authenticated: true, sessionEpochMatches: true })),
        );
        registerBfcacheGuard({
            win,
            fetchImpl,
            isAuthenticated: () => true,
            ...matchingEpochDeps(),
        });

        dispatch(transitionEvent("pagehide", true));
        dispatch(transitionEvent("pageshow", false));
        await flushProbe();

        expect(fetchImpl).toHaveBeenCalledTimes(1);
    });

    it("プローブは same-origin / no-store / Accept + 描画世代ヘッダで叩く", async () => {
        const { win, dispatch } = createWindowStub();
        const fetchImpl = vi.fn<ProbeFetch>(() =>
            Promise.resolve(probeResponse({ authenticated: true, sessionEpochMatches: true })),
        );
        registerBfcacheGuard({
            win,
            fetchImpl,
            isAuthenticated: () => true,
            ...matchingEpochDeps(),
        });

        dispatch(transitionEvent("pagehide", true));
        dispatch(transitionEvent("pageshow", true));
        await flushProbe();

        expect(fetchImpl).toHaveBeenCalledWith(SESSION_STATUS_PATH, {
            credentials: "same-origin",
            cache: "no-store",
            headers: { Accept: "application/json", [SESSION_EPOCH_HEADER]: EPOCH },
        });
    });
});

describe("同期判定の前置 (通信を待たない)", () => {
    /** 秘匿状態から pageshow を 1 回起こす。 */
    function restoreWithEpochs(
        rendered: string | null,
        current: string | null,
    ): { fetchImpl: ReturnType<typeof vi.fn>; reload: ReturnType<typeof vi.fn> } {
        const { win, dispatch, reload } = createWindowStub();
        const fetchImpl = vi.fn<ProbeFetch>();
        registerBfcacheGuard({
            win,
            fetchImpl,
            isAuthenticated: () => true,
            readRenderedEpoch: () => rendered,
            readCurrentEpoch: () => current,
        });

        dispatch(transitionEvent("pagehide", true));
        dispatch(transitionEvent("pageshow", true));

        return { fetchImpl, reload };
    }

    it("世代が不一致ならプローブを 1 度も呼ばずに秘匿のまま読み直す", () => {
        const { fetchImpl, reload } = restoreWithEpochs(EPOCH, OTHER_EPOCH);

        expect(fetchImpl).not.toHaveBeenCalled();
        expect(reload).toHaveBeenCalledTimes(1);
        expect(hiddenAttribute()).toBe(BFCACHE_STATE_RELOADING);
    });

    it("描画世代が読めないときも読み直す (安全側)", () => {
        const { fetchImpl, reload } = restoreWithEpochs(null, EPOCH);

        expect(fetchImpl).not.toHaveBeenCalled();
        expect(reload).toHaveBeenCalledTimes(1);
        expect(hiddenAttribute()).toBe(BFCACHE_STATE_RELOADING);
    });

    it("世代 cookie が読めないときも読み直す (開示側へは倒れない)", () => {
        const { fetchImpl, reload } = restoreWithEpochs(EPOCH, null);

        expect(fetchImpl).not.toHaveBeenCalled();
        expect(reload).toHaveBeenCalledTimes(1);
        expect(hiddenAttribute()).toBe(BFCACHE_STATE_RELOADING);
    });

    it("描画世代の既定は null = 配線を忘れると読み直しに倒れる (素通ししない)", () => {
        const { win, dispatch, reload } = createWindowStub();
        const fetchImpl = vi.fn<ProbeFetch>();
        registerBfcacheGuard({
            win,
            fetchImpl,
            isAuthenticated: () => true,
            readCurrentEpoch: () => EPOCH,
        });

        dispatch(transitionEvent("pagehide", true));
        dispatch(transitionEvent("pageshow", true));

        expect(fetchImpl).not.toHaveBeenCalled();
        expect(reload).toHaveBeenCalledTimes(1);
    });
});

describe("プローブ結果ごとの遷移", () => {
    /** 秘匿状態から 1 回プローブを走らせる (同期判定は一致させて通す)。 */
    async function restoreWith(
        response: () => Promise<ProbeResponseLike>,
        renderedEpoch: string | null = EPOCH,
    ): Promise<{
        fetchImpl: ReturnType<typeof vi.fn>;
        replace: ReturnType<typeof vi.fn>;
        reload: ReturnType<typeof vi.fn>;
    }> {
        const { win, dispatch, replace, reload } = createWindowStub();
        const fetchImpl = vi.fn<ProbeFetch>(response);
        registerBfcacheGuard({
            win,
            fetchImpl,
            isAuthenticated: () => true,
            readRenderedEpoch: () => renderedEpoch,
            readCurrentEpoch: () => renderedEpoch,
        });

        dispatch(transitionEvent("pagehide", true));
        dispatch(transitionEvent("pageshow", true));
        await flushProbe();

        return { fetchImpl, replace, reload };
    }

    it("認証済み + 世代一致なら秘匿を外すだけ (遷移も reload もしない)", async () => {
        const { replace, reload } = await restoreWith(() =>
            Promise.resolve(probeResponse({ authenticated: true, sessionEpochMatches: true })),
        );

        expect(hiddenAttribute()).toBeNull();
        expect(replace).not.toHaveBeenCalled();
        expect(reload).not.toHaveBeenCalled();
    });

    it("認証済みだが世代が不一致なら秘匿のまま読み直す (/login へ倒さない)", async () => {
        const { replace, reload } = await restoreWith(() =>
            Promise.resolve(probeResponse({ authenticated: true, sessionEpochMatches: false })),
        );

        expect(hiddenAttribute()).toBe(BFCACHE_STATE_RELOADING);
        expect(reload).toHaveBeenCalledTimes(1);
        expect(replace).not.toHaveBeenCalled();
    });

    it("authenticated:false なら秘匿のまま login へ hard navigation する", async () => {
        const { replace } = await restoreWith(() =>
            Promise.resolve(probeResponse({ authenticated: false, sessionEpochMatches: false })),
        );

        expect(replace).toHaveBeenCalledWith(LOGIN_PATH);
        expect(hiddenAttribute()).not.toBeNull();
    });

    it("cookie を偽の値へ書き換えても開示に至らない (プローブが最後の関門)", async () => {
        // 同期判定は client 側の値だけで通せるが、サーバが世代不一致と答えれば読み直しになる
        const { win, dispatch, reload } = createWindowStub();
        const fetchImpl = vi.fn<ProbeFetch>(() =>
            Promise.resolve(probeResponse({ authenticated: true, sessionEpochMatches: false })),
        );
        registerBfcacheGuard({
            win,
            fetchImpl,
            isAuthenticated: () => true,
            readRenderedEpoch: () => EPOCH,
            // 攻撃者が cookie を描画世代と同じ値へ書き換えた状況
            readCurrentEpoch: () => EPOCH,
        });

        dispatch(transitionEvent("pagehide", true));
        dispatch(transitionEvent("pageshow", true));
        await flushProbe();

        expect(hiddenAttribute()).toBe(BFCACHE_STATE_RELOADING);
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it("旧 shape (sessionEpochMatches 欠落) は秘匿維持 + 再試行 (契約ずれが開示に倒れない)", async () => {
        const { replace, reload } = await restoreWith(() =>
            Promise.resolve(probeResponse({ authenticated: true })),
        );

        expect(hiddenAttribute()).not.toBeNull();
        expect(hiddenAttribute()).not.toBe(BFCACHE_STATE_RELOADING);
        expect(replace).not.toHaveBeenCalled();
        expect(reload).not.toHaveBeenCalled();
    });

    it("fetch が reject したら秘匿維持 + 再試行 (自動再試行はしない)", async () => {
        const { fetchImpl, replace } = await restoreWith(() =>
            Promise.reject(new Error("network down")),
        );

        expect(hiddenAttribute()).not.toBeNull();
        expect(fetchImpl).toHaveBeenCalledTimes(1);
        expect(replace).not.toHaveBeenCalled();
        expect(
            document.getElementById(BFCACHE_RETRY_BUTTON_ID)?.isConnected,
        ).toBe(true);
    });

    it("HTTP エラー応答 (ok=false) は秘匿維持 (login へ倒さない)", async () => {
        const { replace } = await restoreWith(() =>
            Promise.resolve(
                probeResponse({ authenticated: false, sessionEpochMatches: false }, { ok: false }),
            ),
        );

        expect(hiddenAttribute()).not.toBeNull();
        expect(replace).not.toHaveBeenCalled();
    });

    it("Content-Type が JSON でなければ秘匿維持", async () => {
        const { replace } = await restoreWith(() =>
            Promise.resolve(
                probeResponse(
                    { authenticated: false, sessionEpochMatches: false },
                    { contentType: "text/html; charset=utf-8" },
                ),
            ),
        );

        expect(hiddenAttribute()).not.toBeNull();
        expect(replace).not.toHaveBeenCalled();
    });

    it("Content-Type の charset パラメータは許容する", async () => {
        const { replace } = await restoreWith(() =>
            Promise.resolve(
                probeResponse(
                    { authenticated: false, sessionEpochMatches: false },
                    { contentType: "application/json; charset=UTF-8" },
                ),
            ),
        );

        expect(replace).toHaveBeenCalledWith(LOGIN_PATH);
    });

    it("shape 不一致 (authenticated が boolean でない) は秘匿維持", async () => {
        const { replace } = await restoreWith(() =>
            Promise.resolve(probeResponse({ authenticated: "false", sessionEpochMatches: false })),
        );

        expect(hiddenAttribute()).not.toBeNull();
        expect(replace).not.toHaveBeenCalled();
    });

    it("data ラップ (top-level でない) は秘匿維持", async () => {
        const { replace } = await restoreWith(() =>
            Promise.resolve(
                probeResponse({ data: { authenticated: true, sessionEpochMatches: true } }),
            ),
        );

        expect(hiddenAttribute()).not.toBeNull();
        expect(replace).not.toHaveBeenCalled();
    });
});

describe("再試行 UI", () => {
    it("再試行押下で現在 URL を hard reload する", async () => {
        const { win, dispatch, reload } = createWindowStub();
        const fetchImpl = vi.fn<ProbeFetch>(() => Promise.reject(new Error("network down")));
        registerBfcacheGuard({
            win,
            fetchImpl,
            isAuthenticated: () => true,
            ...matchingEpochDeps(),
        });

        dispatch(transitionEvent("pagehide", true));
        dispatch(transitionEvent("pageshow", true));
        await flushProbe();

        document.getElementById(BFCACHE_RETRY_BUTTON_ID)?.click();

        expect(reload).toHaveBeenCalledTimes(1);
        // 押下しても自動でプローブし直さない (reload に一本化)
        expect(fetchImpl).toHaveBeenCalledTimes(1);
    });

    it("オーバーレイは 1 つだけ生成される (二重登録しても増えない)", () => {
        const first = createWindowStub();
        const second = createWindowStub();
        registerBfcacheGuard({ win: first.win, isAuthenticated: () => true });
        registerBfcacheGuard({ win: second.win, isAuthenticated: () => true });

        expect(document.querySelectorAll(`#${BFCACHE_OVERLAY_ID}`)).toHaveLength(1);
    });
});
