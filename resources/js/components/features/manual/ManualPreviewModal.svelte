<script lang="ts">
    import Modal from "@/components/organisms/Modal.svelte";
    import type { ManualListItem } from "@/types/manual";
    import { currentOrgUrl } from "@/lib/org-url";

    /**
     * 動画一覧からの完成動画プレビュー (doc/04 一覧ページ「プレビュー（オーバーレイ）」)。
     *
     * - 再生/停止/音量/全画面はブラウザ標準の `video controls` が担う (自前の再生制御は作らない)
     * - **言語切替は持たない**。v1 は字幕のみ・`locale=ja` 固定で、切り替える対象の成果物が
     *   1 つも無い。多言語が入る日に一覧・詳細・DL をまとめて設計する
     * - src は**同一オリジンのアプリ route** (302 で S3 署名 URL へ飛ぶ)。署名 URL は props にも
     *   HTML にも現れないため、認証済み画面の 3 枚セット (no-store / bfcache 秘匿 /
     *   Inertia history 暗号化) の前提を変えない
     * - 描画するのは**サーバが再生可と判断した行だけ** (current_finished_render_job_id が非 null)。
     *   published も権限も UI 側で再判定しない
     */
    interface Props {
        projectId: number;
        /** 再生対象の行。null = 未選択 (open=false のときのみ) */
        manual: ManualListItem | null;
        /** 開閉状態 (bindable)。呼び出し側が $state で保持し bind:open する */
        open: boolean;
    }

    let { projectId, manual, open = $bindable(false) }: Props = $props();

    /**
     * 再生 URL。**行 props の id からのみ**組み立てる (status や権限から導出しない)。
     * 閉じている間は Modal が中身を DOM に載せないため、署名 URL の発行要求は
     * オーバーレイを開いたときだけ起きる。
     */
    const playbackSrc = $derived(
        manual === null || manual.current_finished_render_job_id === null
            ? null
            : currentOrgUrl(`/projects/${projectId}/manuals/${manual.id}/render-jobs/`) +
              `${manual.current_finished_render_job_id}/playback`,
    );
</script>

<Modal bind:open title={manual?.title ?? "プレビュー"} size="lg" testId="manual-preview-modal">
    <!-- manual を条件に含めるのは型と意図を揃えるため。
         playbackSrc が非 null なら manual も非 null だが、その含意を読み手と型検査に委ねない -->
    {#if manual !== null && playbackSrc !== null}
        <!-- svelte-ignore a11y_media_has_caption (完成動画の字幕は焼き込み済み) -->
        <!-- preload="none": ブラウザに事前取得しないよう指示し、意図しない先読みを抑制する
             (ヒントであって要求ゼロの保証ではない)。
             RenderPanel の完成動画と同じ値に揃える = 2 通りの流儀を作らない。
             尺は一覧行が duration_ms で既に出しているため、metadata を先読みしても
             操作回数は減らず、得られる情報も増えない。
             autoplay は付けない: 音声付き autoplay はブラウザポリシーで拒否される環境があり
             「押したのに再生されない」が環境依存で生まれるため、再生開始は標準 controls に委ねる -->
        <video
            controls
            preload="none"
            class="w-full rounded-md bg-neutral"
            src={playbackSrc}
            aria-label={`${manual.title} の完成動画`}
            data-testid="manual-preview-video"
        ></video>
    {/if}
</Modal>
