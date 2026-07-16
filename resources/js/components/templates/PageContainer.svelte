<script lang="ts">
    import type { Snippet } from "svelte";

    /**
     * PageContainer — page 内側の薄い padding wrapper (layout primitive, aigenba 準拠)。
     * 認証ページの外周 padding を担う (AppLayout <main> は padding を持たない)。
     * padding=false は PageHeaderSection の負マージン全幅バー契約を壊すため、認証ページからは使わない
     * (Architecture テスト page-shell-structure が padding={false} を禁止)。
     */
    interface Props {
        padding?: boolean;
        children?: Snippet;
    }

    let { padding = true, children }: Props = $props();
</script>

<div class="w-full {padding ? 'px-4 py-8 sm:px-6 lg:px-8' : ''}">
    {#if children}
        {@render children()}
    {/if}
</div>
