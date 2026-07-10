<script lang="ts">
    import { RefreshCw, Upload } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";

    /**
     * アップロードキューの状態表示 (概念設計 D9: 端末保持中のテイク数・概算サイズを明示)。
     * quota 超過はキュー停止の理由を表示し、再開ボタン (resume) を常時押下可能にする。
     */
    interface Props {
        pendingCount: number;
        pendingBytes: number;
        uploading: boolean;
        quotaMessage: string | null;
        onResume: () => void;
    }

    let { pendingCount, pendingBytes, uploading, quotaMessage, onResume }: Props = $props();

    const sizeLabel = $derived(
        pendingBytes >= 1024 * 1024
            ? `${(pendingBytes / (1024 * 1024)).toFixed(1)} MB`
            : `${Math.max(1, Math.round(pendingBytes / 1024))} KB`,
    );
</script>

{#if pendingCount > 0 || quotaMessage !== null}
    <div
        class="flex items-center gap-3 rounded-md border border-border bg-surface px-3 py-2"
        data-testid="upload-queue-bar"
    >
        <Upload class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
        <div class="min-w-0 flex-1">
            {#if pendingCount > 0}
                <p class="text-caption">
                    未送信テイク {pendingCount} 件 ({sizeLabel}) を端末に保持中
                </p>
            {/if}
            {#if quotaMessage !== null}
                <p class="text-caption text-danger" role="alert" data-testid="quota-message">
                    {quotaMessage}
                </p>
            {/if}
        </div>
        <Button variant="neutral" size="sm" loading={uploading} onclick={onResume} testId="resume-uploads">
            <RefreshCw class="size-4" aria-hidden="true" />
            再送
        </Button>
    </div>
{/if}
