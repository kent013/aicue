<script lang="ts" module>
    import type { Component } from "svelte";

    /** サイドバー nav item の型 (AppLayout の navItems はこれに一致させる)。 */
    export interface SidebarNavItem {
        href: string;
        label: string;
        icon: Component;
    }
</script>

<script lang="ts">
    /**
     * SidebarNavItems — AppLayout の desktop/mobile sidebar で重複する nav item list の
     * stateless 表示 helper。state/handler/ref は AppLayout 残置、本 helper は props のみで描画する
     * (aigenba の同名 helper を AI-CUE の型/DS へ移植)。
     */
    interface Props {
        items: SidebarNavItem[];
        /** active 判定 (AppLayout の isActive をそのまま渡す) */
        isActive: (href: string) => boolean;
        /** label 表示 (desktop collapsed 時 false、mobile 常時 true) */
        showLabel?: boolean;
        /** nav クリック時の追加処理 (mobile drawer close 等。desktop は未指定 = no-op) */
        onNavigate?: () => void;
    }

    let { items, isActive, showLabel = true, onNavigate }: Props = $props();
</script>

<nav class="flex-1 space-y-1 overflow-y-auto px-2 py-4">
    {#each items as item (item.href)}
        {@const Icon = item.icon}
        <a
            href={item.href}
            onclick={() => onNavigate?.()}
            class="flex items-center gap-3 rounded-lg px-3 py-3 transition-colors {isActive(item.href)
                ? 'bg-primary text-surface'
                : 'text-text hover:bg-neutral'}"
            title={item.label}
            data-testid="nav-item-{item.href}"
        >
            <Icon class="size-5 shrink-0" aria-hidden="true" />
            {#if showLabel}
                <span class="truncate text-caption font-medium">{item.label}</span>
            {/if}
        </a>
    {/each}
</nav>
