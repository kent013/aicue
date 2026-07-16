<script lang="ts">
    import { page, router } from "@inertiajs/svelte";
    import { Camera, Search } from "@lucide/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Checkbox from "@/components/atoms/Checkbox.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Select from "@/components/atoms/Select.svelte";
    import EmptyState from "@/components/molecules/EmptyState.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import { formatDate } from "@/lib/date-format";
    import type { SharedProps } from "@/lib/shared-props";
    import type { CaptureManualSummary } from "@/types/capture";

    /**
     * 撮影 PWA: シナリオ (動画マニュアル) 一覧。カテゴリ / キーワードで絞り込み、
     * 行タップで撮影ナビ (Capture/Show) へ。撮影対象は ready/published のみ (サーバ側で絞り込み済み)。
     */
    interface Props {
        project: { id: number; name: string };
        manuals: CaptureManualSummary[];
        categories: { id: number; name: string }[];
        filters: { category: number | null; q: string | null; mine: boolean };
    }

    let { project, manuals, categories, filters }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    let search = $state(filters.q ?? "");
    let categoryId = $state(filters.category === null ? "" : String(filters.category));
    let mine = $state(filters.mine);

    function applyFilters(): void {
        const query: Record<string, string> = {};
        if (search !== "") query.q = search;
        if (categoryId !== "") query.category = categoryId;
        if (mine) query.mine = "1";
        router.get(`/app/projects/${project.id}/manuals`, query, {
            preserveState: true,
            preserveScroll: true,
        });
    }
</script>

<AppLayout {appName}>
    <PageContent maxWidth="7xl">
        <h1 class="text-h2">撮影するマニュアルを選ぶ</h1>
        <p class="mt-1 text-caption text-text-secondary">{project.name}</p>

        <div class="mt-4 flex flex-col gap-2 sm:flex-row">
            <form
                class="flex min-w-0 flex-1 items-center gap-2"
                onsubmit={(event) => {
                    event.preventDefault();
                    applyFilters();
                }}
            >
                <Input
                    type="search"
                    bind:value={search}
                    placeholder="タイトルで検索"
                    testId="capture-search"
                />
                <button type="submit" class="shrink-0 text-text-secondary" aria-label="検索">
                    <Search class="size-5" aria-hidden="true" />
                </button>
            </form>
            <div class="sm:w-56">
                <Select bind:value={categoryId} onchange={applyFilters} testId="capture-category">
                    <option value="">すべてのカテゴリ</option>
                    {#each categories as category (category.id)}
                        <option value={String(category.id)}>{category.name}</option>
                    {/each}
                </Select>
            </div>
        </div>

        <div class="mt-3">
            <Checkbox
                id="capture-mine"
                bind:checked={mine}
                label="自分が作ったシナリオ"
                onchange={applyFilters}
                testId="capture-mine"
            />
        </div>

        <div class="mt-4 flex flex-col gap-3" data-testid="capture-manual-list">
            {#if manuals.length === 0}
                <EmptyState
                    icon={Camera}
                    title="撮影できるマニュアルがありません"
                    description="シナリオが確定 (ready) になると、ここに表示されます。"
                />
            {/if}
            {#each manuals as manual (manual.id)}
                <a href={`/app/projects/${project.id}/manuals/${manual.id}`} class="block">
                    <Card>
                        <div class="flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-body font-medium">{manual.title}</p>
                                <p class="mt-1 text-caption text-text-secondary">
                                    {manual.category_name ?? "未分類"}
                                    ・カット {manual.cuts_total} / 採用済 {manual.cuts_adopted}
                                </p>
                                <p class="mt-0.5 text-caption text-text-secondary">
                                    {manual.creator_name ?? "不明"} ・ 更新 {formatDate(
                                        manual.updated_at,
                                    )}
                                </p>
                            </div>
                            <div class="shrink-0">
                                {#if manual.cuts_total > 0 && manual.cuts_adopted === manual.cuts_total}
                                    <Badge tone="success">撮影完了</Badge>
                                {:else if manual.cuts_with_takes > 0}
                                    <Badge tone="tertiary">撮影中</Badge>
                                {:else}
                                    <Badge tone="neutral">未撮影</Badge>
                                {/if}
                            </div>
                        </div>
                    </Card>
                </a>
            {/each}
        </div>
    </PageContent>
</AppLayout>
