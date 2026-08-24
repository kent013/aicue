<script lang="ts">
    import type { Snippet } from "svelte";
    import { onMount } from "svelte";
    import { page, router } from "@inertiajs/svelte";
    import {
        Building2,
        Check,
        ChevronLeft,
        ChevronRight,
        ChevronUp,
        CreditCard,
        FolderKanban,
        House,
        KeyRound,
        Menu,
        Plus,
        UserPlus,
        X,
    } from "@lucide/svelte";
    import EmailVerificationBanner from "@/components/features/auth/EmailVerificationBanner.svelte";
    import NotificationBell from "@/components/molecules/NotificationBell.svelte";
    import PendingInvitationsNotice from "@/components/molecules/PendingInvitationsNotice.svelte";
    import ToastContainer from "@/components/organisms/ToastContainer.svelte";
    import SidebarNavItems, {
        type SidebarNavItem,
    } from "@/components/templates/_helpers/SidebarNavItems.svelte";
    import SidebarUserMenu from "@/components/templates/_helpers/SidebarUserMenu.svelte";
    import type { SharedProps } from "@/lib/shared-props";
    import { consumeFlash } from "@/lib/stores/flash-to-toast";
    import { clearToasts } from "@/lib/stores/toast";
    import { orgUrl } from "@/lib/org-url";

    /**
     * 認証済み画面用レイアウト (左サイドバー型。参照アプリ aigenba 準拠)。
     * desktop 固定サイドバー + 折りたたみ + モバイルドロワー + 下部に組織/ユーザーメニュー。
     * ナビ可視条件はサーバ真実の shared prop (currentOrganization + capability) で出し分ける
     * (サイドバーから 403 導線を作らない)。通知は NotificationBell を単一導線とし、Laravel flash は
     * consumeFlash で toast に変換する。ログアウト POST はこのレイアウトの単一ハンドラに一本化する。
     */
    interface Props {
        appName: string;
        children: Snippet;
    }

    let { appName, children }: Props = $props();

    // shared props は backend (HandleInertiaRequests) が真実。lib/shared-props.ts の型で読む
    const shared = $derived(page.props as unknown as SharedProps);

    // 消去境界 (DESIGN.md §Toast): layout の初期化時に既存 toast を破棄してから
    // 当該 visit の flash を消費する。初期化時の 1 回のみ ($effect に載せない)。
    clearToasts();

    $effect(() => {
        consumeFlash(shared.flash);
    });

    // ログイン時のみ nav / 下部メニュー / ベルを常設 (ゲスト到達ページでは children のみ)
    const showAccountNav = $derived(shared.auth?.user != null);
    const currentOrganization = $derived(shared.currentOrganization ?? null);
    const organizations = $derived(shared.organizations ?? []);
    const userName = $derived(shared.auth?.user?.name ?? "ユーザー");
    const orgName = $derived(currentOrganization?.name ?? "組織未選択");
    const unreadCount = $derived(shared.notifications?.unreadCount ?? 0);
    // 自分宛の保留中招待の件数 (全画面横断の気づき。未ログイン / 未 verified は 0)
    const pendingInvitationCount = $derived(shared.invitationInbox?.pendingCount ?? 0);

    // メール未認証のソフトゲート案内
    const showEmailBanner = $derived(
        shared.auth?.user != null && shared.auth.user.emailVerified === false,
    );

    const SIDEBAR_STORAGE_KEY = "aicue:layout:sidebarOpen";

    let sidebarOpen = $state(true);
    let mobileMenuOpen = $state(false);
    let userMenuOpen = $state(false);
    let detailsOpen = $state(false);
    let orgSearchQuery = $state("");
    let loggingOut = $state(false);

    onMount(() => {
        const saved = localStorage.getItem(SIDEBAR_STORAGE_KEY);
        if (saved !== null) {
            sidebarOpen = saved === "true";
        }
    });

    // active 判定: query/hash を除いた pathname で完全一致 + 明示 prefix 許可 (プロジェクト配下のみ)。
    // ★組織 URL 配下なので prefix も組織ごとに変わる = 派生値にする (定数にすると陳腐化する)。
    const PREFIX_ACTIVE = $derived(
        new Set(currentOrganization ? [orgUrl(currentOrganization.slug, "/projects")] : []),
    );
    const path = $derived(new URL(page.url, "http://localhost").pathname);
    const isActive = (href: string): boolean =>
        path === href || (PREFIX_ACTIVE.has(href) && path.startsWith(href + "/"));

    // org-scoped href は currentOrganization.slug からのみ生成 (org なし時 null = 非表示)
    const orgSettingsHref = $derived(
        currentOrganization ? orgUrl(currentOrganization.slug, "/settings") : null,
    );
    const cliHref = $derived(
        currentOrganization ? orgUrl(currentOrganization.slug, "/onboarding/cli") : null,
    );
    const mcpHref = $derived(
        currentOrganization ? orgUrl(currentOrganization.slug, "/onboarding/mcp") : null,
    );
    /** 通知一覧 (組織 URL 配下)。組織文脈が無ければ導線ごと出さない。 */
    const notificationsHref = $derived(
        currentOrganization ? orgUrl(currentOrganization.slug, "/notifications") : null,
    );

    // nav 項目 (認可マッピング: 詳細設計参照)。org-scoped は currentOrganization != null + capability でゲート。
    const navItems = $derived.by((): SidebarNavItem[] => {
        const org = currentOrganization;
        // ★業務 route はすべて組織 URL 配下にある (家系裁定 AG-037)。
        //   組織文脈が無い面 (個人設定等) では 1 項目も出さない = 壊れた URL を作らない。
        if (!org) return [];
        const items: SidebarNavItem[] = [
            { href: orgUrl(org.slug, "/dashboard"), label: "ダッシュボード", icon: House },
            { href: orgUrl(org.slug, "/projects"), label: "プロジェクト", icon: FolderKanban },
        ];
        if (org.canManageMembers)
            items.push({ href: orgUrl(org.slug, "/manage/users"), label: "メンバー", icon: UserPlus });
        if (org.canManageApiKeys)
            items.push({
                href: orgUrl(org.slug, "/api-keys"),
                label: "API キー",
                icon: KeyRound,
            });
        items.push({ href: orgUrl(org.slug, "/billing"), label: "請求", icon: CreditCard });
        // 設定は下部 SidebarUserMenu(個人設定)に一本化。左 nav には出さない(aigenba 準拠, T070)。
        return items;
    });

    // 組織検索 (5 件超で検索窓)
    const filteredOrganizations = $derived.by(() => {
        const q = orgSearchQuery.trim().toLowerCase();
        if (!q) return organizations;
        return organizations.filter((org) => org.name.toLowerCase().includes(q));
    });

    function toggleSidebar(): void {
        const next = !sidebarOpen;
        sidebarOpen = next;
        localStorage.setItem(SIDEBAR_STORAGE_KEY, String(next));
        // 折りたたむ (next=false) ときは開いている user menu を閉じる
        if (!next) closeUserMenu();
    }

    function toggleMobileMenu(): void {
        mobileMenuOpen = !mobileMenuOpen;
    }
    function closeMobileMenu(): void {
        mobileMenuOpen = false;
        closeUserMenu();
    }
    function toggleUserMenu(): void {
        userMenuOpen = !userMenuOpen;
        if (!userMenuOpen) {
            detailsOpen = false;
            orgSearchQuery = "";
        }
    }
    function closeUserMenu(): void {
        userMenuOpen = false;
        detailsOpen = false;
        orgSearchQuery = "";
    }
    function toggleDetails(): void {
        detailsOpen = !detailsOpen;
    }

    // ログアウト (二重送信ガード。失敗時も onFinish で解除され再試行できる)
    function logout(): void {
        if (loggingOut) return;
        router.post(
            "/logout",
            {},
            {
                onStart: () => {
                    loggingOut = true;
                },
                onFinish: () => {
                    loggingOut = false;
                },
            },
        );
    }

    /**
     * 別組織へは**その組織の URL へ移動する**だけである (家系裁定 AG-037)。
     * 切替 endpoint も保持列も存在しない — 一覧はリンクであって切替 UI ではない。
     */
    function organizationHref(slug: string): string {
        return orgUrl(slug, "/dashboard");
    }

    // user menu 展開中のみ Escape で閉じる (outside click は overlay button が担う)
    $effect(() => {
        if (!userMenuOpen) return;
        function onKeydown(event: KeyboardEvent): void {
            if (event.key === "Escape") closeUserMenu();
        }
        document.addEventListener("keydown", onKeydown);
        return () => document.removeEventListener("keydown", onKeydown);
    });
