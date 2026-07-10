<script lang="ts">
    /**
     * フォームエラー表示 atom。FormField / Checkbox から composition される。
     * 単体でも使えるが、aria-describedby の配線は呼び出し側の責務。
     */
    interface Props {
        message?: string | null;
        id?: string;
        testId?: string;
    }

    let { message, id, testId }: Props = $props();
</script>

{#if message}
    <p {id} class="text-caption text-danger" data-testid={testId}>{message}</p>
{/if}
