# Round 2: Round 1 指摘への対応

Round 1 の [Critical] 2 件・[Warning] 2 件・[Suggestion] 1 件すべてに対応した。
対応方針と根拠は以下のとおりである。**再レビューして全体判定を出してほしい**。

## 対応マトリクス

# 対応マトリクス: impl-review Round 1

## [Critical] close/reopen または replay をまたいだ古い `play()` rejection が新しい再生セッションへ混入する

- 判断: **対応する**
- 根拠: 指摘のとおりである。`startPreview()` は毎回 `generation: 0` から引き直すため、
  世代だけでは「閉じて開き直した直後」を識別できない。`play()` の Promise は
  Modal の unmount を越えて生き残るので、旧セッションの `NotAllowedError` が
  新セッションを `blocked` にできる (component テストが空白だった経路)。
- 対応内容:
  - `ScenarioPreviewDialog.svelte` に**単調増加する `sessionId`** を追加した。
    `startPreview()` と `stopPreview()` の両方で +1 する (開始側だけだと、
    閉じたまま到着した結果が次の open を待って適用されうる)。
  - `playActive()` は呼び出し時点の `sessionId` を closure へ退避し、
    (a) `await tick()` の後 (= 待っている間に閉じた / 開き直した場合の再生そのものを止める)、
    (b) `catch` の中 (= 遅延 rejection の受理) の**両方**で照合する。
    世代の照合は従来どおり併用する (同一セッション内の入れ替えを守るのはこちら)。
  - テスト追加 (`ScenarioPreviewDialog.test.ts`):
    「閉じて開き直した後に届く旧セッションの拒否は新セッションを blocked にしない」。
    1 本目の `play()` を保留したまま閉じ、unmount → 再 render の後に reject させる系列で固定した。

## [Critical] `failed` が表示待ちとして terminal になっておらず、同一世代の `progress` / `playing` で延命・復帰できる

- 判断: **対応する**
- 根拠: 指摘のとおりである。停滞で `failed` にしたクリップの要素は、バッファリングが進めば
  `progress` を出し続けうる。`progressAt` が更新され続けると `placeholderSeconds` の
  満了判定が永久に成立せず、**「有限時間で必ず次へ進む」という本設計の中心契約が破れる**
  (停滞監視が回収装置として空転する)。`playing` による復帰も、一度失敗と告知した区間を
  無言で再生し直すことになり告知と実挙動が食い違う。
- 対応内容:
  - `scenario-preview.ts` の `reducePreview` に、非表示ガードと同じ位置で
    **「`failed` / `placeholder` の間はメディア由来イベントを受け付けない」**ガードを足した
    (`isWaitingState()`)。利用者操作 (`skip` / `retry`) と可視性と時間は従来どおり処理する。
  - テスト追加 (lib): 「failed 中の progress / playing は待ちを延ばさない・復帰させない」
    「placeholder 中のメディア由来イベントも待ちを延ばさない」。どちらも
    **尺の満了で次へ進むこと**まで固定した (ガードが前進を止めていないことの確認)。
  - テスト追加 (component): 「failed 中に progress が届き続けても placeholderSeconds で次へ進む」
    (実際に 1 秒ごとに `timeupdate` を注入する系列で配線ごと固定)。

## [Warning] close/reopen 後の遅延 `play()` rejection を固定するテストが無い

- 判断: **対応する** (上の Critical 1 の対応内容に含む)
- 根拠: 指摘のとおり、既存の「拒否後も閉じられる」は即時 rejection しか通らず、
  世代 0 の再利用を検出できない。
- 対応内容: 上記の新規テストが、保留中の Promise を閉じた後に reject させる形で検出する。

## [Warning] `failed` 後の `progress` / `playing` で待ちが延びないことのテストが無い

- 判断: **対応する** (上の Critical 2 の対応内容に含む)
- 根拠: 同上。既存テストは `failed → tick → advance` の素直な系列だけを見ていた。
- 対応内容: lib 2 本 + component 1 本を追加した。

## [Suggestion] 録画中エラー文言のテストが末尾の共通節だけを見ている

- 判断: **対応する**
- 根拠: 「同じ制約を同じ言葉で説明する」ことが意図なので、共通節の一致だけでは
  通し再生側の全文が変わっても赤くならない。ただし 2 つの文言は前半 (何ができないか) が
  意図的に異なるため**全文の完全一致は不可能**であり、共通定数化は 1 文字列のために
  実装間の依存を増やすので採らない。
- 対応内容: 通し再生側の**全文を完全一致で固定**し、加えて共通節で終わることを固定した。
  個別 preview 側の文言は `TakeStrip.test.ts` が固定しており、どちらかが分岐したら
  いずれかのテストが赤くなる。

## 修正後の差分 (該当ファイルのみ。Round 1 提示分からの累積差分)

