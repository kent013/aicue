<script lang="ts">
    import type { Snippet } from "svelte";
    import { page } from "@inertiajs/svelte";
    import ToastContainer from "@/components/organisms/ToastContainer.svelte";
    import EmailVerificationBanner from "@/components/features/auth/EmailVerificationBanner.svelte";
    import NotificationBell from "@/components/molecules/NotificationBell.svelte";
    import { consumeFlash, type FlashPayload } from "@/lib/stores/flash-to-toast";
    import type { NotificationSharedProps } from "@/types/notification";

    /**
     * 認証済み画面用レイアウト (最小骨格)。
     * Phase 2 (組織・Team・Project 導入) でサイドバー・組織切替・通知センターを拡張する。
     * Laravel flash は consumeFlash で toast に変換する (visitKey で de-dup)。
     */
    interface Props {
        appName: string;
        children: Snippet;
        /** ヘッダー右側 (ユーザーメニュー等) */
        headerActions?: Snippet;
    }

    let { appName, children, headerActions }: Props = $props();

    $effect(() => {
        consumeFlash(page.props.flash as FlashPayload | undefined);
    });

    // メール未認証のソフトゲート案内 (organizations.store / invitations.store は
    // verified.or-back で back + error flash になるため、常設バナーで導線を先出しする)。
    const auth = $derived(page.props.auth as { user?: { emailVerified?: boolean } | null } | undefined);
    const showEmailBanner = $derived(auth?.user != null && auth.user.emailVerified === false);

    // 通知センターの未読数 (shared props)。ログイン時のみベルを常設する
    const notifications = $derived(
        page.props.notifications as NotificationSharedProps | undefined,
    );
    const showBell = $derived(auth?.user != null);
</script>

<ToastContainer />

<div class="flex min-h-screen flex-col bg-neutral text-text">
    <header class="border-b border-border bg-surface">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-8 py-3">
            <a href="/dashboard" class="text-h3 text-primary">{appName}</a>
            <div class="flex items-center gap-3">
                {#if showBell}
                    <NotificationBell unreadCount={notifications?.unreadCount ?? 0} />
                {/if}
                {#if headerActions}
                    {@render headerActions()}
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
