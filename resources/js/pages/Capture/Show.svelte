<script lang="ts">
    import { onMount, tick, untrack } from "svelte";
    import { page, router } from "@inertiajs/svelte";
    import { ArrowLeft, BookOpen, ListVideo, Maximize, Minimize, Video } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageHeaderSection from "@/components/molecules/PageHeaderSection.svelte";
    import CameraRecorder from "@/components/features/capture/CameraRecorder.svelte";
    import type CameraRecorderType from "@/components/features/capture/CameraRecorder.svelte";
    import CaptureFileFallback from "@/components/features/capture/CaptureFileFallback.svelte";
    import CutNavigator from "@/components/features/capture/CutNavigator.svelte";
    import CutSwipeBar from "@/components/features/capture/CutSwipeBar.svelte";
    import ScenarioPreviewDialog from "@/components/features/capture/ScenarioPreviewDialog.svelte";
    import TakeStrip from "@/components/features/capture/TakeStrip.svelte";
    import UploadQueueBar from "@/components/features/capture/UploadQueueBar.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import { AdoptedTakeAutoDownloader } from "@/lib/capture/auto-download";
    import { supportsMediaRecorder } from "@/lib/capture/camera";
    import type { CameraUnavailableReason } from "@/lib/capture/camera";
    import { buildCutLabels } from "@/lib/capture/cut-labels";
    import {
        decideCutNavigation,
        lockBackgroundScroll,
        matchesLandscapeCapture,
        subscribeLandscapeCapture,
        type NavigationDirection,
    } from "@/lib/capture/landscape-capture";
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
        /** プレースホルダ表示秒数 (config manual.preview_placeholder_seconds)。単位は秒 */
        previewPlaceholderSeconds: number;
    }

    let { project, manual, previewPlaceholderSeconds }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    /**
     * 横持ち全画面の初期判定。**テンプレートの初回描画より前**に確定させるため、
     * script のこの位置 (props 受領直後) で 1 度だけ評価する。
     * これより後ろで宣言すると selectedCutId の初期化が宣言前参照 (TDZ) になる。
     */
    const initialLandscape = matchesLandscapeCapture();

    /* 初期描画で全画面になる場合は、**同じ script 評価の中で**先頭カットも選んでおく。
     * 選ばずに全画面へ入ると、最初の 1 描画だけ「カットを選び直してください。」が出る。
     * mount 時点の値で確定させるのが意図どおりなので state_referenced_locally を明示的に無視する
     * (以降の追従は横持ち購読の $effect が担う)。 */
    // svelte-ignore state_referenced_locally
    let selectedCutId = $state<number | null>(
        initialLandscape ? (manual.cuts[0]?.id ?? null) : null,
    );
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

    /* ---- 横持ち全画面 (doc/05 §5.2 / T186) ----
     * 判定・ジェスチャ解釈・移動判断・スクロール抑止は lib/capture/landscape-capture.ts が持ち、
     * ここは配線だけを行う (panel-navigation.ts と同じ役割分担)。 */
    /**
     * 横持ち全画面の条件 (向き + 高さ + 粗いポインタ) を満たすか。
     *
     * **初期値は script 評価時に確定させる** (initialLandscape)。`$effect` はテンプレートの
     * 初回描画の**後**に走るため、`$state(false)` から effect で入れる形にすると
     * 「最初の 1 描画だけ inline レイアウト」というちらつきが必ず残る。
     *
     * **この方式は「Inertia SSR が配線されていない」ことに依存する**。
     * SSR を入れるとサーバは inline、クライアントの初期評価は fullscreen になり得るため
     * hydration が食い違う (詳細設計の「再確認条件」)。
     */
    let landscapeMatches = $state(initialLandscape);
    /** 利用者が明示的に全画面を終了したか。**縦に戻すまで自動で入り直さない**ためのラッチ */
    let fullscreenDismissed = $state(false);
    /**
     * 実際に全画面を描くか。
     * **選択状態ではなく「撮るものがあるか」で決める** (`manual.cuts.length > 0`)。
     * `selectedCut !== null` を条件にすると、自動選択が反映される前の 1 フレームだけ
     * inline レイアウトが描かれてちらつく。また全画面中に reload で選択中カットが
     * 消えたときに「全画面なのに終了ボタンが無い」状態を作りかねない。
     */
    const fullscreenActive = $derived(
        landscapeMatches && !fullscreenDismissed && manual.cuts.length > 0,
    );
    /** 端の告知 (status) / 録画中の移動拒否 (alert)。文言の出所は landscape-capture.ts */
    let navigationNotice = $state<{ tone: "status" | "alert"; message: string } | null>(null);
    /** 全画面の現在位置表示 (1 起点)。cuts の並び順そのものを使う */
    const cutPosition = $derived({
        index: selectedCut === null ? 0 : manual.cuts.findIndex((c) => c.id === selectedCut.id) + 1,
        total: manual.cuts.length,
    });
    /** 全画面へ入った直後のフォーカス着地点 (背後に取り残さない)。tabindex="-1" */
    let fullscreenHeadingEl = $state<HTMLElement | null>(null);
    /** 直前に運んだ全画面状態。true への遷移でちょうど 1 回だけフォーカスを運ぶ */
    let lastFullscreenFocused = false;

    // 横持ち判定の購読。**初期値は script 評価時に確定済み**なので、この effect が担うのは
    // 「向きが変わったときの追従」だけである。追従に伴う後始末は同じ同期ブロックの中で済ませる
    // (2 本の effect に分けると、landscapeMatches が反映された描画と selectedCutId が
    //  入った描画の間に 1 フレーム挟まり、inline レイアウトが一瞬見えてしまう)。
    //  - 縦に戻ったらラッチを解除する (次に横へ倒せばまた自動で全画面に入る)
    //  - 横持ちでカット未選択なら先頭カットを自動選択する (何も撮れない全画面を作らない)
    // manual / selectedCutId は untrack で読む (選択やリロードで購読を張り直さない)。
    $effect(() =>
        subscribeLandscapeCapture((matches) => {
            landscapeMatches = matches;
            if (!matches) {
                fullscreenDismissed = false;

                return;
            }
            const first = untrack(() => manual.cuts)[0];
            if (first !== undefined && untrack(() => selectedCutId) === null) {
                selectedCutId = first.id;
            }
        }),
    );

    // 全画面へ入ったらフォーカスを全画面内へ運ぶ。
    // 背後 (ヘッダ / 左 pane) は inert にするが、AppLayout の chrome は覆わないため、
    // 開始位置を明示的に全画面内へ置くことでキーボード利用者が背後から始まらないようにする。
    $effect(() => {
        if (fullscreenActive === lastFullscreenFocused) return;
        lastFullscreenFocused = fullscreenActive;
        if (!fullscreenActive) return;
        fullscreenHeadingEl?.focus({ preventScroll: true });
    });

    // 全画面中だけ背後のスクロールを止める。**解除は戻り値の 1 か所に集約**する
    // (終了ボタン / 縦復帰 / ページ離脱のどれでも必ず外れる = スクロール不能の詰みを作らない)。
    $effect(() => {
        if (!fullscreenActive) return;

        return lockBackgroundScroll();
    });

    /**
     * 全画面でのカット移動 (スワイプ / 前後ボタン / 左右矢印キーの共通の受け口)。
     * 可否と文言の判断は decideCutNavigation が 1 か所で持つ (ここは配線だけ)。
     * **録画中は移動せずその場でエラーを出す** — 自動停止しない (誤スワイプで録画を確定させない)。
     */
    function handleCutNavigate(direction: NavigationDirection): void {
        const decision = decideCutNavigation({
            captureActive,
            cuts: manual.cuts,
            currentCutId: selectedCutId,
            direction,
        });
        if (decision.kind === "move") {
            navigationNotice = null;
            selectedCutId = decision.cutId;

            return;
        }
        if (decision.kind === "notice") {
            navigationNotice = { tone: decision.tone, message: decision.message };

            return;
        }
        navigationNotice = null; // ignore: 移動対象が無い (自動選択があるため通常は到達しない)
    }

    /**
     * 全画面を終了する。横持ちのまま既存レイアウトへ戻るので、
     * **現在位置を見失わせない**よう視点とフォーカスを撮影パネルへ運ぶ (既存機構を再利用)。
     */
    function exitFullscreen(): void {
        fullscreenDismissed = true;
        navigationNotice = null;
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

    /**
     * 全画面へ戻る手動の再入路。ラッチ (fullscreenDismissed) を解除する。
     * これが無いと「端末を一度縦に倒し直さないと全画面へ帰れない」行き止まりになる。
     * 未選択なら先頭カットを選ぶ (押しても何も起きない、を作らない)。
     */
    function enterFullscreen(): void {
        const first = manual.cuts[0];
        if (selectedCutId === null && first !== undefined) selectedCutId = first.id;
        navigationNotice = null;
        fullscreenDismissed = false;
    }

    function handleSelectCut(cutId: number): void {
        navigationNotice = null; // カットを選び直したら古い告知を捨てる
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

    /* ---- 通し再生 (全体連結プレビュー / T191) ---- */
    let scenarioPreviewOpen = $state(false);
    let scenarioPreviewError = $state<string | null>(null);

    /** カメラ資源の解放・復帰は page 側に 1 つずつ持つ (TakeStrip と同じ関数を渡す = 2 か所に書かない) */
    function releaseCameraForPreview(): void {
        recorderRef?.releaseForPreview();
    }

    function resumeCameraAfterPreview(): void {
        void recorderRef?.resumeAfterPreview();
    }

    /**
     * 撮影中はカメラ資源と競合するため開かない。**ボタンは disabled にせず、押下時に伝える**
     * (禁止事項 8)。判定条件・呼び出し順・文言は**既存の個別 preview (TakeStrip.openPreview) と
     * 同じ言い回し**に揃える (同じ制約を別の言葉で言わない)。
     *
     * `captureActive` は recording|stopping を含む。`releaseForPreview()` は同期の void 関数で、
     * 録画中・取得中は自分で早期 return するため await も失敗ハンドリングも要らない。
     */
    function openScenarioPreview(): void {
        scenarioPreviewError = null;
        if (captureActive) {
            scenarioPreviewError = "撮影中は通し再生を開始できません。撮影を停止してからお試しください。";

            return;
        }
        releaseCameraForPreview();
        scenarioPreviewOpen = true;
    }

    function closeScenarioPreview(): void {
        resumeCameraAfterPreview();
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
        <!-- 全画面中は背後を inert にして、覆われた面へ Tab で入り込めないようにする -->
        <div inert={fullscreenActive}>
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
        </div>

        <!-- UploadQueueBar は全画面かどうかで **どちらか一方にだけ** 置く
             (両方に置くと data-testid が重複してテストの指し先が曖昧になる)。
             UploadQueueBar は props だけの表示 component なので、
             切替時に作り直されても失われる状態が無い。 -->
        {#if !fullscreenActive}
            <div class="mt-3">
                <UploadQueueBar {pendingCount} {pendingBytes} {uploading} {quotaMessage} onResume={resumeUploads} />
            </div>
        {/if}

    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2" data-testid="capture-grid">
        <section
            bind:this={leftPaneEl}
            inert={fullscreenActive}
            class="min-w-0 rounded-md border border-border bg-surface"
            data-testid="capture-left-pane"
        >
            <div class="flex items-center justify-between gap-2 border-b border-border px-3 py-2">
                <!-- 「カット一覧へ戻る」のフォーカス着地点。tabindex="-1" でプログラムからのみ
                     フォーカス可能にする (Tab 順には入れない)。 -->
                <h2
                    bind:this={cutListHeadingEl}
                    tabindex="-1"
                    class="text-caption text-text-secondary focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
                    data-testid="capture-cut-list-heading"
                >
                    シナリオ (タップして撮影)
                </h2>
                <!-- 狭幅で 2 つのボタンが詰まらないよう折り返しを許す (justify-end で右寄せを保つ) -->
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <!-- 通し再生 (doc/05 §5.2 [プレビュー])。**カットが 1 枚も無いときは出さない**
                         (文脈非該当の非表示であって、条件未充足の disabled ではない)。
                         撮影中の押下は開かずにエラーを出す (資源競合。TakeStrip の個別 preview と同じ規則)。 -->
                    {#if manual.cuts.length > 0}
                        <Button
                            variant="neutral"
                            size="sm"
                            onclick={openScenarioPreview}
                            testId="scenario-preview-button"
                        >
                            <ListVideo class="size-4" aria-hidden="true" />
                            通し再生
                        </Button>
                    {/if}
                    <!-- 横持ちなのに全画面でないとき (= 明示終了した後) の再入路。
                         文脈非該当時は非表示にする (disabled ではない)。 -->
                    {#if landscapeMatches && !fullscreenActive && manual.cuts.length > 0}
                        <Button
                            variant="neutral"
                            size="sm"
                            onclick={enterFullscreen}
                            testId="enter-fullscreen-capture"
                        >
                            <Maximize class="size-4" aria-hidden="true" />
                            全画面で撮影
                        </Button>
                    {/if}
                </div>
            </div>
            {#if scenarioPreviewError !== null}
                <p
                    class="px-3 py-2 text-caption text-danger"
                    role="alert"
                    data-testid="scenario-preview-error"
                >
                    {scenarioPreviewError}
                </p>
            {/if}
            <CutNavigator cuts={manual.cuts} {selectedCutId} onSelect={handleSelectCut} />
        </section>

        <!--
          全画面は **この section の class を差し替えるだけ**で作る。
          CameraRecorder を別の {#if} ブランチへ移すと unmount され、録画中の
          MediaStream / MediaRecorder が破棄されて録ったデータが消えるため。
          fixed + h-dvh: iOS Safari の動的ツールバーで下端が隠れないようにする
          (inset-0 だと bottom がツールバー下へ潜りうる)。
          z-40: AppLayout のモバイルヘッダ (sticky z-30) を覆い、
          Toast (z-50) は上に残す (アップロード失敗の告知を隠さない)。
        -->
        <section
            bind:this={rightPaneEl}
            class={fullscreenActive
                ? "fixed inset-x-0 top-0 z-40 flex h-dvh min-w-0 flex-col gap-2 bg-surface p-2"
                : "flex min-w-0 flex-col gap-4"}
            data-testid="capture-right-pane"
            data-fullscreen={fullscreenActive ? "true" : "false"}
        >
            {#if fullscreenActive}
                <!-- 全画面へ入った直後のフォーカス着地点。読み上げ順の先頭に置く -->
                <h2
                    bind:this={fullscreenHeadingEl}
                    tabindex="-1"
                    class="sr-only"
                    data-testid="capture-fullscreen-heading"
                >
                    全画面撮影
                </h2>
                <UploadQueueBar {pendingCount} {pendingBytes} {uploading} {quotaMessage} onResume={resumeUploads} />
                <!--
                  **終了ボタンは selectedCut の有無に依らずここに置く**。
                  出口の有無を選択状態という別の軸に結び付けない
                  (結び付けると「全画面なのに出口が無い」状態を作りうる)。
                -->
                <div class="flex items-center gap-2">
                    <div class="min-w-0 flex-1">
                        {#if selectedCut !== null}
                            <CutSwipeBar
                                label={cutLabels[selectedCut.id] ?? "選択中カット"}
                                scene={selectedCut.scene}
                                position={cutPosition}
                                onNavigate={handleCutNavigate}
                            />
                        {:else}
                            <p class="text-caption text-text-secondary">
                                カットを選び直してください。
                            </p>
                        {/if}
                    </div>
                    <Button
                        variant="neutral"
                        size="sm"
                        onclick={exitFullscreen}
                        testId="exit-fullscreen-capture"
                    >
                        <Minimize class="size-4" aria-hidden="true" />
                        全画面を終了
                    </Button>
                </div>
                {#if navigationNotice !== null}
                    {#if navigationNotice.tone === "alert"}
                        <p
                            class="text-caption text-danger"
                            role="alert"
                            data-testid="cut-navigation-error"
                        >
                            {navigationNotice.message}
                        </p>
                    {:else}
                        <p
                            class="text-caption text-text-secondary"
                            role="status"
                            data-testid="cut-navigation-notice"
                        >
                            {navigationNotice.message}
                        </p>
                    {/if}
                {/if}
            {/if}

            {#if selectedCut === null}
                <p class="text-caption text-text-secondary">
                    左のシナリオからカットを選ぶと撮影パネルが開きます。
                </p>
            {:else}
                <!-- 全画面では見出し・ナレーション・テイク一覧を出さない
                     (撮影ガイドと字幕は映像上の overlay が担う)。
                     **CameraRecorder はこの {#if} を跨がない** = 位置が変わらない。 -->
                {#if !fullscreenActive}
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
                {/if}

                <!-- 全画面では残り高さいっぱいに広げる。**要素そのものは同じ** (class だけ変わる)。
                     inline 側を素の div にせず flex-col gap-4 にしてあるのは、この wrapper を
                     挟んだことで fallback 経路 (notice + ファイル選択) の間隔が消えるのを防ぐため
                     (従来は section 直下の兄弟として gap-4 が効いていた)。 -->
                <div class={fullscreenActive ? "relative min-h-0 flex-1" : "flex flex-col gap-4"}>
                    {#if showRecorder}
                        <CameraRecorder
                            bind:this={recorderRef}
                            onCaptured={(blob, mimeType, durationMs) =>
                                handleCaptured(blob, mimeType, durationMs)}
                            onCameraUnavailable={(reason) => (cameraUnavailableReason = reason)}
                            subtitlePrimary={selectedCut.subtitle_primary}
                            subtitleSecondary={selectedCut.subtitle_secondary}
                            onCaptureActiveChange={(active) => {
                                captureActive = active;
                                // 録画の開始でも停止でも古い告知を捨てる。とくに停止後に
                                // 「録画中は移動できません」が残らないようにする。
                                navigationNotice = null;
                            }}
                            layout={fullscreenActive ? "fullscreen" : "inline"}
                            shootingPoint={selectedCut.shooting_point}
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
                </div>

                {#if !fullscreenActive}
                    <TakeStrip
                        projectId={project.id}
                        manualId={manual.id}
                        cut={selectedCut}
                        cutLabel={cutLabels[selectedCut.id] ?? "選択中カット"}
                        onChanged={reloadManual}
                        {captureActive}
                        onRequestCameraRelease={releaseCameraForPreview}
                        onCameraResume={resumeCameraAfterPreview}
                    />
                {/if}
            {/if}
        </section>
        </div>

        <!-- Modal は Portal で描画されるため設置位置に依存しない (全画面 section の外に置く) -->
        <ScenarioPreviewDialog
            bind:open={scenarioPreviewOpen}
            projectId={project.id}
            manualId={manual.id}
            cuts={manual.cuts}
            labels={cutLabels}
            placeholderSeconds={previewPlaceholderSeconds}
            onClose={closeScenarioPreview}
        />
    </PageContainer>
</AppLayout>
