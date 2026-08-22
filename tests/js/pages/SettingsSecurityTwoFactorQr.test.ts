import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";

/*
 * Settings/Security 2FA の QR 表示 (T251 / 家系正典 svelte-raw-html-sink-ban)。
 *
 * サーバ生成の SVG は **data URI の <img>** として描く。raw HTML 挿入構文
 * (生 HTML を DOM へ差し込む構文) は使わない — 値の出どころが 1 か所でも汚れていれば
 * script がそのまま実行され、同一オリジン・セッション認証の撮影導線に直結するため。
 *
 * 固定する不変条件:
 *   1. two-factor-qr が IMG 要素である
 *   2. その src が data:image/svg+xml, で始まる
 *   3. アクセシブルネーム (alt) が維持されている
 *   4. サーバ応答の SVG に script が含まれていても DOM に script 要素が生えない
 *      (= 画面の層でも sink が閉じていることの直接の裏取り)
 *   5. QR 取得失敗時の代替導線 (qr-unavailable) に後退が無い
 */

const { routerPostMock, pageState, addToastMock } = vi.hoisted(() => ({
    routerPostMock: vi.fn(),
    pageState: {
        props: {} as Record<string, unknown>,
        url: "/settings/security",
    },
    addToastMock: vi.fn(),
}));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: { post: routerPostMock },
    page: pageState,
}));

vi.mock("@/lib/stores/toast", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@/lib/stores/toast")>()),
    addToast: addToastMock,
}));

import Security from "@/pages/Settings/Security.svelte";

const fetchMock = vi.fn();

/** JSON レスポンス風オブジェクト (fetch mock 用) */
function jsonResponse(ok: boolean, status: number, body: unknown): unknown {
    return { ok, status, json: () => Promise.resolve(body) };
}

/** enrollment 素材の fetch を stub する (QR だけ差し替え可能にする) */
function stubFetchRoutes(qr: unknown = jsonResponse(true, 200, { svg: "<svg></svg>" })): void {
    fetchMock.mockImplementation((input: RequestInfo | URL) => {
        const url = String(input);
        if (url.includes("/user/two-factor-qr-code")) {
            return Promise.resolve(qr);
        }
        if (url.includes("/user/two-factor-secret-key")) {
            return Promise.resolve(jsonResponse(true, 200, { secretKey: "ABCDEFGH12345678" }));
        }
        if (url.includes("/recent-auth/status")) {
            return Promise.resolve(
                jsonResponse(true, 200, {
                    recent: true,
                    passwordSet: true,
                    availableProviders: [],
                    passkeyAvailable: false,
                    canSatisfy: true,
                    confirmedAt: 1,
                }),
            );
        }
        return Promise.resolve(jsonResponse(true, 200, ["code-a", "code-b"]));
    });
}

/** 有効化ボタン押下 → router.post の onSuccess 発火で enrollment 表示へ進める */
async function openEnrollment(): Promise<void> {
    await fireEvent.click(screen.getByTestId("enable-two-factor-button"));
    await waitFor(() => {
        expect(routerPostMock).toHaveBeenCalled();
    });
    const call = routerPostMock.mock.calls.at(-1);
    if (!call) throw new Error("router.post が呼ばれていない");
    (call[2] as { onSuccess?: () => void }).onSuccess?.();
    await waitFor(() => {
        expect(screen.getByLabelText("認証コード")).toBeInTheDocument();
    });
}

beforeEach(() => {
    pageState.props = {
        appName: "AI-CUE",
        auth: { user: { id: 1, name: "テスト太郎", twoFactorEnabled: false } },
    };
    stubFetchRoutes();
    vi.stubGlobal("fetch", fetchMock);
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    routerPostMock.mockReset();
    addToastMock.mockReset();
    fetchMock.mockReset();
});

describe("Settings/Security 2FA の QR は data URI の <img> で描く", () => {
    it("two-factor-qr は IMG 要素で src が data:image/svg+xml, で始まる", async () => {
        render(Security, { props: {} });
        await openEnrollment();

        const qr = await screen.findByTestId("two-factor-qr");
        expect(qr.tagName).toBe("IMG");
        expect(qr.getAttribute("src")).toMatch(/^data:image\/svg\+xml,/);
    });

    it("アクセシブルネームを alt で維持する", async () => {
        render(Security, { props: {} });
        await openEnrollment();

        await waitFor(() => {
            expect(screen.getByAltText("2 要素認証の設定用 QR コード")).toBeInTheDocument();
        });
        expect(screen.getByAltText("2 要素認証の設定用 QR コード")).toBe(
            screen.getByTestId("two-factor-qr"),
        );
    });

    it("サーバ応答の SVG に script が含まれていても DOM に script 要素が生えない", async () => {
        stubFetchRoutes(
            jsonResponse(true, 200, { svg: '<svg><script>window.pwned = true;</script></svg>' }),
        );
        const { container } = render(Security, { props: {} });
        await openEnrollment();

        const qr = await screen.findByTestId("two-factor-qr");
        expect(qr.tagName).toBe("IMG");
        // QR 要素の下に子要素が 1 つも生えていない (HTML として解釈されていない)。
        // svg の有無を画面全体で見ることはできない (Lucide のアイコンが svg を描くため)。
        expect(qr.querySelectorAll("*")).toHaveLength(0);
        // script は画面のどこにも生えない (アイコンは script を描かない)
        expect(container.querySelector("script")).toBeNull();
    });

    it("QR 取得失敗時は従来どおり代替導線を出す (後退が無い)", async () => {
        stubFetchRoutes(jsonResponse(false, 500, null));
        render(Security, { props: {} });
        await openEnrollment();

        await waitFor(() => {
            expect(screen.getByTestId("qr-unavailable")).toBeInTheDocument();
        });
        expect(screen.queryByTestId("two-factor-qr")).toBeNull();
        expect(screen.getByTestId("two-factor-setup-key-body")).toHaveTextContent(
            "ABCDEFGH12345678",
        );
    });
});
