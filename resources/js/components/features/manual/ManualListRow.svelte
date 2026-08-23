<script lang="ts">
    import { Download, Play, Trash2 } from "@lucide/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import { formatDurationMs } from "@/lib/manual/format-duration";
    import type { ManualListItem } from "@/types/manual";
    import { MANUAL_PROGRESS_LABELS, MANUAL_PROGRESS_TONES } from "@/types/manual";
    import { currentOrgUrl } from "@/lib/org-url";

    /**
     * 動画マニュアル一覧の 1 行 (doc/04: 状態 / タイトル / カテゴリ / 再生時間 / 更新日 /
     * プレビュー / DL / 削除)。
     *
     * 表示の出し分けは**サーバが決めた行 props だけ**で行う
     * (current_finished_render_job_id / deletable。published も ability も UI 側で再判定しない)。
     * プレビューと DL は**同じ props 1 本**で出し分ける (再生条件は download と完全同一 = T154)。
     * 実行は一覧ページが持つ (この component は要求を上へ返すだけ)。
     */
    interface Props {
        projectId: number;
        manual: ManualListItem;
        /** プレビュー (オーバーレイ再生) を開く要求 */
        onRequestPreview: (manual: ManualListItem) => void;
        /** 削除確認ダイアログを開く要求 */
        onRequestDelete: (manual: ManualListItem) => void;
    }

    let { projectId, manual, onRequestPreview, onRequestDelete }: Props = $props();

    const durationLabel = $derived(formatDurationMs(manual.duration_ms));
    /** 受け取れる完成動画があるか (プレビュー / DL の唯一の出し分け根拠) */
    const finishedRenderJobId = $derived(manual.current_finished_render_job_id);
</script>

<!-- 狭い画面では縦積み (操作群を次行へ逃がす)、sm 以上で現行と同じ横並びに戻す。
     操作が 2 つ増えて shrink-0 側が広がるため、モバイルで行が潰れないようにする -->
<li
    class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
    data-testid={`manual-row-${manual.id}`}
>
    <div class="min-w-0">
        <!-- タイトルは 1 行省略にする (空白の無い長いタイトルでも行の操作領域を押し出さない)。
             TextLink は class prop を受け取れるので、幅制約用の要素で包まずに付与する -->
        <TextLink
            href={currentOrgUrl(`/projects/${projectId}/manuals/${manual.id}`)}
            class="block truncate"
            testId={`manual-link-${manual.id}`}
        >
            {manual.title}
        </TextLink>
        <p class="mt-1 truncate text-caption text-text-secondary">
            {manual.category?.name ?? "未分類"} ・ {manual.creator?.name ?? "不明"} ・ 更新 {manual.updated_at}
        </p>
    </div>
    <div class="flex shrink-0 items-center gap-2">
        <!-- 再生時間: 公開済みの完成動画の長さ。未確定は「—」。権限では隠さない -->
        <span
            class="text-caption text-text-secondary"
            data-testid={`manual-duration-${manual.id}`}
        >
            {durationLabel}
        </span>
        <!-- 一覧の状態は 3 値 (絞り込みと同じ語彙でないと絞り込み結果を説明できない)。
             「解析中 / 書き出し中」の実況は詳細画面 (AnalysisPanel / RenderPanel) が持つ -->
        <Badge tone={MANUAL_PROGRESS_TONES[manual.progress]} testId={`manual-progress-${manual.id}`}>
            {MANUAL_PROGRESS_LABELS[manual.progress]}
        </Badge>
        <!-- 受け取れるとサーバが判断した行にだけ出す。押せない (disabled) ボタンは作らない。
             出ていない行の理由は状態バッジと再生時間「—」が語り、書き出しの CTA は
             詳細画面 (RenderPanel) が唯一持つ。
             プレビューと DL は同じ条件 (playback の完成動画条件 = download 条件) なので
             同じ枝に置く = 2 つの条件を持たない。 -->
        {#if finishedRenderJobId !== null}
            <Button
                variant="ghost"
                size="sm"
                onclick={() => onRequestPreview(manual)}
                ariaLabel={`${manual.title} の完成動画をプレビュー`}
                testId={`manual-preview-${manual.id}`}
            >
                <Play class="size-4" />
                プレビュー
            </Button>
            <!-- 素の <a> (inertia なし) = 非 Inertia 遷移。成功時は attachment 応答のため
                 画面は遷移しない。 -->
            <Button
                variant="ghost"
                size="sm"
                href={currentOrgUrl(`/projects/${projectId}/manuals/${manual.id}/download`)}
                ariaLabel={`${manual.title} の完成動画をダウンロード`}
                testId={`manual-download-${manual.id}`}
            >
                <Download class="size-4" />
                DL
            </Button>
        {/if}
        {#if manual.deletable}
            <Button
                variant="danger-ghost"
                size="sm"
                onclick={() => onRequestDelete(manual)}
                ariaLabel={`${manual.title} を削除`}
                testId={`manual-remove-${manual.id}`}
            >
                <Trash2 class="size-4" />
                削除
            </Button>
        {/if}
    </div>
</li>
