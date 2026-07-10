<script lang="ts">
    import type { HTMLInputAttributes } from "svelte/elements";
    import { Eye, EyeOff } from "@lucide/svelte";
    import Input from "@/components/atoms/Input.svelte";

    /**
     * パスワード入力 molecule。Input atom を右端の Eye/EyeOff トグルと合成し、
     * `password` ↔ `text` を即時切替する。表示/非表示は即時反映の二値状態なので
     * button トグル (aria-pressed) で表現する。
     *
     * FormField の配線 (label の for / error / aria-describedby) は呼び出し側の
     * FormField が担い、本 molecule は id / aria-describedby 等を Input へ透過する。
     * トグルボタンを対象 input に aria-controls で結線するため id は必須 prop。
     */
    interface Props
        extends Omit<HTMLInputAttributes, "type" | "value" | "class" | "id" | "disabled"> {
        /** 入力要素の id (必須)。トグルボタンの aria-controls にも結線する */
        id: string;
        value?: string;
        /** エラー状態 (FormField の invalid を渡す) */
        error?: boolean;
        disabled?: boolean;
        /** 最外 wrapper への追加 class */
        class?: string;
        testId?: string;
    }

    let {
        id,
        value = $bindable(""),
        error = false,
        disabled = false,
        class: extraClass = "",
        testId,
        ...rest
    }: Props = $props();

    let visible = $state(false);

    const inputType = $derived(visible ? "text" : "password");
    const toggleLabel = $derived(visible ? "パスワードを非表示" : "パスワードを表示");
    const wrapperClass = $derived(["relative", extraClass].filter(Boolean).join(" "));
</script>

<div class={wrapperClass}>
    <!-- 右端トグル分の余白を pr-10 で確保する (Input 基底の px-3 より後勝ち) -->
    <Input {...rest} {id} type={inputType} bind:value {error} {disabled} {testId} class="pr-10" />
    <button
        type="button"
        class="absolute inset-y-0 right-0 flex items-center px-3 text-text-secondary transition-colors duration-150 hover:text-text focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-40"
        aria-label={toggleLabel}
        aria-pressed={visible}
        aria-controls={id}
        {disabled}
        onclick={() => (visible = !visible)}
    >
        {#if visible}
            <EyeOff class="size-5" aria-hidden="true" />
        {:else}
            <Eye class="size-5" aria-hidden="true" />
        {/if}
    </button>
</div>
