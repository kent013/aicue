<script lang="ts">
    import type { Component } from "svelte";
    import Card from "@/components/atoms/Card.svelte";

    /**
     * 統計カード molecule。Card atom に label(caption) + value(h2) + 任意の
     * subtext / Lucide icon を載せる最小 API。数値の強調は weight ではなく
     * ramp 昇格 (text-h2) で表現する (DESIGN.md §Typography)。
     */
    interface Props {
        label: string;
        value: string | number;
        /** 補足文 (例: 前月比)。省略時は非描画 */
        subtext?: string;
        /** Lucide アイコン component (@lucide/svelte)。装飾なので aria-hidden */
        icon?: Component;
        class?: string;
        testId?: string;
    }

    let { label, value, subtext, icon, class: extraClass = "", testId }: Props = $props();

    // markup で component として描画するため大文字始まりの別名に束ねる
    const Icon = $derived(icon);
</script>

<Card padding="md" class={extraClass} {testId}>
    <div class="flex items-center gap-4">
        {#if Icon}
            <div
                class="flex size-10 shrink-0 items-center justify-center rounded-md bg-primary-soft"
            >
                <Icon class="size-5 text-primary" aria-hidden="true" />
            </div>
        {/if}
        <div class="min-w-0">
            <p class="text-caption text-text-secondary">{label}</p>
            <p class="text-h2 text-text">{value}</p>
            {#if subtext}
                <p class="mt-1 text-caption text-text-secondary">{subtext}</p>
            {/if}
        </div>
    </div>
</Card>
