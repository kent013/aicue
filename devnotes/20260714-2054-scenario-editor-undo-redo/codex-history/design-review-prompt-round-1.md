# アプリの使命 (North Star) — AGENTS.md より

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」。v1 スコープ: 字幕のみ(TTS 後回し)/撮影は PWA/動画合成は自前 ffmpeg/単一 Default Project。

# 禁止事項 — AGENTS.md より

1. テストなしの実装完了報告 2. PHPStan エラーの widen・baseline 化 3. dev DB への破壊操作 4. `response()->json()` 直書き 5. LLM 呼び出しの Prism 直呼び 6. prompt 文字列のコード直書き 7. 操作系 POST 応答での `redirect()->intended()` 8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示。DESIGN.md)

# 思考原則

まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ(Laravel/Svelte の既存作法)。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

# ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富な Web アプリケーションアーキテクトです。Laravel + Svelte アプリの詳細設計をレビューしてください。

【前提環境】PHP 8.4 / Laravel 12 / Svelte 5 runes / Inertia.js / TypeScript / PHPStan L10 / Pest / Vitest。本施策は**フロントエンドのみ**(サーバ API/DTO/ルート/PHP 不変)。

【この設計の要点】
シナリオ編集コンポーネント `ScenarioEditor.svelte` のクライアント作業コピー `steps: DraftStep[]`($state)に対する undo/redo を実装する。履歴 1 エントリ = 既存 `serializeSteps()` の正規化 JSON 文字列(スナップショット方式)。保存前ローカル編集のみ対象・サーバ状態不変・保存/リロードで履歴リセット。概念設計は Codex(gpt-5.4)レビューで APPROVED 済み。

【概念レビューで既に合意した設計判断(蒸し返し不要。ただし詳細実装での破綻があれば指摘可)】
- キーボード Ctrl/Cmd+Z は編集フィールド focus 中は native undo に委ね、フィールド外でのみ app undo。
- IME: コミット誘発の全経路(flushPendingEdit / 構造操作 / undo / redo)を composing gate 化し、compositionend 後に flushDeferred→pendingActions(FIFO) の順で実行。
- サイズ上限は件数+総文字数(ソフト上限)を両スタックに適用する純関数 boundHistory。
- Undo/Redo の空スタック disabled は禁止事項 8 の対象外(機能内在の不可用状態)。canUndo は pending 編集を含める。
- parseHistory は unknown→型ガード→rowOf 正規化の防御的デコーダ。

【レビュー観点】
1. コードの正確性(ロジックエラー、エッジケース、Svelte 5 runes reactivity: $state proxy の array mutation 追跡 / $derived の依存追跡)
2. 既存コードとの整合性(命名・パターン・serializeSteps/rowOf/reseed の再利用)
3. 型安全性(TypeScript strict。parseHistory の narrowing、素のアサーション残存)
4. テスト計画の網羅性(fail-first。undo/redo/redo クリア/保存後リセット/ショートカット/IME 順序/二重 push 防止)
5. 副作用・後退リスク(既存 dirty/beforeunload/保存/409/419 経路を壊さないか)
6. 波及変更の網羅性(今回は型/API/テスト。フロント限定で漏れがないか)
7. Atomic Design / DESIGN.md 準拠(Lucide のみ、DS token、util の配置層、Button disabled)
8. runSettled / commitStructural / flushPendingEdit の相互作用に隠れた不整合がないか(特に doUndo/doRedo 内の flushPendingEdit と redo クリアの意味論)

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: scenario-editor-undo-redo

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。
- **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA / 動画合成は自前 ffmpeg / 単一 Default Project。

本施策は `doc/04_PCサイト機能仕様.md` L42 の確定要件「Undo / Redo(一つ戻る/一つ進む)」を
満たす**編集 UX**であり v1 対象。TTS/PWA/合成など他スコープには一切触れない。

### 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示。DESIGN.md)

→ 本施策は**フロントエンドのみ**(サーバ API/DTO/ルート/PHP 不変)のため 2〜7 は非該当。
　禁止事項 8 との関係は「Undo/Redo ボタンの disabled」節で個別に整理する。

### コーディングルール（フロント該当分）

- Svelte 5 runes（`$state` / `$derived` / `$effect`）。DS token / DESIGN.md 準拠、hex 直書き禁止。
- アイコンは `@lucide/svelte` のみ。SVG 直書き禁止。
- component 階層は単方向 import（`atoms → molecules → organisms → features → templates → pages`）。
  今回追加する純関数 util は `resources/js/lib/` 配下（component 層ではないため階層規約の対象外）。
