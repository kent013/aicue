<script lang="ts">
    import { page, router } from "@inertiajs/svelte";
    import { Camera, Search } from "@lucide/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Checkbox from "@/components/atoms/Checkbox.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Select from "@/components/atoms/Select.svelte";
    import ManualCoverThumbnail from "@/components/features/capture/ManualCoverThumbnail.svelte";
    import EmptyState from "@/components/molecules/EmptyState.svelte";
    import PageHeader from "@/components/molecules/PageHeader.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import { takeUrl } from "@/lib/capture/take-endpoints";
    import { formatDate } from "@/lib/date-format";
    import type { SharedProps } from "@/lib/shared-props";
    import type { CaptureManualSummary } from "@/types/capture";
    import {
        CAPTURE_PROGRESS_LABELS,
        CAPTURE_PROGRESS_TONES,
        captureProgressOf,
    } from "@/types/capture";

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

    /**
     * 代表サムネイルの URL。cover が null なら null (= プレースホルダ)。
     * **判断材料は cover の null 判定だけ**で、権限も状態もサーバ側で解決済みである。
     */
    function coverUrl(manual: CaptureManualSummary): string | null {
        if (manual.cover === null) return null;
        return takeUrl(
            { projectId: project.id, manualId: manual.id, cutId: manual.cover.cut_id },
            manual.cover.take_id,
            "/thumbnail",
        );
    }
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeader
            title="撮影するマニュアルを選ぶ"
            description={project.name}
            icon={Camera}
            testId="capture-heading"
        />
        <PageContent>
            <div class="flex flex-col gap-2 sm:flex-row">
                <form
                    novalidate
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
                    <!-- 撮影進捗は PC 一覧の制作状態 (ManualProgress) とは別の量。
                         導出は captureProgressOf ただ 1 か所に置く -->
                    {@const captureProgress = captureProgressOf(manual)}
                    <a href={`/app/projects/${project.id}/manuals/${manual.id}`} class="block">
                        <Card>
                            <!-- 3 列 grid: サムネイルは固有幅・本文だけが縮んで truncate・
                                 バッジは潰れない (minmax(0,1fr) が本文列に最小幅 0 を与える) -->
                            <div
                                class="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3"
                            >
                                <ManualCoverThumbnail
                                    src={coverUrl(manual)}
                                    testId={`capture-cover-${manual.id}`}
                                />
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
                                    <Badge tone={CAPTURE_PROGRESS_TONES[captureProgress]}>
                                        {CAPTURE_PROGRESS_LABELS[captureProgress]}
                                    </Badge>
                                </div>
                            </div>
                        </Card>
                    </a>
                {/each}
            </div>
        </PageContent>
    </PageContainer>
</AppLayout>
