/**
 * Tests for resources/js/lib/bfcache-guard.ts
 *
 * 公開契約 (詳細設計 施策 6 の状態遷移表):
 *   1. pagehide            → documentElement に秘匿属性を同期付与 (この DOM ごと bfcache に入る)
 *   2. pageshow (属性あり) → 秘匿のまま軽量プローブ (/session/status)
 *   3. セッション有効       → 秘匿属性を外すだけ (DOM / フォーム / Inertia 履歴は温存)
 *   4. セッション無効       → login へ hard navigation
 *   5. プローブ失敗         → 秘匿維持 + 再試行ボタン表示 (自動再試行しない)
 *   6. 再試行押下           → 現在 URL を hard reload
 *
 * 復元マーカーは documentElement の秘匿属性そのもの (sessionStorage は使わない:
 * タブ単位共有で別ページに漏れるため)。
 *
 * 負のコントロール: 「秘匿ロジックを外す (guard 未登録 / dispose 済み) と pagehide 後に
 * 秘匿属性が付かない」。vitest では実描画の露出は検証できないため属性の有無で責務を閉じる
 * (実描画は Browser E2E の責務)。
 */
import { beforeEach, describe, expect, it, vi } from "vitest";

import {
    BFCACHE_HIDDEN_ATTRIBUTE,
    BFCACHE_OVERLAY_ID,
    BFCACHE_RETRY_BUTTON_ID,
    LOGIN_PATH,
    SESSION_STATUS_PATH,
    registerBfcacheGuard,
    type GuardWindow,
    type ProbeFetch,
    type ProbeResponseLike,
} from "@/lib/bfcache-guard";

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
        const { win, dispatch } = createWindowStub();
        const fetchImpl = vi.fn<ProbeFetch>();
        registerBfcacheGuard({ win, fetchImpl, isAuthenticated: () => false });

        dispatch(transitionEvent("pagehide", true));
        dispatch(transitionEvent("pageshow", true));
        await flushProbe();

        expect(hiddenAttribute()).toBeNull();
        expect(fetchImpl).not.toHaveBeenCalled();
    });
});

describe("pageshow の復元マーカー判定", () => {
    it("秘匿属性が無ければ (通常ロード) プローブしない", async () => {
        const { win, dispatch } = createWindowStub();
        const fetchImpl = vi.fn<ProbeFetch>();
        registerBfcacheGuard({ win, fetchImpl, isAuthenticated: () => true });

        dispatch(transitionEvent("pageshow", true));
        await flushProbe();

        expect(fetchImpl).not.toHaveBeenCalled();
    });

    it("秘匿属性があれば persisted の値に依らずプローブする (属性が唯一のマーカー)", async () => {
        const { win, dispatch } = createWindowStub();
        const fetchImpl = vi.fn<ProbeFetch>(() =>
            Promise.resolve(probeResponse({ authenticated: true })),
        );
        registerBfcacheGuard({ win, fetchImpl, isAuthenticated: () => true });

        dispatch(transitionEvent("pagehide", true));
        dispatch(transitionEvent("pageshow", false));
        await flushProbe();

        expect(fetchImpl).toHaveBeenCalledTimes(1);
    });

    it("プローブは same-origin / no-store / Accept: application/json で叩く", async () => {
        const { win, dispatch } = createWindowStub();
        const fetchImpl = vi.fn<ProbeFetch>(() =>
            Promise.resolve(probeResponse({ authenticated: true })),
        );
        registerBfcacheGuard({ win, fetchImpl, isAuthenticated: () => true });

        dispatch(transitionEvent("pagehide", true));
        dispatch(transitionEvent("pageshow", true));
        await flushProbe();

        expect(fetchImpl).toHaveBeenCalledWith(SESSION_STATUS_PATH, {
            credentials: "same-origin",
            cache: "no-store",
            headers: { Accept: "application/json" },
        });
    });
});

describe("プローブ結果ごとの遷移", () => {
    /** 秘匿状態から 1 回プローブを走らせる。 */
    async function restoreWith(response: () => Promise<ProbeResponseLike>): Promise<{
        fetchImpl: ReturnType<typeof vi.fn>;
        replace: ReturnType<typeof vi.fn>;
        reload: ReturnType<typeof vi.fn>;
    }> {
        const { win, dispatch, replace, reload } = createWindowStub();
        const fetchImpl = vi.fn<ProbeFetch>(response);
        registerBfcacheGuard({ win, fetchImpl, isAuthenticated: () => true });

        dispatch(transitionEvent("pagehide", true));
        dispatch(transitionEvent("pageshow", true));
        await flushProbe();

        return { fetchImpl, replace, reload };
    }

    it("authenticated:true なら秘匿を外すだけ (遷移も reload もしない)", async () => {
        const { replace, reload } = await restoreWith(() =>
            Promise.resolve(probeResponse({ authenticated: true })),
        );

        expect(hiddenAttribute()).toBeNull();
        expect(replace).not.toHaveBeenCalled();
        expect(reload).not.toHaveBeenCalled();
    });

    it("authenticated:false なら秘匿のまま login へ hard navigation する", async () => {
        const { replace } = await restoreWith(() =>
            Promise.resolve(probeResponse({ authenticated: false })),
        );

        expect(replace).toHaveBeenCalledWith(LOGIN_PATH);
        expect(hiddenAttribute()).not.toBeNull();
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
            Promise.resolve(probeResponse({ authenticated: false }, { ok: false })),
        );

        expect(hiddenAttribute()).not.toBeNull();
        expect(replace).not.toHaveBeenCalled();
    });

    it("Content-Type が JSON でなければ秘匿維持", async () => {
        const { replace } = await restoreWith(() =>
            Promise.resolve(
                probeResponse({ authenticated: false }, { contentType: "text/html; charset=utf-8" }),
            ),
        );

        expect(hiddenAttribute()).not.toBeNull();
        expect(replace).not.toHaveBeenCalled();
    });

    it("Content-Type の charset パラメータは許容する", async () => {
        const { replace } = await restoreWith(() =>
            Promise.resolve(
                probeResponse({ authenticated: false }, { contentType: "application/json; charset=UTF-8" }),
            ),
        );

        expect(replace).toHaveBeenCalledWith(LOGIN_PATH);
    });

    it("shape 不一致 (authenticated が boolean でない) は秘匿維持", async () => {
        const { replace } = await restoreWith(() =>
            Promise.resolve(probeResponse({ authenticated: "false" })),
        );

        expect(hiddenAttribute()).not.toBeNull();
        expect(replace).not.toHaveBeenCalled();
    });

    it("data ラップ (top-level でない) は秘匿維持", async () => {
        const { replace } = await restoreWith(() =>
            Promise.resolve(probeResponse({ data: { authenticated: true } })),
        );

        expect(hiddenAttribute()).not.toBeNull();
        expect(replace).not.toHaveBeenCalled();
    });
});

describe("再試行 UI", () => {
    it("再試行押下で現在 URL を hard reload する", async () => {
        const { win, dispatch, reload } = createWindowStub();
        const fetchImpl = vi.fn<ProbeFetch>(() => Promise.reject(new Error("network down")));
        registerBfcacheGuard({ win, fetchImpl, isAuthenticated: () => true });

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
