<script lang="ts">
    import type { Snippet } from "svelte";

    /**
     * 未認証公開ページ (LP / Pricing / Contact / Legal) 用レイアウト。
     * ヘッダーのナビとフッターのリンク群は snippet で差し込む。
     */
    interface Props {
        appName: string;
        children: Snippet;
        nav?: Snippet;
        footerLinks?: Snippet;
    }

    let { appName, children, nav, footerLinks }: Props = $props();
</script>

<div class="flex min-h-screen flex-col bg-neutral text-text">
    <header class="border-b border-border bg-surface">
        <div class="mx-auto flex max-w-5xl items-center justify-between px-8 py-4">
            <a href="/" class="text-h3 text-primary">{appName}</a>
            {#if nav}
                <nav class="flex items-center gap-4 text-body">
                    {@render nav()}
                </nav>
            {/if}
        </div>
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
