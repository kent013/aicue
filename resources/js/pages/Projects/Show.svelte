<script lang="ts">
    import { page, router, useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Textarea from "@/components/atoms/Textarea.svelte";
    import DangerZone from "@/components/molecules/DangerZone.svelte";
    import EmptyState from "@/components/molecules/EmptyState.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import Modal from "@/components/organisms/Modal.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import type { SharedProps } from "@/lib/shared-props";

    /**
     * プロジェクト詳細 + Item 一覧 (Item の専用 Index ページは持たない)。
     * Item の追加はインラインフォーム、編集は Modal、削除は ConfirmDialog。
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
    }

    let { project, items, canManage }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

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
