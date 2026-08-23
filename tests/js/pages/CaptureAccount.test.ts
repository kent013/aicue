import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";

/*
 * 撮影 PWA アカウント確認画面 Capture/Account。
 * - 表示名 / ログイン ID (メール) / 所属組織を共有 props から描画する
 * - メールは truncate せず break-all で全文が DOM に載る (省略した識別子では確認にならない)
 * - auth.user.id は描画に使わない (内部主キー。props には存在するが画面には出さない)
 * - ログアウトは router.post("/logout") = Inertia visit (経路 C の保証条件)
 * - ログアウト送信中はボタンが押下不可になる (必須条件未充足の disabled ではない)
 * - 復路リンクは /organizations/test-org/app (capture.home)
 *
 * mock 方式は既存 tests/js/pages/SettingsIndex.test.ts と同一
 * (vi.hoisted で plain object を作り vi.mock で page / router を差し替える)。
 */

const { pageState, routerPostMock } = vi.hoisted(() => ({
    pageState: { props: {} as Record<string, unknown>, url: "/organizations/test-org/app/account" },
    routerPostMock: vi.fn(),
}));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: { post: routerPostMock },
    page: pageState,
}));

// eslint-disable-next-line import/first
import CaptureAccount from "@/pages/Capture/Account.svelte";

function seed(overrides: Record<string, unknown> = {}): void {
    pageState.props = {
        appName: "AI-CUE",
        auth: {
            user: {
                // 他の表示値 (組織名・アプリ名・メール) と絶対に衝突しない値にする。
                // 「描画に使っていない」を container.textContent の非包含で確かめるため、
                // 短い数値 (42 等) だと偶然一致して偽陽性になる。
                id: 987654321,
                name: "撮影 太郎",
                email: "shooting.taro.very.long.local.part@example.co.jp",
                emailVerified: true,
                twoFactorEnabled: false,
            },
        },
        currentOrganization: {
            id: 1,
            name: "サンプル組織",
            slug: "sample",
            role: "organization_member",
            canManageMembers: false,
            canManageApiKeys: false,
        },
        ...overrides,
    };
}

describe("Capture/Account", () => {
    afterEach(() => {
        cleanup();
        // clearAllMocks は呼び出し履歴しか消さず **mockImplementation は残る**。
        // 送信中ケースが仕込む「onStart を呼んで応答を返さない」実装が次のケースへ漏れるので
        // mockReset まで行う (実装ごと落とす)。
        routerPostMock.mockReset();
    });

    it("表示名・ログイン ID・所属組織を描画する", () => {
        seed();
        render(CaptureAccount);

        expect(screen.getByTestId("capture-account-name")).toHaveTextContent("撮影 太郎");
        expect(screen.getByTestId("capture-account-email")).toHaveTextContent(
            "shooting.taro.very.long.local.part@example.co.jp",
        );
        expect(screen.getByTestId("capture-account-organization")).toHaveTextContent(
            "サンプル組織",
        );
    });

    it("メールは truncate せず break-all で全文を出す", () => {
        seed();
        render(CaptureAccount);

        const email = screen.getByTestId("capture-account-email");
        expect(email.className).toContain("break-all");
        expect(email.className).not.toContain("truncate");
    });

    it("auth.user.id を描画に使わない", () => {
        seed();
        const { container } = render(CaptureAccount);

        expect(container.textContent).not.toContain("987654321");
    });

    /*
     * Inertia の Link は jsdom で href を絶対 URL に解決する (http://localhost:3000/app) ため、
     * 完全一致ではなく末尾一致で固定する (既存 SettingsIndex.test.ts と同じ様式)。
     */
    it("復路リンクは /organizations/test-org/app (capture.home)", () => {
        seed();
        render(CaptureAccount);

        expect(screen.getByTestId("capture-account-back").getAttribute("href")).toMatch(
            /\/app$/,
        );
    });

    it("ログアウトは router.post('/logout') を呼ぶ", async () => {
        seed();
        render(CaptureAccount);

        await fireEvent.click(screen.getByTestId("capture-account-logout"));

        expect(routerPostMock).toHaveBeenCalledTimes(1);
        expect(routerPostMock.mock.calls[0][0]).toBe("/logout");
    });

    /*
     * 固定するのは **Button atom の loading 契約** (disabled={disabled || loading}) である。
     * Svelte 側の `if (loggingOut) return;` は DOM 経由では到達しない多重防御なので
     * ここでは固定しない (到達不能な経路のテストを作らない)。
     */
    it("ログアウト送信中はボタンが押下不可になる", async () => {
        seed();
        // onStart を呼んで loggingOut=true にしたまま応答を返さない (送信中を再現する)
        routerPostMock.mockImplementation(
            (_url: string, _data: unknown, options: { onStart?: () => void }) => {
                options.onStart?.();
            },
        );
        render(CaptureAccount);

        const button = screen.getByTestId("capture-account-logout");
        await fireEvent.click(button);

        expect(button).toBeDisabled();

        await fireEvent.click(button);
        expect(routerPostMock).toHaveBeenCalledTimes(1);
    });

    it("currentOrganization が null なら組織行を出さない (偽の既定値を作らない = 補助テスト)", () => {
        seed({ currentOrganization: null });
        render(CaptureAccount);

        expect(screen.queryByTestId("capture-account-organization")).toBeNull();
    });
});
