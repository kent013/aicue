<script lang="ts">
    import { onMount, tick } from "svelte";
    import { page, router } from "@inertiajs/svelte";
    import { ArrowLeft, BookOpen, Video } from "@lucide/svelte";
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
    import { buildCutLabels } from "@/lib/capture/cut-labels";
    import {
        isStackedLayout,
        navigateBackToList,
        navigateToPanelIfNeeded,
        prefersReducedMotion,
    } from "@/lib/capture/panel-navigation";
    import { createIdbPendingStore } from "@/lib/capture/idb";
    import { ThumbnailRefreshScheduler } from "@/lib/capture/thumbnail-refresh";
    import { generateClientTakeId, UploadQueue } from "@/lib/capture/upload-queue";
    import type { PendingStore, UploadOutcome } from "@/lib/capture/upload-queue";
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
    /** 手順 N / 急所 N-M。CutNavigator の行ラベルと同じ導出元を共有する (二重管理を避ける) */
    const cutLabels = $derived(buildCutLabels(manual.cuts));
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

    /* ---- manual 再取得は single-flight ----
     * アップロード成功 / キュー再開 / 自動 DL / サムネイル反映の 4 経路が同じ 1 本を通る。
     * 直列化しないと、古い応答での上書きと監視集合の判定ずれが起きる。 */
    // ★ in-flight の Promise を**保持して返す**。即解決する Promise を返すと、
    //   scheduler が「再取得が終わった」と誤認して古い manual のまま次の試行を消費する。
    let inFlight: Promise<void> | null = null;
    function reloadManual(): Promise<void> {
        if (inFlight !== null) return inFlight; // 並行呼び出しには同じ Promise を返す
        inFlight = new Promise<void>((resolve) => {
            router.reload({
                only: ["manual"],
                // onFinish は成功・失敗・キャンセルのいずれでも呼ばれる契約に依存している
                onFinish: () => {
                    inFlight = null;
                    resolve();
                },
            });
        });

        return inFlight;
    }

    /* ---- サムネイル生成の有界な反映 (T183) ----
     * この端末がこのセッションで登録したテイクだけを監視し、生成完了で画像へ差し替える。
     * 停止条件・有界性の単位は lib/capture/thumbnail-refresh.ts の docblock が正本。 */
    const thumbnails = new ThumbnailRefreshScheduler(reloadManual);

    // reload 後の最新 manual だけで監視集合を更新する
    $effect(() => {
        thumbnails.sync(manual);
    });

    /* ---- 撮影パネルへの視点/フォーカス移送 (F-1-03) ----
     * 1 カラム表示ではシナリオ一覧の下に撮影パネルが縦積みされるため、カットをタップしても
     * 撮影パネルが viewport に入らず、ユーザーが毎回手動スクロールしていた。
     * 判定と副作用は lib/capture/panel-navigation.ts が持つ (page は配線だけ)。 */
    let leftPaneEl = $state<HTMLElement | null>(null);
    let rightPaneEl = $state<HTMLElement | null>(null);
    let recordingHeadingEl = $state<HTMLElement | null>(null);
    let cutListHeadingEl = $state<HTMLElement | null>(null);
    /** 縦積みか (= 1 カラム)。「カット一覧へ戻る」の出し分けに使う */
    let stacked = $state(false);

    function updateStacked(): void {
        if (leftPaneEl === null || rightPaneEl === null) return;
        stacked = isStackedLayout(
            leftPaneEl.getBoundingClientRect(),
            rightPaneEl.getBoundingClientRect(),
        );
    }

    function handleSelectCut(cutId: number): void {
        selectedCutId = cutId;
        // DOM 反映後に測る (撮影パネルは選択で初めて描画される)
        void tick().then(() => {
            updateStacked();
            navigateToPanelIfNeeded({
                captureActive,
                leftEl: leftPaneEl,
                rightEl: rightPaneEl,
                headingEl: recordingHeadingEl,
                reducedMotion: prefersReducedMotion(),
            });
        });
    }

    /** 視点で運んだ以上、帰り道も用意する (行き先のない詰みを作らない) */
    function backToCutList(): void {
        navigateBackToList(cutListHeadingEl, prefersReducedMotion());
    }

    $effect(() => {
        if (leftPaneEl === null || rightPaneEl === null) return;
        // observer の初回 callback はタイミング差があるため当てにせず、登録前に必ず 1 回測る
        updateStacked();
        if (typeof ResizeObserver === "undefined") return;
        const observer = new ResizeObserver(() => updateStacked());
        observer.observe(leftPaneEl);
        observer.observe(rightPaneEl);
        return () => observer.disconnect();
    });

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
                thumbnails.watch(outcome.clientTakeId); // この端末が登録したテイクだけを監視する
                void reloadManual();
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
        if (changed) void reloadManual();
    }

    async function resumeUploads(): Promise<void> {
        uploading = true;
        try {
            const outcomes = await queue.resume();
            // ★ キュー経由は**複数件**が一度に確定しうる。uploaded を 1 件も watch しないと、
            //   最初の reload 時点で未生成だったテイクは以後まったく反映されない
            //   (= オフライン撮影の主経路が取り残される)。
            const uploaded = outcomes.filter(
                (outcome): outcome is Extract<UploadOutcome, { status: "uploaded" }> =>
                    outcome.status === "uploaded",
            );
            for (const outcome of uploaded) {
                thumbnails.watch(outcome.clientTakeId);
            }
            if (uploaded.length > 0) {
                void reloadManual(); // 件数によらず 1 回だけ (single-flight とも整合する)
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
            thumbnails.stop(); // unmount 後に再取得が走らないようにする
        };
    });

    function handleVisibility(): void {
        // 非表示の間は再取得を止める (停止条件の 1 つ)。復帰でキュー再開と一緒に再開する
        if (document.visibilityState !== "visible") {
            thumbnails.pause();
            return;
        }
        thumbnails.resume();
        void resumeUploads();
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
            <!-- PC 側詳細への復路 (T155)。**この画面へ到達できた利用者に対しては、追加の
                 status / ability 条件で出し分けない**。根拠と保証範囲は
                 docs/architecture.md §撮影 PWA の運用契約。 -->
            <TextLink
                href={`/projects/${project.id}/manuals/${manual.id}`}
                testId="manual-detail-link"
            >
                <BookOpen class="inline size-3" aria-hidden="true" />
                マニュアル詳細へ
            </TextLink>
        </PageHeaderSection>

        <div class="mt-3">
        <UploadQueueBar {pendingCount} {pendingBytes} {uploading} {quotaMessage} onResume={resumeUploads} />
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2" data-testid="capture-grid">
        <section
            bind:this={leftPaneEl}
            class="min-w-0 rounded-md border border-border bg-surface"
            data-testid="capture-left-pane"
        >
            <!-- 「カット一覧へ戻る」のフォーカス着地点。tabindex="-1" でプログラムからのみ
                 フォーカス可能にする (Tab 順には入れない)。 -->
            <h2
                bind:this={cutListHeadingEl}
                tabindex="-1"
                class="border-b border-border px-3 py-2 text-caption text-text-secondary focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
                data-testid="capture-cut-list-heading"
            >
                シナリオ (タップして撮影)
            </h2>
            <CutNavigator cuts={manual.cuts} {selectedCutId} onSelect={handleSelectCut} />
        </section>

        <section
            bind:this={rightPaneEl}
            class="flex min-w-0 flex-col gap-4"
            data-testid="capture-right-pane"
        >
            {#if selectedCut === null}
                <p class="text-caption text-text-secondary">
                    左のシナリオからカットを選ぶと撮影パネルが開きます。
                </p>
            {:else}
                <div class="flex items-center justify-between gap-2">
                    <!-- カット選択時のフォーカス着地点。ラベルを含めて「どのカットの撮影か」を
                         名前で伝える (視点だけ運んでフォーカスを残すと a11y 欠落を作るため)。 -->
                    <h2
                        bind:this={recordingHeadingEl}
                        tabindex="-1"
                        class="text-caption text-text-secondary focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
                        data-testid="capture-recording-heading"
                    >
                        {cutLabels[selectedCut.id] ?? "選択中カット"} の撮影
                    </h2>
                    {#if stacked}
                        <!-- 1 カラムのときだけ出す (2 カラムでは一覧が常に見えているので不要)。
                             TextLink のボタンモード (href なし + onclick) = <button type="button">。 -->
                        <TextLink onclick={backToCutList} testId="back-to-cut-list">
                            カット一覧へ戻る
                        </TextLink>
                    {/if}
                </div>

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
                    cutLabel={cutLabels[selectedCut.id] ?? "選択中カット"}
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
