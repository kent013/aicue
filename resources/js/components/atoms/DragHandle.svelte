<script lang="ts">
    import { GripVertical } from "@lucide/svelte";
    import type { DragHandleProps } from "./DragHandle.types";

    let {
        ariaLabel,
        onpointerdown,
        onkeydown,
        testId,
        class: extraClass = "",
    }: DragHandleProps = $props();

    // 小コントロール → rounded-sm (DESIGN.md §Shapes)。影・scale は使わない (§Elevation)。
    // touch-none: ハンドル上のタッチをブラウザのスクロールに奪われないようにする
    //   (これを付けないと iOS Safari で縦ドラッグがページスクロールになる)。
    // select-none: ドラッグ中のテキスト選択を抑止する。
    // **disabled にはしない** (禁止事項 8 / 受け入れ条件 A1)。
    const computedClass = $derived(
        [
            "inline-flex size-8 shrink-0 cursor-grab touch-none items-center justify-center",
            "rounded-sm border border-transparent text-text-secondary select-none",
            "transition-colors duration-150",
            "hover:border-border-strong hover:text-text",
            "focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none",
            "active:cursor-grabbing",
            extraClass,
        ]
            .filter(Boolean)
            .join(" "),
    );
</script>

<button
    type="button"
    class={computedClass}
    aria-label={ariaLabel}
    data-testid={testId}
    {onpointerdown}
    {onkeydown}
>
    <GripVertical class="size-4" aria-hidden="true" />
</button>