- 型安全（TypeScript strict）。`unknown → 型ガード → 正規化` で外部/永続データを扱う。
- 検証コマンド: `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`（全 green）。
- テストは Vitest。既存テストを削除・上書きしない（追記のみ）。

## 概念設計リファレンス

- `devnotes/20260714-2054-scenario-editor-undo-redo/conceptual-design.md`（APPROVED / Round 4）
- 概念レビュー: `conceptual-review-round-{1..4}.md`（Round 4 = APPROVED）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 履歴スタック純関数 util の新規追加 | `resources/js/lib/manual/scenario-history.ts`（新規） | High |
| 2 | util の単体テスト | `tests/js/lib/manual/scenario-history.test.ts`（新規） | High |
| 3 | ScenarioEditor に undo/redo 実装（状態・コミット点・IME gate・キーボード・UI） | `resources/js/components/features/manual/ScenarioEditor.svelte` | High |
| 4 | ScenarioEditor の Vitest テスト追加 | `tests/js/components/features/manual/ScenarioEditor.test.ts` | High |

波及なし（サーバ API / DTO / ルート / TypeScript props 型 / PHP は不変）。

---

## 施策 1: 履歴スタック純関数 util

### 変更箇所
- ファイル: `resources/js/lib/manual/scenario-history.ts`（**新規**）

### 波及変更
- TypeScript 型定義: なし（`string[]` のみを扱う純関数。DraftStep 等に依存しない）
- API Resource/DTO: なし
- テストファイル: 施策 2 で新規追加

### 設計意図
サイズ管理（Codex R2 観点5）を**純関数**として単体テスト可能にする。履歴 1 エントリは
`serializeSteps()` の正規化 JSON 文字列。undo/redo 両スタックに同じ上限ロジックを適用する。

### 追加コード
```ts
// resources/js/lib/manual/scenario-history.ts
/**
 * シナリオ編集の undo/redo 履歴スタック操作(純関数)。
 * 1 エントリ = ScenarioEditor.serializeSteps() の正規化 JSON 文字列。
 * サイズ上限は件数と総文字数の二本立て (メモリ非有界化の防止)。
 */

/** 履歴の最大エントリ数 */
export const MAX_HISTORY_ENTRIES = 100;
/** 履歴の総文字数ソフト上限 (≈ 数 MB。単一エントリが超えても保持する) */
export const MAX_HISTORY_CHARS = 2_000_000;

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
```

### TS/型安全チェック
- [x] 引数・戻り値の型が明示（`string[]` / `boolean`）
- [x] `unknown` を扱わない（呼び出し側が正規化文字列のみ渡す）
- [x] 破壊的操作である旨を JSDoc に明記（in-place）
- [x] 純関数（副作用は引数配列の変更のみ・DOM/グローバル非依存）

### テスト計画
施策 2 参照。

### リスク
- `boundHistory` が in-place のため、呼び出し側が同一参照を保持している前提。
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
- [ ] `boundHistory`: **単一巨大エントリは空にしない**（length=1 は上限超でも保持）
- [ ] `boundHistory`: 上限内なら変化なし

