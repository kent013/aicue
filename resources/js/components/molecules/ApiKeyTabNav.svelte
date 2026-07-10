<script lang="ts">
    import { Link } from "@inertiajs/svelte";

    /**
     * API キー管理ドメインのページ間 (API キー ⇔ 接続セッション ⇔ 導入ガイド) を
     * URL 遷移で切替えるタブナビ。同一ページ内 section 切替の molecules/Tabs.svelte とは
     * 責務が異なる (こちらは Inertia 遷移)。
     *
     * tabs は汎用配列型 (label + href + active) で受け取り、ページ側が組み立てる
     * (どのタブを出すか・URL は呼び出し側責務)。
     */
    interface TabItem {
        label: string;
        href: string;
        active: boolean;
        testId?: string;
    }

    let { tabs }: { tabs: TabItem[] } = $props();
</script>

<nav class="flex border-b border-border" aria-label="API キー / 接続セッション切替">
    {#each tabs as tab (tab.href)}
        <Link
            href={tab.href}
            data-testid={tab.testId}
            aria-current={tab.active ? "page" : undefined}
            class="border-b-2 px-4 py-2 text-body font-medium transition-colors duration-150
                focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none
                {tab.active
                ? 'border-primary text-primary'
                : 'border-transparent text-text-secondary hover:text-text'}"
        >
            {tab.label}
        </Link>
    {/each}
</nav>
