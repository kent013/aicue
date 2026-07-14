# 詳細設計レビュー Round 3 — 対応報告

Round 2 の指摘（施策3b APPROVE、他 REQUEST_CHANGES）に全対応しました。判定をお願いします。

## [Critical 施策3] `toDraftSteps()` 二重呼び出しで初期 dirty=true
→ 初期化を単一生成に修正（新設 3-1d）:
```ts
const initialSteps = toDraftSteps(scenario.steps);
let steps = $state<DraftStep[]>(initialSteps);
let snapshot = $state(serializeSteps(initialSteps));
```
同一 clientKey から steps/snapshot を作るため初期 dirty=false。

## [Warning 施策1/2] clientKey 重複・空文字を許容
→ (a) `isSerializedRow` で `clientKey.length > 0`（空文字拒否）。
(b) `parseHistorySnapshot` で復元対象全体（step + 全 point）の clientKey 一意性を `Set` で検証し
重複時 null。util テストに空文字拒否・step/point/交差の重複拒否を追加。

## [Critical 施策4] 初期表示 dirty 無し・Undo/Redo disabled のテスト
→ render 直後に `scenario-dirty-indicator` 非表示 + Undo/Redo disabled を検証（二重採番の回帰検出）。

## [Warning 施策3/4] payload に clientKey 混入なしのテスト
→ 保存後 PUT body の各 step/point に `clientKey` プロパティが存在しないことを検証。

## [Warning 施策4] vi.mock hoist の他テスト波及
→ `vi.hoisted` + `importOriginal` の partial mock で実 export 保持・既定は real 委譲、
fail-safe のみ `mockReturnValueOnce(null)`、`beforeEach` で既定再設定。設計に snippet 明記。

## [Suggestion 施策3] clientKeySeq のコメント
→「インスタンス内カウンタ（instance script 宣言）」に修正。

## 反論が承認された項目（relatedTarget）
→ フィールド単位粒度で現状維持（Codex R2 で妥当と承認済み）。

## 変更した詳細設計の該当セクション（抜粋）

## 施策 1: 履歴 util（サイズ管理 + 防御的デコーダ + Serialized 型）

### 変更箇所
- ファイル: `resources/js/lib/manual/scenario-history.ts`（**新規**）

### 波及変更
- TypeScript 型定義: `DraftStep`/`DraftPoint`（`@/types/manual`）を **type-only import** して
  `SerializedRow`/`SerializedStep` を定義（値依存なし）
- API Resource/DTO: なし
- テストファイル: 施策 2 で新規追加

### 設計意図
- サイズ管理（Codex 概念 R2 観点5）を純関数として単体テスト可能にする。
- **防御的デコーダ**（Codex 設計 R1 Warning）も util の純関数 `parseHistorySnapshot` に集約し、
  `vi.mock` で component 側 fail-safe をテスト可能にする（施策 4）。
- 履歴 1 エントリ = `serializeSteps()` の正規化 JSON 文字列（`clientKey` + id + rowOf 8 フィールド
  + points）。両スタックに同じ上限ロジックを適用する。

