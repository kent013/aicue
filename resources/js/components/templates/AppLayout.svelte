<script lang="ts">
    import type { Snippet } from "svelte";
    import { page, router } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import EmailVerificationBanner from "@/components/features/auth/EmailVerificationBanner.svelte";
    import OrganizationSwitcher from "@/components/features/organizations/OrganizationSwitcher.svelte";
    import NotificationBell from "@/components/molecules/NotificationBell.svelte";
    import ToastContainer from "@/components/organisms/ToastContainer.svelte";
    import type { SharedProps } from "@/lib/shared-props";
    import { consumeFlash } from "@/lib/stores/flash-to-toast";

    /**
     * 認証済み画面用レイアウト (最小骨格)。
     * 組織スイッチャー/組織メニューを常設 (組織切替・組織設定/請求/招待/API キー導線)。
     * サイドバー/Team/Project ナビは後続 Phase。
     * Laravel flash は consumeFlash で toast に変換する (visitKey で de-dup)。
     * ログイン中は通知ベル・設定・ログアウトを全ページ常設する (F-08: ナビ統一)。
     * ログアウト POST はこのレイアウトの単一ハンドラに一本化する (ページ側に実装を残さない)。
     */
    interface Props {
        appName: string;
        children: Snippet;
        /** ヘッダー右側のページ固有の追加アクション (常設ナビの左に並ぶ) */
        headerActions?: Snippet;
    }

    let { appName, children, headerActions }: Props = $props();

    // shared props は backend (HandleInertiaRequests) が真実。lib/shared-props.ts の型で読む
    const shared = $derived(page.props as unknown as SharedProps);

    $effect(() => {
        consumeFlash(shared.flash);
    });

    // メール未認証のソフトゲート案内 (organizations.store / invitations.store は
    // verified.or-back で back + error flash になるため、常設バナーで導線を先出しする)。
    const showEmailBanner = $derived(
        shared.auth?.user != null && shared.auth.user.emailVerified === false,
    );

    // ログイン時のみベル + アカウントナビ (設定/ログアウト) を常設する
    // (invitations.accept 等、ゲスト到達がある AppLayout ページでは出さない)
    const showAccountNav = $derived(shared.auth?.user != null);

    let loggingOut = $state(false);

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
</script>

<ToastContainer />

<div class="flex min-h-screen flex-col bg-neutral text-text">
    <header class="border-b border-border bg-surface">
        <!-- 375px 方針: ロゴは shrink-0、右側アクション群は flex-wrap で行内折り返し (2 段化) -->
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-8 py-3">
            <a href="/dashboard" class="shrink-0 text-h3 text-primary">{appName}</a>
            <div class="flex flex-wrap items-center justify-end gap-x-3 gap-y-1">
                {#if headerActions}
                    {@render headerActions()}
                {/if}
                {#if showAccountNav}
                    <OrganizationSwitcher
                        currentOrganization={shared.currentOrganization ?? null}
                        organizations={shared.organizations ?? []}
                    />
                    <NotificationBell unreadCount={shared.notifications?.unreadCount ?? 0} />
                    <TextLink href="/settings" testId="nav-settings">設定</TextLink>
                    <Button
                        variant="ghost"
                        size="sm"
                        onclick={logout}
                        loading={loggingOut}
                        testId="nav-logout"
                    >
                        ログアウト
                    </Button>
                {/if}
            </div>
        </div>
    </header>
    <main class="mx-auto w-full max-w-6xl flex-1 px-8 py-8">
        {#if showEmailBanner}
            <div class="mb-6">
                <EmailVerificationBanner />
            </div>
        {/if}
        {@render children()}
    </main>
</div>
