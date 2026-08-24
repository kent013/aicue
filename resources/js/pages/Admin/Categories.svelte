<script lang="ts">
    import { page, router, useForm } from "@inertiajs/svelte";
    import { ChevronDown, ChevronUp, Tags } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import EmptyState from "@/components/molecules/EmptyState.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import PageHeader from "@/components/molecules/PageHeader.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import Modal from "@/components/organisms/Modal.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import type { SharedProps } from "@/lib/shared-props";
    import type { CategoryOption } from "@/types/manual";
    import { currentOrgUrl } from "@/lib/org-url";

    /**
     * 管理メニュー > カテゴリ管理 (doc/04 §4.2。モック PC_管理メニュー 10〜17)。
     * 追加・名称編集・削除・▲▼並べ替え。write は既存 projects.categories.* endpoint を使う
     * (バックエンド重複実装なし。同名 422・削除で未分類化・reorder 集合検証は既存のまま)。
     * Projects/Show から移設 (Show はカテゴリフィルタ select のみ残す)。
     */
    interface Props {
        project: { id: number; name: string };
        categories: CategoryOption[];
    }

    let { project, categories }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    /* ---- カテゴリ追加 (モック 11〜13) ---- */
    const addCategoryForm = useForm({ name: "" });

    function submitAddCategory(event: SubmitEvent): void {
        event.preventDefault();
        if (addCategoryForm.processing) return; // 二重送信の冪等ガード
        addCategoryForm.post(currentOrgUrl(`/projects/${project.id}/categories`), {
            preserveScroll: true,
            onSuccess: () => {
                addCategoryForm.reset();
            },
        });
    }

    /* ---- カテゴリ編集 (モック 14〜15) ---- */
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
        if (editCategoryForm.processing) return; // 二重送信の冪等ガード
        if (editCategoryTarget === null) return;
        editCategoryForm.patch(currentOrgUrl(`/projects/${project.id}/categories/${editCategoryTarget.id}`), {
            preserveScroll: true,
            onSuccess: () => {
                editCategoryModalOpen = false;
            },
        });
    }

    /* ---- カテゴリ削除 (モック 16〜17: 未分類化の警告) ---- */
    let removeCategoryTarget = $state<CategoryOption | null>(null);
    let removeCategoryDialogOpen = $state(false);
    let removingCategory = $state(false);

    function openRemoveCategoryDialog(category: CategoryOption): void {
        removeCategoryTarget = category;
        removeCategoryDialogOpen = true;
    }

    function removeCategory(): void {
        if (removeCategoryTarget === null || removingCategory) return;
        router.delete(currentOrgUrl(`/projects/${project.id}/categories/${removeCategoryTarget.id}`), {
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

    /* ---- 並べ替え (▲▼): index の要素を direction (±1) 方向へ入れ替えた id 順序配列を送る ---- */
    let reordering = $state(false);

    function moveCategory(index: number, direction: -1 | 1): void {
        const target = index + direction;
        if (target < 0 || target >= categories.length || reordering) return;
        const order = categories.map((category) => category.id);
        [order[index], order[target]] = [order[target], order[index]];
        reordering = true;
        router.patch(
            currentOrgUrl(`/projects/${project.id}/categories/reorder`),
            { order },
            {
                preserveScroll: true,
                onFinish: () => {
                    reordering = false;
                },
            },
        );
    }
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeader
            title="カテゴリ管理"
            description={`${project.name} の動画マニュアルの分類に使うカテゴリを管理します。削除したカテゴリの動画は未分類になります。`}
            icon={Tags}
            testId="categories-heading"
        />
        <PageContent>
            <div class="flex min-w-0 flex-col gap-10">
                <Card padding="lg">
                    <h2 class="text-h3">カテゴリを追加</h2>
                    <form novalidate onsubmit={submitAddCategory} class="mt-4 flex items-start gap-2">
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

                <Card padding="lg">
                    <h2 class="text-h3">カテゴリ一覧</h2>
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
                                        {#if index > 0}
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
                                        {/if}
                                        {#if index < categories.length - 1}
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
                                        {/if}
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
                </Card>
            </div>

        <Modal
            bind:open={editCategoryModalOpen}
            title="カテゴリを編集"
            processing={editCategoryForm.processing}
            testId="edit-category-modal"
        >
            <form novalidate onsubmit={submitEditCategory} class="flex flex-col gap-4">
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
        </PageContent>
    </PageContainer>
</AppLayout>