### 追加コード
```ts
// resources/js/lib/manual/scenario-history.ts
import type { DraftPoint, DraftStep } from "@/types/manual";

/**
 * シナリオ編集の undo/redo 履歴ユーティリティ(純関数)。
 * 1 エントリ = ScenarioEditor.serializeSteps() の正規化 JSON 文字列
 * (clientKey + id + 本文 8 フィールド + points)。
 * サイズ上限は件数と総文字数の二本立て (メモリ非有界化の防止)。
 */

/** 履歴の最大エントリ数 */
export const MAX_HISTORY_ENTRIES = 100;
/** 履歴の総文字数ソフト上限 (≈ 数 MB。単一エントリが超えても保持する) */
export const MAX_HISTORY_CHARS = 2_000_000;

/** serializeSteps が出力する行 shape (DraftPoint と構造一致。id は number|null) */
export type SerializedRow = DraftPoint;
/** serializeSteps が出力する step shape (DraftStep と構造一致) */
export type SerializedStep = DraftStep;

/**
 * スタック(古→新)を上限内に収める(破壊的 in-place。同一参照を返す)。
 * 先頭(最古)から捨てるが、length>1 を保持し単一エントリでは空にしない
 * (= MAX_HISTORY_CHARS はソフト上限)。
 */
export function boundHistory(
    stack: string[],
    maxEntries: number = MAX_HISTORY_ENTRIES,
    maxChars: number = MAX_HISTORY_CHARS,
): string[] {
    let chars = stack.reduce((total, entry) => total + entry.length, 0);
    while (stack.length > 1 && (stack.length > maxEntries || chars > maxChars)) {
        const removed = stack.shift();
        if (removed === undefined) break;
        chars -= removed.length;
    }
    return stack;
}

/**
 * before が current と異なるときのみ before を stack に積み、上限を適用する。
 * 積んだら true(呼び出し側は true のとき redo スタックをクリアする)。
 */
export function pushHistory(stack: string[], before: string, current: string): boolean {
    if (before === current) return false;
    stack.push(before);
    boundHistory(stack);
    return true;
}

/** 履歴 1 行 (clientKey + id + 本文 8 フィールド) の type predicate */
function isSerializedRow(value: unknown): value is SerializedRow {
    if (value === null || typeof value !== "object") return false;
    const r = value as Record<string, unknown>;
    return (
        typeof r.clientKey === "string" &&
        r.clientKey.length > 0 && // 空文字 clientKey は keyed each を壊すため拒否
        (r.id === null || typeof r.id === "number") &&
        typeof r.scene === "string" &&
        (r.shot_type === "hiki" || r.shot_type === "yori") &&
        (r.shooting_point === null || typeof r.shooting_point === "string") &&
        typeof r.narration === "string" &&
        (r.subtitle_primary === null || typeof r.subtitle_primary === "string") &&
        typeof r.subtitle_secondary === "string" &&
        (r.material_type === null || r.material_type === "video" || r.material_type === "still") &&
        (r.static_display_seconds === null || typeof r.static_display_seconds === "number")
    );
}

/** step (row + points 配列) の type predicate */
function isSerializedStep(value: unknown): value is SerializedStep {
    if (!isSerializedRow(value)) return false;
    const points = (value as { points?: unknown }).points; // 未知プロパティ読取の局所 cast
    return Array.isArray(points) && points.every(isSerializedRow);
}

/**
 * 履歴文字列 → 検証済み SerializedStep[] の防御的デコーダ。
 * JSON 破損・shape 不一致は null(呼び出し側 fail-safe が履歴を破棄)。
 * 素の型アサーションをデータ経路に残さない。
 */
export function parseHistorySnapshot(serialized: string): SerializedStep[] | null {
    let parsed: unknown;
    try {
        parsed = JSON.parse(serialized);
    } catch {
        return null;
    }
    if (!Array.isArray(parsed)) return null;
    const steps: SerializedStep[] = [];
    const keys = new Set<string>();
    for (const step of parsed) {
        if (!isSerializedStep(step)) return null;
        // clientKey は復元対象全体 (step + 全 point) で一意。重複は keyed each を壊すため拒否
        if (keys.has(step.clientKey)) return null;
        keys.add(step.clientKey);
        for (const point of step.points) {
            if (keys.has(point.clientKey)) return null;
            keys.add(point.clientKey);
        }
        steps.push(step);
    }
    return steps;
}
```

### TS/型安全チェック
- [x] 引数・戻り値の型が明示（`string[]` / `boolean` / `SerializedStep[] | null`）
- [x] `parseHistorySnapshot` は `unknown → type predicate → 返却`（データ経路にアサーションなし。
  points 読取の局所 cast は既存 `isScenarioDocument` と同水準）
- [x] `SerializedRow = DraftPoint` / `SerializedStep = DraftStep`（type-only import。値依存なし）
- [x] 破壊的操作である旨を JSDoc に明記（`boundHistory`/`pushHistory` は in-place）
- [x] DOM/グローバル非依存の純関数

### テスト計画
施策 2 参照。

### リスク
- `boundHistory`/`pushHistory` が in-place のため、呼び出し側が同一参照を保持している前提。
  ScenarioEditor 側は `$state` 配列を渡し、`push`/`shift` は Svelte proxy が追跡するため
  reactivity は保たれる（reassign 不要）。

---

## 施策 2: util の単体テスト

### 変更箇所
- ファイル: `tests/js/lib/manual/scenario-history.test.ts`（**新規**）

