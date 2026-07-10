<script lang="ts">
    import { Check, ChevronDown, ChevronUp, Download, Pencil, Trash2 } from "@lucide/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import TakeCommentDialog from "@/components/features/capture/TakeCommentDialog.svelte";
    import { captureJson, extractErrorMessage } from "@/lib/capture/http";
    import type { CaptureCut, CaptureTake } from "@/types/capture";

    /**
     * カット配下のテイク一覧 (先頭 = 採用候補。doc/05)。
     * 採用・並べ替え・コメント・削除・DL 済み ACK を XHR で行う。
     * 失敗 (DL 済み削除不可・処理中等) は押下時にエラー表示する (disabled 禁止)。
     */
    interface Props {
        projectId: number;
        manualId: number;
        cut: CaptureCut;
        onChanged: () => void;
    }

    let { projectId, manualId, cut, onChanged }: Props = $props();

    let error = $state<string | null>(null);
    let busyTakeId = $state<number | null>(null);
    let commentTarget = $state<CaptureTake | null>(null);
    let commentDialogOpen = $state(false);
    let commentSaving = $state(false);
    let commentError = $state<string | null>(null);

    function takeUrl(take: CaptureTake, suffix = ""): string {
        return `/app/projects/${projectId}/manuals/${manualId}/cuts/${cut.id}/takes/${take.id}${suffix}`;
    }

    async function run(take: CaptureTake, action: () => Promise<Response>): Promise<void> {
        error = null;
        busyTakeId = take.id;
        try {
            const response = await action();
            if (!response.ok) {
                error = await extractErrorMessage(response);
                return;
            }
            onChanged();
        } catch {
            error = "通信に失敗しました。ネットワークを確認してください。";
        } finally {
            busyTakeId = null;
        }
    }

    const adopt = (take: CaptureTake) => run(take, () => captureJson(takeUrl(take, "/adopt"), "POST"));
    const remove = (take: CaptureTake) => run(take, () => captureJson(takeUrl(take), "DELETE"));
    const move = (take: CaptureTake, position: number) =>
        run(take, () => captureJson(takeUrl(take), "PATCH", { position: Math.max(0, position) }));

    function openComment(take: CaptureTake): void {
        commentTarget = take;
        commentError = null;
        commentDialogOpen = true;
    }

    async function saveComment(comment: string): Promise<void> {
        if (commentTarget === null) return;
        commentSaving = true;
        commentError = null;
        try {
            const response = await captureJson(takeUrl(commentTarget), "PATCH", {
                comment: comment === "" ? null : comment,
            });
            if (!response.ok) {
                commentError = await extractErrorMessage(response);
                return;
            }
            commentDialogOpen = false;
            onChanged();
        } catch {
            commentError = "通信に失敗しました。";
        } finally {
            commentSaving = false;
        }
    }

    /** 採用テイクの DL 完了 ACK (概念設計 D6): DL 開始後にトークンを送る */
    async function downloadAndAck(take: CaptureTake): Promise<void> {
        if (take.playback_url === null || take.download_ack_token === null) {
            error = "この端末からダウンロードできるのは採用テイクのみです。";
            return;
        }
        window.open(take.playback_url, "_blank", "noopener");
        await run(take, () =>
            captureJson(takeUrl(take, "/downloaded"), "POST", { ack_token: take.download_ack_token }),
        );
    }

    function sizeLabel(bytes: number): string {
        if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }
</script>

<div class="flex flex-col gap-2" data-testid={`take-strip-${cut.id}`}>
    {#if cut.takes.length === 0}
        <p class="text-caption text-text-secondary">テイクはまだありません。撮影してください。</p>
    {/if}
    {#each cut.takes as take, index (take.id)}
        <div
            class="flex items-center gap-2 rounded-md border border-border bg-surface px-3 py-2 {take.downloaded
                ? 'border-border-strong'
                : ''}"
            data-testid={`take-item-${take.id}`}
        >
            <div class="flex flex-col gap-1">
                <Button
                    variant="ghost"
                    size="sm"
                    iconOnly
                    ariaLabel="上へ"
                    onclick={() => move(take, index - 1)}
                    testId={`take-up-${take.id}`}
                >
                    <ChevronUp class="size-4" aria-hidden="true" />
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    iconOnly
                    ariaLabel="下へ"
                    onclick={() => move(take, index + 1)}
                    testId={`take-down-${take.id}`}
                >
                    <ChevronDown class="size-4" aria-hidden="true" />
                </Button>
            </div>
            <div class="min-w-0 flex-1">
                <p class="flex items-center gap-2 text-body">
                    テイク {index + 1}
                    {#if cut.adopted_take_id === take.id}
                        <Badge tone="success" testId={`take-adopted-${take.id}`}>採用中</Badge>
                    {/if}
                    {#if take.downloaded}
                        <Badge tone="neutral">DL 済み</Badge>
                    {/if}
                </p>
                <p class="text-caption text-text-secondary">
                    {sizeLabel(take.size_bytes)}
                    {#if take.duration_ms !== null}
                        ・{Math.round(take.duration_ms / 1000)} 秒
                    {/if}
                    {#if take.comment}
                        ・{take.comment}
                    {/if}
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-1">
                <Button
                    variant="neutral"
                    size="sm"
                    loading={busyTakeId === take.id}
                    onclick={() => adopt(take)}
                    testId={`take-adopt-${take.id}`}
                >
                    <Check class="size-4" aria-hidden="true" />
                    採用
                </Button>
                {#if take.playback_url !== null}
                    <Button
                        variant="ghost"
                        size="sm"
                        iconOnly
                        ariaLabel="ダウンロード"
                        onclick={() => downloadAndAck(take)}
                        testId={`take-download-${take.id}`}
                    >
                        <Download class="size-4" aria-hidden="true" />
                    </Button>
                {/if}
                <Button
                    variant="ghost"
                    size="sm"
                    iconOnly
                    ariaLabel="コメント"
                    onclick={() => openComment(take)}
                    testId={`take-comment-${take.id}`}
                >
                    <Pencil class="size-4" aria-hidden="true" />
                </Button>
                <Button
                    variant="danger-ghost"
                    size="sm"
                    iconOnly
                    ariaLabel="削除"
                    onclick={() => remove(take)}
                    testId={`take-delete-${take.id}`}
                >
                    <Trash2 class="size-4" aria-hidden="true" />
                </Button>
            </div>
        </div>
    {/each}
    {#if error}
        <p class="text-caption text-danger" role="alert" data-testid="take-strip-error">{error}</p>
    {/if}
</div>

<TakeCommentDialog
    bind:open={commentDialogOpen}
    initial={commentTarget?.comment ?? ""}
    saving={commentSaving}
    error={commentError}
    onSave={saveComment}
/>
