<script lang="ts">
    /**
     * SidebarUserMenu — AppLayout の desktop popup / mobile section で重複する
     * 「個人設定 / 組織設定 / CLI / MCP + 詳細(法務) + ログアウト」の stateless 表示 helper
     * (aigenba の同名 helper を AI-CUE の実ルートへ翻訳)。
     *
     * state/handler は AppLayout 残置、本 helper は props のみで描画。org-scoped href は親が
     * null を渡せば非表示 = サイドバーから 403 導線を作らない (helper 側 {#if href} ガードが契約)。
     */
    import {
        Settings,
        Terminal,
        Plug,
        ChevronRight,
        FileText,
        Shield,
        Scale,
        LogOut,
    } from "@lucide/svelte";

    interface Props {
        /** 組織設定 href (currentOrganization なし時 null) */
        orgSettingsHref: string | null;
        /** CLI セットアップ href (currentOrganization なし時 null) */
        cliHref: string | null;
        /** MCP セットアップ href (currentOrganization なし時 null) */
        mcpHref: string | null;
        detailsOpen: boolean;
        onToggleDetails: () => void;
        /** link クリック時の close 処理 (desktop closeUserMenu / mobile closeMobileMenu) */
        onNavigate: () => void;
        onLogout: () => void;
        /** 個人設定 link の testId (desktop 'nav-settings' / mobile 'nav-settings-mobile') */
        settingsTestId: string;
        /** ログアウト button の testId (desktop 'logout-button' / mobile 'logout-button-mobile') */
        logoutTestId?: string;
        /** 詳細トグル button の testId (mobile 'details-toggle-mobile' / desktop undefined) */
        detailsToggleTestId?: string;
    }

    let {
        orgSettingsHref,
        cliHref,
        mcpHref,
        detailsOpen,
        onToggleDetails,
        onNavigate,
        onLogout,
        settingsTestId,
        logoutTestId,
        detailsToggleTestId,
    }: Props = $props();

    const linkClass =
        "flex items-center gap-2 rounded-lg px-2 py-2 text-caption text-text transition-colors hover:bg-neutral";
</script>

<!-- Primary -->
<div class="space-y-0.5 px-2">
    <a href="/settings" onclick={onNavigate} data-testid={settingsTestId} class={linkClass}>
        <Settings class="size-4 shrink-0" aria-hidden="true" />
        <span>個人設定</span>
    </a>
    {#if orgSettingsHref}
        <a href={orgSettingsHref} onclick={onNavigate} class={linkClass}>
            <Settings class="size-4 shrink-0" aria-hidden="true" />
            <span>組織設定</span>
        </a>
    {/if}
    {#if cliHref}
        <a href={cliHref} onclick={onNavigate} class={linkClass}>
            <Terminal class="size-4 shrink-0" aria-hidden="true" />
            <span>CLI セットアップ</span>
        </a>
    {/if}
    {#if mcpHref}
        <a href={mcpHref} onclick={onNavigate} class={linkClass}>
            <Plug class="size-4 shrink-0" aria-hidden="true" />
            <span>MCP セットアップ</span>
        </a>
    {/if}
</div>

<div class="my-2 border-t border-border"></div>

<!-- Details (collapsible): 法務リンク (public, 認証済み user menu 内で常時表示) -->
<div class="px-2">
    <button
        type="button"
        onclick={onToggleDetails}
        aria-expanded={detailsOpen}
        data-testid={detailsToggleTestId}
        class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-caption text-text-secondary transition-colors hover:bg-neutral"
    >
        <ChevronRight
            class="size-3.5 shrink-0 text-text-secondary transition-transform {detailsOpen ? 'rotate-90' : ''}"
            aria-hidden="true"
        />
        <span>詳細</span>
    </button>
    {#if detailsOpen}
        <div class="ml-4 space-y-0.5">
            <a href="/terms" onclick={onNavigate} class={linkClass}>
                <FileText class="size-4 shrink-0" aria-hidden="true" />
                <span>利用規約</span>
            </a>
            <a href="/privacy" onclick={onNavigate} class={linkClass}>
                <Shield class="size-4 shrink-0" aria-hidden="true" />
                <span>プライバシーポリシー</span>
            </a>
            <a href="/commerce-disclosure" onclick={onNavigate} class={linkClass}>
                <Scale class="size-4 shrink-0" aria-hidden="true" />
                <span>特定商取引法に基づく表記</span>
            </a>
        </div>
    {/if}
</div>

<div class="my-2 border-t border-border"></div>

<!-- Logout -->
<div class="px-2">
    <button
        type="button"
        onclick={onLogout}
        data-testid={logoutTestId}
        class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-caption text-danger transition-colors hover:bg-danger/10"
    >
        <LogOut class="size-4 shrink-0" aria-hidden="true" />
        <span>ログアウト</span>
    </button>
</div>
