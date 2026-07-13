import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
import { createRawSnippet } from "svelte";
import { page } from "@inertiajs/svelte";
import AppLayout from "@/components/templates/AppLayout.svelte";
import type { AuthUser } from "@/lib/shared-props";

// router をモックし page state は実物を使う (テスト毎に props を差し替える)
const { routerMock } = vi.hoisted(() => ({
    routerMock: { post: vi.fn() },
}));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: routerMock,
}));

/*
 * AppLayout の常設アカウントナビ (F-08: ナビ統一) の単一の真実。
 * 全 AppLayout 利用ページのナビ表示はこの template テストで代表する
 * (ページ個別のナビテストは追加しない)。
 */

const children = createRawSnippet(() => ({
    render: () => `<div data-testid="page-content">content</div>`,
}));

function authUser(): AuthUser {
    return {
        id: 1,
        name: "テスト 太郎",
        email: "test@example.com",
        emailVerified: true,
        twoFactorEnabled: false,
    };
}

function setPageProps(props: Record<string, unknown>): void {
    page.props = props as typeof page.props;
}

afterEach(() => {
    cleanup();
    routerMock.post.mockReset();
    setPageProps({});
});

describe("templates/AppLayout", () => {
    it("ログイン中は設定リンク (/settings) とログアウトボタン・通知ベルを常設する", () => {
        setPageProps({
            auth: { user: authUser() },
            notifications: { unreadCount: 0 },
        });
        render(AppLayout, { props: { appName: "AI-CUE", children } });

        // Inertia Link は href を絶対 URL に正規化するため pathname で比較する
        const settingsHref = screen.getByTestId("nav-settings").getAttribute("href") ?? "";
        expect(new URL(settingsHref, "http://localhost").pathname).toBe("/settings");
        expect(screen.getByTestId("nav-logout")).toBeInTheDocument();
        expect(screen.getByTestId("notification-bell")).toBeInTheDocument();
        expect(screen.getByTestId("page-content")).toBeInTheDocument();
    });

    it("ログアウトボタン押下で POST /logout が呼ばれる", async () => {
        setPageProps({
            auth: { user: authUser() },
            notifications: { unreadCount: 0 },
        });
        render(AppLayout, { props: { appName: "AI-CUE", children } });

        await fireEvent.click(screen.getByTestId("nav-logout"));

        expect(routerMock.post).toHaveBeenCalledTimes(1);
        expect(routerMock.post.mock.calls[0][0]).toBe("/logout");
    });

    it("auth.user が null なら設定/ログアウト/ベルを描画しない (ゲスト到達ページの回帰)", () => {
        setPageProps({ auth: { user: null } });
        render(AppLayout, { props: { appName: "AI-CUE", children } });

        expect(screen.queryByTestId("nav-settings")).toBeNull();
        expect(screen.queryByTestId("nav-logout")).toBeNull();
        expect(screen.queryByTestId("notification-bell")).toBeNull();
        expect(screen.getByTestId("page-content")).toBeInTheDocument();
    });

    it("ログアウトボタンは disabled でない (禁止事項 8 の系)", () => {
        setPageProps({
            auth: { user: authUser() },
            notifications: { unreadCount: 0 },
        });
        render(AppLayout, { props: { appName: "AI-CUE", children } });

        expect(screen.getByTestId("nav-logout")).not.toBeDisabled();
    });

    it("notifications が undefined でもクラッシュせず unreadCount 0 相当で描画する", () => {
        // partial reload で shared props の閉包が省略されるケース・テスト環境での
        // 未定義ケースの両方をカバー (shared.notifications?.unreadCount ?? 0 の回帰固定)
        setPageProps({ auth: { user: authUser() } });
        render(AppLayout, { props: { appName: "AI-CUE", children } });

        expect(screen.getByTestId("notification-bell")).toBeInTheDocument();
        expect(screen.queryByTestId("unread-badge")).toBeNull();
    });

    it("ログイン中は組織スイッチャートリガーを常設描画する", () => {
        setPageProps({
            auth: { user: authUser() },
            notifications: { unreadCount: 0 },
            currentOrganization: {
                id: 1,
                name: "アクメ社",
                slug: "acme",
                role: "organization_owner",
                canManageMembers: true,
                canManageApiKeys: true,
            },
            organizations: [{ id: 1, name: "アクメ社", isPersonal: false }],
        });
        render(AppLayout, { props: { appName: "AI-CUE", children } });

        expect(screen.getByTestId("org-switcher-trigger")).toBeInTheDocument();
        expect(screen.getByTestId("org-switcher-trigger")).toHaveTextContent("アクメ社");
    });

    it("組織スイッチャートリガーは shrink-0 で 375px ヘッダー折返しを維持する", () => {
        setPageProps({
            auth: { user: authUser() },
            notifications: { unreadCount: 0 },
            currentOrganization: null,
            organizations: [],
        });
        render(AppLayout, { props: { appName: "AI-CUE", children } });

        expect(screen.getByTestId("org-switcher-trigger")).toHaveClass("shrink-0");
    });

    it("ページ固有の headerActions snippet と常設ナビが共存する (常設ナビは各 1 個)", () => {
        setPageProps({
            auth: { user: authUser() },
            notifications: { unreadCount: 0 },
        });
        const headerActions = createRawSnippet(() => ({
            render: () => `<button type="button" data-testid="page-action">ページ操作</button>`,
        }));
        render(AppLayout, { props: { appName: "AI-CUE", children, headerActions } });

        expect(screen.getByTestId("page-action")).toBeInTheDocument();
        expect(screen.getAllByTestId("nav-settings")).toHaveLength(1);
        expect(screen.getAllByTestId("nav-logout")).toHaveLength(1);
    });
});
