import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
import Security from "@/pages/Settings/Security.svelte";

/*
 * セキュリティ設定画面 (F-10: リカバリコード再生成導線)。
 * - 2FA 有効時のみ再生成ボタンが出る (非権限者非表示)
 * - ConfirmDialog 経由でのみ POST される
 * - 再生成 / 表示は recent-auth precheck 込み (stale なら再認証モーダル、POST しない)
 * - POST 成功 → GET 成功: 新コード表示 + success トースト
 * - POST 成功 → GET 失敗: 旧コード非表示のまま error トースト + 再試行導線
 * - disabled 不使用 (AGENTS.md 禁止事項 8)
 */

// router.post をモックし、page は 2FA 状態を書き換えられる可変オブジェクトにする
const { routerPostMock, routerDeleteMock, pageState, addToastMock } = vi.hoisted(() => ({
    routerPostMock: vi.fn(),
    routerDeleteMock: vi.fn(),
    pageState: {
        props: {} as Record<string, unknown>,
        url: "/settings/security",
    },
    addToastMock: vi.fn(),
}));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: { post: routerPostMock, delete: routerDeleteMock },
    page: pageState,
}));

// addToast のみ差し替え、toasts store 等 (ToastContainer が使う) は実物を残す
vi.mock("@/lib/stores/toast", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@/lib/stores/toast")>()),
    addToast: addToastMock,
}));

const fetchMock = vi.fn();

function setTwoFactor(enabled: boolean): void {
    pageState.props = {
        appName: "AI-CUE",
        auth: { user: { id: 1, name: "テスト太郎", twoFactorEnabled: enabled } },
    };
}

/** JSON レスポンス風オブジェクト (fetch mock 用) */
function jsonResponse(ok: boolean, status: number, body: unknown): unknown {
    return { ok, status, json: () => Promise.resolve(body) };
}

/**
 * URL で分岐する fetch mock を張る。
 * - /recent-auth/status: recent-auth precheck (fresh/stale)
 * - /user/two-factor-recovery-codes: GET 新コード取得 (成功/失敗)
 */
function stubFetchRoutes({
    recent = true,
    codes = ["new-code-1", "new-code-2"],
    codesOk = true,
}: {
    recent?: boolean;
    codes?: string[];
    codesOk?: boolean;
} = {}): void {
    fetchMock.mockImplementation((input: RequestInfo | URL) => {
        const url = String(input);
        if (url.includes("/recent-auth/status")) {
            return Promise.resolve(
                jsonResponse(true, 200, {
                    recent,
                    passwordSet: true,
                    availableProviders: [],
                    canSatisfy: true,
                    confirmedAt: recent ? 1 : null,
                }),
            );
        }
        if (url.includes("/recent-auth/password")) {
            return Promise.resolve(jsonResponse(true, 204, null));
        }
        return Promise.resolve(
            codesOk ? jsonResponse(true, 200, codes) : jsonResponse(false, 500, {}),
        );
    });
}

beforeEach(() => {
    setTwoFactor(true);
    vi.stubGlobal("fetch", fetchMock);
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    routerPostMock.mockReset();
    routerDeleteMock.mockReset();
    addToastMock.mockReset();
    fetchMock.mockReset();
});

/** router.post の第3引数 (visit options) の検証対象部分。自己参照キャストを避けて明示定義する */
interface InertiaVisitOptions {
    onStart?: () => void;
    onSuccess?: () => void;
    onError?: () => void;
    onFinish?: () => void;
}

/** Inertia visit options (第3引数) を取り出す */
function lastVisitOptions(): InertiaVisitOptions {
    const call = routerPostMock.mock.calls.at(-1);
    if (!call) throw new Error("router.post が呼ばれていない");
    return call[2] as InertiaVisitOptions;
}

/** 再生成ダイアログを開いて確定する (recent-auth precheck が挟まるため POST は async) */
async function confirmRegenerate(): Promise<void> {
    await fireEvent.click(screen.getByTestId("regenerate-recovery-codes-button"));
    await fireEvent.click(screen.getByRole("button", { name: "再生成する" }));
}

/** 無効化ダイアログを開いて確定する (recent-auth precheck が挟まるため DELETE は async) */
async function confirmDisable(): Promise<void> {
    await fireEvent.click(screen.getByTestId("disable-two-factor-button"));
    await fireEvent.click(screen.getByRole("button", { name: "無効化する" }));
}

