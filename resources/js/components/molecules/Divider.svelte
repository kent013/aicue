<script lang="ts">
    /**
     * 区切り線。「または」セパレータ等を正規化する molecule。
     * - label 指定時: 中央にラベルを置いた区切り (absolute の border line + 中央 span)
     * - label 省略時: 素の水平線 (<hr>)
     * 余白は呼び出し側が class で渡す (my-6 等)。
     */
    interface Props {
        label?: string;
        class?: string;
        testId?: string;
    }

    let { label, class: extraClass = "", testId }: Props = $props();
</script>

{#if label !== undefined}
    <div class="relative {extraClass}" data-testid={testId}>
        <div class="absolute inset-0 flex items-center" aria-hidden="true">
            <div class="w-full border-t border-border"></div>
        </div>
        <div class="relative flex justify-center">
            <span class="bg-surface px-2 text-caption text-text-secondary">{label}</span>
        </div>
    </div>
{:else}
    <hr class="border-t border-border {extraClass}" data-testid={testId} />
{/if}
