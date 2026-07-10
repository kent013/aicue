<script lang="ts">
    import Select from "@/components/atoms/Select.svelte";

    /**
     * Select のテスト専用 harness。
     * children snippet (<option> 群) と bind:value の親への同期を実 DOM で検証する。
     */
    let {
        value = $bindable("apple"),
        error = false,
        disabled = false,
    }: { value?: string; error?: boolean; disabled?: boolean } = $props();
</script>

<Select bind:value {error} {disabled} id="fruit" testId="select" aria-describedby="fruit-error">
    <option value="apple">りんご</option>
    <option value="banana">バナナ</option>
</Select>

<!-- bind:value が親へ伝播したことを DOM 経由で検証するための出力 -->
<output data-testid="bound-value">{value}</output>
