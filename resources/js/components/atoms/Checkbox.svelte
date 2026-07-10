<script lang="ts">
    import type { Snippet } from "svelte";
    import FormError from "./FormError.svelte";

    /**
     * チェックボックス atom。インラインラベル(右側)とエラー表示を内包する。
     *
     * チェックボックスと複数行ラベルの行揃えは本 atom の責務
     * (items-start + チェックボックス側の mt 調整で、ラベルが折り返しても
     * チェックボックスが 1 行目に揃う)。ページ側で素の <input type="checkbox"> を
     * 書かないこと (DESIGN.md §Do's and Don'ts)。
     */
    interface Props {
        checked?: boolean;
        /** ラベル内容。リンク (利用規約等) を含められるよう snippet で受ける */
        label: Snippet | string;
        error?: string | null;
        id: string;
        disabled?: boolean;
        required?: boolean;
        testId?: string;
        onchange?: (event: Event) => void;
    }

    let {
        checked = $bindable(false),
        label,
        error,
        id,
        disabled = false,
        required = false,
        testId,
        onchange,
    }: Props = $props();

    const errorId = $derived(error ? `${id}-error` : undefined);
</script>

<div>
    <label for={id} class="flex items-start gap-2">
        <input
            {id}
            type="checkbox"
            bind:checked
            {disabled}
            {required}
            {onchange}
            class="mt-1.5 size-4 shrink-0 accent-primary disabled:cursor-not-allowed"
            aria-invalid={error ? true : undefined}
            aria-describedby={errorId}
            data-testid={testId}
        />
        <span class="text-body text-text">
            {#if typeof label === "string"}
                {label}
            {:else}
                {@render label()}
            {/if}
        </span>
    </label>
    <div class="mt-1 pl-6">
        <FormError id={errorId} message={error} />
    </div>
</div>
