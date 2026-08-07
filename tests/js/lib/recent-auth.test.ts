import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

/*
 * recent-auth クライアントヘルパの契約 (施策 3 / 4)。
 *
 * 施策 3: `/recent-auth/status` は **strict parse**。既定値で補完しない。
 *   field が欠けた応答を既定値で埋めると「サーバは手段があると言っているのに UI に出ない」
 *   = 監査 F-1 と同じ詰みが通信境界で再演する (call-site gate では検出できない)。
 *   契約不成立は null にし、delegated (サーバの最終ゲートへ委譲) に倒す。
 *
 * 施策 4: 409 `recent_auth_required` を confirm 画面への Inertia visit に変換する単一ハンドラ。
 *   購読するのは @inertiajs/core 3.x の `httpException` (v1/v2 の `invalid` の後継)。
 *   受入条件を満たさない応答は preventDefault せず Inertia 既定処理に渡す (fail-closed)。
 */

const { routerOnMock, routerVisitMock } = vi.hoisted(() => ({
    routerOnMock: vi.fn(),
    routerVisitMock: vi.fn(),
}));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: { on: routerOnMock, visit: routerVisitMock, post: vi.fn() },
}));

const { addToastMock } = vi.hoisted(() => ({ addToastMock: vi.fn() }));

vi.mock("@/lib/stores/toast", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@/lib/stores/toast")>()),
    addToast: addToastMock,
}));

import {
    fetchRecentAuthStatus,
    isRecentAuthRequiredPayload,
    parseRecentAuthStatus,
    registerRecentAuthRedirectHandler,
    withRecentAuth,
} from "@/lib/recent-auth";

const VALID_BODY = {
    recent: false,
    passwordSet: true,
    availableProviders: [
        { provider: "google", capability: "reauth", reauthUrl: "/auth/google/redirect/step-up" },
    ],
    passkeyAvailable: true,
    canSatisfy: true,
    confirmedAt: null,
};

/** fetch を 1 応答でスタブする */
function stubFetch(body: unknown, ok = true, status = 200): void {
    vi.stubGlobal(
        "fetch",
        vi.fn(() => Promise.resolve({ ok, status, json: () => Promise.resolve(body) })),
    );
}

