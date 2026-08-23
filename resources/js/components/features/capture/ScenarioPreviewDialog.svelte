<script lang="ts">
    import { tick, untrack } from "svelte";
    import { Captions, CaptionsOff, LoaderCircle, Play, SkipForward } from "@lucide/svelte";
    import Alert from "@/components/atoms/Alert.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Modal from "@/components/organisms/Modal.svelte";
    import {
        buildPreviewEntries,
        initialPreviewState,
        missingCount,
        reducePreview,
        type PreviewEntry,
        type PreviewEvent,
        type PreviewOptions,
    } from "@/lib/capture/scenario-preview";
    import type { CaptureCut } from "@/types/capture";
    import { currentOrganizationSlug } from "@/lib/org-url";

    /**
     * 通し再生 (全体連結プレビュー。doc/05 §5.2 [プレビュー] / T191)。
     *
     * - 素材は**採用テイク**である (先頭テイクではない)。選択はサーバの
     *   `AdoptedReadyTakeCoverage` が決め、`cut.adopted_ready_take_id` として渡ってくる。
     * - 使用できる採用テイクが無いカットはプレースホルダを placeholderSeconds 秒表示して次へ進む。
     * - **1 本の失敗で通し再生を止めない**。判断は lib/capture/scenario-preview.ts が持ち、
     *   このコンポーネントは配線とメディア要素の操作だけを行う。
     * - **2 枚の <video> を交互に使う**。次のクリップは非表示側の要素へ先読みし、
     *   進むときに役割を入れ替える (同じ動画を 2 回取得しない)。
     *
     * **保証しないもの**: jsdom は実メディア再生を行わないため、component テストが固定できるのは
     * DOM 契約とイベント配線までである (実機での連続再生の滑らかさは実機確認の領域)。
     */
    interface Props {
        /** bindable。親 (Capture/Show) が `bind:open` で開閉する */
        open: boolean;
        projectId: number;
        manualId: number;
        cuts: CaptureCut[];
        /** buildCutLabels の結果 (規則を再実装しない) */
        labels: Record<number, string>;
        placeholderSeconds: number;
        onClose: () => void;
    }

    let {
        open = $bindable(false),
        projectId,
        manualId,
        cuts,
        labels,
        placeholderSeconds,
        onClose,
    }: Props = $props();

    /** 再生リスト。**open になった時点の cuts から 1 度だけ組む** (再生中に位置が飛ばない) */
    let entries = $state<PreviewEntry[]>([]);
    /**
     * 閉じている間の状態 (entries 0 件 = finished)。**open の時点で startPreview が必ず組み直す**ため、
     * ここで props を読まない (初期値だけを捕まえる参照を作らない)。
     */
    let previewState = $state(initialPreviewState({ entries: [], placeholderSeconds: 0 }, 0));
    let subtitlesOn = $state(true);

    /** 現在再生に使っている要素 (0 = videoA / 1 = videoB)。advance のたびに反転する */
    let activeSlot = $state<0 | 1>(0);
    /** 各 slot に**現在割り当てている src** (再代入による二重取得を防ぐ台帳) */
    let slotSrc = $state<[string | null, string | null]>([null, null]);
    /**
     * 各 slot に割り当てた**世代**の台帳。
     * slot の要素から届いたイベントには**この世代**を付けて reducer へ送る
     * (slot 反転後に旧要素から遅延イベントが届いても、世代不一致で捨てられる)。
     * active 割当時は現在の `generation`、先読み時は `generation + 1` を入れる。
     */
    let slotGeneration = $state<[number | null, number | null]>([null, null]);
    /**
     * slot 別の pause 抑止。**`pause()` の直後に戻さない** — pause イベントは非同期に配送されるため、
     * 「イベントを受けた時点で消費する」形にしないと抑止が効かない。
     * 2 枚あるので単一 boolean では発生元を区別できない。
     */
    let suppressPause = $state<[boolean, boolean]>([false, false]);
    /**
     * slot 別の**割り当て世代** (assignment epoch)。`{#key}` に渡して**要素ごと作り直す**ための値で、
     * `src + generation` を**別資源へ割り当て直すときだけ**増やす。
     *
     * 世代台帳 (`slotGeneration`) だけでは、次の順序を識別できない:
     *   (1) slot に旧 src・旧世代を割り当てる → (2) 旧 src 由来のイベントがキューへ入る →
     *   (3) 同じ slot を新 src・新世代へ割り当て直す → (4) 旧イベントが配送され、
     *   ハンドラが**新しい** slotGeneration を読んでしまう。
     * 要素ごと作り直せば listener も一緒に破棄されるため、この経路が構造的に消える。
     * **先読み済み slot の active 昇格では割り当てを変えない**ので、二重取得は起きない。
     */
    let assignmentId = $state<[number, number]>([0, 0]);

    // bind:this は**破棄時に null を書き戻す**ため null 許容で持つ (undefined ではない)
    let videoA = $state<HTMLVideoElement | null>(null);
    let videoB = $state<HTMLVideoElement | null>(null);
    let ticker: ReturnType<typeof setInterval> | null = null;
    /**
     * 再生セッションの識別子 (**単調増加**)。開始と終了のたびに +1 する。
     *
     * `generation` は 1 セッションの中でしか単調でない (startPreview が 0 から引き直す) ため、
     * **閉じて開き直した直後**は旧セッションの世代 0 と新セッションの世代 0 が一致してしまう。
     * `play()` の Promise は teardown 後も生き残るので、世代だけで照合すると
     * 旧セッションの `NotAllowedError` が新セッションを blocked にできる。
     * 非同期結果の受理は**セッションと世代の両方**が一致するときだけに限る。
     */
    let sessionId = 0;

    const missing = $derived(missingCount(entries));
    const currentEntry = $derived<PreviewEntry | undefined>(entries[previewState.index]);

    function currentOptions(): PreviewOptions {
        return { entries, placeholderSeconds };
    }

    function elementFor(slot: 0 | 1): HTMLVideoElement | null {
        return slot === 0 ? videoA : videoB;
    }

    function otherSlot(slot: 0 | 1): 0 | 1 {
        return slot === 0 ? 1 : 0;
    }

    /* ---- メディア要素の操作 (判断は lib 側。ここは操作だけ) ---- */

    /** メディア由来イベントの唯一の送出口。**世代が確定していないものは送らない** */
    type MediaOriginEventType = "progress" | "playing" | "paused" | "resumed" | "ended" | "error" | "blocked";

    function dispatchMediaEvent(slot: 0 | 1, type: MediaOriginEventType): void {
        const generation = slotGeneration[slot];
        // teardown 済み / 未割当の要素からの遅延イベントは捨てる
        // (`?? undefined` へ落とすと reducer が「世代省略 = 現在世代」とみなして誤適用する)
        if (generation === null) return;

        dispatch({ type, generation, at: Date.now() });
    }

    /** 自分から止めるときの唯一の入口。既に paused なら抑止を立てない (消費されない抑止を残さない) */
    function pauseProgrammatically(slot: 0 | 1, video: HTMLVideoElement): void {
        if (video.paused) {
            suppressPause[slot] = false;

            return;
        }
        suppressPause[slot] = true;
        video.pause();
    }

    function handlePause(slot: 0 | 1): void {
        if (suppressPause[slot]) {
            suppressPause[slot] = false; // 抑止は**イベントを受けた時点で消費**する

            return;
        }
        dispatchMediaEvent(slot, "paused");
    }

    /**
     * slot へ資源を割り当てる。**台帳と一致するなら何もしない** (再代入 = 再取得を作らない)。
     * 同一性は `src` だけでなく `src + generation` で判断する。
     */
    function assignSlot(slot: 0 | 1, entry: PreviewEntry, generation: number): void {
        if (entry.kind !== "clip") {
            teardownSlot(slot);

            return;
        }
        if (slotSrc[slot] === entry.src && slotGeneration[slot] === generation) return;

        // 別資源への割り当て直しなので要素ごと作り直す (旧 listener を構造的に捨てる)
        assignmentId[slot] += 1;
        slotSrc[slot] = entry.src;
        slotGeneration[slot] = generation;
        suppressPause[slot] = false;
    }

    /** slot の資源を解放する (pause → src 除去 → load)。台帳も同時に初期化する */
    function teardownSlot(slot: 0 | 1): void {
        const video = elementFor(slot);
        if (video !== null) {
            pauseProgrammatically(slot, video);
            video.removeAttribute("src");
            video.load();
        }
        slotSrc[slot] = null;
        slotGeneration[slot] = null;
        suppressPause[slot] = false;
    }

    /**
     * active slot の再生を試みる。
     *
     * **再生対象は `await tick()` の前に確定させる** (セッション / slot / 世代 / 割り当て世代の 4 つ)。
     * `tick()` の後に台帳を読み直す形にすると、待っている間に前進した場合に
     * **古い呼び出しが新しいクリップを再生してしまい** (二重 play)、その拒否が
     * 現在世代の `blocked` として適用される。照合は再生の直前と `catch` の両方で行う。
     */
    async function playActive(): Promise<void> {
        const session = sessionId;
        const slot = activeSlot;
        const generation = slotGeneration[slot];
        const assignment = assignmentId[slot];
        if (generation === null) return;

        await tick(); // src の反映 / 要素の再生成を待ってから再生する
        if (!isCurrentTarget(session, slot, generation, assignment)) return;
        // **待っている間に非表示になった / 再生要求のない状態へ移ったら再生しない**。
        // reducer が非表示中のイベントを捨てても、実メディアの再生自体は止まらないため、
        // ここで出すと直前の programmatic pause を打ち消してバックグラウンド再生になる。
        if (!previewState.visible) return;
        if (previewState.clip !== "loading" && previewState.clip !== "playing") return;
        const video = elementFor(slot);
        if (video === null) return;

        const started = video.play() as Promise<void> | undefined;
        if (started === undefined) return; // Promise を返さない実装 (古い WebKit / jsdom)

        started.catch((error: unknown) => {
            if (!isCurrentTarget(session, slot, generation, assignment)) return;
            if (generation !== previewState.generation) return;
            // **自動再生制限と判定できる拒否だけ** blocked にする。
            // それ以外は何も送らない (失敗の確定は error と停滞監視に委ねる)。
            if (error instanceof DOMException && error.name === "NotAllowedError") {
                dispatch({ type: "blocked", generation, at: Date.now() });
            }
        });
    }

    /** 退避した再生対象が今もそのままか (閉じた / 開き直した / 前進した / 割り当て直した を弾く) */
    function isCurrentTarget(
        session: number,
        slot: 0 | 1,
        generation: number,
        assignment: number,
    ): boolean {
        return (
            session === sessionId &&
            slot === activeSlot &&
            slotGeneration[slot] === generation &&
            assignmentId[slot] === assignment
        );
    }

    /** 現在クリップが再生に入ったら、**次の 1 件だけ**非表示側へ先読みする */
    function prefetchNext(): void {
        const inactive = otherSlot(activeSlot);
        const next = entries[previewState.index + 1];
        if (next === undefined || next.kind !== "clip") {
            teardownSlot(inactive);

            return;
        }
        assignSlot(inactive, next, previewState.generation + 1);
    }

    /**
     * 進んだ先の同期。先読みが無い経路 (先頭 / missing の後 / 先読み失敗) を補完する。
     * **台帳と一致するときは何もしない**ので、先読み成功経路で二重取得にならない。
     */
    function syncDestination(): void {
        const old = activeSlot;
        teardownSlot(old); // 再生し終えたクリップの資源を解放する
        const next = otherSlot(old);
        activeSlot = next;

        const entry = entries[previewState.index];
        if (entry === undefined || entry.kind !== "clip") {
            teardownSlot(next);

            return;
        }
        assignSlot(next, entry, previewState.generation);
        void playActive();
    }

    /* ---- 状態遷移の受け口 ---- */

    function dispatch(event: PreviewEvent): void {
        const before = previewState;
        const after = reducePreview(before, event, currentOptions());
        if (after === before) return;
        previewState = after;

        if (after.index !== before.index) {
            if (after.finished) {
                stopPlayback();

                return;
            }
            syncDestination();

            return;
        }
        if (after.clip === "playing" && before.clip !== "playing") {
            prefetchNext();
        }
    }

    /* ---- 開始 / 終了 ---- */

    function startPreview(): void {
        sessionId += 1; // 旧セッションの遅延結果 (play() の reject) をここで無効化する
        entries = buildPreviewEntries(cuts, labels, {
            organizationSlug: currentOrganizationSlug(),
            projectId,
            manualId,
        });
        previewState = initialPreviewState(currentOptions(), Date.now());
        subtitlesOn = true;
        activeSlot = 0;
        teardownSlot(0);
        teardownSlot(1);

        const first = entries[0];
        if (first !== undefined && first.kind === "clip") {
            assignSlot(0, first, previewState.generation);
            void playActive();
        }
        if (ticker !== null) clearInterval(ticker);
        ticker = setInterval(() => dispatch({ type: "tick", at: Date.now() }), 1_000);
        // 「もう一度再生」でも通るため、必ず外してから付ける (二重登録を作らない)
        document.removeEventListener("visibilitychange", handleVisibility);
        document.addEventListener("visibilitychange", handleVisibility);
    }

    /** メディア資源と時間駆動だけを止める (状態は残す = 終端表示を出せる) */
    function stopPlayback(): void {
        if (ticker !== null) {
            clearInterval(ticker);
            ticker = null;
        }
        teardownSlot(0);
        teardownSlot(1);
    }

    function stopPreview(): void {
        sessionId += 1; // 閉じた時点で受理を打ち切る (再オープンが同じ世代 0 から始まるため)
        stopPlayback();
        document.removeEventListener("visibilitychange", handleVisibility);
    }

    function handleVisibility(): void {
        if (document.visibilityState !== "visible") {
            const video = elementFor(activeSlot);
            // 非表示中に ended で勝手に次へ進まないよう、実メディアも自分から止める
            if (video !== null) pauseProgrammatically(activeSlot, video);
            dispatch({ type: "hidden", at: Date.now() });

            return;
        }
        dispatch({ type: "shown", at: Date.now() });
        // 再生要求のある状態 (再生中 / 読み込み中) だけ再開を試みる。
        // **loading も含める**のは、非表示で再生要求を見送った直後に復帰したとき、
        // 誰も再生を出し直さないまま停滞監視の回収 (最大 stallTimeoutMs) を待つことになるためである。
        // paused / blocked では何もしない (再生状態を勝手に変えない)。
        if (previewState.clip === "playing" || previewState.clip === "loading") void playActive();
    }

    // 開閉の単一の観測点。**true→false でだけ**後始末して親へ通知する
    // (背景クリック / Esc / × / 閉じるボタンをすべて拾う)。
    let wasOpen = false;
    $effect(() => {
        if (open === wasOpen) return;
        wasOpen = open;
        if (open) {
            untrack(() => startPreview());

            return;
        }
        untrack(() => {
            stopPreview();
            onClose();
        });
    });

    // component 破棄時も必ず資源を解放する (interval / listener を残さない)
    $effect(() => () => stopPreview());

    /* ---- 利用者操作 ---- */

    function retry(): void {
        dispatch({ type: "retry", at: Date.now() });
        void playActive();
    }

    function skip(): void {
        dispatch({ type: "skip", at: Date.now() });
    }

    function replay(): void {
        startPreview();
    }