### テスト計画（Vitest / fail-first）
- [ ] `pushHistory`: before ≠ current で push し true / before == current で no-op false
- [ ] `pushHistory` 後に redo クリアは**呼び出し側責務**（util は関知しない）ことを明示するコメント
- [ ] `boundHistory`: 件数上限超過で最古から打ち切り（`maxEntries` 小値で検証）
- [ ] `boundHistory`: 総文字数上限超過で最古から打ち切り（`maxChars` 小値で検証）
- [ ] `boundHistory`: **件数超過かつ文字数超過**の複合ケース（while 条件の回帰検出。Codex R1）
- [ ] `boundHistory`: **単一巨大エントリは空にしない**（length=1 は上限超でも保持）
- [ ] `boundHistory`: 上限内なら変化なし
- [ ] `parseHistorySnapshot`: 正常な serialize 文字列 → `SerializedStep[]`（clientKey/points 保持）
- [ ] `parseHistorySnapshot`: 不正 JSON（`"{"` 等）→ `null`
- [ ] `parseHistorySnapshot`: 非配列（`"{}"`）→ `null`
- [ ] `parseHistorySnapshot`: 必須欠落（`scene` 欠落 / `clientKey` 欠落 / `points` 非配列）→ `null`
- [ ] `parseHistorySnapshot`: `clientKey` が空文字 → `null`
- [ ] `parseHistorySnapshot`: clientKey 重複（step 同士 / point 同士 / step×point）→ `null`

### リスク
なし（純関数）。

---

## 施策 3b: `DraftStep`/`DraftPoint` に `clientKey` を追加

### 変更箇所
- ファイル: `resources/js/types/manual.ts`（`DraftPoint`/`DraftStep` 定義）

### 設計意図（Codex 設計 R1 Critical）
安定 each key 用のクライアント専用識別子。`{#each steps as step (step.clientKey)}` で
undo/redo の `steps` 再代入時も不変行の DOM を保持し、変化行のみパッチする。

### 現行コード
```ts
/** 編集中の作業コピー (未保存行は id: null)。PUT payload の steps はこの型を直列化する */
export type DraftPoint = Omit<ScenarioPoint, "id"> & { id: number | null };
export type DraftStep = Omit<ScenarioStep, "id" | "points"> & {
    id: number | null;
    points: DraftPoint[];
};
```

### 変更後コード
```ts
/**
 * 編集中の作業コピー (未保存行は id: null)。
 * clientKey は each の安定 key 用のクライアント専用識別子。
 * serializeSteps() には含めるが PUT payload (payloadSteps) には含めない (サーバ非公開)。
 */
export type DraftPoint = Omit<ScenarioPoint, "id"> & { id: number | null; clientKey: string };
export type DraftStep = Omit<ScenarioStep, "id" | "points"> & {
    id: number | null;
    clientKey: string;
    points: DraftPoint[];
};
```

### 波及変更
- 構築箇所: ScenarioEditor の `toDraftSteps` / `emptyRow` のみ（施策 3 で対応）。
- テスト: `ScenarioEditor.test.ts` の `makeDocument()` は**サーバ shape**（`ScenarioDocument` /
  `ScenarioStep`）を作るため clientKey 不要（Draft 型ではない）→ 既存テスト不変。

### TS/型安全チェック
- [x] `clientKey: string`（必須）。全構築箇所で採番される（施策 3 で担保）

### リスク
- `payloadSteps` に clientKey を混入させると保護キー扱いになりうる。→ `payloadSteps` は
  従来どおり `{ id, ...rowOf, points }` のみ（clientKey 不追加）を施策 3 のチェックで担保。

---

## 施策 3: ScenarioEditor に undo/redo 実装

### 変更箇所
- ファイル: `resources/js/components/features/manual/ScenarioEditor.svelte`
  - `<script>`: import 追加、状態追加、コミット点/IME/キーボード/undo/redo ロジック追加、
    既存 `reseed()` に `resetHistory()` を追加、既存の構造操作関数(add/remove/move)を
    履歴コミット経由に置換。
  - template: `<section>` に focus/composition ハンドラ、操作領域に Undo/Redo ボタン追加。

### 波及変更
- TypeScript 型定義: なし（既存 `DraftStep`/`DraftPoint` を再利用。新規 export 型なし）
- API Resource/DTO: なし
- Inertia Props: なし（`Props` インターフェース不変）
- テストファイル: 施策 4 で追加

### 3-1. import 追加
```ts
import { Check, ChevronDown, ChevronUp, ListPlus, Plus, Redo2, Trash2, Undo2 } from "@lucide/svelte";
import { boundHistory, parseHistorySnapshot, pushHistory } from "@/lib/manual/scenario-history";
```

