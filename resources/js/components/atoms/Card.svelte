<script lang="ts">
    import type { Snippet } from "svelte";

    /**
     * Card atom。浮いた要素の背景は surface、階層は明度差 + 1px border で表現する
     * (DESIGN.md §Elevation & Depth: 影は使わない)。カードの角丸は rounded-lg (§Shapes)。
     * padding="none" は table/list 等を内包して内側で個別に padding を制御する箱用。
     */

    type CardPadding = "none" | "sm" | "md" | "lg";

    interface Props {
        padding?: CardPadding;
        class?: string;
        testId?: string;
        children?: Snippet;
    }

    let {
        padding = "md",
        class: extraClass = "",
        testId,
        children,
    }: Props = $props();

    const PADDING_CLASSES = {
        none: "",
        sm: "p-2",
        md: "p-4",
        lg: "p-6",
    } as const satisfies Record<CardPadding, string>;

    const computedClass = $derived(
        ["rounded-lg border border-border bg-surface", PADDING_CLASSES[padding], extraClass]
            .filter(Boolean)
            .join(" "),
    );
</script>

<div class={computedClass} data-testid={testId}>
    {@render children?.()}
</div>
