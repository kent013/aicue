<script lang="ts">
    import { Trash2 } from "@lucide/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import TakeThumbnail from "@/components/features/manual/TakeThumbnail.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import { captureJson, extractErrorMessage } from "@/lib/capture/http";
    import { takeUrl as buildTakeUrl } from "@/lib/capture/take-endpoints";
    import { currentOrganizationSlug } from "@/lib/org-url";
    import { formatBytes } from "@/lib/format-bytes";
    import { TAKE_STATUS_LABELS, type SelectableTake } from "@/types/manual";

    /**
     * テイク一覧 (PC テイク選択画面の左ペイン)。選択とテイク削除を担う。
     * 採用の確定はプレビュー側 (TakePreviewPanel) が行う。
     * 削除は撮影 PWA と同じ capture.takes.destroy を叩き、DL 済み (422) の理由は
     * サーバ供給の文言をそのまま表示する (UI 側で理由を再実装しない)。
     */
    interface Props {
        takes: SelectableTake[];
        /** 現在の採用テイク id (青枠の対象) */
        adoptedTakeId: number | null;
        selectedTakeId: number | null;
        onSelect: (takeId: number) => void;
        projectId: number;
        manualId: number;
        cutId: number;
        /** 削除成功後の再取得 */
        onChanged: () => void;
    }

    let {
        takes,
        adoptedTakeId,
        selectedTakeId,
        onSelect,
        projectId,
        manualId,
        cutId,
        onChanged,
    }: Props = $props();

    let error = $state<string | null>(null);
    let busyTakeId = $state<number | null>(null);

    // 削除確認: id と表示ラベルをスナップショット保持する (再取得で参照内容がずれないため)
    let deleteTargetId = $state<number | null>(null);
    let deleteLabel = $state("");
    let deleteDialogOpen = $state(false);

    function thumbnailUrl(take: SelectableTake): string | null {
        return take.has_thumbnail
            ? buildTakeUrl({ organizationSlug: currentOrganizationSlug(), projectId, manualId, cutId }, take.id, "/thumbnail")
            : null;
    }

    function requestDelete(take: SelectableTake, index: number): void {
        error = null;
        deleteTargetId = take.id;
        deleteLabel = `テイク ${index + 1}`;
        deleteDialogOpen = true;
    }

    /** 「削除する」確定時のみ DELETE を送る (押下は常に受け、422 はサーバ文言を表示する) */
    async function confirmDelete(): Promise<void> {
        const id = deleteTargetId;
        if (id === null) return;
        busyTakeId = id;
        try {
            const response = await captureJson(
                buildTakeUrl({ organizationSlug: currentOrganizationSlug(), projectId, manualId, cutId }, id),
                "DELETE",
            );
            if (!response.ok) {
                error = await extractErrorMessage(response);
                return;
            }
            onChanged();
        } catch {
            error = "通信に失敗しました。ネットワークを確認してください。";
        } finally {
            busyTakeId = null;
            deleteDialogOpen = false;
            deleteTargetId = null;
            deleteLabel = "";
        }
    }
</script>

<div class="flex flex-col gap-2" data-testid="take-picker-list">
    {#if takes.length === 0}
        <p class="text-caption text-text-secondary" data-testid="take-picker-empty">
            このカットにはまだ動画がありません。スマホで撮影するか、下のフォームから動画ファイルを追加してください。
        </p>
    {/if}
    <ul class="flex flex-col gap-2">
        {#each takes as take, index (take.id)}
            <li
                class="flex items-start gap-2 rounded-md border p-2 {adoptedTakeId === take.id
                    ? 'border-primary'
                    : 'border-border'} {selectedTakeId === take.id ? 'bg-primary-soft' : 'bg-surface'}"
                data-testid={`take-tile-${take.id}`}
            >
                <button
                    type="button"
                    class="flex min-w-0 flex-1 items-start gap-2 text-left"
                    aria-current={selectedTakeId === take.id ? "true" : undefined}
                    onclick={() => onSelect(take.id)}
                    data-testid={`take-select-${take.id}`}
                >
                    <TakeThumbnail
                        {index}
                        status={take.status}
                        durationMs={take.duration_ms}
                        thumbnailUrl={thumbnailUrl(take)}
                        testId={`take-thumbnail-${take.id}`}
                    />
                    <span class="flex min-w-0 flex-col gap-1">
                        <span class="flex flex-wrap items-center gap-1 text-body text-text">
                            テイク {index + 1}
                            {#if adoptedTakeId === take.id}
                                <Badge tone="primary" testId={`take-adopted-${take.id}`}>採用中</Badge>
                            {/if}
                            {#if take.downloaded}
                                <Badge tone="neutral">DL 済み</Badge>
                            {/if}
                        </span>
                        <span class="text-caption text-text-secondary">
                            {TAKE_STATUS_LABELS[take.status]}・{formatBytes(take.size_bytes)}
                            {#if take.duration_ms !== null}
                                ・{Math.round(take.duration_ms / 1000)} 秒
                            {/if}
                        </span>
                        {#if take.downloaded}
                            <!-- 削除は 422 になる。押下は止めないが理由は押す前に見せる -->
                            <span
                                class="text-caption text-text-secondary"
                                data-testid={`take-downloaded-note-${take.id}`}
                            >
                                ダウンロード済みのため削除できません。
                            </span>
                        {/if}
                        {#if take.comment}
                            <span class="text-caption text-text-secondary">{take.comment}</span>
                        {/if}
                    </span>
                </button>
                <Button
                    variant="danger-ghost"
                    size="sm"
                    iconOnly
                    ariaLabel={`テイク ${index + 1} を削除`}
                    loading={busyTakeId === take.id}
                    onclick={() => requestDelete(take, index)}
                    testId={`take-delete-${take.id}`}
                >
                    <Trash2 class="size-4" aria-hidden="true" />
                </Button>
            </li>
        {/each}
    </ul>
    {#if error}
        <p class="text-caption text-danger" role="alert" data-testid="take-picker-error">{error}</p>
    {/if}
</div>

<ConfirmDialog
    bind:open={deleteDialogOpen}
    title="テイクの削除"
    message={`${deleteLabel}を削除しますか？ この操作は取り消せません。動画は完全に削除されます。`}
    confirmLabel="削除する"
    confirmVariant="danger"
    processing={deleteTargetId !== null && busyTakeId === deleteTargetId}
    onConfirm={confirmDelete}
    testId="take-delete-dialog"
/>
