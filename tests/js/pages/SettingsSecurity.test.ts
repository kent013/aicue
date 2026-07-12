import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
import Security from "@/pages/Settings/Security.svelte";

/*
 * セキュリティ設定画面 (F-10: リカバリコード再生成導線)。
 * - 2FA 有効時のみ再生成ボタンが出る (非権限者非表示)
 * - ConfirmDialog 経由でのみ POST される
 * - POST 成功 → GET 成功: 新コード表示 + success トースト
 * - POST 成功 → GET 失敗: 旧コード非表示のまま error トースト + 再試行導線
 * - disabled 不使用 (AGENTS.md 禁止事項 8)
 */

// router.post をモックし、page は 2FA 状態を書き換えられる可変オブジェクトにする
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
    router: { post: routerPostMock, delete: vi.fn() },
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

beforeEach(() => {
    setTwoFactor(true);
    vi.stubGlobal("fetch", fetchMock);
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    routerPostMock.mockReset();
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

    it("ダイアログ確認で POST /user/two-factor-recovery-codes が発火する", async () => {
        render(Security, { props: {} });

        await fireEvent.click(screen.getByTestId("regenerate-recovery-codes-button"));
        await fireEvent.click(screen.getByRole("button", { name: "再生成する" }));

        expect(routerPostMock).toHaveBeenCalledWith(
            "/user/two-factor-recovery-codes",
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it("POST 成功 → GET 成功で新コードを表示し success トーストを出す", async () => {
        fetchMock.mockResolvedValue({
            ok: true,
            json: () => Promise.resolve(["new-code-1", "new-code-2"]),
        });
        render(Security, { props: {} });

        await fireEvent.click(screen.getByTestId("regenerate-recovery-codes-button"));
        await fireEvent.click(screen.getByRole("button", { name: "再生成する" }));
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
        // fetchJson は response.ok で throw するが、実装変更 (先に json() を読む等) にも
        // 壊れないよう失敗レスポンスにも json を持たせる
        fetchMock.mockResolvedValue({
            ok: false,
            status: 500,
            json: () => Promise.resolve({}),
        });
        render(Security, { props: {} });

        await fireEvent.click(screen.getByTestId("regenerate-recovery-codes-button"));
        await fireEvent.click(screen.getByRole("button", { name: "再生成する" }));
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
        render(Security, { props: {} });

        await fireEvent.click(screen.getByTestId("regenerate-recovery-codes-button"));
        await fireEvent.click(screen.getByRole("button", { name: "再生成する" }));

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