```diff
diff --git a/resources/js/components/features/capture/ScenarioPreviewDialog.svelte b/resources/js/components/features/capture/ScenarioPreviewDialog.svelte
new file mode 100644
index 0000000..3d70b8d
--- /dev/null
+++ b/resources/js/components/features/capture/ScenarioPreviewDialog.svelte
@@ -0,0 +1,534 @@
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
+     * active slot の再生を試みる。**呼び出し時点の世代を closure へ退避してから** play() する
+     * (catch の中で台帳を読み直すと、要素再生成後の新しい世代を読みうる)。
+     */
+    async function playActive(): Promise<void> {
+        const session = sessionId;
+        await tick(); // src の反映 / 要素の再生成を待ってから再生する
+        if (session !== sessionId) return; // 待っている間に閉じた / 開き直した
+        const slot = activeSlot;
+        const generation = slotGeneration[slot];
+        const video = elementFor(slot);
+        if (video === null || generation === null) return;
+
+        const started = video.play() as Promise<void> | undefined;
+        if (started === undefined) return; // Promise を返さない実装 (古い WebKit / jsdom)
+
+        started.catch((error: unknown) => {
+            if (session !== sessionId) return; // 別セッションの遅延結果は 1 ビットも反映しない
+            if (generation !== previewState.generation) return;
+            // **自動再生制限と判定できる拒否だけ** blocked にする。
+            // それ以外は何も送らない (失敗の確定は error と停滞監視に委ねる)。
+            if (error instanceof DOMException && error.name === "NotAllowedError") {
+                dispatch({ type: "blocked", generation, at: Date.now() });
+            }
+        });
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
diff --git a/resources/js/lib/capture/scenario-preview.ts b/resources/js/lib/capture/scenario-preview.ts
new file mode 100644
index 0000000..cde3487
--- /dev/null
+++ b/resources/js/lib/capture/scenario-preview.ts
@@ -0,0 +1,291 @@
+import { takeUrl } from "@/lib/capture/take-endpoints";
+import type { CaptureCut } from "@/types/capture";
+
+/**
+ * 撮影 PWA の通し再生 (全体連結プレビュー) の再生リストと状態機械。
+ *
+ * 方式の決定 (端末側連結再生 / サーバ生成プレビューを撮影者に開かない) と、
+ * ここで固定する契約の根拠は devnotes/20260816-1754-capture-full-scenario-preview/。
+ *
+ * **この面は素材の選択判定を持たない**。どのテイクを再生するかは
+ * サーバの `AdoptedReadyTakeCoverage` が決め、`cut.adopted_ready_take_id` として渡ってくる
+ * (adopted_take_id と take.status からここで組み立て直さない = T148 の二重化を作らない)。
+ *
+ * 判断はここ (純関数)、配線とメディア要素の操作は component
+ * (landscape-capture.ts / panel-navigation.ts と同じ役割分担)。
+ */
+
+/** 再生リストの 1 件 (クリップ = 再生する / 欠落 = プレースホルダを出す) */
+export type PreviewEntry =
+    | {
+          kind: "clip";
+          cutId: number;
+          takeId: number;
+          label: string;
+          subtitlePrimary: string | null;
+          subtitleSecondary: string;
+          /** capture.takes.playback の URL (takeUrl が唯一の導出元) */
+          src: string;
+      }
+    | {
+          kind: "missing";
+          cutId: number;
+          label: string;
+          subtitlePrimary: string | null;
+          subtitleSecondary: string;
+      };
+
+/** 再生状態 (可視性とは**直交**する) */
+export type ClipState = "loading" | "playing" | "paused" | "blocked" | "failed" | "placeholder";
+
+export interface PreviewState {
+    /** 再生リスト内の位置 (0 起点)。entries.length に達したら finished */
+    index: number;
+    /** 非同期結果の受付世代。index の前進・スキップ・終了のたびに +1 する */
+    generation: number;
+    clip: ClipState;
+    /** ページが表示されているか (可視性の軸) */
+    visible: boolean;
+    /** 直近に「進捗があった」時刻 (ms)。停滞判定の起点 */
+    progressAt: number;
+    /** 全カットを見終わったか */
+    finished: boolean;
+}
+
+export interface PreviewEvent {
+    type:
+        | "progress" // timeupdate / progress / canplay 等の前進イベント
+        | "playing"
+        | "paused" // 利用者の一時停止
+        | "resumed" // 利用者の再生
+        | "ended"
+        | "error" // media error / 404
+        | "blocked" // 自動再生制限と判定できる play() 拒否
+        | "retry" // 「再生を続ける」
+        | "skip" // 「このカットをスキップ」
+        | "hidden"
+        | "shown"
+        | "tick"; // 時間経過の通知 (停滞監視・プレースホルダ尺)
+    /** 発生元の世代。省略時は現在世代とみなす (利用者操作など同期的なもの) */
+    generation?: number;
+    /** イベント時刻 (ms) */
+    at: number;
+}
+
+export interface PreviewOptions {
+    entries: PreviewEntry[];
+    /** プレースホルダの表示秒数 (サーバの preview_placeholder_seconds と同じ値) */
+    placeholderSeconds: number;
+    /** 停滞と判定するまでの無進捗時間 (ms) */
+    stallTimeoutMs?: number;
+}
+
+/**
+ * 停滞判定の既定閾値。
+ *
+ * **この値が「正しい」ことは主張しない**。固定するのは「監視条件を満たす限り有限時間で
+ * 必ず次へ進む」ことだけで、閾値そのものは実地の観測が出るまで動かさない
+ * (仕組みが機能していない段階で値を弄らない)。現場のモバイル回線で先頭バッファに
+ * 時間がかかることを想定して保守的に置く。
+ */
+export const PREVIEW_STALL_TIMEOUT_MS = 20_000;
+
+/**
+ * 再生リストを組み立てる。並び順は props の cuts の順 (= サーバの表示順: 手順 → 配下の急所) をそのまま使う。
+ * ラベルは buildCutLabels の結果を受け取る (規則をここで再実装しない)。
+ */
+export function buildPreviewEntries(
+    cuts: CaptureCut[],
+    labels: Record<number, string>,
+    target: { projectId: number; manualId: number },
+): PreviewEntry[] {
+    return cuts.map((cut): PreviewEntry => {
+        const label = labels[cut.id] ?? "カット";
+        const takeId = cut.adopted_ready_take_id;
+        if (takeId === null) {
+            return {
+                kind: "missing",
+                cutId: cut.id,
+                label,
+                subtitlePrimary: cut.subtitle_primary,
+                subtitleSecondary: cut.subtitle_secondary,
+            };
+        }
+
+        return {
+            kind: "clip",
+            cutId: cut.id,
+            takeId,
+            label,
+            subtitlePrimary: cut.subtitle_primary,
+            subtitleSecondary: cut.subtitle_secondary,
+            src: takeUrl(
+                { projectId: target.projectId, manualId: target.manualId, cutId: cut.id },
+                takeId,
+                "/playback",
+            ),
+        };
+    });
+}
+
+/** 使用できる採用テイクが無いカットの件数 (再生前の告知に使う。述語は持たない = null を数えるだけ) */
+export function missingCount(entries: PreviewEntry[]): number {
+    return entries.filter((entry) => entry.kind === "missing").length;
+}
+
+/**
+ * 初期状態 (先頭 entry の種別で clip / placeholder が決まる)。
+ *
+ * **entries が空のときの `clip` は意味を持たない** — `finished: true` の状態では
+ * UI も reducer も `clip` を読まない (reducer は先頭で `finished` を見て素通しする)。
+ * 便宜上 `"placeholder"` を入れるが、**この値に依存する分岐を書かない**
+ * (この約束は Vitest の「空リストでは finished かつどのイベントでも状態が変わらない」で固定する)。
+ */
+export function initialPreviewState(options: PreviewOptions, at: number): PreviewState {
+    return {
+        index: 0,
+        generation: 0,
+        clip: stateForEntry(options.entries[0]),
+        visible: true,
+        progressAt: at,
+        finished: options.entries.length === 0,
+    };
+}
+
+/**
+ * 停滞監視を動かす条件。
+ * **可視性 × 再生要求 × 状態**の 3 つが揃ったときだけ監視する
+ * (一時停止・非表示・blocked・failed の間は監視しない = 誤って次へ進めない)。
+ */
+export function shouldWatchStall(state: PreviewState): boolean {
+    return state.visible && !state.finished && (state.clip === "loading" || state.clip === "playing");
+}
+
+/**
+ * 状態遷移。**現在世代と一致しない非同期結果は 1 ビットも状態を変えない**
+ * (要素の入れ替えで生じる古い reject / error を誤って現在のクリップの失敗にしない)。
+ */
+export function reducePreview(
+    state: PreviewState,
+    event: PreviewEvent,
+    options: PreviewOptions,
+): PreviewState {
+    if (state.finished) return state;
+    if (event.generation !== undefined && event.generation !== state.generation) return state;
+    // **非表示中はメディア由来のイベントを受け付けない**。実メディアを pause() しても、
+    // 既にキューへ入った ended / error は到着しうるため、実要素の操作だけに依存しない
+    // (非表示の間に勝手に次のカットへ進むのを構造で止める)。
+    // 利用者操作 (skip / retry) と可視性 (hidden / shown) と時間 (tick) は常に処理する。
+    if (!state.visible && isMediaOriginEvent(event.type)) return state;
+    // **`failed` / `placeholder` は「見せてから次へ進むまでの待ち」であり、メディア由来の
+    // イベントで延命・復帰させない**。失敗したクリップの要素はバッファリングを続けて
+    // `progress` を出し続けうるため、受け付けると `progressAt` が更新され続けて
+    // 尺の満了判定が永久に成立しない (= 停滞回収が空転して次のカットへ進めなくなる)。
+    // 利用者操作 (skip / retry) と可視性と時間は引き続き処理する。
+    if (isWaitingState(state.clip) && isMediaOriginEvent(event.type)) return state;
+
+    switch (event.type) {
+        case "hidden":
+            return { ...state, visible: false };
+        case "shown":
+            // 再生状態は変えない (playing なら component が再開を試み、paused/blocked は維持)。
+            // 進捗の起点だけ引き直す (非表示だった時間を停滞に数えない)。
+            return { ...state, visible: true, progressAt: event.at };
+        case "progress":
+            return { ...state, progressAt: event.at };
+        case "playing":
+            return { ...state, clip: "playing", progressAt: event.at };
+        case "paused":
+            // **利用者操作由来の pause だけがここへ来る** (component が programmatic pause を送らない)。
+            // 読み込み中に利用者が止めることもあるため loading からも受け付ける
+            // (受け付けないと「止めたのに停滞監視が動き続けて failed になる」)。
+            return state.clip === "playing" || state.clip === "loading"
+                ? { ...state, clip: "paused" }
+                : state;
+        case "resumed":
+            return state.clip === "paused" ? { ...state, clip: "loading", progressAt: event.at } : state;
+        case "blocked":
+            return { ...state, clip: "blocked" };
+        case "retry":
+            // 「再生を続ける」= もう一度読み込みからやり直す (再拒否ならまた blocked になる)
+            return { ...state, clip: "loading", progressAt: event.at };
+        case "error":
+            return { ...state, clip: "failed", progressAt: event.at };
+        case "ended":
+        case "skip":
+            return advance(state, options, event.at);
+        case "tick":
+            return onTick(state, options, event.at);
+    }
+}
+
+/**
+ * 時間経過: プレースホルダの尺満了と停滞判定の 2 つだけを見る。
+ *
+ * `failed` の表示待ちにも `placeholderSeconds` を流用する (**欠落と同じ長さで通過させる**)。
+ * 別の設定値を新設しないのは、どちらも「見せてから次へ進むまでの待ち」であり、
+ * 2 つ持つと必ず食い違うためである (値の意味は「プレースホルダ表示秒数」のまま)。
+ */
+function onTick(state: PreviewState, options: PreviewOptions, at: number): PreviewState {
+    if (!state.visible) return state; // 非表示の間は尺も停滞も進めない
+    if (state.clip === "placeholder" || state.clip === "failed") {
+        return at - state.progressAt >= options.placeholderSeconds * 1000
+            ? advance(state, options, at)
+            : state;
+    }
+    if (!shouldWatchStall(state)) return state;
+    const timeout = options.stallTimeoutMs ?? PREVIEW_STALL_TIMEOUT_MS;
+
+    // 進捗が途切れたまま閾値を超えた → そのカットだけ失敗にする (通し再生は止めない)
+    return at - state.progressAt >= timeout ? { ...state, clip: "failed", progressAt: at } : state;
+}
+
+/** 次の entry へ。**世代を必ず +1 する** (破棄したクリップの遅延イベントを無効化する) */
+function advance(state: PreviewState, options: PreviewOptions, at: number): PreviewState {
+    const next = state.index + 1;
+    if (next >= options.entries.length) {
+        return {
+            ...state,
+            index: next,
+            generation: state.generation + 1,
+            finished: true,
+            progressAt: at,
+        };
+    }
+
+    return {
+        ...state,
+        index: next,
+        generation: state.generation + 1,
+        clip: stateForEntry(options.entries[next]),
+        progressAt: at,
+    };
+}
+
+function stateForEntry(entry: PreviewEntry | undefined): ClipState {
+    return entry?.kind === "clip" ? "loading" : "placeholder";
+}
+
+/**
+ * メディア要素が起点のイベント (非表示中は受け付けない側)。
+ * `Set<PreviewEvent["type"]>` が担保するのは**要素型の正当性**だけで、
+ * **必要なイベントの登録漏れは検出しない** (漏れは Vitest が拾う)。
+ */
+const MEDIA_ORIGIN_EVENTS = new Set<PreviewEvent["type"]>([
+    "progress",
+    "playing",
+    "paused",
+    "resumed",
+    "ended",
+    "error",
+    "blocked",
+]);
+
+function isMediaOriginEvent(type: PreviewEvent["type"]): boolean {
+    return MEDIA_ORIGIN_EVENTS.has(type);
+}
+
+/** 「見せてから次へ進むまでの待ち」の状態 (尺が満了したら必ず前進する側) */
+function isWaitingState(clip: ClipState): boolean {
+    return clip === "failed" || clip === "placeholder";
+}
diff --git a/tests/js/components/features/capture/ScenarioPreviewDialog.test.ts b/tests/js/components/features/capture/ScenarioPreviewDialog.test.ts
new file mode 100644
index 0000000..f97f077
--- /dev/null
+++ b/tests/js/components/features/capture/ScenarioPreviewDialog.test.ts
@@ -0,0 +1,475 @@
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
diff --git a/tests/js/lib/capture/scenario-preview.test.ts b/tests/js/lib/capture/scenario-preview.test.ts
new file mode 100644
index 0000000..92ae991
--- /dev/null
+++ b/tests/js/lib/capture/scenario-preview.test.ts
@@ -0,0 +1,416 @@
+/**
+ * Tests for resources/js/lib/capture/scenario-preview.ts (T191)
+ *
+ * 固定する契約:
+ * - 再生リストは「サーバが決めた adopted_ready_take_id」だけを見る (述語を持たない)
+ * - 表示中かつ再生要求中なら**有限時間で必ず次へ進む** (停滞監視による回収)
+ * - 一時停止 / 非表示 / blocked / failed の間は勝手に進まない
+ * - 世代が一致しない非同期結果は 1 ビットも状態を変えない
+ */
+import { describe, expect, it } from "vitest";
+
+import {
+    buildPreviewEntries,
+    initialPreviewState,
+    missingCount,
+    PREVIEW_STALL_TIMEOUT_MS,
+    reducePreview,
+    shouldWatchStall,
+    type PreviewEntry,
+    type PreviewEvent,
+    type PreviewOptions,
+    type PreviewState,
+} from "@/lib/capture/scenario-preview";
+import { takeUrl } from "@/lib/capture/take-endpoints";
+import type { CaptureCut } from "@/types/capture";
+
+const TARGET = { projectId: 1, manualId: 5 };
+
+function cut(id: number, readyTakeId: number | null, type: "step" | "point" = "step"): CaptureCut {
+    return {
+        id,
+        type,
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
+function clipEntry(cutId: number, takeId: number): PreviewEntry {
+    return {
+        kind: "clip",
+        cutId,
+        takeId,
+        label: `手順 ${cutId}`,
+        subtitlePrimary: null,
+        subtitleSecondary: "",
+        src: takeUrl({ ...TARGET, cutId }, takeId, "/playback"),
+    };
+}
+
+function missingEntry(cutId: number): PreviewEntry {
+    return {
+        kind: "missing",
+        cutId,
+        label: `手順 ${cutId}`,
+        subtitlePrimary: null,
+        subtitleSecondary: "",
+    };
+}
+
+function options(entries: PreviewEntry[], overrides: Partial<PreviewOptions> = {}): PreviewOptions {
+    return { entries, placeholderSeconds: 3, stallTimeoutMs: 1_000, ...overrides };
+}
+
+/** 連続適用のヘルパ (状態遷移の可読性のため) */
+function apply(state: PreviewState, opts: PreviewOptions, events: PreviewEvent[]): PreviewState {
+    return events.reduce((current, event) => reducePreview(current, event, opts), state);
+}
+
+describe("buildPreviewEntries", () => {
+    it("adopted_ready_take_id が非 null なら clip、null なら missing になる", () => {
+        const entries = buildPreviewEntries(
+            [cut(101, 900), cut(102, null)],
+            { 101: "手順 1", 102: "急所 1-1" },
+            TARGET,
+        );
+
+        expect(entries[0]).toMatchObject({ kind: "clip", cutId: 101, takeId: 900, label: "手順 1" });
+        expect(entries[1]).toMatchObject({ kind: "missing", cutId: 102, label: "急所 1-1" });
+    });
+
+    it("clip の src は takeUrl の /playback と一致する (URL 規則を再実装しない)", () => {
+        const entries = buildPreviewEntries([cut(101, 900)], { 101: "手順 1" }, TARGET);
+
+        expect(entries[0]).toHaveProperty(
+            "src",
+            takeUrl({ projectId: 1, manualId: 5, cutId: 101 }, 900, "/playback"),
+        );
+        expect(entries[0]).toHaveProperty("src", "/app/projects/1/manuals/5/cuts/101/takes/900/playback");
+    });
+
+    it("cuts の順序をそのまま保つ (手順 → 急所の並びを崩さない)", () => {
+        const entries = buildPreviewEntries(
+            [cut(1, 11), cut(2, null, "point"), cut(3, 33)],
+            { 1: "手順 1", 2: "急所 1-1", 3: "手順 2" },
+            TARGET,
+        );
+
+        expect(entries.map((entry) => entry.cutId)).toEqual([1, 2, 3]);
+    });
+
+    it("ラベルが無いカットは既定ラベルになる (buildCutLabels の結果をそのまま使う)", () => {
+        const entries = buildPreviewEntries([cut(1, 11)], {}, TARGET);
+
+        expect(entries[0]?.label).toBe("カット");
+    });
+
+    it("字幕は cut の値をそのまま運ぶ", () => {
+        const entries = buildPreviewEntries([cut(7, 70)], { 7: "手順 1" }, TARGET);
+
+        expect(entries[0]?.subtitleSecondary).toBe("字幕 7");
+        expect(entries[0]?.subtitlePrimary).toBeNull();
+    });
+});
+
+describe("missingCount", () => {
+    it("使用できる採用テイクが無いカットの件数を数える", () => {
+        expect(missingCount([clipEntry(1, 11), missingEntry(2), missingEntry(3)])).toBe(2);
+        expect(missingCount([clipEntry(1, 11)])).toBe(0);
+        expect(missingCount([])).toBe(0);
+    });
+});
+
+describe("initialPreviewState", () => {
+    it("先頭が clip なら loading、missing なら placeholder から始まる", () => {
+        expect(initialPreviewState(options([clipEntry(1, 11)]), 0).clip).toBe("loading");
+        expect(initialPreviewState(options([missingEntry(1)]), 0).clip).toBe("placeholder");
+    });
+
+    it("entries が空なら finished で始まる", () => {
+        expect(initialPreviewState(options([]), 0).finished).toBe(true);
+    });
+});
+
+describe("reducePreview: 停滞監視", () => {
+    it("loading のまま閾値を超える tick で failed になり、さらに tick で次へ進む", () => {
+        const opts = options([clipEntry(1, 11), clipEntry(2, 22)]);
+        const start = initialPreviewState(opts, 0);
+
+        const stalled = reducePreview(start, { type: "tick", at: 1_000 }, opts);
+        expect(stalled.clip).toBe("failed");
+        expect(stalled.index).toBe(0);
+
+        // failed の表示は placeholderSeconds だけ見せてから次へ進む (有限時間で必ず前進する)
+        const advanced = reducePreview(stalled, { type: "tick", at: 1_000 + 3_000 }, opts);
+        expect(advanced.index).toBe(1);
+        expect(advanced.clip).toBe("loading");
+        expect(advanced.generation).toBe(1);
+    });
+
+    it("failed 中の progress / playing は待ちを延ばさない・復帰させない (Codex R1-Critical)", () => {
+        const opts = options([clipEntry(1, 11), clipEntry(2, 22)]);
+        const failed = reducePreview(initialPreviewState(opts, 0), { type: "tick", at: 1_000 }, opts);
+        expect(failed.clip).toBe("failed");
+
+        // 失敗したクリップの要素はバッファリングを続けて progress を出し続けうる。
+        // 受け付けると progressAt が更新され続けて尺の満了判定が永久に成立しない。
+        const stillFailed = apply(failed, opts, [
+            { type: "progress", at: 2_000 },
+            { type: "playing", at: 3_000 },
+            { type: "progress", at: 3_900 },
+        ]);
+        expect(stillFailed).toEqual(failed);
+
+        // 失敗表示の尺が満了したら必ず次へ進む
+        expect(reducePreview(stillFailed, { type: "tick", at: 4_000 }, opts).index).toBe(1);
+    });
+
+    it("placeholder 中のメディア由来イベントも待ちを延ばさない", () => {
+        const opts = options([missingEntry(1), clipEntry(2, 22)]);
+        const start = initialPreviewState(opts, 0);
+
+        const untouched = apply(start, opts, [
+            { type: "progress", at: 1_000 },
+            { type: "ended", at: 2_000 },
+            { type: "error", at: 2_500 },
+        ]);
+        expect(untouched).toEqual(start);
+        expect(reducePreview(untouched, { type: "tick", at: 3_000 }, opts).index).toBe(1);
+    });
+
+    it("既定の停滞閾値は PREVIEW_STALL_TIMEOUT_MS である", () => {
+        const opts = options([clipEntry(1, 11)], { stallTimeoutMs: undefined });
+        const start = initialPreviewState(opts, 0);
+
+        expect(reducePreview(start, { type: "tick", at: PREVIEW_STALL_TIMEOUT_MS - 1 }, opts).clip).toBe(
+            "loading",
+        );
+        expect(reducePreview(start, { type: "tick", at: PREVIEW_STALL_TIMEOUT_MS }, opts).clip).toBe(
+            "failed",
+        );
+    });
+
+    it("progress が来ている間は停滞にならない", () => {
+        const opts = options([clipEntry(1, 11)]);
+        const start = initialPreviewState(opts, 0);
+
+        const state = apply(start, opts, [
+            { type: "playing", at: 100 },
+            { type: "progress", at: 900 },
+            { type: "tick", at: 1_500 },
+        ]);
+
+        expect(state.clip).toBe("playing");
+    });
+
+    it("paused 中は tick をいくら送っても failed にならない", () => {
+        const opts = options([clipEntry(1, 11), clipEntry(2, 22)]);
+        const start = initialPreviewState(opts, 0);
+
+        const state = apply(start, opts, [
+            { type: "playing", at: 10 },
+            { type: "paused", at: 20 },
+            { type: "tick", at: 5_000 },
+            { type: "tick", at: 50_000 },
+        ]);
+
+        expect(state.clip).toBe("paused");
+        expect(state.index).toBe(0);
+    });
+
+    it("shouldWatchStall は表示中かつ loading/playing のときだけ真", () => {
+        const opts = options([clipEntry(1, 11)]);
+        const base = initialPreviewState(opts, 0);
+
+        expect(shouldWatchStall(base)).toBe(true);
+        expect(shouldWatchStall({ ...base, clip: "playing" })).toBe(true);
+        expect(shouldWatchStall({ ...base, clip: "paused" })).toBe(false);
+        expect(shouldWatchStall({ ...base, clip: "blocked" })).toBe(false);
+        expect(shouldWatchStall({ ...base, clip: "failed" })).toBe(false);
+        expect(shouldWatchStall({ ...base, visible: false })).toBe(false);
+        expect(shouldWatchStall({ ...base, finished: true })).toBe(false);
+    });
+});
+
+describe("reducePreview: 一時停止と再開", () => {
+    it("loading 中の paused を受け付け、以後 tick で failed にならない", () => {
+        const opts = options([clipEntry(1, 11)]);
+        const start = initialPreviewState(opts, 0);
+
+        const paused = reducePreview(start, { type: "paused", at: 100 }, opts);
+        expect(paused.clip).toBe("paused");
+        expect(reducePreview(paused, { type: "tick", at: 90_000 }, opts).clip).toBe("paused");
+    });
+
+    it("resumed は loading へ戻し progressAt を引き直す (停止していた時間を停滞に数えない)", () => {
+        const opts = options([clipEntry(1, 11)]);
+        const start = initialPreviewState(opts, 0);
+
+        const resumed = apply(start, opts, [
+            { type: "paused", at: 100 },
+            { type: "resumed", at: 60_000 },
+        ]);
+        expect(resumed.clip).toBe("loading");
+        expect(resumed.progressAt).toBe(60_000);
+
+        expect(reducePreview(resumed, { type: "playing", at: 60_100 }, opts).clip).toBe("playing");
+    });
+
+    it("paused でない状態の resumed は状態を変えない", () => {
+        const opts = options([clipEntry(1, 11)]);
+        const start = initialPreviewState(opts, 0);
+
+        expect(reducePreview(start, { type: "resumed", at: 10 }, opts)).toEqual(start);
+    });
+});
+
+describe("reducePreview: 可視性", () => {
+    it("hidden 中は tick で進まず、shown で progressAt が引き直される", () => {
+        const opts = options([clipEntry(1, 11), clipEntry(2, 22)]);
+        const start = initialPreviewState(opts, 0);
+
+        const hidden = apply(start, opts, [
+            { type: "hidden", at: 10 },
+            { type: "tick", at: 100_000 },
+        ]);
+        expect(hidden.clip).toBe("loading");
+        expect(hidden.index).toBe(0);
+
+        const shown = reducePreview(hidden, { type: "shown", at: 100_100 }, opts);
+        expect(shown.visible).toBe(true);
+        expect(shown.progressAt).toBe(100_100);
+    });
+
+    it("paused → hidden → shown で再生状態が変わらない", () => {
+        const opts = options([clipEntry(1, 11)]);
+        const start = initialPreviewState(opts, 0);
+
+        const state = apply(start, opts, [
+            { type: "playing", at: 10 },
+            { type: "paused", at: 20 },
+            { type: "hidden", at: 30 },
+            { type: "shown", at: 40 },
+        ]);
+
+        expect(state.clip).toBe("paused");
+    });
+
+    it("非表示中はメディア由来イベントを受け付けない (ended / error / playing / paused)", () => {
+        const opts = options([clipEntry(1, 11), clipEntry(2, 22)]);
+        const hidden = reducePreview(initialPreviewState(opts, 0), { type: "hidden", at: 10 }, opts);
+
+        for (const type of ["ended", "error", "playing", "paused"] as const) {
+            const next = reducePreview(hidden, { type, at: 20 }, opts);
+            expect(next).toEqual(hidden);
+        }
+    });
+
+    it("非表示中でも利用者操作 (skip) は効く", () => {
+        const opts = options([clipEntry(1, 11), clipEntry(2, 22)]);
+        const hidden = reducePreview(initialPreviewState(opts, 0), { type: "hidden", at: 10 }, opts);
+
+        const skipped = reducePreview(hidden, { type: "skip", at: 20 }, opts);
+        expect(skipped.index).toBe(1);
+        expect(skipped.generation).toBe(1);
+    });
+});
+
+describe("reducePreview: 世代", () => {
+    it("advance 後に古い世代の error / blocked を送っても状態が変わらない", () => {
+        const opts = options([clipEntry(1, 11), clipEntry(2, 22)]);
+        const advanced = reducePreview(initialPreviewState(opts, 0), { type: "ended", at: 10 }, opts);
+
+        expect(advanced.generation).toBe(1);
+        expect(reducePreview(advanced, { type: "error", generation: 0, at: 20 }, opts)).toEqual(advanced);
+        expect(reducePreview(advanced, { type: "blocked", generation: 0, at: 20 }, opts)).toEqual(
+            advanced,
+        );
+    });
+
+    it("現在世代のイベントは受理される", () => {
+        const opts = options([clipEntry(1, 11), clipEntry(2, 22)]);
+        const advanced = reducePreview(initialPreviewState(opts, 0), { type: "ended", at: 10 }, opts);
+
+        expect(reducePreview(advanced, { type: "error", generation: 1, at: 20 }, opts).clip).toBe(
+            "failed",
+        );
+    });
+});
+
+describe("reducePreview: blocked (自動再生制限)", () => {
+    it("blocked → retry → blocked を繰り返しても failed にならない", () => {
+        const opts = options([clipEntry(1, 11), clipEntry(2, 22)]);
+        const start = initialPreviewState(opts, 0);
+
+        const state = apply(start, opts, [
+            { type: "blocked", at: 10 },
+            { type: "tick", at: 90_000 },
+            { type: "retry", at: 90_100 },
+            { type: "blocked", at: 90_200 },
+            { type: "tick", at: 180_000 },
+        ]);
+
+        expect(state.clip).toBe("blocked");
+        expect(state.index).toBe(0);
+    });
+
+    it("blocked から skip で次へ進む (出口がある)", () => {
+        const opts = options([clipEntry(1, 11), clipEntry(2, 22)]);
+        const blocked = reducePreview(initialPreviewState(opts, 0), { type: "blocked", at: 10 }, opts);
+
+        const skipped = reducePreview(blocked, { type: "skip", at: 20 }, opts);
+        expect(skipped.index).toBe(1);
+        expect(skipped.clip).toBe("loading");
+    });
+});
+
+describe("reducePreview: プレースホルダ", () => {
+    it("placeholder は placeholderSeconds 経過の tick で次へ進む", () => {
+        const opts = options([missingEntry(1), clipEntry(2, 22)]);
+        const start = initialPreviewState(opts, 0);
+
+        expect(reducePreview(start, { type: "tick", at: 2_999 }, opts).index).toBe(0);
+        const advanced = reducePreview(start, { type: "tick", at: 3_000 }, opts);
+        expect(advanced.index).toBe(1);
+        expect(advanced.clip).toBe("loading");
+    });
+
+    it("missing が連続しても順に進み最後は finished になる", () => {
+        const opts = options([missingEntry(1), missingEntry(2)]);
+        const start = initialPreviewState(opts, 0);
+
+        const second = reducePreview(start, { type: "tick", at: 3_000 }, opts);
+        expect(second.clip).toBe("placeholder");
+        const finished = reducePreview(second, { type: "tick", at: 6_000 }, opts);
+        expect(finished.finished).toBe(true);
+    });
+});
+
+describe("reducePreview: 終端と空リスト", () => {
+    it("最後の entry の ended で finished になり、以後どのイベントでも状態が変わらない", () => {
+        const opts = options([clipEntry(1, 11)]);
+        const finished = reducePreview(initialPreviewState(opts, 0), { type: "ended", at: 10 }, opts);
+
+        expect(finished.finished).toBe(true);
+        for (const type of ["tick", "skip", "retry", "error", "playing", "shown"] as const) {
+            expect(reducePreview(finished, { type, at: 20 }, opts)).toEqual(finished);
+        }
+    });
+
+    it("entries が 0 件ならどのイベントを送っても状態が変わらない (clip の値に依存しない)", () => {
+        const opts = options([]);
+        const start = initialPreviewState(opts, 0);
+
+        for (const type of ["tick", "skip", "ended", "error", "hidden", "shown"] as const) {
+            expect(reducePreview(start, { type, at: 10 }, opts)).toEqual(start);
+        }
+    });
+});
diff --git a/tests/js/pages/CaptureShow.test.ts b/tests/js/pages/CaptureShow.test.ts
index 1f8a149..905238e 100644
--- a/tests/js/pages/CaptureShow.test.ts
+++ b/tests/js/pages/CaptureShow.test.ts
@@ -100,6 +100,7 @@ function makeCut(overrides: Partial<CaptureCut> = {}): CaptureCut {
         subtitle_primary: null,
         subtitle_secondary: "",
         adopted_take_id: null,
+        adopted_ready_take_id: null,
         takes: [],
         ...overrides,
     };
@@ -134,13 +135,14 @@ function makeAdoptedManual(): CaptureManualDetail {
         id: 5,
         title: "ネジ締め作業",
         status: "ready",
-        cuts: [makeCut({ adopted_take_id: take.id, takes: [take] })],
+        cuts: [makeCut({ adopted_take_id: take.id, adopted_ready_take_id: take.id, takes: [take] })],
     };
 }
 
 const baseProps = {
     project: { id: 1, name: "現場A" },
     manual: makeManual(),
+    previewPlaceholderSeconds: 3,
 };
 
 function stubCameraSupported(supported: boolean): void {
@@ -295,7 +297,11 @@ describe("Capture/Show カメラフォールバック", () => {
 });
 
 describe("Capture/Show 採用済みテイク自動 DL 結線 (T051)", () => {
-    const adoptedProps = { project: { id: 1, name: "現場A" }, manual: makeAdoptedManual() };
+    const adoptedProps = {
+        project: { id: 1, name: "現場A" },
+        manual: makeAdoptedManual(),
+        previewPlaceholderSeconds: 3,
+    };
 
     it("入室時に run(manual) が発火し、changed=true なら manual reload される", async () => {
         stubCameraSupported(false);
@@ -364,7 +370,9 @@ describe("Capture/Show 採用済みテイク自動 DL 結線 (T051)", () => {
         stubCameraSupported(true);
         getUserMediaMock.mockRejectedValue(new DOMException("denied", "NotAllowedError"));
 
-        render(CaptureShow, { props: { project: { id: 1, name: "現場A" }, manual: makeManual() } });
+        render(CaptureShow, {
+            props: { project: { id: 1, name: "現場A" }, manual: makeManual(), previewPlaceholderSeconds: 3 },
+        });
         await selectCut();
         await fireEvent.click(screen.getByTestId("start-recording"));
         await vi.waitFor(() => {
@@ -607,8 +615,16 @@ function makeLandscapeManual(count: number): CaptureManualDetail {
     };
 }
 
-function landscapeProps(count = 3): { project: { id: number; name: string }; manual: CaptureManualDetail } {
-    return { project: { id: 1, name: "現場A" }, manual: makeLandscapeManual(count) };
+function landscapeProps(count = 3): {
+    project: { id: number; name: string };
+    manual: CaptureManualDetail;
+    previewPlaceholderSeconds: number;
+} {
+    return {
+        project: { id: 1, name: "現場A" },
+        manual: makeLandscapeManual(count),
+        previewPlaceholderSeconds: 3,
+    };
 }
 
 /** 実 CameraRecorder を録画状態まで駆動できる stub 一式 (component は本物のまま使う) */
@@ -931,3 +947,110 @@ describe("Capture/Show 全画面での録画中カット移動抑止 (T186)", ()
         expect(screen.getByTestId("cut-swipe-label")).toHaveTextContent("手順 2");
     });
 });
+
+/*
+ * 通し再生 (全体連結プレビュー / T191) のページ配線。
+ * 再生そのものの契約は ScenarioPreviewDialog.test.ts / scenario-preview.test.ts が持つ。
+ * ここで固定するのは **ページが何を渡し、いつ開き、カメラ資源をどう明け渡すか** だけ。
+ */
+describe("Capture/Show 通し再生の配線 (T191)", () => {
+    beforeEach(() => {
+        // jsdom は HTMLMediaElement の再生系を未実装 (ダイアログの teardown / 再生要求が呼ぶ)
+        vi.spyOn(HTMLMediaElement.prototype, "play").mockResolvedValue(undefined);
+        vi.spyOn(HTMLMediaElement.prototype, "pause").mockImplementation(() => undefined);
+        vi.spyOn(HTMLMediaElement.prototype, "load").mockImplementation(() => undefined);
+    });
+
+    afterEach(() => {
+        vi.restoreAllMocks();
+    });
+
+    it("通し再生ボタンを押すとダイアログが開く", async () => {
+        stubCameraSupported(false);
+        render(CaptureShow, { props: baseProps });
+
+        const button = screen.getByTestId("scenario-preview-button");
+        expect(button).not.toBeDisabled();
+
+        await fireEvent.click(button);
+
+        expect(screen.getByTestId("scenario-preview-dialog")).toBeInTheDocument();
+    });
+
+    it("カットが 0 件ならボタンを出さない (disabled ではなく非表示)", () => {
+        stubCameraSupported(false);
+        render(CaptureShow, {
+            props: { ...baseProps, manual: { ...makeManual(), cuts: [] } },
+        });
+
+        expect(screen.queryByTestId("scenario-preview-button")).not.toBeInTheDocument();
+    });
+
+    it("録画中に押すとエラーを出しダイアログは開かない (ボタンは常に押せる)", async () => {
+        stubCameraRecordable();
+        render(CaptureShow, { props: baseProps });
+        await selectCut();
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => {
+            expect(screen.getByTestId("stop-recording")).toBeInTheDocument();
+        });
+
+        const button = screen.getByTestId("scenario-preview-button");
+        expect(button).not.toBeDisabled();
+        await fireEvent.click(button);
+
+        expect(screen.getByTestId("scenario-preview-error")).toHaveTextContent(
+            "撮影中は通し再生を開始できません。撮影を停止してからお試しください。",
+        );
+        expect(screen.queryByTestId("scenario-preview-dialog")).not.toBeInTheDocument();
+    });
+
+    it("録画中のエラー文言は個別 preview と同じ言い回しである (制約を別の言葉で説明しない)", async () => {
+        stubCameraRecordable();
+        render(CaptureShow, { props: baseProps });
+        await selectCut();
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => {
+            expect(screen.getByTestId("stop-recording")).toBeInTheDocument();
+        });
+
+        await fireEvent.click(screen.getByTestId("scenario-preview-button"));
+        const scenarioMessage = (screen.getByTestId("scenario-preview-error").textContent ?? "").trim();
+
+        // 個別 preview (TakeStrip) は「撮影中はプレビューを再生できません。撮影を停止してからお試しください。」
+        // で、対になる文言は TakeStrip.test.ts が固定している。**共通節が同一**であることと、
+        // 通し再生側の全文がこの 1 文であることの両方を固定する (別の言い回しへ分岐したら赤くなる)。
+        const SHARED_CLAUSE = "撮影を停止してからお試しください。";
+        expect(scenarioMessage).toBe(`撮影中は通し再生を開始できません。${SHARED_CLAUSE}`);
+        expect(scenarioMessage.endsWith(SHARED_CLAUSE)).toBe(true);
+    });
+
+    it("開くとカメラを解放し、閉じると復帰する", async () => {
+        stubCameraRecordable();
+        const camera = fakeStream();
+        getUserMediaMock.mockResolvedValue(camera.stream);
+        render(CaptureShow, { props: baseProps });
+        await selectCut();
+        // カメラは録画開始で初めて取得される。撮影を停止すると stream は live のまま idle へ戻る
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => {
+            expect(screen.getByTestId("stop-recording")).toBeInTheDocument();
+        });
+        await fireEvent.click(screen.getByTestId("stop-recording"));
+        await vi.waitFor(() => {
+            expect(screen.getByTestId("start-recording")).toBeInTheDocument();
+        });
+        expect(getUserMediaMock).toHaveBeenCalledTimes(1);
+
+        await fireEvent.click(screen.getByTestId("scenario-preview-button"));
+
+        expect(camera.stop).toHaveBeenCalled(); // releaseForPreview 経由でトラックが止まる
+
+        await fireEvent.click(screen.getByTestId("scenario-preview-close"));
+
+        // resumeAfterPreview による再取得 (解放前に live だったときだけ走る)
+        await vi.waitFor(() => {
+            expect(getUserMediaMock).toHaveBeenCalledTimes(2);
+        });
+    });
+});
```

## 再検証結果 (修正後)

- composer test (該当): ScenarioPreviewProps 13 tests / 13 passed
- composer phpstan (level 10): No errors
- vendor/bin/pint --test: passed
- pnpm lint: passed
- pnpm typecheck: passed
- pnpm test: 153 files / 1886 passed (Round 1 時点 1882 + 追加 4)
- pnpm build: 成功

## 確認してほしい点

1. `sessionId` (単調増加) と `generation` (セッション内) の 2 段照合で、
   close/reopen・replay をまたぐ遅延結果の混入経路が閉じたか。閉じ残りがあれば具体系列で指摘してほしい。
2. `failed` / `placeholder` の間にメディア由来イベントを落とす変更が、
   「有限時間で必ず次へ進む」を壊していないか (前進経路を塞いでいないか)。
3. 追加したテストが実装の追認になっていないか (実装を壊したときに実際に赤くなるか)。