describe("Settings/Security リカバリコード再生成 (F-10)", () => {
    it("2FA 有効時に再生成ボタンが表示され、disabled ではない", () => {
        render(Security, { props: {} });

        const button = screen.getByTestId("regenerate-recovery-codes-button");
        expect(button).toBeInTheDocument();
        expect(button).not.toBeDisabled();
    });

    it("2FA 無効時は再生成ボタンを表示しない (有効化ボタンのみ)", () => {
        setTwoFactor(false);
        render(Security, { props: {} });

        expect(screen.queryByTestId("regenerate-recovery-codes-button")).toBeNull();
        expect(screen.getByTestId("enable-two-factor-button")).toBeInTheDocument();
    });

    it("再生成ボタン押下で確認ダイアログが開き、確認までは POST しない", async () => {
        render(Security, { props: {} });

        await fireEvent.click(screen.getByTestId("regenerate-recovery-codes-button"));

        expect(
            screen.getByText(/既存のリカバリコードは直ちにすべて失効します/),
        ).toBeInTheDocument();
        expect(routerPostMock).not.toHaveBeenCalled();
    });

    it("ダイアログ確認で recent-auth precheck 後に POST /user/two-factor-recovery-codes が発火する", async () => {
        stubFetchRoutes({ recent: true });
        render(Security, { props: {} });

        await confirmRegenerate();

        await waitFor(() => {
            expect(routerPostMock).toHaveBeenCalledWith(
                "/user/two-factor-recovery-codes",
                {},
                expect.objectContaining({ preserveScroll: true }),
            );
        });
        // precheck (/recent-auth/status) を経由している
        expect(fetchMock).toHaveBeenCalledWith("/recent-auth/status", expect.anything());
    });

    it("recent-auth が stale なら再認証モーダルを開き、POST しない", async () => {
        stubFetchRoutes({ recent: false });
        render(Security, { props: {} });

        await confirmRegenerate();

        await waitFor(() => {
            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
        });
        expect(routerPostMock).not.toHaveBeenCalled();
        // stale 時にコード GET も発火していない (precheck のみ)
        const requestedUrls = fetchMock.mock.calls.map((call) => String(call[0]));
        expect(requestedUrls).not.toContain("/user/two-factor-recovery-codes");
    });

    it("stale → モーダルで再認証成功 (204) すると保留していた POST を再開する", async () => {
        stubFetchRoutes({ recent: false });
        render(Security, { props: {} });

        await confirmRegenerate();
        await waitFor(() => {
            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
        });

        await fireEvent.input(screen.getByTestId("recent-auth-password-input"), {
            target: { value: "current-password" },
        });
        await fireEvent.click(screen.getByTestId("recent-auth-submit"));

        await waitFor(() => {
            expect(routerPostMock).toHaveBeenCalledWith(
                "/user/two-factor-recovery-codes",
                {},
                expect.objectContaining({ preserveScroll: true }),
            );
        });
    });

    it("POST 成功 → GET 成功で新コードを表示し success トーストを出す", async () => {
        stubFetchRoutes({ recent: true, codes: ["new-code-1", "new-code-2"], codesOk: true });
        render(Security, { props: {} });

        await confirmRegenerate();
        await waitFor(() => {
            expect(routerPostMock).toHaveBeenCalled();
        });
        lastVisitOptions().onSuccess?.();

        await waitFor(() => {
            expect(screen.getByTestId("recovery-codes")).toHaveTextContent("new-code-1");
        });
        expect(addToastMock).toHaveBeenCalledWith(
            "success",
            expect.stringContaining("再生成しました"),
        );
    });

    it("POST 成功 → GET 失敗では旧コードを残さず error トースト + 再試行導線に戻る", async () => {
        stubFetchRoutes({ recent: true, codesOk: false });
        render(Security, { props: {} });

        await confirmRegenerate();
        await waitFor(() => {
            expect(routerPostMock).toHaveBeenCalled();
        });
        lastVisitOptions().onSuccess?.();

        await waitFor(() => {
            expect(addToastMock).toHaveBeenCalledWith(
                "error",
                expect.stringContaining("以前のコードは既に無効です"),
            );
        });
        expect(screen.queryByTestId("recovery-codes")).toBeNull();
        expect(screen.getByTestId("show-recovery-codes-button")).toBeInTheDocument();
    });

    it("POST 実行中 (onStart〜onFinish) は確認ボタンが processing (aria-busy) になる", async () => {
        stubFetchRoutes({ recent: true });
        render(Security, { props: {} });

        await confirmRegenerate();
        await waitFor(() => {
            expect(routerPostMock).toHaveBeenCalled();
        });

        const options = lastVisitOptions();
        options.onStart?.();
        await waitFor(() => {
            // Button atom は loading 中 aria-busy を立てる (二重送信抑止の回帰固定)
            expect(screen.getByRole("button", { name: "再生成する" })).toHaveAttribute(
                "aria-busy",
                "true",
            );
        });

        options.onFinish?.();
        await waitFor(() => {
            expect(screen.getByRole("button", { name: "再生成する" })).not.toHaveAttribute(
                "aria-busy",
            );
        });
    });
});

