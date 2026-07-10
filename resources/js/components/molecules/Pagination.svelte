<script lang="ts">
    /**
     * ページ送り UI (前へ / ページ番号 / 次へ)。callback ベース:
     * ページング state は親が持ち、currentPage / totalPages を受け取り onChange(page) を返す。
     * 遷移手段は持たない (Inertia 遷移かローカル state 更新かは呼び出し側裁量) ため
     * 全て <button type="button"> で構成する。
     */
    import { ChevronLeft, ChevronRight } from "@lucide/svelte";

    interface Props {
        /** 現在ページ (1-indexed) */
        currentPage: number;
        /** 総ページ数 */
        totalPages: number;
        /** ページ変更時のコールバック (範囲外・現在ページでは呼ばれない) */
        onChange: (page: number) => void;
        /** nav ランドマークの accessible name */
        ariaLabel?: string;
        testId?: string;
    }

    let {
        currentPage,
        totalPages,
        onChange,
        ariaLabel = "ページネーション",
        testId,
    }: Props = $props();

    // 表示するページ番号列。totalPages ≤ 1 は番号なし、≤ 7 は全表示、
    // 超過時は先頭・末尾を常に出し、現在ページ ± 1 の窓との間に飛びがあれば
    // 省略記号 "…" を挿入する (多段省略は持たない最小実装)。
    const ELLIPSIS = "…";
    type PageItem = number | typeof ELLIPSIS;

    const pages = $derived.by<PageItem[]>(() => {
        if (totalPages <= 1) return [];
        if (totalPages <= 7) {
            return Array.from({ length: totalPages }, (_, i) => i + 1);
        }
        // 窓: 現在ページ ± 1 を 2..totalPages-1 にクランプ
        const windowStart = Math.max(2, currentPage - 1);
        const windowEnd = Math.min(totalPages - 1, currentPage + 1);
        const items: PageItem[] = [1];
        if (windowStart > 2) {
            items.push(ELLIPSIS);
        }
        for (let p = windowStart; p <= windowEnd; p++) {
            items.push(p);
        }
        if (windowEnd < totalPages - 1) {
            items.push(ELLIPSIS);
        }
        items.push(totalPages);
        return items;
    });

    function go(page: number): void {
        if (page < 1 || page > totalPages || page === currentPage) return;
        onChange(page);
    }

    // 前へ/次へ (icon) と ページ番号で共通の操作スタイル
    const controlClass =
        "transition-colors duration-150 focus-visible:ring-3 focus-visible:ring-primary/35 " +
        "focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-40";
</script>

<nav aria-label={ariaLabel} class="flex items-center gap-1" data-testid={testId}>
    <button
        type="button"
        onclick={() => go(currentPage - 1)}
        disabled={currentPage <= 1}
        aria-label="前のページ"
        class="inline-flex size-8 items-center justify-center rounded-sm text-text-secondary hover:bg-neutral {controlClass}"
    >
        <ChevronLeft class="size-4" aria-hidden="true" />
    </button>
    {#each pages as page, i (i)}
        {#if page === ELLIPSIS}
            <span class="px-2 text-caption text-text-secondary" aria-hidden="true">{ELLIPSIS}</span>
        {:else}
            <button
                type="button"
                onclick={() => go(page)}
                aria-current={page === currentPage ? "page" : undefined}
                class="h-8 min-w-8 rounded-sm px-2 text-caption font-medium {controlClass} {page ===
                currentPage
                    ? 'bg-primary text-neutral'
                    : 'text-text-secondary hover:bg-neutral'}"
            >
                {page}
            </button>
        {/if}
    {/each}
    <button
        type="button"
        onclick={() => go(currentPage + 1)}
        disabled={currentPage >= totalPages}
        aria-label="次のページ"
        class="inline-flex size-8 items-center justify-center rounded-sm text-text-secondary hover:bg-neutral {controlClass}"
    >
        <ChevronRight class="size-4" aria-hidden="true" />
    </button>
</nav>
