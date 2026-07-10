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

    interface Props extends Omit<HTMLInputAttributes, "type" | "value" | "class"> {
        type?: InputType;
        value?: string;
        error?: boolean;
        testId?: string;
        class?: string;
    }

    let {
        type = "text",
        value = $bindable(""),
        error = false,
        testId,
        class: extraClass = "",
        ...rest
    }: Props = $props();

    const computedClass = $derived(
        [INPUT_BASE_CLASSES, inputStateClass(error), extraClass].filter(Boolean).join(" "),
    );
</script>

<input
    {type}
    bind:value
    class={computedClass}
    aria-invalid={error || undefined}
    data-testid={testId}
    {...rest}
/>
