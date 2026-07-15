import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
import { reactiveUseForm } from "../support/reactiveUseForm.svelte";

/*
 * Settings/Security 2FA セットアップ確認 (F-2-02 / T059)。
 * Fortify の ConfirmTwoFactorAuthentication は検証失敗を名前付き error bag
 * "confirmTwoFactorAuthentication" に投げる。client が同名の errorBag を指定しないと
 * Inertia が named bag をネストしたまま共有し confirmForm.errors.code が解決されず、
 * 誤コード時に無言失敗する。本テストは以下を回帰固定する:
 *   (a) 確認 POST に errorBag: "confirmTwoFactorAuthentication" が付く
 *   (b) レスポンスの errors 反映で入力直下にエラーが表示され Input が aria-invalid になる
 *   (c) 正コード成功で確認フォームが閉じ reset される
 *
 * useForm を reactiveUseForm フェイクへ差し替え「post の visit options 検証」と
 * 「named bag エラーからの表示」を分離して検証する。router.post / page は既存テスト同様モック。
 */

const { routerPostMock, pageState, addToastMock, holder } = vi.hoisted(() => ({
    routerPostMock: vi.fn(),
    pageState: {
        props: {} as Record<string, unknown>,
        url: "/settings/security",
    },
    addToastMock: vi.fn(),
    holder: { form: null as ReturnType<typeof reactiveUseForm> | null },
}));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: { post: routerPostMock },
    page: pageState,
    useForm: (init: Record<string, unknown>) => {
        const form = reactiveUseForm(init);
        holder.form = form;
        return form;
    },
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

/** 確認フロー描画に必要な fetch (QR / recent-auth / recovery codes) を stub する */
function stubFetchRoutes(): void {
    fetchMock.mockImplementation((input: RequestInfo | URL) => {
        const url = String(input);
        if (url.includes("/user/two-factor-qr-code")) {
            return Promise.resolve(jsonResponse(true, 200, { svg: "<svg></svg>" }));
        }
        if (url.includes("/recent-auth/status")) {
            return Promise.resolve(
                jsonResponse(true, 200, {
                    recent: true,
                    passwordSet: true,
                    availableProviders: [],
                    canSatisfy: true,
                    confirmedAt: 1,
                }),
            );
        }
        // /user/two-factor-recovery-codes (成功 callback 後の showRecoveryCodes)
        return Promise.resolve(jsonResponse(true, 200, ["code-a", "code-b"]));
    });
}

/** Inertia visit options (第3引数) の検証対象部分 */
interface InertiaVisitOptions {
    onStart?: () => void;
    onSuccess?: () => void;
    onError?: () => void;
    onFinish?: () => void;
}

/** router.post (enableTwoFactor) の第3引数を取り出す */
function lastRouterVisitOptions(): InertiaVisitOptions {
    const call = routerPostMock.mock.calls.at(-1);
    if (!call) throw new Error("router.post が呼ばれていない");
    return call[2] as InertiaVisitOptions;
}

function currentForm(): ReturnType<typeof reactiveUseForm> {
    if (!holder.form) throw new Error("confirmForm フェイクが未生成");
    return holder.form;
}

/** confirmForm.post の第2引数 (visit options) を取り出す */
function lastConfirmPostOptions(): InertiaVisitOptions {
    const call = currentForm().post.mock.calls.at(-1);
    if (!call) throw new Error("confirmForm.post が呼ばれていない");
    return call[1] as InertiaVisitOptions;
}

/**
 * 2FA 無効状態から確認フォームを表示させる。
 * 有効化ボタン押下 → router.post onSuccess で confirming=true にして QR/確認フォームを描画する。
 */
async function openConfirmForm(): Promise<void> {
    await fireEvent.click(screen.getByTestId("enable-two-factor-button"));
    await waitFor(() => {
        expect(routerPostMock).toHaveBeenCalledWith(
            "/user/two-factor-authentication",
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });
    lastRouterVisitOptions().onSuccess?.();
    await waitFor(() => {
        expect(screen.getByLabelText("認証コード")).toBeInTheDocument();
    });
}

/** 認証コードを入力して確認フォームを submit する */
async function submitConfirm(code = "123456"): Promise<void> {
    await fireEvent.input(screen.getByLabelText("認証コード"), { target: { value: code } });
    await fireEvent.click(screen.getByRole("button", { name: "確認して有効化" }));
}

beforeEach(() => {
    holder.form = null;
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

describe("Settings/Security 2FA 確認 (F-2-02: 誤コードエラー表示)", () => {
    it("(a) 確認 POST に errorBag: confirmTwoFactorAuthentication を指定する", async () => {
        render(Security, { props: {} });

        await openConfirmForm();
        await submitConfirm();

        expect(currentForm().post).toHaveBeenCalledWith(
            "/user/confirmed-two-factor-authentication",
            expect.objectContaining({ errorBag: "confirmTwoFactorAuthentication" }),
        );
    });

    it("(b) 誤コードのレスポンス errors 反映で入力直下にエラーを表示し Input を aria-invalid にする", async () => {
        render(Security, { props: {} });

        await openConfirmForm();
        await submitConfirm("000000");

        // Inertia がレスポンス受領後に form.errors を更新する挙動を模倣 (named bag からスコープ済み)
        currentForm().respondWithErrors({ code: "認証コードが無効です" });

        await waitFor(() => {
            expect(screen.getByText("認証コードが無効です")).toBeInTheDocument();
        });
        // 入力直下 (#two-factor-code-error) に文言が紐づく
        expect(screen.getByText("認証コードが無効です")).toHaveAttribute(
            "id",
            "two-factor-code-error",
        );
        // Input が error 状態 (赤枠 class は実装詳細のため aria-invalid で固定する)
        expect(screen.getByLabelText("認証コード")).toHaveAttribute("aria-invalid", "true");
    });

    it("(c) 正コード成功で確認フォームが閉じ reset される", async () => {
        render(Security, { props: {} });

        await openConfirmForm();
        await submitConfirm("123456");

        const form = currentForm();
        // 成功 callback を発火 (Inertia visit 成功時の onSuccess)
        lastConfirmPostOptions().onSuccess?.();

        await waitFor(() => {
            expect(screen.queryByLabelText("認証コード")).toBeNull();
        });
        expect(form.reset).toHaveBeenCalled();
        // 有効化ボタンに戻る (twoFactorEnabled は依然 false のため)
        expect(screen.getByTestId("enable-two-factor-button")).toBeInTheDocument();
    });
});
