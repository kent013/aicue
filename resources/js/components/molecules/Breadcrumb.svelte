<script lang="ts">
    import { ChevronRight } from "@lucide/svelte";
    import type { BreadcrumbItem } from "@/types/components";

    /**
     * パンくず navigation molecule (aigenba 準拠)。atom 非依存 (@lucide/svelte の ChevronRight のみ)。
     * href 省略の項目は現在位置としてリンクにしない。
     */
    interface Props {
        items: BreadcrumbItem[];
    }

    let { items }: Props = $props();
</script>

<nav class="flex" aria-label="Breadcrumb">
    <ol class="flex items-center">
        {#each items as item, index (item.href ?? item.label)}
            <li class="flex items-center">
                {#if index > 0}
                    <ChevronRight class="mx-1 size-4 text-text-secondary" aria-hidden="true" />
                {/if}
                {#if item.href}
                    <a href={item.href} class="text-caption text-text-secondary hover:text-primary">
                        {item.label}
                    </a>
                {:else}
                    <span class="text-caption text-text">{item.label}</span>
                {/if}
            </li>
        {/each}
    </ol>
</nav>
