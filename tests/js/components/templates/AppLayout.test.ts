import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, within } from "@testing-library/svelte";
import { createRawSnippet } from "svelte";
import { page } from "@inertiajs/svelte";
import AppLayout from "@/components/templates/AppLayout.svelte";
import type { AuthUser, CurrentOrganization } from "@/lib/shared-props";
import { resetFlashConsumption } from "@/lib/stores/flash-to-toast";
import { addToast, clearToasts } from "@/lib/stores/toast";

// router をモックし page state は実物を使う (テスト毎に props を差し替える)
const { routerMock } = vi.hoisted(() => ({
    routerMock: { post: vi.fn() },
}));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: routerMock,
}));

/*
 * AppLayout (左サイドバー型) のナビ表示・認可連動・主要インタラクションの単一の真実。
 * 全 AppLayout 利用ページのナビ表示はこの template テストで代表する
 * (ページ個別のナビテストは追加しない)。sidebar visibility contract のサーバ側 shape は
 * tests/Feature/Organizations/OrganizationNavSharedPropsTest.php が固定する。
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

function org(overrides: Partial<CurrentOrganization> = {}): CurrentOrganization {
    return {
        id: 1,
        name: "アクメ社",
        slug: "acme",
        role: "organization_member",
        canManageMembers: false,
        canManageApiKeys: false,
        ...overrides,
    };
}

function setPageProps(props: Record<string, unknown>): void {
    page.props = props as typeof page.props;
    page.url = "/dashboard";
}

function renderApp(): void {
    render(AppLayout, { props: { appName: "AI-CUE", children } });
}

/** desktop シェル (app-sidebar) にスコープした query。nav/menu は desktop/mobile 二枚に出るため。 */
function desktop() {
    return within(screen.getByTestId("app-sidebar"));
}

/** mobile シェル (app-sidebar-mobile ドロワー) にスコープした query。 */
function mobile() {
    return within(screen.getByTestId("app-sidebar-mobile"));
}

afterEach(() => {
    cleanup();
    routerMock.post.mockReset();
    localStorage.clear();
    clearToasts();
    resetFlashConsumption();
    setPageProps({});
});

