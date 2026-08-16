<script lang="ts">
    import { onMount, tick } from "svelte";
    import { router } from "@inertiajs/svelte";
    import {
        Check,
        ChevronDown,
        ChevronUp,
        Film,
        ListPlus,
        Plus,
        Redo2,
        Trash2,
        Undo2,
    } from "@lucide/svelte";
    import Alert from "@/components/atoms/Alert.svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import DragHandle from "@/components/atoms/DragHandle.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Select from "@/components/atoms/Select.svelte";
    import Textarea from "@/components/atoms/Textarea.svelte";
    import EmptyState from "@/components/molecules/EmptyState.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import TakeHoverPreview from "@/components/features/manual/TakeHoverPreview.svelte";
    import { takeUrl as buildTakeUrl } from "@/lib/capture/take-endpoints";
    import { csrfToken } from "@/lib/csrf";
    import { moveItem } from "@/lib/dnd/list-reorder";
    import { createPointerDrag, type PointerDragState } from "@/lib/dnd/pointer-drag";
    import { boundHistory, parseHistorySnapshot, pushHistory } from "@/lib/manual/scenario-history";
    import { addToast } from "@/lib/stores/toast";
    import type {
        CutTakeSummary,
        DraftPoint,
        DraftStep,
        ScenarioConflictBody,
        ScenarioDocument,
        ScenarioStep,
    } from "@/types/manual";

    /**
     * シナリオ (手順 step / 急所 point の 2 階層ツリー) の document 編集エディタ。
     * クライアントは作業コピー (DraftStep[]) を編集し、「シナリオを更新」で document 全体を
     * 1 回の PUT で送信する (doc/09 §9.4)。楽観ロックの 409 / 行別 422 は自前で描画する。
     * parent_cut_id / sort_order / type は payload に含めない (サーバ導出。doc/10 §10.8-5)。
     */
    interface Props {
        projectId: number;
        manualId: number;
        scenario: ScenarioDocument;
        /** 動画列 (カットごとのテイク要約)。未保存行 (id=null) には対応する要約が無い */
        takeSummaries: CutTakeSummary[];
    }

    let { projectId, manualId, scenario, takeSummaries }: Props = $props();

    /** cut_id → 要約の索引 (行ごとの線形探索を避ける) */
    const summaryByCutId = $derived(
        new Map(takeSummaries.map((summary) => [summary.cut_id, summary])),
    );

    // インスタンス内カウンタ (instance script 宣言 = コンポーネントインスタンスごとに独立)。
    // 採番値は履歴文字列に保存され undo/redo で round-trip する。
    let clientKeySeq = 0;
    function nextClientKey(): string {
        clientKeySeq += 1;
        return `ck-${clientKeySeq}`;
    }

    /** サーバ shape → 編集用作業コピー (新しい配列/オブジェクトに clone し props と分離する) */
    function toDraftSteps(steps: ScenarioStep[]): DraftStep[] {
        return steps.map((step) => ({
            ...rowOf(step),
            id: step.id,
            clientKey: nextClientKey(),
            points: step.points.map((point) => ({
                ...rowOf(point),
                id: point.id,
                clientKey: nextClientKey(),
            })),
        }));
    }

    /** 本文フィールドのみの正規形 (キー順固定。payload / dirty 比較の共通基盤) */
    function rowOf(row: Omit<DraftPoint, "id">): Omit<DraftPoint, "id"> {
        return {
            scene: row.scene,
            shot_type: row.shot_type,
            shooting_point: row.shooting_point,
            narration: row.narration,
            subtitle_primary: row.subtitle_primary,
            subtitle_secondary: row.subtitle_secondary,
            material_type: row.material_type,
            static_display_seconds: row.static_display_seconds,
        };
    }

    /**
     * PUT payload の steps を組み立てる (呼び出しごとに新しい配列/オブジェクトを生成)。
     * parent_cut_id / sort_order / type は含めない (サーバ導出)。
     */
    function payloadSteps(): Array<Record<string, unknown>> {
        return steps.map((step) => ({
            id: step.id,
            ...rowOf(step),
            points: step.points.map((point) => ({ id: point.id, ...rowOf(point) })),
        }));
    }

    /**
     * 正規化シリアライザ (履歴/dirty 比較の正規形)。
     * clientKey を含め undo/redo で安定 key を round-trip させる。
     * payloadSteps (PUT body) は clientKey を含めない (サーバ保護キー混入防止)。
     */
    function serializeSteps(list: DraftStep[]): string {
        return JSON.stringify(
            list.map((step) => ({
                clientKey: step.clientKey,
                id: step.id,
                ...rowOf(step),
                points: step.points.map((point) => ({
                    clientKey: point.clientKey,
                    id: point.id,
                    ...rowOf(point),
                })),
            })),
        );
    }

    // 作業コピーは scenario prop から「マウント時に一度だけ」seed する (意図的)。
    // prop 追随で編集中の内容を握り潰さないため。サーバ最新への置換は
    // applySaved (保存成功) / reloadScenario (409 からの明示同意リロード) が reseed で行う。
    // svelte-ignore state_referenced_locally
    let version = $state(scenario.scenario_version);
    // clientKey 採番は 1 回だけ (2 回呼ぶと steps と snapshot で異なるキーが振られ初期 dirty になる)。
    // svelte-ignore state_referenced_locally
    const initialSteps = toDraftSteps(scenario.steps);
    // svelte-ignore state_referenced_locally
    let steps = $state<DraftStep[]>(initialSteps);
    /** 保存済みスナップショット (正規形の JSON 文字列。$state proxy と参照を共有しない) */
    // svelte-ignore state_referenced_locally
    let snapshot = $state(serializeSteps(initialSteps));
    let saving = $state(false);
    // 直近の保存成功をその場に残す (toast の 4s 自動消去に依存しない永続確認)。
    // true にするのは applySaved() のみ。reseed()・save 開始・失敗・dirty 転換で false。
    let justSaved = $state(false);
    let errors = $state<Record<string, string[]>>({});

    // --- undo/redo 履歴 (保存前のローカル編集のみ対象。サーバ状態 version/snapshot は不変) ---
    let undoStack = $state<string[]>([]);
    let redoStack = $state<string[]>([]);
    /** 編集フィールド focus 時の「変更前」状態 (未確定の pending 編集の基準)。canUndo が参照するため $state */
    let editBaseline = $state<string | null>(null);
    // IME/保留は event handler 内でのみ同期参照するため非 reactive local で足りる
    let composing = false;
    let flushDeferred = false;
    /** composing 中に要求された構造操作/undo/redo を compositionend 後に FIFO 実行する */
    let pendingActions: Array<() => void> = [];

    /**
     * 保存失敗フィードバックの判別可能 union。
     * - conflict: 409 (scenario_conflict 契約。理由はサーバ供給 message)
     * - forbidden: 403 (セッション途中の権限剥奪等。将来の再ログイン導線はこの分岐に足す)
     * - generic: 通信断・5xx・shape 不一致などその他の失敗
     */
    type SaveFailure =
        | { kind: "conflict"; body: ScenarioConflictBody }
        | { kind: "forbidden" }
        | { kind: "generic"; message: string };

    /** アラート描画用の表示モデル (kind → 見た目の導出を switch 1 箇所に集約) */
    interface FailureView {
        type: "warning" | "danger";
        title?: string;
        message: string;
        showReloadCta: boolean;
        testId: string;
    }

    let saveFailure = $state<SaveFailure | null>(null);
    /** 失敗アラートの focus 対象 wrapper (tabindex=-1) */
    let failureEl = $state<HTMLDivElement | null>(null);
    /**
     * 削除確認ダイアログが対象にしている手順の**安定キー** (null = 閉じている)。
     * 数値 index を持つと、ダイアログを開いている間に compositionend で遅延中の並べ替えが
     * 実行されたとき、確定した時点で index が別の手順を指す。
     */
    let confirmingStepKey = $state<string | null>(null);
    let confirmingReload = $state(false);
    /** 明示同意済みの最新取得中フラグ (dirty 離脱確認を二重に出さない) */
    let reloading = false;

    const dirty = $derived(serializeSteps(steps) !== snapshot);

    const canUndo = $derived(
        undoStack.length > 0 ||
            (editBaseline !== null && editBaseline !== serializeSteps(steps)),
    );
    const canRedo = $derived(redoStack.length > 0);

    // 編集で dirty に転じたら成功確認を消す (level-triggered)。dirty は derived で決定的なため
    // applySaved 直後は dirty=false のままで justSaved=true が保たれる。
    $effect(() => {
        if (dirty) justSaved = false;
    });

    /** 新規行の空値 (scene のみ必須のため空で作る)。clientKey は安定 key 用に採番する */
    function emptyRow(shotType: "hiki" | "yori"): Omit<DraftPoint, "id"> {
        return {
            clientKey: nextClientKey(),
            scene: "",
            shot_type: shotType,
            shooting_point: null,
            narration: "",
            subtitle_primary: null,
            subtitle_secondary: "",
            material_type: null,
            static_display_seconds: null,
        };
    }

    function addStep(): void {
        runSettled(() =>
            commitStructural(() => steps.push({ ...emptyRow("hiki"), id: null, points: [] })),
        );
    }

    /**
     * 急所の追加。
     * **親手順は数値 index ではなく安定キー (clientKey) で持ち回る**。`runSettled` は IME 変換中に
     * 実行を compositionend まで遅らせるため、実行時点では手順の並びが変わっていることがある
     * (moveStepTo / movePointTo と同じ理由)。実行時に親手順が消えていたら**何もしない** —
     * 「この手順に足す」という意図が無効になったので、別の手順や末尾へ足さない。
     */
    function addPoint(stepKey: string): void {
        runSettled(() => {
            const at = steps.findIndex((step) => step.clientKey === stepKey); // 実行時点で解決し直す
            if (at < 0) return;
            const parent = steps[at];
            commitStructural(() => parent.points.push({ ...emptyRow("yori"), id: null }));
        });
    }

    /**
     * 手順の削除。
     * **対象は数値 index ではなく安定キー (clientKey) で持ち回る** (理由は addPoint と同じ)。
     * 見つからなければ何もしない。**早期 return は必須** (落とすと splice(-1,1) で末尾が消える)。
     * ダイアログを閉じるのは解決の成否によらず即時に行う。
     */
    function removeStep(stepKey: string): void {
        runSettled(() => {
            const at = steps.findIndex((step) => step.clientKey === stepKey); // 実行時点で解決し直す
            if (at < 0) return; // ← 落とすと splice(-1,1) で末尾を消す
            commitStructural(() => steps.splice(at, 1));
        });
        confirmingStepKey = null; // 確認ダイアログを閉じるのは即時 (履歴とは独立)
    }

    /**
     * 急所の削除。
     * **親手順・対象の急所とも安定キー (clientKey) で持ち回る** (理由は addPoint と同じ)。
     * 見つからなければ**何もしない** — もう消えているなら意図は満たされている。
     * 親を先に解決してからその配下だけを探すのは、「急所は手順をまたがない」という
     * 既存のドメイン制約をコードに残すためである (movePointTo と同じ形)。
     */
    function removePoint(stepKey: string, pointKey: string): void {
        runSettled(() => {
            const stepAt = steps.findIndex((step) => step.clientKey === stepKey);
            if (stepAt < 0) return; // ← 落とすと steps[-1] が undefined で TypeError になり drain が中断する
            const parent = steps[stepAt];
            const at = parent.points.findIndex((point) => point.clientKey === pointKey);
            if (at < 0) return; // ← 落とすと splice(-1,1) で末尾の急所を消す
            commitStructural(() => parent.points.splice(at, 1));
        });
    }

    // --- 並べ替え (同一スコープ内のみ。階層をまたぐ移動は提供しない) ---

    /** 並べ替え結果のスクリーンリーダ告知 (視覚的には出さない) */
    let reorderStatus = $state("");
    function announce(message: string): void {
        reorderStatus = message;
    }

    /**
     * 並べ替えは「任意位置への移動」1 本に集約する。
     * ▲▼ ボタン・ハンドルのキーボード操作・D&D のすべてがここへ合流するので、
     * undo/redo 履歴・dirty 判定・IME ゲート (runSettled) との整合が 1 箇所で保たれる。
     * 保存 payload は配列順がそのまま順序 (sort_order はサーバ採番) なので、
     * ここで順序表現を作る必要はない。
     */
    function moveStepTo(from: number, to: number): void {
        const target = steps[from];
        if (target === undefined || from === to || to < 0 || to >= steps.length) return;
        // 掴んだ行を**安定キーで覚える**。runSettled は IME 変換中なら実行を compositionend まで
        // 遅らせるので、その間に先行する構造操作が実行されると数値 index は別の行を指す。
        const key = target.clientKey;
        // 告知は runSettled の**中**に置く。実行時の再検査で no-op になることもあるため、
        // 外に置くと「移動していないのに移動しましたと読み上げる」ことになる (design-review R2)。
        runSettled(() => {
            const at = steps.findIndex((step) => step.clientKey === key); // 実行時点で解決し直す
            if (at < 0 || at === to || to >= steps.length) return;
            commitStructural(() => {
                steps = moveItem(steps, at, to);
            });
            announce(`手順 ${at + 1} を ${to + 1} 番目に移動しました`);
        });
    }

    /**
     * 急所の移動。
     * **対象は数値 index ではなく安定キー (clientKey) で持ち回る**。`runSettled` は IME 変換中に
     * 実行を compositionend まで遅らせるため、実行時点では手順の並びが変わっていることがあり、
     * 数値 index を持ち回ると「掴んだのとは別の手順の急所」を並べ替えてしまう
     * (impl-review R1 Critical)。呼び出し時点と実行時点の両方で検査する。
     */
    function movePointTo(stepIndex: number, from: number, to: number): void {
        const step = steps[stepIndex];
        if (step === undefined) return;
        const point = step.points[from];
        if (point === undefined || from === to || to < 0 || to >= step.points.length) return;
        const stepKey = step.clientKey;
        const pointKey = point.clientKey;
        runSettled(() => {
            const stepAt = steps.findIndex((row) => row.clientKey === stepKey);
            if (stepAt < 0) return;
            const current = steps[stepAt];
            const at = current.points.findIndex((row) => row.clientKey === pointKey);
            if (at < 0 || at === to || to >= current.points.length) return;
            commitStructural(() => {
                current.points = moveItem(current.points, at, to);
            });
            announce(`急所 ${stepAt + 1}-${at + 1} を ${to + 1} 番目に移動しました`);
        });
    }

    /** ▲▼ (既存 UI。挙動は現行と同じ = 1 段移動 + 端は無変更) */
    function moveStep(index: number, delta: -1 | 1): void {
        const next = index + delta;
        if (next < 0 || next >= steps.length) {
            // disabled にはしない (禁止事項 8)。押されたら「なぜ動かないか」を告知する
            announce(delta < 0 ? "これ以上、上へは移動できません" : "これ以上、下へは移動できません");
            return;
        }
        moveStepTo(index, next);
    }

    function movePoint(stepIndex: number, index: number, delta: -1 | 1): void {
        const step = steps[stepIndex];
        if (step === undefined) return;
        const next = index + delta;
        if (next < 0 || next >= step.points.length) {
            announce(delta < 0 ? "これ以上、上へは移動できません" : "これ以上、下へは移動できません");
            return;
        }
        movePointTo(stepIndex, index, next);
    }

    /** ドラッグ表示状態 (手順リスト / 急所リストで別々に持つ) */
    let stepDrag = $state<PointerDragState>({ activeIndex: null, insertionIndex: null });
    let pointDrag = $state<PointerDragState>({ activeIndex: null, insertionIndex: null });
    /** 急所ドラッグ中の親手順 index (急所は手順をまたがないので 1 つで足りる) */
    let pointDragStep = $state<number | null>(null);

    /** 手順 <ol> / ドラッグ中の急所 <ol> の実体 (行の実測に使う) */
    let stepListEl = $state<HTMLOListElement | null>(null);
    let pointListEl: HTMLOListElement | null = null; // ドラッグ中のみ有効 (非 reactive で足りる)

    /**
     * リスト直下の**行だけ**を表示順で採る。
     * `data-reorder-index` で絞るのは、落とし先の目印として末尾に差し込む `<li>` を
     * 測定対象から外すためである (混ざると目印の出現でリスト長が変わり、
     * 挿入位置が n と n+1 の間で振動する)。
     */
    function directRows(list: HTMLElement | null): HTMLElement[] {
        if (list === null) return [];
        return [...list.querySelectorAll<HTMLElement>(":scope > li[data-reorder-index]")];
    }

    // controller は**マウント時に 1 度だけ**作り、破棄時に必ず destroy する (受け入れ条件 A2)。
    // $effect ではなく onMount を使う: これは「派生状態」ではなく
    // 「ブラウザ資源 (window listener) をマウント期間だけ持つ」ことであり、
    // $effect だと「本体で $state を同期 read しなければ再実行されない」という
    // 実装者の注意力に依存した不変条件になる (多重生成のリスク)。
    let stepDragCtl: ReturnType<typeof createPointerDrag> | null = null;
    let pointDragCtl: ReturnType<typeof createPointerDrag> | null = null;

    /**
     * **コンポーネント全体で 1 つだけドラッグを許す所有権**。
     * controller は自分の pointerId しか知らないため、手順用と急所用の 2 つは
     * 互いを排他できない。所有権を持たないと
     * 「手順のドラッグ中に急所のドラッグを開始 → 手順を先に drop して並びが変わる →
     * 急所の drop が指す `pointDragStep` の数値 index が別の手順を指す」
     * という取り違えが起きる (design-review R3 Critical)。
     * 判定の基準は `start()` が**受理した瞬間**である (閾値超えではない)。
     * UI には出さないので非 reactive な local で足りる (既存の `composing` と同じ扱い)。
     */
    type DragOwner = "step" | "point";
    let dragOwner: DragOwner | null = null;

    /** ドラッグに紐づく状態 (所有権 + 急所スコープ) を 1 箇所で捨てる */
    function releaseDrag(): void {
        dragOwner = null;
        pointListEl = null;
        pointDragStep = null;
    }

    onMount(() => {
        stepDragCtl = createPointerDrag({
            rows: () => directRows(stepListEl),
            onState: (state) => (stepDrag = state),
            onCommit: (from, to) => {
                try {
                    moveStepTo(from, to);
                } finally {
                    releaseDrag();
                }
            },
            onCancel: releaseDrag,
        });
        pointDragCtl = createPointerDrag({
            rows: () => directRows(pointListEl),
            onState: (state) => (pointDrag = state),
            onCommit: (from, to) => {
                // 確定でも取消でも所有権とスコープは必ず捨てる (finally で漏れを塞ぐ)
                try {
                    if (pointDragStep !== null) movePointTo(pointDragStep, from, to);
                } finally {
                    releaseDrag();
                }
            },
            onCancel: releaseDrag,
        });
        return () => {
            stepDragCtl?.destroy();
            pointDragCtl?.destroy();
            stepDragCtl = null;
            pointDragCtl = null;
            releaseDrag(); // destroy は onCancel を呼ばないので、ここで明示的に捨てる
        };
    });

    /**
     * 手順ドラッグの開始。
     * 所有権 (`dragOwner`) が空いているときだけ開始し、**受理されたときだけ**所有権を確定する。
     */
    function onStepHandleDown(index: number, event: PointerEvent): void {
        if (dragOwner !== null || stepDragCtl === null) return; // 急所ドラッグ中は開始しない
        if (!stepDragCtl.start(index, event)) return;
        dragOwner = "step";
    }

    /**
     * 急所ドラッグの開始。
     * **スコープ (どの手順の <ol> を掴んでいるか) は `start()` が受理したときだけ確定する。**
     * 先に書き換えてしまうと、1 本目のドラッグが進行中に 2 本目の指で別の手順のハンドルを
     * 押したとき、1 本目の drop が**別の手順**へ適用される (iOS の多点入力で起きる
     * データ整合性バグ。design-review R2 Critical)。
     * さらに `dragOwner` により**手順ドラッグとの同時進行も断つ** (design-review R3 Critical)。
     */
    function onPointHandleDown(stepIndex: number, pointIndex: number, event: PointerEvent): void {
        if (dragOwner !== null || pointDragCtl === null) return; // 手順ドラッグ中は開始しない
        const target = event.currentTarget;
        const list =
            target instanceof HTMLElement
                ? target.closest<HTMLOListElement>("ol[data-point-list]")
                : null;
        if (list === null) return;
        // 一時変数で受け、受理されたときだけ反映する (順序が本質)
        if (!pointDragCtl.start(pointIndex, event)) return;
        dragOwner = "point";
        pointListEl = list;
        pointDragStep = stepIndex;
    }

    /** ハンドル上のキーボード操作 (▲▼ と同じ 1 段移動へ写す) */
    function onHandleKeydown(event: KeyboardEvent, move: (delta: -1 | 1) => void): void {
        if (event.key !== "ArrowUp" && event.key !== "ArrowDown") return;
        event.preventDefault();
        move(event.key === "ArrowUp" ? -1 : 1);
    }

    // --- 履歴コア (保存前ローカル編集のみ対象。undo/redo は steps を再代入し安定 clientKey で差分描画) ---

    /** 保存/リロード時に履歴を断つ (保存前ローカル編集のみ対象。R1 決定) */
    function resetHistory(): void {
        undoStack = [];
        redoStack = [];
        editBaseline = null;
        flushDeferred = false;
        pendingActions = [];
    }

    /** editBaseline を(変化があれば)確定して 1 エントリに積む。IME-aware・冪等 */
    function flushPendingEdit(): void {
        if (composing) {
            flushDeferred = true; // 変換確定後に compositionend で flush
            return;
        }
        if (editBaseline === null) return;
        const before = editBaseline;
        editBaseline = null; // 冪等化 (直後の focusout で再 push しない)
        if (pushHistory(undoStack, before, serializeSteps(steps))) {
            redoStack = []; // 新規編集で redo クリア
        }
    }

    /** 構造操作/undo/redo の IME ゲート。composing 中は compositionend まで保留 */
    function runSettled(action: () => void): void {
        if (composing) {
            pendingActions.push(action); // FIFO: 発行順に compositionend で実行 (R4 policy)
            return;
        }
        action();
    }

    /** 構造操作の共通コミット: pending 編集確定 → 変更前を控え → 変異 → 変化があれば push */
    function commitStructural(mutate: () => void): void {
        flushPendingEdit();
        const before = serializeSteps(steps);
        mutate();
        if (pushHistory(undoStack, before, serializeSteps(steps))) {
            redoStack = [];
        }
    }

    /**
     * 履歴文字列を検証(util)→ rowOf 正規化で新規 DraftStep[] を作り steps に反映。
     * 壊れていれば false(steps を変えない fail-safe)。素の型アサーションを残さない。
     */
    function restoreFrom(serialized: string): boolean {
        const parsed = parseHistorySnapshot(serialized); // util: unknown→type predicate→検証済み
        if (parsed === null) return false;
        steps = parsed.map((step) => ({
            ...rowOf(step),
            id: step.id,
            clientKey: step.clientKey, // 安定 key を round-trip
            points: step.points.map((point) => ({
                ...rowOf(point),
                id: point.id,
                clientKey: point.clientKey,
            })),
        }));
        return true;
    }

    function reportHistoryCorruption(): void {
        resetHistory();
        if (import.meta.env.DEV) {
            console.warn("[ScenarioEditor] 編集履歴の復元に失敗しました (履歴を破棄)");
        }
        addToast("warning", "編集履歴を復元できませんでした");
    }

    function undo(): void {
        runSettled(doUndo);
    }
    function redo(): void {
        runSettled(doRedo);
    }

    function doUndo(): void {
        flushPendingEdit(); // 進行中のテキスト編集を先に 1 エントリ確定
        if (undoStack.length === 0) return;
        const current = serializeSteps(steps); // 復元前 = redo へ退避する状態
        if (!restoreFrom(undoStack[undoStack.length - 1])) {
            reportHistoryCorruption(); // fail-safe: steps は変えない
            return;
        }
        undoStack.pop();
        redoStack.push(current);
        boundHistory(redoStack);
        editBaseline = null;
    }

    function doRedo(): void {
        flushPendingEdit(); // pending 編集があれば「新規編集」= redo クリア (この後 length 0 で no-op)
        if (redoStack.length === 0) return;
        const current = serializeSteps(steps);
        if (!restoreFrom(redoStack[redoStack.length - 1])) {
            reportHistoryCorruption();
            return;
        }
        redoStack.pop();
        undoStack.push(current);
        boundHistory(undoStack);
        editBaseline = null;
    }

    // --- focus / composition ハンドラ (section に委譲。バブリングする focusin/focusout を使う) ---

    /** input/textarea/select/contenteditable か */
    function isEditableField(el: EventTarget | null): boolean {
        if (!(el instanceof HTMLElement)) return false;
        const tag = el.tagName;
        return tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT" || el.isContentEditable;
    }

    function onEditorFocusIn(event: FocusEvent): void {
        if (isEditableField(event.target) && editBaseline === null) {
            editBaseline = serializeSteps(steps); // このフィールド編集セッションの基準
        }
    }
    function onEditorFocusOut(): void {
        flushPendingEdit(); // composing 中なら flushDeferred に退避される
    }
    // 粒度=フィールド単位 (1 フィールドの編集 = 1 履歴エントリ)。値を変えないフォーカス巡回は
    // pushHistory(before===current) が no-op のため履歴を汚さない。
    function onCompositionStart(): void {
        composing = true;
    }
    function onCompositionEnd(): void {
        composing = false;
        if (flushDeferred) {
            flushDeferred = false;
            flushPendingEdit(); // テキスト編集を 1 エントリ確定 (中間文字列は積まれない)
        }
        const queued = pendingActions;
        pendingActions = [];
        for (const action of queued) action(); // 構造操作/undo/redo を発行順に実行
    }

    // キーボードショートカット (Ctrl/Cmd+Z = undo, +Shift = redo)。編集フィールド内は native に委譲
    $effect(() => {
        const onKeydown = (event: KeyboardEvent): void => {
            if (event.isComposing) return; // IME 変換中は無視
            if (!(event.metaKey || event.ctrlKey) || event.key.toLowerCase() !== "z") return;
            if (saving || confirmingStepKey !== null || confirmingReload) return;
            // 編集フィールドに focus がある間は native の文字単位 undo に委ねる (R1 決定)
            if (isEditableField(document.activeElement)) return;
            event.preventDefault();
            if (event.shiftKey) redo();
            else undo();
        };
        window.addEventListener("keydown", onKeydown);
        return () => window.removeEventListener("keydown", onKeydown);
    });

    /**
     * union 網羅の型固定 (kind 追加時は引数の never 不一致でコンパイルエラーになり
     * 表示漏れを検出する)。runtime に到達した場合は throw せず汎用 fallback を返す
     * ($derived 内の throw で画面全体を巻き込まない。詳細レビュー合意)。
     */
    function unreachableFailureView(_value: never): FailureView {
        return {
            type: "danger",
            message: "保存に失敗しました。時間をおいて再度お試しください。",
            showReloadCta: false,
            testId: "scenario-generic-error",
        };
    }

    const failureView = $derived.by((): FailureView | null => {
        if (saveFailure === null) return null; // null 先処理 → switch (概念レビュー合意)
        switch (saveFailure.kind) {
            case "conflict":
                return {
                    type: "warning",
                    title: CONFLICT_TITLES[saveFailure.body.conflict_type],
                    message: saveFailure.body.message,
                    showReloadCta: saveFailure.body.conflict_type === "version_mismatch",
                    testId: "scenario-conflict-banner",
                };
            case "forbidden":
                return {
                    type: "danger",
                    message:
                        "この操作を行う権限がありません。ページを再読み込みして状態を確認してください。",
                    showReloadCta: false,
                    testId: "scenario-forbidden-error",
                };
            case "generic":
                return {
                    type: "danger",
                    message: saveFailure.message,
                    showReloadCta: false,
                    testId: "scenario-generic-error",
                };
            default:
                return unreachableFailureView(saveFailure);
        }
    });

    /**
     * 失敗フィードバックの単一表示経路 (全 kind 共通)。state 確定 → tick() で DOM 反映を
     * 待ち → focus({preventScroll}) → scrollIntoView({block:"nearest"}) の順で知覚させる。
     * 明示呼び出し限定 ($effect の state 監視にしない = 無関係な再レンダで再発火しない)。
     * focus 既定スクロールは抑止し、スクロール制御を scrollIntoView に一本化する
     * (完全可視ならスクロールは原則発生せず、連続失敗時のジャンプを起こしにくい)。
     */
    async function showFailure(failure: SaveFailure): Promise<void> {
        justSaved = false; // 失敗表示時は成功確認を消す
        saveFailure = failure;
        await tick();
        failureEl?.focus({ preventScroll: true });
        // UA 差異を残さないよう block/inline/behavior を全指定で固定 (Vitest は引数完全一致で担保)
        failureEl?.scrollIntoView({ block: "nearest", inline: "nearest", behavior: "auto" });
    }

    async function save(): Promise<void> {
        if (saving) return; // 多重送信ガード (disabled にはしない。押下は受けて即 return)
        saving = true;
        errors = {};
        justSaved = false; // 再保存中は前回の成功確認を伏せる
        saveFailure = null; // 前回の失敗表示をクリア (再保存成功後に旧エラーを残さない)
        try {
            const res = await putScenario();
            await handleResponse(res);
        } catch {
            // ネットワーク断・fetch reject (419 回復 GET / 再試行 PUT の reject も含む)。
            // 作業コピーは保持したまま汎用エラーを表示 (未処理 Promise を漏らさない)
            await showFailure({
                kind: "generic",
                message: "通信に失敗しました。接続を確認して再度お試しください。",
            });
        } finally {
            saving = false;
        }
    }

    async function putScenario(): Promise<Response> {
        return fetch(`/projects/${projectId}/manuals/${manualId}/scenario`, {
            method: "PUT",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-XSRF-TOKEN": csrfToken(),
                "X-Requested-With": "XMLHttpRequest",
            },
            credentials: "same-origin",
            body: JSON.stringify({ expected_version: version, steps: payloadSteps() }),
        });
    }

    async function handleResponse(res: Response, retried = false): Promise<void> {
        if (res.ok) {
            // 成功応答も実行時検証 (JSON 破損・期待外 shape は汎用エラーへフォールバック)
            const body = (await res.json().catch(() => null)) as unknown;
            if (isScenarioDocument(body)) {
                applySaved(body);
                return;
            }
            await showFailure({
                kind: "generic",
                message: "保存結果の取得に失敗しました。画面を再読み込みしてください。",
            });
            return;
        }
        if (res.status === 419 && !retried) {
            // CSRF 失効: cookie を再取得して 1 回だけ自動リトライ (doc/10 §10.8-3 の共通処理方針)
            await fetch(window.location.pathname, {
                credentials: "same-origin",
                headers: { Accept: "text/html" },
            });
            await handleResponse(await putScenario(), true);
            return;
        }
        if (res.status === 401 || res.status === 419) {
            // セッション失効: 作業コピーは破棄せず、別タブでの再ログインを案内 (リダイレクトしない)
            await showFailure({
                kind: "generic",
                message:
                    "セッションが切れました。別のタブでログインし直してから、もう一度保存してください。",
            });
            return;
        }
        if (res.status === 403) {
            // 権限剥奪など。理由を明示する (汎用「時間をおいて再試行」への誤誘導をやめる)
            await showFailure({ kind: "forbidden" });
            return;
        }
        if (res.status === 409) {
            const body = (await res.json().catch(() => null)) as ScenarioConflictBody | null;
            if (body?.code === "scenario_conflict") {
                await showFailure({ kind: "conflict", body }); // 作業コピーは保持
                return;
            }
        }
        if (res.status === 422) {
            // Laravel 標準 { errors: Record<string, string[]> } を実行時に判別 (防御的パース)
            const body = (await res.json().catch(() => null)) as { errors?: unknown } | null;
            if (body !== null && isValidationErrors(body.errors)) {
                errors = body.errors; // "steps.0.points.1.scene" 形式のキーを行別セルに表示
                return;
            }
        }
        await showFailure({
            kind: "generic",
            message: "保存に失敗しました。時間をおいて再度お試しください。",
        });
    }

    /**
     * サーバ document で作業コピーを再 seed する (保存成功 / 明示リロードの共通処理)。
     * version / steps / snapshot を最新へ置換し、旧作業コピー由来の行別エラーも消す。
     */
    function reseed(document: ScenarioDocument): void {
        version = document.scenario_version;
        steps = toDraftSteps(document.steps);
        snapshot = serializeSteps(steps);
        errors = {};
        justSaved = false; // 409 競合/明示リロードの reseed で偽の成功表示を出さない
        resetHistory(); // 保存成功/明示リロードで履歴を断つ (保存前ローカル編集のみ対象)
    }

    /** 成功応答の取り込み: 確定 id + version + スナップショット更新 + 成功トースト */
    function applySaved(document: ScenarioDocument): void {
        reseed(document);
        justSaved = true; // 保存成功パスのみ (reseed の後)
        addToast("success", "シナリオを保存しました");
    }

    /** Record<string, string[]> かを実行時検証する type guard */
    function isValidationErrors(value: unknown): value is Record<string, string[]> {
        if (value === null || typeof value !== "object" || Array.isArray(value)) return false;
        return Object.values(value).every(
            (messages) =>
                Array.isArray(messages) && messages.every((m) => typeof m === "string"),
        );
    }

    /** step/point 行の最低限 shape (id: number, scene: string) の実行時検証 */
    function isScenarioRow(value: unknown): boolean {
        if (value === null || typeof value !== "object") return false;
        const row = value as { id?: unknown; scene?: unknown };
        return typeof row.id === "number" && typeof row.scene === "string";
    }

    /**
     * サーバ document (scenario_version + steps ツリー) の type guard。
     * reload 経路は外部応答依存のため、行 shape (id/scene/points) まで軽量に検証する。
     */
    function isScenarioDocument(value: unknown): value is ScenarioDocument {
        if (value === null || typeof value !== "object") return false;
        const doc = value as { scenario_version?: unknown; steps?: unknown };
        return (
            typeof doc.scenario_version === "number" &&
            Array.isArray(doc.steps) &&
            doc.steps.every((step: unknown) => {
                if (!isScenarioRow(step)) return false;
                const points = (step as { points?: unknown }).points;
                return Array.isArray(points) && points.every(isScenarioRow);
            })
        );
    }

    /**
     * 409 バナーからの明示同意リロード (編集内容の破棄を ConfirmDialog で確認済み)。
     * Inertia の部分リロードは preserveState のためコンポーネントを再マウントしない。
     * scenario prop が差し替わっても $state の作業コピーは自動では追随しないので、
     * onSuccess で最新 document を明示的に再 seed する (楽観ロック競合からの復帰)。
     */
    function reloadScenario(): void {
        confirmingReload = false;
        saveFailure = null;
        reloading = true;
        router.reload({
            only: ["scenario", "manual"],
            onSuccess: (visited) => {
                const latest: unknown = (visited.props as Record<string, unknown>).scenario;
                if (isScenarioDocument(latest)) {
                    reseed(latest);
                    return;
                }
                void showFailure({
                    kind: "generic",
                    message: "最新シナリオの取得に失敗しました。画面を再読み込みしてください。",
                });
            },
            onError: () => {
                // 部分リロード自体の失敗 (ネットワーク断等)。無反応に見せない
                void showFailure({
                    kind: "generic",
                    message: "最新シナリオの取得に失敗しました。画面を再読み込みしてください。",
                });
            },
            onFinish: () => {
                reloading = false;
            },
        });
    }

    // dirty 離脱警告: beforeunload + Inertia before イベント (dirty 時 confirm)
    $effect(() => {
        const onBeforeUnload = (event: BeforeUnloadEvent): void => {
            if (dirty) event.preventDefault();
        };
        window.addEventListener("beforeunload", onBeforeUnload);
        const offBefore = router.on("before", (event) => {
            if (
                !reloading && // 明示同意済みリロードでは破棄確認を二重に出さない
                dirty &&
                !window.confirm("シナリオの変更が保存されていません。ページを離れますか?")
            ) {
                event.preventDefault();
            }
        });
        return () => {
            window.removeEventListener("beforeunload", onBeforeUnload);
            offBefore();
        };
    });

    function fieldError(prefix: string, field: string): string | undefined {
        return errors[`${prefix}.${field}`]?.[0];
    }

    /** 数値 input (string) → number | null 変換 (空文字は null) */
    function toSeconds(value: string): number | null {
        const parsed = Number.parseInt(value, 10);
        return Number.isNaN(parsed) ? null : parsed;
    }

    const CONFLICT_TITLES: Record<ScenarioConflictBody["conflict_type"], string> = {
        version_mismatch: "他の編集と競合しました",
        rendering: "処理中のため保存できません",
        analyzing: "処理中のため保存できません",
    };