beforeEach(() => {
    routerOnMock.mockReset();
    routerVisitMock.mockReset();
    addToastMock.mockReset();
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe("parseRecentAuthStatus (strict parse)", () => {
    it("完全な応答は各 field が写る", () => {
        expect(parseRecentAuthStatus(VALID_BODY)).toEqual(VALID_BODY);
    });

    it("未知キーが増えても壊れない (サーバの field 追加に耐える)", () => {
        expect(parseRecentAuthStatus({ ...VALID_BODY, futureField: 1 })).toEqual(VALID_BODY);
    });

    it("confirmedAt が number でも通る", () => {
        const body = { ...VALID_BODY, recent: true, confirmedAt: 1700000000 };
        expect(parseRecentAuthStatus(body)?.confirmedAt).toBe(1700000000);
    });

    it.each([
        ["recent", undefined],
        ["recent", "yes"],
        ["passwordSet", undefined],
        ["passwordSet", 1],
        ["passkeyAvailable", undefined],
        ["passkeyAvailable", "true"],
        ["canSatisfy", undefined],
        ["canSatisfy", null],
        ["confirmedAt", "1700000000"],
        ["availableProviders", undefined],
        ["availableProviders", {}],
    ])("top-level %s の欠損・型不一致は null (既定値で補完しない)", (key, value) => {
        const body: Record<string, unknown> = { ...VALID_BODY };
        if (value === undefined) {
            delete body[key];
        } else {
            body[key] = value;
        }
        expect(parseRecentAuthStatus(body)).toBeNull();
    });

    it.each(["provider", "capability", "reauthUrl"])(
        "provider 要素の %s 欠損は null (SSO ボタンが消える詰みを防ぐ)",
        (key) => {
            const element: Record<string, unknown> = { ...VALID_BODY.availableProviders[0] };
            delete element[key];
            expect(
                parseRecentAuthStatus({ ...VALID_BODY, availableProviders: [element] }),
            ).toBeNull();
        },
    );

    it("provider 要素が非オブジェクトなら null", () => {
        expect(
            parseRecentAuthStatus({ ...VALID_BODY, availableProviders: ["google"] }),
        ).toBeNull();
    });

    it("body が非オブジェクト / null なら null", () => {
        expect(parseRecentAuthStatus(null)).toBeNull();
        expect(parseRecentAuthStatus("recent")).toBeNull();
    });
});

describe("fetchRecentAuthStatus", () => {
    it("200 + 契約充足なら status を返す", async () => {
        stubFetch(VALID_BODY);
        await expect(fetchRecentAuthStatus()).resolves.toEqual(VALID_BODY);
    });

    it("res.ok=false なら null", async () => {
        stubFetch(VALID_BODY, false, 500);
        await expect(fetchRecentAuthStatus()).resolves.toBeNull();
    });

    it("JSON パース失敗なら null", async () => {
        vi.stubGlobal(
            "fetch",
            vi.fn(() =>
                Promise.resolve({ ok: true, status: 200, json: () => Promise.reject(new Error()) }),
            ),
        );
        await expect(fetchRecentAuthStatus()).resolves.toBeNull();
    });

    it("契約不成立 (field 欠損) なら null", async () => {
        const { passkeyAvailable: _drop, ...partial } = VALID_BODY;
        stubFetch(partial);
        await expect(fetchRecentAuthStatus()).resolves.toBeNull();
    });
});

describe("withRecentAuth", () => {
    it("契約不成立なら delegated を返し onStale を呼ばない", async () => {
        const { canSatisfy: _drop, ...partial } = VALID_BODY;
        stubFetch(partial);
        const onFresh = vi.fn();
        const onStale = vi.fn();

        await expect(withRecentAuth({ onFresh, onStale })).resolves.toBe("delegated");
        expect(onStale).not.toHaveBeenCalled();
        expect(onFresh).toHaveBeenCalledTimes(1);
    });

    it("recent=false なら stale で onStale に status を渡す", async () => {
        stubFetch(VALID_BODY);
        const onStale = vi.fn();

        await expect(withRecentAuth({ onFresh: vi.fn(), onStale })).resolves.toBe("stale");
        expect(onStale).toHaveBeenCalledWith(VALID_BODY);
    });

    it("recent=true なら fresh で onFresh を呼ぶ", async () => {
        stubFetch({ ...VALID_BODY, recent: true, confirmedAt: 1 });
        const onFresh = vi.fn();

        await expect(withRecentAuth({ onFresh, onStale: vi.fn() })).resolves.toBe("fresh");
        expect(onFresh).toHaveBeenCalledTimes(1);
    });
});

describe("registerRecentAuthRedirectHandler (409 の単一ハンドラ)", () => {
    /** router.on に登録された handler を取り出して疑似イベントを流す */
    function dispatch(response: unknown): { prevented: boolean } {
        let handler: ((event: unknown) => void) | null = null;
        routerOnMock.mockImplementation((_type: string, cb: (event: unknown) => void) => {
            handler = cb;
            return () => {};
        });
        registerRecentAuthRedirectHandler();
        expect(routerOnMock).toHaveBeenCalledWith("httpException", expect.any(Function));

        let prevented = false;
        const event = {
            detail: { response },
            preventDefault: () => {
                prevented = true;
            },
        };
        (handler as unknown as (e: unknown) => void)(event);
        return { prevented };
    }

    const REQUIRED_409 = {
        status: 409,
        data: {
            code: "recent_auth_required",
            message: "この操作には直近の再認証が必要です。",
            redirect: "http://localhost:3000/recent-auth/confirm",
        },
    };

    it("409 + recent_auth_required + 同一 origin の confirm URL なら visit する", () => {
        const { prevented } = dispatch(REQUIRED_409);

        expect(prevented).toBe(true);
        expect(routerVisitMock).toHaveBeenCalledWith("/recent-auth/confirm");
    });

    it.each([
        ["別 code (誤食しない)", { ...REQUIRED_409, data: { ...REQUIRED_409.data, code: "scenario_conflict" } }],
        ["別 code (2FA)", { ...REQUIRED_409, data: { ...REQUIRED_409.data, code: "two_factor_required" } }],
        [
            "外部 URL",
            { ...REQUIRED_409, data: { ...REQUIRED_409.data, redirect: "https://evil.example/x" } },
        ],
        [
            "別 route",
            { ...REQUIRED_409, data: { ...REQUIRED_409.data, redirect: "/dashboard" } },
        ],
        ["redirect 欠損", { status: 409, data: { code: "recent_auth_required" } }],
        [
            "redirect が非文字列",
            { ...REQUIRED_409, data: { ...REQUIRED_409.data, redirect: 1 } },
        ],
        ["422", { ...REQUIRED_409, status: 422 }],
        ["500", { ...REQUIRED_409, status: 500 }],
        ["data が文字列 (非 JSON 応答)", { status: 409, data: "<html>error</html>" }],
        ["response が null", null],
    ])("%s では preventDefault しない (Inertia 既定処理へ渡す)", (_label, response) => {
        const { prevented } = dispatch(response);

        expect(prevented).toBe(false);
        expect(routerVisitMock).not.toHaveBeenCalled();
    });

    it("戻り値を呼ぶと購読解除される (HMR の二重登録防止)", () => {
        const unsubscribe = vi.fn();
        routerOnMock.mockReturnValue(unsubscribe);

        registerRecentAuthRedirectHandler()();

        expect(unsubscribe).toHaveBeenCalledTimes(1);
    });
});

describe("isRecentAuthRequiredPayload (409 契約の型ガード。T124)", () => {
    /*
     * status だけでは判定しない。同じ 409 を RequireTwoFactorForEnforcedOrganizations も
     * 返す (code: "two_factor_required") ため、status のみの判定は誤食する。
     */

    it("409 + code=recent_auth_required を true と判定する", () => {
        expect(
            isRecentAuthRequiredPayload(409, {
                code: "recent_auth_required",
                message: "この操作には直近の再認証が必要です。",
                redirect: "/recent-auth/confirm",
            }),
        ).toBe(true);
    });

    it("409 + code=two_factor_required を false と判定する (2FA 必須ゲートの 409 を誤食しない)", () => {
        expect(
            isRecentAuthRequiredPayload(409, {
                code: "two_factor_required",
                message: "組織は 2 段階認証を必須としています。",
                redirect: "/settings/security",
            }),
        ).toBe(false);
    });

    it.each([200, 302, 422, 500])("status %i は code が一致しても false", (status) => {
        expect(isRecentAuthRequiredPayload(status, { code: "recent_auth_required" })).toBe(false);
    });

    it.each([
        ["null", null],
        ["文字列 (非 JSON 応答)", "<html>error</html>"],
        ["配列", []],
        ["数値", 1],
        ["undefined", undefined],
        ["code 欠損", { message: "x" }],
        ["code が非文字列", { code: 1 }],
    ])("body が %s でも例外を投げず false", (_label, body) => {
        expect(() => isRecentAuthRequiredPayload(409, body)).not.toThrow();
        expect(isRecentAuthRequiredPayload(409, body)).toBe(false);
    });
});
