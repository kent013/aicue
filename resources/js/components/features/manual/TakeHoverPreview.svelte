<script lang="ts">
    import { onDestroy, onMount } from "svelte";
    import { Link } from "@inertiajs/svelte";
    import { Film } from "@lucide/svelte";
    import { prefersReducedMotion } from "@/lib/capture/panel-navigation";

    /**
     * サムネイルにマウスを載せている間だけ、そのテイクを**無音・ループ**で自動再生する
     * (doc/04 動画列「登録済みテイクはサムネイル表示 (ホバーで自動再生)」)。
     *
     * 設計上の約束 (誇張しない):
     * - <video> は**ホバー中しか DOM に存在しない**ので、**1 コンポーネントにつき高々 1 本**である。
     *   画面全体で 1 本に収まるのは「マウスが同時に 1 か所しかホバーできない」ことに依る性質で、
     *   この component が画面横断の相互排他を保証しているわけではない。
     * - **タッチ・ペンでは起動しない**。ホバーの無い環境ではリンク (タップ = 遷移) として働く。
     *   同じ場所のタップに「遷移」と「再生」の 2 つの意味を持たせない。
     * - `prefers-reduced-motion: reduce` では起動しない (静止画のまま)。
     * - 失敗は静かに静止画へ戻す。**エラー文言・トーストは出さない**
     *   (ホバーは補助的な確認手段であり、失敗が編集作業を妨げてはならない)。
     */
    interface Props {
        /** 静止サムネイルの URL (capture.takes.thumbnail)。未生成なら null */
        thumbnailUrl: string | null;
        /** 再生 URL (capture.takes.playback)。ready でなければ null */
        playbackUrl: string | null;
        /** クリック / タップの行き先 (テイク選択画面) */
        href: string;
        /** リンクの読み上げ名 (画像は装飾扱いで alt="") */
        label: string;
        testId?: string;
    }

    let { thumbnailUrl, playbackUrl, href, label, testId }: Props = $props();

    /** 滞留タイマー。null = 予約なし */
    let dwellTimer: ReturnType<typeof setTimeout> | null = null;
    /** ポインタがまだ載っているか (満了時の再確認に使う) */
    let hovering = false;
    /** 再生中か = <video> を mount しているか */
    let playing = $state(false);
    /** 世代判定の基準。現在 mount されている video 要素 */
    let videoEl: HTMLVideoElement | null = null;

    const DWELL_MS = 200;

    /** 起動条件を満たすポインタか (タッチ・ペン / ボタン押下中は起動しない) */
    function isPreviewablePointer(event: PointerEvent): boolean {
        return event.pointerType === "mouse" && event.buttons === 0;
    }

    function onPointerEnter(event: PointerEvent): void {
        hovering = true;
        if (playbackUrl === null) return;
        if (!isPreviewablePointer(event)) return;
        if (prefersReducedMotion()) return;
        clearDwell();
        dwellTimer = setTimeout(startPreview, DWELL_MS);
    }

    /**
     * 満了時の再確認は 3 つだけ:
     * (a) タイマーが無効化されていない (pointerdown 等は clearDwell でここへ来なくする)
     * (b) ホバーが継続している
     * (c) reduced-motion でない (200ms の間に設定が変わることがある)
     * ボタンの押下状態は**読み直さない**。pointerdown が停止条件としてタイマーそのものを
     * 破棄することで保証する (過去のイベントを現在の状態の代理にしない)。
     */
    function startPreview(): void {
        dwellTimer = null;
        if (!hovering) return;
        if (playbackUrl === null) return; // props が入れ替わった場合に備えて再確認する
        if (prefersReducedMotion()) return;
        playing = true;
    }

    /** 停止 (冪等)。タイマー clear と video unmount を必ず両方行う */
    function stopPreview(): void {
        clearDwell();
        playing = false;
        videoEl = null;
    }

    function clearDwell(): void {
        if (dwellTimer === null) return;
        clearTimeout(dwellTimer);
        dwellTimer = null;
    }

    function onPointerLeave(): void {
        hovering = false;
        stopPreview();
    }

    /** mount 後に再生を開始する。開始の正本は play() で、autoplay 属性は使わない */
    function onVideoMounted(el: Element): void {
        if (!(el instanceof HTMLVideoElement)) return;
        videoEl = el;
        el.muted = true; // 属性だけでなく property でも立てる (自動再生の許可条件)
        void el.play().catch(() => {
            // 自動再生ポリシーによる拒否。error イベントでは飛んでこない経路。
            // 古い試行の rejection が新しい試行を止めないよう、要素の同一性で世代を判定する
            if (videoEl === el) stopPreview();
        });
    }

    /** 取得・デコード失敗。世代判定は play() の catch と同じ規則 */
    function onVideoError(event: Event): void {
        if (videoEl === event.currentTarget) stopPreview();
    }

    /** タブが隠れたら止める (見えない場所で再生し続けない) */
    function onVisibilityChange(): void {
        if (document.visibilityState === "hidden") stopPreview();
    }

    // 登録と解除を onMount の中で対にする。onMount はブラウザでしか走らず、
    // 返した後始末もブラウザでしか走らないため、`typeof document !== "undefined"` の
    // 自前 guard を書かずに非ブラウザ環境と対称になる (フレームワークのレンジ内でやる)。
    onMount(() => {
        document.addEventListener("visibilitychange", onVisibilityChange);
        return () => document.removeEventListener("visibilitychange", onVisibilityChange);
    });

    // document に触らない後始末だけを onDestroy に置く (予約済みタイマーを捨てる)
    onDestroy(clearDwell);
</script>

<Link
    {href}
    class="relative block size-16 shrink-0 overflow-hidden rounded-md border border-border bg-neutral"
    aria-label={label}
    data-testid={testId}
    onpointerenter={onPointerEnter}
    onpointerleave={onPointerLeave}
    onpointercancel={onPointerLeave}
    onpointerdown={stopPreview}
>
    {#if playing && playbackUrl !== null}
        <!-- svelte-ignore a11y_media_has_caption -->
        <video
            {@attach onVideoMounted}
            src={playbackUrl}
            poster={thumbnailUrl}
            muted
            loop
            playsinline
            preload="metadata"
            class="size-full object-cover"
            aria-hidden="true"
            onerror={onVideoError}
            data-testid={testId ? `${testId}-video` : undefined}
        ></video>
    {:else if thumbnailUrl !== null}
        <img
            src={thumbnailUrl}
            alt=""
            loading="lazy"
            decoding="async"
            class="size-full object-cover"
            data-testid={testId ? `${testId}-image` : undefined}
        />
    {:else}
        <span class="flex size-full items-center justify-center" aria-hidden="true">
            <Film class="size-4 text-text-secondary" />
        </span>
    {/if}
</Link>