</script>

{#snippet rowFields(row: DraftPoint, prefix: string, idPrefix: string)}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label="シーン (何を撮るか)" id="{idPrefix}-scene" error={fieldError(prefix, "scene")} required>
            {#snippet children({ id, describedBy, invalid })}
                <Input
                    {id}
                    type="text"
                    bind:value={row.scene}
                    error={invalid}
                    aria-describedby={describedBy}
                    testId="{idPrefix}-scene"
                />
            {/snippet}
        </FormField>
        <div class="grid grid-cols-2 gap-3">
            <FormField label="画角" id="{idPrefix}-shot-type" error={fieldError(prefix, "shot_type")}>
                {#snippet children({ id, describedBy, invalid })}
                    <Select
                        {id}
                        bind:value={row.shot_type}
                        error={invalid}
                        aria-describedby={describedBy}
                    >
                        <option value="hiki">引き (全体)</option>
                        <option value="yori">寄り (手元)</option>
                    </Select>
                {/snippet}
            </FormField>
            <FormField label="素材" id="{idPrefix}-material" error={fieldError(prefix, "material_type")}>
                {#snippet children({ id, describedBy, invalid })}
                    <Select
                        {id}
                        bind:value={
                            () => row.material_type ?? "",
                            (next) =>
                                (row.material_type = next === "" ? null : (next as "video" | "still"))
                        }
                        error={invalid}
                        aria-describedby={describedBy}
                    >
                        <option value="">未指定</option>
                        <option value="video">動画</option>
                        <option value="still">静止画</option>
                    </Select>
                {/snippet}
            </FormField>
        </div>
        <FormField
            label="撮影ポイント"
            id="{idPrefix}-shooting-point"
            error={fieldError(prefix, "shooting_point")}
        >
            {#snippet children({ id, describedBy, invalid })}
                <Input
                    {id}
                    type="text"
                    bind:value={
                        () => row.shooting_point ?? "",
                        (next) => (row.shooting_point = next === "" ? null : next)
                    }
                    error={invalid}
                    aria-describedby={describedBy}
                />
            {/snippet}
        </FormField>
        <FormField
            label="字幕① (要点・100文字まで)"
            id="{idPrefix}-subtitle-primary"
            error={fieldError(prefix, "subtitle_primary")}
        >
            {#snippet children({ id, describedBy, invalid })}
                <Input
                    {id}
                    type="text"
                    maxlength={100}
                    bind:value={
                        () => row.subtitle_primary ?? "",
                        (next) => (row.subtitle_primary = next === "" ? null : next)
                    }
                    error={invalid}
                    aria-describedby={describedBy}
                />
            {/snippet}
        </FormField>
        <FormField label="ナレーション" id="{idPrefix}-narration" error={fieldError(prefix, "narration")}>
            {#snippet children({ id, describedBy, invalid })}
                <Textarea
                    {id}
                    rows={2}
                    bind:value={row.narration}
                    error={invalid}
                    aria-describedby={describedBy}
                />
            {/snippet}
        </FormField>
        <FormField
            label="字幕② (補足)"
            id="{idPrefix}-subtitle-secondary"
            error={fieldError(prefix, "subtitle_secondary")}
        >
            {#snippet children({ id, describedBy, invalid })}
                <Textarea
                    {id}
                    rows={2}
                    bind:value={row.subtitle_secondary}
                    error={invalid}
                    aria-describedby={describedBy}
                />
            {/snippet}
        </FormField>
        {#if row.material_type === "still"}
            <FormField
                label="静止表示秒数 (1〜60)"
                id="{idPrefix}-static-seconds"
                error={fieldError(prefix, "static_display_seconds")}
            >
                {#snippet children({ id, describedBy, invalid })}
                    <Input
                        {id}
                        type="number"
                        min={1}
                        max={60}
                        bind:value={
                            () =>
                                row.static_display_seconds === null
                                    ? ""
                                    : String(row.static_display_seconds),
                            (next) => (row.static_display_seconds = toSeconds(next))
                        }
                        error={invalid}
                        aria-describedby={describedBy}
                    />
                {/snippet}
            </FormField>
        {/if}
    </div>
{/snippet}

{#snippet videoCell(cutId: number | null, testIdSuffix: string)}
    <!-- 動画列 (doc/04)。未保存行はリンクを出さず、押せるのに詰むボタンを作らない。
         行 Card の中に角丸カードを入れ子にせず、区切り線で段を分ける。

         サムネイルを出すのは**採用テイク 1 件だけ**である (doc/04 の
         「登録済みテイクはサムネイル表示」に対する意図的な狭め)。動画列の意味は
         「このカットの成果は何か」であり、候補テイクを見比べるのはテイク選択画面の仕事。
         未採用テイクの一覧表示は残ギャップとして扱う
         (devnotes/20260816-1757-take-thumbnail-hover-preview/conceptual-design.md)。
         has_thumbnail は**描画時点のスナップショット**である。生成は非同期なので、
         採用直後は false になりうる。その場合は今までどおり要約テキストだけになる
         (詰まないので、この画面に再取得ポーリングは持ち込まない)。 -->
    <div class="mt-3 border-t border-border pt-3" data-testid={`video-cell-${testIdSuffix}`}>
        <p class="text-caption text-text-secondary">動画</p>
        {#if cutId === null}
            <p class="mt-1 text-caption text-text-secondary" data-testid="video-cell-unsaved">
                「シナリオを更新」で保存すると、このカットに動画を登録できます。
            </p>
        {:else}
            {@const summary = summaryByCutId.get(cutId)}
            {@const takesHref = `/projects/${projectId}/manuals/${manualId}/cuts/${cutId}/takes`}
            {@const adopted = summary?.adopted ?? null}
            <div class="mt-1 flex items-start gap-2">
                <!-- サムネイル表示条件 = サーバが 404 を返す状態へ URL を張らないための 3 条件
                     (採用がある / ready / サムネイル生成済み)。充足判定ではない -->
                {#if adopted !== null && adopted.status === "ready" && adopted.has_thumbnail}
                    <TakeHoverPreview
                        thumbnailUrl={buildTakeUrl(
                            { projectId, manualId, cutId },
                            adopted.id,
                            "/thumbnail",
                        )}
                        playbackUrl={buildTakeUrl(
                            { projectId, manualId, cutId },
                            adopted.id,
                            "/playback",
                        )}
                        href={takesHref}
                        label="採用テイクを開く"
                        testId={`video-cell-preview-${testIdSuffix}`}
                    />
                {/if}
                <div class="min-w-0 flex-1">
                    <p class="flex items-center gap-2 text-caption text-text">
                        <span data-testid="video-cell-count">
                            テイク {summary?.takes_count ?? 0} 件
                        </span>
                        <!--
                          素材登録状況 (doc/02 §2.4 の 3 値)。「採用テイクが在るか」と
                          「その素材種別」だけで決める。ready かどうかは別軸なので、ここでは
                          言わない (充足の告知は既存の詳細画面 props が担当)。
                        -->
                        {#if adopted !== null}
                            <Badge tone="primary" testId="video-cell-material">
                                {adopted.material_type === "still" ? "静止画登録済" : "動画登録済"}
                            </Badge>
                        {:else}
                            <Badge tone="neutral" testId="video-cell-material">未登録</Badge>
                        {/if}
                    </p>
                    <div class="mt-2">
                        <Button
                            variant="neutral"
                            size="sm"
                            href={takesHref}
                            inertia
                            testId="video-cell-link"
                        >
                            <Film class="size-4" aria-hidden="true" />
                            {summary && summary.takes_count > 0 ? "テイクを選択" : "ファイルの選択"}
                        </Button>
                    </div>
                </div>
            </div>
        {/if}
    </div>
{/snippet}

<section
    aria-label="シナリオ編集"
    onfocusin={onEditorFocusIn}
    onfocusout={onEditorFocusOut}
    oncompositionstart={onCompositionStart}
    oncompositionend={onCompositionEnd}
>
    <!-- 並べ替え結果の読み上げ (視覚的には出さない。端で動かせない理由もここへ出す) -->
    <p class="sr-only" aria-live="polite" data-testid="scenario-reorder-status">{reorderStatus}</p>

    {#if steps.length === 0}
        <div class="mt-4">
            <EmptyState
                title="シナリオがまだありません"
                description="手順を追加して、マニュアル動画の台本を組み立てましょう。"
                icon={ListPlus}
                bordered
                cta={{ kind: "action", label: "最初の手順を追加", onclick: addStep }}
                testId="scenario-empty-state"
            />
        </div>
    {:else}
        <ol
            class="mt-4 flex flex-col gap-4 {stepDrag.activeIndex !== null ? 'select-none' : ''}"
            data-testid="scenario-steps"
            bind:this={stepListEl}
        >
            {#each steps as step, stepIndex (step.clientKey)}
                <li class="relative" data-reorder-index={stepIndex}>
                    {#if stepDrag.insertionIndex === stepIndex}
                        <!-- 落とし先の目印。影・scale は使わない (DESIGN.md §Elevation) -->
                        <div class="absolute inset-x-0 -top-2 h-0.5 bg-primary" aria-hidden="true"></div>
                    {/if}
                    <div class={stepDrag.activeIndex === stepIndex ? "opacity-50" : ""}>
                    <Card padding="md">
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <DragHandle
                                    ariaLabel={`手順 ${stepIndex + 1} の並び順を変更 (ドラッグ、または上下キー)`}
                                    onpointerdown={(event) => onStepHandleDown(stepIndex, event)}
                                    onkeydown={(event) =>
                                        onHandleKeydown(event, (delta) => moveStep(stepIndex, delta))}
                                    testId="step-{stepIndex}-drag-handle"
                                />
                                <h3 class="text-body font-medium text-text">手順 {stepIndex + 1}</h3>
                            </div>
                            <div class="flex items-center gap-1">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    iconOnly
                                    ariaLabel={`手順 ${stepIndex + 1} を上へ移動`}
                                    onclick={() => moveStep(stepIndex, -1)}
                                    testId="step-{stepIndex}-move-up"
                                >
                                    <ChevronUp class="size-4" aria-hidden="true" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    iconOnly
                                    ariaLabel={`手順 ${stepIndex + 1} を下へ移動`}
                                    onclick={() => moveStep(stepIndex, 1)}
                                    testId="step-{stepIndex}-move-down"
                                >
                                    <ChevronDown class="size-4" aria-hidden="true" />
                                </Button>
                                <Button
                                    variant="danger-ghost"
                                    size="sm"
                                    iconOnly
                                    ariaLabel={`手順 ${stepIndex + 1} を削除`}
                                    onclick={() => (confirmingStepKey = step.clientKey)}
                                    testId="step-{stepIndex}-remove"
                                >
                                    <Trash2 class="size-4" aria-hidden="true" />
                                </Button>
                            </div>
                        </div>
                        <div class="mt-3">
                            {@render rowFields(step, `steps.${stepIndex}`, `step-${stepIndex}`)}
                        </div>
                        {@render videoCell(step.id, `step-${stepIndex}`)}

                        {#if step.points.length > 0}
                            <ol
                                class="mt-4 flex flex-col gap-3 border-l-2 border-border pl-4"
                                data-point-list
                            >
                                {#each step.points as point, pointIndex (point.clientKey)}
                                    {@const dragging = pointDragStep === stepIndex}
                                    <li class="relative" data-reorder-index={pointIndex}>
                                        {#if dragging && pointDrag.insertionIndex === pointIndex}
                                            <div
                                                class="absolute inset-x-0 -top-1.5 h-0.5 bg-primary"
                                                aria-hidden="true"
                                            ></div>
                                        {/if}
                                        <div
                                            class={dragging && pointDrag.activeIndex === pointIndex
                                                ? "opacity-50"
                                                : ""}
                                        >
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="flex items-center gap-2">
                                                <DragHandle
                                                    ariaLabel={`急所 ${stepIndex + 1}-${pointIndex + 1} の並び順を変更 (ドラッグ、または上下キー)`}
                                                    onpointerdown={(event) =>
                                                        onPointHandleDown(stepIndex, pointIndex, event)}
                                                    onkeydown={(event) =>
                                                        onHandleKeydown(event, (delta) =>
                                                            movePoint(stepIndex, pointIndex, delta),
                                                        )}
                                                    testId="point-{stepIndex}-{pointIndex}-drag-handle"
                                                />
                                                <h4
                                                    class="text-caption font-medium text-text-secondary"
                                                >
                                                    急所 {stepIndex + 1}-{pointIndex + 1}
                                                </h4>
                                            </div>
                                            <div class="flex items-center gap-1">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    iconOnly
                                                    ariaLabel={`急所 ${stepIndex + 1}-${pointIndex + 1} を上へ移動`}
                                                    onclick={() => movePoint(stepIndex, pointIndex, -1)}
                                                    testId="point-{stepIndex}-{pointIndex}-move-up"
                                                >
                                                    <ChevronUp class="size-4" aria-hidden="true" />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    iconOnly
                                                    ariaLabel={`急所 ${stepIndex + 1}-${pointIndex + 1} を下へ移動`}
                                                    onclick={() => movePoint(stepIndex, pointIndex, 1)}
                                                    testId="point-{stepIndex}-{pointIndex}-move-down"
                                                >
                                                    <ChevronDown class="size-4" aria-hidden="true" />
                                                </Button>
                                                <Button
                                                    variant="danger-ghost"
                                                    size="sm"
                                                    iconOnly
                                                    ariaLabel={`急所 ${stepIndex + 1}-${pointIndex + 1} を削除`}
                                                    onclick={() =>
                                                        removePoint(step.clientKey, point.clientKey)}
                                                    testId="point-{stepIndex}-{pointIndex}-remove"
                                                >
                                                    <Trash2 class="size-4" aria-hidden="true" />
                                                </Button>
                                            </div>
                                        </div>
                                        <div class="mt-2">
                                            {@render rowFields(
                                                point,
                                                `steps.${stepIndex}.points.${pointIndex}`,
                                                `point-${stepIndex}-${pointIndex}`,
                                            )}
                                        </div>
                                        {@render videoCell(
                                            point.id,
                                            `point-${stepIndex}-${pointIndex}`,
                                        )}
                                        </div>
                                    </li>
                                {/each}
                                {#if pointDragStep === stepIndex && pointDrag.insertionIndex === step.points.length}
                                    <li class="h-0.5 bg-primary" aria-hidden="true"></li>
                                {/if}
                            </ol>
                        {/if}

                        <div class="mt-4">
                            <Button
                                variant="ghost"
                                size="sm"
                                onclick={() => addPoint(step.clientKey)}
                                testId="step-{stepIndex}-add-point"
                            >
                                <Plus class="size-4" aria-hidden="true" />
                                急所を追加
                            </Button>
                        </div>
                    </Card>
                    </div>
                </li>
            {/each}
            {#if stepDrag.insertionIndex === steps.length}
                <li class="h-0.5 bg-primary" aria-hidden="true"></li>
            {/if}
        </ol>

        <div class="mt-4">
            <Button variant="neutral" onclick={addStep} testId="scenario-add-step">
                <Plus class="size-4" aria-hidden="true" />
                手順を追加
            </Button>
        </div>
    {/if}

    <!-- 再取得 CTA (トップレベル snippet として宣言し、必要な場合のみ Alert の action prop へ渡す) -->
    {#snippet reloadAction()}
        <Button
            variant="neutral"
            size="sm"
            onclick={() => (confirmingReload = true)}
            testId="scenario-conflict-reload"
        >
            サーバの最新を取得
        </Button>
    {/snippet}

    {#if failureView}
        <!-- 操作点 (シナリオを更新) 直上の失敗フィードバック。tabindex=-1 で programmatic focus を受ける -->
        <div
            class="mt-6 focus:outline-none"
            bind:this={failureEl}
            tabindex="-1"
            data-testid="scenario-failure-region"
        >
            <Alert
                type={failureView.type}
                title={failureView.title}
                action={failureView.showReloadCta ? reloadAction : undefined}
                testId={failureView.testId}
            >
                <p>{failureView.message}</p>
            </Alert>
        </div>
    {/if}

    <div class="mt-6 flex flex-wrap items-center gap-2">
        <Button onclick={save} loading={saving} testId="scenario-submit">シナリオを更新</Button>
        <Button
            variant="neutral"
            size="sm"
            onclick={undo}
            disabled={!canUndo}
            testId="scenario-undo"
        >
            <Undo2 class="size-4" aria-hidden="true" />
            元に戻す
        </Button>
        <Button
            variant="neutral"
            size="sm"
            onclick={redo}
            disabled={!canRedo}
            testId="scenario-redo"
        >
            <Redo2 class="size-4" aria-hidden="true" />
            やり直す
        </Button>
        {#if dirty}
            <span class="text-caption text-text-secondary" data-testid="scenario-dirty-indicator">
                未保存の変更があります
            </span>
        {:else if justSaved}
            <span
                class="flex items-center gap-1 text-caption text-success"
                data-testid="scenario-saved-indicator"
            >
                <Check class="size-4" aria-hidden="true" />
                保存しました
            </span>
        {/if}
    </div>
</section>

<ConfirmDialog
    open={confirmingStepKey !== null}
    title="手順を削除しますか?"
    message="この手順を削除すると、配下の急所と登録済みのテイク (撮影動画) も一緒に削除されます。この操作は「シナリオを更新」で保存すると元に戻せません。"
    confirmLabel="削除する"
    confirmVariant="danger"
    onConfirm={() => confirmingStepKey !== null && removeStep(confirmingStepKey)}
    onCancel={() => (confirmingStepKey = null)}
    testId="scenario-step-remove-dialog"
/>

<ConfirmDialog
    bind:open={confirmingReload}
    title="サーバの最新を取得しますか?"
    message="現在編集中の内容は破棄され、サーバに保存されている最新のシナリオに置き換わります。"
    confirmLabel="破棄して最新を取得"
    confirmVariant="danger"
    onConfirm={reloadScenario}
    testId="scenario-reload-dialog"
/>
