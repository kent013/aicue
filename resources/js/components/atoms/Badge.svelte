<script lang="ts">
    import type { BadgeProps } from "./Badge.types";
    import { BORDERED_CLASSES, SIZE_CLASSES, TONE_CLASSES } from "./Badge.types";

    let {
        tone = "primary",
        size = "sm",
        bordered = false,
        icon,
        class: extraClass = "",
        testId,
        children,
    }: BadgeProps = $props();

    // バッジは小コントロール → rounded-sm (DESIGN.md §Shapes)
    const computedClass = $derived(
        [
            "inline-flex items-center gap-1 rounded-sm",
            TONE_CLASSES[tone],
            bordered ? BORDERED_CLASSES[tone] : "",
            SIZE_CLASSES[size],
            extraClass,
        ]
            .filter(Boolean)
            .join(" "),
    );
</script>

<span class={computedClass} data-testid={testId}>
    {#if icon}
        <!-- icon の size/色責務を Badge 内 wrapper に閉じる (caller は icon を素描画するだけ) -->
        <span
            class="inline-flex size-3.5 shrink-0 items-center [&_svg]:size-3.5 [&_svg]:stroke-current"
            aria-hidden="true"
        >
            {@render icon()}
        </span>
    {/if}
    {@render children?.()}
</span>