describe("Settings/Security リカバリコード表示 (recent-auth precheck)", () => {
    it("fresh なら「リカバリコードを表示」でコード一覧を取得して表示する", async () => {
        stubFetchRoutes({ recent: true, codes: ["code-a", "code-b"] });
        render(Security, { props: {} });

        await fireEvent.click(screen.getByTestId("show-recovery-codes-button"));

        await waitFor(() => {
            expect(screen.getByTestId("recovery-codes")).toHaveTextContent("code-a");
        });
    });

    it("stale なら再認証モーダルを開き、コードを取得しない", async () => {
        stubFetchRoutes({ recent: false });
        render(Security, { props: {} });

        await fireEvent.click(screen.getByTestId("show-recovery-codes-button"));

        await waitFor(() => {
            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
        });
        expect(screen.queryByTestId("recovery-codes")).toBeNull();
        // /user/two-factor-recovery-codes への GET は発火していない (status のみ)
        const requestedUrls = fetchMock.mock.calls.map((call) => String(call[0]));
        expect(requestedUrls).not.toContain("/user/two-factor-recovery-codes");
    });
});

describe("Settings/Security 2FA 無効化 (recent-auth precheck)", () => {
    it("fresh なら DELETE /user/two-factor-authentication が exactly once 発火する", async () => {
        stubFetchRoutes({ recent: true });
        render(Security, { props: {} });

        await confirmDisable();

        await waitFor(() => {
            expect(routerDeleteMock).toHaveBeenCalledWith(
                "/user/two-factor-authentication",
                expect.objectContaining({ preserveScroll: true }),
            );
        });
        expect(routerDeleteMock).toHaveBeenCalledTimes(1);
        expect(fetchMock).toHaveBeenCalledWith("/recent-auth/status", expect.anything());
    });

    it("stale なら再認証モーダルを開き確認ダイアログを閉じ、DELETE しない (二重モーダル回避)", async () => {
        stubFetchRoutes({ recent: false });
        render(Security, { props: {} });

        await confirmDisable();

        await waitFor(() => {
            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
        });
        expect(routerDeleteMock).not.toHaveBeenCalled();
        // 二重モーダル回避: 無効化確認ダイアログ (disable-two-factor-dialog) は閉じている
        expect(screen.queryByTestId("disable-two-factor-dialog")).toBeNull();
    });

    it("stale → 再認証キャンセルで pending を破棄し、後続の別操作 resume でも DELETE しない", async () => {
        stubFetchRoutes({ recent: false });
        render(Security, { props: {} });

        // 1. disable を stale で開始 → 再認証モーダル表示
        await confirmDisable();
        await waitFor(() => {
            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
        });

        // 2. 再認証をキャンセル (open=false) → $effect が pendingAction を破棄
        await fireEvent.click(screen.getByRole("button", { name: "キャンセル" }));
        await waitFor(() => {
            expect(screen.queryByTestId("recent-auth-modal")).toBeNull();
        });

        // 3. 別操作 (再生成) を stale → 再認証成功させても disable closure は resume されない
        await confirmRegenerate();
        await waitFor(() => {
            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
        });
        await fireEvent.input(screen.getByTestId("recent-auth-password-input"), {
            target: { value: "current-password" },
        });
        await fireEvent.click(screen.getByTestId("recent-auth-submit"));

        // regenerate (POST) は resume されるが、破棄された disable (DELETE) は一度も発火しない
        await waitFor(() => {
            expect(routerPostMock).toHaveBeenCalled();
        });
        expect(routerDeleteMock).not.toHaveBeenCalled();
    });

    it("stale → 再認証成功で保留していた DELETE を exactly once 再開する", async () => {
        stubFetchRoutes({ recent: false });
        render(Security, { props: {} });

        await confirmDisable();
        await waitFor(() => {
            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
        });

        await fireEvent.input(screen.getByTestId("recent-auth-password-input"), {
            target: { value: "current-password" },
        });
        await fireEvent.click(screen.getByTestId("recent-auth-submit"));

        await waitFor(() => {
            expect(routerDeleteMock).toHaveBeenCalledWith(
                "/user/two-factor-authentication",
                expect.objectContaining({ preserveScroll: true }),
            );
        });
        expect(routerDeleteMock).toHaveBeenCalledTimes(1);
    });
});
