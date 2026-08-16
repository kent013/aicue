<script lang="ts">
    import { Film } from "@lucide/svelte";
    import { TAKE_STATUS_LABELS, type SelectableTakeStatus } from "@/types/manual";

    /**
     * テイクのタイル。サムネイル生成は非同期なので、録画直後・生成失敗・過去分のテイクは
     * has_thumbnail=false になる。その場合は**同じ寸法の状態タイル**を描き、枠の大きさを
     * 変えない (生成完了後の再取得で同じ枠が画像へ置き換わる = レイアウトが跳ねない)。
     * 表示差し替え点をこの 1 コンポーネントに閉じている。
     */
    interface Props {
        /** 一覧内の 0 始まり位置 (表示は +1) */
        index: number;
        status: SelectableTakeStatus;
        durationMs: number | null;
        /** 生成済みサムネイルの URL (未生成は null) */
        thumbnailUrl: string | null;
        /** sm = 一覧タイル / lg = プレビュー枠の代替 */
        size?: "sm" | "lg";
        testId?: string;
    }

    let { index, status, durationMs, thumbnailUrl, size = "sm", testId }: Props = $props();

    const boxClass = $derived(size === "sm" ? "size-16" : "aspect-video w-full");
    const seconds = $derived(durationMs === null ? null : Math.round(durationMs / 1000));
</script>

{#if thumbnailUrl !== null}
    <img
        src={thumbnailUrl}
        alt=""
        loading="lazy"
        decoding="async"
        class="{boxClass} shrink-0 rounded-md border border-border object-cover"
        data-testid={testId}
    />
{:else}
    <div
        class="{boxClass} flex shrink-0 flex-col items-center justify-center gap-1 rounded-md border border-border bg-neutral text-text-secondary"
        data-testid={testId}
    >
        <Film class="size-4" aria-hidden="true" />
        <span class="text-caption">テイク {index + 1}</span>
        <span class="text-caption">
            {TAKE_STATUS_LABELS[status]}{#if seconds !== null}・{seconds} 秒{/if}
        </span>
    </div>
{/if}
