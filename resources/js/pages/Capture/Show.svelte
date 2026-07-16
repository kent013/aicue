<script lang="ts">
    import { onMount } from "svelte";
    import { page, router } from "@inertiajs/svelte";
    import { ArrowLeft, Video } from "@lucide/svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageHeaderSection from "@/components/molecules/PageHeaderSection.svelte";
    import CameraRecorder from "@/components/features/capture/CameraRecorder.svelte";
    import type CameraRecorderType from "@/components/features/capture/CameraRecorder.svelte";
    import CaptureFileFallback from "@/components/features/capture/CaptureFileFallback.svelte";
    import CutNavigator from "@/components/features/capture/CutNavigator.svelte";
    import TakeStrip from "@/components/features/capture/TakeStrip.svelte";
    import UploadQueueBar from "@/components/features/capture/UploadQueueBar.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import { AdoptedTakeAutoDownloader } from "@/lib/capture/auto-download";
    import { supportsMediaRecorder } from "@/lib/capture/camera";
    import type { CameraUnavailableReason } from "@/lib/capture/camera";
    import { createIdbPendingStore } from "@/lib/capture/idb";
    import { generateClientTakeId, UploadQueue } from "@/lib/capture/upload-queue";
    import type { PendingStore } from "@/lib/capture/upload-queue";
    import type { SharedProps } from "@/lib/shared-props";
    import type { CaptureManualDetail } from "@/types/capture";

    /**
     * 撮影ナビ (doc/05 / 概念設計 D9)。cut を選び、録画 (または ファイル選択) →
     * 即時アップロード (upload-url → S3 PUT → POST takes)。失敗/オフラインは IndexedDB に
     * 一時保持し、フォアグラウンド復帰 / online / SW message で再送する。
     */
    interface Props {
        project: { id: number; name: string };
        manual: CaptureManualDetail;
    }

    let { project, manual }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    let selectedCutId = $state<number | null>(null);
    const selectedCut = $derived(manual.cuts.find((cut) => cut.id === selectedCutId) ?? null);
    // 静的 feature-detect (従来) + 実行時失敗による上書き (F-03: doc/10 §10.8-3)
    const canRecord = typeof window !== "undefined" && supportsMediaRecorder();
    let cameraUnavailableReason = $state<CameraUnavailableReason | null>(null);
    const showRecorder = $derived(canRecord && cameraUnavailableReason === null);
    // 撮影 active (recording|stopping) と recorder 参照 (preview の資源競合制御。T050 / S4)
    let captureActive = $state(false);
    let recorderRef = $state<CameraRecorderType | null>(null);
    // 実行時フォールバックの説明文 (reason で出し分け。静的 feature-detect 由来は
    // CaptureFileFallback 既存の説明文だけで足りるため notice なし)
    const fallbackNotice = $derived.by(() => {
        if (cameraUnavailableReason === null) return null;
        if (cameraUnavailableReason === "permission_denied") {
            return "カメラを利用できないため、ファイル選択でのアップロードに切り替えました。カメラで撮影する場合はブラウザまたは端末・組織のカメラ設定を確認して再読み込みしてください。";
        }
        return "この端末ではカメラ録画を利用できないため、ファイル選択でのアップロードに切り替えました。";
    });

    /* ---- アップロードキュー ---- */
    const store: PendingStore = createIdbPendingStore();
    const queue = new UploadQueue({ store });

    /* ---- 採用済みテイクの自動 DL (T051) ----
     * project.id / manual.id はインスタンス生存中は安定 (別 manual へ遷移すると Inertia が
     * ページを remount する。reload({only:["manual"]}) は id を変えない)。mount 時点の値で
     * 確定させるのが意図どおりなので state_referenced_locally を明示的に無視する。 */
    // svelte-ignore state_referenced_locally
    const autoDownloader = new AdoptedTakeAutoDownloader(project.id, manual.id);
    let pendingCount = $state(0);
    let pendingBytes = $state(0);
    let uploading = $state(false);
    let quotaMessage = $state<string | null>(null);

    async function refreshPending(): Promise<void> {
        const items = await store.list();
        pendingCount = items.length;
        pendingBytes = items.reduce((sum, item) => sum + item.blob.size, 0);
        quotaMessage = queue.quotaMessage;
    }

    function reloadManual(): void {
        router.reload({ only: ["manual"] });
    }

    async function handleCaptured(blob: Blob, mimeType: string, durationMs: number | null): Promise<void> {
        if (selectedCutId === null) return;
        uploading = true;
        try {
            const outcome = await queue.enqueue({
                clientTakeId: generateClientTakeId(),
                projectId: project.id,
                manualId: manual.id,
                cutId: selectedCutId,
                blob,
                contentType: mimeType.split(";")[0],
                durationMs,
                capturedAt: new Date().toISOString(),
            });
            if (outcome.status === "uploaded") {
                reloadManual();
            }
        } finally {
            uploading = false;
            await refreshPending();
        }
    }

    // 入室時 / online 復帰時に採用済み未 DL テイクを自動取得する。changed のときのみ
    // reload を 1 回行う (複数採用テイクでも reload は 1 回)。多重発火は内部 running ガードが抑止。
    // reload 後は downloaded=true で対象が空になるため再 DL は起きない (冪等)。
    async function runAutoDownload(): Promise<void> {
        const { changed } = await autoDownloader.run(manual);
        if (changed) reloadManual();
    }

    async function resumeUploads(): Promise<void> {
        uploading = true;
        try {
            const outcomes = await queue.resume();
            if (outcomes.some((outcome) => outcome.status === "uploaded")) {
                reloadManual();
            }
        } finally {
            uploading = false;
            await refreshPending();
        }
    }

    onMount(() => {
        void refreshPending();
        void runAutoDownload();

        // SW 登録 (Capture ページ mount 時に限定。素の JS・/build/* のみキャッシュ)
        if ("serviceWorker" in navigator) {
            void navigator.serviceWorker.register("/capture-sw.js");
            navigator.serviceWorker.addEventListener("message", handleSwMessage);
        }
        // フォアグラウンド復帰 / online でキュー再開 (Background Sync 非依存。概念設計 D9)
        document.addEventListener("visibilitychange", handleVisibility);
        window.addEventListener("online", handleOnline);

        return () => {
            document.removeEventListener("visibilitychange", handleVisibility);
            window.removeEventListener("online", handleOnline);
            if ("serviceWorker" in navigator) {
                navigator.serviceWorker.removeEventListener("message", handleSwMessage);
            }
        };
    });

    function handleVisibility(): void {
        if (document.visibilityState === "visible") void resumeUploads();
    }

    function handleOnline(): void {
        // resumeUploads と runAutoDownload は独立・順序非依存 (将来回帰防止のため明記)
        void resumeUploads();
        void runAutoDownload();
    }

    function handleSwMessage(event: MessageEvent): void {
        if (event.data === "resume-uploads") void resumeUploads();
    }
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeaderSection title={manual.title} icon={Video} testId="capture-manual-title">
            <TextLink href={`/app/projects/${project.id}/manuals`}>
                <ArrowLeft class="inline size-3" aria-hidden="true" />
                一覧へ戻る
            </TextLink>
        </PageHeaderSection>

        <div class="mt-3">
        <UploadQueueBar {pendingCount} {pendingBytes} {uploading} {quotaMessage} onResume={resumeUploads} />
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2" data-testid="capture-grid">
        <section class="min-w-0 rounded-md border border-border bg-surface" data-testid="capture-left-pane">
            <h2 class="border-b border-border px-3 py-2 text-caption text-text-secondary">
                シナリオ (タップして撮影)
            </h2>
            <CutNavigator
                cuts={manual.cuts}
                {selectedCutId}
                onSelect={(cutId) => (selectedCutId = cutId)}
            />
        </section>

        <section class="flex min-w-0 flex-col gap-4" data-testid="capture-right-pane">
            {#if selectedCut === null}
                <p class="text-caption text-text-secondary">
                    左のシナリオからカットを選ぶと撮影パネルが開きます。
                </p>
            {:else}
                <div class="rounded-md border border-border bg-surface p-3">
                    <p class="text-caption text-text-secondary">ナレーション</p>
                    <p class="mt-1 text-body">{selectedCut.narration}</p>
                    {#if selectedCut.shooting_point}
                        <p class="mt-2 text-caption text-text-secondary">
                            撮影ポイント: {selectedCut.shooting_point}
                        </p>
                    {/if}
                </div>

                {#if showRecorder}
                    <CameraRecorder
                        bind:this={recorderRef}
                        onCaptured={(blob, mimeType, durationMs) =>
                            handleCaptured(blob, mimeType, durationMs)}
                        onCameraUnavailable={(reason) => (cameraUnavailableReason = reason)}
                        subtitlePrimary={selectedCut.subtitle_primary}
                        subtitleSecondary={selectedCut.subtitle_secondary}
                        onCaptureActiveChange={(active) => (captureActive = active)}
                    />
                {:else}
                    {#if fallbackNotice !== null}
                        <p
                            class="text-caption text-text-secondary"
                            role="status"
                            data-testid="camera-fallback-notice"
                        >
                            {fallbackNotice}
                        </p>
                    {/if}
                    <CaptureFileFallback
                        onCaptured={(file) => handleCaptured(file, file.type, null)}
                    />
                {/if}

                <TakeStrip
                    projectId={project.id}
                    manualId={manual.id}
                    cut={selectedCut}
                    onChanged={reloadManual}
                    {captureActive}
                    onRequestCameraRelease={() => recorderRef?.releaseForPreview()}
                    onCameraResume={() => void recorderRef?.resumeAfterPreview()}
                />
            {/if}
        </section>
        </div>
    </PageContainer>
</AppLayout>
