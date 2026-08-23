<script lang="ts">
    import { router } from "@inertiajs/svelte";
    import { LoaderCircle, Sparkles } from "@lucide/svelte";
    import Alert from "@/components/atoms/Alert.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import { isInsufficientTickets } from "@/components/features/manual/insufficient-tickets";
    import { csrfToken } from "@/lib/csrf";
    import type { AnalysisJobProps, VideoManualStatus } from "@/types/manual";
    import { ANALYSIS_STEP_LABELS, isAnalyzable, isScenarioEstablished } from "@/types/manual";
    import { currentOrgUrl } from "@/lib/org-url";

    /**
     * AI 解析パネル (起動・進捗ポーリング・エラー表示)。doc/10 §10.3 / 概念設計 §8。
     * - 起動は POST .../analyze (XHR)。402/409/422 は押下時にサーバのメッセージを表示
     *   (必須未充足でもボタンは disabled にしない = DESIGN.md)
     * - analyzing 中は GET .../jobs/{id} を 2.5 秒間隔でポーリング。
     *   succeeded → router.reload() (ready 反映)、failed → エラー + 再実行導線
     * - ready からの再解析は「既存シナリオが置き換えられます」確認ダイアログを挟む
     */
    interface Props {
        projectId: number;
        manualId: number;
        manualStatus: VideoManualStatus;
        job: AnalysisJobProps | null;
        hasDocument: boolean;
        canManage: boolean;
    }

    let { projectId, manualId, manualStatus, job, hasDocument, canManage }: Props = $props();

    // 作業状態 (props から一度だけ seed し、以後は XHR 応答で更新する)
    // svelte-ignore state_referenced_locally
    let currentJob = $state<AnalysisJobProps | null>(job);
    // svelte-ignore state_referenced_locally
    let status = $state<VideoManualStatus>(manualStatus);
    let starting = $state(false);
    let errorMessage = $state<string | null>(null);
    // 402 (残高不足) のとき購入導線を併記する (code 厳格一致。他エラーで誤表示しない)
    let showPurchaseLink = $state(false);
    // セッション失効 (401/419) の案内。解析中表示の中で出す (ポーリングは停止する)
    let sessionExpiredMessage = $state<string | null>(null);
    let confirmingReanalyze = $state(false);

    /**
     * start error (解析起動 XHR の失敗) の種別。文言一致でなく種別で分岐することで、
     * i18n・文言変更に強く、「手順書なし(422)」だけを型安全に破棄できる。
     * - missing_document: 422 かつ errors.document 由来 (SOP 未アップロード)
     * - insufficient_tickets / conflict / generic: それ以外
     */
    type StartErrorKind = "missing_document" | "insufficient_tickets" | "conflict" | "generic";
    let startErrorKind = $state<StartErrorKind | null>(null);

    const analyzing = $derived(
        status === "analyzing" ||
            currentJob?.status === "queued" ||
            currentJob?.status === "running",
    );
    const failedJob = $derived(currentJob?.status === "failed" ? currentJob : null);
    const stepLabel = $derived(
        currentJob?.step ? ANALYSIS_STEP_LABELS[currentJob.step] : "解析を待機中",
    );
    // ポーリング対象の job id。effect の依存を id に狭めることで、running/queued の
    // 各応答で currentJob を更新しても同一 id なら effect が再購読されず、
    // setInterval の 2.5 秒間隔が保たれる (terminal 遷移で analyzing=false → null で停止)
    const pollJobId = $derived(analyzing && currentJob !== null ? currentJob.id : null);

    /* ---- ポーリング (analyzing 中のみ。cleanup で必ず破棄) ---- */
    $effect(() => {
        // この effect の反応的依存は pollJobId のみ (currentJob 本体は読まない)
        const jobId = pollJobId;
        if (jobId === null) return;

        let stopped = false;
        let interval: ReturnType<typeof setInterval> | null = null;

        const poll = async (): Promise<void> => {
            if (stopped || document.hidden) return;
            try {
                const res = await fetch(
                    currentOrgUrl(`/projects/${projectId}/manuals/${manualId}/jobs/${jobId}`),
                    {
                        headers: {
                            Accept: "application/json",
                            "X-Requested-With": "XMLHttpRequest",
                        },
                        credentials: "same-origin",
                    },
                );
                if (res.status === 401 || res.status === 419) {
                    // セッション失効は再試行しても回復しない → 停止して再読み込みを案内
                    // (解析はサーバ側で継続する。再読み込み後のログインで進捗表示に復帰できる)
                    stop();
                    sessionExpiredMessage =
                        "セッションの有効期限が切れました。ページを再読み込みしてください (解析はサーバで継続しています)。";
                    return;
                }
                if (!res.ok) return; // 一時失敗は次周期に任せる
                const body = (await res.json().catch(() => null)) as AnalysisJobProps | null;
                if (body === null || typeof body.status !== "string") return;
                if (stopped) return;
                currentJob = body;
                status = body.manual_status;
                if (body.status === "succeeded") {
                    stop();
                    router.reload();
                }
            } catch {
                // ネットワーク断は次周期に任せる
            }
        };

        const stop = (): void => {
            stopped = true;
            if (interval !== null) clearInterval(interval);
            interval = null;
        };

        // バックグラウンドタブの無駄打ちを避ける (再表示で即時 1 回 fetch)
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

    /* ---- stale overlay 破棄 (bug-hunt F-H2 再現対策) ----
     * 症状: 解析起動が 422「手順書をアップロードしてください。」で失敗して errorMessage が
     *   出た後、同一 Show 画面で SOP アップロードが成功しても (Inertia が Show を再描画し
     *   hasDocument: false→true) errorMessage が seed のまま残留する。
     * 対策 (level-triggered): 「手順書があれば解消される種別 (missing_document)」の start error が
     *   出ている状態で hasDocument が true になったら破棄する。missing_document かつ hasDocument=true は
     *   常に矛盾なので、edge (false→true 遷移) でなく level で判定する。これにより
     *   「422 表示 → upload」と「解析要求中に upload 完了 → 遅延 422 到達」の両順序を一様に扱える。
     * 注: currentJob/status は server-truth、sessionExpiredMessage は poll 系のため触らない。
     *   ポーリングは props を変えない (XHR でローカル state のみ更新) ので、この effect は
     *   ポーリング進行では発火せず、進捗表示・2.5 秒間隔は壊れない。
     */
    $effect(() => {
        if (hasDocument && isResolvedByDocumentUpload(startErrorKind)) {
            errorMessage = null;
            showPurchaseLink = false;
            startErrorKind = null;
        }
    });

    /* ---- 起動 ---- */
    function requestAnalyze(): void {
        if (status === "ready") {
            confirmingReanalyze = true;
            return;
        }
        void startAnalyze();
    }

    async function startAnalyze(): Promise<void> {
        if (starting) return; // 多重送信ガード (disabled にはしない)
        starting = true;
        errorMessage = null;
        showPurchaseLink = false;
        sessionExpiredMessage = null;
        startErrorKind = null; // 再送時に種別もリセット
        // 分類を要求時点に固定 (応答遅延中に hasDocument が変わっても安定分類)
        const hadDocumentAtStart = hasDocument;
        try {
            const res = await fetch(currentOrgUrl(`/projects/${projectId}/manuals/${manualId}/analyze`), {
                method: "POST",
                headers: {
                    Accept: "application/json",
                    "X-XSRF-TOKEN": csrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
            });
            await handleStartResponse(res, hadDocumentAtStart);
        } catch {
            errorMessage = "通信に失敗しました。接続を確認して再度お試しください。";
            startErrorKind = "generic";
        } finally {
            starting = false;
            confirmingReanalyze = false;
        }
    }

    async function handleStartResponse(res: Response, hadDocumentAtStart: boolean): Promise<void> {
        const body = (await res.json().catch(() => null)) as unknown;
        showPurchaseLink = res.status === 402 && isInsufficientTickets(body);
        if (res.status === 201 && body !== null && typeof body === "object") {
            const jobBody = body as AnalysisJobProps;
            currentJob = jobBody;
            status = jobBody.manual_status;
            startErrorKind = null; // 成功時は種別もクリア (自己記述的)
            return;
        }
        // 402 (残高不足) / 409 (競合) / 422 (手順書なし) は種別を記録しつつサーバのメッセージを表示
        startErrorKind = classifyStartError(res.status, body, hadDocumentAtStart);
        errorMessage =
            extractMessage(body) ?? "解析を開始できませんでした。時間をおいて再度お試しください。";
    }

    /**
     * start error 種別を res.status / body / 解析開始時の hadDocumentAtStart から判定する (文言非依存)。
     * missing_document は「解析要求時に手順書が無かった (hadDocumentAtStart=false)」を条件に含める。
     * これにより:
     *  - 将来 document フィールド由来の別 422 (形式/容量) を誤分類しない。
     *  - 応答遅延中に hasDocument が true へ変わっても、要求時点の値で安定して分類できる。
     */
    function classifyStartError(
        status: number,
        body: unknown,
        hadDocumentAtStart: boolean,
    ): StartErrorKind {
        if (status === 402 && isInsufficientTickets(body)) return "insufficient_tickets";
        if (status === 409) return "conflict";
        if (status === 422 && !hadDocumentAtStart && hasDocumentValidationError(body)) {
            return "missing_document";
        }
        return "generic";
    }

    /** 422 body に errors.document (SOP 未アップロード) が含まれるか。
     *  bare 422 を一律 missing_document にしない (将来別用途の 422 と混同しないため)。 */
    function hasDocumentValidationError(body: unknown): boolean {
        if (body === null || typeof body !== "object") return false;
        const errors = (body as { errors?: unknown }).errors;
        if (errors === null || typeof errors !== "object") return false;
        const doc = (errors as { document?: unknown }).document;
        return Array.isArray(doc) && doc.length > 0;
    }

    /** hasDocument が満たされたとき自動破棄してよい start error 種別か (missing_document のみ) */
    function isResolvedByDocumentUpload(kind: StartErrorKind | null): boolean {
        return kind === "missing_document";
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
        <h2 class="text-h3">シナリオ</h2>
        {#if canManage && isAnalyzable(status) && !analyzing}
            <Button
                onclick={requestAnalyze}
                loading={starting}
                testId="analyze-button"
            >
                <Sparkles class="size-4" />
                AI 解析
            </Button>
        {/if}
    </div>

    {#if analyzing}
        <div class="mt-4 flex flex-col gap-2" data-testid="analysis-progress">
            <div class="flex items-center gap-2 text-body text-text-secondary">
                <LoaderCircle class="size-4 animate-spin" />
                <span data-testid="analysis-step-label">{stepLabel}</span>
            </div>
            <div
                class="h-2 w-full overflow-hidden rounded-md bg-neutral"
                role="progressbar"
                aria-valuenow={currentJob?.progress ?? 0}
                aria-valuemin={0}
                aria-valuemax={100}
            >
                <div
                    class="h-full rounded-md bg-primary transition-all"
                    style={`width: ${currentJob?.progress ?? 0}%`}
                ></div>
            </div>
            <p class="text-caption text-text-secondary">
                AI が手順書からシナリオを生成しています。このページを開いたまましばらくお待ちください。
            </p>
            {#if sessionExpiredMessage}
                <div data-testid="analysis-session-expired">
                    <Alert type="warning">{sessionExpiredMessage}</Alert>
                </div>
            {/if}
        </div>
    {:else}
        {#if failedJob?.error}
            <div class="mt-4" data-testid="analysis-error">
                <Alert type="danger">{failedJob.error}</Alert>
            </div>
        {/if}
        {#if errorMessage}
            <div class="mt-4" data-testid="analysis-start-error">
                <Alert type="danger">
                    {errorMessage}
                    {#if showPurchaseLink}
                        <span class="ml-1">
                            <TextLink href={currentOrgUrl("/billing/purchase-tickets")} testId="analysis-purchase-link">
                                チケットを購入する
                            </TextLink>
                        </span>
                    {/if}
                </Alert>
            </div>
        {/if}
        <p class="mt-2 text-body text-text-secondary">
            {#if isScenarioEstablished(status)}
                {#if status === "ready"}
                    手順書から生成したシナリオを編集画面で確認できます。再解析すると既存のシナリオは置き換えられます。
                {:else}
                    生成済みのシナリオは編集画面で確認できます。
                {/if}
            {:else if !hasDocument}
                手順書 (SOP) をアップロードすると、AI が撮るべきカットを設計したシナリオを生成します。
            {:else}
                アップロード済みの手順書から AI がシナリオを生成できます。
            {/if}
        </p>
    {/if}
</Card>

<ConfirmDialog
    bind:open={confirmingReanalyze}
    title="AI 解析の再実行"
    message="既存のシナリオは AI の生成結果で置き換えられます。再解析しますか？"
    confirmLabel="再解析する"
    confirmVariant="danger"
    processing={starting}
    onConfirm={() => void startAnalyze()}
    testId="reanalyze-dialog"
/>
