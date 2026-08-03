<script lang="ts">
    import Button from "@/components/atoms/Button.svelte";
    import Modal from "@/components/organisms/Modal.svelte";
    import type { ConfirmDialogProps } from "./ConfirmDialog.types";

    let {
        open = $bindable(false),
        title,
        message,
        banner,
        confirmLabel = "確認",
        cancelLabel = "キャンセル",
        confirmVariant = "primary",
        processing = false,
        onConfirm,
        onCancel,
        testId,
    }: ConfirmDialogProps = $props();

    // Modal 側 (ESC / overlay / X) で閉じた時も onCancel を発火させるための function binding。
    // processing 中の ESC / overlay 抑止は Modal が担う。
    function setOpen(next: boolean): void {
        open = next;
        if (!next) {
            onCancel?.();
        }
    }

    // cancel ボタンは自前で閉じる (setOpen を経由しないため onCancel をここで呼ぶ)
    function handleCancel(): void {
        if (processing) return;
        open = false;
        onCancel?.();
    }
</script>

<Modal bind:open={() => open, setOpen} {title} size="sm" {processing} {testId}>
    {#if banner}
        {@render banner()}
    {/if}
    <p>{message}</p>
    {#snippet footer()}
        <Button variant="ghost" onclick={handleCancel} disabled={processing}>
            {cancelLabel}
        </Button>
        <Button variant={confirmVariant} onclick={() => onConfirm()} loading={processing}>
            {confirmLabel}
        </Button>
    {/snippet}
</Modal>