### 3-1b. clientKey 採番と既存 seed 関数の更新（安定 key。施策 3b と対）
```ts
// インスタンス内カウンタ (instance script 宣言 = コンポーネントインスタンスごとに独立)。
// 採番値は履歴文字列に保存され undo/redo で round-trip する。
let clientKeySeq = 0;
function nextClientKey(): string {
    clientKeySeq += 1;
    return `ck-${clientKeySeq}`;
}

// toDraftSteps: 各行に clientKey を採番
function toDraftSteps(steps: ScenarioStep[]): DraftStep[] {
    return steps.map((step) => ({
        ...rowOf(step),
        id: step.id,
        clientKey: nextClientKey(),
        points: step.points.map((point) => ({ ...rowOf(point), id: point.id, clientKey: nextClientKey() })),
    }));
}

// emptyRow: clientKey を採番 (addStep/addPoint はこの戻りを spread する)
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
```
> `rowOf` は本文 8 フィールドのみを返す正規化関数のため **clientKey を含めない**（変更なし）。
> clientKey は各構築箇所で明示採番する。

### 3-1c. serializeSteps に clientKey を含める（payloadSteps は不変）
```ts
// serializeSteps: 履歴/dirty 比較の正規形。clientKey を含め undo/redo で round-trip させる
function serializeSteps(list: DraftStep[]): string {
    return JSON.stringify(
        list.map((step) => ({
            clientKey: step.clientKey,
            id: step.id,
            ...rowOf(step),
            points: step.points.map((point) => ({ clientKey: point.clientKey, id: point.id, ...rowOf(point) })),
        })),
    );
}
```
> **`payloadSteps`（PUT body）は変更しない**（`{ id, ...rowOf, points:{id,...rowOf} }` のまま。
> clientKey を混入させない = サーバ保護キー混入を防ぐ）。dirty 判定は clientKey 込みでも整合:
> reseed 後 snapshot の clientKey と、履歴に保存された同一 clientKey が undo で復元され一致する。

### 3-1d. 初期化を単一 `toDraftSteps` に修正（Codex R2 Critical）
現行は `steps` と `snapshot` で `toDraftSteps()` を**2 回**呼ぶ。clientKey 採番後は各回で
異なるキーが振られ `serializeSteps(steps) !== snapshot` となり**初期表示から dirty=true**に
なってしまう（離脱警告・保存済み表示が後退）。作業コピーを一度だけ生成し snapshot も同一値から作る:

現行:
```ts
let steps = $state<DraftStep[]>(toDraftSteps(scenario.steps));
let snapshot = $state(serializeSteps(toDraftSteps(scenario.steps)));
```
変更後:
```ts
// svelte-ignore state_referenced_locally
const initialSteps = toDraftSteps(scenario.steps); // 1 回だけ生成 (clientKey 一貫性)
// svelte-ignore state_referenced_locally
let steps = $state<DraftStep[]>(initialSteps);
// svelte-ignore state_referenced_locally
let snapshot = $state(serializeSteps(initialSteps)); // 同一 clientKey から snapshot
```
> `version` の seed（`scenario.scenario_version`）は現行どおり。`initialSteps` は seed 専用の
> ローカル定数で、以後 `steps`（$state proxy）を編集する（`initialSteps` 参照は保持しない）。

### 3-2. 状態追加（既存 `let errors = ...` 付近）
```ts
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

const canUndo = $derived(
    undoStack.length > 0 ||
    (editBaseline !== null && editBaseline !== serializeSteps(steps)),
);
const canRedo = $derived(redoStack.length > 0);
```

### 3-3. 履歴コア関数
```ts
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
        points: step.points.map((point) => ({ ...rowOf(point), id: point.id, clientKey: point.clientKey })),
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
```

### 3-4. focus / composition ハンドラ
```ts
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
// 粒度=フィールド単位 (1 フィールドの編集 = 1 履歴エントリ)。これは意図した設計。
// relatedTarget を見た「編集可能→編集可能の coalesce」は採らない (Codex R1 反論):
//   coalesce すると 1 回の undo で複数フィールドの編集が消え粗くなる。
//   値を変えないフォーカス巡回は pushHistory(before===current) が no-op のため履歴を汚さない。
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
```

