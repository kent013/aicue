<script lang="ts">
    import type { Snippet } from "svelte";

    /**
     * PageContent — 認証ページ本文の中央寄せ max-width wrapper (layout primitive, aigenba 準拠)。
     * prop を持たず常に mx-auto max-w-7xl 中央寄せ (T070 の独自 maxWidth/testId prop は撤去済み)。
     */
    interface Props {
        children: Snippet;
    }

    let { children }: Props = $props();
</script>

<div class="mx-auto max-w-7xl">
    {@render children()}
</div>
