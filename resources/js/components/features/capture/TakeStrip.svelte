<script lang="ts">
    import { onMount } from "svelte";
    import {
        Check,
        ChevronDown,
        ChevronUp,
        Download,
        Film,
        Image,
        Pencil,
        Play,
        Trash2,
    } from "@lucide/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import DragHandle from "@/components/atoms/DragHandle.svelte";
    import TakeCommentDialog from "@/components/features/capture/TakeCommentDialog.svelte";
    import TakePreviewDialog from "@/components/features/capture/TakePreviewDialog.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import { captureJson, extractErrorMessage } from "@/lib/capture/http";
    import { createPointerDrag, type PointerDragState } from "@/lib/dnd/pointer-drag";
    import { takeUrl as buildTakeUrl } from "@/lib/capture/take-endpoints";
    import type { CaptureCut, CaptureTake } from "@/types/capture";

    /**
     * カット配下のテイク一覧 (先頭 = 採用候補。doc/05)。
     * 採用・並べ替え・コメント・削除・DL 済み ACK を XHR で行う。
     * 失敗 (DL 済み削除不可・処理中等) は押下時にエラー表示する (disabled 禁止)。
     */
    interface Props {
        projectId: number;
        manualId: number;
        cut: CaptureCut;
        /** 手順 N / 急所 N-M。TakePreviewDialog の video アクセシブルネームへ中継する */
        cutLabel: string;
        onChanged: () => void;
        /** 撮影 active (recording|stopping) なら preview を開かずエラー表示 (資源競合防止) */
        captureActive?: boolean;
        /** preview を開く直前に撮影待機中の live stream を解放させる (親: CameraRecorder) */
        onRequestCameraRelease?: () => void;
        /** preview close で撮影待機を復帰させる (親: CameraRecorder) */
        onCameraResume?: () => void;
    }

    let {
        projectId,
        manualId,
        cut,
        cutLabel,
        onChanged,
        captureActive = false,
        onRequestCameraRelease,
        onCameraResume,
    }: Props = $props();

    let error = $state<string | null>(null);
    let busyTakeId = $state<number | null>(null);
    let commentTarget = $state<CaptureTake | null>(null);
    let commentDialogOpen = $state(false);
    let commentSaving = $state(false);
    let commentError = $state<string | null>(null);

    // プレビュー再生ダイアログ (T050)。preview は video element での確認 (DL の window.open とは別用途)。
    let previewTarget = $state<CaptureTake | null>(null);
    let previewOpen = $state(false);
    const previewUrl = $derived(previewTarget !== null ? takeUrl(previewTarget, "/playback") : null);

    // 削除確認ダイアログ。id をスナップショット保持し、ラベルは開いた時点の値で確定する
    // (親の再取得・並べ替えで参照内容がずれないように object 参照ではなく id + label を持つ)。
    let deleteTargetId = $state<number | null>(null);
    let deleteLabel = $state("");
    let deleteDialogOpen = $state(false);

    // 削除ボタン押下: 即 DELETE せず、対象を確定して確認ダイアログを開く。
    function requestDelete(take: CaptureTake, index: number): void {
        deleteTargetId = take.id;
        deleteLabel = `テイク ${index + 1}`;
        deleteDialogOpen = true;
    }

    // 「削除する」確定時のみ DELETE を送る。null ガードを明示 (optional 連鎖任せにしない)。
    async function confirmDelete(): Promise<void> {
        const id = deleteTargetId;
        if (id === null) return;
        const target = cut.takes.find((t) => t.id === id);
        if (target === undefined) {
            // 既に一覧から消えている等: ダイアログを閉じるだけ
            deleteDialogOpen = false;
            deleteTargetId = null;
            return;
        }
        // 成功・失敗いずれも解決後に閉じる。失敗 (422 等) は既存の take-strip-error (role="alert") に表示。
        await remove(target);
        deleteDialogOpen = false;
        deleteTargetId = null;
        deleteLabel = ""; // 再オープン時の古い文言混入を防ぐ (design-review S1 Suggestion)
    }

    // URL 規則は lib/capture/take-endpoints に 1 箇所化してある (PC 編集面と共用の API 面)
    function takeUrl(take: CaptureTake, suffix = ""): string {
        return buildTakeUrl({ projectId, manualId, cutId: cut.id }, take.id, suffix);
    }

    /**
     * 共通の XHR 実行。**成功したら true** を返す。
     * 戻り値を足したのは、並べ替えの読み上げ (aria-live) を**成功時だけ**にするためである。
     * 失敗しても「移動しました」と読み上げると、スクリーンリーダ利用者にだけ嘘をつくことになる
     * (視覚利用者は role="alert" のエラーを見る)。
     * 既存の呼び出し側 (adopt / remove / downloadAndAck / confirmDelete) は戻り値を無視するだけで
     * 無変更のまま動く。
     */
    async function run(take: CaptureTake, action: () => Promise<Response>): Promise<boolean> {
        error = null;
        busyTakeId = take.id;
        try {
            const response = await action();
            if (!response.ok) {
                error = await extractErrorMessage(response);
                return false;
            }
            onChanged();
            return true;
        } catch {
            error = "通信に失敗しました。ネットワークを確認してください。";
            return false;
        } finally {
            busyTakeId = null;
        }
    }

    const adopt = (take: CaptureTake) => run(take, () => captureJson(takeUrl(take, "/adopt"), "POST"));
    const remove = (take: CaptureTake) => run(take, () => captureJson(takeUrl(take), "DELETE"));
    const move = (take: CaptureTake, position: number) =>
        run(take, () => captureJson(takeUrl(take), "PATCH", { position: Math.max(0, position) }));

    // --- 並べ替え (▲▼ / ハンドルのキーボード / D&D の合流点) ---

    let reorderStatus = $state("");
    function announce(message: string): void {
        reorderStatus = message;
    }

    /**
     * テイクの並べ替え。▲▼・ハンドルのキーボード・D&D のすべてがここへ合流する。
     * position は**最終 index** (移動後の全体配列での 0 始まり index)。
     * サーバの reorderWithinCut が対象を除いた配列へ splice するため両者は一致する。
     *
     * クライアント側で楽観的に並べ替えない (サーバ権威)。理由は 2 つ:
     *   1. 採用テイク (adopted_take_id) と親の再取得 (onChanged) が同じ経路で更新されるため、
     *      ローカル順序を別に持つと 2 つの真実ができる
     *   2. 既存の ▲▼ と同じ挙動になり、並べ替え手段ごとに体感が割れない
     *
     * 告知は**成功後**に行う (失敗は既存の role="alert" が伝えるので二重に出さない)。
     */
    async function reorderTo(from: number, to: number): Promise<void> {
        const take = cut.takes[from];
        if (take === undefined || from === to || to < 0 || to >= cut.takes.length) return;
        const label = `テイク ${from + 1} を ${to + 1} 番目に移動しました`;
        if (await move(take, to)) announce(label);
    }

    /** ▲▼ とハンドルのキーボードの共通経路 (1 段移動)。端は disabled にせず告知する */
    function moveBy(index: number, delta: -1 | 1): void {
        const to = index + delta;
        if (to < 0 || to >= cut.takes.length) {
            // 端: 通信しない / busy にしない / 再取得しない。理由だけ告知する
            announce(delta < 0 ? "これ以上、上へは移動できません" : "これ以上、下へは移動できません");
            return;
        }
        void reorderTo(index, to);
    }

    let takeDrag = $state<PointerDragState>({ activeIndex: null, insertionIndex: null });
    let listEl = $state<HTMLDivElement | null>(null);
    let dragCtl: ReturnType<typeof createPointerDrag> | null = null;

    // $effect ではなく onMount (マウント期間だけ持つブラウザ資源であり、派生状態ではない)。
    onMount(() => {
        dragCtl = createPointerDrag({
            rows: () =>
                listEl === null
                    ? []
                    : [...listEl.querySelectorAll<HTMLElement>(":scope > [data-reorder-index]")],
            onState: (state) => (takeDrag = state),
            onCommit: (from, to) => void reorderTo(from, to),
        });
        return () => {
            dragCtl?.destroy();
            dragCtl = null;
        };
    });

    function onHandleKeydown(event: KeyboardEvent, index: number): void {
        if (event.key !== "ArrowUp" && event.key !== "ArrowDown") return;
        event.preventDefault();
        moveBy(index, event.key === "ArrowUp" ? -1 : 1);
    }

    // 再生ボタン押下: 撮影中はエラー表示して開かない (押下時エラー。disabled 禁止)。
    function openPreview(take: CaptureTake): void {
        error = null;
        if (captureActive) {
            // captureActive は recording|stopping を含む (撮影データ保護のため preview と同居させない)
            error = "撮影中はプレビューを再生できません。撮影を停止してからお試しください。";
            return;
        }
        previewTarget = take;
        onRequestCameraRelease?.(); // 撮影待機中の live stream を解放
        previewOpen = true;
    }

    // dialog が閉じた時 (背景クリック / Esc / × / 閉じるボタン / 採用成功) の単一クリーンアップ点。
    // TakePreviewDialog が open の true→false 遷移でちょうど 1 回だけ呼ぶ (二重復帰防止)。
    function handlePreviewClose(): void {
        previewTarget = null;
        onCameraResume?.(); // 録画待機を復帰
    }

    // dialog の採用ボタン: 既存 adopt を呼び、成功時のみ dialog を閉じる。
    // previewOpen=false 遷移が handlePreviewClose を発火させる (復帰は 1 経路に集約)。
    // run() は失敗時に error を設定するので dialog はそのまま (error を表示)。
    async function adoptFromPreview(): Promise<void> {
        const target = previewTarget;
        if (target === null) return;
        await adopt(target);
        if (error === null) {
            previewOpen = false;
        }
    }

    function openComment(take: CaptureTake): void {
        commentTarget = take;
        commentError = null;
        commentDialogOpen = true;
    }

    async function saveComment(comment: string): Promise<void> {
        if (commentTarget === null) return;
        commentSaving = true;
        commentError = null;
        try {
            const response = await captureJson(takeUrl(commentTarget), "PATCH", {
                comment: comment === "" ? null : comment,
            });
            if (!response.ok) {
                commentError = await extractErrorMessage(response);
                return;
            }
            commentDialogOpen = false;
            onChanged();
        } catch {
            commentError = "通信に失敗しました。";
        } finally {
            commentSaving = false;
        }
    }

    /** 採用テイクの DL 完了 ACK (概念設計 D6): DL 開始後にトークンを送る */
    async function downloadAndAck(take: CaptureTake): Promise<void> {
        if (take.playback_url === null || take.download_ack_token === null) {
            error = "この端末からダウンロードできるのは採用テイクのみです。";
            return;
        }
        window.open(take.playback_url, "_blank", "noopener");
        await run(take, () =>
            captureJson(takeUrl(take, "/downloaded"), "POST", { ack_token: take.download_ack_token }),
        );
    }

    function sizeLabel(bytes: number): string {
        if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }
</script>

<div class="flex flex-col gap-2" data-testid={`take-strip-${cut.id}`} bind:this={listEl}>
    {#if cut.takes.length === 0}
        <p class="text-caption text-text-secondary">テイクはまだありません。撮影してください。</p>
    {/if}
    {#each cut.takes as take, index (take.id)}
        <div
            class="relative flex flex-wrap items-center gap-x-2 gap-y-2 rounded-md border border-border bg-surface px-3 py-2 sm:flex-nowrap {take.downloaded
                ? 'border-border-strong'
                : ''} {takeDrag.activeIndex === index ? 'opacity-50' : ''}"
            data-testid={`take-item-${take.id}`}
            data-reorder-index={index}
        >
            {#if takeDrag.insertionIndex === index}
                <!-- 落とし先の目印。影・scale は使わない (DESIGN.md §Elevation) -->
                <div class="absolute inset-x-0 -top-1 h-0.5 bg-primary" aria-hidden="true"></div>
            {/if}
            <DragHandle
                ariaLabel={`テイク ${index + 1} の並び順を変更 (ドラッグ、または上下キー)`}
                onpointerdown={(event) => void dragCtl?.start(index, event)}
                onkeydown={(event) => onHandleKeydown(event, index)}
                testId={`take-drag-${take.id}`}
            />
            <!--
              サムネイル (doc/04 動画列 / doc/05 撮影後の確認)。
              生成は非同期なので、録画直後・生成失敗・過去分のテイクは has_thumbnail=false になる。
              その場合は同じ寸法のプレースホルダを出し、枠の大きさを変えない
              (生成完了後の再取得で同じ枠が画像へ置き換わる = レイアウトが跳ねない)。
              画像は装飾 (行に「テイク N」の見出しが既にある) なので alt="" にする。
            -->
            {#if take.has_thumbnail}
                <img
                    src={takeUrl(take, "/thumbnail")}
                    alt=""
                    loading="lazy"
                    decoding="async"
                    class="size-12 shrink-0 rounded-md border border-border object-cover"
                    data-testid={`take-thumbnail-${take.id}`}
                />
            {:else}
                <div
                    class="flex size-12 shrink-0 items-center justify-center rounded-md border border-border bg-neutral"
                    data-testid={`take-thumbnail-placeholder-${take.id}`}
                    aria-hidden="true"
                >
                    <!-- 未生成プレースホルダのアイコンだけ素材種別で替える (寸法は同じ = 跳ねない) -->
                    {#if take.material_type === "still"}
                        <Image class="size-4 text-text-secondary" aria-hidden="true" />
                    {:else}
                        <Film class="size-4 text-text-secondary" aria-hidden="true" />
                    {/if}
                </div>
            {/if}
            <div class="flex shrink-0 flex-col gap-1">
                <Button
                    variant="ghost"
                    size="sm"
                    iconOnly
                    ariaLabel="上へ"
                    onclick={() => moveBy(index, -1)}
                    testId={`take-up-${take.id}`}
                >
                    <ChevronUp class="size-4" aria-hidden="true" />
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    iconOnly
                    ariaLabel="下へ"
                    onclick={() => moveBy(index, 1)}
                    testId={`take-down-${take.id}`}
                >
                    <ChevronDown class="size-4" aria-hidden="true" />
                </Button>
            </div>
            <div class="min-w-0 flex-1">
                <p
                    class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-body"
                    data-testid={`take-label-${take.id}`}
                >
                    テイク {index + 1}
                    {#if cut.adopted_take_id === take.id}
                        <Badge tone="success" testId={`take-adopted-${take.id}`}>採用中</Badge>
                    {/if}
                    {#if take.downloaded}
                        <Badge tone="neutral">DL 済み</Badge>
                    {/if}
                </p>
                <p class="text-caption text-text-secondary">
                    {sizeLabel(take.size_bytes)}
                    {#if take.duration_ms !== null}
                        ・{Math.round(take.duration_ms / 1000)} 秒
                    {/if}
                    {#if take.comment}
                        ・{take.comment}
                    {/if}
                </p>
                {#if take.status !== "ready"}
                    <p class="text-caption text-text-secondary" data-testid={`take-not-ready-${take.id}`}>
                        {#if take.status === "failed"}
                            アップロードに失敗しました。
                        {:else}
                            アップロード処理中は再生できません。
                        {/if}
                    </p>
                {/if}
            </div>
            <div
                class="flex w-full shrink-0 flex-wrap items-center justify-end gap-x-1 gap-y-1 sm:w-auto sm:flex-nowrap sm:justify-start"
                data-testid={`take-actions-${take.id}`}
            >
                {#if take.status === "ready"}
                    <Button
                        variant="ghost"
                        size="sm"
                        iconOnly
                        ariaLabel="再生"
                        onclick={() => openPreview(take)}
                        testId={`take-preview-${take.id}`}
                    >
                        <Play class="size-4" aria-hidden="true" />
                    </Button>
                {/if}
                <Button
                    variant="neutral"
                    size="sm"
                    loading={busyTakeId === take.id}
                    onclick={() => adopt(take)}
                    testId={`take-adopt-${take.id}`}
                >
                    <Check class="size-4" aria-hidden="true" />
                    採用
                </Button>
                {#if take.playback_url !== null}
                    <Button
                        variant="ghost"
                        size="sm"
                        iconOnly
                        ariaLabel="ダウンロード"
                        onclick={() => downloadAndAck(take)}
                        testId={`take-download-${take.id}`}
                    >
                        <Download class="size-4" aria-hidden="true" />
                    </Button>
                {/if}
                <Button
                    variant="ghost"
                    size="sm"
                    iconOnly
                    ariaLabel="コメント"
                    onclick={() => openComment(take)}
                    testId={`take-comment-${take.id}`}
                >
                    <Pencil class="size-4" aria-hidden="true" />
                </Button>
                <Button
                    variant="danger-ghost"
                    size="sm"
                    iconOnly
                    ariaLabel="削除"
                    onclick={() => requestDelete(take, index)}
                    testId={`take-delete-${take.id}`}
                >
                    <Trash2 class="size-4" aria-hidden="true" />
                </Button>
            </div>
        </div>
    {/each}
    {#if takeDrag.insertionIndex === cut.takes.length}
        <div class="h-0.5 bg-primary" aria-hidden="true"></div>
    {/if}
    <!-- 並べ替え結果の読み上げ (視覚的には出さない)。data-reorder-index を持たないので行に混ざらない -->
    <p class="sr-only" aria-live="polite" data-testid="take-reorder-status">{reorderStatus}</p>
    {#if error}
        <p class="text-caption text-danger" role="alert" data-testid="take-strip-error">{error}</p>
    {/if}
</div>

<TakePreviewDialog
    bind:open={previewOpen}
    take={previewTarget}
    {cut}
    {cutLabel}
    playbackUrl={previewUrl}
    adopting={previewTarget !== null && busyTakeId === previewTarget.id}
    {error}
    onAdopt={adoptFromPreview}
    onClose={handlePreviewClose}
/>

<TakeCommentDialog
    bind:open={commentDialogOpen}
    initial={commentTarget?.comment ?? ""}
    saving={commentSaving}
    error={commentError}
    onSave={saveComment}
/>

<ConfirmDialog
    bind:open={deleteDialogOpen}
    title="テイク削除"
    message={`${deleteLabel}を削除しますか？ この操作は取り消せません。`}
    confirmLabel="削除する"
    confirmVariant="danger"
    processing={deleteTargetId !== null && busyTakeId === deleteTargetId}
    onConfirm={confirmDelete}
    testId="take-delete-dialog"
/>