### 3-5. キーボードショートカット（既存 beforeunload $effect とは別 $effect）
```ts
$effect(() => {
    const onKeydown = (event: KeyboardEvent): void => {
        if (event.isComposing) return; // IME 変換中は無視
        if (!(event.metaKey || event.ctrlKey) || event.key.toLowerCase() !== "z") return;
        if (saving || confirmingStepIndex !== null || confirmingReload) return;
        // 編集フィールドに focus がある間は native の文字単位 undo に委ねる (R1 決定)
        if (isEditableField(document.activeElement)) return;
        event.preventDefault();
        if (event.shiftKey) redo();
        else undo();
    };
    window.addEventListener("keydown", onKeydown);
    return () => window.removeEventListener("keydown", onKeydown);
});
```

### 3-6. 既存の構造操作関数を履歴コミット経由に置換
```ts
function addStep(): void {
    runSettled(() =>
        commitStructural(() => steps.push({ ...emptyRow("hiki"), id: null, points: [] })),
    );
}
function addPoint(stepIndex: number): void {
    runSettled(() =>
        commitStructural(() => steps[stepIndex].points.push({ ...emptyRow("yori"), id: null })),
    );
}
function removeStep(index: number): void {
    runSettled(() => commitStructural(() => steps.splice(index, 1)));
    confirmingStepIndex = null; // 確認ダイアログを閉じるのは即時 (履歴とは独立)
}
function removePoint(stepIndex: number, pointIndex: number): void {
    runSettled(() => commitStructural(() => steps[stepIndex].points.splice(pointIndex, 1)));
}
function moveStep(index: number, delta: -1 | 1): void {
    const next = index + delta;
    if (next < 0 || next >= steps.length) return; // 境界: 履歴も積まない
    runSettled(() =>
        commitStructural(() => {
            [steps[index], steps[next]] = [steps[next], steps[index]];
        }),
    );
}
function movePoint(stepIndex: number, index: number, delta: -1 | 1): void {
    const points = steps[stepIndex].points;
    const next = index + delta;
    if (next < 0 || next >= points.length) return;
    runSettled(() =>
        commitStructural(() => {
            [points[index], points[next]] = [points[next], points[index]];
        }),
    );
}
```
> 注: `removeStep` の `confirmingStepIndex = null` は `runSettled` の外（ダイアログ開閉は
> composing とは独立の UI 状態）。`commitStructural` 内の `splice` が pending 状態なら
> compositionend 後に実行されるが、ダイアログは即閉じる。実運用で削除ボタン押下と IME 変換が
> 重なるのは稀で、閉じるタイミングの前後は UX 上問題ない。

### 3-7. 既存 `reseed()` に履歴リセットを追加
```ts
function reseed(document: ScenarioDocument): void {
    version = document.scenario_version;
    steps = toDraftSteps(document.steps);
    snapshot = serializeSteps(steps);
    errors = {};
    justSaved = false;
    resetHistory(); // 保存成功/明示リロードで履歴を断つ (保存前ローカル編集のみ対象)
}
```

### 3-8. template: `<section>` にイベント委譲
```svelte
<section
    aria-label="シナリオ編集"
    onfocusin={onEditorFocusIn}
    onfocusout={onEditorFocusOut}
    oncompositionstart={onCompositionStart}
    oncompositionend={onCompositionEnd}
>
```
> `focusin`/`focusout`/`compositionstart`/`compositionend` はいずれもバブリングするため
> section 一箇所への委譲で全セルを拾える（`focus`/`blur` はバブリングしないので使わない）。

### 3-8b. template: each の key を安定 key へ（Codex R1 Critical）
```svelte
{#each steps as step, stepIndex (step.clientKey)}
    ...
    {#each step.points as point, pointIndex (point.clientKey)}
```
> 現行の `(step)` / `(point)`（object identity）を `(step.clientKey)` / `(point.clientKey)` に
> 変更。undo/redo で `steps` を新規オブジェクトに再代入しても、clientKey が一致する不変行は
> Svelte が DOM を保持し、変化した行のみパッチする（全行再生成によるフォーカス/selection の
> 過剰リセットを回避）。構造操作（add/remove/move）も clientKey 追跡で正しく差分描画される。

### 3-9. template: Undo/Redo ボタン（操作領域 = 「シナリオを更新」の行）
```svelte
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
        <span class="flex items-center gap-1 text-caption text-success" data-testid="scenario-saved-indicator">
            <Check class="size-4" aria-hidden="true" />
            保存しました
        </span>
    {/if}
</div>
```

