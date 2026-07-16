<script lang="ts">
    import type { Component, Snippet } from "svelte";
    import type { BreadcrumbItem } from "@/types/components";
    import Breadcrumb from "./Breadcrumb.svelte";

    /**
     * 詳細画面用 PageHeader (breadcrumbs / description / icon / actions(children) を持つ full feature、
     * aigenba 準拠)。root 画面 shorthand は PageHeader.svelte 経由。
     * サイドバーのロゴブロックと同じ 73px 高。全幅バーは PageContainer padding を前提とした負マージン契約。
     * actions は children Snippet で渡す (旧 slot API は使わない)。icon は lucide 互換の Component。
     */
    interface Props {
        title: string;
        breadcrumbs?: BreadcrumbItem[];
        description?: string;
        icon?: Component;
        testId?: string;
        children?: Snippet;
    }

    let { title, breadcrumbs = [], description, icon, testId, children }: Props = $props();

    // ルート画面 (パンくずが 1 件のみ) はパンくずを出さない (h1 と二重提示になるため)。
    const showBreadcrumbs = $derived(breadcrumbs.length > 1);
</script>

<div
    class="-mx-4 -mt-8 border-b border-border bg-surface px-4 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8"
>
    <div class="py-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex min-w-0 items-center gap-3">
                {#if icon}
                    {@const Icon = icon}
                    <Icon class="size-10 shrink-0 text-primary" aria-hidden="true" />
                {/if}
                <h1 class="truncate text-h2 text-text" data-testid={testId}>{title}</h1>
            </div>
            {#if children}
                <div class="flex min-w-0 shrink flex-wrap justify-end gap-2">
                    {@render children()}
                </div>
            {/if}
        </div>
    </div>
</div>

{#if showBreadcrumbs || description}
    <div
        class="-mx-4 mb-6 border-b border-border bg-surface px-4 py-2 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8"
    >
        {#if showBreadcrumbs}
            <Breadcrumb items={breadcrumbs} />
        {/if}
        {#if description}
            <p class="truncate text-caption text-text-secondary">{description}</p>
        {/if}
    </div>
{:else}
    <div class="mb-8"></div>
{/if}
