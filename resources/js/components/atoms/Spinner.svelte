<script lang="ts">
    import { LoaderCircle } from "@lucide/svelte";

    /**
     * Spinner atom。@lucide/svelte の LoaderCircle を animate-spin で回転させる。
     * 色は currentColor 継承 (置かれた文脈の文字色に従う)。
     * 既定は装飾扱い (aria-hidden)。単独のローディング表示として使う場合は label を渡すと
     * role="status" + sr-only テキストで支援技術に読み上げさせる。
     */

    type SpinnerSize = "sm" | "md" | "lg" | "xl";

    interface Props {
        size?: SpinnerSize;
        /** 指定時は role="status" + sr-only で読み上げる accessible name */
        label?: string;
        class?: string;
        testId?: string;
    }

    let { size = "md", label, class: extraClass = "", testId }: Props = $props();

    const SIZE_CLASSES = {
        sm: "size-4",
        md: "size-5",
        lg: "size-8",
        xl: "size-12",
    } as const satisfies Record<SpinnerSize, string>;

    const computedClass = $derived(
        ["inline-flex items-center", extraClass].filter(Boolean).join(" "),
    );
</script>

<span
    class={computedClass}
    role={label !== undefined ? "status" : undefined}
    aria-hidden={label === undefined ? "true" : undefined}
    data-testid={testId}
>
    <LoaderCircle class="animate-spin {SIZE_CLASSES[size]}" aria-hidden="true" />
    {#if label !== undefined}
        <span class="sr-only">{label}</span>
    {/if}
</span>
