<script lang="ts">
    import { Film } from "@lucide/svelte";

    /**
     * 撮影 PWA のシナリオ選択カードに出す**代表サムネイル 1 枚** (doc/05 §5.2)。
     *
     * 表示するか否かはサーバが決めている (props の cover が非 null かどうか)。
     * ここは「与えられた URL を出す / 出せなければ同寸法のプレースホルダを描く」だけで、
     * 権限や状態の判断を持たない (判断を 2 箇所に持たない)。
     *
     * 読み込みに失敗したときもプレースホルダへ戻す。署名 URL は期限を持ち、
     * PWA は画面を開いたまま放置されうるため、壊れた画像アイコンを現場に出さない。
     * 再試行はしない (画面を訪ね直せば新しい署名 URL を取り直せる)。
     */
    interface Props {
        /** 代表サムネイルの取得 URL (代表が無いときは null) */
        src: string | null;
        testId?: string;
    }

    let { src, testId }: Props = $props();

    // 失敗した URL そのものを覚える = src が変わったら自動的に再挑戦できる
    let failedSrc = $state<string | null>(null);
    const url = $derived(src !== null && src !== failedSrc ? src : null);
</script>

{#if url !== null}
    <img
        src={url}
        alt=""
        loading="lazy"
        decoding="async"
        class="size-16 shrink-0 rounded-md border border-border object-cover"
        data-testid={testId}
        data-state="image"
        onerror={() => (failedSrc = src)}
    />
{:else}
    <div
        class="flex size-16 shrink-0 items-center justify-center rounded-md border border-border bg-neutral text-text-secondary"
        data-testid={testId}
        data-state="placeholder"
        aria-hidden="true"
    >
        <Film class="size-5" />
    </div>
{/if}
