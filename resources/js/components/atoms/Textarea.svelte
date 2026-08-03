<script lang="ts">
    import type { HTMLTextareaAttributes } from "svelte/elements";
    import { INPUT_BASE_CLASSES, inputStateClass } from "./input-state";

    /**
     * Textarea atom。複数行テキスト入力。
     * 見た目の真実は input-state.ts (入力系共通スタイル) に集約する。
     * aria-describedby 等の追加属性は restProps で透過する。
     */
    type Props = {
        /** 入力値 ($bindable) */
        value?: string;
        /** true で枠線を danger 化し aria-invalid を立てる */
        error?: boolean;
        /** 編集不可だが値は生きている (送信される・コピー/フォーカス可)。disabled とは意味が違う */
        readonly?: boolean;
        /** data-testid に反映するテスト用 ID */
        testId?: string;
        class?: string;
    } & Omit<HTMLTextareaAttributes, "value" | "class" | "readonly">;

    let {
        value = $bindable(),
        error = false,
        disabled = false,
        readonly = false,
        rows = 4,
        id,
        placeholder,
        testId,
        class: extraClass = "",
        ...restProps
    }: Props = $props();

    // マージ順: 共通 base → error/readonly 状態 → 外部 class (外部後勝ち)
    const computedClass = $derived(
        [INPUT_BASE_CLASSES, inputStateClass(error, readonly), extraClass]
            .filter(Boolean)
            .join(" "),
    );
</script>

<!-- bind:value は native textarea に直結。aria-invalid は error と連動する -->
<textarea
    {...restProps}
    bind:value
    {id}
    {rows}
    {placeholder}
    {disabled}
    {readonly}
    aria-invalid={error || undefined}
    data-testid={testId}
    class={computedClass}
></textarea>
