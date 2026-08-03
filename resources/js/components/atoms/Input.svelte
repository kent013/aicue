<script lang="ts">
    import type { HTMLInputAttributes } from "svelte/elements";
    import { INPUT_BASE_CLASSES, inputStateClass } from "./input-state";

    type InputType =
        | "text"
        | "email"
        | "password"
        | "tel"
        | "url"
        | "number"
        | "search"
        | "date";

    // type は「入力補助 (モバイルキーボード / autofill / 型のアナウンス)」のための意味付けであり、
    // 検証手段ではない。検証の正本はサーバ (日本語) + 押下時の client エラーで、
    // native constraint validation には依存しない (form 側の novalidate。DESIGN.md §Do's and Don'ts)。
    interface Props extends Omit<HTMLInputAttributes, "type" | "value" | "class" | "readonly"> {
        type?: InputType;
        value?: string;
        error?: boolean;
        /** 編集不可だが値は生きている (送信される・コピー/フォーカス可)。disabled とは意味が違う */
        readonly?: boolean;
        testId?: string;
        class?: string;
    }

    let {
        type = "text",
        value = $bindable(""),
        error = false,
        readonly = false,
        testId,
        class: extraClass = "",
        ...rest
    }: Props = $props();

    const computedClass = $derived(
        [INPUT_BASE_CLASSES, inputStateClass(error, readonly), extraClass]
            .filter(Boolean)
            .join(" "),
    );
</script>

<input
    {type}
    {readonly}
    bind:value
    class={computedClass}
    aria-invalid={error || undefined}
    data-testid={testId}
    {...rest}
/>
