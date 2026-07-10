<script lang="ts">
    import { page, router, useForm } from "@inertiajs/svelte";
    import { ChevronDown, ChevronUp } from "@lucide/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Select from "@/components/atoms/Select.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import Textarea from "@/components/atoms/Textarea.svelte";
    import DangerZone from "@/components/molecules/DangerZone.svelte";
    import EmptyState from "@/components/molecules/EmptyState.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import Pagination from "@/components/molecules/Pagination.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import Modal from "@/components/organisms/Modal.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import type { SharedProps } from "@/lib/shared-props";
    import type {
        CategoryOption,
        ManualFilters,
        ManualListItem,
        PaginationMeta,
        VideoManualStatus,
    } from "@/types/manual";
    import { VIDEO_MANUAL_STATUS_LABELS } from "@/types/manual";

    /**
     * プロジェクト詳細。動画マニュアル一覧 (フィルタ + paginate)・カテゴリ管理・
     * Item 一覧を内包する (それぞれ専用 Index ページは持たない)。
     */
    interface Item {
        id: number;
        name: string;
        note: string | null;
    }

    interface Props {
        project: { id: number; name: string; description: string | null };
        items: Item[];
        canManage: boolean;
        manuals: { data: ManualListItem[]; meta: PaginationMeta };
        categories: CategoryOption[];
        manualFilters: ManualFilters;
    }

    let { project, items, canManage, manuals, categories, manualFilters }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    /* ---- 動画マニュアル: 状態バッジの tone (結果表示の意味色) ---- */
    const STATUS_TONES: Record<
        VideoManualStatus,
        "primary" | "tertiary" | "success" | "warning" | "neutral"
    > = {
        draft: "neutral",
        analyzing: "tertiary",
        ready: "success",
        rendering: "warning",
        published: "primary",
    };

    /* ---- 動画マニュアル: フィルタ (GET クエリで manuals のみ部分更新) ---- */
    let filterCategory = $state(manualFilters.category ?? "");
    let filterStatus = $state(manualFilters.status ?? "");
    let filterQ = $state(manualFilters.q ?? "");

    function manualQuery(pageNumber?: number): Record<string, string | number> {
        const query: Record<string, string | number> = {};
        if (filterCategory !== "") query.category = filterCategory;
        if (filterStatus !== "") query.status = filterStatus;
        if (filterQ.trim() !== "") query.q = filterQ.trim();
        if (pageNumber !== undefined && pageNumber > 1) query.page = pageNumber;
        return query;
    }

    function applyManualFilters(event?: SubmitEvent): void {
        event?.preventDefault();
        router.get(`/projects/${project.id}`, manualQuery(), {
            preserveState: true,
            preserveScroll: true,
            only: ["manuals", "manualFilters"],
        });
    }

    function changeManualPage(pageNumber: number): void {
        router.get(`/projects/${project.id}`, manualQuery(pageNumber), {
            preserveState: true,
            preserveScroll: true,
            only: ["manuals", "manualFilters"],
        });
    }

    /* ---- カテゴリ管理 ---- */
    const addCategoryForm = useForm({ name: "" });

    function submitAddCategory(event: SubmitEvent): void {
        event.preventDefault();
        addCategoryForm.post(`/projects/${project.id}/categories`, {
            preserveScroll: true,
            onSuccess: () => {
                addCategoryForm.reset();
            },
        });
    }

    let editCategoryTarget = $state<CategoryOption | null>(null);
    let editCategoryModalOpen = $state(false);
    const editCategoryForm = useForm({ name: "" });

    function openEditCategoryModal(category: CategoryOption): void {
        editCategoryTarget = category;
        editCategoryForm.name = category.name;
        editCategoryForm.clearErrors();
        editCategoryModalOpen = true;
    }

    function submitEditCategory(event: SubmitEvent): void {
        event.preventDefault();
        if (editCategoryTarget === null) return;
        editCategoryForm.patch(`/projects/${project.id}/categories/${editCategoryTarget.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                editCategoryModalOpen = false;
            },
        });
    }

    let removeCategoryTarget = $state<CategoryOption | null>(null);
    let removeCategoryDialogOpen = $state(false);
    let removingCategory = $state(false);

    function openRemoveCategoryDialog(category: CategoryOption): void {
        removeCategoryTarget = category;
        removeCategoryDialogOpen = true;
    }

    function removeCategory(): void {
        if (removeCategoryTarget === null) return;
        router.delete(`/projects/${project.id}/categories/${removeCategoryTarget.id}`, {
            preserveScroll: true,
            onStart: () => {
                removingCategory = true;
            },
            onFinish: () => {
                removingCategory = false;
                removeCategoryDialogOpen = false;
            },
        });
    }

    /** 並べ替え: index の要素を direction (±1) 方向へ入れ替えた id 順序配列を送る */
    function moveCategory(index: number, direction: -1 | 1): void {
        const target = index + direction;
        if (target < 0 || target >= categories.length) return;
        const order = categories.map((category) => category.id);
        [order[index], order[target]] = [order[target], order[index]];
        router.patch(
            `/projects/${project.id}/categories/reorder`,
            { order },
            { preserveScroll: true },
        );
    }

    /* ---- Item 追加 ---- */
    const addForm = useForm({ name: "", note: "" });

    function submitAdd(event: SubmitEvent): void {
        event.preventDefault();
        addForm.post(`/projects/${project.id}/items`, {
            preserveScroll: true,
            onSuccess: () => {
                addForm.reset();
            },
        });
    }

    /* ---- Item 編集 (Modal) ---- */
    let editTarget = $state<Item | null>(null);
    let editModalOpen = $state(false);
    const editForm = useForm({ name: "", note: "" });

    function openEditModal(item: Item): void {
        editTarget = item;
        editForm.name = item.name;
        editForm.note = item.note ?? "";
        editForm.clearErrors();
        editModalOpen = true;
    }

    function submitEdit(event: SubmitEvent): void {
        event.preventDefault();
        if (editTarget === null) return;
        editForm.patch(`/projects/${project.id}/items/${editTarget.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                editModalOpen = false;
            },
        });
    }

    /* ---- Item 削除 ---- */
    let removeTarget = $state<Item | null>(null);
    let removeDialogOpen = $state(false);
    let removing = $state(false);

    function openRemoveDialog(item: Item): void {
        removeTarget = item;
        removeDialogOpen = true;
    }

    function removeItem(): void {
        if (removeTarget === null) return;
        router.delete(`/projects/${project.id}/items/${removeTarget.id}`, {
            preserveScroll: true,
            onStart: () => {
                removing = true;
            },
            onFinish: () => {
                removing = false;
                removeDialogOpen = false;
            },
        });
    }

    /* ---- プロジェクト削除 ---- */
    let deleteProjectDialogOpen = $state(false);
    let deletingProject = $state(false);

    function deleteProject(): void {
        router.delete(`/projects/${project.id}`, {
            onStart: () => {
                deletingProject = true;
            },
            onFinish: () => {
                deletingProject = false;
                deleteProjectDialogOpen = false;
            },
        });
    }
