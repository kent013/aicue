<script lang="ts">
    import type { Snippet } from "svelte";
    import { Menu, X } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";

    /**
     * 未認証公開ページ (LP / Pricing / Contact / Legal) 用レイアウト。
     * ヘッダーのナビとフッターのリンク群は snippet で差し込む。
     * nav は「単純なリンク群 (<a>)」を想定する契約: 広幅ナビと狭幅パネルで二重に
     * @render するため、状態を持つ要素・複雑な構造を snippet に入れないこと。
     */
    interface Props {
        appName: string;
        children: Snippet;
        nav?: Snippet;
        footerLinks?: Snippet;
    }

    let { appName, children, nav, footerLinks }: Props = $props();

    // 狭幅 (sm 未満) のハンバーガー開閉。sm 以上は広幅ナビ表示のため未使用。
    let menuOpen = $state(false);
    // Escape close 時のフォーカス復帰用にトグルボタン DOM を保持
    let toggleEl = $state<HTMLButtonElement>();

    function closeMenu(): void {
        menuOpen = false;
    }

    // Escape で閉じてトグルへフォーカスを戻す (open 時のみ作用)。
    // 入力要素起点 (input/textarea/contenteditable) の Escape は誤クローズ防止のため無視する
    // (nav は単純リンク群契約だが将来 snippet 逸脱に対する防御)。
    function handleKeydown(event: KeyboardEvent): void {
        // defaultPrevented: 他ハンドラが Escape を処理済みなら二重処理しない
        if (event.defaultPrevented || event.key !== "Escape" || !menuOpen) return;
        const target = event.target;
        if (
            target instanceof HTMLElement &&
            target.closest("input, textarea, [contenteditable='true']")
        ) {
            return;
        }
        closeMenu();
        toggleEl?.focus();
    }

    // パネル内リンク押下で閉じる。委譲は window 側で受ける (パネル <nav> 自体には
    // イベントリスナを付けず a11y_click_events_have_key_events を発生させない)。
    // リンクの Enter 押下も既定で click を発火するためキーボード操作でも閉じる。
    function handleWindowClick(event: MouseEvent): void {
        if (!menuOpen) return;
        const target = event.target;
        if (target instanceof Element && target.closest("#guest-nav-panel a")) closeMenu();
    }
</script>

<svelte:window onkeydown={handleKeydown} onclick={handleWindowClick} />

<div class="flex min-h-screen flex-col bg-neutral text-text">
    <header class="border-b border-border bg-surface">
        <div class="mx-auto flex max-w-5xl items-center justify-between px-8 py-4">
            <a href="/" class="text-h3 text-primary">{appName}</a>
            {#if nav}
                <!-- 広幅 (sm+) は横並びナビ。狭幅では非表示 -->
                <nav class="hidden items-center gap-4 text-body sm:flex">
                    {@render nav()}
                </nav>
                <!-- 狭幅 (sm 未満) はハンバーガー。sm+ では非表示 -->
                <Button
                    iconOnly
                    variant="ghost"
                    size="sm"
                    ariaLabel={menuOpen ? "メニューを閉じる" : "メニューを開く"}
                    ariaExpanded={menuOpen}
                    ariaControls="guest-nav-panel"
                    onclick={() => (menuOpen = !menuOpen)}
                    bind:element={toggleEl}
                    class="sm:hidden"
                    testId="guest-nav-toggle"
                >
                    {#if menuOpen}
                        <X class="size-5" aria-hidden="true" />
                    {:else}
                        <Menu class="size-5" aria-hidden="true" />
                    {/if}
                </Button>
            {/if}
        </div>
        <!-- 狭幅パネル: 開いているときだけ DOM に描画。sm+ では sm:hidden で必ず非表示。
             リンク押下での close は window の onclick 委譲で受ける (この <nav> にリスナを付けない) -->
        {#if nav && menuOpen}
            <nav
                id="guest-nav-panel"
                data-testid="guest-nav-panel"
                class="flex flex-col gap-2 border-t border-border px-8 py-4 text-body sm:hidden"
            >
                {@render nav()}
            </nav>
        {/if}
    </header>
    <main class="mx-auto w-full max-w-5xl flex-1 px-8 py-10">
        {@render children()}
    </main>
    <footer class="border-t border-border bg-surface">
        <div class="mx-auto flex max-w-5xl items-center justify-between px-8 py-4 text-caption text-text-secondary">
            <span>&copy; {new Date().getFullYear()} {appName}</span>
            {#if footerLinks}
                <div class="flex items-center gap-4">
                    {@render footerLinks()}
                </div>
            {/if}
        </div>
    </footer>
</div>
