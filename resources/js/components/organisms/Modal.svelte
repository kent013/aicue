<script lang="ts">
    import { Dialog } from "bits-ui";
    import { X } from "@lucide/svelte";
    import type { ModalProps } from "./Modal.types";
    import { SIZE_CLASSES } from "./Modal.types";

    let {
        open = $bindable(false),
        title,
        size = "md",
        processing = false,
        children,
        footer,
        testId,
    }: ModalProps = $props();
</script>

<Dialog.Root bind:open>
    <Dialog.Portal>
        <!-- 暗幕は墨色 (text) の 50% で表現する (黒 hex を使わない) -->
        <Dialog.Overlay class="fixed inset-0 z-30 bg-text/50" />
        <!-- 影が使えないため border + bg-surface で背景と区別する (DESIGN.md §Elevation) -->
        <Dialog.Content
            class="fixed top-1/2 left-1/2 z-40 max-h-[85vh] w-full -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-lg border border-border bg-surface p-6 {SIZE_CLASSES[size]}"
            escapeKeydownBehavior={processing ? "ignore" : "close"}
            interactOutsideBehavior={processing ? "ignore" : "close"}
            data-testid={testId}
        >
            <div class="mb-4 flex items-start justify-between gap-4">
                <Dialog.Title class="text-h3 text-text">{title}</Dialog.Title>
                <Dialog.Close
                    class="rounded-sm text-text-secondary transition-colors duration-150 hover:text-text focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-40"
                    aria-label="閉じる"
                    disabled={processing}
                >
                    <X class="size-5" aria-hidden="true" />
                </Dialog.Close>
            </div>
            {#if children}
                <div class="text-body text-text">
                    {@render children()}
                </div>
            {/if}
            {#if footer}
                <div class="mt-6 flex items-center justify-end gap-3">
                    {@render footer()}
                </div>
            {/if}
        </Dialog.Content>
    </Dialog.Portal>
</Dialog.Root>
