<script lang="ts">
    import Button from "@/components/atoms/Button.svelte";
    import Textarea from "@/components/atoms/Textarea.svelte";
    import Modal from "@/components/organisms/Modal.svelte";

    /**
     * テイクのコメント編集ダイアログ。保存は親 (TakeStrip) が PATCH する。
     */
    interface Props {
        open: boolean;
        initial: string;
        saving: boolean;
        error: string | null;
        onSave: (comment: string) => void;
    }

    let { open = $bindable(false), initial, saving, error, onSave }: Props = $props();
    let comment = $state("");

    $effect(() => {
        if (open) {
            comment = initial;
        }
    });
</script>

<Modal bind:open title="テイクのコメント" processing={saving} testId="take-comment-dialog">
    <div class="flex flex-col gap-3">
        <Textarea bind:value={comment} rows={4} testId="take-comment-input" />
        {#if error}
            <p class="text-caption text-danger" role="alert">{error}</p>
        {/if}
    </div>
    {#snippet footer()}
        <Button variant="neutral" onclick={() => (open = false)}>キャンセル</Button>
        <Button
            variant="primary"
            loading={saving}
            onclick={() => onSave(comment)}
            testId="save-take-comment"
        >
            保存
        </Button>
    {/snippet}
</Modal>