</script>

<Modal bind:open title="通し再生" size="lg" testId="scenario-preview-dialog">
    <!-- 再生の内部状態を DOM 契約として露出する (Capture/Show の data-fullscreen と同じ流儀)。
         これが無いと「一時停止したか」「どちらの要素が再生中か」を DOM から観測できない。 -->
    <div
        class="flex flex-col gap-3"
        data-testid="scenario-preview-body"
        data-clip={previewState.clip}
        data-index={previewState.index}
        data-generation={previewState.generation}
        data-active-slot={activeSlot}
    >
        <div class="flex items-center justify-between gap-2">
            <p class="text-caption text-text-secondary" data-testid="scenario-preview-position">
                {#if previewState.finished || currentEntry === undefined}
                    {entries.length} / {entries.length}
                {:else}
                    {currentEntry.label} ({previewState.index + 1} / {entries.length})
                {/if}
            </p>
            <Button
                variant="ghost"
                size="sm"
                onclick={() => (subtitlesOn = !subtitlesOn)}
                ariaExpanded={subtitlesOn}
                testId="scenario-preview-subtitle-toggle"
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

        {#if missing > 0}
            <Alert type="warning" testId="scenario-preview-coverage-note">
                {missing} / {entries.length} 件のカットに、撮影・処理が完了した採用テイクがありません。その区間はプレースホルダになります。
            </Alert>
        {/if}

        <div class="relative w-full overflow-hidden rounded-md bg-text/5">
            <!-- 2 枚の要素を交互に使う。**非表示側は先読み用**であり、進むときに役割が入れ替わる -->
            {#key assignmentId[0]}
                <!-- svelte-ignore a11y_media_has_caption -->
                <video
                    bind:this={videoA}
                    controls
                    playsinline
                    preload="auto"
                    src={slotSrc[0] ?? undefined}
                    class={activeSlot === 0 ? "w-full" : "hidden"}
                    aria-label="通し再生 (1 枚目)"
                    data-testid="scenario-preview-video-0"
                    data-assignment={assignmentId[0]}
                    onplaying={() => dispatchMediaEvent(0, "playing")}
                    onplay={() => dispatchMediaEvent(0, "resumed")}
                    onpause={() => handlePause(0)}
                    onended={() => dispatchMediaEvent(0, "ended")}
                    onerror={() => dispatchMediaEvent(0, "error")}
                    oncanplay={() => dispatchMediaEvent(0, "progress")}
                    ontimeupdate={() => dispatchMediaEvent(0, "progress")}
                    onprogress={() => dispatchMediaEvent(0, "progress")}
                ></video>
            {/key}
            {#key assignmentId[1]}
                <!-- svelte-ignore a11y_media_has_caption -->
                <video
                    bind:this={videoB}
                    controls
                    playsinline
                    preload="auto"
                    src={slotSrc[1] ?? undefined}
                    class={activeSlot === 1 ? "w-full" : "hidden"}
                    aria-label="通し再生 (2 枚目)"
                    data-testid="scenario-preview-video-1"
                    data-assignment={assignmentId[1]}
                    onplaying={() => dispatchMediaEvent(1, "playing")}
                    onplay={() => dispatchMediaEvent(1, "resumed")}
                    onpause={() => handlePause(1)}
                    onended={() => dispatchMediaEvent(1, "ended")}
                    onerror={() => dispatchMediaEvent(1, "error")}
                    oncanplay={() => dispatchMediaEvent(1, "progress")}
                    ontimeupdate={() => dispatchMediaEvent(1, "progress")}
                    onprogress={() => dispatchMediaEvent(1, "progress")}
                ></video>
            {/key}

            {#if !previewState.finished && currentEntry !== undefined}
                {#if currentEntry.kind === "missing"}
                    <p
                        class="flex min-h-32 items-center justify-center p-4 text-body text-text-secondary"
                        data-testid="scenario-preview-placeholder"
                    >
                        {currentEntry.label}: 撮影・処理が完了した採用テイクがありません
                    </p>
                {:else if previewState.clip === "failed"}
                    <p
                        class="flex min-h-32 items-center justify-center p-4 text-body text-text-secondary"
                        data-testid="scenario-preview-failed"
                    >
                        {currentEntry.label}: このカットは再生できませんでした
                    </p>
                {:else if previewState.clip === "loading"}
                    <p
                        class="flex items-center justify-center gap-2 p-4 text-caption text-text-secondary"
                        data-testid="scenario-preview-loading"
                    >
                        <LoaderCircle class="size-4 animate-spin" aria-hidden="true" />
                        読み込み中
                    </p>
                {/if}
            {/if}

            {#if subtitlesOn && currentEntry !== undefined && !previewState.finished}
                <div class="pointer-events-none absolute inset-0 flex flex-col justify-between p-3">
                    {#if currentEntry.subtitlePrimary !== null && currentEntry.subtitlePrimary !== ""}
                        <span
                            class="self-start rounded-sm bg-surface/80 px-2 py-1 text-caption text-text-secondary"
                            aria-live="off"
                            data-testid="scenario-preview-subtitle-primary"
                        >
                            {currentEntry.subtitlePrimary}
                        </span>
                    {:else}
                        <span></span>
                    {/if}
                    {#if currentEntry.subtitleSecondary !== ""}
                        <span
                            class="self-stretch rounded-sm bg-surface/80 px-2 py-1 text-body text-text"
                            aria-live="off"
                            data-testid="scenario-preview-subtitle-secondary"
                        >
                            {currentEntry.subtitleSecondary}
                        </span>
                    {/if}
                </div>
            {/if}
        </div>

        {#if previewState.clip === "blocked" && !previewState.finished}
            <Alert type="info" testId="scenario-preview-blocked">
                このカットの自動再生がブラウザに止められました。再生を続けるか、このカットをスキップしてください。
            </Alert>
            <div class="flex flex-wrap items-center gap-2">
                <Button variant="primary" size="sm" onclick={retry} testId="scenario-preview-retry">
                    <Play class="size-4" aria-hidden="true" />
                    再生を続ける
                </Button>
                <Button variant="neutral" size="sm" onclick={skip} testId="scenario-preview-skip">
                    <SkipForward class="size-4" aria-hidden="true" />
                    このカットをスキップ
                </Button>
            </div>
        {/if}

        {#if previewState.finished}
            <p class="text-body text-text" role="status" data-testid="scenario-preview-finished">
                すべてのカットを再生しました。
            </p>
        {/if}
    </div>

    {#snippet footer()}
        {#if previewState.finished}
            <Button variant="neutral" size="sm" onclick={replay} testId="scenario-preview-replay">
                <Play class="size-4" aria-hidden="true" />
                もう一度再生
            </Button>
        {/if}
        <Button variant="neutral" onclick={() => (open = false)} testId="scenario-preview-close">
            閉じる
        </Button>
    {/snippet}
</Modal>
