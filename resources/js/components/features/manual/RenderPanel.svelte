<script lang="ts">
    import { router } from "@inertiajs/svelte";
    import { Clapperboard, Download, LoaderCircle, Play } from "@lucide/svelte";
    import Alert from "@/components/atoms/Alert.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import { isInsufficientTickets } from "@/components/features/manual/insufficient-tickets";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import { csrfToken } from "@/lib/csrf";
    import type { RenderJobProps, TakeCoverageProps, VideoManualStatus } from "@/types/manual";
    import { RENDER_STEP_LABELS } from "@/types/manual";

    /**
     * レンダパネル (完成動画生成・プレビュー生成・進捗ポーリング・DL 導線)。概念設計 §8。
     * - 起動は POST .../render / .../preview (XHR)。402/409/422 は押下時にサーバの
     *   メッセージを表示 (必須未充足でもボタンは disabled にしない = DESIGN.md)
     * - ポーリングは 1 コンポーネント内で scheduler 1 本 (render/preview を別タイマーで
     *   追わない)。単一 interval が追跡中 job id 集合を順に fetch し、終端条件のみ kind 別分岐
     *   (render: succeeded → router.reload() / preview: succeeded → <video> 表示)
     * - failed + error_code=scenario_version_changed は「作り直す」CTA (preview 再 POST)
     * - 事前告知 (coverage) / 事後説明 (playbackJob.placeholder_cut_count) は**別概念**:
     *   前者は「今から作ると黒背景が出る」、後者は「今再生している動画に黒背景があった」。
     *   どちらもボタンを止めない (必須条件未充足を理由に disabled にしない = 禁止事項 8)
     */
    interface Props {
        projectId: number;
        manualId: number;
        manualStatus: VideoManualStatus;
        job: RenderJobProps | null;
        previewJob: RenderJobProps | null;
        playbackJob: RenderJobProps | null;
        coverage: TakeCoverageProps;
        canManage: boolean;
    }

    let {
        projectId,
        manualId,
        manualStatus,
        job,
        previewJob,
        playbackJob: playbackJobProp,
        coverage,
        canManage,
    }: Props = $props();

    // 作業状態 (props から一度だけ seed し、以後は XHR 応答で更新する)
    // svelte-ignore state_referenced_locally
    let renderJob = $state<RenderJobProps | null>(job);
    // svelte-ignore state_referenced_locally
    let preview = $state<RenderJobProps | null>(previewJob);
    // svelte-ignore state_referenced_locally
    let playbackJob = $state<RenderJobProps | null>(playbackJobProp);
    // svelte-ignore state_referenced_locally
    let status = $state<VideoManualStatus>(manualStatus);
    let starting = $state(false);
    // 起動失敗の表示モデル (message + 402 残高不足時の購入導線)。402 (残高不足) のときのみ
    // showPurchaseLink=true (code 厳格一致。他エラーで誤表示しない)。
    type StartError = { message: string; showPurchaseLink: boolean };
    // 起動失敗は render/preview 独立に保持する (共有だと後発が先発を上書きし帰属が崩れる)
    let renderStartError = $state<StartError | null>(null);
    let previewStartError = $state<StartError | null>(null);
    let sessionExpiredMessage = $state<string | null>(null);
    let confirmingRender = $state(false);

    const isInFlight = (target: RenderJobProps | null): boolean =>
        target?.status === "queued" || target?.status === "running";

    const rendering = $derived(status === "rendering" || isInFlight(renderJob));
    const previewInFlight = $derived(isInFlight(preview));
    const failedRenderJob = $derived(renderJob?.status === "failed" ? renderJob : null);
    const failedPreviewJob = $derived(preview?.status === "failed" ? preview : null);
    const stepLabel = $derived(
        renderJob?.step ? RENDER_STEP_LABELS[renderJob.step] : "書き出しを待機中",
    );
    // 完成動画はあるがシナリオ編集で ready に戻った (要再生成) 案内
    const needsRegenerate = $derived(
        status === "ready" && renderJob?.status === "succeeded",
    );
    /**
     * 事前告知の要約ラベル。missing_labels は props 側で先頭 10 件に打ち切られているため、
     * 打ち切られていることを UI 側でも明示する (件数は missing_count が正)。
     */
    const missingLabelSummary = $derived(
        coverage.missing_labels.length < coverage.missing_count
            ? `${coverage.missing_labels.join("、")} ほか ${
                  coverage.missing_count - coverage.missing_labels.length
              } 件`
            : coverage.missing_labels.join("、"),
    );
    /** 再生している動画**そのもの**の実績値だけを出す (null は 0 と同一視しない = 何も言わない) */
    const playbackNote = $derived(
        playbackJob !== null &&
            playbackJob.placeholder_cut_count !== null &&
            playbackJob.placeholder_cut_count > 0
            ? playbackJob.placeholder_cut_count
            : null,
    );
    // ポーリング対象の job id 集合 (id のみに依存を狭め、応答更新で再購読しない)
    const pollKey = $derived(
        [
            isInFlight(renderJob) && renderJob !== null ? `r${renderJob.id}` : null,
            isInFlight(preview) && preview !== null ? `p${preview.id}` : null,
        ]
            .filter((key) => key !== null)
            .join(","),
    );

    /* ---- ポーリング (scheduler 1 本。cleanup で必ず破棄) ---- */
    $effect(() => {
        // この effect の反応的依存は pollKey のみ
        const key = pollKey;
        if (key === "") return;
        const targets = key.split(",").map((entry) => ({
            kind: entry.startsWith("r") ? ("render" as const) : ("preview" as const),
            id: Number(entry.slice(1)),
        }));

        let stopped = false;
        let interval: ReturnType<typeof setInterval> | null = null;

        const stop = (): void => {
            stopped = true;
            if (interval !== null) clearInterval(interval);
            interval = null;
        };

        const pollOne = async (target: { kind: "render" | "preview"; id: number }) => {
            const res = await fetch(
                `/projects/${projectId}/manuals/${manualId}/render-jobs/${target.id}`,
                {
                    headers: {
                        Accept: "application/json",
                        "X-Requested-With": "XMLHttpRequest",
                    },
                    credentials: "same-origin",
                },
            );
            if (res.status === 401 || res.status === 419) {
                stop();
                sessionExpiredMessage =
                    "セッションの有効期限が切れました。ページを再読み込みしてください (書き出しはサーバで継続しています)。";
                return;
            }
            if (!res.ok) return; // 一時失敗は次周期に任せる
            const body = (await res.json().catch(() => null)) as RenderJobProps | null;
            if (body === null || typeof body.status !== "string" || stopped) return;
            if (target.kind === "render") {
                renderJob = body;
                status = body.manual_status;
                if (body.status === "succeeded") {
                    // reload は render 側終端でのみ発火 (preview 終端は local state 更新のみ)
                    stop();
                    router.reload();
                }
            } else {
                preview = body;
                if (body.status === "succeeded") {
                    // 注記と動画 URL は同一オブジェクトから出す (別世代の値で説明しない)
                    playbackJob = body;
                }
            }
        };

        const poll = async (): Promise<void> => {
            if (stopped || document.hidden) return;
            try {
                for (const target of targets) {
                    if (stopped) return;
                    await pollOne(target);
                }
            } catch {
                // ネットワーク断は次周期に任せる
            }
        };

        const onVisibilityChange = (): void => {
            if (!document.hidden) void poll();
        };
        document.addEventListener("visibilitychange", onVisibilityChange);
        interval = setInterval(() => void poll(), 2500);
        void poll();

        return () => {
            stop();
            document.removeEventListener("visibilitychange", onVisibilityChange);
        };
    });

    /* ---- 起動 ---- */
    function requestRender(): void {
        confirmingRender = true;
    }

    async function start(kind: "render" | "preview"): Promise<void> {
        if (starting) return; // 多重送信ガード (disabled にはしない)
        starting = true;
        // 該当 source のみクリア (もう片方の失敗表示は帰属を保つため残す)
        if (kind === "render") renderStartError = null;
        else previewStartError = null;
        sessionExpiredMessage = null;
        try {
            const res = await fetch(`/projects/${projectId}/manuals/${manualId}/${kind}`, {
                method: "POST",
                headers: {
                    Accept: "application/json",
                    "X-XSRF-TOKEN": csrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
            });
            await handleStartResponse(kind, res);
        } catch {
            const failure: StartError = {
                message: "通信に失敗しました。接続を確認して再度お試しください。",
                showPurchaseLink: false,
            };
            if (kind === "render") renderStartError = failure;
            else previewStartError = failure;
        } finally {
            starting = false;
            confirmingRender = false;
        }
    }

    async function handleStartResponse(kind: "render" | "preview", res: Response): Promise<void> {
        const body = (await res.json().catch(() => null)) as unknown;
        if (res.status === 201 && body !== null && typeof body === "object") {
            const jobBody = body as RenderJobProps;
            if (kind === "render") {
                renderJob = jobBody;
                status = jobBody.manual_status;
            } else {
                preview = jobBody;
            }
            return;
        }
        // 402 (残高不足) / 409 (競合) / 422 (採用テイク欠落・尺超過) はサーバのメッセージを表示。
        // 起動失敗は source 別 state に積む (完成動画/プレビューの帰属を保つ)。
        const failure: StartError = {
            message:
                extractMessage(body) ??
                "書き出しを開始できませんでした。時間をおいて再度お試しください。",
            showPurchaseLink: res.status === 402 && isInsufficientTickets(body),
        };
        if (kind === "render") renderStartError = failure;
        else previewStartError = failure;
    }

    /** 402/409 の { message } と 422 の { message, errors } からユーザー向け文言を取り出す */
    function extractMessage(body: unknown): string | null {
        if (body === null || typeof body !== "object") return null;
        const message = (body as { message?: unknown }).message;
        return typeof message === "string" && message !== "" ? message : null;
    }
</script>

<Card padding="lg">
    <div class="flex items-center justify-between gap-3">
        <h2 class="text-h3">完成動画</h2>
        {#if canManage && !rendering}
            <div class="flex items-center gap-2">
                <Button
                    variant="secondary"
                    onclick={() => void start("preview")}
                    loading={starting && !confirmingRender}
                    testId="preview-button"
                >
                    <Play class="size-4" />
                    プレビュー生成
                </Button>
                {#if status === "ready"}
                    <Button onclick={requestRender} testId="render-button">
                        <Clapperboard class="size-4" />
                        完成動画を生成
                    </Button>
                {/if}
            </div>
        {/if}
    </div>

    {#if rendering}
        <div class="mt-4 flex flex-col gap-2" data-testid="render-progress">
            <div class="flex items-center gap-2 text-body text-text-secondary">
                <LoaderCircle class="size-4 animate-spin" />
                <span data-testid="render-step-label">{stepLabel}</span>
            </div>
            <div
                class="h-2 w-full overflow-hidden rounded-md bg-neutral"
                role="progressbar"
                aria-valuenow={renderJob?.progress ?? 0}
                aria-valuemin={0}
                aria-valuemax={100}
            >
                <div
                    class="h-full rounded-md bg-primary transition-all"
                    style={`width: ${renderJob?.progress ?? 0}%`}
                ></div>
            </div>
            <p class="text-caption text-text-secondary">
                採用テイクを合成して完成動画を書き出しています。このページを開いたまましばらくお待ちください。
            </p>
        </div>
    {:else}
        {#if failedRenderJob?.error}
            <div class="mt-4" data-testid="render-error">
                <Alert type="danger" title="完成動画の生成に失敗しました">
                    {failedRenderJob.error}
                </Alert>
            </div>
        {/if}
        {#if needsRegenerate}
            <p class="mt-2 text-body text-text-secondary" data-testid="render-regenerate-note">
                シナリオが編集されています。最新の内容で完成動画を再生成してください。
            </p>
        {/if}
        {#if status === "published" && canManage}
            <div class="mt-4">
                <Button
                    variant="secondary"
                    href={`/projects/${projectId}/manuals/${manualId}/download`}
                    testId="download-button"
                >
                    <Download class="size-4" />
                    完成動画をダウンロード
                </Button>
            </div>
        {/if}
        {#if !canManage}
            <p class="mt-2 text-body text-text-secondary">
                完成動画の生成・ダウンロードは編集者が行えます。
            </p>
        {/if}
    {/if}

    {#if renderStartError}
        <div class="mt-4" data-testid="render-start-error">
            <Alert type="danger" title="完成動画の生成を開始できませんでした">
                {renderStartError.message}
                {#if renderStartError.showPurchaseLink}
                    <span class="ml-1">
                        <TextLink href="/purchase-tickets" testId="render-purchase-link">
                            チケットを購入する
                        </TextLink>
                    </span>
                {/if}
            </Alert>
        </div>
    {/if}
    {#if sessionExpiredMessage}
        <div class="mt-4" data-testid="render-session-expired">
            <Alert type="warning">{sessionExpiredMessage}</Alert>
        </div>
    {/if}

    {#if canManage}
        <div class="mt-6 flex flex-col gap-2">
            {#if coverage.missing_count > 0}
                <!-- 事前告知: プレビューは生成できる (ボタンは止めない) が黒背景になることを先に伝える。
                     TakeStatus は 4 値あるため「未撮影」と断定せず述語の意味をそのまま言う。 -->
                <div data-testid="preview-coverage-note">
                    <Alert type="warning" title="プレビューに黒背景の区間があります">
                        {coverage.missing_count} / {coverage.total_cuts}
                        件のカットに、撮影・処理が完了した採用テイクがありません ({missingLabelSummary})。プレビューは生成できますが、該当区間は黒背景になります。完成動画の生成には、すべてのカットで撮影・処理が完了した採用テイクが必要です。
                    </Alert>
                </div>
            {/if}
            {#if previewInFlight}
                <div
                    class="flex items-center gap-2 text-body text-text-secondary"
                    data-testid="preview-progress"
                >
                    <LoaderCircle class="size-4 animate-spin" />
                    <span>プレビューを生成中 ({preview?.progress ?? 0}%)</span>
                </div>
            {:else if failedPreviewJob}
                <div data-testid="preview-error">
                    <Alert type="danger" title="プレビューの生成に失敗しました">
                        {failedPreviewJob.error ?? "プレビューの生成に失敗しました。"}
                    </Alert>
                </div>
                {#if failedPreviewJob.error_code === "scenario_version_changed"}
                    <div>
                        <Button
                            variant="secondary"
                            onclick={() => void start("preview")}
                            testId="preview-retry-button"
                        >
                            <Play class="size-4" />
                            プレビューを作り直す
                        </Button>
                    </div>
                {/if}
            {/if}
            {#if previewStartError}
                <div data-testid="preview-start-error">
                    <Alert type="danger" title="プレビューの生成を開始できませんでした">
                        {previewStartError.message}
                        {#if previewStartError.showPurchaseLink}
                            <span class="ml-1">
                                <TextLink href="/purchase-tickets" testId="preview-purchase-link">
                                    チケットを購入する
                                </TextLink>
                            </span>
                        {/if}
                    </Alert>
                </div>
            {/if}
            {#if playbackJob !== null && !previewInFlight}
                {#if playbackNote !== null}
                    <!-- 事後説明: 注記と動画 URL は同一の playbackJob から出る (別世代の値で説明しない) -->
                    <p
                        class="text-caption text-text-secondary"
                        data-testid="preview-placeholder-note"
                    >
                        このプレビューは {playbackNote}
                        件のカットに使用できる採用テイクがないため、その区間が黒背景になっています。
                    </p>
                {/if}
                <!-- svelte-ignore a11y_media_has_caption (プレビュー動画の字幕は焼き込み済み) -->
                <!-- aria-label は固定文言でよい: playbackJob の供給源は初期値 (Controller が
                     kind=Preview ∧ status=Succeeded で抽出) と poll の preview 分岐だけで、
                     render job が入る経路が無い (完成動画と取り違わない)。 -->
                <video
                    controls
                    preload="metadata"
                    class="w-full rounded-md bg-neutral"
                    src={`/projects/${projectId}/manuals/${manualId}/render-jobs/${playbackJob.id}/playback`}
                    aria-label="プレビュー動画"
                    data-testid="preview-video"
                ></video>
            {/if}
        </div>
    {/if}
</Card>

<ConfirmDialog
    bind:open={confirmingRender}
    title="完成動画の生成"
    message="チケットを消費して完成動画を書き出します。書き出し中はシナリオ編集・撮影ができません。実行しますか？"
    confirmLabel="生成する"
    processing={starting}
    onConfirm={() => void start("render")}
    testId="render-dialog"
/>
