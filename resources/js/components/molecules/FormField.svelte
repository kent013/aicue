<script lang="ts">
    import type { Snippet } from "svelte";
    import FormError from "@/components/atoms/FormError.svelte";

    /**
     * ラベル + 入力 + エラー + ヘルプの複合 molecule。
     *
     * 入力 atom (Input/Textarea/Select) は最小責務に保ち、ラベル・エラー文言・
     * aria-describedby の配線は本 molecule が担う (関心分離)。
     * children snippet に { id, describedBy, invalid } を渡すので、呼び出し側は
     * それを入力 atom へ流し込む。
     *
     * 使用例:
     *   <FormField label="名前" id="name" required error={form.errors.name}>
     *       {#snippet children({ id, describedBy, invalid })}
     *           <Input {id} bind:value={form.name} error={invalid} aria-describedby={describedBy} />
     *       {/snippet}
     *   </FormField>
     */
    interface Props {
        label: string;
        id: string;
        error?: string | null;
        help?: string;
        required?: boolean;
        children: Snippet<[{ id: string; describedBy: string | undefined; invalid: boolean }]>;
    }

    let { label, id, error, help, required = false, children }: Props = $props();

    const errorId = $derived(error ? `${id}-error` : undefined);
    const helpId = $derived(help ? `${id}-help` : undefined);
    const describedBy = $derived(
        [errorId, helpId].filter(Boolean).join(" ") || undefined,
    );
</script>

<div class="flex flex-col gap-1.5">
    <label for={id} class="text-caption font-medium text-text">
        {label}
        {#if required}<span class="text-danger" aria-hidden="true">*</span>{/if}
    </label>
    {@render children({ id, describedBy, invalid: Boolean(error) })}
    {#if help}
        <p id={helpId} class="text-caption text-text-secondary">{help}</p>
    {/if}
    <FormError id={errorId} message={error} />
</div>
