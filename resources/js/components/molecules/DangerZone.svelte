<script lang="ts">
    import type { Snippet } from "svelte";

    /**
     * 破壊的・取り返しのつかない操作 (アカウント削除等) を集約する警告セクション。
     * children には danger 系 Button を置く想定の presentational molecule (状態なし)。
     */
    interface Props {
        title: string;
        description?: string;
        /** 見出し id の基底 (複数 DangerZone 同居時の id 衝突回避) */
        idBase?: string;
        testId?: string;
        /** 破壊的操作ボタン群 (Button variant="danger-outline" 等) */
        children: Snippet;
    }

    let { title, description, idBase = "danger-zone", testId, children }: Props = $props();
</script>

<!-- <section> + aria-labelledby で見出しを境界の accessible name に紐付け、
     支援技術が「破壊的操作セクション」という region 境界を読み上げられるようにする。
     accessible name 付き <section> は暗黙で role=region のため role は明示しない。 -->
<section
    aria-labelledby="{idBase}-title"
    class="rounded-lg border border-danger/30 bg-danger/5 p-4"
    data-testid={testId}
>
    <h3 id="{idBase}-title" class="text-h3 text-danger">{title}</h3>
    {#if description}
        <p class="mt-1 text-caption text-text-secondary">{description}</p>
    {/if}
    <div class="mt-4">
        {@render children()}
    </div>
</section>
