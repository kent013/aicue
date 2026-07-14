<script lang="ts">
    import { Captions, CaptionsOff } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Modal from "@/components/organisms/Modal.svelte";
    import type { CaptureCut, CaptureTake } from "@/types/capture";

    /**
     * テイク単体のインラインプレビュー再生 (T050 / S2)。
     * 生映像を native <video controls> で再生し、cut の固定字幕を overlay で重ねる。
     * 採用ボタンを同居させ、確認しながらそのまま採用できる (doc/04・doc/05)。
     * 字幕は timed track ではなく cut 固定字幕の全編 overlay (構図確認用途)。
     */
    interface Props {
        open: boolean; // bindable
        take: CaptureTake | null; // 再生対象 (null で閉)
        cut: CaptureCut; // 字幕 (subtitle_primary/secondary) の供給元
        playbackUrl: string | null; // takeUrl(take, "/playback")。親が組み立て
        adopting: boolean; // 採用 XHR 中
        error: string | null; // 採用失敗メッセージ (親の run() error を流用)
        onAdopt: () => void; // 親の adopt() を呼ぶ
        onClose: () => void; // 親: dialog close + 録画復帰
    }

    let {
        open = $bindable(false),
        take,
        cut,
        playbackUrl,
        adopting,
        error,
        onAdopt,
        onClose,
    }: Props = $props();

    let video: HTMLVideoElement | undefined = $state();
    let subtitlesOn = $state(true);

    // 再オープン時に字幕を初期 ON へ戻す (撮影 PWA は初期 ON。doc/05)。
    $effect(() => {
        if (open) {
            subtitlesOn = true;
        }
    });

    // video のデコード資源/ネットワーク接続を完全解放する。
    function teardownVideo(target: HTMLVideoElement): void {
        target.pause();
        target.removeAttribute("src");
        target.load();
    }

    // close / 採用成功で閉じる / take 差し替え / component 破棄を同一 cleanup で扱う。
    // effect 実行時の要素を固定し、差し替え時に新要素を誤 teardown しない。
    $effect(() => {
        if (!open || take === null || video === undefined) return;
        const target = video;
        return () => teardownVideo(target);
    });

    // Modal の bind:open が true→false に遷移した時のみ親へ通知する
    // (背景クリック / Esc / × / 閉じるボタン / 採用成功をすべて拾う)。
    // 初期 mount の false では発火させない (wasOpen ガード)。
    let wasOpen = false;
    $effect(() => {
        if (wasOpen && !open) {
            onClose();
        }
        wasOpen = open;
    });
</script>

<Modal bind:open title="テイクのプレビュー" size="lg" processing={adopting} testId="take-preview-dialog">
    <div class="flex flex-col gap-3">
        <div class="relative w-full overflow-hidden rounded-md bg-text/5">
            {#if open && take !== null}
                {#key take.id}
                    <!-- svelte-ignore a11y_media_has_caption -->
                    <video
                        bind:this={video}
                        controls
                        playsinline
                        src={playbackUrl ?? undefined}
                        class="w-full"
                        data-testid="take-preview-video"
                    ></video>
                {/key}
            {/if}

            {#if subtitlesOn}
                <div class="pointer-events-none absolute inset-0 flex flex-col justify-between p-3">
                    {#if cut.subtitle_primary !== null && cut.subtitle_primary !== ""}
                        <span
                            class="self-start rounded-sm bg-surface/80 px-2 py-1 text-caption text-text-secondary"
                            aria-live="off"
                            data-testid="take-preview-subtitle-primary"
                        >
                            {cut.subtitle_primary}
                        </span>
                    {:else}
                        <span></span>
                    {/if}
                    {#if cut.subtitle_secondary !== ""}
                        <span
                            class="self-stretch rounded-sm bg-surface/80 px-2 py-1 text-body text-text"
                            aria-live="off"
                            data-testid="take-preview-subtitle-secondary"
                        >
                            {cut.subtitle_secondary}
                        </span>
                    {/if}
                </div>
            {/if}
        </div>

        <div class="flex items-center justify-between gap-2">
            <Button
                variant="ghost"
                size="sm"
                onclick={() => (subtitlesOn = !subtitlesOn)}
                ariaExpanded={subtitlesOn}
                testId="take-preview-subtitle-toggle"
            >
                {#if subtitlesOn}
                    <Captions class="size-4" aria-hidden="true" />
                    字幕を隠す
                {:else}
                    <CaptionsOff class="size-4" aria-hidden="true" />
                    字幕を表示
                {/if}
            </Button>
        </div>

        {#if error}
            <p class="text-caption text-danger" role="alert" data-testid="take-preview-error">
                {error}
            </p>
        {/if}
    </div>

    {#snippet footer()}
        <Button variant="neutral" onclick={() => (open = false)} testId="take-preview-close">
            閉じる
        </Button>
        <Button
            variant="primary"
            loading={adopting}
            onclick={onAdopt}
            testId="take-preview-adopt"
        >
            このテイクを採用する
        </Button>
    {/snippet}
</Modal>