### Undo/Redo ボタンの disabled と禁止事項 8 の整理
- 禁止事項 8 は「**必須条件未充足を理由に**ボタンを disabled にする UI」を禁じる
  （例: 利用規約チェック未了で送信不可。押下時に不足を伝えられないため）。
- Undo/Redo の空スタックは「ユーザが満たすべき不足条件」ではなく、
  「戻る/進む先が存在しない」機能内在の不可用状態（`ConfirmDialog` の processing 中
  X ボタン disabled と同種）。押下しても伝えるべき不足が無い純 no-op のため disabled が適切。
- Button atom は native `<button disabled>` を出力（`disabled:opacity-40 disabled:cursor-not-allowed`）。
  native disabled は aria 的にも無効を伝える。
- **canUndo は pending 編集を含める**（R2 観点2）ため、初回セル編集中でも Undo は活性化し、
  クリック → blur(focusout) → flush → undo が成立する（disabled で詰まない）。

### TS/型安全チェック
- [x] 全関数の戻り値型明示（`void` / `boolean` / `string`）
- [x] 防御的デコードは util `parseHistorySnapshot`（type predicate）。component `restoreFrom` は
  検証済み `SerializedStep[]` を `rowOf` 正規化するのみ（データ経路に素のアサーションなし）
- [x] `editBaseline` は `$state<string | null>`（canUndo の reactivity 確保）
- [x] `clientKey` は全構築箇所（`toDraftSteps`/`emptyRow`）で採番。`serializeSteps` に含め
  `payloadSteps` には含めない
- [x] Lucide アイコン（`Undo2`/`Redo2`）のみ。SVG 直書きなし
- [x] DS token 経由（Button variant / text-caption 等既存 token）。hex 直書きなし
- [x] `isEditableField` は `instanceof HTMLElement` で narrowing

### リスク
- **フォーカス喪失**: undo/redo は `steps` を再代入するが、安定 `clientKey`（施策 3b）により
  Svelte は不変行の DOM を保持し変化行のみパッチする。値が変わった行の入力にフォーカスが
  あれば失われうるが、undo/redo の期待挙動として許容。
- **native undo との差異**: 編集フィールド内では Ctrl/Cmd+Z が native 動作になり、
  フィールド外では document undo になる（設計上の意図。R1 決定）。
- **IME 保留の稀な順序**: `focusout → 構造 click → compositionend` の順でも FIFO 保留で
  「テキスト編集 1 + 構造操作 1」の 2 エントリに落ちる。テストで固定（施策 4）。

---

## 施策 4: ScenarioEditor の Vitest テスト追加

### 変更箇所
- ファイル: `tests/js/components/features/manual/ScenarioEditor.test.ts`（既存へ**追記のみ**）

### テスト計画（fail-first。既存ヘルパ `typeInto` / `makeDocument` / `jsonResponse` を再利用）
- [ ] **初期表示**: render 直後に `scenario-dirty-indicator` が無く、Undo/Redo ボタンが
      disabled（clientKey 二重採番の回帰検出。Codex R2 Critical）
- [ ] **payload 境界**: 編集して保存 → PUT body の各 step/point に `clientKey` プロパティが
      **存在しない**（`lastPutPayload()` で検証。保護キー混入防止）
- [ ] セル編集 → Undo で前状態 → Redo で再適用（`step-0-scene` の値往復）
- [ ] 行追加(`scenario-add-step`) → Undo で消える → Redo で戻る
- [ ] 手順削除（`step-0-remove` → 確認ダイアログ確定）→ Undo で復活（配下急所も復活）
- [ ] 並べ替え（`step-0-move-down`）→ Undo で順序が戻る
- [ ] 複数操作の連続 Undo/Redo（追加→編集→並べ替えを 3 回 Undo で初期状態）
- [ ] 新規編集で redo クリア（Undo 後に別セル編集 → Redo ボタンが disabled）
- [ ] 保存成功（`jsonResponse(200, doc)`）→ reseed 後に Undo ボタン disabled（履歴リセット）
- [ ] 409 競合 → 明示リロード（`router.reload` onSuccess）後に履歴リセット
- [ ] ショートカット: `document.activeElement` が非編集要素（例: body/ボタン）で
      `keydown{ctrlKey,key:"z"}` → Undo 実行。`{metaKey,key:"z"}`（mac）でも Undo 実行
