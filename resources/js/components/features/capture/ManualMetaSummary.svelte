<script lang="ts">
    import { Clock } from "@lucide/svelte";
    import { formatDate } from "@/lib/date-format";
    import { formatDurationMs } from "@/lib/manual/format-duration";

    /**
     * 撮影 PWA シナリオ詳細のメタ情報 (doc/05 §5.2: タイトル / TIME 合計 / カテゴリ・日付・作成者)。
     * タイトルは PageHeaderSection の h1 が持つので、ここは残り 4 つを出す。
     *
     * **合計時間は「いま尺が確定している分」の合計**であり完成動画の見込み尺ではない。
     * 判定も整形規則もサーバから来た 2 つの値だけで決め、ここで条件を足さない
     * (秘匿境界も算出も props 側で解決済み)。
     *
     * PC 一覧の「再生時間」(公開済み完成動画の実尺) とは**別の量**なので同じ語を使わない。
     */
    interface Props {
        categoryName: string | null;
        creatorName: string | null;
        updatedAt: string | null;
        /** 確定分の合計 (ms)。1 本も確定していなければ null */
        totalDurationMs: number | null;
        /** 尺が確定していないカット数 */
        undeterminedCutCount: number;
    }

    let {
        categoryName,
        creatorName,
        updatedAt,
        totalDurationMs,
        undeterminedCutCount,
    }: Props = $props();

    /**
     * 未確定が 1 件でもあれば、値が部分和であることを値の隣で言う。
     *
     * **`totalDurationMs === null` (全件未確定) のときは「確定分・」を前置しない**
     * (「確定分・未確定 5 カット」と書くと、確定分が実在するかのように読めてしまう —
     * 合計は `—` で確定分自体が無いため)。
     */
    const durationNote = $derived(
        undeterminedCutCount === 0
            ? null
            : totalDurationMs === null
                ? `未確定 ${undeterminedCutCount} カット`
                : `確定分・未確定 ${undeterminedCutCount} カット`,
    );
</script>

<div
    class="rounded-md border border-border bg-surface px-3 py-2"
    data-testid="capture-manual-meta"
>
    <p class="flex items-center gap-1 text-body" data-testid="capture-manual-duration">
        <Clock class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
        合計時間 {formatDurationMs(totalDurationMs)}{#if durationNote !== null}<span
                class="text-caption text-text-secondary">（{durationNote}）</span
            >{/if}
    </p>
    <p class="mt-1 text-caption text-text-secondary" data-testid="capture-manual-meta-line">
        {categoryName ?? "未分類"} ・ {creatorName ?? "不明"} ・ 更新 {formatDate(updatedAt)}
    </p>
</div>
