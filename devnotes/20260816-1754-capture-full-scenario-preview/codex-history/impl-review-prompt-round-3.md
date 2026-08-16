# Round 3: Round 2 指摘への対応

Round 2 の [Critical] 1 件・[Warning] 1 件に対応した。**再レビューして全体判定を出してほしい**。

## 対応マトリクス

# 対応マトリクス: impl-review Round 2

## [Critical] 同一セッション内で `await tick()` をまたぐ古い `playActive()` が、前進後のクリップを再生する

- 判断: **対応する**
- 根拠: 指摘のとおりである。Round 1 の修正は `sessionId` を `tick()` 前に退避したが、
  **再生対象そのもの** (`activeSlot` / `slotGeneration[slot]` / `assignmentId[slot]`) は
  `tick()` の後に読み直していた。保留中に前進すると、古い呼び出しが新しいクリップに対して
  `play()` を重ねて呼び (= 同じ資源への二重再生要求)、その拒否は**現在世代と一致する**ため
  誤って `blocked` になる。詳細設計 S5 の「呼び出し時点の generation を closure へ退避してから
  `play()` する」という契約とも食い違っていた。
- 対応内容:
  - `playActive()` は **`await tick()` の前に 4 つ (session / slot / generation / assignment) を
    退避**し、再生の直前と `catch` の両方で `isCurrentTarget()` により照合する形にした。
    照合に落ちた呼び出しは `play()` を呼ばずに終わる (二重再生要求そのものを作らない)。
  - `assignmentId` も照合に入れたのは、同一 slot・同一世代でも要素を作り直した場合
    (別資源への割り当て直し) に旧要素へ `play()` しないためである。
  - テスト追加 (component):
    「tick 待ちの間に前進した古い再生要求は、新しいクリップを再生しない」。
    render 直後 (playActive が `await tick()` で保留中) に同期的に `ended` を発火させ、
    `play()` が**進んだ先のクリップに対して 1 回だけ**呼ばれることを、
    呼び出し先の要素の同一性まで含めて固定した。
  - **fail-first を実測で確認した**: `playActive()` を修正前の形 (tick 後に台帳を読み直す)
    へ戻すと、この 1 本だけが `played` が 2 件になって赤くなることを確認してから戻した。

## [Warning] close → reopen / replay の直接固定が無い (unmount → 再 render での検証になっている)

- 判断: **対応する**
- 根拠: 指摘のとおり、実運用は `bind:open` による**同一インスタンス**の開閉であり、
  unmount を挟む形はその経路を通っていない。`replay()` も同様に未固定だった。
- 対応内容: component テストを 2 本追加した。
  - 「同一インスタンスの close → reopen をまたぐ拒否も新セッションへ混入しない」
    (`rerender({ open: false })` → `rerender({ open: true })` で同一インスタンスを開閉する)
  - 「もう一度再生の後に届く旧セッションの拒否も混入しない」
    (終端 → `replay()` → 旧 `play()` を reject)

## [問題なし] `scenario-preview.ts` / lib テスト / page テスト

- 判断: **対応不要**
- 根拠: Round 2 で「前進経路を塞いでいない」「待機状態ガードを外すと赤くなる」ことを
  確認済みという判定を受けた。`retry` が `failed` / `placeholder` でも通る点は、
  現行 UI にその導線が無いため不整合にならないという判定も一致している
  (将来 `failed` に再試行導線を足すときは、`retry` が待機状態から `loading` へ戻す
  既存の遷移がそのまま使える)。

## 修正後の差分 (Round 1 提示分からの累積差分。該当 2 ファイルのみ)