- [ ] ショートカット: 編集フィールド focus 時は preventDefault されず app undo が走らない
      （native 委譲。`isEditableField` 分岐）
- [ ] ショートカット: `keydown{ ..., isComposing:true }` で無視
- [ ] ショートカット: `{ctrlKey,shiftKey,key:"z"}` → Redo
- [ ] `blur → 構造操作(click)` で二重 push しない（1 編集 + 1 構造 = Undo 2 回で初期に戻る）
- [ ] IME 順序 1: `compositionstart` → セル input → `focusout`（composing 中）→
      `compositionend` で 1 エントリに確定（focusout 先行でも中間文字列を積まない）
- [ ] IME 順序 2: `compositionstart` → input → `focusout` → 構造ボタン click → `compositionend`
      で「テキスト編集 1 + 構造操作 1」= Undo 2 回で初期。中間文字列を積まない
- [ ] 復元 fail-safe（**partial mock**）: `@/lib/manual/scenario-history` を
      `importOriginal` で partial mock し、`parseHistorySnapshot` のみ `vi.hoisted` の mock fn に
      差し替え（既定は real 実装へ委譲）。fail-safe テストのみ `mockReturnValueOnce(null)` →
      Undo で「steps 非破壊 + 履歴リセット（Undo/Redo disabled）+ warning toast（`toasts` 検証）」。
      `beforeEach` で既定実装を再設定し他テストへ波及させない（Codex R2 Warning）
- [ ] canUndo（pending 編集）: 初回セル編集の focus 中（focusout 前）に Undo ボタンが活性
      （`fireEvent.focusIn` → `input` 後、`focusOut` を発火せずに Undo ボタン `not.toBeDisabled()`）
- [ ] reactivity: 各操作直後（`push`/`splice`/swap 後）に Undo/Redo ボタン活性・dirty
      インジケータが即時反映される（Svelte 5 array mutation 追跡の担保。Codex R1）
- [ ] Undo で snapshot に一致まで戻すと `scenario-dirty-indicator` が消える（離脱警告解除）

> テスト実装メモ:
> - `fireEvent.focusIn` / `fireEvent.focusOut` / `fireEvent.compositionStart` /
>   `fireEvent.compositionEnd` で focus/IME 遷移を模す。
> - キーボードは `fireEvent.keyDown(window, { ctrlKey: true, key: "z" })`。
>   native 委譲分岐は `document.activeElement` を編集要素にした状態で
>   `preventDefault` が呼ばれないことを spy で確認。
> - Undo/Redo ボタンの活性は `screen.getByTestId("scenario-undo")` の
>   `toBeDisabled()` / `not.toBeDisabled()` で確認。
> - fail-safe の partial mock（他テスト非波及）:
>   ```ts
>   const { parseHistorySnapshotMock } = vi.hoisted(() => ({ parseHistorySnapshotMock: vi.fn() }));
>   vi.mock("@/lib/manual/scenario-history", async (importOriginal) => {
>       const actual = await importOriginal<typeof import("@/lib/manual/scenario-history")>();
>       parseHistorySnapshotMock.mockImplementation(actual.parseHistorySnapshot); // 既定 = real
>       return { ...actual, parseHistorySnapshot: parseHistorySnapshotMock };
>   });
>   // beforeEach: 既定 real 実装を再設定（他テストへ波及させない）
>   //   parseHistorySnapshotMock.mockImplementation(actualParse);
>   // fail-safe テスト内: parseHistorySnapshotMock.mockReturnValueOnce(null);
>   ```

### 個別 DatabaseTransactions
- [x] 該当なし（フロント Vitest。DB 非依存）

### リスク
- jsdom の focus/composition イベント順序が実ブラウザと完全一致しない可能性。
  ハンドラは event 種別のみに依存し順序を FIFO 保留で吸収するため、
  `fireEvent` の明示順で決定的に検証できる。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 単一コンポーネント + 新規 util の追加で、既存 API/型/他機能への波及がない。既存テストは追記のみで不変。小さく閉じた変更のため incremental が適切。 |
| 競合リスク | 低。ScenarioEditor.svelte の他施策との同時編集がなければ衝突しない。util は新規ファイル。 |

## 完了条件
- `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` 全 green。
- 施策 2・4 のテストが追加され、fail-first で赤 → 実装で緑を確認。
- 既存 ScenarioEditor テスト（保存/409/419/dirty 等）が引き続き green。