describe("templates/AppLayout", () => {
    it("ログイン時: 通知ベル (desktop) を単一描画し、page-content を描画する", () => {
        setPageProps({
            auth: { user: authUser() },
            notifications: { unreadCount: 0 },
            currentOrganization: org(),
        });
        renderApp();

        // desktop notification-bell は 1 個 (mobile は notification-bell-mobile で別 testId)
        expect(screen.getAllByTestId("notification-bell")).toHaveLength(1);
        expect(screen.getByTestId("notification-bell-mobile")).toBeInTheDocument();
        expect(screen.getByTestId("page-content")).toBeInTheDocument();
    });

    it("通知ベルは描画のみで副作用 (fetch / router) を発火しない (回帰: 二重マウント安全性)", () => {
        const fetchSpy = vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response(null));
        setPageProps({
            auth: { user: authUser() },
            notifications: { unreadCount: 3 },
            currentOrganization: org(),
        });
        renderApp();

        expect(fetchSpy).not.toHaveBeenCalled();
        expect(routerMock.post).not.toHaveBeenCalled();
        fetchSpy.mockRestore();
    });

    it("ユーザーメニューを開くと個人設定リンク (/settings) とログアウトが出る", async () => {
        setPageProps({
            auth: { user: authUser() },
            notifications: { unreadCount: 0 },
            currentOrganization: org(),
        });
        renderApp();

        await fireEvent.click(desktop().getByTestId("app-user-menu-toggle"));

        const settings = desktop().getByTestId("nav-settings");
        expect(new URL(settings.getAttribute("href") ?? "", "http://localhost").pathname).toBe(
            "/settings",
        );
        expect(desktop().getByTestId("logout-button")).toBeInTheDocument();
    });

    it("ログアウトボタン押下で POST /logout が呼ばれ、ボタンは disabled でない", async () => {
        setPageProps({
            auth: { user: authUser() },
            notifications: { unreadCount: 0 },
            currentOrganization: org(),
        });
        renderApp();

        await fireEvent.click(desktop().getByTestId("app-user-menu-toggle"));
        const logout = desktop().getByTestId("logout-button");
        expect(logout).not.toBeDisabled();

        await fireEvent.click(logout);
        expect(routerMock.post).toHaveBeenCalledTimes(1);
        expect(routerMock.post.mock.calls[0][0]).toBe("/logout");
    });

    it("ゲスト到達 (auth.user=null): nav / メニュー / ベルを描画せず page-content のみ", () => {
        setPageProps({ auth: { user: null } });
        renderApp();

        expect(screen.queryByTestId("app-sidebar")).toBeNull();
        expect(screen.queryByTestId("app-sidebar-mobile")).toBeNull();
        expect(screen.queryByTestId("notification-bell")).toBeNull();
        expect(screen.queryByTestId("notification-bell-mobile")).toBeNull();
        expect(screen.queryByTestId("app-user-menu-toggle")).toBeNull();
        expect(screen.getByTestId("page-content")).toBeInTheDocument();
    });

    it("notifications undefined でもクラッシュせず unread バッジ非表示", () => {
        setPageProps({ auth: { user: authUser() }, currentOrganization: org() });
        renderApp();

        expect(screen.getAllByTestId("notification-bell")).toHaveLength(1);
        expect(screen.queryByTestId("unread-badge")).toBeNull();
    });

    // --- 認可連動 (負例) ---

    it("currentOrganization=null: org-scoped 導線 (プロジェクト/請求/メンバー/API キー) を出さない", () => {
        setPageProps({
            auth: { user: authUser() },
            notifications: { unreadCount: 0 },
            currentOrganization: null,
        });
        renderApp();

        const d = desktop();
        expect(d.getByTestId("nav-item-/dashboard")).toBeInTheDocument();
        // 設定は左 nav から撤去 (下部ユーザーメニューに一本化, T070) のため nav 項目としては出ない
        expect(d.queryByTestId("nav-item-/settings")).toBeNull();
        expect(d.queryByTestId("nav-item-/projects")).toBeNull();
        expect(d.queryByTestId("nav-item-/billing")).toBeNull();
        expect(d.queryByTestId("nav-item-/manage/users")).toBeNull();
        expect(d.queryByTestId("nav-item-/organizations/acme/api-keys")).toBeNull();
    });

    it("メンバー (manage 権限なし): メンバー/API キー導線は出さず、プロジェクト/請求は出す", () => {
        setPageProps({
            auth: { user: authUser() },
            notifications: { unreadCount: 0 },
            currentOrganization: org(),
        });
        renderApp();

        const d = desktop();
        expect(d.getByTestId("nav-item-/projects")).toBeInTheDocument();
        expect(d.getByTestId("nav-item-/billing")).toBeInTheDocument();
        expect(d.queryByTestId("nav-item-/manage/users")).toBeNull();
        expect(d.queryByTestId("nav-item-/organizations/acme/api-keys")).toBeNull();
    });

    it("canManageMembers=true: メンバー導線 (/manage/users) を出す", () => {
        setPageProps({
            auth: { user: authUser() },
            notifications: { unreadCount: 0 },
            currentOrganization: org({ canManageMembers: true }),
        });
        renderApp();

        expect(desktop().getByTestId("nav-item-/manage/users")).toBeInTheDocument();
    });

    it("canManageApiKeys=true: API キー導線 (/organizations/{slug}/api-keys) を出す", () => {
        setPageProps({
            auth: { user: authUser() },
            notifications: { unreadCount: 0 },
            currentOrganization: org({ canManageApiKeys: true }),
        });
        renderApp();

        expect(desktop().getByTestId("nav-item-/organizations/acme/api-keys")).toBeInTheDocument();
    });

    // --- 設定は下部ユーザーメニューに一本化 (T070: 左 nav に設定を出さない) ---

    it("左サイドバー nav に「設定」(/settings) を出さない (desktop/mobile 両シェル)", () => {
        setPageProps({
            auth: { user: authUser() },
            notifications: { unreadCount: 0 },
            currentOrganization: org(),
        });
        renderApp();

        // 左 nav に /settings 項目が無い (設定はポップアップの個人設定に一本化)
        expect(desktop().queryByTestId("nav-item-/settings")).toBeNull();
        expect(mobile().queryByTestId("nav-item-/settings")).toBeNull();
        // ダッシュボード等の他 nav は出る (回帰の確認)
        expect(desktop().getByTestId("nav-item-/dashboard")).toBeInTheDocument();
    });

    it("個人設定 (/settings) は下部ユーザーメニュー内に一本化して存在する", async () => {
        setPageProps({
            auth: { user: authUser() },
            notifications: { unreadCount: 0 },
            currentOrganization: org(),
        });
        renderApp();

        await fireEvent.click(desktop().getByTestId("app-user-menu-toggle"));
        const settings = desktop().getByTestId("nav-settings");
        expect(new URL(settings.getAttribute("href") ?? "", "http://localhost").pathname).toBe(
            "/settings",
        );
    });

    // --- 組織切替 / drawer / Escape ---

    it("組織切替: org-switch-{id} 押下で正しい id の POST /organizations/{id}/switch が 1 回", async () => {
        setPageProps({
            auth: { user: authUser() },
            notifications: { unreadCount: 0 },
            currentOrganization: org({ id: 1 }),
            organizations: [
                { id: 1, name: "アクメ社", isPersonal: false },
                { id: 2, name: "別組織", isPersonal: false },
            ],
        });
        renderApp();

        await fireEvent.click(desktop().getByTestId("app-user-menu-toggle"));
        await fireEvent.click(desktop().getByTestId("org-switch-2"));

        expect(routerMock.post).toHaveBeenCalledTimes(1);
        expect(routerMock.post.mock.calls[0][0]).toBe("/organizations/2/switch");
    });

    it("mobile drawer: メニューボタンで開き、閉じるボタンで閉じる", async () => {
        setPageProps({
            auth: { user: authUser() },
            notifications: { unreadCount: 0 },
            currentOrganization: org(),
        });
        renderApp();

        const drawer = screen.getByTestId("app-sidebar-mobile");
        expect(drawer.className).toContain("-translate-x-full");

        await fireEvent.click(screen.getByTestId("mobile-menu-button"));
        expect(drawer.className).toContain("translate-x-0");

        await fireEvent.click(screen.getByTestId("mobile-menu-close"));
        expect(drawer.className).toContain("-translate-x-full");
    });

    it("Escape で開いている user menu が閉じる", async () => {
        setPageProps({
            auth: { user: authUser() },
            notifications: { unreadCount: 0 },
            currentOrganization: org(),
        });
        renderApp();

        await fireEvent.click(desktop().getByTestId("app-user-menu-toggle"));
        expect(screen.getByTestId("app-user-menu")).toBeInTheDocument();

        await fireEvent.keyDown(document, { key: "Escape" });
        expect(screen.queryByTestId("app-user-menu")).toBeNull();
    });

    // --- toast の消去境界 (T095 / DESIGN.md §Toast) ---

    it("layout の初期化で既存 toast を破棄し、当該 visit の flash を表示する", async () => {
        // 遷移前ページで積まれた toast は layout の再初期化で消え、着地応答の flash が新規に出る。
        // 消去責務は ToastContainer.onDestroy ではなく layout 初期化にある (順序が決定的)。
        addToast("error", "前のページのエラー");
        setPageProps({
            auth: { user: authUser() },
            notifications: { unreadCount: 0 },
            currentOrganization: org(),
            flash: { success: "動画マニュアルを削除しました", visitKey: "visit-1" },
        });
        renderApp();

        expect(await screen.findByTestId("toast-success")).toHaveTextContent(
            "動画マニュアルを削除しました",
        );
        expect(screen.queryByText("前のページのエラー")).toBeNull();
    });

    it("サイドバー折りたたみ状態を localStorage から復元する", () => {
        localStorage.setItem("aicue:layout:sidebarOpen", "false");
        setPageProps({
            auth: { user: authUser() },
            notifications: { unreadCount: 0 },
            currentOrganization: org(),
        });
        renderApp();

        // 折りたたみ時は desktop の notification-bell (sidebarOpen 時のみ描画) が出ない
        expect(screen.queryByTestId("notification-bell")).toBeNull();
        // mobile 側は viewport 非依存で常時ある
        expect(screen.getByTestId("notification-bell-mobile")).toBeInTheDocument();
    });
});