</script>

<AppLayout {appName}>
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="truncate text-h2">{project.name}</h1>
            {#if project.description}
                <p class="mt-1 text-body text-text-secondary">{project.description}</p>
            {/if}
        </div>
        {#if canManage}
            <Button
                variant="ghost"
                href={`/projects/${project.id}/edit`}
                inertia
                testId="edit-project-button"
            >
                編集
            </Button>
        {/if}
    </div>

    <div class="mt-6 flex max-w-2xl flex-col gap-10">
        <Card padding="lg">
            <div class="flex items-start justify-between gap-4">
                <h2 class="text-h3">動画マニュアル</h2>
                {#if canManage}
                    <Button
                        href={`/projects/${project.id}/manuals/create`}
                        inertia
                        testId="create-manual-button"
                    >
                        新規作成
                    </Button>
                {/if}
            </div>

            <!-- フィルタ (カテゴリ / 状態 / キーワード)。GET クエリで manuals のみ部分更新する -->
            <form
                onsubmit={applyManualFilters}
                class="mt-4 flex flex-wrap items-end gap-3"
                data-testid="manual-filter-form"
            >
                <div class="flex flex-col gap-1">
                    <label class="text-caption text-text-secondary" for="manual-filter-category">
                        カテゴリ
                    </label>
                    <Select
                        id="manual-filter-category"
                        bind:value={filterCategory}
                        onchange={() => applyManualFilters()}
                        testId="manual-filter-category"
                    >
                        <option value="">すべて</option>
                        <option value="uncategorized">未分類</option>
                        {#each categories as category (category.id)}
                            <option value={String(category.id)}>{category.name}</option>
                        {/each}
                    </Select>
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-caption text-text-secondary" for="manual-filter-status">
                        状態
                    </label>
                    <Select
                        id="manual-filter-status"
                        bind:value={filterStatus}
                        onchange={() => applyManualFilters()}
                        testId="manual-filter-status"
                    >
                        <option value="">すべて</option>
                        {#each Object.entries(VIDEO_MANUAL_STATUS_LABELS) as [value, label] (value)}
                            <option {value}>{label}</option>
                        {/each}
                    </Select>
                </div>
                <div class="flex min-w-40 grow flex-col gap-1">
                    <label class="text-caption text-text-secondary" for="manual-filter-q">
                        キーワード
                    </label>
                    <Input
                        id="manual-filter-q"
                        type="search"
                        bind:value={filterQ}
                        testId="manual-filter-q"
                    />
                </div>
                <Button type="submit" variant="ghost" testId="manual-filter-submit">検索</Button>
            </form>

            {#if manuals.data.length === 0}
                <EmptyState
                    title="動画マニュアルはまだありません"
                    description="SOP を起点に動画マニュアルを作成すると、ここに表示されます。"
                    testId="manuals-empty"
                />
            {:else}
                <ul class="mt-4 flex flex-col divide-y divide-border" data-testid="manual-list">
                    {#each manuals.data as manual (manual.id)}
                        <li class="flex items-center justify-between gap-4 py-3">
                            <div class="min-w-0">
                                <TextLink
                                    href={`/projects/${project.id}/manuals/${manual.id}`}
                                    testId={`manual-link-${manual.id}`}
                                >
                                    {manual.title}
                                </TextLink>
                                <p class="mt-1 text-caption text-text-secondary">
                                    {manual.category?.name ?? "未分類"} ・ {manual.created_at}
                                </p>
                            </div>
                            <Badge
                                tone={STATUS_TONES[manual.status]}
                                testId={`manual-status-${manual.id}`}
                            >
                                {VIDEO_MANUAL_STATUS_LABELS[manual.status]}
                            </Badge>
                        </li>
                    {/each}
                </ul>
                <div class="mt-4">
                    <Pagination
                        currentPage={manuals.meta.current_page}
                        totalPages={manuals.meta.last_page}
                        onChange={changeManualPage}
                        testId="manuals-pagination"
                    />
                </div>
            {/if}
        </Card>

        {#if canManage}
            <Card padding="lg">
                <h2 class="text-h3">カテゴリ管理</h2>
                <p class="mt-1 text-caption text-text-secondary">
                    動画マニュアルの分類に使うカテゴリを管理します。削除したカテゴリの動画は未分類になります。
                </p>
                {#if categories.length === 0}
                    <EmptyState
                        description="カテゴリはまだありません。追加すると動画マニュアルを分類できます。"
                        testId="categories-empty"
                    />
                {:else}
                    <ul
                        class="mt-4 flex flex-col divide-y divide-border"
                        data-testid="category-list"
                    >
                        {#each categories as category, index (category.id)}
                            <li class="flex items-center justify-between gap-4 py-3">
                                <p class="min-w-0 truncate text-body">{category.name}</p>
                                <div class="flex shrink-0 items-center gap-2">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        iconOnly
                                        ariaLabel={`「${category.name}」を上へ移動`}
                                        onclick={() => moveCategory(index, -1)}
                                        testId={`move-up-category-${category.id}`}
                                    >
                                        <ChevronUp class="size-4" aria-hidden="true" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        iconOnly
                                        ariaLabel={`「${category.name}」を下へ移動`}
                                        onclick={() => moveCategory(index, 1)}
                                        testId={`move-down-category-${category.id}`}
                                    >
                                        <ChevronDown class="size-4" aria-hidden="true" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onclick={() => openEditCategoryModal(category)}
                                        testId={`edit-category-${category.id}`}
                                    >
                                        編集
                                    </Button>
                                    <Button
                                        variant="danger-ghost"
                                        size="sm"
                                        onclick={() => openRemoveCategoryDialog(category)}
                                        testId={`remove-category-${category.id}`}
                                    >
                                        削除
                                    </Button>
                                </div>
                            </li>
                        {/each}
                    </ul>
                {/if}
                <form onsubmit={submitAddCategory} class="mt-4 flex items-start gap-2">
                    <div class="grow">
                        <FormField
                            label="カテゴリ名"
                            id="category-name"
                            error={addCategoryForm.errors.name}
                            required
                        >
                            {#snippet children({ id, describedBy, invalid })}
                                <Input
                                    {id}
                                    type="text"
                                    bind:value={addCategoryForm.name}
                                    error={invalid}
                                    aria-describedby={describedBy}
                                />
                            {/snippet}
                        </FormField>
                    </div>
                    <div class="pt-6">
                        <Button
                            type="submit"
                            loading={addCategoryForm.processing}
                            testId="category-submit"
                        >
                            追加
                        </Button>
                    </div>
                </form>
            </Card>
        {/if}

        <Card padding="lg">
            <h2 class="text-h3">アイテム</h2>
            {#if items.length === 0}
                <EmptyState
                    title="アイテムはまだありません"
                    description="このプロジェクトにアイテムを追加すると、ここに表示されます。"
                    testId="items-empty"
                />
            {:else}
                <ul class="mt-4 flex flex-col divide-y divide-border" data-testid="item-list">
                    {#each items as item (item.id)}
                        <li class="flex items-center justify-between gap-4 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-body">{item.name}</p>
                                {#if item.note}
                                    <p class="truncate text-caption text-text-secondary">
                                        {item.note}
                                    </p>
                                {/if}
                            </div>
                            {#if canManage}
                                <div class="flex shrink-0 items-center gap-2">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onclick={() => openEditModal(item)}
                                        testId={`edit-item-${item.id}`}
                                    >
                                        編集
                                    </Button>
                                    <Button
                                        variant="danger-ghost"
                                        size="sm"
                                        onclick={() => openRemoveDialog(item)}
                                        testId={`remove-item-${item.id}`}
                                    >
                                        削除
                                    </Button>
                                </div>
                            {/if}
                        </li>
                    {/each}
                </ul>
            {/if}
        </Card>

        {#if canManage}
            <Card padding="lg">
                <h2 class="text-h3">アイテムを追加</h2>
                <form onsubmit={submitAdd} class="mt-4 flex flex-col gap-4">
                    <FormField label="名前" id="item-name" error={addForm.errors.name} required>
                        {#snippet children({ id, describedBy, invalid })}
                            <Input
                                {id}
                                type="text"
                                bind:value={addForm.name}
                                error={invalid}
                                aria-describedby={describedBy}
                            />
                        {/snippet}
                    </FormField>
                    <FormField label="メモ" id="item-note" error={addForm.errors.note}>
                        {#snippet children({ id, describedBy, invalid })}
                            <Textarea
                                {id}
                                rows={2}
                                bind:value={addForm.note}
                                error={invalid}
                                aria-describedby={describedBy}
                            />
                        {/snippet}
                    </FormField>
                    <div>
                        <Button type="submit" loading={addForm.processing} testId="item-submit">
                            追加
                        </Button>
                    </div>
                </form>
            </Card>

            <DangerZone
                title="プロジェクトの削除"
                description="このプロジェクトと配下のすべてのアイテムを削除します。この操作は取り消せません。"
            >
                <Button
                    variant="danger-outline"
                    onclick={() => (deleteProjectDialogOpen = true)}
                    testId="delete-project-button"
                >
                    プロジェクトを削除
                </Button>
            </DangerZone>
        {/if}
    </div>

    <Modal
        bind:open={editCategoryModalOpen}
        title="カテゴリを編集"
        processing={editCategoryForm.processing}
        testId="edit-category-modal"
    >
        <form onsubmit={submitEditCategory} class="flex flex-col gap-4">
            <FormField
                label="カテゴリ名"
                id="edit-category-name"
                error={editCategoryForm.errors.name}
                required
            >
                {#snippet children({ id, describedBy, invalid })}
                    <Input
                        {id}
                        type="text"
                        bind:value={editCategoryForm.name}
                        error={invalid}
                        aria-describedby={describedBy}
                    />
                {/snippet}
            </FormField>
            <div class="flex items-center justify-end gap-2">
                <Button
                    variant="ghost"
                    onclick={() => (editCategoryModalOpen = false)}
                    disabled={editCategoryForm.processing}
                >
                    キャンセル
                </Button>
                <Button
                    type="submit"
                    loading={editCategoryForm.processing}
                    testId="edit-category-submit"
                >
                    保存
                </Button>
            </div>
        </form>
    </Modal>

    <ConfirmDialog
        bind:open={removeCategoryDialogOpen}
        title="カテゴリ削除"
        message={`「${removeCategoryTarget?.name ?? ""}」を削除しますか？ このカテゴリの動画マニュアルは未分類になります。`}
        confirmLabel="削除する"
        confirmVariant="danger"
        processing={removingCategory}
        onConfirm={removeCategory}
        testId="remove-category-dialog"
    />

    <Modal
        bind:open={editModalOpen}
        title="アイテムを編集"
        processing={editForm.processing}
        testId="edit-item-modal"
    >
        <form onsubmit={submitEdit} class="flex flex-col gap-4">
            <FormField label="名前" id="edit-item-name" error={editForm.errors.name} required>
                {#snippet children({ id, describedBy, invalid })}
                    <Input
                        {id}
                        type="text"
                        bind:value={editForm.name}
                        error={invalid}
                        aria-describedby={describedBy}
                    />
                {/snippet}
            </FormField>
            <FormField label="メモ" id="edit-item-note" error={editForm.errors.note}>
                {#snippet children({ id, describedBy, invalid })}
                    <Textarea
                        {id}
                        rows={2}
                        bind:value={editForm.note}
                        error={invalid}
                        aria-describedby={describedBy}
                    />
                {/snippet}
            </FormField>
            <div class="flex items-center justify-end gap-2">
                <Button
                    variant="ghost"
                    onclick={() => (editModalOpen = false)}
                    disabled={editForm.processing}
                >
                    キャンセル
                </Button>
                <Button type="submit" loading={editForm.processing} testId="edit-item-submit">
                    保存
                </Button>
            </div>
        </form>
    </Modal>

    <ConfirmDialog
        bind:open={removeDialogOpen}
        title="アイテム削除"
        message={`「${removeTarget?.name ?? ""}」を削除しますか？ この操作は取り消せません。`}
        confirmLabel="削除する"
        confirmVariant="danger"
        processing={removing}
        onConfirm={removeItem}
        testId="remove-item-dialog"
    />

    <ConfirmDialog
        bind:open={deleteProjectDialogOpen}
        title="プロジェクト削除"
        message={`プロジェクト「${project.name}」と配下のすべてのアイテムを削除しますか？ この操作は取り消せません。`}
        confirmLabel="削除する"
        confirmVariant="danger"
        processing={deletingProject}
        onConfirm={deleteProject}
        testId="delete-project-dialog"
    />
</AppLayout>
