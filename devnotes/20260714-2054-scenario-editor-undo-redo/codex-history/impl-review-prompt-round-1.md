【アプリの使命 — North Star】
AI-CUE は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。本施策 T048 は doc/04 L42 の確定要件「Undo / Redo(一つ戻る/一つ進む)」を満たす編集 UX(保存前のローカル編集対象)。フロントエンドのみ(サーバ API/DTO/ルート/PHP 不変)。

【禁止事項(自分・Codex 双方に適用)】
1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. response()->json() の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での redirect()->intended()
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
→ 本施策はフロントのみのため 2〜7 は非該当。禁止事項 8 は Undo/Redo ボタンの disabled と関連するため後述の観点で判定せよ。

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。想定外のパターンも判断材料になる。
先人の知恵を探せ。Svelte/Laravel エコシステムに既存解があるなら乗れ。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

【あなたの役割: コードレビュアー】
Laravel + Svelte 5 のフロントエンド実装(シナリオ編集の Undo/Redo)をレビューする。以下の観点で厳密に判定せよ:

1. 設計との一致性: 添付の詳細設計書(施策 1〜4)どおりに実装されているか。乖離があれば指摘。
2. 正確性: undo/redo の状態遷移(undoStack/redoStack/editBaseline)、IME ゲート(composing/pendingActions/flushDeferred)、フォーカス/composition イベント委譲、キーボードショートカット、履歴サイズ上限(boundHistory)、防御的デコード(parseHistorySnapshot)にバグがないか。境界条件・二重 push・redo クリア漏れ・メモリ非有界化を精査。
3. TypeScript 型安全: strict。unknown→type predicate→正規化。データ経路に素の型アサーションを残していないか。
4. 保護キー混入防止: clientKey が PUT payload(payloadSteps)に混入していないか(サーバ非公開のクライアント専用キー)。dirty 判定が clientKey 込みで整合するか。
5. テスト網羅性: 施策 2(util 純関数)・施策 4(component)のテストが状態遷移・IME・fail-safe・保護キー境界・reactivity を網羅しているか。既存テストを削除・改変していないか(追記のみか)。
6. 禁止事項 8(disabled UI): Undo/Redo の空スタック disabled が「必須条件未充足による disabled」に該当するか、それとも機能内在の不可用状態(no-op)として許容されるか、設計の整理が妥当か判定せよ。
7. DESIGN.md 準拠: color/radius/typography は token 経由か。hex 直書き(#RRGGBB)を増やしていないか。→ 本 diff は既存 token(Button variant / text-caption / text-success)と Lucide アイコン(Undo2/Redo2)のみ使用、新規 token・hex・SVG 直書きなし。
8. Atomic Design 準拠: 追加した純関数 util は resources/js/lib/ 配下(component 層でないため階層規約対象外)。ScenarioEditor は features/manual(既存)。階層の逆流がないか。

【出力形式】
- ファイルごとに判定を述べる。
- 指摘は Critical / Warning / Suggestion に分類する。
- 最後に全体判定を APPROVED または CHANGES_REQUESTED で明示する。

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
| 1 | 履歴 util（サイズ管理 + 防御的デコーダ + Serialized 型）の新規追加 | `resources/js/lib/manual/scenario-history.ts`（新規） | High |
| 2 | util の単体テスト | `tests/js/lib/manual/scenario-history.test.ts`（新規） | High |
| 3 | ScenarioEditor に undo/redo 実装（状態・コミット点・IME gate・キーボード・UI・安定 key） | `resources/js/components/features/manual/ScenarioEditor.svelte` | High |
| 3b | `DraftStep`/`DraftPoint` に `clientKey` を追加（安定 each key 用） | `resources/js/types/manual.ts` | High |
| 4 | ScenarioEditor の Vitest テスト追加 | `tests/js/components/features/manual/ScenarioEditor.test.ts` | High |

波及: フロント内に閉じる。**唯一のインターフェース変更**は `DraftStep`/`DraftPoint` への
`clientKey: string` 追加（施策 3b）。この Draft 型はクライアント作業コピー専用で、構築箇所は
ScenarioEditor（`toDraftSteps`/`emptyRow`）のみ。PUT payload（`payloadSteps`）には含めないため
**サーバ API / DTO / ルート / Inertia props / PHP は不変**。

---

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
> - fail-safe の partial mock（他テスト非波及。Codex R3 Suggestion 反映: real 実装を
>   hoisted holder に保持し、`mockReset` に依存しない）:
>   ```ts
>   const holder = vi.hoisted(() => ({
>       mock: vi.fn(),
>       real: undefined as undefined | typeof import("@/lib/manual/scenario-history").parseHistorySnapshot,
>   }));
>   vi.mock("@/lib/manual/scenario-history", async (importOriginal) => {
>       const actual = await importOriginal<typeof import("@/lib/manual/scenario-history")>();
>       holder.real = actual.parseHistorySnapshot;               // real を保持
>       holder.mock.mockImplementation(actual.parseHistorySnapshot); // 既定 = real 委譲
>       return { ...actual, parseHistorySnapshot: holder.mock };  // 他 export は実物
>   });
>   beforeEach(() => {
>       if (holder.real) holder.mock.mockImplementation(holder.real); // 毎テスト既定へ復帰
>   });
>   // fail-safe テスト内: holder.mock.mockReturnValueOnce(null); // この 1 回だけ破損扱い
>   ```
>   `mockReturnValueOnce` は一度消費されると既定実装へ戻るため、他テストは real を使う。

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

## 実装差分 (git diff HEAD -- resources/ tests/)

```diff
diff --git a/resources/js/components/features/manual/ScenarioEditor.svelte b/resources/js/components/features/manual/ScenarioEditor.svelte
index 44fd7ab..72fc02b 100644
--- a/resources/js/components/features/manual/ScenarioEditor.svelte
+++ b/resources/js/components/features/manual/ScenarioEditor.svelte
@@ -1,7 +1,16 @@
 <script lang="ts">
     import { tick } from "svelte";
     import { router } from "@inertiajs/svelte";
-    import { Check, ChevronDown, ChevronUp, ListPlus, Plus, Trash2 } from "@lucide/svelte";
+    import {
+        Check,
+        ChevronDown,
+        ChevronUp,
+        ListPlus,
+        Plus,
+        Redo2,
+        Trash2,
+        Undo2,
+    } from "@lucide/svelte";
     import Alert from "@/components/atoms/Alert.svelte";
     import Button from "@/components/atoms/Button.svelte";
     import Card from "@/components/atoms/Card.svelte";
@@ -12,6 +21,7 @@
     import FormField from "@/components/molecules/FormField.svelte";
     import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
     import { csrfToken } from "@/lib/csrf";
+    import { boundHistory, parseHistorySnapshot, pushHistory } from "@/lib/manual/scenario-history";
     import { addToast } from "@/lib/stores/toast";
     import type {
         DraftPoint,
@@ -35,12 +45,25 @@
 
     let { projectId, manualId, scenario }: Props = $props();
 
+    // インスタンス内カウンタ (instance script 宣言 = コンポーネントインスタンスごとに独立)。
+    // 採番値は履歴文字列に保存され undo/redo で round-trip する。
+    let clientKeySeq = 0;
+    function nextClientKey(): string {
+        clientKeySeq += 1;
+        return `ck-${clientKeySeq}`;
+    }
+
     /** サーバ shape → 編集用作業コピー (新しい配列/オブジェクトに clone し props と分離する) */
     function toDraftSteps(steps: ScenarioStep[]): DraftStep[] {
         return steps.map((step) => ({
             ...rowOf(step),
             id: step.id,
-            points: step.points.map((point) => ({ ...rowOf(point), id: point.id })),
+            clientKey: nextClientKey(),
+            points: step.points.map((point) => ({
+                ...rowOf(point),
+                id: point.id,
+                clientKey: nextClientKey(),
+            })),
         }));
     }
 
@@ -70,13 +93,22 @@
         }));
     }
 
-    /** 正規化シリアライザ (キー順固定・payload 対象フィールドのみ)。比較と送信の正規形を一本化する */
+    /**
+     * 正規化シリアライザ (履歴/dirty 比較の正規形)。
+     * clientKey を含め undo/redo で安定 key を round-trip させる。
+     * payloadSteps (PUT body) は clientKey を含めない (サーバ保護キー混入防止)。
+     */
     function serializeSteps(list: DraftStep[]): string {
         return JSON.stringify(
             list.map((step) => ({
+                clientKey: step.clientKey,
                 id: step.id,
                 ...rowOf(step),
-                points: step.points.map((point) => ({ id: point.id, ...rowOf(point) })),
+                points: step.points.map((point) => ({
+                    clientKey: point.clientKey,
+                    id: point.id,
+                    ...rowOf(point),
+                })),
             })),
         );
     }
@@ -86,17 +118,31 @@
     // applySaved (保存成功) / reloadScenario (409 からの明示同意リロード) が reseed で行う。
     // svelte-ignore state_referenced_locally
     let version = $state(scenario.scenario_version);
+    // clientKey 採番は 1 回だけ (2 回呼ぶと steps と snapshot で異なるキーが振られ初期 dirty になる)。
     // svelte-ignore state_referenced_locally
-    let steps = $state<DraftStep[]>(toDraftSteps(scenario.steps));
+    const initialSteps = toDraftSteps(scenario.steps);
+    // svelte-ignore state_referenced_locally
+    let steps = $state<DraftStep[]>(initialSteps);
     /** 保存済みスナップショット (正規形の JSON 文字列。$state proxy と参照を共有しない) */
     // svelte-ignore state_referenced_locally
-    let snapshot = $state(serializeSteps(toDraftSteps(scenario.steps)));
+    let snapshot = $state(serializeSteps(initialSteps));
     let saving = $state(false);
     // 直近の保存成功をその場に残す (toast の 4s 自動消去に依存しない永続確認)。
     // true にするのは applySaved() のみ。reseed()・save 開始・失敗・dirty 転換で false。
     let justSaved = $state(false);
     let errors = $state<Record<string, string[]>>({});
 
+    // --- undo/redo 履歴 (保存前のローカル編集のみ対象。サーバ状態 version/snapshot は不変) ---
+    let undoStack = $state<string[]>([]);
+    let redoStack = $state<string[]>([]);
+    /** 編集フィールド focus 時の「変更前」状態 (未確定の pending 編集の基準)。canUndo が参照するため $state */
+    let editBaseline = $state<string | null>(null);
+    // IME/保留は event handler 内でのみ同期参照するため非 reactive local で足りる
+    let composing = false;
+    let flushDeferred = false;
+    /** composing 中に要求された構造操作/undo/redo を compositionend 後に FIFO 実行する */
+    let pendingActions: Array<() => void> = [];
+
     /**
      * 保存失敗フィードバックの判別可能 union。
      * - conflict: 409 (scenario_conflict 契約。理由はサーバ供給 message)
@@ -127,15 +173,22 @@
 
     const dirty = $derived(serializeSteps(steps) !== snapshot);
 
+    const canUndo = $derived(
+        undoStack.length > 0 ||
+            (editBaseline !== null && editBaseline !== serializeSteps(steps)),
+    );
+    const canRedo = $derived(redoStack.length > 0);
+
     // 編集で dirty に転じたら成功確認を消す (level-triggered)。dirty は derived で決定的なため
     // applySaved 直後は dirty=false のままで justSaved=true が保たれる。
     $effect(() => {
         if (dirty) justSaved = false;
     });
 
-    /** 新規行の空値 (scene のみ必須のため空で作る) */
+    /** 新規行の空値 (scene のみ必須のため空で作る)。clientKey は安定 key 用に採番する */
     function emptyRow(shotType: "hiki" | "yori"): Omit<DraftPoint, "id"> {
         return {
+            clientKey: nextClientKey(),
             scene: "",
             shot_type: shotType,
             shooting_point: null,
@@ -148,36 +201,204 @@
     }
 
     function addStep(): void {
-        steps.push({ ...emptyRow("hiki"), id: null, points: [] });
+        runSettled(() =>
+            commitStructural(() => steps.push({ ...emptyRow("hiki"), id: null, points: [] })),
+        );
     }
 
     function addPoint(stepIndex: number): void {
-        steps[stepIndex].points.push({ ...emptyRow("yori"), id: null });
+        runSettled(() =>
+            commitStructural(() => steps[stepIndex].points.push({ ...emptyRow("yori"), id: null })),
+        );
     }
 
     function removeStep(index: number): void {
-        steps.splice(index, 1);
-        confirmingStepIndex = null;
+        runSettled(() => commitStructural(() => steps.splice(index, 1)));
+        confirmingStepIndex = null; // 確認ダイアログを閉じるのは即時 (履歴とは独立)
     }
 
     function removePoint(stepIndex: number, pointIndex: number): void {
-        steps[stepIndex].points.splice(pointIndex, 1);
+        runSettled(() => commitStructural(() => steps[stepIndex].points.splice(pointIndex, 1)));
     }
 
     /** ▲▼ 並べ替え (同一スコープ内のみ。階層をまたぐ移動は提供しない) */
     function moveStep(index: number, delta: -1 | 1): void {
         const next = index + delta;
-        if (next < 0 || next >= steps.length) return;
-        [steps[index], steps[next]] = [steps[next], steps[index]];
+        if (next < 0 || next >= steps.length) return; // 境界: 履歴も積まない
+        runSettled(() =>
+            commitStructural(() => {
+                [steps[index], steps[next]] = [steps[next], steps[index]];
+            }),
+        );
     }
 
     function movePoint(stepIndex: number, index: number, delta: -1 | 1): void {
         const points = steps[stepIndex].points;
         const next = index + delta;
         if (next < 0 || next >= points.length) return;
-        [points[index], points[next]] = [points[next], points[index]];
+        runSettled(() =>
+            commitStructural(() => {
+                [points[index], points[next]] = [points[next], points[index]];
+            }),
+        );
+    }
+
+    // --- 履歴コア (保存前ローカル編集のみ対象。undo/redo は steps を再代入し安定 clientKey で差分描画) ---
+
+    /** 保存/リロード時に履歴を断つ (保存前ローカル編集のみ対象。R1 決定) */
+    function resetHistory(): void {
+        undoStack = [];
+        redoStack = [];
+        editBaseline = null;
+        flushDeferred = false;
+        pendingActions = [];
+    }
+
+    /** editBaseline を(変化があれば)確定して 1 エントリに積む。IME-aware・冪等 */
+    function flushPendingEdit(): void {
+        if (composing) {
+            flushDeferred = true; // 変換確定後に compositionend で flush
+            return;
+        }
+        if (editBaseline === null) return;
+        const before = editBaseline;
+        editBaseline = null; // 冪等化 (直後の focusout で再 push しない)
+        if (pushHistory(undoStack, before, serializeSteps(steps))) {
+            redoStack = []; // 新規編集で redo クリア
+        }
+    }
+
+    /** 構造操作/undo/redo の IME ゲート。composing 中は compositionend まで保留 */
+    function runSettled(action: () => void): void {
+        if (composing) {
+            pendingActions.push(action); // FIFO: 発行順に compositionend で実行 (R4 policy)
+            return;
+        }
+        action();
+    }
+
+    /** 構造操作の共通コミット: pending 編集確定 → 変更前を控え → 変異 → 変化があれば push */
+    function commitStructural(mutate: () => void): void {
+        flushPendingEdit();
+        const before = serializeSteps(steps);
+        mutate();
+        if (pushHistory(undoStack, before, serializeSteps(steps))) {
+            redoStack = [];
+        }
     }
 
+    /**
+     * 履歴文字列を検証(util)→ rowOf 正規化で新規 DraftStep[] を作り steps に反映。
+     * 壊れていれば false(steps を変えない fail-safe)。素の型アサーションを残さない。
+     */
+    function restoreFrom(serialized: string): boolean {
+        const parsed = parseHistorySnapshot(serialized); // util: unknown→type predicate→検証済み
+        if (parsed === null) return false;
+        steps = parsed.map((step) => ({
+            ...rowOf(step),
+            id: step.id,
+            clientKey: step.clientKey, // 安定 key を round-trip
+            points: step.points.map((point) => ({
+                ...rowOf(point),
+                id: point.id,
+                clientKey: point.clientKey,
+            })),
+        }));
+        return true;
+    }
+
+    function reportHistoryCorruption(): void {
+        resetHistory();
+        if (import.meta.env.DEV) {
+            console.warn("[ScenarioEditor] 編集履歴の復元に失敗しました (履歴を破棄)");
+        }
+        addToast("warning", "編集履歴を復元できませんでした");
+    }
+
+    function undo(): void {
+        runSettled(doUndo);
+    }
+    function redo(): void {
+        runSettled(doRedo);
+    }
+
+    function doUndo(): void {
+        flushPendingEdit(); // 進行中のテキスト編集を先に 1 エントリ確定
+        if (undoStack.length === 0) return;
+        const current = serializeSteps(steps); // 復元前 = redo へ退避する状態
+        if (!restoreFrom(undoStack[undoStack.length - 1])) {
+            reportHistoryCorruption(); // fail-safe: steps は変えない
+            return;
+        }
+        undoStack.pop();
+        redoStack.push(current);
+        boundHistory(redoStack);
+        editBaseline = null;
+    }
+
+    function doRedo(): void {
+        flushPendingEdit(); // pending 編集があれば「新規編集」= redo クリア (この後 length 0 で no-op)
+        if (redoStack.length === 0) return;
+        const current = serializeSteps(steps);
+        if (!restoreFrom(redoStack[redoStack.length - 1])) {
+            reportHistoryCorruption();
+            return;
+        }
+        redoStack.pop();
+        undoStack.push(current);
+        boundHistory(undoStack);
+        editBaseline = null;
+    }
+
+    // --- focus / composition ハンドラ (section に委譲。バブリングする focusin/focusout を使う) ---
+
+    /** input/textarea/select/contenteditable か */
+    function isEditableField(el: EventTarget | null): boolean {
+        if (!(el instanceof HTMLElement)) return false;
+        const tag = el.tagName;
+        return tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT" || el.isContentEditable;
+    }
+
+    function onEditorFocusIn(event: FocusEvent): void {
+        if (isEditableField(event.target) && editBaseline === null) {
+            editBaseline = serializeSteps(steps); // このフィールド編集セッションの基準
+        }
+    }
+    function onEditorFocusOut(): void {
+        flushPendingEdit(); // composing 中なら flushDeferred に退避される
+    }
+    // 粒度=フィールド単位 (1 フィールドの編集 = 1 履歴エントリ)。値を変えないフォーカス巡回は
+    // pushHistory(before===current) が no-op のため履歴を汚さない。
+    function onCompositionStart(): void {
+        composing = true;
+    }
+    function onCompositionEnd(): void {
+        composing = false;
+        if (flushDeferred) {
+            flushDeferred = false;
+            flushPendingEdit(); // テキスト編集を 1 エントリ確定 (中間文字列は積まれない)
+        }
+        const queued = pendingActions;
+        pendingActions = [];
+        for (const action of queued) action(); // 構造操作/undo/redo を発行順に実行
+    }
+
+    // キーボードショートカット (Ctrl/Cmd+Z = undo, +Shift = redo)。編集フィールド内は native に委譲
+    $effect(() => {
+        const onKeydown = (event: KeyboardEvent): void => {
+            if (event.isComposing) return; // IME 変換中は無視
+            if (!(event.metaKey || event.ctrlKey) || event.key.toLowerCase() !== "z") return;
+            if (saving || confirmingStepIndex !== null || confirmingReload) return;
+            // 編集フィールドに focus がある間は native の文字単位 undo に委ねる (R1 決定)
+            if (isEditableField(document.activeElement)) return;
+            event.preventDefault();
+            if (event.shiftKey) redo();
+            else undo();
+        };
+        window.addEventListener("keydown", onKeydown);
+        return () => window.removeEventListener("keydown", onKeydown);
+    });
+
     /**
      * union 網羅の型固定 (kind 追加時は引数の never 不一致でコンパイルエラーになり
      * 表示漏れを検出する)。runtime に到達した場合は throw せず汎用 fallback を返す
@@ -342,6 +563,7 @@
         snapshot = serializeSteps(steps);
         errors = {};
         justSaved = false; // 409 競合/明示リロードの reseed で偽の成功表示を出さない
+        resetHistory(); // 保存成功/明示リロードで履歴を断つ (保存前ローカル編集のみ対象)
     }
 
     /** 成功応答の取り込み: 確定 id + version + スナップショット更新 + 成功トースト */
@@ -597,7 +819,13 @@
     </div>
 {/snippet}
 
-<section aria-label="シナリオ編集">
+<section
+    aria-label="シナリオ編集"
+    onfocusin={onEditorFocusIn}
+    onfocusout={onEditorFocusOut}
+    oncompositionstart={onCompositionStart}
+    oncompositionend={onCompositionEnd}
+>
     {#if steps.length === 0}
         <div class="mt-4">
             <EmptyState
@@ -611,7 +839,7 @@
         </div>
     {:else}
         <ol class="mt-4 flex flex-col gap-4" data-testid="scenario-steps">
-            {#each steps as step, stepIndex (step)}
+            {#each steps as step, stepIndex (step.clientKey)}
                 <li>
                     <Card padding="md">
                         <div class="flex items-start justify-between gap-2">
@@ -655,7 +883,7 @@
 
                         {#if step.points.length > 0}
                             <ol class="mt-4 flex flex-col gap-3 border-l-2 border-border pl-4">
-                                {#each step.points as point, pointIndex (point)}
+                                {#each step.points as point, pointIndex (point.clientKey)}
                                     <li>
                                         <div class="flex items-start justify-between gap-2">
                                             <h4 class="text-caption font-medium text-text-secondary">
@@ -761,8 +989,28 @@
         </div>
     {/if}
 
-    <div class="mt-6 flex items-center gap-2">
+    <div class="mt-6 flex flex-wrap items-center gap-2">
         <Button onclick={save} loading={saving} testId="scenario-submit">シナリオを更新</Button>
+        <Button
+            variant="neutral"
+            size="sm"
+            onclick={undo}
+            disabled={!canUndo}
+            testId="scenario-undo"
+        >
+            <Undo2 class="size-4" aria-hidden="true" />
+            元に戻す
+        </Button>
+        <Button
+            variant="neutral"
+            size="sm"
+            onclick={redo}
+            disabled={!canRedo}
+            testId="scenario-redo"
+        >
+            <Redo2 class="size-4" aria-hidden="true" />
+            やり直す
+        </Button>
         {#if dirty}
             <span class="text-caption text-text-secondary" data-testid="scenario-dirty-indicator">
                 未保存の変更があります
diff --git a/resources/js/lib/manual/scenario-history.ts b/resources/js/lib/manual/scenario-history.ts
new file mode 100644
index 0000000..af601f8
--- /dev/null
+++ b/resources/js/lib/manual/scenario-history.ts
@@ -0,0 +1,103 @@
+import type { DraftPoint, DraftStep } from "@/types/manual";
+
+/**
+ * シナリオ編集の undo/redo 履歴ユーティリティ(純関数)。
+ * 1 エントリ = ScenarioEditor.serializeSteps() の正規化 JSON 文字列
+ * (clientKey + id + 本文 8 フィールド + points)。
+ * サイズ上限は件数と総文字数の二本立て (メモリ非有界化の防止)。
+ */
+
+/** 履歴の最大エントリ数 */
+export const MAX_HISTORY_ENTRIES = 100;
+/** 履歴の総文字数ソフト上限 (≈ 数 MB。単一エントリが超えても保持する) */
+export const MAX_HISTORY_CHARS = 2_000_000;
+
+/** serializeSteps が出力する行 shape (DraftPoint と構造一致。id は number|null) */
+export type SerializedRow = DraftPoint;
+/** serializeSteps が出力する step shape (DraftStep と構造一致) */
+export type SerializedStep = DraftStep;
+
+/**
+ * スタック(古→新)を上限内に収める(破壊的 in-place。同一参照を返す)。
+ * 先頭(最古)から捨てるが、length>1 を保持し単一エントリでは空にしない
+ * (= MAX_HISTORY_CHARS はソフト上限)。
+ */
+export function boundHistory(
+    stack: string[],
+    maxEntries: number = MAX_HISTORY_ENTRIES,
+    maxChars: number = MAX_HISTORY_CHARS,
+): string[] {
+    let chars = stack.reduce((total, entry) => total + entry.length, 0);
+    while (stack.length > 1 && (stack.length > maxEntries || chars > maxChars)) {
+        const removed = stack.shift();
+        if (removed === undefined) break;
+        chars -= removed.length;
+    }
+    return stack;
+}
+
+/**
+ * before が current と異なるときのみ before を stack に積み、上限を適用する。
+ * 積んだら true(呼び出し側は true のとき redo スタックをクリアする)。
+ */
+export function pushHistory(stack: string[], before: string, current: string): boolean {
+    if (before === current) return false;
+    stack.push(before);
+    boundHistory(stack);
+    return true;
+}
+
+/** 履歴 1 行 (clientKey + id + 本文 8 フィールド) の type predicate */
+function isSerializedRow(value: unknown): value is SerializedRow {
+    if (value === null || typeof value !== "object") return false;
+    const r = value as Record<string, unknown>;
+    return (
+        typeof r.clientKey === "string" &&
+        r.clientKey.length > 0 && // 空文字 clientKey は keyed each を壊すため拒否
+        (r.id === null || typeof r.id === "number") &&
+        typeof r.scene === "string" &&
+        (r.shot_type === "hiki" || r.shot_type === "yori") &&
+        (r.shooting_point === null || typeof r.shooting_point === "string") &&
+        typeof r.narration === "string" &&
+        (r.subtitle_primary === null || typeof r.subtitle_primary === "string") &&
+        typeof r.subtitle_secondary === "string" &&
+        (r.material_type === null || r.material_type === "video" || r.material_type === "still") &&
+        (r.static_display_seconds === null || typeof r.static_display_seconds === "number")
+    );
+}
+
+/** step (row + points 配列) の type predicate */
+function isSerializedStep(value: unknown): value is SerializedStep {
+    if (!isSerializedRow(value)) return false;
+    const points = (value as { points?: unknown }).points; // 未知プロパティ読取の局所 cast
+    return Array.isArray(points) && points.every(isSerializedRow);
+}
+
+/**
+ * 履歴文字列 → 検証済み SerializedStep[] の防御的デコーダ。
+ * JSON 破損・shape 不一致は null(呼び出し側 fail-safe が履歴を破棄)。
+ * 素の型アサーションをデータ経路に残さない。
+ */
+export function parseHistorySnapshot(serialized: string): SerializedStep[] | null {
+    let parsed: unknown;
+    try {
+        parsed = JSON.parse(serialized);
+    } catch {
+        return null;
+    }
+    if (!Array.isArray(parsed)) return null;
+    const steps: SerializedStep[] = [];
+    const keys = new Set<string>();
+    for (const step of parsed) {
+        if (!isSerializedStep(step)) return null;
+        // clientKey は復元対象全体 (step + 全 point) で一意。重複は keyed each を壊すため拒否
+        if (keys.has(step.clientKey)) return null;
+        keys.add(step.clientKey);
+        for (const point of step.points) {
+            if (keys.has(point.clientKey)) return null;
+            keys.add(point.clientKey);
+        }
+        steps.push(step);
+    }
+    return steps;
+}
diff --git a/resources/js/types/manual.ts b/resources/js/types/manual.ts
index 94a2091..2199a63 100644
--- a/resources/js/types/manual.ts
+++ b/resources/js/types/manual.ts
@@ -86,10 +86,15 @@ export interface ScenarioDocument {
     steps: ScenarioStep[];
 }
 
-/** 編集中の作業コピー (未保存行は id: null)。PUT payload の steps はこの型を直列化する */
-export type DraftPoint = Omit<ScenarioPoint, "id"> & { id: number | null };
+/**
+ * 編集中の作業コピー (未保存行は id: null)。
+ * clientKey は each の安定 key 用のクライアント専用識別子。
+ * serializeSteps() には含めるが PUT payload (payloadSteps) には含めない (サーバ非公開)。
+ */
+export type DraftPoint = Omit<ScenarioPoint, "id"> & { id: number | null; clientKey: string };
 export type DraftStep = Omit<ScenarioStep, "id" | "points"> & {
     id: number | null;
+    clientKey: string;
     points: DraftPoint[];
 };
 
diff --git a/tests/js/components/features/manual/ScenarioEditor.test.ts b/tests/js/components/features/manual/ScenarioEditor.test.ts
index d114d1c..4a84036 100644
--- a/tests/js/components/features/manual/ScenarioEditor.test.ts
+++ b/tests/js/components/features/manual/ScenarioEditor.test.ts
@@ -5,6 +5,21 @@ import ScenarioEditor from "@/components/features/manual/ScenarioEditor.svelte";
 import { clearToasts, toasts } from "@/lib/stores/toast";
 import type { ScenarioDocument } from "@/types/manual";
 
+// parseHistorySnapshot の fail-safe テスト用 partial mock。
+// 既定は real 実装へ委譲し (holder.real)、fail-safe テストのみ mockReturnValueOnce(null) で破損扱いにする。
+const holder = vi.hoisted(() => ({
+    mock: vi.fn(),
+    real: undefined as
+        | undefined
+        | typeof import("@/lib/manual/scenario-history").parseHistorySnapshot,
+}));
+vi.mock("@/lib/manual/scenario-history", async (importOriginal) => {
+    const actual = await importOriginal<typeof import("@/lib/manual/scenario-history")>();
+    holder.real = actual.parseHistorySnapshot; // real を保持
+    holder.mock.mockImplementation(actual.parseHistorySnapshot); // 既定 = real 委譲
+    return { ...actual, parseHistorySnapshot: holder.mock }; // 他 export は実物
+});
+
 // router.reload (部分リロード) はテスト環境では実行できないためモックする。
 // onSuccess をテスト側から呼び、サーバ最新 document の再取り込みを検証する。
 const { routerReloadMock, routerOnMock } = vi.hoisted(() => ({
@@ -122,6 +137,9 @@ beforeEach(() => {
     // jsdom は scrollIntoView 未実装。失敗フィードバックの知覚処理 (showFailure) が
     // 全失敗経路で呼ぶため、毎テスト新しい spy を注入する (呼び出し順/引数検証にも使う)
     Element.prototype.scrollIntoView = vi.fn();
+    // parseHistorySnapshot mock を毎テスト既定 (real 委譲) へ復帰させ、fail-safe テストの
+    // mockReturnValueOnce が他テストへ波及しないようにする
+    if (holder.real) holder.mock.mockImplementation(holder.real);
 });
 
 afterEach(() => {
@@ -860,4 +878,331 @@ describe("ScenarioEditor", () => {
             expect(screen.queryByTestId("scenario-failure-region")).not.toBeInTheDocument();
         });
     });
+
+    // --- T048: Undo/Redo (一つ戻る / 進む) ---
+
+    describe("Undo/Redo", () => {
+        /** フィールド編集セッションを模す: focusIn → input → focusOut (1 履歴エントリ) */
+        async function editCell(testId: string, value: string): Promise<void> {
+            const el = screen.getByTestId(testId);
+            await fireEvent.focusIn(el);
+            await fireEvent.input(el, { target: { value } });
+            await fireEvent.focusOut(el);
+        }
+
+        /** keydown を window に dispatch し、defaultPrevented 判定用に event を返す */
+        function dispatchKey(init: KeyboardEventInit): KeyboardEvent {
+            const ev = new KeyboardEvent("keydown", { bubbles: true, cancelable: true, ...init });
+            window.dispatchEvent(ev);
+            return ev;
+        }
+
+        const undoBtn = (): HTMLElement => screen.getByTestId("scenario-undo");
+        const redoBtn = (): HTMLElement => screen.getByTestId("scenario-redo");
+
+        it("初期表示は dirty なし・Undo/Redo とも disabled (clientKey 二重採番の回帰検出)", () => {
+            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+
+            expect(screen.queryByTestId("scenario-dirty-indicator")).not.toBeInTheDocument();
+            expect(undoBtn()).toBeDisabled();
+            expect(redoBtn()).toBeDisabled();
+        });
+
+        it("PUT payload に clientKey を含めない (保護キー混入防止)", async () => {
+            fetchMock.mockResolvedValueOnce(jsonResponse(200, { ...makeDocument(), scenario_version: 4 }));
+
+            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+            await editCell("step-0-scene", "手順シーンAX");
+            await fireEvent.click(screen.getByTestId("scenario-submit"));
+
+            await waitFor(() => {
+                expect(fetchMock).toHaveBeenCalledTimes(1);
+            });
+            const payload = lastPutPayload();
+            expect(payload.steps[0]).not.toHaveProperty("clientKey");
+            expect(
+                (payload.steps[0].points as Array<Record<string, unknown>>)[0],
+            ).not.toHaveProperty("clientKey");
+        });
+
+        it("セル編集 → Undo で前状態 → Redo で再適用", async () => {
+            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+
+            await editCell("step-0-scene", "手順シーンAX");
+            expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンAX");
+
+            await fireEvent.click(undoBtn());
+            expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンA");
+
+            await fireEvent.click(redoBtn());
+            expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンAX");
+        });
+
+        it("行追加 → Undo で消える → Redo で戻る", async () => {
+            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+
+            await fireEvent.click(screen.getByTestId("scenario-add-step"));
+            expect(screen.getByTestId("step-2-scene")).toBeInTheDocument();
+
+            await fireEvent.click(undoBtn());
+            expect(screen.queryByTestId("step-2-scene")).not.toBeInTheDocument();
+
+            await fireEvent.click(redoBtn());
+            expect(screen.getByTestId("step-2-scene")).toBeInTheDocument();
+        });
+
+        it("手順削除 (確認ダイアログ) → Undo で配下急所ごと復活", async () => {
+            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+
+            await fireEvent.click(screen.getByTestId("step-0-remove"));
+            await fireEvent.click(screen.getByRole("button", { name: "削除する" }));
+            expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンB");
+            expect(screen.queryByTestId("point-0-0-scene")).not.toBeInTheDocument();
+
+            await fireEvent.click(undoBtn());
+            expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンA");
+            expect(screen.getByTestId("point-0-0-scene")).toHaveValue("急所シーンA-1");
+        });
+
+        it("並べ替え → Undo で順序が戻る", async () => {
+            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+
+            await fireEvent.click(screen.getByTestId("step-0-move-down"));
+            expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンB");
+
+            await fireEvent.click(undoBtn());
+            expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンA");
+            expect(screen.getByTestId("step-1-scene")).toHaveValue("手順シーンB");
+        });
+
+        it("複数操作 (追加→編集→並べ替え) を 3 回 Undo で初期状態へ戻す", async () => {
+            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+
+            await fireEvent.click(screen.getByTestId("scenario-add-step")); // 操作1
+            await editCell("step-0-scene", "手順シーンAX"); // 操作2
+            await fireEvent.click(screen.getByTestId("step-0-move-down")); // 操作3
+
+            await fireEvent.click(undoBtn());
+            await fireEvent.click(undoBtn());
+            await fireEvent.click(undoBtn());
+
+            expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンA");
+            expect(screen.getByTestId("step-1-scene")).toHaveValue("手順シーンB");
+            expect(screen.queryByTestId("step-2-scene")).not.toBeInTheDocument();
+            expect(screen.queryByTestId("scenario-dirty-indicator")).not.toBeInTheDocument();
+        });
+
+        it("Undo 後に別セルを編集すると Redo がクリアされる", async () => {
+            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+
+            await editCell("step-0-scene", "手順シーンAX");
+            await fireEvent.click(undoBtn());
+            expect(redoBtn()).not.toBeDisabled();
+
+            await editCell("step-1-scene", "手順シーンBX");
+            expect(redoBtn()).toBeDisabled();
+        });
+
+        it("保存成功後は履歴がリセットされ Undo が disabled になる", async () => {
+            fetchMock.mockResolvedValueOnce(jsonResponse(200, { ...makeDocument(), scenario_version: 4 }));
+
+            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+            await editCell("step-0-scene", "手順シーンAX");
+            expect(undoBtn()).not.toBeDisabled();
+
+            await fireEvent.click(screen.getByTestId("scenario-submit"));
+            await waitFor(() => {
+                expect(screen.getByTestId("scenario-saved-indicator")).toBeInTheDocument();
+            });
+            expect(undoBtn()).toBeDisabled();
+            expect(redoBtn()).toBeDisabled();
+        });
+
+        it("409 → 明示リロード後は履歴がリセットされる", async () => {
+            fetchMock.mockResolvedValueOnce(
+                jsonResponse(409, {
+                    code: "scenario_conflict",
+                    conflict_type: "version_mismatch",
+                    message: "他の編集と競合しました。",
+                    current_version: 9,
+                }),
+            );
+
+            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+            await editCell("step-0-scene", "手順シーンAX");
+            expect(undoBtn()).not.toBeDisabled();
+
+            await fireEvent.click(screen.getByTestId("scenario-submit"));
+            await waitFor(() => {
+                expect(screen.getByTestId("scenario-conflict-reload")).toBeInTheDocument();
+            });
+            await fireEvent.click(screen.getByTestId("scenario-conflict-reload"));
+            await waitFor(() => {
+                expect(screen.getByRole("button", { name: "破棄して最新を取得" })).toBeInTheDocument();
+            });
+            await fireEvent.click(screen.getByRole("button", { name: "破棄して最新を取得" }));
+
+            const latest: ScenarioDocument = {
+                scenario_version: 9,
+                steps: [{ ...makeDocument().steps[0], scene: "サーバ最新シーン", points: [] }],
+            };
+            lastReloadOptions().onSuccess({ props: { scenario: latest } });
+            lastReloadOptions().onFinish();
+
+            await waitFor(() => {
+                expect(screen.getByTestId("step-0-scene")).toHaveValue("サーバ最新シーン");
+            });
+            expect(undoBtn()).toBeDisabled();
+            expect(redoBtn()).toBeDisabled();
+        });
+
+        it("ショートカット: 非編集要素に focus 時 Ctrl+Z / Cmd+Z で Undo", async () => {
+            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+            await editCell("step-0-scene", "手順シーンAX");
+            // editCell の focusOut 後、activeElement は body (非編集要素)
+            expect(document.activeElement?.tagName).not.toBe("INPUT");
+
+            const ctrl = dispatchKey({ ctrlKey: true, key: "z" });
+            expect(ctrl.defaultPrevented).toBe(true);
+            await waitFor(() => {
+                expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンA");
+            });
+
+            // Cmd+Z (mac) でも Undo が走る (別編集を積んで再検証)
+            await editCell("step-0-scene", "手順シーンAY");
+            dispatchKey({ metaKey: true, key: "z" });
+            await waitFor(() => {
+                expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンA");
+            });
+        });
+
+        it("ショートカット: 編集フィールド focus 中は native に委譲し app undo を走らせない", async () => {
+            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+            await editCell("step-0-scene", "手順シーンAX");
+
+            const input = screen.getByTestId("step-0-scene") as HTMLInputElement;
+            input.focus();
+            expect(document.activeElement).toBe(input);
+
+            const ev = dispatchKey({ ctrlKey: true, key: "z" });
+            // 編集フィールド内なので preventDefault されず (native 委譲)、app undo も走らない
+            expect(ev.defaultPrevented).toBe(false);
+            expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンAX");
+        });
+
+        it("ショートカット: IME 変換中 (isComposing) は無視する", async () => {
+            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+            await editCell("step-0-scene", "手順シーンAX");
+
+            const ev = dispatchKey({ ctrlKey: true, key: "z", isComposing: true });
+            expect(ev.defaultPrevented).toBe(false);
+            expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンAX");
+        });
+
+        it("ショートカット: Ctrl+Shift+Z で Redo", async () => {
+            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+            await editCell("step-0-scene", "手順シーンAX");
+            await fireEvent.click(undoBtn());
+            expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンA");
+
+            dispatchKey({ ctrlKey: true, shiftKey: true, key: "z" });
+            await waitFor(() => {
+                expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンAX");
+            });
+        });
+
+        it("blur → 構造操作(click) で二重 push しない (1 編集 + 1 構造 = Undo 2 回で初期)", async () => {
+            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+
+            await editCell("step-0-scene", "手順シーンAX"); // 1 エントリ
+            await fireEvent.click(screen.getByTestId("scenario-add-step")); // 1 エントリ
+
+            await fireEvent.click(undoBtn()); // 追加取消
+            expect(screen.queryByTestId("step-2-scene")).not.toBeInTheDocument();
+            await fireEvent.click(undoBtn()); // 編集取消
+            expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンA");
+            expect(undoBtn()).toBeDisabled(); // これ以上戻れない (2 エントリのみ)
+        });
+
+        it("IME 順序1: focusout(composing) → compositionend で 1 エントリに確定", async () => {
+            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+            const section = screen.getByLabelText("シナリオ編集");
+            const el = screen.getByTestId("step-0-scene");
+
+            await fireEvent.focusIn(el);
+            await fireEvent.compositionStart(section);
+            await fireEvent.input(el, { target: { value: "手順シーンAX" } });
+            await fireEvent.focusOut(el); // composing 中: 保留 (中間文字列を積まない)
+            await fireEvent.compositionEnd(section); // 確定で 1 エントリ
+
+            await fireEvent.click(undoBtn());
+            expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンA");
+            expect(undoBtn()).toBeDisabled(); // 1 エントリのみ
+        });
+
+        it("IME 順序2: focusout → 構造click → compositionend で テキスト1 + 構造1", async () => {
+            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+            const section = screen.getByLabelText("シナリオ編集");
+            const el = screen.getByTestId("step-0-scene");
+
+            await fireEvent.focusIn(el);
+            await fireEvent.compositionStart(section);
+            await fireEvent.input(el, { target: { value: "手順シーンAX" } });
+            await fireEvent.focusOut(el);
+            await fireEvent.click(screen.getByTestId("scenario-add-step")); // composing 中: FIFO 保留
+            await fireEvent.compositionEnd(section); // テキスト確定 → 構造実行 の順
+
+            // 構造操作 (追加) が反映され、Undo で取消
+            expect(screen.getByTestId("step-2-scene")).toBeInTheDocument();
+            await fireEvent.click(undoBtn());
+            expect(screen.queryByTestId("step-2-scene")).not.toBeInTheDocument();
+            // テキスト編集も 1 エントリ残る
+            await fireEvent.click(undoBtn());
+            expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンA");
+            expect(undoBtn()).toBeDisabled();
+        });
+
+        it("復元 fail-safe: 履歴破損時は steps 非破壊・履歴リセット・warning トースト", async () => {
+            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+            await editCell("step-0-scene", "手順シーンAX");
+
+            // 次の parseHistorySnapshot 呼び出し (undo の restoreFrom) のみ破損扱いにする
+            holder.mock.mockReturnValueOnce(null);
+            await fireEvent.click(undoBtn());
+
+            // steps は変えない (編集値のまま) + 履歴リセットで Undo/Redo disabled + warning トースト
+            expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンAX");
+            expect(undoBtn()).toBeDisabled();
+            expect(redoBtn()).toBeDisabled();
+            expect(get(toasts).some((toast) => toast.type === "warning")).toBe(true);
+        });
+
+        it("canUndo は pending 編集を含む (focusout 前でも Undo が活性)", async () => {
+            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+            const el = screen.getByTestId("step-0-scene");
+
+            await fireEvent.focusIn(el);
+            await fireEvent.input(el, { target: { value: "手順シーンAX" } });
+            // focusOut を発火せず (pending 編集) でも Undo は活性
+            expect(undoBtn()).not.toBeDisabled();
+        });
+
+        it("reactivity: 構造操作直後に Undo 活性・dirty 表示が即時反映される", async () => {
+            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+
+            await fireEvent.click(screen.getByTestId("scenario-add-step"));
+            expect(undoBtn()).not.toBeDisabled();
+            expect(screen.getByTestId("scenario-dirty-indicator")).toBeInTheDocument();
+        });
+
+        it("Undo で snapshot まで戻すと dirty 表示 (離脱警告) が解除される", async () => {
+            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+
+            await editCell("step-0-scene", "手順シーンAX");
+            expect(screen.getByTestId("scenario-dirty-indicator")).toBeInTheDocument();
+
+            await fireEvent.click(undoBtn());
+            expect(screen.queryByTestId("scenario-dirty-indicator")).not.toBeInTheDocument();
+        });
+    });
 });
diff --git a/tests/js/lib/manual/scenario-history.test.ts b/tests/js/lib/manual/scenario-history.test.ts
new file mode 100644
index 0000000..e21f82b
--- /dev/null
+++ b/tests/js/lib/manual/scenario-history.test.ts
@@ -0,0 +1,173 @@
+import { describe, expect, it } from "vitest";
+import {
+    boundHistory,
+    parseHistorySnapshot,
+    pushHistory,
+} from "@/lib/manual/scenario-history";
+import type { DraftPoint, DraftStep } from "@/types/manual";
+
+/**
+ * scenario-history util の純関数テスト。
+ * - pushHistory / boundHistory は破壊的 in-place (同一参照)。
+ * - parseHistorySnapshot は unknown → type predicate → 検証済み SerializedStep[] | null。
+ */
+
+/** 本文 8 フィールド + clientKey/id を備えた DraftPoint を作る */
+function makeRow(clientKey: string, overrides: Partial<DraftPoint> = {}): DraftPoint {
+    return {
+        clientKey,
+        id: null,
+        scene: "シーン",
+        shot_type: "hiki",
+        shooting_point: null,
+        narration: "ナレーション",
+        subtitle_primary: null,
+        subtitle_secondary: "字幕",
+        material_type: null,
+        static_display_seconds: null,
+        ...overrides,
+    };
+}
+
+/** step (row + points) を作る */
+function makeStep(clientKey: string, points: DraftPoint[] = []): DraftStep {
+    return { ...makeRow(clientKey), points };
+}
+
+/** 履歴 1 エントリ (serialize 済み文字列) を作る */
+function serialize(steps: DraftStep[]): string {
+    return JSON.stringify(steps);
+}
+
+describe("pushHistory", () => {
+    it("before ≠ current では before を push して true を返す", () => {
+        const stack: string[] = [];
+        const pushed = pushHistory(stack, "A", "B");
+
+        expect(pushed).toBe(true);
+        expect(stack).toEqual(["A"]);
+    });
+
+    it("before == current では no-op で false を返す", () => {
+        const stack: string[] = ["X"];
+        const pushed = pushHistory(stack, "same", "same");
+
+        expect(pushed).toBe(false);
+        expect(stack).toEqual(["X"]);
+    });
+
+    // redo スタックのクリアは呼び出し側 (ScenarioEditor) の責務であり、util は関知しない。
+    // pushHistory は undo スタックへの追加のみを行う。
+    it("push は undo スタックのみを対象とし redo には触れない (呼び出し側責務)", () => {
+        const undo: string[] = [];
+        pushHistory(undo, "A", "B");
+
+        expect(undo).toEqual(["A"]);
+    });
+});
+
+describe("boundHistory", () => {
+    it("件数上限超過で最古から打ち切る", () => {
+        const stack = ["1", "2", "3", "4", "5"];
+        boundHistory(stack, 3, Number.MAX_SAFE_INTEGER);
+
+        expect(stack).toEqual(["3", "4", "5"]);
+    });
+
+    it("総文字数上限超過で最古から打ち切る", () => {
+        // 各エントリ 3 文字。maxChars=7 なら合計 <= 7 になるよう最古から捨てる
+        const stack = ["aaa", "bbb", "ccc"];
+        boundHistory(stack, Number.MAX_SAFE_INTEGER, 7);
+
+        expect(stack).toEqual(["bbb", "ccc"]); // 6 文字 <= 7
+    });
+
+    it("件数超過かつ文字数超過の複合ケースでも while 条件が正しく打ち切る", () => {
+        const stack = ["aa", "bb", "cc", "dd", "ee"];
+        // maxEntries=4 と maxChars=5 の両方を同時に満たすまで最古から捨てる
+        boundHistory(stack, 4, 5);
+
+        // 4 件以下 かつ 5 文字以下 → ["cc","dd","ee"] は 3件6文字なので dd/ee=4文字まで削る
+        expect(stack).toEqual(["dd", "ee"]); // 2件4文字 <= 5
+    });
+
+    it("単一巨大エントリは上限超でも空にしない (ソフト上限)", () => {
+        const stack = ["超巨大なエントリ"];
+        boundHistory(stack, 100, 1);
+
+        expect(stack).toEqual(["超巨大なエントリ"]);
+    });
+
+    it("上限内なら変化なし", () => {
+        const stack = ["1", "2"];
+        boundHistory(stack, 100, 1000);
+
+        expect(stack).toEqual(["1", "2"]);
+    });
+
+    it("同一参照を破壊的に返す (in-place)", () => {
+        const stack = ["1", "2", "3"];
+        const returned = boundHistory(stack, 2, Number.MAX_SAFE_INTEGER);
+
+        expect(returned).toBe(stack);
+    });
+});
+
+describe("parseHistorySnapshot", () => {
+    it("正常な serialize 文字列を SerializedStep[] に復元する (clientKey/points 保持)", () => {
+        const steps = [makeStep("ck-1", [makeRow("ck-2", { id: 21 })])];
+        const parsed = parseHistorySnapshot(serialize(steps));
+
+        expect(parsed).not.toBeNull();
+        expect(parsed?.[0].clientKey).toBe("ck-1");
+        expect(parsed?.[0].points[0].clientKey).toBe("ck-2");
+        expect(parsed?.[0].points[0].id).toBe(21);
+    });
+
+    it("不正 JSON は null", () => {
+        expect(parseHistorySnapshot("{")).toBeNull();
+    });
+
+    it("非配列は null", () => {
+        expect(parseHistorySnapshot("{}")).toBeNull();
+    });
+
+    it("必須フィールド (scene) 欠落は null", () => {
+        const broken = JSON.stringify([
+            { clientKey: "ck-1", id: null, shot_type: "hiki", points: [] },
+        ]);
+        expect(parseHistorySnapshot(broken)).toBeNull();
+    });
+
+    it("clientKey 欠落は null", () => {
+        const step = makeStep("ck-1");
+        const raw = JSON.parse(serialize([step])) as Record<string, unknown>[];
+        delete raw[0].clientKey;
+        expect(parseHistorySnapshot(JSON.stringify(raw))).toBeNull();
+    });
+
+    it("points が非配列は null", () => {
+        const raw = JSON.parse(serialize([makeStep("ck-1")])) as Record<string, unknown>[];
+        raw[0].points = "not-array";
+        expect(parseHistorySnapshot(JSON.stringify(raw))).toBeNull();
+    });
+
+    it("clientKey が空文字は null", () => {
+        expect(parseHistorySnapshot(serialize([makeStep("")]))).toBeNull();
+    });
+
+    it("clientKey 重複 (step 同士) は null", () => {
+        const steps = [makeStep("dup"), makeStep("dup")];
+        expect(parseHistorySnapshot(serialize(steps))).toBeNull();
+    });
+
+    it("clientKey 重複 (point 同士) は null", () => {
+        const steps = [makeStep("ck-1", [makeRow("dup"), makeRow("dup")])];
+        expect(parseHistorySnapshot(serialize(steps))).toBeNull();
+    });
+
+    it("clientKey 重複 (step × point) は null", () => {
+        const steps = [makeStep("shared", [makeRow("shared")])];
+        expect(parseHistorySnapshot(serialize(steps))).toBeNull();
+    });
+});
```

---

## テスト結果

- util 単体 (tests/js/lib/manual/scenario-history.test.ts): 19 passed
- component (tests/js/components/features/manual/ScenarioEditor.test.ts): 56 passed (既存 35 + 新規 21、既存は追記のみで不変)
- フル JS スイート (pnpm test): 73 files / 605 tests all passed
- pnpm lint / pnpm typecheck / pnpm build すべて green。PHP 変更なし。

## design system 参照
- 追加 UI: 操作行に Undo2/Redo2 (Lucide) アイコン付き Button (variant=neutral, size=sm)。text-caption/text-success は既存 token。hex 直書き・新規 token・SVG 直書きなし。
- atomic: 新規 util は resources/js/lib/manual/scenario-history.ts (component 層外)。ScenarioEditor は features/manual (既存位置)。
