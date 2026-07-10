<script lang="ts">
    import { router } from "@inertiajs/svelte";
    import { LoaderCircle, Sparkles } from "@lucide/svelte";
    import Alert from "@/components/atoms/Alert.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import { csrfToken } from "@/lib/csrf";
    import type { AnalysisJobProps, VideoManualStatus } from "@/types/manual";
    import { ANALYSIS_STEP_LABELS } from "@/types/manual";

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
    // セッション失効 (401/419) の案内。解析中表示の中で出す (ポーリングは停止する)
    let sessionExpiredMessage = $state<string | null>(null);
    let confirmingReanalyze = $state(false);

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
                    `/projects/${projectId}/manuals/${manualId}/jobs/${jobId}`,
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
        sessionExpiredMessage = null;
        try {
            const res = await fetch(`/projects/${projectId}/manuals/${manualId}/analyze`, {
                method: "POST",
                headers: {
                    Accept: "application/json",
                    "X-XSRF-TOKEN": csrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
            });
            await handleStartResponse(res);
        } catch {
            errorMessage = "通信に失敗しました。接続を確認して再度お試しください。";
        } finally {
            starting = false;
            confirmingReanalyze = false;
        }
    }

    async function handleStartResponse(res: Response): Promise<void> {
        const body = (await res.json().catch(() => null)) as unknown;
        if (res.status === 201 && body !== null && typeof body === "object") {
            const jobBody = body as AnalysisJobProps;
            currentJob = jobBody;
            status = jobBody.manual_status;
            return;
        }
        // 402 (残高不足) / 409 (競合) / 422 (手順書なし) はサーバのメッセージをそのまま表示
        const message = extractMessage(body);
        if (message !== null) {
            errorMessage = message;
            return;
        }
        errorMessage = "解析を開始できませんでした。時間をおいて再度お試しください。";
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
        {#if canManage && !analyzing}
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
                <Alert type="danger">{errorMessage}</Alert>
            </div>
        {/if}
        <p class="mt-2 text-body text-text-secondary">
            {#if !hasDocument}
                手順書 (SOP) をアップロードすると、AI が撮るべきカットを設計したシナリオを生成します。
            {:else if status === "ready"}
                手順書から生成したシナリオを編集画面で確認できます。再解析すると既存のシナリオは置き換えられます。
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