```diff
diff --git a/resources/js/components/features/capture/ScenarioPreviewDialog.svelte b/resources/js/components/features/capture/ScenarioPreviewDialog.svelte
new file mode 100644
index 0000000..d00d18e
--- /dev/null
+++ b/resources/js/components/features/capture/ScenarioPreviewDialog.svelte
@@ -0,0 +1,556 @@
+<script lang="ts">
+    import { tick, untrack } from "svelte";
+    import { Captions, CaptionsOff, LoaderCircle, Play, SkipForward } from "@lucide/svelte";
+    import Alert from "@/components/atoms/Alert.svelte";
+    import Button from "@/components/atoms/Button.svelte";
+    import Modal from "@/components/organisms/Modal.svelte";
+    import {
+        buildPreviewEntries,
+        initialPreviewState,
+        missingCount,
+        reducePreview,
+        type PreviewEntry,
+        type PreviewEvent,
+        type PreviewOptions,
+    } from "@/lib/capture/scenario-preview";
+    import type { CaptureCut } from "@/types/capture";
+
+    /**
+     * 通し再生 (全体連結プレビュー。doc/05 §5.2 [プレビュー] / T191)。
+     *
+     * - 素材は**採用テイク**である (先頭テイクではない)。選択はサーバの
+     *   `AdoptedReadyTakeCoverage` が決め、`cut.adopted_ready_take_id` として渡ってくる。
+     * - 使用できる採用テイクが無いカットはプレースホルダを placeholderSeconds 秒表示して次へ進む。
+     * - **1 本の失敗で通し再生を止めない**。判断は lib/capture/scenario-preview.ts が持ち、
+     *   このコンポーネントは配線とメディア要素の操作だけを行う。
+     * - **2 枚の <video> を交互に使う**。次のクリップは非表示側の要素へ先読みし、
+     *   進むときに役割を入れ替える (同じ動画を 2 回取得しない)。
+     *
+     * **保証しないもの**: jsdom は実メディア再生を行わないため、component テストが固定できるのは
+     * DOM 契約とイベント配線までである (実機での連続再生の滑らかさは実機確認の領域)。
+     */
+    interface Props {
+        /** bindable。親 (Capture/Show) が `bind:open` で開閉する */
+        open: boolean;
+        projectId: number;
+        manualId: number;
+        cuts: CaptureCut[];
+        /** buildCutLabels の結果 (規則を再実装しない) */
+        labels: Record<number, string>;
+        placeholderSeconds: number;
+        onClose: () => void;
+    }
+
+    let {
+        open = $bindable(false),
+        projectId,
+        manualId,
+        cuts,
+        labels,
+        placeholderSeconds,
+        onClose,
+    }: Props = $props();
+
+    /** 再生リスト。**open になった時点の cuts から 1 度だけ組む** (再生中に位置が飛ばない) */
+    let entries = $state<PreviewEntry[]>([]);
+    /**
+     * 閉じている間の状態 (entries 0 件 = finished)。**open の時点で startPreview が必ず組み直す**ため、
+     * ここで props を読まない (初期値だけを捕まえる参照を作らない)。
+     */
+    let previewState = $state(initialPreviewState({ entries: [], placeholderSeconds: 0 }, 0));
+    let subtitlesOn = $state(true);
+
+    /** 現在再生に使っている要素 (0 = videoA / 1 = videoB)。advance のたびに反転する */
+    let activeSlot = $state<0 | 1>(0);
+    /** 各 slot に**現在割り当てている src** (再代入による二重取得を防ぐ台帳) */
+    let slotSrc = $state<[string | null, string | null]>([null, null]);
+    /**
+     * 各 slot に割り当てた**世代**の台帳。
+     * slot の要素から届いたイベントには**この世代**を付けて reducer へ送る
+     * (slot 反転後に旧要素から遅延イベントが届いても、世代不一致で捨てられる)。
+     * active 割当時は現在の `generation`、先読み時は `generation + 1` を入れる。
+     */
+    let slotGeneration = $state<[number | null, number | null]>([null, null]);
+    /**
+     * slot 別の pause 抑止。**`pause()` の直後に戻さない** — pause イベントは非同期に配送されるため、
+     * 「イベントを受けた時点で消費する」形にしないと抑止が効かない。
+     * 2 枚あるので単一 boolean では発生元を区別できない。
+     */
+    let suppressPause = $state<[boolean, boolean]>([false, false]);
+    /**
+     * slot 別の**割り当て世代** (assignment epoch)。`{#key}` に渡して**要素ごと作り直す**ための値で、
+     * `src + generation` を**別資源へ割り当て直すときだけ**増やす。
+     *
+     * 世代台帳 (`slotGeneration`) だけでは、次の順序を識別できない:
+     *   (1) slot に旧 src・旧世代を割り当てる → (2) 旧 src 由来のイベントがキューへ入る →
+     *   (3) 同じ slot を新 src・新世代へ割り当て直す → (4) 旧イベントが配送され、
+     *   ハンドラが**新しい** slotGeneration を読んでしまう。
+     * 要素ごと作り直せば listener も一緒に破棄されるため、この経路が構造的に消える。
+     * **先読み済み slot の active 昇格では割り当てを変えない**ので、二重取得は起きない。
+     */
+    let assignmentId = $state<[number, number]>([0, 0]);
+
+    // bind:this は**破棄時に null を書き戻す**ため null 許容で持つ (undefined ではない)
+    let videoA = $state<HTMLVideoElement | null>(null);
+    let videoB = $state<HTMLVideoElement | null>(null);
+    let ticker: ReturnType<typeof setInterval> | null = null;
+    /**
+     * 再生セッションの識別子 (**単調増加**)。開始と終了のたびに +1 する。
+     *
+     * `generation` は 1 セッションの中でしか単調でない (startPreview が 0 から引き直す) ため、
+     * **閉じて開き直した直後**は旧セッションの世代 0 と新セッションの世代 0 が一致してしまう。
+     * `play()` の Promise は teardown 後も生き残るので、世代だけで照合すると
+     * 旧セッションの `NotAllowedError` が新セッションを blocked にできる。
+     * 非同期結果の受理は**セッションと世代の両方**が一致するときだけに限る。
+     */
+    let sessionId = 0;
+
+    const missing = $derived(missingCount(entries));
+    const currentEntry = $derived<PreviewEntry | undefined>(entries[previewState.index]);
+
+    function currentOptions(): PreviewOptions {
+        return { entries, placeholderSeconds };
+    }
+
+    function elementFor(slot: 0 | 1): HTMLVideoElement | null {
+        return slot === 0 ? videoA : videoB;
+    }
+
+    function otherSlot(slot: 0 | 1): 0 | 1 {
+        return slot === 0 ? 1 : 0;
+    }
+
+    /* ---- メディア要素の操作 (判断は lib 側。ここは操作だけ) ---- */
+
+    /** メディア由来イベントの唯一の送出口。**世代が確定していないものは送らない** */
+    type MediaOriginEventType = "progress" | "playing" | "paused" | "resumed" | "ended" | "error" | "blocked";
+
+    function dispatchMediaEvent(slot: 0 | 1, type: MediaOriginEventType): void {
+        const generation = slotGeneration[slot];
+        // teardown 済み / 未割当の要素からの遅延イベントは捨てる
+        // (`?? undefined` へ落とすと reducer が「世代省略 = 現在世代」とみなして誤適用する)
+        if (generation === null) return;
+
+        dispatch({ type, generation, at: Date.now() });
+    }
+
+    /** 自分から止めるときの唯一の入口。既に paused なら抑止を立てない (消費されない抑止を残さない) */
+    function pauseProgrammatically(slot: 0 | 1, video: HTMLVideoElement): void {
+        if (video.paused) {
+            suppressPause[slot] = false;
+
+            return;
+        }
+        suppressPause[slot] = true;
+        video.pause();
+    }
+
+    function handlePause(slot: 0 | 1): void {
+        if (suppressPause[slot]) {
+            suppressPause[slot] = false; // 抑止は**イベントを受けた時点で消費**する
+
+            return;
+        }
+        dispatchMediaEvent(slot, "paused");
+    }
+
+    /**
+     * slot へ資源を割り当てる。**台帳と一致するなら何もしない** (再代入 = 再取得を作らない)。
+     * 同一性は `src` だけでなく `src + generation` で判断する。
+     */
+    function assignSlot(slot: 0 | 1, entry: PreviewEntry, generation: number): void {
+        if (entry.kind !== "clip") {
+            teardownSlot(slot);
+
+            return;
+        }
+        if (slotSrc[slot] === entry.src && slotGeneration[slot] === generation) return;
+
+        // 別資源への割り当て直しなので要素ごと作り直す (旧 listener を構造的に捨てる)
+        assignmentId[slot] += 1;
+        slotSrc[slot] = entry.src;
+        slotGeneration[slot] = generation;
+        suppressPause[slot] = false;
+    }
+
+    /** slot の資源を解放する (pause → src 除去 → load)。台帳も同時に初期化する */
+    function teardownSlot(slot: 0 | 1): void {
+        const video = elementFor(slot);
+        if (video !== null) {
+            pauseProgrammatically(slot, video);
+            video.removeAttribute("src");
+            video.load();
+        }
+        slotSrc[slot] = null;
+        slotGeneration[slot] = null;
+        suppressPause[slot] = false;
+    }
+
+    /**
+     * active slot の再生を試みる。
+     *
+     * **再生対象は `await tick()` の前に確定させる** (セッション / slot / 世代 / 割り当て世代の 4 つ)。
+     * `tick()` の後に台帳を読み直す形にすると、待っている間に前進した場合に
+     * **古い呼び出しが新しいクリップを再生してしまい** (二重 play)、その拒否が
+     * 現在世代の `blocked` として適用される。照合は再生の直前と `catch` の両方で行う。
+     */
+    async function playActive(): Promise<void> {
+        const session = sessionId;
+        const slot = activeSlot;
+        const generation = slotGeneration[slot];
+        const assignment = assignmentId[slot];
+        if (generation === null) return;
+
+        await tick(); // src の反映 / 要素の再生成を待ってから再生する
+        if (!isCurrentTarget(session, slot, generation, assignment)) return;
+        const video = elementFor(slot);
+        if (video === null) return;
+
+        const started = video.play() as Promise<void> | undefined;
+        if (started === undefined) return; // Promise を返さない実装 (古い WebKit / jsdom)
+
+        started.catch((error: unknown) => {
+            if (!isCurrentTarget(session, slot, generation, assignment)) return;
+            if (generation !== previewState.generation) return;
+            // **自動再生制限と判定できる拒否だけ** blocked にする。
+            // それ以外は何も送らない (失敗の確定は error と停滞監視に委ねる)。
+            if (error instanceof DOMException && error.name === "NotAllowedError") {
+                dispatch({ type: "blocked", generation, at: Date.now() });
+            }
+        });
+    }
+
+    /** 退避した再生対象が今もそのままか (閉じた / 開き直した / 前進した / 割り当て直した を弾く) */
+    function isCurrentTarget(
+        session: number,
+        slot: 0 | 1,
+        generation: number,
+        assignment: number,
+    ): boolean {
+        return (
+            session === sessionId &&
+            slot === activeSlot &&
+            slotGeneration[slot] === generation &&
+            assignmentId[slot] === assignment
+        );
+    }
+
+    /** 現在クリップが再生に入ったら、**次の 1 件だけ**非表示側へ先読みする */
+    function prefetchNext(): void {
+        const inactive = otherSlot(activeSlot);
+        const next = entries[previewState.index + 1];
+        if (next === undefined || next.kind !== "clip") {
+            teardownSlot(inactive);
+
+            return;
+        }
+        assignSlot(inactive, next, previewState.generation + 1);
+    }
+
+    /**
+     * 進んだ先の同期。先読みが無い経路 (先頭 / missing の後 / 先読み失敗) を補完する。
+     * **台帳と一致するときは何もしない**ので、先読み成功経路で二重取得にならない。
+     */
+    function syncDestination(): void {
+        const old = activeSlot;
+        teardownSlot(old); // 再生し終えたクリップの資源を解放する
+        const next = otherSlot(old);
+        activeSlot = next;
+
+        const entry = entries[previewState.index];
+        if (entry === undefined || entry.kind !== "clip") {
+            teardownSlot(next);
+
+            return;
+        }
+        assignSlot(next, entry, previewState.generation);
+        void playActive();
+    }
+
+    /* ---- 状態遷移の受け口 ---- */
+
+    function dispatch(event: PreviewEvent): void {
+        const before = previewState;
+        const after = reducePreview(before, event, currentOptions());
+        if (after === before) return;
+        previewState = after;
+
+        if (after.index !== before.index) {
+            if (after.finished) {
+                stopPlayback();
+
+                return;
+            }
+            syncDestination();
+
+            return;
+        }
+        if (after.clip === "playing" && before.clip !== "playing") {
+            prefetchNext();
+        }
+    }
+
+    /* ---- 開始 / 終了 ---- */
+
+    function startPreview(): void {
+        sessionId += 1; // 旧セッションの遅延結果 (play() の reject) をここで無効化する
+        entries = buildPreviewEntries(cuts, labels, { projectId, manualId });
+        previewState = initialPreviewState(currentOptions(), Date.now());
+        subtitlesOn = true;
+        activeSlot = 0;
+        teardownSlot(0);
+        teardownSlot(1);
+
+        const first = entries[0];
+        if (first !== undefined && first.kind === "clip") {
+            assignSlot(0, first, previewState.generation);
+            void playActive();
+        }
+        if (ticker !== null) clearInterval(ticker);
+        ticker = setInterval(() => dispatch({ type: "tick", at: Date.now() }), 1_000);
+        // 「もう一度再生」でも通るため、必ず外してから付ける (二重登録を作らない)
+        document.removeEventListener("visibilitychange", handleVisibility);
+        document.addEventListener("visibilitychange", handleVisibility);
+    }
+
+    /** メディア資源と時間駆動だけを止める (状態は残す = 終端表示を出せる) */
+    function stopPlayback(): void {
+        if (ticker !== null) {
+            clearInterval(ticker);
+            ticker = null;
+        }
+        teardownSlot(0);
+        teardownSlot(1);
+    }
+
+    function stopPreview(): void {
+        sessionId += 1; // 閉じた時点で受理を打ち切る (再オープンが同じ世代 0 から始まるため)
+        stopPlayback();
+        document.removeEventListener("visibilitychange", handleVisibility);
+    }
+
+    function handleVisibility(): void {
+        if (document.visibilityState !== "visible") {
+            const video = elementFor(activeSlot);
+            // 非表示中に ended で勝手に次へ進まないよう、実メディアも自分から止める
+            if (video !== null) pauseProgrammatically(activeSlot, video);
+            dispatch({ type: "hidden", at: Date.now() });
+
+            return;
+        }
+        dispatch({ type: "shown", at: Date.now() });
+        if (previewState.clip === "playing") void playActive();
+    }
+
+    // 開閉の単一の観測点。**true→false でだけ**後始末して親へ通知する
+    // (背景クリック / Esc / × / 閉じるボタンをすべて拾う)。
+    let wasOpen = false;
+    $effect(() => {
+        if (open === wasOpen) return;
+        wasOpen = open;
+        if (open) {
+            untrack(() => startPreview());
+
+            return;
+        }
+        untrack(() => {
+            stopPreview();
+            onClose();
+        });
+    });
+
+    // component 破棄時も必ず資源を解放する (interval / listener を残さない)
+    $effect(() => () => stopPreview());
+
+    /* ---- 利用者操作 ---- */
+
+    function retry(): void {
+        dispatch({ type: "retry", at: Date.now() });
+        void playActive();
+    }
+
+    function skip(): void {
+        dispatch({ type: "skip", at: Date.now() });
+    }
+
+    function replay(): void {
+        startPreview();
+    }
+</script>
+
+<Modal bind:open title="通し再生" size="lg" testId="scenario-preview-dialog">
+    <!-- 再生の内部状態を DOM 契約として露出する (Capture/Show の data-fullscreen と同じ流儀)。
+         これが無いと「一時停止したか」「どちらの要素が再生中か」を DOM から観測できない。 -->
+    <div
+        class="flex flex-col gap-3"
+        data-testid="scenario-preview-body"
+        data-clip={previewState.clip}
+        data-index={previewState.index}
+        data-generation={previewState.generation}
+        data-active-slot={activeSlot}
+    >
+        <div class="flex items-center justify-between gap-2">
+            <p class="text-caption text-text-secondary" data-testid="scenario-preview-position">
+                {#if previewState.finished || currentEntry === undefined}
+                    {entries.length} / {entries.length}
+                {:else}
+                    {currentEntry.label} ({previewState.index + 1} / {entries.length})
+                {/if}
+            </p>
+            <Button
+                variant="ghost"
+                size="sm"
+                onclick={() => (subtitlesOn = !subtitlesOn)}
+                ariaExpanded={subtitlesOn}
+                testId="scenario-preview-subtitle-toggle"
+            >
+                {#if subtitlesOn}
+                    <Captions class="size-4" aria-hidden="true" />
+                    字幕を隠す
+                {:else}
+                    <CaptionsOff class="size-4" aria-hidden="true" />
+                    字幕を表示
+                {/if}
+            </Button>
+        </div>
+
+        {#if missing > 0}
+            <Alert type="warning" testId="scenario-preview-coverage-note">
+                {missing} / {entries.length} 件のカットに、撮影・処理が完了した採用テイクがありません。その区間はプレースホルダになります。
+            </Alert>
+        {/if}
+
+        <div class="relative w-full overflow-hidden rounded-md bg-text/5">
+            <!-- 2 枚の要素を交互に使う。**非表示側は先読み用**であり、進むときに役割が入れ替わる -->
+            {#key assignmentId[0]}
+                <!-- svelte-ignore a11y_media_has_caption -->
+                <video
+                    bind:this={videoA}
+                    controls
+                    playsinline
+                    preload="auto"
+                    src={slotSrc[0] ?? undefined}
+                    class={activeSlot === 0 ? "w-full" : "hidden"}
+                    aria-label="通し再生 (1 枚目)"
+                    data-testid="scenario-preview-video-0"
+                    data-assignment={assignmentId[0]}
+                    onplaying={() => dispatchMediaEvent(0, "playing")}
+                    onplay={() => dispatchMediaEvent(0, "resumed")}
+                    onpause={() => handlePause(0)}
+                    onended={() => dispatchMediaEvent(0, "ended")}
+                    onerror={() => dispatchMediaEvent(0, "error")}
+                    oncanplay={() => dispatchMediaEvent(0, "progress")}
+                    ontimeupdate={() => dispatchMediaEvent(0, "progress")}
+                    onprogress={() => dispatchMediaEvent(0, "progress")}
+                ></video>
+            {/key}
+            {#key assignmentId[1]}
+                <!-- svelte-ignore a11y_media_has_caption -->
+                <video
+                    bind:this={videoB}
+                    controls
+                    playsinline
+                    preload="auto"
+                    src={slotSrc[1] ?? undefined}
+                    class={activeSlot === 1 ? "w-full" : "hidden"}
+                    aria-label="通し再生 (2 枚目)"
+                    data-testid="scenario-preview-video-1"
+                    data-assignment={assignmentId[1]}
+                    onplaying={() => dispatchMediaEvent(1, "playing")}
+                    onplay={() => dispatchMediaEvent(1, "resumed")}
+                    onpause={() => handlePause(1)}
+                    onended={() => dispatchMediaEvent(1, "ended")}
+                    onerror={() => dispatchMediaEvent(1, "error")}
+                    oncanplay={() => dispatchMediaEvent(1, "progress")}
+                    ontimeupdate={() => dispatchMediaEvent(1, "progress")}
+                    onprogress={() => dispatchMediaEvent(1, "progress")}
+                ></video>
+            {/key}
+
+            {#if !previewState.finished && currentEntry !== undefined}
+                {#if currentEntry.kind === "missing"}
+                    <p
+                        class="flex min-h-32 items-center justify-center p-4 text-body text-text-secondary"
+                        data-testid="scenario-preview-placeholder"
+                    >
+                        {currentEntry.label}: 撮影・処理が完了した採用テイクがありません
+                    </p>
+                {:else if previewState.clip === "failed"}
+                    <p
+                        class="flex min-h-32 items-center justify-center p-4 text-body text-text-secondary"
+                        data-testid="scenario-preview-failed"
+                    >
+                        {currentEntry.label}: このカットは再生できませんでした
+                    </p>
+                {:else if previewState.clip === "loading"}
+                    <p
+                        class="flex items-center justify-center gap-2 p-4 text-caption text-text-secondary"
+                        data-testid="scenario-preview-loading"
+                    >
+                        <LoaderCircle class="size-4 animate-spin" aria-hidden="true" />
+                        読み込み中
+                    </p>
+                {/if}
+            {/if}
+
+            {#if subtitlesOn && currentEntry !== undefined && !previewState.finished}
+                <div class="pointer-events-none absolute inset-0 flex flex-col justify-between p-3">
+                    {#if currentEntry.subtitlePrimary !== null && currentEntry.subtitlePrimary !== ""}
+                        <span
+                            class="self-start rounded-sm bg-surface/80 px-2 py-1 text-caption text-text-secondary"
+                            aria-live="off"
+                            data-testid="scenario-preview-subtitle-primary"
+                        >
+                            {currentEntry.subtitlePrimary}
+                        </span>
+                    {:else}
+                        <span></span>
+                    {/if}
+                    {#if currentEntry.subtitleSecondary !== ""}
+                        <span
+                            class="self-stretch rounded-sm bg-surface/80 px-2 py-1 text-body text-text"
+                            aria-live="off"
+                            data-testid="scenario-preview-subtitle-secondary"
+                        >
+                            {currentEntry.subtitleSecondary}
+                        </span>
+                    {/if}
+                </div>
+            {/if}
+        </div>
+
+        {#if previewState.clip === "blocked" && !previewState.finished}
+            <Alert type="info" testId="scenario-preview-blocked">
+                このカットの自動再生がブラウザに止められました。再生を続けるか、このカットをスキップしてください。
+            </Alert>
+            <div class="flex flex-wrap items-center gap-2">
+                <Button variant="primary" size="sm" onclick={retry} testId="scenario-preview-retry">
+                    <Play class="size-4" aria-hidden="true" />
+                    再生を続ける
+                </Button>
+                <Button variant="neutral" size="sm" onclick={skip} testId="scenario-preview-skip">
+                    <SkipForward class="size-4" aria-hidden="true" />
+                    このカットをスキップ
+                </Button>
+            </div>
+        {/if}
+
+        {#if previewState.finished}
+            <p class="text-body text-text" role="status" data-testid="scenario-preview-finished">
+                すべてのカットを再生しました。
+            </p>
+        {/if}
+    </div>
+
+    {#snippet footer()}
+        {#if previewState.finished}
+            <Button variant="neutral" size="sm" onclick={replay} testId="scenario-preview-replay">
+                <Play class="size-4" aria-hidden="true" />
+                もう一度再生
+            </Button>
+        {/if}
+        <Button variant="neutral" onclick={() => (open = false)} testId="scenario-preview-close">
+            閉じる
+        </Button>
+    {/snippet}
+</Modal>
diff --git a/tests/js/components/features/capture/ScenarioPreviewDialog.test.ts b/tests/js/components/features/capture/ScenarioPreviewDialog.test.ts
new file mode 100644
index 0000000..65c8d61
--- /dev/null
+++ b/tests/js/components/features/capture/ScenarioPreviewDialog.test.ts
@@ -0,0 +1,556 @@
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
+import ScenarioPreviewDialog from "@/components/features/capture/ScenarioPreviewDialog.svelte";
+import type { CaptureCut } from "@/types/capture";
+
+/*
+ * ScenarioPreviewDialog (通し再生 / T191)。
+ *
+ * jsdom は実メディア再生を行わないため、ここで固定できるのは **DOM 契約とイベント配線**まで
+ * である (実機での連続再生の滑らかさは実機確認の領域)。逆に言えば、次の構造的な不変条件は
+ * すべてここで固定する:
+ *   - 先読み済み要素をそのまま本再生へ引き継ぐ (同じ動画を 2 回取得しない)
+ *   - missing を挟む並びでも次の clip に必ず src が入る (再生不能を作らない)
+ *   - 世代 / 割り当て世代により、旧要素・teardown 後の遅延イベントが状態を変えない
+ *   - programmatic pause と利用者 pause を slot 単位で区別する
+ *   - 1 本の失敗で通し再生が止まらない (停滞監視が有限時間で回収する)
+ */
+
+const TARGET = { projectId: 1, manualId: 5 };
+
+function cut(id: number, readyTakeId: number | null): CaptureCut {
+    return {
+        id,
+        type: "step",
+        parent_cut_id: null,
+        scene: `scene-${id}`,
+        shot_type: "hiki",
+        shooting_point: null,
+        narration: "",
+        subtitle_primary: null,
+        subtitle_secondary: `字幕 ${id}`,
+        adopted_take_id: readyTakeId,
+        adopted_ready_take_id: readyTakeId,
+        takes: [],
+    };
+}
+
+const LABELS: Record<number, string> = { 101: "手順 1", 102: "手順 2", 103: "手順 3" };
+
+function playbackUrl(cutId: number, takeId: number): string {
+    return `/app/projects/1/manuals/5/cuts/${cutId}/takes/${takeId}/playback`;
+}
+
+function renderDialog(cuts: CaptureCut[], onClose = vi.fn()): { onClose: ReturnType<typeof vi.fn> } {
+    render(ScenarioPreviewDialog, {
+        open: true,
+        projectId: TARGET.projectId,
+        manualId: TARGET.manualId,
+        cuts,
+        labels: LABELS,
+        placeholderSeconds: 3,
+        onClose,
+    });
+
+    return { onClose };
+}
+
+function video(slot: 0 | 1): HTMLVideoElement {
+    return screen.getByTestId(`scenario-preview-video-${slot}`) as HTMLVideoElement;
+}
+
+function body(): HTMLElement {
+    return screen.getByTestId("scenario-preview-body");
+}
+
+/** 要素が「再生中」であるかのように見せる (jsdom の paused は常に true のため) */
+function markPlaying(element: HTMLVideoElement): void {
+    Object.defineProperty(element, "paused", { value: false, configurable: true });
+}
+
+let playMock: ReturnType<typeof vi.fn>;
+
+beforeEach(() => {
+    playMock = vi.fn().mockResolvedValue(undefined);
+    vi.spyOn(HTMLMediaElement.prototype, "play").mockImplementation(
+        playMock as unknown as () => Promise<void>,
+    );
+    vi.spyOn(HTMLMediaElement.prototype, "pause").mockImplementation(() => undefined);
+    vi.spyOn(HTMLMediaElement.prototype, "load").mockImplementation(() => undefined);
+});
+
+afterEach(() => {
+    cleanup();
+    vi.restoreAllMocks();
+    vi.useRealTimers();
+});
+
+describe("ScenarioPreviewDialog: 起動と告知", () => {
+    it("開くと先頭 entry の src が active 要素に入る", () => {
+        renderDialog([cut(101, 900), cut(102, 901)]);
+
+        expect(video(0)).toHaveAttribute("src", playbackUrl(101, 900));
+        expect(body()).toHaveAttribute("data-active-slot", "0");
+    });
+
+    it("使用できる採用テイクが無いカットがあると事前告知を出す (ボタンは止めない)", () => {
+        renderDialog([cut(101, 900), cut(102, null)]);
+
+        expect(screen.getByTestId("scenario-preview-coverage-note")).toHaveTextContent(
+            "1 / 2 件のカットに、撮影・処理が完了した採用テイクがありません",
+        );
+        expect(screen.getByTestId("scenario-preview-close")).not.toBeDisabled();
+    });
+
+    it("欠落が無ければ事前告知は出ない", () => {
+        renderDialog([cut(101, 900)]);
+
+        expect(screen.queryByTestId("scenario-preview-coverage-note")).not.toBeInTheDocument();
+    });
+
+    it("missing entry ではプレースホルダ文言を出し video に src を入れない", () => {
+        renderDialog([cut(101, null), cut(102, 901)]);
+
+        expect(screen.getByTestId("scenario-preview-placeholder")).toHaveTextContent(
+            "手順 1: 撮影・処理が完了した採用テイクがありません",
+        );
+        expect(video(0)).not.toHaveAttribute("src");
+        expect(video(1)).not.toHaveAttribute("src");
+    });
+
+    it("字幕は初期 ON で、トグルで隠せる", async () => {
+        renderDialog([cut(101, 900)]);
+
+        expect(screen.getByTestId("scenario-preview-subtitle-secondary")).toHaveTextContent("字幕 101");
+
+        await fireEvent.click(screen.getByTestId("scenario-preview-subtitle-toggle"));
+
+        expect(screen.queryByTestId("scenario-preview-subtitle-secondary")).not.toBeInTheDocument();
+    });
+});
+
+describe("ScenarioPreviewDialog: 先読みと役割の入れ替え", () => {
+    it("再生に入ると次のクリップが inactive 側へ先読みされる", async () => {
+        renderDialog([cut(101, 900), cut(102, 901)]);
+
+        await fireEvent(video(0), new Event("playing"));
+
+        expect(video(1)).toHaveAttribute("src", playbackUrl(102, 901));
+    });
+
+    it("進むと役割が入れ替わり、先読み済み要素は作り直されない (二重取得を作らない)", async () => {
+        renderDialog([cut(101, 900), cut(102, 901)]);
+        await fireEvent(video(0), new Event("playing"));
+
+        const assignmentBefore = video(1).getAttribute("data-assignment");
+
+        await fireEvent(video(0), new Event("ended"));
+
+        expect(body()).toHaveAttribute("data-active-slot", "1");
+        expect(body()).toHaveAttribute("data-index", "1");
+        expect(video(1)).toHaveAttribute("src", playbackUrl(102, 901));
+        expect(video(1).getAttribute("data-assignment")).toBe(assignmentBefore);
+    });
+
+    it("次が missing なら先読みせず inactive 側を空のままにする", async () => {
+        renderDialog([cut(101, 900), cut(102, null)]);
+
+        await fireEvent(video(0), new Event("playing"));
+
+        expect(video(1)).not.toHaveAttribute("src");
+    });
+});
+
+describe("ScenarioPreviewDialog: 進んだ先の同期 (再生不能を作らない)", () => {
+    it("missing → clip で次の clip に src が入る", async () => {
+        vi.useFakeTimers();
+        renderDialog([cut(101, null), cut(102, 901)]);
+
+        await vi.advanceTimersByTimeAsync(4_000);
+
+        expect(body()).toHaveAttribute("data-index", "1");
+        expect(video(1)).toHaveAttribute("src", playbackUrl(102, 901));
+    });
+
+    it("clip → missing → clip で最後の clip に src が入る", async () => {
+        vi.useFakeTimers();
+        renderDialog([cut(101, 900), cut(102, null), cut(103, 902)]);
+
+        await fireEvent(video(0), new Event("ended"));
+        expect(body()).toHaveAttribute("data-index", "1");
+
+        await vi.advanceTimersByTimeAsync(4_000);
+
+        expect(body()).toHaveAttribute("data-index", "2");
+        const activeSlot = body().getAttribute("data-active-slot");
+        expect(video(activeSlot === "0" ? 0 : 1)).toHaveAttribute("src", playbackUrl(103, 902));
+    });
+
+    it("missing → missing → clip で最後の clip に src が入る", async () => {
+        vi.useFakeTimers();
+        renderDialog([cut(101, null), cut(102, null), cut(103, 902)]);
+
+        await vi.advanceTimersByTimeAsync(4_000);
+        await vi.advanceTimersByTimeAsync(4_000);
+
+        expect(body()).toHaveAttribute("data-index", "2");
+        const activeSlot = body().getAttribute("data-active-slot");
+        expect(video(activeSlot === "0" ? 0 : 1)).toHaveAttribute("src", playbackUrl(103, 902));
+    });
+});
+
+describe("ScenarioPreviewDialog: 自動再生制限 (blocked)", () => {
+    it("NotAllowedError の拒否で blocked 表示になり 3 つの出口が出る", async () => {
+        playMock.mockRejectedValue(new DOMException("blocked", "NotAllowedError"));
+        renderDialog([cut(101, 900), cut(102, 901)]);
+
+        await vi.waitFor(() => {
+            expect(screen.getByTestId("scenario-preview-blocked")).toBeInTheDocument();
+        });
+        expect(screen.getByTestId("scenario-preview-retry")).toBeInTheDocument();
+        expect(screen.getByTestId("scenario-preview-skip")).toBeInTheDocument();
+        expect(screen.getByTestId("scenario-preview-close")).toBeInTheDocument();
+        expect(body()).toHaveAttribute("data-clip", "blocked");
+    });
+
+    it("blocked からスキップで次のカットへ進める", async () => {
+        playMock.mockRejectedValue(new DOMException("blocked", "NotAllowedError"));
+        renderDialog([cut(101, 900), cut(102, 901)]);
+        await vi.waitFor(() => {
+            expect(screen.getByTestId("scenario-preview-skip")).toBeInTheDocument();
+        });
+
+        await fireEvent.click(screen.getByTestId("scenario-preview-skip"));
+
+        expect(body()).toHaveAttribute("data-index", "1");
+    });
+
+    it("拒否後もダイアログを閉じられる (未処理 rejection を残さない)", async () => {
+        playMock.mockRejectedValue(new DOMException("blocked", "NotAllowedError"));
+        const { onClose } = renderDialog([cut(101, 900)]);
+        await vi.waitFor(() => {
+            expect(screen.getByTestId("scenario-preview-blocked")).toBeInTheDocument();
+        });
+
+        await fireEvent.click(screen.getByTestId("scenario-preview-close"));
+
+        expect(onClose).toHaveBeenCalledTimes(1);
+    });
+
+    it("閉じて開き直した後に届く旧セッションの拒否は新セッションを blocked にしない (Codex R1-Critical)", async () => {
+        // 1 本目の play() は保留したままにし、閉じた後に拒否させる
+        const pending: { reject?: (reason: unknown) => void } = {};
+        playMock.mockImplementationOnce(
+            () =>
+                new Promise<void>((_resolve, reject) => {
+                    pending.reject = reject;
+                }),
+        );
+        const onClose = vi.fn();
+        const { unmount } = render(ScenarioPreviewDialog, {
+            open: true,
+            projectId: TARGET.projectId,
+            manualId: TARGET.manualId,
+            cuts: [cut(101, 900)],
+            labels: LABELS,
+            placeholderSeconds: 3,
+            onClose,
+        });
+        await vi.waitFor(() => {
+            expect(pending.reject).toBeTypeOf("function");
+        });
+
+        await fireEvent.click(screen.getByTestId("scenario-preview-close"));
+        unmount();
+
+        // 開き直し (新しいセッション。世代は再び 0 から始まる)
+        renderDialog([cut(101, 900)]);
+        pending.reject?.(new DOMException("blocked", "NotAllowedError"));
+        await Promise.resolve();
+
+        expect(screen.queryByTestId("scenario-preview-blocked")).not.toBeInTheDocument();
+        expect(body()).toHaveAttribute("data-clip", "loading");
+    });
+
+    it("同一インスタンスの close → reopen をまたぐ拒否も新セッションへ混入しない", async () => {
+        const pending: { reject?: (reason: unknown) => void } = {};
+        playMock.mockImplementationOnce(
+            () =>
+                new Promise<void>((_resolve, reject) => {
+                    pending.reject = reject;
+                }),
+        );
+        const { rerender } = render(ScenarioPreviewDialog, {
+            open: true,
+            projectId: TARGET.projectId,
+            manualId: TARGET.manualId,
+            cuts: [cut(101, 900)],
+            labels: LABELS,
+            placeholderSeconds: 3,
+            onClose: vi.fn(),
+        });
+        await vi.waitFor(() => {
+            expect(pending.reject).toBeTypeOf("function");
+        });
+
+        await rerender({ open: false });
+        await rerender({ open: true });
+
+        pending.reject?.(new DOMException("blocked", "NotAllowedError"));
+        await Promise.resolve();
+
+        expect(screen.queryByTestId("scenario-preview-blocked")).not.toBeInTheDocument();
+        expect(body()).toHaveAttribute("data-clip", "loading");
+    });
+
+    it("もう一度再生の後に届く旧セッションの拒否も混入しない", async () => {
+        const pending: { reject?: (reason: unknown) => void } = {};
+        playMock.mockImplementationOnce(
+            () =>
+                new Promise<void>((_resolve, reject) => {
+                    pending.reject = reject;
+                }),
+        );
+        renderDialog([cut(101, 900)]);
+        await vi.waitFor(() => {
+            expect(pending.reject).toBeTypeOf("function");
+        });
+
+        await fireEvent(video(0), new Event("ended")); // 終端まで再生
+        await fireEvent.click(screen.getByTestId("scenario-preview-replay"));
+
+        pending.reject?.(new DOMException("blocked", "NotAllowedError"));
+        await Promise.resolve();
+
+        expect(screen.queryByTestId("scenario-preview-blocked")).not.toBeInTheDocument();
+        expect(body()).toHaveAttribute("data-index", "0");
+        expect(body()).toHaveAttribute("data-clip", "loading");
+    });
+
+    it("tick 待ちの間に前進した古い再生要求は、新しいクリップを再生しない (Codex R2-Critical)", async () => {
+        const played: HTMLVideoElement[] = [];
+        vi.spyOn(HTMLMediaElement.prototype, "play").mockImplementation(function (
+            this: HTMLVideoElement,
+        ) {
+            played.push(this);
+
+            return Promise.resolve();
+        });
+
+        // render は同期で startPreview まで進み、先頭クリップの playActive() は
+        // await tick() で保留になる。その保留中に前のクリップが終端まで進む状況を作る。
+        renderDialog([cut(101, 900), cut(102, 901)]);
+        video(0).dispatchEvent(new Event("ended"));
+
+        await vi.waitFor(() => {
+            expect(played.length).toBeGreaterThan(0);
+        });
+        await Promise.resolve();
+
+        // 再生要求は「進んだ先のクリップに対して 1 回だけ」であること
+        // (古い呼び出しが activeSlot / 世代を読み直すと 2 回になる = 二重取得と誤 blocked の温床)
+        expect(played).toEqual([video(1)]);
+        expect(body()).toHaveAttribute("data-index", "1");
+    });
+
+    it("NotAllowedError 以外の拒否は即 failed にせず、停滞監視が回収する", async () => {
+        vi.useFakeTimers();
+        playMock.mockRejectedValue(new Error("decode failure"));
+        renderDialog([cut(101, 900), cut(102, 901)]);
+
+        await vi.advanceTimersByTimeAsync(1_000);
+        expect(body()).toHaveAttribute("data-clip", "loading");
+
+        await vi.advanceTimersByTimeAsync(20_000);
+        expect(body()).toHaveAttribute("data-clip", "failed");
+        expect(screen.getByTestId("scenario-preview-failed")).toHaveTextContent(
+            "手順 1: このカットは再生できませんでした",
+        );
+
+        await vi.advanceTimersByTimeAsync(4_000);
+        expect(body()).toHaveAttribute("data-index", "1");
+    });
+});
+
+describe("ScenarioPreviewDialog: 失敗表示の回収", () => {
+    it("failed 中に progress が届き続けても placeholderSeconds で次へ進む", async () => {
+        vi.useFakeTimers();
+        renderDialog([cut(101, 900), cut(102, 901)]);
+
+        await fireEvent(video(0), new Event("error"));
+        expect(body()).toHaveAttribute("data-clip", "failed");
+
+        // 失敗したクリップの要素がバッファリングを続けて progress を出し続ける状況
+        for (let elapsed = 0; elapsed < 3; elapsed += 1) {
+            await fireEvent(video(0), new Event("timeupdate"));
+            await vi.advanceTimersByTimeAsync(1_000);
+        }
+
+        expect(body()).toHaveAttribute("data-index", "1");
+    });
+});
+
+describe("ScenarioPreviewDialog: pause の抑止", () => {
+    it("利用者操作の pause は paused になる", async () => {
+        renderDialog([cut(101, 900)]);
+
+        await fireEvent(video(0), new Event("pause"));
+
+        expect(body()).toHaveAttribute("data-clip", "paused");
+    });
+
+    it("自分から止めた pause は paused を作らない (非表示での programmatic pause)", async () => {
+        renderDialog([cut(101, 900)]);
+        await fireEvent(video(0), new Event("playing"));
+        markPlaying(video(0));
+
+        // 非表示 → component が自分から pause() する (抑止が立つ)
+        Object.defineProperty(document, "visibilityState", { value: "hidden", configurable: true });
+        await fireEvent(document, new Event("visibilitychange"));
+        await fireEvent(video(0), new Event("pause"));
+
+        expect(body()).toHaveAttribute("data-clip", "playing");
+
+        Object.defineProperty(document, "visibilityState", { value: "visible", configurable: true });
+    });
+
+    it("抑止は slot 別である (片方を止めても他方の利用者 pause は効く)", async () => {
+        renderDialog([cut(101, 900), cut(102, 901)]);
+        await fireEvent(video(0), new Event("playing")); // slot1 へ先読み
+        markPlaying(video(0));
+
+        Object.defineProperty(document, "visibilityState", { value: "hidden", configurable: true });
+        await fireEvent(document, new Event("visibilitychange"));
+        Object.defineProperty(document, "visibilityState", { value: "visible", configurable: true });
+        await fireEvent(document, new Event("visibilitychange"));
+
+        // slot0 の抑止が立っている状態で slot1 (先読み側) から pause が来ても握り潰さない
+        await fireEvent(video(1), new Event("pause"));
+
+        // slot1 の世代は先読み世代 (現在世代 + 1) なので reducer が捨てる = 状態は変わらない
+        expect(body()).toHaveAttribute("data-clip", "playing");
+
+        // slot0 の抑止は残っているので、こちらの pause は 1 度だけ握り潰される
+        await fireEvent(video(0), new Event("pause"));
+        expect(body()).toHaveAttribute("data-clip", "playing");
+        // 抑止は消費済み。次の pause は利用者操作として通る
+        await fireEvent(video(0), new Event("pause"));
+        expect(body()).toHaveAttribute("data-clip", "paused");
+    });
+
+    it("既に paused の要素には抑止を立てない (後の利用者 pause を握り潰さない)", async () => {
+        renderDialog([cut(101, 900)]);
+        // jsdom の既定 paused=true のまま非表示にする (pause() は呼ばれない = 抑止も立たない)
+        Object.defineProperty(document, "visibilityState", { value: "hidden", configurable: true });
+        await fireEvent(document, new Event("visibilitychange"));
+        Object.defineProperty(document, "visibilityState", { value: "visible", configurable: true });
+        await fireEvent(document, new Event("visibilitychange"));
+
+        await fireEvent(video(0), new Event("pause"));
+
+        expect(body()).toHaveAttribute("data-clip", "paused");
+    });
+});
+
+describe("ScenarioPreviewDialog: 遅延イベントの遮断", () => {
+    it("旧 slot の遅延 error / ended が進んだ後のクリップを壊さない", async () => {
+        renderDialog([cut(101, 900), cut(102, 901)]);
+        await fireEvent(video(0), new Event("playing"));
+        await fireEvent(video(0), new Event("ended"));
+
+        expect(body()).toHaveAttribute("data-index", "1");
+
+        await fireEvent(video(0), new Event("error"));
+        await fireEvent(video(0), new Event("ended"));
+
+        expect(body()).toHaveAttribute("data-index", "1");
+        expect(body()).toHaveAttribute("data-clip", "loading");
+    });
+
+    it("同一 slot を作り直した後、旧要素からのイベントは届かない", async () => {
+        vi.useFakeTimers();
+        renderDialog([cut(101, 900), cut(102, null), cut(103, 902)]);
+        const firstElement = video(0);
+
+        await fireEvent(video(0), new Event("ended")); // → missing (slot1 が active)
+        await vi.advanceTimersByTimeAsync(4_000); // → clip3 (slot0 を作り直して active)
+
+        expect(body()).toHaveAttribute("data-index", "2");
+        expect(video(0)).not.toBe(firstElement); // 要素ごと作り直されている
+
+        await fireEvent(firstElement, new Event("ended"));
+        await fireEvent(firstElement, new Event("error"));
+
+        expect(body()).toHaveAttribute("data-index", "2");
+        expect(body()).toHaveAttribute("data-clip", "loading");
+    });
+
+    it("非表示中は ended が起きても次へ進まない", async () => {
+        renderDialog([cut(101, 900), cut(102, 901)]);
+        Object.defineProperty(document, "visibilityState", { value: "hidden", configurable: true });
+        await fireEvent(document, new Event("visibilitychange"));
+
+        await fireEvent(video(0), new Event("ended"));
+
+        expect(body()).toHaveAttribute("data-index", "0");
+
+        Object.defineProperty(document, "visibilityState", { value: "visible", configurable: true });
+    });
+});
+
+describe("ScenarioPreviewDialog: 終端と後始末", () => {
+    it("最終 entry の ended で終端表示になり、もう一度再生できる", async () => {
+        renderDialog([cut(101, 900)]);
+
+        await fireEvent(video(0), new Event("ended"));
+
+        expect(screen.getByTestId("scenario-preview-finished")).toHaveTextContent(
+            "すべてのカットを再生しました。",
+        );
+
+        await fireEvent.click(screen.getByTestId("scenario-preview-replay"));
+
+        expect(body()).toHaveAttribute("data-index", "0");
+        expect(video(0)).toHaveAttribute("src", playbackUrl(101, 900));
+    });
+
+    it("終端では両方の要素が teardown され、時間駆動も止まる", async () => {
+        vi.useFakeTimers();
+        renderDialog([cut(101, 900)]);
+
+        await fireEvent(video(0), new Event("ended"));
+
+        expect(video(0)).not.toHaveAttribute("src");
+        expect(video(1)).not.toHaveAttribute("src");
+
+        // 終端後に時間が進んでも状態は動かない (interval を破棄している)
+        await vi.advanceTimersByTimeAsync(60_000);
+        expect(screen.getByTestId("scenario-preview-finished")).toBeInTheDocument();
+    });
+
+    it("閉じると両方の要素を teardown し onClose を 1 度だけ呼ぶ", async () => {
+        const { onClose } = renderDialog([cut(101, 900), cut(102, 901)]);
+        await fireEvent(video(0), new Event("playing"));
+        expect(video(1)).toHaveAttribute("src", playbackUrl(102, 901));
+
+        await fireEvent.click(screen.getByTestId("scenario-preview-close"));
+
+        expect(onClose).toHaveBeenCalledTimes(1);
+    });
+
+    it("teardown 後に届いた遅延イベントは状態を変えない", async () => {
+        vi.useFakeTimers();
+        renderDialog([cut(101, 900), cut(102, 901)]);
+        const active = video(0);
+
+        await fireEvent(active, new Event("ended")); // slot0 teardown → slot1 が active
+        const indexAfterAdvance = body().getAttribute("data-index");
+
+        await fireEvent(active, new Event("pause"));
+        await fireEvent(active, new Event("error"));
+        await fireEvent(active, new Event("ended"));
+
+        expect(body().getAttribute("data-index")).toBe(indexAfterAdvance);
+        expect(body()).toHaveAttribute("data-clip", "loading");
+    });
+});
```

## 再検証結果 (修正後)

- composer phpstan (level 10): No errors / vendor/bin/pint --test: passed
- pnpm lint: passed / pnpm typecheck: passed
- pnpm test: 153 files / 1889 passed (Round 2 時点 1886 + 追加 3)
- pnpm build: 成功
- **fail-first の実測**: `playActive()` を修正前の形へ戻すと、新規テスト
  「tick 待ちの間に前進した古い再生要求は、新しいクリップを再生しない」だけが
  `played` 2 件で赤くなることを確認した (実装の追認になっていないことの直接確認)。

## 確認してほしい点

1. `isCurrentTarget()` の 4 点照合 (session / slot / generation / assignment) で、
   遅延した再生要求と拒否の混入経路が閉じたか。閉じ残りがあれば具体系列で指摘してほしい。
2. 追加した 3 本のテストが、それぞれ実装のどのガードを外すと赤くなるかが明確か。