### リスク
なし（純関数）。

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
import { boundHistory, pushHistory } from "@/lib/manual/scenario-history";
```

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

/** シリアライズ文字列 → DraftStep[] の防御的デコーダ (壊れていれば null) */
function parseHistory(serialized: string): DraftStep[] | null {
    let parsed: unknown;
    try {
        parsed = JSON.parse(serialized);
    } catch {
        return null;
    }
    if (!Array.isArray(parsed)) return null;
    const result: DraftStep[] = [];
    for (const step of parsed) {
        if (!isSerializedRow(step)) return null;
        const points = (step as { points?: unknown }).points;
        if (!Array.isArray(points) || !points.every(isSerializedRow)) return null;
        result.push({
            ...rowOf(step as DraftPoint),
            id: (step as DraftPoint).id,
            points: (points as DraftPoint[]).map((point) => ({ ...rowOf(point), id: point.id })),
        });
    }
    return result;
}

/** 履歴 1 行 (rowOf の 8 フィールド + id: number|null) の実行時検証 */
function isSerializedRow(value: unknown): boolean {
    if (value === null || typeof value !== "object") return false;
    const r = value as Record<string, unknown>;
    return (
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

function undo(): void {
    runSettled(doUndo);
}
function redo(): void {
    runSettled(doRedo);
}

function doUndo(): void {
    flushPendingEdit(); // 進行中のテキスト編集を先に 1 エントリ確定
    if (undoStack.length === 0) return;
    const restored = parseHistory(undoStack[undoStack.length - 1]);
    if (restored === null) {
        resetHistory();
        addToast("warning", "編集履歴を復元できませんでした");
        return; // fail-safe: steps は変えない
    }
    undoStack.pop();
    redoStack.push(serializeSteps(steps)); // 現在を redo へ退避
    boundHistory(redoStack);
    steps = restored;
    editBaseline = null;
}

function doRedo(): void {
    flushPendingEdit(); // pending 編集があれば「新規編集」= redo クリア (この後 length 0 で no-op)
    if (redoStack.length === 0) return;
    const restored = parseHistory(redoStack[redoStack.length - 1]);
    if (restored === null) {
        resetHistory();
        addToast("warning", "編集履歴を復元できませんでした");
        return;
    }
    redoStack.pop();
    undoStack.push(serializeSteps(steps));
    boundHistory(undoStack);
    steps = restored;
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
- [x] 全関数の戻り値型明示（`void` / `DraftStep[] | null` / `boolean`）
- [x] `parseHistory` は `unknown → isSerializedRow 検証 → rowOf 正規化`（素のアサーション残さず）
- [x] `editBaseline` は `$state<string | null>`（canUndo の reactivity 確保）
- [x] Lucide アイコン（`Undo2`/`Redo2`）のみ。SVG 直書きなし
- [x] DS token 経由（Button variant / text-caption 等既存 token）。hex 直書きなし
- [x] `isEditableField` は `instanceof HTMLElement` で narrowing

### リスク
- **フォーカス喪失**: undo/redo は `steps` を再代入し `{#each steps as step (step)}` が
  identity キーで全再描画するため、編集中セルの DOM フォーカスは失われる。undo/redo の
  期待挙動として許容。
- **native undo との差異**: 編集フィールド内では Ctrl/Cmd+Z が native 動作になり、
  フィールド外では document undo になる（設計上の意図。R1 決定）。
- **IME 保留の稀な順序**: `focusout → 構造 click → compositionend` の順でも FIFO 保留で
  「テキスト編集 1 + 構造操作 1」の 2 エントリに落ちる。テストで固定（施策 4）。

---

## 施策 4: ScenarioEditor の Vitest テスト追加

### 変更箇所
- ファイル: `tests/js/components/features/manual/ScenarioEditor.test.ts`（既存へ**追記のみ**）

### テスト計画（fail-first。既存ヘルパ `typeInto` / `makeDocument` / `jsonResponse` を再利用）
- [ ] セル編集 → Undo で前状態 → Redo で再適用（`step-0-scene` の値往復）
- [ ] 行追加(`scenario-add-step`) → Undo で消える → Redo で戻る
- [ ] 手順削除（`step-0-remove` → 確認ダイアログ確定）→ Undo で復活（配下急所も復活）
- [ ] 並べ替え（`step-0-move-down`）→ Undo で順序が戻る
- [ ] 複数操作の連続 Undo/Redo（追加→編集→並べ替えを 3 回 Undo で初期状態）
- [ ] 新規編集で redo クリア（Undo 後に別セル編集 → Redo ボタンが disabled）
- [ ] 保存成功（`jsonResponse(200, doc)`）→ reseed 後に Undo ボタン disabled（履歴リセット）
- [ ] 409 競合 → 明示リロード（`router.reload` onSuccess）後に履歴リセット
- [ ] ショートカット: `document.activeElement` が非編集要素（例: body/ボタン）で
      `keydown{ctrlKey,key:"z"}` → Undo 実行。編集フィールド focus 時は preventDefault されず
      app undo が走らない（native 委譲）
- [ ] ショートカット: `keydown{ ..., isComposing:true }` で無視
- [ ] `blur → 構造操作(click)` で二重 push しない（1 編集 + 1 構造 = Undo 2 回で初期に戻る）
- [ ] IME 順序: `compositionstart` → セル input → `focusout`（composing 中）→ 構造ボタン click →
      `compositionend` で「テキスト編集 1 + 構造操作 1」= Undo 2 回で初期。中間文字列を積まない
- [ ] 復元 fail-safe: 履歴に壊れた文字列が入った状況を作れないため、
      **`parseHistory` 相当の防御は施策 2 の util 側では扱わない**ため、ここでは
      「JSON 破損しても steps を破壊しない」ことを `parseHistory` の分岐で担保する設計とし、
      実挙動テストが困難な場合は最低限「不正 JSON を返す stub 経路が無い」ことを確認
      （※ parseHistory は内部生成文字列のみ受けるため通常不到達。fail-safe はコード保全目的）
- [ ] canUndo（pending 編集）: 初回セル編集の focus 中（focusout 前）に Undo ボタンが活性
- [ ] Undo で snapshot に一致まで戻すと `scenario-dirty-indicator` が消える（離脱警告解除）

> テスト実装メモ:
> - `fireEvent.focusIn` / `fireEvent.focusOut` / `fireEvent.compositionStart` /
>   `fireEvent.compositionEnd` で focus/IME 遷移を模す。
> - キーボードは `fireEvent.keyDown(window, { ctrlKey: true, key: "z" })`。
>   native 委譲分岐は `document.activeElement` を編集要素にした状態で
>   `preventDefault` が呼ばれないことを spy で確認。
> - Undo/Redo ボタンの活性は `screen.getByTestId("scenario-undo")` の
>   `toBeDisabled()` / `not.toBeDisabled()` で確認。

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


---

## 関連する現行コード（ScenarioEditor.svelte 抜粋: 変更対象の既存関数と serialize/rowOf/reseed）

```svelte
    /** サーバ shape → 編集用作業コピー (新しい配列/オブジェクトに clone し props と分離する) */
    function toDraftSteps(steps: ScenarioStep[]): DraftStep[] {
        return steps.map((step) => ({
            ...rowOf(step),
            id: step.id,
            points: step.points.map((point) => ({ ...rowOf(point), id: point.id })),
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

    /** 正規化シリアライザ (キー順固定・payload 対象フィールドのみ)。比較と送信の正規形を一本化する */
    function serializeSteps(list: DraftStep[]): string {
        return JSON.stringify(
            list.map((step) => ({
                id: step.id,
                ...rowOf(step),
                points: step.points.map((point) => ({ id: point.id, ...rowOf(point) })),
            })),
        );
    }

    // 作業コピーは scenario prop から「マウント時に一度だけ」seed する (意図的)。
    // prop 追随で編集中の内容を握り潰さないため。サーバ最新への置換は
    // applySaved (保存成功) / reloadScenario (409 からの明示同意リロード) が reseed で行う。
    // svelte-ignore state_referenced_locally
    let version = $state(scenario.scenario_version);
    // svelte-ignore state_referenced_locally
    let steps = $state<DraftStep[]>(toDraftSteps(scenario.steps));
    /** 保存済みスナップショット (正規形の JSON 文字列。$state proxy と参照を共有しない) */
    // svelte-ignore state_referenced_locally
    let snapshot = $state(serializeSteps(toDraftSteps(scenario.steps)));
    let saving = $state(false);
    // 直近の保存成功をその場に残す (toast の 4s 自動消去に依存しない永続確認)。
    // true にするのは applySaved() のみ。reseed()・save 開始・失敗・dirty 転換で false。
    let justSaved = $state(false);
    let errors = $state<Record<string, string[]>>({});

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
    let confirmingStepIndex = $state<number | null>(null);
    let confirmingReload = $state(false);
    /** 明示同意済みの最新取得中フラグ (dirty 離脱確認を二重に出さない) */
    let reloading = false;

    const dirty = $derived(serializeSteps(steps) !== snapshot);

    // 編集で dirty に転じたら成功確認を消す (level-triggered)。dirty は derived で決定的なため
    // applySaved 直後は dirty=false のままで justSaved=true が保たれる。
    $effect(() => {
        if (dirty) justSaved = false;
    });

    /** 新規行の空値 (scene のみ必須のため空で作る) */
    function emptyRow(shotType: "hiki" | "yori"): Omit<DraftPoint, "id"> {
        return {
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
        steps.push({ ...emptyRow("hiki"), id: null, points: [] });
    }

    function addPoint(stepIndex: number): void {
        steps[stepIndex].points.push({ ...emptyRow("yori"), id: null });
    }

    function removeStep(index: number): void {
        steps.splice(index, 1);
        confirmingStepIndex = null;
    }

    function removePoint(stepIndex: number, pointIndex: number): void {
        steps[stepIndex].points.splice(pointIndex, 1);
    }

    /** ▲▼ 並べ替え (同一スコープ内のみ。階層をまたぐ移動は提供しない) */
    function moveStep(index: number, delta: -1 | 1): void {
        const next = index + delta;
        if (next < 0 || next >= steps.length) return;
        [steps[index], steps[next]] = [steps[next], steps[index]];
    }

    function movePoint(stepIndex: number, index: number, delta: -1 | 1): void {
        const points = steps[stepIndex].points;
        const next = index + delta;
        if (next < 0 || next >= points.length) return;
        [points[index], points[next]] = [points[next], points[index]];
    }
```