</script>

<ToastContainer />

<div class="min-h-screen bg-neutral text-text">
    {#if showAccountNav}
        <!-- Mobile Top Bar -->
        <div
            class="sticky top-0 z-30 flex h-14 items-center justify-between gap-3 border-b border-border bg-surface px-4 lg:hidden"
        >
            <div class="flex items-center gap-3">
                <button
                    onclick={toggleMobileMenu}
                    class="flex size-10 items-center justify-center rounded-lg text-text-secondary transition-colors hover:bg-neutral hover:text-text"
                    type="button"
                    aria-label="メニューを開く"
                    data-testid="mobile-menu-button"
                >
                    <Menu class="size-6" aria-hidden="true" />
                </button>
                <a href="/go" class="text-h3 text-primary">{appName}</a>
            </div>
            {#if notificationsHref}
                <NotificationBell
                    {unreadCount}
                    href={notificationsHref}
                    testId="notification-bell-mobile"
                />
            {/if}
        </div>

        <!-- Desktop Sidebar -->
        <aside
            class="fixed top-0 left-0 z-40 hidden h-screen flex-col border-r border-border bg-surface transition-all duration-300 lg:flex"
            style="width: {sidebarOpen ? '256px' : '64px'}"
            data-testid="app-sidebar"
        >
            <!-- Logo / Brand + bell -->
            <div class="flex items-center justify-between gap-2 border-b border-border px-3 py-4">
                <a
                    href="/go"
                    class="truncate text-h3 text-primary {sidebarOpen ? '' : 'sr-only'}"
                >
                    {appName}
                </a>
                {#if sidebarOpen && notificationsHref}
                    <NotificationBell {unreadCount} href={notificationsHref} testId="notification-bell" />
                {/if}
            </div>

            <!-- Collapse Toggle -->
            <button
                type="button"
                onclick={toggleSidebar}
                aria-label={sidebarOpen ? "サイドバーを折りたたむ" : "サイドバーを開く"}
                class="absolute top-[73px] -right-3 z-50 hidden size-6 -translate-y-1/2 items-center justify-center rounded-lg border border-border bg-surface text-text-secondary transition-colors hover:bg-neutral hover:text-text lg:flex"
                data-testid="sidebar-collapse-toggle"
            >
                {#if sidebarOpen}
                    <ChevronLeft class="size-3.5" aria-hidden="true" />
                {:else}
                    <ChevronRight class="size-3.5" aria-hidden="true" />
                {/if}
            </button>

            <!-- Nav -->
            <SidebarNavItems items={navItems} {isActive} showLabel={sidebarOpen} />

            <!-- User / Organization Menu (bottom) -->
            <div class="relative border-t border-border">
                {#if userMenuOpen}
                    <button
                        type="button"
                        class="fixed inset-0 z-30 cursor-default"
                        aria-label="メニューを閉じる"
                        onclick={closeUserMenu}
                    ></button>
                {/if}

                <button
                    type="button"
                    onclick={toggleUserMenu}
                    data-testid="app-user-menu-toggle"
                    class="relative z-40 flex w-full items-center gap-3 px-3 py-3 transition-colors hover:bg-neutral {sidebarOpen
                        ? ''
                        : 'justify-center'}"
                    aria-haspopup="menu"
                    aria-expanded={userMenuOpen}
                    title={`${orgName} / ${userName}`}
                >
                    <div
                        class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary text-surface"
                    >
                        <Building2 class="size-5" aria-hidden="true" />
                    </div>
                    {#if sidebarOpen}
                        <div class="min-w-0 flex-1 text-left">
                            <p class="truncate text-caption font-medium text-text">{orgName}</p>
                            <p class="truncate text-caption text-text-secondary">{userName}</p>
                        </div>
                        <ChevronUp
                            class="size-4 shrink-0 text-text-secondary transition-transform {userMenuOpen
                                ? 'rotate-180'
                                : ''}"
                            aria-hidden="true"
                        />
                    {/if}
                </button>

                {#if userMenuOpen}
                    <div
                        class="absolute bottom-full left-2 z-40 mb-2 max-h-[70vh] w-64 overflow-y-auto rounded-lg border border-border bg-surface py-2"
                        data-testid="app-user-menu"
                        role="menu"
                    >
                        <!-- Organization Switcher -->
                        {#if organizations.length > 0}
                            <div class="px-2">
                                <p
                                    class="mb-1 px-2 text-caption font-medium tracking-wider text-text-secondary uppercase"
                                >
                                    組織
                                </p>
                                {#if organizations.length > 5}
                                    <div class="mb-1 px-1">
                                        <input
                                            type="text"
                                            bind:value={orgSearchQuery}
                                            placeholder="組織を検索…"
                                            class="w-full rounded-lg border border-border-strong px-3 py-1.5 text-caption focus:ring-2 focus:ring-primary focus:outline-none"
                                            onclick={(e) => e.stopPropagation()}
                                        />
                                    </div>
                                {/if}
                                <div class="max-h-48 space-y-0.5 overflow-y-auto">
                                    {#each filteredOrganizations as org (org.id)}
                                        {#if org.id === currentOrganization?.id}
                                            <div
                                                class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-caption text-text"
                                                data-testid="org-current-{org.id}"
                                                aria-current="true"
                                            >
                                                <Check
                                                    class="size-4 shrink-0 text-primary"
                                                    aria-hidden="true"
                                                />
                                                <span class="flex-1 truncate">{org.name}</span>
                                            </div>
                                        {:else}
                                            <a
                                                href={organizationHref(org.slug)}
                                                onclick={closeUserMenu}
                                                data-testid="org-switch-{org.id}"
                                                class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-caption text-text transition-colors hover:bg-neutral"
                                            >
                                                <span class="size-4 shrink-0" aria-hidden="true"></span>
                                                <span class="flex-1 truncate">{org.name}</span>
                                            </a>
                                        {/if}
                                    {/each}
                                </div>
                            </div>
                            <div class="my-2 border-t border-border"></div>
                        {/if}

                        <SidebarUserMenu
                            {orgSettingsHref}
                            {cliHref}
                            {mcpHref}
                            {detailsOpen}
                            onToggleDetails={toggleDetails}
                            onNavigate={closeUserMenu}
                            onLogout={logout}
                            settingsTestId="nav-settings"
                            logoutTestId="logout-button"
                        />
                    </div>
                {/if}
            </div>
        </aside>

        <!-- Mobile Sidebar Overlay -->
        {#if mobileMenuOpen}
            <button
                type="button"
                class="fixed inset-0 z-40 cursor-default bg-text/50 lg:hidden"
                aria-label="メニューを閉じる"
                onclick={closeMobileMenu}
            ></button>
        {/if}

        <!-- Mobile Sidebar Drawer -->
        <aside
            class="fixed top-0 left-0 z-50 flex h-full w-64 flex-col border-r border-border bg-surface transition-transform duration-300 lg:hidden {mobileMenuOpen
                ? 'translate-x-0'
                : '-translate-x-full'}"
            data-testid="app-sidebar-mobile"
        >
            <div class="flex shrink-0 items-center justify-between border-b border-border px-4 py-4">
                <a href="/go" onclick={closeMobileMenu} class="text-h3 text-primary">
                    {appName}
                </a>
                <button
                    onclick={closeMobileMenu}
                    class="rounded-lg p-2 text-text-secondary transition-colors hover:bg-neutral hover:text-text"
                    type="button"
                    aria-label="メニューを閉じる"
                    data-testid="mobile-menu-close"
                >
                    <X class="size-5" aria-hidden="true" />
                </button>
            </div>

            <SidebarNavItems items={navItems} {isActive} showLabel onNavigate={closeMobileMenu} />

            <!-- Mobile: User / Organization Section (常時展開) -->
            <div class="min-h-0 overflow-y-auto border-t border-border px-2 py-3">
                <div class="mb-2 flex items-center gap-2 px-2">
                    <div
                        class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary text-surface"
                    >
                        <Building2 class="size-5" aria-hidden="true" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-caption font-medium text-text">{orgName}</p>
                        <p class="truncate text-caption text-text-secondary">{userName}</p>
                    </div>
                </div>

                {#if organizations.length > 0}
                    <p
                        class="mt-2 mb-1 px-2 text-caption font-medium tracking-wider text-text-secondary uppercase"
                    >
                        組織
                    </p>
                    <div class="max-h-40 space-y-0.5 overflow-y-auto">
                        {#each organizations as org (org.id)}
                            {#if org.id === currentOrganization?.id}
                                <div
                                    class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-caption text-text"
                                    data-testid="org-current-mobile-{org.id}"
                                    aria-current="true"
                                >
                                    <Check class="size-4 shrink-0 text-primary" aria-hidden="true" />
                                    <span class="flex-1 truncate">{org.name}</span>
                                </div>
                            {:else}
                                <a
                                    href={organizationHref(org.slug)}
                                    onclick={closeMobileMenu}
                                    data-testid="org-switch-mobile-{org.id}"
                                    class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-caption text-text transition-colors hover:bg-neutral"
                                >
                                    <span class="size-4 shrink-0" aria-hidden="true"></span>
                                    <span class="flex-1 truncate">{org.name}</span>
                                </a>
                            {/if}
                        {/each}
                    </div>
                {/if}

                <div class="mt-2 border-t border-border pt-2">
                    <SidebarUserMenu
                        {orgSettingsHref}
                        {cliHref}
                        {mcpHref}
                        {detailsOpen}
                        onToggleDetails={toggleDetails}
                        onNavigate={closeMobileMenu}
                        onLogout={logout}
                        settingsTestId="nav-settings-mobile"
                        logoutTestId="logout-button-mobile"
                        detailsToggleTestId="details-toggle-mobile"
                    />
                </div>
            </div>
        </aside>
    {/if}

    <!-- Main Content -->
    <main
        class="w-full flex-1 transition-all duration-300"
        style="--app-sidebar-w: {showAccountNav ? (sidebarOpen ? '256px' : '64px') : '0px'};"
    >
        <div class="transition-[margin-left] duration-300 lg:[margin-left:var(--app-sidebar-w)]">
            {#if showEmailBanner}
                <div class="px-4 pt-4 sm:px-6 lg:px-8">
                    <EmailVerificationBanner />
                </div>
            {/if}
            {#if showAccountNav && pendingInvitationCount > 0}
                <div class="px-4 pt-4 sm:px-6 lg:px-8">
                    {#if notificationsHref}
                        <PendingInvitationsNotice
                            pendingCount={pendingInvitationCount}
                            href={notificationsHref}
                        />
                    {/if}
                </div>
            {/if}
            <!-- padding は各ページの PageContainer が担う (aigenba parity, T071)。ここでは付けない。 -->
            <div data-testid="app-main">
                {@render children()}
            </div>
        </div>
    </main>
</div>
