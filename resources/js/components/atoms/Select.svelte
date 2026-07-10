<script lang="ts">
    import type { Snippet } from "svelte";
    import type { HTMLSelectAttributes } from "svelte/elements";
    import { INPUT_BASE_CLASSES, inputStateClass } from "./input-state";

    /**
     * Select atom。ネイティブ <select> の薄いラッパ。
     * 見た目の真実は input-state.ts (入力系共通スタイル) に集約する。
     * <option> 群は children snippet として呼び出し側が記述する。
     */
    type Props = {
        /** 選択値 ($bindable) */
        value?: string;
        /** true で枠線を danger 化し aria-invalid を立てる */
        error?: boolean;
        /** data-testid に反映するテスト用 ID */
        testId?: string;
        class?: string;
        /** <option> 群 (呼び出し側が記述する) */
        children: Snippet;
    } & Omit<HTMLSelectAttributes, "value" | "class">;

    let {
        value = $bindable(),
        error = false,
        disabled = false,
        id,
        testId,
        class: extraClass = "",
        children,
        ...restProps
    }: Props = $props();

    // マージ順: 共通 base → error 状態 → 外部 class (外部後勝ち)
    const computedClass = $derived(
        [INPUT_BASE_CLASSES, inputStateClass(error), extraClass].filter(Boolean).join(" "),
    );
</script>

<!-- bind:value は native select に直結。aria-invalid は error と連動する -->
<select
    {...restProps}
    bind:value
    {id}
    {disabled}
    aria-invalid={error || undefined}
    data-testid={testId}
    class={computedClass}
>
    {@render children()}
</select>
