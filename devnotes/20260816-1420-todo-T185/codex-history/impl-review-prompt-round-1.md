【アプリの使命 (North Star)】
## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則
## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

## セキュリティ不変条件(アプリ都合で緩めない)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: あなたの役割

Laravel + Svelte 5 アプリ AI-CUE の**実装レビュアー**として、TODO T185
「シナリオ行とテイクのドラッグ&ドロップ並べ替え」の実装差分をレビューせよ。

## レビュー観点

1. **詳細設計との一致性**: 設計書の 7 施策が実装されているか。逸脱があるなら妥当か
2. **正確性**: off-by-one、リソースリーク (window listener / rAF)、競合状態 (多点入力・
   controller またぎ)、非同期の取り違え、境界条件
3. **TypeScript strict 適合**: `any` / 素の型アサーション不使用、戻り値型の明示、null 安全
   (本変更は PHP を 1 行も含まない。PHPStan 適合チェックは TypeScript strict 適合に読み替える)
4. **DTO / JsonResource パターン**: 本変更はサーバ側 API 契約に触れていないことの確認
5. **テスト網羅性**: 各施策にテストが割り付いているか。**テストが実際に fail-first で
   意味を持つか** (assert が空振りしていないか)。回帰防止として弱いところ
6. **セキュリティ**: クライアント側のみの変更だが、サーバ権威の維持 (楽観更新をしていないこと)、
   保護キーの混入がないこと
7. **DESIGN.md 準拠**: `/DESIGN.md` が design token の canonical source。
   color / radius / typography は token 経由で参照し hex 直書き (`#RRGGBB`) を増やさない。
   影 (`shadow-*`) / gradient / `hover:scale-*` を使わない (§Elevation & Depth)。
   角丸は `rounded-sm` / `rounded-md` / `rounded-lg` の 3 段のみ (§Shapes)。
   小コントロール (ボタン・入力・バッジ) は `rounded-sm`
8. **Atomic Design 準拠**: `resources/js/components/` は
   `atoms → molecules → organisms → features/{domain} → templates → pages` の
   単方向 import。atom は単機能で状態を持たない。アイコンは `@lucide/svelte` のみ
   (SVG 直書きを増やさない)。`resources/js/lib/` は util 層で全層から import 可
9. **禁止事項 8 (必須条件未充足を理由にボタンを disabled にしない)** への適合

## 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
  - Critical = 直さないとマージできない (バグ・規約違反・データ不整合)
  - Warning = 直すべきだが議論の余地がある
  - Suggestion = 好みや将来の改善
- 最後に**全体判定を `APPROVED` または `CHANGES_REQUESTED` の 1 語で明示**する

---

# user

## 背景

- 本タスクはフロントエンドのみの変更である (PHP の差分は 0 件。`git diff -- '*.php'` が空)
- 既存の ▲▼ ボタンによる 1 段移動は**残したまま**、ドラッグハンドルと
  キーボード (ArrowUp/ArrowDown) を足して並べ替え手段を 3 つに増やしている
- サーバ API は無変更。シナリオ編集は既存の PUT (配列順がそのまま順序)、
  撮影 PWA のテイクは既存の PATCH `{ position }` を使う

## 検証結果 (すべて green)

- `composer test`: 5416 tests / 5414 passed / 2 skipped / 23332 assertions
- `composer phpstan`: No errors (level 10)
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm build`: green
- `pnpm test`: 145 files / **1702 tests passed**
  (うち本タスクの新規: list-reorder 39 / pointer-drag 15 / ScenarioEditor +15 / TakeStrip +13)
- `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` (106 tests): green

### 実施した負のコントロール (テストが空振りしていないことの確認)

`ScenarioEditor.svelte` の `onPointHandleDown` から `dragOwner !== null` の排他ガードを
一時的に外したところ、「手順ドラッグ中は急所ドラッグが始まらない」テストが期待どおり
赤くなることを確認した (急所 B-1 が B-2 に入れ替わった)。確認後にガードを戻してある。

## 詳細設計書

# 詳細設計: drag-and-drop-reordering (シナリオ行とテイクのドラッグ&ドロップ並べ替え)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` factory → `PromptDefense` → `GuardedPrompt` の 1 本道のみ)
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. **必須条件未充足を理由にボタンを disabled にする UI**(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用

> 本設計は **8 に直接関係する**。新設するドラッグハンドルも既存の ▲▼ も disabled にしない。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- フロント固有: Svelte 5 runes + DS token のみ (`DESIGN.md` canonical / `ds-purity` が検出)。
  component 階層は `atoms → molecules → organisms → features/{domain} → templates → pages` の
  単方向 import (`atomic-import-graph.test.ts`)。アイコンは `@lucide/svelte` のみ
  (`lucide-scoped-import.test.ts` / `svg-inline-allowlist.test.ts`)。

> **本設計は PHP を 1 行も変更しない**。よって各施策の「PHPStan 適合チェック」は
> **TypeScript strict 適合チェック**（`pnpm typecheck` = `tsc --noEmit` / `any` 不使用 /
> 素の型アサーション不使用）に読み替える。PHP 側は「変更 0 件であること」自体を
> 受け入れ条件とし、`composer phpstan` / `composer test` が**無変更のまま緑**であることを確認する。

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md) （Codex `conceptual-review` **Round 1 で APPROVED**）
- 受け入れ条件 A1〜A5（概念設計 §受け入れ条件）は本設計の各施策に割り付ける:

| 受け入れ条件 | 割り付け先 |
|---|---|
| A1 disabled を増やさない / 端は告知する | 施策 3・4・5 |
| A2 ポインタ状態を必ず解放する | 施策 2（単一出口 `finish()`）・施策 4・5（`onMount` の cleanup） |
| A3 iOS Safari 実機確認を devnotes に記録 | 施策 7（受け入れ手順） |
| A4 共通化の境界を越えない | 施策 1・2（lib は lifecycle / 挿入位置計算 / moveItem のみ） |
| A5 挿入 index と最終 index を分ける | 施策 1（関数分離）・施策 7（off-by-one テスト） |

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 並べ替え計算の純関数モジュール | `resources/js/lib/dnd/list-reorder.ts` (新規) | 高 |
| 2 | Pointer Events のドラッグ制御 (Svelte 非依存の素 TS) | `resources/js/lib/dnd/pointer-drag.ts` (新規) | 高 |
| 3 | ドラッグハンドル atom | `resources/js/components/atoms/DragHandle.svelte` / `DragHandle.types.ts` (新規) | 高 |
| 4 | シナリオ編集への配線 (手順行・急所行) | `resources/js/components/features/manual/ScenarioEditor.svelte` | 高 |
| 5 | 撮影 PWA テイク列への配線 | `resources/js/components/features/capture/TakeStrip.svelte` | 高 |
| 6 | jsdom に無い pointer capture のスタブ | `tests/js/setup.ts` | 中 |
| 7 | テスト (純関数 2 本 + コンポーネント 2 本) | `tests/js/lib/dnd/*.test.ts` / 既存 2 テストへ追記 | 高 |

---

## 施策 1: 並べ替え計算の純関数モジュール

### 変更箇所

- ファイル: `resources/js/lib/dnd/list-reorder.ts` (**新規**)

### 波及変更

- TypeScript 型定義: 本ファイルが `RowBounds` を新規 export する。他型への影響なし
- API Resource/DTO: **なし**（サーバ変更なし）
- テストファイル: `tests/js/lib/dnd/list-reorder.test.ts` (新規)
- 走査系テストへの影響: `ds-purity` は `resources/js` 配下の `.ts` も走査するが、
  本ファイルは class 文字列を持たないため無関係。`atomic-import-graph` 上は `util` 層

### 現行コード

存在しない（新規）。同等の計算は現在 `ScenarioEditor.svelte` の隣接 swap に埋め込まれている:

```svelte
function moveStep(index: number, delta: -1 | 1): void {
    const next = index + delta;
    if (next < 0 || next >= steps.length) return; // 境界: 履歴も積まない
    runSettled(() =>
        commitStructural(() => {
            [steps[index], steps[next]] = [steps[next], steps[index]];
        }),
    );
}
```

### 変更後コード

```ts
/**
 * リスト並べ替えの純関数 (DOM に触れない)。
 *
 * D&D は DOM イベントの連鎖でテストしづらい。そこで「どこに落ちたら何番目になるか」の
 * 意味論だけをここに閉じ込め、Vitest で網羅する (概念設計 D3)。
 *
 * **index の語彙は 2 つある**。混同すると off-by-one になるため関数を分ける (受け入れ条件 A5):
 * - **挿入 index (insertion index)**: 行と行の隙間を数えた `0..n` の値。n 行のリストには
 *   隙間が n+1 個ある。「どの隙間に落としたか」を表す。
 * - **最終 index (final index)**: 移動が終わった後の配列での `0..n-1` の値。
 *   撮影 PWA がサーバへ渡す `position` はこちらである
 *   (`CaptureTakeService::reorderWithinCut` は対象を除いた配列へ splice するため、
 *   結果として「移動後の全体配列での 0 始まり index」と一致する)。
 */

/** 行の上下位置 (getBoundingClientRect の実測値。viewport 座標) */
export interface RowBounds {
    readonly top: number;
    readonly height: number;
}

/**
 * 要素を from から to (最終 index) へ動かした**新しい配列**を返す。入力は変更しない。
 * 範囲外・非整数は「動かさない」に倒す (fail-safe。呼び出し側で throw させない)。
 *
 * 要素を**値として取り出さず、配列のまま移す**。
 * `const moved = next[from]; if (moved === undefined) return next;` の形は、
 * 配列要素の**値**を存在判定に使うため `T` に `undefined` を含む型では
 * 有効な要素が動かせなくなる (generic の契約と実装が食い違う)。
 * `splice` の戻り値をそのまま spread すれば、`undefined` 要素も正しく動き、
 * `noUncheckedIndexedAccess` の有無にも依存しない (design-review R2)。
 * `from` は直前に範囲検査済みなので、戻り値は実行時に必ず 1 要素である。
 */
export function moveItem<T>(list: readonly T[], from: number, to: number): T[] {
    const next = [...list];
    if (!Number.isInteger(from) || !Number.isInteger(to)) return next;
    if (from < 0 || from >= next.length) return next;
    const clamped = Math.min(Math.max(to, 0), next.length - 1);
    if (clamped === from) return next;
    const moved = next.splice(from, 1);
    next.splice(clamped, 0, ...moved);
    return next;
}

/**
 * ポインタの Y 座標 (viewport 座標) から**挿入 index** (0..rows.length) を決める。
 * 各行の中点より上なら「その行の前」、下ならさらに次の行を見る。
 *
 * rows は**表示順**で、掴んでいる行自身も含めて渡す (DOM から抜かないため)。
 * スクロールしても `getBoundingClientRect()` を採り直せば viewport 座標系で
 * ポインタ座標と一致する (受け入れ条件 A2)。
 */
export function insertionIndexFromRects(rows: readonly RowBounds[], pointerY: number): number {
    for (let i = 0; i < rows.length; i += 1) {
        if (pointerY < rows[i].top + rows[i].height / 2) return i;
    }
    return rows.length;
}

/**
 * 挿入 index → 最終 index。
 * 掴んだ行自身がいったんリストから抜けるぶん、掴んだ位置より後ろの隙間は 1 つ手前へ詰まる。
 * 掴んだ行の前後 2 つの隙間 (from と from+1) はどちらも「動かさない」= from になる。
 *
 * **入力契約**: `insertion` は `0..n` (insertionIndexFromRects の出力)、`from` は `0..n-1` の
 * 正規化済みの値を前提とする。範囲外の clamp はここでは行わない
 * (下流の `moveItem` が 1 箇所で clamp する。2 箇所で丸めると意味が分散する)。
 */
export function toFinalIndex(insertion: number, from: number): number {
    return insertion > from ? insertion - 1 : insertion;
}
```

### TypeScript strict 適合チェック（PHPStan 適合チェックの読み替え）

- [x] 戻り値の型が明示されている（`T[]` / `number`）
- [x] `any` / 素の型アサーション（`as`）を使っていない
- [x] 入力を破壊しない（`readonly T[]` を受け、`[...list]` を返す）
- [x] 範囲外入力で throw せず定義済みの値へ倒す（fail-safe）
- [x] DOM API に触れない（jsdom 非依存で単体テストできる）

### テスト計画

- [x] 新規 `tests/js/lib/dnd/list-reorder.test.ts`
  - `moveItem`: 前方移動 / 後方移動 / 端 → 端 / `from === to` の no-op /
    範囲外 from / 非整数 / 空配列 / **入力配列が変更されていないこと** (immutability) /
    **`T` に `undefined` を含む配列 (`Array<number | undefined>`) でも正しく動くこと**
    (値を存在判定に使っていないことの回帰防止。design-review R2)
  - `insertionIndexFromRects`: 空配列 → 0 / 1 行目の中点の上 → 0 / 中点の下 → 1 /
    最終行の中点より下 → `rows.length` / 行の高さが不揃いでも中点で切り替わること
  - `toFinalIndex`: `insertion <= from` は素通し / `insertion > from` は 1 減 /
    **`from` と `from+1` がどちらも `from` になる**こと
  - **合成テスト (A5 の本丸)**: 4 要素の配列に対し、全 (from, insertion) の組み合わせで
    `moveItem(list, from, toFinalIndex(insertion, from))` の結果が
    「手で並べた期待値」と一致することを表駆動で検証する（off-by-one の恒久回帰）
- [x] 個別の `DatabaseTransactions` を使っていない（JS テストのため対象外）

### リスク

- 挿入 index / 最終 index の語彙が実装者に伝わらないと再び混同が起きる。
  → 関数名・doc コメント・表駆動テストの 3 点で固定する。

---

## 施策 2: Pointer Events のドラッグ制御

### 変更箇所

- ファイル: `resources/js/lib/dnd/pointer-drag.ts` (**新規**)

### 波及変更

- TypeScript 型定義: `PointerDragCallbacks` / `PointerDragController` / `PointerDragState` を新規 export
- API Resource/DTO: **なし**
- テストファイル: `tests/js/lib/dnd/pointer-drag.test.ts` (新規)
- `tests/js/setup.ts`: pointer capture のスタブ（施策 6）

### 現行コード

存在しない（新規）。現状 `resources/js` 配下に `pointerdown` / `draggable` / `touch-action` を
使う実装は 1 件も無い（`rg` で確認済み）。

### 変更後コード

```ts
/**
 * Pointer Events による 1 軸 (縦) の並べ替えドラッグ制御。**Svelte に依存しない素の TS**。
 *
 * HTML5 Drag and Drop API は iOS Safari のタッチで発火しないため採らない (概念設計 D1)。
 * 撮影 PWA の主戦場は iOS Safari (docs/supported-browsers.md) なので、
 * マウス・タッチ・ペンを 1 系統で扱える Pointer Events に一本化する。
 *
 * **共通化の境界** (受け入れ条件 A4): ここに置くのは
 * (i) ポインタの生死管理 (ii) 挿入位置の算出 (iii) 端での自動スクロール だけである。
 * 保存経路・文言・aria-live メッセージ・見た目・サーバへ渡す position 変換は
 * 呼び出し側 (feature component) に残す。
 */
import { insertionIndexFromRects, toFinalIndex, type RowBounds } from "./list-reorder";

/** ドラッグ開始とみなす最小移動量 (px)。タップ/クリックをドラッグにしない */
export const DRAG_ACTIVATION_DISTANCE = 6;
/** 画面端からこの距離に入ったら自動スクロールする (px) */
export const AUTO_SCROLL_EDGE = 64;
/** 自動スクロールの 1 フレームあたりの移動量 (px) */
export const AUTO_SCROLL_STEP = 12;

/** 表示用の状態。UI (影ではなく border と不透明度) の描画にのみ使う */
export interface PointerDragState {
    /** 掴んでいる行の index。ドラッグしていなければ null */
    readonly activeIndex: number | null;
    /** 落とし先の隙間 (挿入 index)。ドラッグしていなければ null */
    readonly insertionIndex: number | null;
}

export interface PointerDragCallbacks {
    /** 表示順の行要素を返す (呼び出し側が DOM から採る)。毎回の pointermove で採り直す */
    readonly rows: () => ReadonlyArray<HTMLElement>;
    /** 表示状態の変化通知 */
    readonly onState: (state: PointerDragState) => void;
    /** 確定。`to` は**最終 index**。`from === to` のときは呼ばれない */
    readonly onCommit: (from: number, to: number) => void;
    /**
     * 取消。**利用者由来の取消だけ**を通知する (Esc / pointercancel / 位置が変わらない drop)。
     * `destroy()` (コンポーネント破棄) では**呼ばれない** — 破棄は利用者の意思ではないので、
     * ここに告知や通信を足したときに unmount で誤発火しないようにするためである。
     */
    readonly onCancel?: () => void;
}

export interface PointerDragController {
    /**
     * ハンドルの pointerdown から呼ぶ。
     * **開始を受理したら true**、既に別のポインタが進行中などで無視したら false を返す。
     * 呼び出し側は「受理されたときだけ」ドラッグに紐づく状態 (対象スコープ等) を確定すること。
     * 戻り値を無視して先に状態を書き換えると、2 本目の指が 1 本目のドラッグの対象を
     * すり替えてしまう (design-review R2 Critical)。
     */
    readonly start: (index: number, event: PointerEvent) => boolean;
    /** コンポーネント破棄時に必ず呼ぶ (受け入れ条件 A2) */
    readonly destroy: () => void;
}

/**
 * **`isDragging()` のような「今ドラッグ中か」を外へ出す API は置かない**。
 * 閾値を超えるまで false を返すため、閾値未満の待機中に別のドラッグを受理してしまう
 * (排他の判定に使うと穴になる)。排他は `start()` の戻り値 = **受理した瞬間**を基準にする
 * (design-review R3)。呼び出し側が複数の controller を持つ場合も同じ基準で 1 つに絞る。
 */

export function createPointerDrag(callbacks: PointerDragCallbacks): PointerDragController {
    let pointerId: number | null = null;
    let handle: HTMLElement | null = null;
    let fromIndex = 0;
    let startY = 0;
    /** 閾値を超えて実際にドラッグが始まったか */
    let activated = false;
    let insertion: number | null = null;
    let scrollFrame: number | null = null;
    let scrollDelta = 0;
    /** 直近のポインタ Y (viewport 座標)。自動スクロール中の再計算に使う */
    let lastClientY = 0;

    function bounds(): RowBounds[] {
        return callbacks.rows().map((el): RowBounds => {
            const rect = el.getBoundingClientRect();
            return { top: rect.top, height: rect.height };
        });
    }

    function stopAutoScroll(): void {
        if (scrollFrame !== null && typeof cancelAnimationFrame === "function") {
            cancelAnimationFrame(scrollFrame);
        }
        scrollFrame = null;
        scrollDelta = 0;
    }

    /**
     * 自動スクロールの 1 フレーム。
     * **スクロールしたら挿入位置を必ず採り直す**。指を止めたまま端でスクロールさせると
     * `pointermove` は来ないのに行だけが動くため、採り直さないと古い挿入位置のまま
     * drop できてしまう (iOS Safari で最も起きやすい。design-review R1 の指摘)。
     */
    /** 挿入位置が実際に変わったときだけ通知する (毎フレームの無駄な再描画を避ける) */
    function setInsertion(next: number): void {
        if (insertion === next) return;
        insertion = next;
        callbacks.onState({ activeIndex: fromIndex, insertionIndex: next });
    }

    function tickAutoScroll(): void {
        scrollFrame = null;
        if (pointerId === null || scrollDelta === 0) return;
        window.scrollBy(0, scrollDelta);
        setInsertion(insertionIndexFromRects(bounds(), lastClientY));
        scrollFrame = requestAnimationFrame(tickAutoScroll);
    }

    /**
     * 画面端に近ければスクロールを回す。
     * requestAnimationFrame が無い環境 (jsdom 等) では自動スクロールだけ働かない。
     * 並べ替えそのものは動くので、機能検出で静かに劣化させる (誇張しない)。
     */
    function updateAutoScroll(clientY: number): void {
        if (typeof requestAnimationFrame !== "function") return;
        const height = window.innerHeight;
        const next =
            clientY < AUTO_SCROLL_EDGE
                ? -AUTO_SCROLL_STEP
                : clientY > height - AUTO_SCROLL_EDGE
                  ? AUTO_SCROLL_STEP
                  : 0;
        scrollDelta = next;
        if (next === 0) {
            stopAutoScroll();
            return;
        }
        if (scrollFrame === null) scrollFrame = requestAnimationFrame(tickAutoScroll);
    }

    /**
     * **すべての終了経路が合流する唯一の出口** (受け入れ条件 A2)。
     * pointerup / pointercancel / Escape / destroy はここへ入る。
     * 資源 (pointer capture / rAF) を先に解放してから callback を呼ぶので、
     * callback 内で再入しても状態は壊れない。
     *
     * @param commit true なら位置が変わっていれば onCommit する
     * @param notify false なら onCancel を呼ばない (destroy 専用。
     *        破棄は利用者の取消ではないため、告知や通信を伴う onCancel を発火させない)
     */
    function finish(commit: boolean, notify: boolean): void {
        if (pointerId === null) return;
        const wasActivated = activated;
        const target = insertion;
        const from = fromIndex;
        if (
            handle !== null &&
            typeof handle.releasePointerCapture === "function" &&
            typeof handle.hasPointerCapture === "function" &&
            handle.hasPointerCapture(pointerId)
        ) {
            handle.releasePointerCapture(pointerId);
        }
        pointerId = null;
        handle = null;
        activated = false;
        insertion = null;
        stopAutoScroll();
        callbacks.onState({ activeIndex: null, insertionIndex: null });
        if (!commit || !wasActivated || target === null) {
            if (notify) callbacks.onCancel?.();
            return;
        }
        const to = toFinalIndex(target, from);
        if (to === from) {
            if (notify) callbacks.onCancel?.();
            return;
        }
        callbacks.onCommit(from, to);
    }

    function onPointerMove(event: PointerEvent): void {
        if (pointerId === null || event.pointerId !== pointerId) return;
        lastClientY = event.clientY;
        if (!activated) {
            if (Math.abs(event.clientY - startY) < DRAG_ACTIVATION_DISTANCE) return;
            activated = true;
        }
        // ハンドルの touch-action:none と併せて、スクロール/テキスト選択との競合を断つ
        event.preventDefault();
        if (insertion === null) {
            // 掴んだ直後の 1 回目は必ず通知する (activeIndex を UI へ伝えるため)
            insertion = insertionIndexFromRects(bounds(), event.clientY);
            callbacks.onState({ activeIndex: fromIndex, insertionIndex: insertion });
        } else {
            setInsertion(insertionIndexFromRects(bounds(), event.clientY));
        }
        updateAutoScroll(event.clientY);
    }

    function onPointerUp(event: PointerEvent): void {
        if (pointerId === null || event.pointerId !== pointerId) return;
        finish(true, true);
    }

    function onPointerCancel(event: PointerEvent): void {
        if (pointerId === null || event.pointerId !== pointerId) return;
        finish(false, true);
    }

    function onKeyDown(event: KeyboardEvent): void {
        if (pointerId !== null && event.key === "Escape") finish(false, true);
    }

    // listener は生成時に 1 度だけ張り destroy で外す (start/finish のたびに張り替えない)。
    // capture が使えない環境でも window で拾えるよう、ハンドルではなく window に張る。
    window.addEventListener("pointermove", onPointerMove, { passive: false });
    window.addEventListener("pointerup", onPointerUp);
    window.addEventListener("pointercancel", onPointerCancel);
    window.addEventListener("keydown", onKeyDown);

    return {
        start(index: number, event: PointerEvent): boolean {
            if (pointerId !== null) return false; // 2 本目の指は無視 (多点ドラッグは提供しない)
            if (event.pointerType === "mouse" && event.button !== 0) return false; // 左ボタンのみ
            const target = event.currentTarget;
            handle = target instanceof HTMLElement ? target : null;
            pointerId = event.pointerId;
            fromIndex = index;
            startY = event.clientY;
            lastClientY = event.clientY;
            activated = false;
            insertion = null;
            // pointer capture が無い環境 (jsdom / 一部の古い WebKit) でも
            // window の listener で同じ callback 契約のまま完走する (受け入れ条件 A2)
            if (handle !== null && typeof handle.setPointerCapture === "function") {
                handle.setPointerCapture(event.pointerId);
            }
            return true;
        },
        destroy(): void {
            // 進行中のドラッグを畳むが onCancel は呼ばない (破棄は利用者の取消ではない)
            finish(false, false);
            window.removeEventListener("pointermove", onPointerMove);
            window.removeEventListener("pointerup", onPointerUp);
            window.removeEventListener("pointercancel", onPointerCancel);
            window.removeEventListener("keydown", onKeyDown);
        },
    };
}
```

### TypeScript strict 適合チェック

- [x] 戻り値の型が明示されている（全関数）
- [x] `any` を使わない。`event.currentTarget` は `instanceof HTMLElement` で絞る（素の `as` なし）
- [x] `null` 安全（`pointerId === null` の早期 return を全ハンドラの先頭に置く）
- [x] 省略可能な callback は `?.()` で呼ぶ
- [x] ブラウザ API は機能検出する（`setPointerCapture` / `hasPointerCapture` /
      `releasePointerCapture` / `requestAnimationFrame` / `cancelAnimationFrame`）
- [x] `window` を module 読み込み時に触らない（`createPointerDrag` を呼んだ時だけ触るので
      SSR/Node 読み込みで落ちない）

### テスト計画

- [x] 新規 `tests/js/lib/dnd/pointer-drag.test.ts`（jsdom 上で `PointerEvent` を直接 dispatch）
  - 閾値未満の移動では `onCommit` も `onState(active)` も起きない（タップがドラッグにならない）
  - 閾値超え → `onState` が `{activeIndex, insertionIndex}` を通知する
  - `pointerup` で `onCommit(from, to)` が**最終 index** で 1 回だけ呼ばれる
  - 位置が変わらない drop は `onCommit` ではなく `onCancel`
  - `pointercancel` / `Escape` は `onCommit` を呼ばず `onCancel` を呼ぶ
  - **異なる `pointerId` の move/up を無視する**（2 本目の指で確定しない）
  - `destroy()` 後に `pointermove` を投げても callback が呼ばれない（listener 解放の証明）
  - **`destroy()` を drag 中に呼ぶと `onCommit` も `onCancel` も呼ばれず、
    `onState` だけが `{null, null}` にリセットされる**（破棄と取消を区別する契約の固定）
  - **`start()` の戻り値**: 進行中に 2 回目の `start()` を呼ぶと `false` を返し、
    1 本目の対象 (`fromIndex`) が保持されること
  - **自動スクロール**: `requestAnimationFrame` は**同期即時実行にしない**
    （`tickAutoScroll` が末尾で次フレームを登録するため、同期実行すると無限再帰になる。
    design-review R2）。callback を**キューに保存するだけの fake** に差し替え、
    テストから 1 フレームぶんだけ明示的に実行する:

    ```ts
    let frame: FrameRequestCallback | null = null;
    vi.stubGlobal("requestAnimationFrame", (cb: FrameRequestCallback) => { frame = cb; return 1; });
    vi.stubGlobal("cancelAnimationFrame", () => { frame = null; });
    // …pointermove を端の座標で出したあと
    frame?.(0); // 1 フレームだけ進める
    ```

    `window.scrollBy` を spy して行の `getBoundingClientRect` をスクロール分ずらすと、
    `pointermove` を出さなくても `onState` の `insertionIndex` が更新されること
    （指を止めたまま端でスクロールしたときの stale 挿入位置の回帰防止）
  - global の差し替えは `afterEach` の `vi.unstubAllGlobals()` で必ず戻す
    （施策 6 の `withoutPointerCapture` とは**別の後始末**なので混ぜない。
    テスト間に global が漏れないようにする。design-review R2）
  - `setPointerCapture` が**無い**環境でも一連の流れが完走する
    （施策 6 の helper `withoutPointerCapture()` で 1 ケースだけ外して検証する）
- [x] 個別の `DatabaseTransactions` を使っていない（JS テストのため対象外）

### リスク

| リスク | 対応 |
|---|---|
| `window` に張った listener が漏れる | `destroy()` で必ず外す。テストで「destroy 後は callback が来ない」ことを固定する |
| rAF の自動スクロールが止まらない | `finish()` が必ず `stopAutoScroll()` を通る。`pointerId === null` なら tick 自身も自走を止める |
| pointer capture が効かず、ハンドル外へ出るとイベントが切れる | listener を `window` に張っているため capture の有無に依存しない（capture は補助） |
| `preventDefault()` がスクロールを殺しすぎる | `preventDefault` はドラッグが**確定してから**(閾値超え後)しか呼ばない。ハンドル以外は `touch-action` を変えないので通常スクロールは無傷 |
| iOS Safari の実挙動が jsdom と異なる | A3: 実機確認を devnotes に記録する。自動テストの緑を「iOS 対応の実証」と書かない |
| 端スクロール中に指を止めて drop すると古い挿入位置で確定する | `tickAutoScroll()` が毎フレーム `lastClientY` で挿入位置を採り直す。テストで固定 |
| unmount 時の `onCancel` が告知や通信を誤発火させる | `finish(commit, notify)` の `notify=false` で `destroy()` からは呼ばない。契約を型 doc とテストで固定 |
| ドラッグ後にハンドルの click が発火して別の操作が走る | `DragHandleProps` に `onclick` を**定義しない**（施策 3）。経路が型で存在しない |

---

## 施策 3: ドラッグハンドル atom

### 変更箇所

- ファイル: `resources/js/components/atoms/DragHandle.svelte` (**新規**)
- ファイル: `resources/js/components/atoms/DragHandle.types.ts` (**新規**)

### 波及変更

- TypeScript 型定義: `DragHandleProps` を新規 export（既存 atom の `Badge.types.ts` /
  `Button.types.ts` と同じ companion 型ファイル方式に合わせる）
- API Resource/DTO: **なし**
- テストファイル: 単体テストは置かず、施策 4・5 のコンポーネントテストで挙動を検証する
  （atom 単体では「押しても何も起きない」ため、意味のある assert は配線側にしか無い）
- 走査系テスト: `atomic-import-graph`（atom は token/util/external のみ import 可 →
  `@lucide/svelte` は external、自身の `.types.ts` は相対 import で同層 = 適合）、
  `lucide-scoped-import` / `svg-inline-allowlist`（Lucide 経由のみ）、`ds-purity`（token のみ）

### 現行コード

存在しない（新規）。既存 `Button` atom は `onpointerdown` / `onkeydown` を prop に持たないため、
**共有 atom である `Button` に prop を足すのではなく**、専用 atom を新設する
（`Button` への prop 追加は全画面が影響範囲になるのに対し、ここで必要なのは
「掴むための小さな取っ手」という別の役割である。思考原則 4）。

### 変更後コード

`resources/js/components/atoms/DragHandle.types.ts`:

```ts
/**
 * 並べ替えの取っ手 (DragHandle) の props。
 * 「掴む」ことが役割であり、押しても何も起きない点で Button とは別物なので atom を分ける。
 *
 * **`onclick` は意図的に定義しない**。ドラッグの後には click が発火しうるため、
 * click ハンドラを付けられる口を残すと「ドラッグしたのに別の操作が走る」経路が生まれる。
 * props に無ければ呼び出し側は付けられない = 型で経路を消す (design-review R1)。
 */
export interface DragHandleProps {
    /**
     * 何を掴んでいるかの読み上げ。
     * 例: 「手順 2 の並び順を変更 (ドラッグ、または上下キー)」
     */
    ariaLabel: string;
    /** ドラッグ開始 (PointerDragController.start へ中継する) */
    onpointerdown: (event: PointerEvent) => void;
    /** キーボードでの 1 段移動 (ArrowUp / ArrowDown)。呼び出し側が既存の移動関数へ写す */
    onkeydown?: (event: KeyboardEvent) => void;
    testId?: string;
    class?: string;
}
```

`resources/js/components/atoms/DragHandle.svelte`:

```svelte
<script lang="ts">
    import { GripVertical } from "@lucide/svelte";
    import type { DragHandleProps } from "./DragHandle.types";

    let {
        ariaLabel,
        onpointerdown,
        onkeydown,
        testId,
        class: extraClass = "",
    }: DragHandleProps = $props();

    // 小コントロール → rounded-sm (DESIGN.md §Shapes)。影・scale は使わない (§Elevation)。
    // touch-none: ハンドル上のタッチをブラウザのスクロールに奪われないようにする
    //   (これを付けないと iOS Safari で縦ドラッグがページスクロールになる)。
    // select-none: ドラッグ中のテキスト選択を抑止する。
    // **disabled にはしない** (禁止事項 8 / 受け入れ条件 A1)。
    const computedClass = $derived(
        [
            "inline-flex size-8 shrink-0 cursor-grab touch-none items-center justify-center",
            "rounded-sm border border-transparent text-text-secondary select-none",
            "transition-colors duration-150",
            "hover:border-border-strong hover:text-text",
            "focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none",
            "active:cursor-grabbing",
            extraClass,
        ]
            .filter(Boolean)
            .join(" "),
    );
</script>

<button
    type="button"
    class={computedClass}
    aria-label={ariaLabel}
    data-testid={testId}
    {onpointerdown}
    {onkeydown}
>
    <GripVertical class="size-4" aria-hidden="true" />
</button>
```

### TypeScript strict 適合チェック

- [x] props は `DragHandleProps` で型付け（`$props()` の暗黙 any なし）
- [x] `any` / 素の型アサーションなし
- [x] `class` を `class: extraClass` で受ける（既存 atom と同じ命名）
- [x] `ds-purity` 抵触なし（raw palette / hex / 静的 inline style / shadow / gradient /
      scale / 素の rounded / raw text-size をいずれも含まない）

### テスト計画

- [x] 施策 4・5 のコンポーネントテストで、`data-testid` によるハンドルの実在、
      `aria-label` の文言、`ArrowUp` / `ArrowDown` の動作を検証する
- [x] `disabled` 属性を一切持たないこと（禁止事項 8 の回帰防止）を
      ScenarioEditor / TakeStrip 双方のテストで assert する

### リスク

| リスク | 対応 |
|---|---|
| focus できるのに何も起きないコントロールになる | `ArrowUp`/`ArrowDown` で既存の 1 段移動を必ず呼ぶ（受け入れ条件 A1/D6） |
| `touch-none` が広がりすぎてスクロールを殺す | ハンドル要素**だけ**に付ける。行やリストには付けない |
| icon-only ボタンの操作対象が小さすぎる（タッチ） | `size-8` (32px) を最低ラインとし、実機確認 (A3) で押しにくさを確認する |

---

## 施策 4: シナリオ編集への配線

### 変更箇所

- ファイル: `resources/js/components/features/manual/ScenarioEditor.svelte`
  - L224-244（`moveStep` / `movePoint`）
  - L841-951（手順 `<ol>` と急所 `<ol>` の markup）
  - script 冒頭の import 群（L1-32）

### 波及変更

- TypeScript 型定義: **変更なし**（`DraftStep` / `DraftPoint` / `ScenarioDocument` は無変更）
- Inertia Props: **変更なし**（`projectId` / `manualId` / `scenario` の 3 props は無変更）
- API Resource / DTO: **変更なし**（PUT payload は `payloadSteps()` のまま。
  `sort_order` / `parent_cut_id` / `type` はサーバ導出のままで、**順序は配列順が唯一の表現**）
- テストファイル: `tests/js/components/features/manual/ScenarioEditor.test.ts` に追記
- 履歴 util: `resources/js/lib/manual/scenario-history.ts` は**変更なし**
  （並べ替えは既存 `commitStructural` を通るので履歴形式が変わらない）

### 現行コード

```svelte
/** ▲▼ 並べ替え (同一スコープ内のみ。階層をまたぐ移動は提供しない) */
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

markup（抜粋）:

```svelte
<ol class="mt-4 flex flex-col gap-4" data-testid="scenario-steps">
    {#each steps as step, stepIndex (step.clientKey)}
        <li>
            <Card padding="md">
                <div class="flex items-start justify-between gap-2">
                    <h3 class="text-body font-medium text-text">手順 {stepIndex + 1}</h3>
                    <div class="flex items-center gap-1">
                        <Button ... onclick={() => moveStep(stepIndex, -1)} testId="step-{stepIndex}-move-up"> ...
```

### 変更後コード

**(a) import 追加**

```svelte
import { onMount, tick } from "svelte"; // tick は既存 import。onMount を追加
import DragHandle from "@/components/atoms/DragHandle.svelte";
import { moveItem } from "@/lib/dnd/list-reorder";
import { createPointerDrag, type PointerDragState } from "@/lib/dnd/pointer-drag";
```

> `verbatimModuleSyntax: true` のため、型は必ず `type` 修飾子付きで import する
> （`import { createPointerDrag, type PointerDragState }` の形）。

**(b) 移動関数を「任意位置移動」に一本化し、▲▼ はその特殊形にする**

```svelte
/**
 * 並べ替えは「任意位置への移動」1 本に集約する。
 * ▲▼ ボタン・ハンドルのキーボード操作・D&D のすべてがここへ合流するので、
 * undo/redo 履歴・dirty 判定・IME ゲート (runSettled) との整合が 1 箇所で保たれる。
 * 保存 payload は配列順がそのまま順序 (sort_order はサーバ採番) なので、
 * ここで順序表現を作る必要はない。
 */
function moveStepTo(from: number, to: number): void {
    if (from === to || to < 0 || to >= steps.length) return;
    // 告知は runSettled の**中**に置く。runSettled は IME 変換中なら実行を compositionend まで
    // 遅らせ、実行時の再検査で no-op になることもある。外に置くと「移動していないのに
    // 移動しましたと読み上げる」ことになる (design-review R2)。
    runSettled(() => {
        if (from >= steps.length || to >= steps.length) return; // 実行時点の再検査
        commitStructural(() => {
            steps = moveItem(steps, from, to);
        });
        announce(`手順 ${from + 1} を ${to + 1} 番目に移動しました`);
    });
}

/**
 * 急所の移動。
 * **変異の直前にもう一度 step を取り直す**のは意図的である。`runSettled` は IME 変換中に
 * 実行を compositionend まで遅らせるため、呼び出し時点の参照が実行時点でも有効とは限らない。
 * 「呼び出し時点の検査」と「実行時点の検査」を両方置く (design-review R1 の指摘より一段強い対応)。
 */
function movePointTo(stepIndex: number, from: number, to: number): void {
    const step = steps[stepIndex];
    if (step === undefined) return;
    if (from === to || to < 0 || to >= step.points.length) return;
    runSettled(() => {
        const current = steps[stepIndex]; // 実行時点で取り直す
        if (current === undefined) return;
        if (from >= current.points.length || to >= current.points.length) return;
        commitStructural(() => {
            current.points = moveItem(current.points, from, to);
        });
        announce(`急所 ${stepIndex + 1}-${from + 1} を ${to + 1} 番目に移動しました`);
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
```

**(c) 読み上げ領域 (aria-live)**

```svelte
/** 並べ替え結果のスクリーンリーダ告知 (視覚的には出さない) */
let reorderStatus = $state("");
function announce(message: string): void {
    reorderStatus = message;
}
```

```svelte
<p class="sr-only" aria-live="polite" data-testid="scenario-reorder-status">{reorderStatus}</p>
```

**(d) ドラッグ制御の生成と破棄**

```svelte
/** ドラッグ表示状態 (手順リスト / 急所リストで別々に持つ) */
let stepDrag = $state<PointerDragState>({ activeIndex: null, insertionIndex: null });
let pointDrag = $state<PointerDragState>({ activeIndex: null, insertionIndex: null });
/** 急所ドラッグ中の親手順 index (急所は手順をまたがないので 1 つで足りる) */
let pointDragStep = $state<number | null>(null);

/** 手順 <ol> / ドラッグ中の急所 <ol> の実体 (行の実測に使う) */
let stepListEl = $state<HTMLOListElement | null>(null);
let pointListEl: HTMLOListElement | null = null; // ドラッグ中のみ有効 (非 reactive で足りる)

function directRows(list: HTMLElement | null): HTMLElement[] {
    if (list === null) return [];
    return [...list.querySelectorAll<HTMLElement>(":scope > li")];
}

// controller は**マウント時に 1 度だけ**作り、破棄時に必ず destroy する (受け入れ条件 A2)。
// $effect ではなく onMount を使う: これは「派生状態」ではなく
// 「ブラウザ資源 (window listener) をマウント期間だけ持つ」ことであり、
// $effect だと「本体で $state を同期 read しなければ再実行されない」という
// 実装者の注意力に依存した不変条件になる (多重生成のリスク。design-review R1 Critical)。
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
        target instanceof HTMLElement ? target.closest<HTMLOListElement>("ol[data-point-list]") : null;
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
```

**(e) markup（手順リスト。急所リストも同型）**

```svelte
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
                            <!-- 既存の ▲▼ と削除ボタンは無変更 (キーボード経路を消さない) -->
                        </div>
                    </div>
                    ...
                </Card>
            </div>
        </li>
    {/each}
    {#if stepDrag.insertionIndex === steps.length}
        <li class="h-0.5 bg-primary" aria-hidden="true"></li>
    {/if}
</ol>
```

急所側は `<ol ... data-point-list bind:this=...>` の代わりに **属性 `data-point-list` だけ**を
付ける（`bind:this` は使わず `closest()` で引く。手順ごとに ref を持たずに済む）。

### TypeScript strict 適合チェック

- [x] `querySelectorAll<HTMLElement>` / `closest<HTMLOListElement>` の型引数を明示
      （素の `as` を使わない）
- [x] `event.currentTarget` は `instanceof HTMLElement` で絞る
- [x] `steps[stepIndex]?.points` の undefined を早期 return で処理
- [x] `PointerDragState` を型 import して `$state` の型を明示
- [x] `ReturnType<typeof createPointerDrag>` で controller の型を明示（`any` なし）
- [x] `verbatimModuleSyntax: true` に従い型は `type` 修飾子付きで import
- [x] `steps[stepIndex]` は必ず const に受けて undefined 検査（`?.` の結果を別式で再アクセスしない）
- [x] `pnpm typecheck` / `pnpm lint` / `svelte-no-undef-gate` が緑

### テスト計画

`tests/js/components/features/manual/ScenarioEditor.test.ts` に describe を追加する。

- [x] 既存テスト（`step-1-move-up` / `step-0-move-down` を使う 3 箇所）は**無変更で緑**
      であること（▲▼ の外形と挙動を変えていないことの証明）
- [x] 新規: ハンドルの pointerdown → pointermove（閾値超え、目的行の中点より下）→ pointerup で
      **`payloadSteps` の順序が入れ替わる**こと（保存ボタン押下時の PUT body で検証する。
      `fetch` mock の body を JSON.parse して `steps[].id` の並びを assert）
- [x] 新規: D&D の直後に `scenario-dirty-indicator` が出ること（dirty 判定との整合）
- [x] 新規: D&D の直後に「元に戻す」で**元の順序へ戻る**こと（undo 履歴との整合）
- [x] 新規: 急所行の D&D が**同じ手順の中だけ**で完結すること
      （別手順の急所 `<ol>` の行は `rows()` に入らない = `closest()` による絞り込みの検証）
- [x] 新規: ドラッグ中に `Escape` を押すと順序が変わらないこと
- [x] 新規（**多点入力の競合・同一 controller**。design-review R2 Critical の回帰防止）:
      手順 A の急所ハンドルで `pointerdown`（pointerId=1）→ 閾値超えの `pointermove` →
      その状態で手順 B の急所ハンドルに別の `pointerdown`（pointerId=2）→
      pointerId=1 で `pointerup` したとき、**手順 A の急所だけが動き、手順 B は無変更**であること
- [x] 新規（**controller をまたぐ排他・双方向**。design-review R3 Critical の回帰防止）:
      - 手順ドラッグ中に急所ハンドルの `pointerdown` を出しても**急所ドラッグは始まらない**
        （急所側の drop 相当のイベントを続けても急所の順序が変わらない）
      - 急所ドラッグ中に手順ハンドルの `pointerdown` を出しても**手順ドラッグは始まらない**
      - どちらの向きでも、**拒否された 2 本目が 1 本目の対象と結果を変えない**
        （1 本目を drop したとき、掴んだとおりの行が期待どおりの位置へ動く）
- [x] 新規（**IME 遅延中の告知**。design-review R2 の回帰防止）:
      `compositionstart` 中に D&D を確定させると、その時点では
      `scenario-reorder-status` が空のままで、`compositionend` の後に初めて
      告知が入り順序が変わること
- [x] 新規: ハンドルに focus して `ArrowUp` / `ArrowDown` で 1 段移動すること
- [x] 新規: ハンドルが `disabled` 属性を持たないこと（禁止事項 8）
- [x] 新規: 先頭行で `ArrowUp` を押すと順序が変わらず、
      `scenario-reorder-status` に「これ以上、上へは移動できません」が入ること（A1）
- [x] テスト用の DOM 実測: `HTMLElement.prototype.getBoundingClientRect` を
      `vi.spyOn` で行ごとに固定値（`top = index * 100`, `height = 100`）へ差し替える
- [x] 個別の `DatabaseTransactions` を使っていない（JS テストのため対象外）

### リスク

| リスク | 対応 |
|---|---|
| `steps = moveItem(...)` の再代入で keyed each が全再描画になる | key は `clientKey` で変わらないため DOM は移動のみ。既存 undo/redo も `steps` 再代入で動いている（`restoreFrom`）ので前例がある |
| controller が多重生成され、1 回のドロップで 2 回移動する | `onMount` で 1 度だけ生成する（`$effect` は使わない）。テストで「1 回のドロップで順序が 1 回だけ変わる」ことを assert する |
| IME 変換中の D&D が履歴を壊す | `runSettled` を通すので既存の pending キューに乗る（構造操作と同じ扱い） |
| ドラッグ中に別タブ/別操作でリストが縮む | `moveStepTo` / `movePointTo` が**実行時点**でも範囲を再検査する（`runSettled` の遅延実行があるため）。範囲外は no-op で、告知も出ない |
| 2 本目の指が 1 本目のドラッグ対象をすり替える | `start()` が受理したときだけスコープを確定する。多点入力のテストで固定（R2 Critical） |
| 手順ドラッグと急所ドラッグが同時に走り、先に確定した並べ替えで `pointDragStep` が別の手順を指す | コンポーネント全体で `dragOwner` を 1 つだけ持ち、受理時点から排他する。双方向のテストで固定（R3 Critical） |
| `-top-2` の目印がカード間の `gap-4` の中央に来ない | 目印は視覚的補助であり、判定は `insertionIndexFromRects` が持つ。位置は実装時に微調整（値ではなく仕組みで判定している。思考原則） |

---

## 施策 5: 撮影 PWA テイク列への配線

### 変更箇所

- ファイル: `resources/js/components/features/capture/TakeStrip.svelte`
  - L106-109（`move` の定義）
  - L188-220（リスト container と ▲▼ の markup）
  - script 冒頭の import 群

### 波及変更

- TypeScript 型定義: **変更なし**（`CaptureTake` / `CaptureCut` は無変更）
- API Resource / DTO: **変更なし**。既存の `PATCH /app/projects/{p}/manuals/{m}/cuts/{c}/takes/{t}`
  に `{ position }` を送る既存経路をそのまま使う。
  `UpdateCaptureTakeRequest`（`position` は `nullable|integer|min:0`）・
  `CaptureTakeService::update()` / `reorderWithinCut()` は**無変更**
- Inertia Props: **変更なし**（`onChanged` によるサーバ再取得も現行どおり）
- テストファイル: `tests/js/components/features/capture/TakeStrip.test.ts` に追記

### 現行コード

```svelte
async function run(take: CaptureTake, action: () => Promise<Response>): Promise<void> {
    error = null;
    busyTakeId = take.id;
    try {
        const response = await action();
        if (!response.ok) {
            error = await extractErrorMessage(response);
            return;
        }
        onChanged();
    } catch {
        error = "通信に失敗しました。ネットワークを確認してください。";
    } finally {
        busyTakeId = null;
    }
}

const move = (take: CaptureTake, position: number) =>
    run(take, () => captureJson(takeUrl(take), "PATCH", { position: Math.max(0, position) }));
```

```svelte
<div class="flex shrink-0 flex-col gap-1">
    <Button variant="ghost" size="sm" iconOnly ariaLabel="上へ"
        onclick={() => move(take, index - 1)} testId={`take-up-${take.id}`}>
        <ChevronUp class="size-4" aria-hidden="true" />
    </Button>
    <Button variant="ghost" size="sm" iconOnly ariaLabel="下へ"
        onclick={() => move(take, index + 1)} testId={`take-down-${take.id}`}>
        <ChevronDown class="size-4" aria-hidden="true" />
    </Button>
</div>
```

サーバ側（無変更。`position` の意味の根拠）:

```php
/** cut 内の並べ替え (対象を position に挿入し 0..n-1 でサーバ再採番)。行ロック下で呼ぶ */
private function reorderWithinCut(Cut $lockedCut, Take $target, int $position): void
{
    $ordered = $lockedCut->takes()->whereKeyNot($target->id)->orderBy('sort_order')->orderBy('id')->get()->all();
    $position = min($position, count($ordered));
    array_splice($ordered, $position, 0, [$target]);
    ...
}
```

> **`position` の基準（受け入れ条件 A5 の確定）**: サーバは「対象を除いた配列」へ `position` で
> splice する。対象を除いた配列に index `p` で挿入した結果、その要素は**全体配列でも index `p`**
> に来る。したがって **`position` = 移動後の全体配列での 0 始まり index = 最終 index** であり、
> `toFinalIndex()` の出力をそのまま渡してよい。既存 ▲▼ の `index + 1`（1 段下げ）もこの定義と
> 一致する（`[A,B,C]` で A を position 1 → 除外後 `[B,C]` に 1 で挿入 → `[B,A,C]`）。

### 変更後コード

**(a) import 追加**

```svelte
import { onMount } from "svelte";
import DragHandle from "@/components/atoms/DragHandle.svelte";
import { createPointerDrag, type PointerDragState } from "@/lib/dnd/pointer-drag";
```

**(b) `run()` を成否が分かる形に変える（告知を成功時だけにするため）**

```svelte
/**
 * 共通の XHR 実行。**成功したら true** を返す。
 * 戻り値を足したのは、並べ替えの読み上げ (aria-live) を**成功時だけ**にするためである。
 * 失敗しても「移動しました」と読み上げると、スクリーンリーダ利用者にだけ嘘をつくことになる
 * (視覚利用者は role="alert" のエラーを見る。design-review R1 の指摘)。
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
```

**(c) 並べ替えの単一入口**

```svelte
/**
 * テイクの並べ替え。▲▼・ハンドルのキーボード・D&D のすべてがここへ合流する。
 * position は**最終 index**（移動後の全体配列での 0 始まり index）。
 * サーバの reorderWithinCut が対象を除いた配列へ splice するため両者は一致する。
 *
 * クライアント側で楽観的に並べ替えない（サーバ権威）。理由は 2 つ:
 *   1. 採用テイク (adopted_take_id) と親の再取得 (onChanged) が同じ経路で更新されるため、
 *      ローカル順序を別に持つと 2 つの真実ができる
 *   2. 既存の ▲▼ と同じ挙動になり、並べ替え手段ごとに体感が割れない
 *
 * 告知は**成功後**に行う（失敗は既存の role="alert" が伝えるので二重に出さない）。
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

let reorderStatus = $state("");
function announce(message: string): void {
    reorderStatus = message;
}
```

既存 `move` は残す（`reorderTo` が呼ぶ）。▲▼ の `onclick` は
`() => move(take, index - 1)` から `() => moveBy(index, -1)` へ差し替える。

> **既存挙動の変化（意図的）**: 現行は先頭で「上へ」を押すと `position: 0` の PATCH が飛び、
> サーバ側 no-op のうえで親の再取得が走っていた。変更後の**端操作の期待値**を次に固定する:
>
> | 観点 | 変更前 | 変更後 |
> |---|---|---|
> | 通信 (`fetch`) | 発生する（no-op PATCH） | **発生しない** |
> | `busyTakeId` | 立つ | **立たない** |
> | 親の再取得 (`onChanged`) | 呼ばれる | **呼ばれない** |
> | 利用者へのフィードバック | 無し（見た目も変わらない） | **aria-live で理由を告知** |
> | ボタンの活性 | 活性 | 活性のまま（禁止事項 8 に適合） |

**(d) ドラッグ制御**

```svelte
let takeDrag = $state<PointerDragState>({ activeIndex: null, insertionIndex: null });
let listEl = $state<HTMLDivElement | null>(null);
let dragCtl: ReturnType<typeof createPointerDrag> | null = null;

// $effect ではなく onMount (施策 4 と同じ理由: マウント期間だけ持つブラウザ資源であり、
// 派生状態ではない)。
onMount(() => {
    dragCtl = createPointerDrag({
        rows: () =>
            listEl === null ? [] : [...listEl.querySelectorAll<HTMLElement>(":scope > [data-reorder-index]")],
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
```

**(e) markup**

```svelte
<div class="flex flex-col gap-2" data-testid={`take-strip-${cut.id}`} bind:this={listEl}>
    ...
    {#each cut.takes as take, index (take.id)}
        <div
            class="relative flex flex-wrap items-center ... {takeDrag.activeIndex === index ? 'opacity-50' : ''}"
            data-testid={`take-item-${take.id}`}
            data-reorder-index={index}
        >
            {#if takeDrag.insertionIndex === index}
                <div class="absolute inset-x-0 -top-1 h-0.5 bg-primary" aria-hidden="true"></div>
            {/if}
            <DragHandle
                ariaLabel={`テイク ${index + 1} の並び順を変更 (ドラッグ、または上下キー)`}
                onpointerdown={(event) => void dragCtl?.start(index, event)}
                onkeydown={(event) => onHandleKeydown(event, index)}
                testId={`take-drag-${take.id}`}
            />
            <div class="flex shrink-0 flex-col gap-1">
                <!-- 既存の 上へ / 下へ ボタン (onclick だけ moveBy へ差し替え) -->
            </div>
            ...
        </div>
    {/each}
    <p class="sr-only" aria-live="polite" data-testid="take-reorder-status">{reorderStatus}</p>
    {#if error}
        <p class="text-caption text-danger" role="alert" data-testid="take-strip-error">{error}</p>
    {/if}
</div>
```

> `listEl` を `data-testid={take-strip-...}` の div にそのまま bind するため、
> `:scope > [data-reorder-index]` の絞り込みで**行だけ**を採る
> （末尾の告知段落・エラー段落は `data-reorder-index` を持たないので混ざらない）。

### TypeScript strict 適合チェック

- [x] `querySelectorAll<HTMLElement>` の型引数を明示
- [x] `cut.takes[from]` の undefined を早期 return で処理
- [x] `run()` の戻り値を `Promise<boolean>` に変更（`finally` の `busyTakeId = null` は維持。
      `finally` に `return` を書かない = 戻り値を握り潰さない）
- [x] `void reorderTo(...)` で floating promise を明示的に破棄
- [x] `PointerDragState` / `ReturnType<typeof createPointerDrag>` で型明示（`any` なし）
- [x] `verbatimModuleSyntax: true` に従い型は `type` 修飾子付きで import
- [x] `pnpm typecheck` / `pnpm lint` が緑

### テスト計画

`tests/js/components/features/capture/TakeStrip.test.ts` に describe を追加する。

- [x] 新規: ハンドルの pointerdown → pointermove（3 行目の中点より下）→ pointerup で
      **`PATCH` の body が `{ position: <最終 index> }`** になること（A5 の本丸。
      3 要素で「1 番目を 3 番目へ」= `position: 2`、「3 番目を 1 番目へ」= `position: 0`）
- [x] 新規: 位置が変わらない drop では **`fetch` が呼ばれない**こと
- [x] 新規: ドラッグ中の `Escape` / `pointercancel` で `fetch` が呼ばれないこと
- [x] 新規: ハンドル focus 中の `ArrowUp` / `ArrowDown` が既存 ▲▼ と同じ PATCH を出すこと
- [x] 新規: 先頭で `ArrowUp` / 末尾で `ArrowDown` は上の表のとおり
      **`fetch` を出さず / 採用ボタンが loading にならず / `onChanged` を呼ばず /
      `take-reorder-status` に理由が入る**こと（A1。既存挙動からの意図的な変更を 4 点で固定）
- [x] 新規: PATCH が 422 を返したら既存の `take-strip-error`（`role="alert"`）に
      サーバ文言が出ること（失敗経路が D&D でも同じであることの証明）
- [x] 新規: **PATCH が失敗したときに `take-reorder-status` が空のままである**こと
      （成功していないのに「移動しました」と読み上げない。design-review R1 の指摘の回帰防止）
- [x] 新規: ハンドルが `disabled` 属性を持たないこと（禁止事項 8）
- [x] 既存テスト（採用 / 削除 / DL ACK / プレビュー）は**無変更で緑**であること
- [x] `getBoundingClientRect` は施策 4 と同じ方法でテストごとに固定する
- [x] 個別の `DatabaseTransactions` を使っていない（JS テストのため対象外）

### リスク

| リスク | 対応 |
|---|---|
| drop 後、サーバ再取得までの間に行が元位置へ戻って見える | 既存 ▲▼ と同じ挙動。`run()` が `busyTakeId` を立てるので操作中であることは伝わる。楽観更新は「2 つの真実」を作るため採らない（設計判断として明記） |
| 端での PATCH をやめたことで、意図せず何かが壊れる | 既存テストに端 PATCH を assert するものは無い（確認済み）。no-op PATCH と再取得が消えるだけ。期待値は上の 4 点表で固定 |
| `run()` の戻り値追加が既存呼び出しを壊す | 既存 4 箇所（`adopt` / `remove` / `downloadAndAck` / `confirmDelete`）は戻り値を使わない。`adoptFromPreview` の成否判定は現行どおり `error === null` のままにする（判定を 2 つに増やさない） |
| ドラッグ中に親の再取得（ポーリング等）でリストが差し替わる | `rows()` は毎 move で DOM を採り直し、`reorderTo` が範囲を再検査する。範囲外は no-op |
| ハンドルが増えて 1 行が横に広がりスマホで折り返す | 行は既に `flex-wrap`。ハンドルは `shrink-0` で最左に固定。実機確認 (A3) の確認項目に入れる |

---

## 施策 6: jsdom に無い pointer capture のスタブ

### 変更箇所

- ファイル: `tests/js/setup.ts`（末尾の既存スタブ群の隣に追加）
- ファイル: `tests/js/support/pointer-capture.ts`（**新規**。capture 非対応を模す型付き helper）

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 本ファイル自体がテスト基盤

### 現行コード

`tests/js/setup.ts` は `Element.prototype.animate` / `ResizeObserver` /
`Element.prototype.scrollTo` / `scrollIntoView` を「未実装なら最小スタブ」の形で補っている。

### 変更後コード

```ts
// jsdom は Pointer capture (setPointerCapture / releasePointerCapture / hasPointerCapture) を
// 実装しない。並べ替えの制御 (resources/js/lib/dnd/pointer-drag.ts) は機能検出して
// 無い環境でも完走するが、「capture がある環境」の分岐もテストで通せるよう
// 実際の捕捉状態を覚える最小スタブを入れる。
if (typeof Element !== "undefined" && typeof Element.prototype.setPointerCapture !== "function") {
    const captured = new WeakMap<Element, Set<number>>();
    Element.prototype.setPointerCapture = function (pointerId: number): void {
        const ids = captured.get(this) ?? new Set<number>();
        ids.add(pointerId);
        captured.set(this, ids);
    };
    Element.prototype.releasePointerCapture = function (pointerId: number): void {
        captured.get(this)?.delete(pointerId);
    };
    Element.prototype.hasPointerCapture = function (pointerId: number): boolean {
        return captured.get(this)?.has(pointerId) ?? false;
    };
}
```

### TypeScript strict 適合チェック

- [x] `WeakMap<Element, Set<number>>` で型を明示（`any` なし）
- [x] 既存スタブと同じ「未実装なら入れる」条件付きにする（実装がある環境を上書きしない）

**capture 非対応を模す型付き helper**（`tests/js/support/pointer-capture.ts` に置く）:

```ts
/**
 * pointer capture が実装されていない環境 (古い WebKit 等) を模して run を実行する。
 *
 * `Element.prototype.setPointerCapture = undefined` は
 * `(pointerId: number) => void` 型への undefined 代入になり型エラーになるため、
 * `Object.defineProperty` で差し替えて finally で必ず戻す
 * (delete も生の代入も使わない。design-review R1 の指摘)。
 */
export async function withoutPointerCapture(run: () => void | Promise<void>): Promise<void> {
    const original = Object.getOwnPropertyDescriptor(Element.prototype, "setPointerCapture");
    Object.defineProperty(Element.prototype, "setPointerCapture", {
        value: undefined,
        configurable: true,
        writable: true,
    });
    try {
        await run();
    } finally {
        if (original === undefined) {
            Reflect.deleteProperty(Element.prototype, "setPointerCapture");
        } else {
            Object.defineProperty(Element.prototype, "setPointerCapture", original);
        }
    }
}
```

### テスト計画

- [x] `pointer-drag.test.ts` の 1 ケースを `withoutPointerCapture(async () => { … })` で包み、
      capture が無くても一連の流れ（開始 → 移動 → 確定）が完走することを検証する
- [x] スタブ導入で既存テストが 1 件も壊れないこと（`pnpm test` 全緑）
- [x] helper は `tests/js/support/` に置く（`tests/js/**/*.test.ts` の include に入らないので
      テストとして実行されない = 目録 gate に影響しない）

### リスク

| リスク | 対応 |
|---|---|
| スタブが本物と挙動が違い、テストが実ブラウザを保証しない | 保証範囲を誇張しない。**capture の有無で結果が変わらない設計**（listener は window）にしてあり、スタブは分岐網羅のためだけに置く。iOS Safari の実挙動は A3 の実機確認が担う |

---

## 施策 7: テストと受け入れ手順

### 変更箇所

- 新規: `tests/js/lib/dnd/list-reorder.test.ts` / `tests/js/lib/dnd/pointer-drag.test.ts`
- 追記: `tests/js/components/features/manual/ScenarioEditor.test.ts`
- 追記: `tests/js/components/features/capture/TakeStrip.test.ts`

### 波及変更

- **テスト目録**: `scripts/test-inventory-config.ts` が Vitest の `include` の正本であり、
  `scripts/vitest-inventory-gate.test.ts` が FS 走査と突き合わせる。
  **確認済み**: root project の include は `["tests/js/**/*.test.ts", "scripts/**/*.test.ts"]` で、
  新規の `tests/js/lib/dnd/*.test.ts` は既存 glob に入る = **目録の変更は不要**。
  （テスト置き場を `tests/js/` の外へ動かす場合のみ目録を同じ変更で直すこと）
- **CI**: 追加の workflow は作らない（`ci-workflow-inventory.test.ts` の W17 等に触れない）

### テスト方針（D&D をどうテストするか）

D&D の実挙動は DOM とブラウザ実装に強く依存する。そこで**検証対象を 3 層に分ける**:

| 層 | 何を固定するか | 手段 |
|---|---|---|
| 1. 意味論 | どこに落ちたら何番目になるか（off-by-one） | `list-reorder.test.ts`（純関数・表駆動・DOM なし） |
| 2. 生死 | ポインタの開始/確定/取消/解放が漏れないか | `pointer-drag.test.ts`（jsdom で `PointerEvent` を dispatch。`getBoundingClientRect` は spy で固定） |
| 3. 配線 | 落としたら既存の保存経路が期待どおり動くか | 2 つのコンポーネントテスト（PUT body の順序 / PATCH の position） |

**実機でしか分からないこと**（タッチの取りこぼし、慣性スクロールとの競合、標準外の
ジェスチャ割り込み）は自動テストの対象外であり、A3 の実機確認で扱う。
**Vitest の緑を「iOS Safari で動く証明」と書かない。**

**design-review R2 / R3 で追加が必須になった 6 ケース**（層と担当ファイルを明記しておく）:

| # | ケース | 層 | 置き場所 |
|---|---|---|---|
| R2-1 | 多点入力: 2 本目の pointerdown が 1 本目のドラッグ対象をすり替えない（同一 controller） | 3（配線） | `ScenarioEditor.test.ts` |
| R2-2 | IME 変換中に確定した D&D は、compositionend まで順序も告知も変わらない | 3（配線） | `ScenarioEditor.test.ts` |
| R2-3 | 自動スクロールの rAF は手動 1 フレーム実行（同期即時実行は無限再帰） | 2（生死） | `pointer-drag.test.ts` |
| R3-1 | 手順ドラッグ中に急所ドラッグが始まらない（controller またぎの排他） | 3（配線） | `ScenarioEditor.test.ts` |
| R3-2 | 急所ドラッグ中に手順ドラッグが始まらない（逆向きも固定する） | 3（配線） | `ScenarioEditor.test.ts` |
| R3-3 | `start()` を 2 回呼ぶと 2 回目は `false` を返し、1 本目の対象が保持される | 2（生死） | `pointer-drag.test.ts` |

### 受け入れ手順（実装完了の条件）

1. `pnpm test` / `pnpm typecheck` / `pnpm lint` / `pnpm build` が緑
2. `composer test` / `composer phpstan` / `vendor/bin/pint --test` が**無変更のまま**緑
   （PHP 差分が 0 件であることを併せて確認する）
3. **iOS Safari 実機**（PWA standalone を含む）で以下を確認し、日時・端末・OS バージョン・
   結果を `devnotes/20260816-1021-drag-and-drop-reordering/ios-acceptance.md` に記録する（A3）:
   - テイク列のハンドルを縦にドラッグして順序が変わる
   - ハンドル以外を触ったときは従来どおりページがスクロールする
   - リスト末尾が画面外にあるとき、端に寄せると自動スクロールして落とせる
   - ドラッグ中に着信・アプリ切替が入っても（`pointercancel`）操作が中断されるだけで
     順序が壊れない
4. シナリオ編集を **Chrome / Safari デスクトップ**でマウス操作し、
   undo（元に戻す）で元の順序に戻ることを確認する

> **実機確認記録の存在を自動テストで強制しない**（design-review R1 Suggestion への回答）。
> ファイルが在ることは**実機で確認した事実の証明にならない**ため、存在チェックを緑にすると
> 「機械が確認した」という誤った安心を作る。これは `docs/supported-browsers.md` が繰り返し
> 戒めている「WebKit レーンの green を iOS Safari 対応の実証と言い換えない」と同型の誤りである。
> よって受け入れ条件 3 は**人間のレビューで見る運用**とし、記録が無ければ完了にしない。

### リスク

| リスク | 対応 |
|---|---|
| 新規テストディレクトリが `include` に載らず走らない | 確認済み（`tests/js/**/*.test.ts` に含まれる）。テスト置き場を動かす場合のみ目録を直す |
| 実機確認が省略されて「対応済み」と書かれる | 記録ファイルを受け入れ条件 3 に明記。機械強制はしない（上記の理由）ため、レビュー時に人間が見る |

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | (1) 新規ファイル 5 本 + 既存 3 ファイル改修で 1 つの機能が完結し、途中状態に意味が無い（純関数だけ入れても UI は変わらず、UI だけ入れても計算が無い）。(2) 触るのはフロントの 2 画面と共通 lib のみで、他ドメインの同時作業とファイルが重ならない。(3) サーバ・DB・API 契約に触れないためマイグレーション順序の制約が無い |
| 競合リスク | `ScenarioEditor.svelte` / `TakeStrip.svelte` を触る他タスクがあれば衝突する（どちらも大きなファイル）。`tests/js/setup.ts` は追記のみで衝突しにくい。`scripts/test-inventory-config.ts` を触る場合は他タスクと確認する |
| 実装順序 | 施策 1 → 2 → 6（テスト基盤）→ 3 → 4 → 5 → 7。1・2 はテストファースト（fail を見てから実装）で進められる |

## 使命・禁止事項の最終チェック

- **使命への寄与**: 「編集ゼロ」の中核である「AI 生成シナリオの構成順の修正」と
  「採用候補テイクの入れ替え」を、それぞれ 1 ジェスチャに縮める。撮影 PWA（現場）と
  PC 編集（設計）の両方に効く。
- **禁止事項 1（テストなしの完了報告）**: 全施策にテストを割り付け済み。
  D&D の弱点（テストしづらさ）に対しては 3 層分割で対処した。
- **禁止事項 8（disabled）**: ハンドルも ▲▼ も disabled にしない。端での要求は
  aria-live で告知する。
- **禁止事項 2/4/5/6/7（PHP 側）**: PHP を変更しないため抵触しない。
- **禁止事項 9（Artifact）**: 成果物は本 devnotes 配下のファイルのみ。
- **依存追加なし**: 新しい npm パッケージを入れないため、
  `docs/supply-chain/review-checklist.md` の審査対象は増えない。
  将来ライブラリ化する場合は同 §（新規依存の審査観点）に沿って別途審議する。

## 実装差分 (git diff HEAD -- resources/ tests/)

```diff
diff --git a/resources/js/components/atoms/DragHandle.svelte b/resources/js/components/atoms/DragHandle.svelte
new file mode 100644
index 0000000..b2a6e86
--- /dev/null
+++ b/resources/js/components/atoms/DragHandle.svelte
@@ -0,0 +1,42 @@
+<script lang="ts">
+    import { GripVertical } from "@lucide/svelte";
+    import type { DragHandleProps } from "./DragHandle.types";
+
+    let {
+        ariaLabel,
+        onpointerdown,
+        onkeydown,
+        testId,
+        class: extraClass = "",
+    }: DragHandleProps = $props();
+
+    // 小コントロール → rounded-sm (DESIGN.md §Shapes)。影・scale は使わない (§Elevation)。
+    // touch-none: ハンドル上のタッチをブラウザのスクロールに奪われないようにする
+    //   (これを付けないと iOS Safari で縦ドラッグがページスクロールになる)。
+    // select-none: ドラッグ中のテキスト選択を抑止する。
+    // **disabled にはしない** (禁止事項 8 / 受け入れ条件 A1)。
+    const computedClass = $derived(
+        [
+            "inline-flex size-8 shrink-0 cursor-grab touch-none items-center justify-center",
+            "rounded-sm border border-transparent text-text-secondary select-none",
+            "transition-colors duration-150",
+            "hover:border-border-strong hover:text-text",
+            "focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none",
+            "active:cursor-grabbing",
+            extraClass,
+        ]
+            .filter(Boolean)
+            .join(" "),
+    );
+</script>
+
+<button
+    type="button"
+    class={computedClass}
+    aria-label={ariaLabel}
+    data-testid={testId}
+    {onpointerdown}
+    {onkeydown}
+>
+    <GripVertical class="size-4" aria-hidden="true" />
+</button>
diff --git a/resources/js/components/atoms/DragHandle.types.ts b/resources/js/components/atoms/DragHandle.types.ts
new file mode 100644
index 0000000..5e0fe00
--- /dev/null
+++ b/resources/js/components/atoms/DragHandle.types.ts
@@ -0,0 +1,21 @@
+/**
+ * 並べ替えの取っ手 (DragHandle) の props。
+ * 「掴む」ことが役割であり、押しても何も起きない点で Button とは別物なので atom を分ける。
+ *
+ * **`onclick` は意図的に定義しない**。ドラッグの後には click が発火しうるため、
+ * click ハンドラを付けられる口を残すと「ドラッグしたのに別の操作が走る」経路が生まれる。
+ * props に無ければ呼び出し側は付けられない = 型で経路を消す (design-review R1)。
+ */
+export interface DragHandleProps {
+    /**
+     * 何を掴んでいるかの読み上げ。
+     * 例: 「手順 2 の並び順を変更 (ドラッグ、または上下キー)」
+     */
+    ariaLabel: string;
+    /** ドラッグ開始 (PointerDragController.start へ中継する) */
+    onpointerdown: (event: PointerEvent) => void;
+    /** キーボードでの 1 段移動 (ArrowUp / ArrowDown)。呼び出し側が既存の移動関数へ写す */
+    onkeydown?: (event: KeyboardEvent) => void;
+    testId?: string;
+    class?: string;
+}
diff --git a/resources/js/components/features/capture/TakeStrip.svelte b/resources/js/components/features/capture/TakeStrip.svelte
index 6df1920..cbee559 100644
--- a/resources/js/components/features/capture/TakeStrip.svelte
+++ b/resources/js/components/features/capture/TakeStrip.svelte
@@ -1,11 +1,14 @@
 <script lang="ts">
+    import { onMount } from "svelte";
     import { Check, ChevronDown, ChevronUp, Download, Film, Pencil, Play, Trash2 } from "@lucide/svelte";
     import Badge from "@/components/atoms/Badge.svelte";
     import Button from "@/components/atoms/Button.svelte";
+    import DragHandle from "@/components/atoms/DragHandle.svelte";
     import TakeCommentDialog from "@/components/features/capture/TakeCommentDialog.svelte";
     import TakePreviewDialog from "@/components/features/capture/TakePreviewDialog.svelte";
     import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
     import { captureJson, extractErrorMessage } from "@/lib/capture/http";
+    import { createPointerDrag, type PointerDragState } from "@/lib/dnd/pointer-drag";
     import { takeUrl as buildTakeUrl } from "@/lib/capture/take-endpoints";
     import type { CaptureCut, CaptureTake } from "@/types/capture";
 
@@ -88,18 +91,28 @@
         return buildTakeUrl({ projectId, manualId, cutId: cut.id }, take.id, suffix);
     }
 
-    async function run(take: CaptureTake, action: () => Promise<Response>): Promise<void> {
+    /**
+     * 共通の XHR 実行。**成功したら true** を返す。
+     * 戻り値を足したのは、並べ替えの読み上げ (aria-live) を**成功時だけ**にするためである。
+     * 失敗しても「移動しました」と読み上げると、スクリーンリーダ利用者にだけ嘘をつくことになる
+     * (視覚利用者は role="alert" のエラーを見る)。
+     * 既存の呼び出し側 (adopt / remove / downloadAndAck / confirmDelete) は戻り値を無視するだけで
+     * 無変更のまま動く。
+     */
+    async function run(take: CaptureTake, action: () => Promise<Response>): Promise<boolean> {
         error = null;
         busyTakeId = take.id;
         try {
             const response = await action();
             if (!response.ok) {
                 error = await extractErrorMessage(response);
-                return;
+                return false;
             }
             onChanged();
+            return true;
         } catch {
             error = "通信に失敗しました。ネットワークを確認してください。";
+            return false;
         } finally {
             busyTakeId = null;
         }
@@ -110,6 +123,69 @@
     const move = (take: CaptureTake, position: number) =>
         run(take, () => captureJson(takeUrl(take), "PATCH", { position: Math.max(0, position) }));
 
+    // --- 並べ替え (▲▼ / ハンドルのキーボード / D&D の合流点) ---
+
+    let reorderStatus = $state("");
+    function announce(message: string): void {
+        reorderStatus = message;
+    }
+
+    /**
+     * テイクの並べ替え。▲▼・ハンドルのキーボード・D&D のすべてがここへ合流する。
+     * position は**最終 index** (移動後の全体配列での 0 始まり index)。
+     * サーバの reorderWithinCut が対象を除いた配列へ splice するため両者は一致する。
+     *
+     * クライアント側で楽観的に並べ替えない (サーバ権威)。理由は 2 つ:
+     *   1. 採用テイク (adopted_take_id) と親の再取得 (onChanged) が同じ経路で更新されるため、
+     *      ローカル順序を別に持つと 2 つの真実ができる
+     *   2. 既存の ▲▼ と同じ挙動になり、並べ替え手段ごとに体感が割れない
+     *
+     * 告知は**成功後**に行う (失敗は既存の role="alert" が伝えるので二重に出さない)。
+     */
+    async function reorderTo(from: number, to: number): Promise<void> {
+        const take = cut.takes[from];
+        if (take === undefined || from === to || to < 0 || to >= cut.takes.length) return;
+        const label = `テイク ${from + 1} を ${to + 1} 番目に移動しました`;
+        if (await move(take, to)) announce(label);
+    }
+
+    /** ▲▼ とハンドルのキーボードの共通経路 (1 段移動)。端は disabled にせず告知する */
+    function moveBy(index: number, delta: -1 | 1): void {
+        const to = index + delta;
+        if (to < 0 || to >= cut.takes.length) {
+            // 端: 通信しない / busy にしない / 再取得しない。理由だけ告知する
+            announce(delta < 0 ? "これ以上、上へは移動できません" : "これ以上、下へは移動できません");
+            return;
+        }
+        void reorderTo(index, to);
+    }
+
+    let takeDrag = $state<PointerDragState>({ activeIndex: null, insertionIndex: null });
+    let listEl = $state<HTMLDivElement | null>(null);
+    let dragCtl: ReturnType<typeof createPointerDrag> | null = null;
+
+    // $effect ではなく onMount (マウント期間だけ持つブラウザ資源であり、派生状態ではない)。
+    onMount(() => {
+        dragCtl = createPointerDrag({
+            rows: () =>
+                listEl === null
+                    ? []
+                    : [...listEl.querySelectorAll<HTMLElement>(":scope > [data-reorder-index]")],
+            onState: (state) => (takeDrag = state),
+            onCommit: (from, to) => void reorderTo(from, to),
+        });
+        return () => {
+            dragCtl?.destroy();
+            dragCtl = null;
+        };
+    });
+
+    function onHandleKeydown(event: KeyboardEvent, index: number): void {
+        if (event.key !== "ArrowUp" && event.key !== "ArrowDown") return;
+        event.preventDefault();
+        moveBy(index, event.key === "ArrowUp" ? -1 : 1);
+    }
+
     // 再生ボタン押下: 撮影中はエラー表示して開かない (押下時エラー。disabled 禁止)。
     function openPreview(take: CaptureTake): void {
         error = null;
@@ -187,17 +263,28 @@
     }
 </script>
 
-<div class="flex flex-col gap-2" data-testid={`take-strip-${cut.id}`}>
+<div class="flex flex-col gap-2" data-testid={`take-strip-${cut.id}`} bind:this={listEl}>
     {#if cut.takes.length === 0}
         <p class="text-caption text-text-secondary">テイクはまだありません。撮影してください。</p>
     {/if}
     {#each cut.takes as take, index (take.id)}
         <div
-            class="flex flex-wrap items-center gap-x-2 gap-y-2 rounded-md border border-border bg-surface px-3 py-2 sm:flex-nowrap {take.downloaded
+            class="relative flex flex-wrap items-center gap-x-2 gap-y-2 rounded-md border border-border bg-surface px-3 py-2 sm:flex-nowrap {take.downloaded
                 ? 'border-border-strong'
-                : ''}"
+                : ''} {takeDrag.activeIndex === index ? 'opacity-50' : ''}"
             data-testid={`take-item-${take.id}`}
+            data-reorder-index={index}
         >
+            {#if takeDrag.insertionIndex === index}
+                <!-- 落とし先の目印。影・scale は使わない (DESIGN.md §Elevation) -->
+                <div class="absolute inset-x-0 -top-1 h-0.5 bg-primary" aria-hidden="true"></div>
+            {/if}
+            <DragHandle
+                ariaLabel={`テイク ${index + 1} の並び順を変更 (ドラッグ、または上下キー)`}
+                onpointerdown={(event) => void dragCtl?.start(index, event)}
+                onkeydown={(event) => onHandleKeydown(event, index)}
+                testId={`take-drag-${take.id}`}
+            />
             <!--
               サムネイル (doc/04 動画列 / doc/05 撮影後の確認)。
               生成は非同期なので、録画直後・生成失敗・過去分のテイクは has_thumbnail=false になる。
@@ -229,7 +316,7 @@
                     size="sm"
                     iconOnly
                     ariaLabel="上へ"
-                    onclick={() => move(take, index - 1)}
+                    onclick={() => moveBy(index, -1)}
                     testId={`take-up-${take.id}`}
                 >
                     <ChevronUp class="size-4" aria-hidden="true" />
@@ -239,7 +326,7 @@
                     size="sm"
                     iconOnly
                     ariaLabel="下へ"
-                    onclick={() => move(take, index + 1)}
+                    onclick={() => moveBy(index, 1)}
                     testId={`take-down-${take.id}`}
                 >
                     <ChevronDown class="size-4" aria-hidden="true" />
@@ -338,6 +425,11 @@
             </div>
         </div>
     {/each}
+    {#if takeDrag.insertionIndex === cut.takes.length}
+        <div class="h-0.5 bg-primary" aria-hidden="true"></div>
+    {/if}
+    <!-- 並べ替え結果の読み上げ (視覚的には出さない)。data-reorder-index を持たないので行に混ざらない -->
+    <p class="sr-only" aria-live="polite" data-testid="take-reorder-status">{reorderStatus}</p>
     {#if error}
         <p class="text-caption text-danger" role="alert" data-testid="take-strip-error">{error}</p>
     {/if}
diff --git a/resources/js/components/features/manual/ScenarioEditor.svelte b/resources/js/components/features/manual/ScenarioEditor.svelte
index 83942e1..b7c495b 100644
--- a/resources/js/components/features/manual/ScenarioEditor.svelte
+++ b/resources/js/components/features/manual/ScenarioEditor.svelte
@@ -1,5 +1,5 @@
 <script lang="ts">
-    import { tick } from "svelte";
+    import { onMount, tick } from "svelte";
     import { router } from "@inertiajs/svelte";
     import {
         Check,
@@ -16,6 +16,7 @@
     import Badge from "@/components/atoms/Badge.svelte";
     import Button from "@/components/atoms/Button.svelte";
     import Card from "@/components/atoms/Card.svelte";
+    import DragHandle from "@/components/atoms/DragHandle.svelte";
     import Input from "@/components/atoms/Input.svelte";
     import Select from "@/components/atoms/Select.svelte";
     import Textarea from "@/components/atoms/Textarea.svelte";
@@ -23,6 +24,8 @@
     import FormField from "@/components/molecules/FormField.svelte";
     import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
     import { csrfToken } from "@/lib/csrf";
+    import { moveItem } from "@/lib/dnd/list-reorder";
+    import { createPointerDrag, type PointerDragState } from "@/lib/dnd/pointer-drag";
     import { boundHistory, parseHistorySnapshot, pushHistory } from "@/lib/manual/scenario-history";
     import { addToast } from "@/lib/stores/toast";
     import type {
@@ -231,26 +234,200 @@
         runSettled(() => commitStructural(() => steps[stepIndex].points.splice(pointIndex, 1)));
     }
 
-    /** ▲▼ 並べ替え (同一スコープ内のみ。階層をまたぐ移動は提供しない) */
+    // --- 並べ替え (同一スコープ内のみ。階層をまたぐ移動は提供しない) ---
+
+    /** 並べ替え結果のスクリーンリーダ告知 (視覚的には出さない) */
+    let reorderStatus = $state("");
+    function announce(message: string): void {
+        reorderStatus = message;
+    }
+
+    /**
+     * 並べ替えは「任意位置への移動」1 本に集約する。
+     * ▲▼ ボタン・ハンドルのキーボード操作・D&D のすべてがここへ合流するので、
+     * undo/redo 履歴・dirty 判定・IME ゲート (runSettled) との整合が 1 箇所で保たれる。
+     * 保存 payload は配列順がそのまま順序 (sort_order はサーバ採番) なので、
+     * ここで順序表現を作る必要はない。
+     */
+    function moveStepTo(from: number, to: number): void {
+        if (from === to || to < 0 || to >= steps.length) return;
+        // 告知は runSettled の**中**に置く。runSettled は IME 変換中なら実行を compositionend まで
+        // 遅らせ、実行時の再検査で no-op になることもある。外に置くと「移動していないのに
+        // 移動しましたと読み上げる」ことになる (design-review R2)。
+        runSettled(() => {
+            if (from >= steps.length || to >= steps.length) return; // 実行時点の再検査
+            commitStructural(() => {
+                steps = moveItem(steps, from, to);
+            });
+            announce(`手順 ${from + 1} を ${to + 1} 番目に移動しました`);
+        });
+    }
+
+    /**
+     * 急所の移動。
+     * **変異の直前にもう一度 step を取り直す**のは意図的である。`runSettled` は IME 変換中に
+     * 実行を compositionend まで遅らせるため、呼び出し時点の参照が実行時点でも有効とは限らない。
+     * 「呼び出し時点の検査」と「実行時点の検査」を両方置く。
+     */
+    function movePointTo(stepIndex: number, from: number, to: number): void {
+        const step = steps[stepIndex];
+        if (step === undefined) return;
+        if (from === to || to < 0 || to >= step.points.length) return;
+        runSettled(() => {
+            const current = steps[stepIndex]; // 実行時点で取り直す
+            if (current === undefined) return;
+            if (from >= current.points.length || to >= current.points.length) return;
+            commitStructural(() => {
+                current.points = moveItem(current.points, from, to);
+            });
+            announce(`急所 ${stepIndex + 1}-${from + 1} を ${to + 1} 番目に移動しました`);
+        });
+    }
+
+    /** ▲▼ (既存 UI。挙動は現行と同じ = 1 段移動 + 端は無変更) */
     function moveStep(index: number, delta: -1 | 1): void {
         const next = index + delta;
-        if (next < 0 || next >= steps.length) return; // 境界: 履歴も積まない
-        runSettled(() =>
-            commitStructural(() => {
-                [steps[index], steps[next]] = [steps[next], steps[index]];
-            }),
-        );
+        if (next < 0 || next >= steps.length) {
+            // disabled にはしない (禁止事項 8)。押されたら「なぜ動かないか」を告知する
+            announce(delta < 0 ? "これ以上、上へは移動できません" : "これ以上、下へは移動できません");
+            return;
+        }
+        moveStepTo(index, next);
     }
 
     function movePoint(stepIndex: number, index: number, delta: -1 | 1): void {
-        const points = steps[stepIndex].points;
+        const step = steps[stepIndex];
+        if (step === undefined) return;
         const next = index + delta;
-        if (next < 0 || next >= points.length) return;
-        runSettled(() =>
-            commitStructural(() => {
-                [points[index], points[next]] = [points[next], points[index]];
-            }),
-        );
+        if (next < 0 || next >= step.points.length) {
+            announce(delta < 0 ? "これ以上、上へは移動できません" : "これ以上、下へは移動できません");
+            return;
+        }
+        movePointTo(stepIndex, index, next);
+    }
+
+    /** ドラッグ表示状態 (手順リスト / 急所リストで別々に持つ) */
+    let stepDrag = $state<PointerDragState>({ activeIndex: null, insertionIndex: null });
+    let pointDrag = $state<PointerDragState>({ activeIndex: null, insertionIndex: null });
+    /** 急所ドラッグ中の親手順 index (急所は手順をまたがないので 1 つで足りる) */
+    let pointDragStep = $state<number | null>(null);
+
+    /** 手順 <ol> / ドラッグ中の急所 <ol> の実体 (行の実測に使う) */
+    let stepListEl = $state<HTMLOListElement | null>(null);
+    let pointListEl: HTMLOListElement | null = null; // ドラッグ中のみ有効 (非 reactive で足りる)
+
+    /**
+     * リスト直下の**行だけ**を表示順で採る。
+     * `data-reorder-index` で絞るのは、落とし先の目印として末尾に差し込む `<li>` を
+     * 測定対象から外すためである (混ざると目印の出現でリスト長が変わり、
+     * 挿入位置が n と n+1 の間で振動する)。
+     */
+    function directRows(list: HTMLElement | null): HTMLElement[] {
+        if (list === null) return [];
+        return [...list.querySelectorAll<HTMLElement>(":scope > li[data-reorder-index]")];
+    }
+
+    // controller は**マウント時に 1 度だけ**作り、破棄時に必ず destroy する (受け入れ条件 A2)。
+    // $effect ではなく onMount を使う: これは「派生状態」ではなく
+    // 「ブラウザ資源 (window listener) をマウント期間だけ持つ」ことであり、
+    // $effect だと「本体で $state を同期 read しなければ再実行されない」という
+    // 実装者の注意力に依存した不変条件になる (多重生成のリスク)。
+    let stepDragCtl: ReturnType<typeof createPointerDrag> | null = null;
+    let pointDragCtl: ReturnType<typeof createPointerDrag> | null = null;
+
+    /**
+     * **コンポーネント全体で 1 つだけドラッグを許す所有権**。
+     * controller は自分の pointerId しか知らないため、手順用と急所用の 2 つは
+     * 互いを排他できない。所有権を持たないと
+     * 「手順のドラッグ中に急所のドラッグを開始 → 手順を先に drop して並びが変わる →
+     * 急所の drop が指す `pointDragStep` の数値 index が別の手順を指す」
+     * という取り違えが起きる (design-review R3 Critical)。
+     * 判定の基準は `start()` が**受理した瞬間**である (閾値超えではない)。
+     * UI には出さないので非 reactive な local で足りる (既存の `composing` と同じ扱い)。
+     */
+    type DragOwner = "step" | "point";
+    let dragOwner: DragOwner | null = null;
+
+    /** ドラッグに紐づく状態 (所有権 + 急所スコープ) を 1 箇所で捨てる */
+    function releaseDrag(): void {
+        dragOwner = null;
+        pointListEl = null;
+        pointDragStep = null;
+    }
+
+    onMount(() => {
+        stepDragCtl = createPointerDrag({
+            rows: () => directRows(stepListEl),
+            onState: (state) => (stepDrag = state),
+            onCommit: (from, to) => {
+                try {
+                    moveStepTo(from, to);
+                } finally {
+                    releaseDrag();
+                }
+            },
+            onCancel: releaseDrag,
+        });
+        pointDragCtl = createPointerDrag({
+            rows: () => directRows(pointListEl),
+            onState: (state) => (pointDrag = state),
+            onCommit: (from, to) => {
+                // 確定でも取消でも所有権とスコープは必ず捨てる (finally で漏れを塞ぐ)
+                try {
+                    if (pointDragStep !== null) movePointTo(pointDragStep, from, to);
+                } finally {
+                    releaseDrag();
+                }
+            },
+            onCancel: releaseDrag,
+        });
+        return () => {
+            stepDragCtl?.destroy();
+            pointDragCtl?.destroy();
+            stepDragCtl = null;
+            pointDragCtl = null;
+            releaseDrag(); // destroy は onCancel を呼ばないので、ここで明示的に捨てる
+        };
+    });
+
+    /**
+     * 手順ドラッグの開始。
+     * 所有権 (`dragOwner`) が空いているときだけ開始し、**受理されたときだけ**所有権を確定する。
+     */
+    function onStepHandleDown(index: number, event: PointerEvent): void {
+        if (dragOwner !== null || stepDragCtl === null) return; // 急所ドラッグ中は開始しない
+        if (!stepDragCtl.start(index, event)) return;
+        dragOwner = "step";
+    }
+
+    /**
+     * 急所ドラッグの開始。
+     * **スコープ (どの手順の <ol> を掴んでいるか) は `start()` が受理したときだけ確定する。**
+     * 先に書き換えてしまうと、1 本目のドラッグが進行中に 2 本目の指で別の手順のハンドルを
+     * 押したとき、1 本目の drop が**別の手順**へ適用される (iOS の多点入力で起きる
+     * データ整合性バグ。design-review R2 Critical)。
+     * さらに `dragOwner` により**手順ドラッグとの同時進行も断つ** (design-review R3 Critical)。
+     */
+    function onPointHandleDown(stepIndex: number, pointIndex: number, event: PointerEvent): void {
+        if (dragOwner !== null || pointDragCtl === null) return; // 手順ドラッグ中は開始しない
+        const target = event.currentTarget;
+        const list =
+            target instanceof HTMLElement
+                ? target.closest<HTMLOListElement>("ol[data-point-list]")
+                : null;
+        if (list === null) return;
+        // 一時変数で受け、受理されたときだけ反映する (順序が本質)
+        if (!pointDragCtl.start(pointIndex, event)) return;
+        dragOwner = "point";
+        pointListEl = list;
+        pointDragStep = stepIndex;
+    }
+
+    /** ハンドル上のキーボード操作 (▲▼ と同じ 1 段移動へ写す) */
+    function onHandleKeydown(event: KeyboardEvent, move: (delta: -1 | 1) => void): void {
+        if (event.key !== "ArrowUp" && event.key !== "ArrowDown") return;
+        event.preventDefault();
+        move(event.key === "ArrowUp" ? -1 : 1);
     }
 
     // --- 履歴コア (保存前ローカル編集のみ対象。undo/redo は steps を再代入し安定 clientKey で差分描画) ---
@@ -869,6 +1046,9 @@
     oncompositionstart={onCompositionStart}
     oncompositionend={onCompositionEnd}
 >
+    <!-- 並べ替え結果の読み上げ (視覚的には出さない。端で動かせない理由もここへ出す) -->
+    <p class="sr-only" aria-live="polite" data-testid="scenario-reorder-status">{reorderStatus}</p>
+
     {#if steps.length === 0}
         <div class="mt-4">
             <EmptyState
@@ -881,12 +1061,30 @@
             />
         </div>
     {:else}
-        <ol class="mt-4 flex flex-col gap-4" data-testid="scenario-steps">
+        <ol
+            class="mt-4 flex flex-col gap-4 {stepDrag.activeIndex !== null ? 'select-none' : ''}"
+            data-testid="scenario-steps"
+            bind:this={stepListEl}
+        >
             {#each steps as step, stepIndex (step.clientKey)}
-                <li>
+                <li class="relative" data-reorder-index={stepIndex}>
+                    {#if stepDrag.insertionIndex === stepIndex}
+                        <!-- 落とし先の目印。影・scale は使わない (DESIGN.md §Elevation) -->
+                        <div class="absolute inset-x-0 -top-2 h-0.5 bg-primary" aria-hidden="true"></div>
+                    {/if}
+                    <div class={stepDrag.activeIndex === stepIndex ? "opacity-50" : ""}>
                     <Card padding="md">
                         <div class="flex items-start justify-between gap-2">
-                            <h3 class="text-body font-medium text-text">手順 {stepIndex + 1}</h3>
+                            <div class="flex items-center gap-2">
+                                <DragHandle
+                                    ariaLabel={`手順 ${stepIndex + 1} の並び順を変更 (ドラッグ、または上下キー)`}
+                                    onpointerdown={(event) => onStepHandleDown(stepIndex, event)}
+                                    onkeydown={(event) =>
+                                        onHandleKeydown(event, (delta) => moveStep(stepIndex, delta))}
+                                    testId="step-{stepIndex}-drag-handle"
+                                />
+                                <h3 class="text-body font-medium text-text">手順 {stepIndex + 1}</h3>
+                            </div>
                             <div class="flex items-center gap-1">
                                 <Button
                                     variant="ghost"
@@ -926,13 +1124,42 @@
                         {@render videoCell(step.id, `step-${stepIndex}`)}
 
                         {#if step.points.length > 0}
-                            <ol class="mt-4 flex flex-col gap-3 border-l-2 border-border pl-4">
+                            <ol
+                                class="mt-4 flex flex-col gap-3 border-l-2 border-border pl-4"
+                                data-point-list
+                            >
                                 {#each step.points as point, pointIndex (point.clientKey)}
-                                    <li>
+                                    {@const dragging = pointDragStep === stepIndex}
+                                    <li class="relative" data-reorder-index={pointIndex}>
+                                        {#if dragging && pointDrag.insertionIndex === pointIndex}
+                                            <div
+                                                class="absolute inset-x-0 -top-1.5 h-0.5 bg-primary"
+                                                aria-hidden="true"
+                                            ></div>
+                                        {/if}
+                                        <div
+                                            class={dragging && pointDrag.activeIndex === pointIndex
+                                                ? "opacity-50"
+                                                : ""}
+                                        >
                                         <div class="flex items-start justify-between gap-2">
-                                            <h4 class="text-caption font-medium text-text-secondary">
-                                                急所 {stepIndex + 1}-{pointIndex + 1}
-                                            </h4>
+                                            <div class="flex items-center gap-2">
+                                                <DragHandle
+                                                    ariaLabel={`急所 ${stepIndex + 1}-${pointIndex + 1} の並び順を変更 (ドラッグ、または上下キー)`}
+                                                    onpointerdown={(event) =>
+                                                        onPointHandleDown(stepIndex, pointIndex, event)}
+                                                    onkeydown={(event) =>
+                                                        onHandleKeydown(event, (delta) =>
+                                                            movePoint(stepIndex, pointIndex, delta),
+                                                        )}
+                                                    testId="point-{stepIndex}-{pointIndex}-drag-handle"
+                                                />
+                                                <h4
+                                                    class="text-caption font-medium text-text-secondary"
+                                                >
+                                                    急所 {stepIndex + 1}-{pointIndex + 1}
+                                                </h4>
+                                            </div>
                                             <div class="flex items-center gap-1">
                                                 <Button
                                                     variant="ghost"
@@ -977,8 +1204,12 @@
                                             point.id,
                                             `point-${stepIndex}-${pointIndex}`,
                                         )}
+                                        </div>
                                     </li>
                                 {/each}
+                                {#if pointDragStep === stepIndex && pointDrag.insertionIndex === step.points.length}
+                                    <li class="h-0.5 bg-primary" aria-hidden="true"></li>
+                                {/if}
                             </ol>
                         {/if}
 
@@ -994,8 +1225,12 @@
                             </Button>
                         </div>
                     </Card>
+                    </div>
                 </li>
             {/each}
+            {#if stepDrag.insertionIndex === steps.length}
+                <li class="h-0.5 bg-primary" aria-hidden="true"></li>
+            {/if}
         </ol>
 
         <div class="mt-4">
diff --git a/resources/js/lib/dnd/list-reorder.ts b/resources/js/lib/dnd/list-reorder.ts
new file mode 100644
index 0000000..7517b70
--- /dev/null
+++ b/resources/js/lib/dnd/list-reorder.ts
@@ -0,0 +1,73 @@
+/**
+ * リスト並べ替えの純関数 (DOM に触れない)。
+ *
+ * D&D は DOM イベントの連鎖でテストしづらい。そこで「どこに落ちたら何番目になるか」の
+ * 意味論だけをここに閉じ込め、Vitest で網羅する (概念設計 D3)。
+ *
+ * **index の語彙は 2 つある**。混同すると off-by-one になるため関数を分ける (受け入れ条件 A5):
+ * - **挿入 index (insertion index)**: 行と行の隙間を数えた `0..n` の値。n 行のリストには
+ *   隙間が n+1 個ある。「どの隙間に落としたか」を表す。
+ * - **最終 index (final index)**: 移動が終わった後の配列での `0..n-1` の値。
+ *   撮影 PWA がサーバへ渡す `position` はこちらである
+ *   (`CaptureTakeService::reorderWithinCut` は対象を除いた配列へ splice するため、
+ *   結果として「移動後の全体配列での 0 始まり index」と一致する)。
+ */
+
+/** 行の上下位置 (getBoundingClientRect の実測値。viewport 座標) */
+export interface RowBounds {
+    readonly top: number;
+    readonly height: number;
+}
+
+/**
+ * 要素を from から to (最終 index) へ動かした**新しい配列**を返す。入力は変更しない。
+ * 範囲外・非整数は「動かさない」に倒す (fail-safe。呼び出し側で throw させない)。
+ *
+ * 要素を**値として取り出さず、配列のまま移す**。
+ * `const moved = next[from]; if (moved === undefined) return next;` の形は、
+ * 配列要素の**値**を存在判定に使うため `T` に `undefined` を含む型では
+ * 有効な要素が動かせなくなる (generic の契約と実装が食い違う)。
+ * `splice` の戻り値をそのまま spread すれば、`undefined` 要素も正しく動き、
+ * 添字アクセスの厳格化設定の有無にも依存しない (design-review R2)。
+ * `from` は直前に範囲検査済みなので、戻り値は実行時に必ず 1 要素である。
+ */
+export function moveItem<T>(list: readonly T[], from: number, to: number): T[] {
+    const next = [...list];
+    if (!Number.isInteger(from) || !Number.isInteger(to)) return next;
+    if (from < 0 || from >= next.length) return next;
+    const clamped = Math.min(Math.max(to, 0), next.length - 1);
+    if (clamped === from) return next;
+    const moved = next.splice(from, 1);
+    next.splice(clamped, 0, ...moved);
+    return next;
+}
+
+/**
+ * ポインタの Y 座標 (viewport 座標) から**挿入 index** (0..rows.length) を決める。
+ * 各行の中点より上なら「その行の前」、下ならさらに次の行を見る。
+ *
+ * rows は**表示順**で、掴んでいる行自身も含めて渡す (DOM から抜かないため)。
+ * スクロールしても `getBoundingClientRect()` を採り直せば viewport 座標系で
+ * ポインタ座標と一致する (受け入れ条件 A2)。
+ */
+export function insertionIndexFromRects(rows: readonly RowBounds[], pointerY: number): number {
+    let index = 0;
+    for (const row of rows) {
+        if (pointerY < row.top + row.height / 2) return index;
+        index += 1;
+    }
+    return rows.length;
+}
+
+/**
+ * 挿入 index → 最終 index。
+ * 掴んだ行自身がいったんリストから抜けるぶん、掴んだ位置より後ろの隙間は 1 つ手前へ詰まる。
+ * 掴んだ行の前後 2 つの隙間 (from と from+1) はどちらも「動かさない」= from になる。
+ *
+ * **入力契約**: `insertion` は `0..n` (insertionIndexFromRects の出力)、`from` は `0..n-1` の
+ * 正規化済みの値を前提とする。範囲外の clamp はここでは行わない
+ * (下流の `moveItem` が 1 箇所で clamp する。2 箇所で丸めると意味が分散する)。
+ */
+export function toFinalIndex(insertion: number, from: number): number {
+    return insertion > from ? insertion - 1 : insertion;
+}
diff --git a/resources/js/lib/dnd/pointer-drag.ts b/resources/js/lib/dnd/pointer-drag.ts
new file mode 100644
index 0000000..2fad782
--- /dev/null
+++ b/resources/js/lib/dnd/pointer-drag.ts
@@ -0,0 +1,244 @@
+/**
+ * Pointer Events による 1 軸 (縦) の並べ替えドラッグ制御。**Svelte に依存しない素の TS**。
+ *
+ * HTML5 Drag and Drop API は iOS Safari のタッチで発火しないため採らない (概念設計 D1)。
+ * 撮影 PWA の主戦場は iOS Safari (docs/supported-browsers.md) なので、
+ * マウス・タッチ・ペンを 1 系統で扱える Pointer Events に一本化する。
+ *
+ * **共通化の境界** (受け入れ条件 A4): ここに置くのは
+ * (i) ポインタの生死管理 (ii) 挿入位置の算出 (iii) 端での自動スクロール だけである。
+ * 保存経路・文言・aria-live メッセージ・見た目・サーバへ渡す position 変換は
+ * 呼び出し側 (feature component) に残す。
+ */
+import { insertionIndexFromRects, toFinalIndex, type RowBounds } from "./list-reorder";
+
+/** ドラッグ開始とみなす最小移動量 (px)。タップ/クリックをドラッグにしない */
+export const DRAG_ACTIVATION_DISTANCE = 6;
+/** 画面端からこの距離に入ったら自動スクロールする (px) */
+export const AUTO_SCROLL_EDGE = 64;
+/** 自動スクロールの 1 フレームあたりの移動量 (px) */
+export const AUTO_SCROLL_STEP = 12;
+
+/** 表示用の状態。UI (影ではなく border と不透明度) の描画にのみ使う */
+export interface PointerDragState {
+    /** 掴んでいる行の index。ドラッグしていなければ null */
+    readonly activeIndex: number | null;
+    /** 落とし先の隙間 (挿入 index)。ドラッグしていなければ null */
+    readonly insertionIndex: number | null;
+}
+
+export interface PointerDragCallbacks {
+    /** 表示順の行要素を返す (呼び出し側が DOM から採る)。毎回の pointermove で採り直す */
+    readonly rows: () => ReadonlyArray<HTMLElement>;
+    /** 表示状態の変化通知 */
+    readonly onState: (state: PointerDragState) => void;
+    /** 確定。`to` は**最終 index**。`from === to` のときは呼ばれない */
+    readonly onCommit: (from: number, to: number) => void;
+    /**
+     * 取消。**利用者由来の取消だけ**を通知する (Esc / pointercancel / 位置が変わらない drop)。
+     * `destroy()` (コンポーネント破棄) では**呼ばれない** — 破棄は利用者の意思ではないので、
+     * ここに告知や通信を足したときに unmount で誤発火しないようにするためである。
+     */
+    readonly onCancel?: () => void;
+}
+
+export interface PointerDragController {
+    /**
+     * ハンドルの pointerdown から呼ぶ。
+     * **開始を受理したら true**、既に別のポインタが進行中などで無視したら false を返す。
+     * 呼び出し側は「受理されたときだけ」ドラッグに紐づく状態 (対象スコープ等) を確定すること。
+     * 戻り値を無視して先に状態を書き換えると、2 本目の指が 1 本目のドラッグの対象を
+     * すり替えてしまう (design-review R2 Critical)。
+     */
+    readonly start: (index: number, event: PointerEvent) => boolean;
+    /** コンポーネント破棄時に必ず呼ぶ (受け入れ条件 A2) */
+    readonly destroy: () => void;
+}
+
+/**
+ * **`isDragging()` のような「今ドラッグ中か」を外へ出す API は置かない**。
+ * 閾値を超えるまで false を返すため、閾値未満の待機中に別のドラッグを受理してしまう
+ * (排他の判定に使うと穴になる)。排他は `start()` の戻り値 = **受理した瞬間**を基準にする
+ * (design-review R3)。呼び出し側が複数の controller を持つ場合も同じ基準で 1 つに絞る。
+ */
+export function createPointerDrag(callbacks: PointerDragCallbacks): PointerDragController {
+    let pointerId: number | null = null;
+    let handle: HTMLElement | null = null;
+    let fromIndex = 0;
+    let startY = 0;
+    /** 閾値を超えて実際にドラッグが始まったか */
+    let activated = false;
+    let insertion: number | null = null;
+    let scrollFrame: number | null = null;
+    let scrollDelta = 0;
+    /** 直近のポインタ Y (viewport 座標)。自動スクロール中の再計算に使う */
+    let lastClientY = 0;
+
+    function bounds(): RowBounds[] {
+        return callbacks.rows().map((el): RowBounds => {
+            const rect = el.getBoundingClientRect();
+            return { top: rect.top, height: rect.height };
+        });
+    }
+
+    function stopAutoScroll(): void {
+        if (scrollFrame !== null && typeof cancelAnimationFrame === "function") {
+            cancelAnimationFrame(scrollFrame);
+        }
+        scrollFrame = null;
+        scrollDelta = 0;
+    }
+
+    /** 挿入位置が実際に変わったときだけ通知する (毎フレームの無駄な再描画を避ける) */
+    function setInsertion(next: number): void {
+        if (insertion === next) return;
+        insertion = next;
+        callbacks.onState({ activeIndex: fromIndex, insertionIndex: next });
+    }
+
+    /**
+     * 自動スクロールの 1 フレーム。
+     * **スクロールしたら挿入位置を必ず採り直す**。指を止めたまま端でスクロールさせると
+     * `pointermove` は来ないのに行だけが動くため、採り直さないと古い挿入位置のまま
+     * drop できてしまう (iOS Safari で最も起きやすい。design-review R1 の指摘)。
+     */
+    function tickAutoScroll(): void {
+        scrollFrame = null;
+        if (pointerId === null || scrollDelta === 0) return;
+        window.scrollBy(0, scrollDelta);
+        setInsertion(insertionIndexFromRects(bounds(), lastClientY));
+        scrollFrame = requestAnimationFrame(tickAutoScroll);
+    }
+
+    /**
+     * 画面端に近ければスクロールを回す。
+     * requestAnimationFrame が無い環境 (jsdom 等) では自動スクロールだけ働かない。
+     * 並べ替えそのものは動くので、機能検出で静かに劣化させる (誇張しない)。
+     */
+    function updateAutoScroll(clientY: number): void {
+        if (typeof requestAnimationFrame !== "function") return;
+        const height = window.innerHeight;
+        const next =
+            clientY < AUTO_SCROLL_EDGE
+                ? -AUTO_SCROLL_STEP
+                : clientY > height - AUTO_SCROLL_EDGE
+                  ? AUTO_SCROLL_STEP
+                  : 0;
+        scrollDelta = next;
+        if (next === 0) {
+            stopAutoScroll();
+            return;
+        }
+        if (scrollFrame === null) scrollFrame = requestAnimationFrame(tickAutoScroll);
+    }
+
+    /**
+     * **すべての終了経路が合流する唯一の出口** (受け入れ条件 A2)。
+     * pointerup / pointercancel / Escape / destroy はここへ入る。
+     * 資源 (pointer capture / rAF) を先に解放してから callback を呼ぶので、
+     * callback 内で再入しても状態は壊れない。
+     *
+     * @param commit true なら位置が変わっていれば onCommit する
+     * @param notify false なら onCancel を呼ばない (destroy 専用。
+     *        破棄は利用者の取消ではないため、告知や通信を伴う onCancel を発火させない)
+     */
+    function finish(commit: boolean, notify: boolean): void {
+        if (pointerId === null) return;
+        const wasActivated = activated;
+        const target = insertion;
+        const from = fromIndex;
+        if (
+            handle !== null &&
+            typeof handle.releasePointerCapture === "function" &&
+            typeof handle.hasPointerCapture === "function" &&
+            handle.hasPointerCapture(pointerId)
+        ) {
+            handle.releasePointerCapture(pointerId);
+        }
+        pointerId = null;
+        handle = null;
+        activated = false;
+        insertion = null;
+        stopAutoScroll();
+        callbacks.onState({ activeIndex: null, insertionIndex: null });
+        if (!commit || !wasActivated || target === null) {
+            if (notify) callbacks.onCancel?.();
+            return;
+        }
+        const to = toFinalIndex(target, from);
+        if (to === from) {
+            if (notify) callbacks.onCancel?.();
+            return;
+        }
+        callbacks.onCommit(from, to);
+    }
+
+    function onPointerMove(event: PointerEvent): void {
+        if (pointerId === null || event.pointerId !== pointerId) return;
+        lastClientY = event.clientY;
+        if (!activated) {
+            if (Math.abs(event.clientY - startY) < DRAG_ACTIVATION_DISTANCE) return;
+            activated = true;
+        }
+        // ハンドルの touch-action:none と併せて、スクロール/テキスト選択との競合を断つ
+        event.preventDefault();
+        if (insertion === null) {
+            // 掴んだ直後の 1 回目は必ず通知する (activeIndex を UI へ伝えるため)
+            insertion = insertionIndexFromRects(bounds(), event.clientY);
+            callbacks.onState({ activeIndex: fromIndex, insertionIndex: insertion });
+        } else {
+            setInsertion(insertionIndexFromRects(bounds(), event.clientY));
+        }
+        updateAutoScroll(event.clientY);
+    }
+
+    function onPointerUp(event: PointerEvent): void {
+        if (pointerId === null || event.pointerId !== pointerId) return;
+        finish(true, true);
+    }
+
+    function onPointerCancel(event: PointerEvent): void {
+        if (pointerId === null || event.pointerId !== pointerId) return;
+        finish(false, true);
+    }
+
+    function onKeyDown(event: KeyboardEvent): void {
+        if (pointerId !== null && event.key === "Escape") finish(false, true);
+    }
+
+    // listener は生成時に 1 度だけ張り destroy で外す (start/finish のたびに張り替えない)。
+    // capture が使えない環境でも window で拾えるよう、ハンドルではなく window に張る。
+    window.addEventListener("pointermove", onPointerMove, { passive: false });
+    window.addEventListener("pointerup", onPointerUp);
+    window.addEventListener("pointercancel", onPointerCancel);
+    window.addEventListener("keydown", onKeyDown);
+
+    return {
+        start(index: number, event: PointerEvent): boolean {
+            if (pointerId !== null) return false; // 2 本目の指は無視 (多点ドラッグは提供しない)
+            if (event.pointerType === "mouse" && event.button !== 0) return false; // 左ボタンのみ
+            const target = event.currentTarget;
+            handle = target instanceof HTMLElement ? target : null;
+            pointerId = event.pointerId;
+            fromIndex = index;
+            startY = event.clientY;
+            lastClientY = event.clientY;
+            activated = false;
+            insertion = null;
+            // pointer capture が無い環境 (jsdom / 一部の古い WebKit) でも
+            // window の listener で同じ callback 契約のまま完走する (受け入れ条件 A2)
+            if (handle !== null && typeof handle.setPointerCapture === "function") {
+                handle.setPointerCapture(event.pointerId);
+            }
+            return true;
+        },
+        destroy(): void {
+            // 進行中のドラッグを畳むが onCancel は呼ばない (破棄は利用者の取消ではない)
+            finish(false, false);
+            window.removeEventListener("pointermove", onPointerMove);
+            window.removeEventListener("pointerup", onPointerUp);
+            window.removeEventListener("pointercancel", onPointerCancel);
+            window.removeEventListener("keydown", onKeyDown);
+        },
+    };
+}
diff --git a/tests/js/components/features/capture/TakeStrip.test.ts b/tests/js/components/features/capture/TakeStrip.test.ts
index 2883fd5..9b0c1ae 100644
--- a/tests/js/components/features/capture/TakeStrip.test.ts
+++ b/tests/js/components/features/capture/TakeStrip.test.ts
@@ -410,3 +410,216 @@ describe("サムネイル表示 (T183)", () => {
         expect(screen.queryByTestId("take-thumbnail-placeholder-10")).not.toBeInTheDocument();
     });
 });
+
+/*
+ * 並べ替え (T185)。層 3 = 配線: 落としたら既存の PATCH 経路が期待どおりの position を出すか。
+ * position は**最終 index** (移動後の全体配列での 0 始まり index)。サーバの reorderWithinCut が
+ * 対象を除いた配列へ splice するため両者は一致する。
+ */
+describe("テイクの並べ替え (T185)", () => {
+    /** 行の実測を data-reorder-index から固定値へ差し替える (top = index * 100, height = 100) */
+    function stubRowRects(): void {
+        vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockImplementation(function (
+            this: HTMLElement,
+        ): DOMRect {
+            const raw = this.dataset.reorderIndex;
+            const index = raw === undefined ? -1 : Number(raw);
+            const top = index < 0 ? 0 : index * 100;
+            const height = index < 0 ? 0 : 100;
+            return {
+                top,
+                height,
+                bottom: top + height,
+                left: 0,
+                right: 0,
+                width: 0,
+                x: 0,
+                y: top,
+                toJSON: () => ({}),
+            } as DOMRect;
+        });
+    }
+
+    function pointerEvent(type: string, clientY: number, pointerId = 1): PointerEvent {
+        return new PointerEvent(type, {
+            bubbles: true,
+            cancelable: true,
+            pointerId,
+            clientY,
+            button: 0,
+            pointerType: "touch",
+        });
+    }
+
+    /** 3 テイク (id 10 / 11 / 12) */
+    function threeTakes(): CaptureTake[] {
+        return [makeTake({ id: 10 }), makeTake({ id: 11 }), makeTake({ id: 12 })];
+    }
+
+    function renderStrip(onChanged = vi.fn()): { onChanged: ReturnType<typeof vi.fn> } {
+        render(TakeStrip, {
+            projectId: 1,
+            manualId: 2,
+            cut: makeCut(threeTakes()),
+            cutLabel: "手順 1",
+            onChanged,
+        });
+        return { onChanged };
+    }
+
+    /** ハンドルを掴んで pointerY まで動かし drop する */
+    async function dragHandle(testId: string, startY: number, endY: number): Promise<void> {
+        await fireEvent(screen.getByTestId(testId), pointerEvent("pointerdown", startY));
+        await fireEvent(window, pointerEvent("pointermove", endY));
+        await fireEvent(window, pointerEvent("pointerup", endY));
+    }
+
+    /** 直近の PATCH の URL と body */
+    function lastPatch(): { url: string; body: unknown } {
+        const call = fetchMock.mock.calls.filter((c) => c[1]?.method === "PATCH").at(-1);
+        if (!call) throw new Error("PATCH リクエストがありません");
+        return { url: String(call[0]), body: JSON.parse(String(call[1].body)) as unknown };
+    }
+
+    beforeEach(() => {
+        stubRowRects();
+    });
+
+    it("1 番目のテイクを 3 番目へ落とすと position: 2 の PATCH が飛ぶ", async () => {
+        fetchMock.mockResolvedValue(jsonResponse(200, {}));
+        renderStrip();
+
+        // 掴んだ行 index 0 → 最終行の中点 (250) より下 = 挿入 index 3 → 最終 index 2
+        await dragHandle("take-drag-10", 50, 260);
+
+        await waitFor(() => expect(fetchMock).toHaveBeenCalled());
+        expect(lastPatch().url).toBe("/app/projects/1/manuals/2/cuts/3/takes/10");
+        expect(lastPatch().body).toEqual({ position: 2 });
+    });
+
+    it("3 番目のテイクを 1 番目へ落とすと position: 0 の PATCH が飛ぶ", async () => {
+        fetchMock.mockResolvedValue(jsonResponse(200, {}));
+        renderStrip();
+
+        await dragHandle("take-drag-12", 250, 10);
+
+        await waitFor(() => expect(fetchMock).toHaveBeenCalled());
+        expect(lastPatch().url).toBe("/app/projects/1/manuals/2/cuts/3/takes/12");
+        expect(lastPatch().body).toEqual({ position: 0 });
+    });
+
+    it("位置が変わらない drop では通信しない", async () => {
+        renderStrip();
+
+        // 掴んだ行 index 0 の直後の隙間 (挿入 index 1) → 最終 index 0 = from
+        await dragHandle("take-drag-10", 50, 120);
+
+        expect(fetchMock).not.toHaveBeenCalled();
+    });
+
+    it("ドラッグ中の Escape では通信しない", async () => {
+        renderStrip();
+
+        await fireEvent(screen.getByTestId("take-drag-10"), pointerEvent("pointerdown", 50));
+        await fireEvent(window, pointerEvent("pointermove", 260));
+        await fireEvent.keyDown(window, { key: "Escape" });
+        await fireEvent(window, pointerEvent("pointerup", 260));
+
+        expect(fetchMock).not.toHaveBeenCalled();
+    });
+
+    it("ドラッグ中の pointercancel では通信しない", async () => {
+        renderStrip();
+
+        await fireEvent(screen.getByTestId("take-drag-10"), pointerEvent("pointerdown", 50));
+        await fireEvent(window, pointerEvent("pointermove", 260));
+        await fireEvent(window, pointerEvent("pointercancel", 260));
+
+        expect(fetchMock).not.toHaveBeenCalled();
+    });
+
+    it("ハンドル上の ArrowDown は ▼ と同じ 1 段移動の PATCH を出す", async () => {
+        fetchMock.mockResolvedValue(jsonResponse(200, {}));
+        renderStrip();
+
+        await fireEvent.keyDown(screen.getByTestId("take-drag-10"), { key: "ArrowDown" });
+
+        await waitFor(() => expect(fetchMock).toHaveBeenCalled());
+        expect(lastPatch().url).toBe("/app/projects/1/manuals/2/cuts/3/takes/10");
+        expect(lastPatch().body).toEqual({ position: 1 });
+    });
+
+    it("ハンドル上の ArrowUp は ▲ と同じ 1 段移動の PATCH を出す", async () => {
+        fetchMock.mockResolvedValue(jsonResponse(200, {}));
+        renderStrip();
+
+        await fireEvent.keyDown(screen.getByTestId("take-drag-12"), { key: "ArrowUp" });
+
+        await waitFor(() => expect(fetchMock).toHaveBeenCalled());
+        expect(lastPatch().url).toBe("/app/projects/1/manuals/2/cuts/3/takes/12");
+        expect(lastPatch().body).toEqual({ position: 1 });
+    });
+
+    it.each([
+        ["先頭で ▲", "take-up-10", "これ以上、上へは移動できません"],
+        ["末尾で ▼", "take-down-12", "これ以上、下へは移動できません"],
+    ])(
+        "%s は通信せず・busy にせず・再取得せず、理由を告知する",
+        async (_label, testId, message) => {
+            const { onChanged } = renderStrip();
+
+            await fireEvent.click(screen.getByTestId(testId));
+
+            expect(fetchMock).not.toHaveBeenCalled();
+            expect(onChanged).not.toHaveBeenCalled();
+            expect(screen.getByTestId("take-adopt-10")).not.toHaveAttribute("aria-busy");
+            expect(screen.getByTestId("take-reorder-status")).toHaveTextContent(message);
+        },
+    );
+
+    it("端のハンドル操作 (ArrowUp) も同じく通信せず理由を告知する", async () => {
+        renderStrip();
+
+        await fireEvent.keyDown(screen.getByTestId("take-drag-10"), { key: "ArrowUp" });
+
+        expect(fetchMock).not.toHaveBeenCalled();
+        expect(screen.getByTestId("take-reorder-status")).toHaveTextContent(
+            "これ以上、上へは移動できません",
+        );
+    });
+
+    it("成功した並べ替えは aria-live で告知する", async () => {
+        fetchMock.mockResolvedValue(jsonResponse(200, {}));
+        renderStrip();
+
+        await dragHandle("take-drag-10", 50, 260);
+
+        await waitFor(() =>
+            expect(screen.getByTestId("take-reorder-status")).toHaveTextContent(
+                "テイク 1 を 3 番目に移動しました",
+            ),
+        );
+    });
+
+    it("PATCH が 422 ならサーバ文言を role=alert に出し、告知はしない", async () => {
+        fetchMock.mockResolvedValue(jsonResponse(422, { message: "処理中のため並べ替えできません" }));
+        renderStrip();
+
+        await dragHandle("take-drag-10", 50, 260);
+
+        await waitFor(() =>
+            expect(screen.getByTestId("take-strip-error")).toHaveTextContent(
+                "処理中のため並べ替えできません",
+            ),
+        );
+        expect(screen.getByTestId("take-reorder-status")).toHaveTextContent("");
+    });
+
+    it("ハンドルは disabled 属性を持たない (禁止事項 8)", () => {
+        renderStrip();
+
+        for (const id of ["take-drag-10", "take-drag-11", "take-drag-12"]) {
+            expect(screen.getByTestId(id)).not.toHaveAttribute("disabled");
+        }
+    });
+});
diff --git a/tests/js/components/features/manual/ScenarioEditor.test.ts b/tests/js/components/features/manual/ScenarioEditor.test.ts
index 6359d42..d729834 100644
--- a/tests/js/components/features/manual/ScenarioEditor.test.ts
+++ b/tests/js/components/features/manual/ScenarioEditor.test.ts
@@ -1210,3 +1210,309 @@ describe("ScenarioEditor", () => {
         });
     });
 });
+
+/*
+ * ドラッグ&ドロップ並べ替え (T185)。層 3 = 配線:
+ * 落としたら既存の保存経路 (payloadSteps の配列順) / 履歴 / dirty 判定が期待どおり動くか。
+ * 意味論 (どこに落ちたら何番目か) は tests/js/lib/dnd/list-reorder.test.ts が持つ。
+ */
+describe("ドラッグ&ドロップ並べ替え (T185)", () => {
+    let rectSpy: ReturnType<typeof vi.spyOn> | null = null;
+
+    /** 行の実測を data-reorder-index から固定値へ差し替える (top = index * 100, height = 100) */
+    function stubRowRects(): void {
+        rectSpy = vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockImplementation(
+            function (this: HTMLElement): DOMRect {
+                const raw = this.dataset.reorderIndex;
+                const index = raw === undefined ? -1 : Number(raw);
+                const top = index < 0 ? 0 : index * 100;
+                const height = index < 0 ? 0 : 100;
+                return {
+                    top,
+                    height,
+                    bottom: top + height,
+                    left: 0,
+                    right: 0,
+                    width: 0,
+                    x: 0,
+                    y: top,
+                    toJSON: () => ({}),
+                } as DOMRect;
+            },
+        );
+    }
+
+    function pointerEvent(type: string, clientY: number, pointerId = 1): PointerEvent {
+        return new PointerEvent(type, {
+            bubbles: true,
+            cancelable: true,
+            pointerId,
+            clientY,
+            button: 0,
+            pointerType: "touch",
+        });
+    }
+
+    async function grab(testId: string, clientY: number, pointerId = 1): Promise<void> {
+        await fireEvent(screen.getByTestId(testId), pointerEvent("pointerdown", clientY, pointerId));
+    }
+
+    async function dragTo(clientY: number, pointerId = 1): Promise<void> {
+        await fireEvent(window, pointerEvent("pointermove", clientY, pointerId));
+    }
+
+    async function drop(clientY: number, pointerId = 1): Promise<void> {
+        await fireEvent(window, pointerEvent("pointerup", clientY, pointerId));
+    }
+
+    /** 掴む → 動かす → 落とす */
+    async function dragHandle(testId: string, startY: number, endY: number): Promise<void> {
+        await grab(testId, startY);
+        await dragTo(endY);
+        await drop(endY);
+    }
+
+    /** 2 手順 × 2 急所 (急所の同一スコープ性を検証できる形) */
+    function makeDndDocument(): ScenarioDocument {
+        const row = (id: number, scene: string) => ({
+            id,
+            scene,
+            shot_type: "yori" as const,
+            shooting_point: null,
+            narration: "",
+            subtitle_primary: null,
+            subtitle_secondary: "",
+            material_type: null,
+            static_display_seconds: null,
+        });
+        return {
+            scenario_version: 3,
+            steps: [
+                {
+                    ...row(11, "手順シーンA"),
+                    shot_type: "hiki",
+                    points: [row(21, "急所A-1"), row(22, "急所A-2")],
+                },
+                {
+                    ...row(12, "手順シーンB"),
+                    shot_type: "hiki",
+                    points: [row(23, "急所B-1"), row(24, "急所B-2")],
+                },
+            ],
+        };
+    }
+
+    function renderDnd(): void {
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDndDocument() } });
+    }
+
+    /** 現在の手順の scene 値 (表示順) */
+    function stepScenes(): string[] {
+        return screen
+            .getAllByTestId(/^step-\d+-scene$/)
+            .map((el) => (el as HTMLInputElement).value);
+    }
+
+    beforeEach(() => {
+        stubRowRects();
+    });
+
+    afterEach(() => {
+        rectSpy?.mockRestore();
+        rectSpy = null;
+    });
+
+    it("手順のハンドルをドラッグすると順序が入れ替わり、保存 payload の並びも変わる", async () => {
+        const saved: ScenarioDocument = { ...makeDndDocument(), scenario_version: 4 };
+        fetchMock.mockResolvedValueOnce(jsonResponse(200, saved));
+        renderDnd();
+
+        // 手順 1 を掴んで手順 2 の中点 (150) より下へ落とす → 挿入 index 2 → 最終 index 1
+        await dragHandle("step-0-drag-handle", 50, 160);
+
+        expect(stepScenes()).toEqual(["手順シーンB", "手順シーンA"]);
+
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+        await waitFor(() => expect(fetchMock).toHaveBeenCalled());
+        expect(lastPutPayload().steps.map((step) => step.id)).toEqual([12, 11]);
+    });
+
+    it("D&D の直後は未保存の変更として表示される", async () => {
+        renderDnd();
+
+        await dragHandle("step-0-drag-handle", 50, 160);
+
+        expect(screen.getByTestId("scenario-dirty-indicator")).toBeInTheDocument();
+    });
+
+    it("D&D の直後に『元に戻す』で元の順序へ戻る", async () => {
+        renderDnd();
+
+        await dragHandle("step-0-drag-handle", 50, 160);
+        expect(stepScenes()).toEqual(["手順シーンB", "手順シーンA"]);
+
+        await fireEvent.click(screen.getByTestId("scenario-undo"));
+
+        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]);
+    });
+
+    it("成功した並べ替えは aria-live で告知する", async () => {
+        renderDnd();
+
+        await dragHandle("step-0-drag-handle", 50, 160);
+
+        expect(screen.getByTestId("scenario-reorder-status")).toHaveTextContent(
+            "手順 1 を 2 番目に移動しました",
+        );
+    });
+
+    it("急所の D&D は同じ手順の中だけで完結する", async () => {
+        renderDnd();
+
+        await dragHandle("point-0-0-drag-handle", 50, 160);
+
+        expect(screen.getByTestId("point-0-0-scene")).toHaveValue("急所A-2");
+        expect(screen.getByTestId("point-0-1-scene")).toHaveValue("急所A-1");
+        // 別手順の急所は無変更 (closest による絞り込みが効いている)
+        expect(screen.getByTestId("point-1-0-scene")).toHaveValue("急所B-1");
+        expect(screen.getByTestId("point-1-1-scene")).toHaveValue("急所B-2");
+    });
+
+    it("ドラッグ中に Escape を押すと順序が変わらない", async () => {
+        renderDnd();
+
+        await grab("step-0-drag-handle", 50);
+        await dragTo(160);
+        await fireEvent.keyDown(window, { key: "Escape" });
+        await drop(160);
+
+        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]);
+        expect(screen.getByTestId("scenario-reorder-status")).toHaveTextContent("");
+    });
+
+    it("2 本目の指は 1 本目のドラッグ対象をすり替えない (同一 controller)", async () => {
+        renderDnd();
+
+        // 手順 A の急所を pointerId=1 で掴んで動かす
+        await grab("point-0-0-drag-handle", 50, 1);
+        await dragTo(160, 1);
+        // その最中に手順 B の急所ハンドルを別の指 (pointerId=2) で押す
+        await grab("point-1-0-drag-handle", 50, 2);
+        // 1 本目を drop する
+        await drop(160, 1);
+
+        // 手順 A の急所だけが動き、手順 B は無変更
+        expect(screen.getByTestId("point-0-0-scene")).toHaveValue("急所A-2");
+        expect(screen.getByTestId("point-0-1-scene")).toHaveValue("急所A-1");
+        expect(screen.getByTestId("point-1-0-scene")).toHaveValue("急所B-1");
+        expect(screen.getByTestId("point-1-1-scene")).toHaveValue("急所B-2");
+    });
+
+    it("手順ドラッグ中は急所ドラッグが始まらない (controller またぎの排他)", async () => {
+        renderDnd();
+
+        await grab("step-0-drag-handle", 50, 1);
+        await dragTo(160, 1);
+        // 急所ハンドルを別の指で押し、急所の drop 相当まで出しても始まらない
+        await grab("point-1-0-drag-handle", 50, 2);
+        await dragTo(160, 2);
+        await drop(160, 2);
+
+        expect(screen.getByTestId("point-1-0-scene")).toHaveValue("急所B-1");
+        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]); // 1 本目はまだ drop していない
+
+        await drop(160, 1);
+
+        // 1 本目は掴んだとおりの行が期待どおりの位置へ動く
+        expect(stepScenes()).toEqual(["手順シーンB", "手順シーンA"]);
+    });
+
+    it("急所ドラッグ中は手順ドラッグが始まらない (逆向き)", async () => {
+        renderDnd();
+
+        await grab("point-0-0-drag-handle", 50, 1);
+        await dragTo(160, 1);
+        await grab("step-0-drag-handle", 50, 2);
+        await dragTo(160, 2);
+        await drop(160, 2);
+
+        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]);
+
+        await drop(160, 1);
+
+        expect(screen.getByTestId("point-0-0-scene")).toHaveValue("急所A-2");
+        expect(screen.getByTestId("point-0-1-scene")).toHaveValue("急所A-1");
+        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]);
+    });
+
+    it("IME 変換中に確定した D&D は compositionend まで順序も告知も変わらない", async () => {
+        renderDnd();
+
+        await fireEvent.compositionStart(screen.getByTestId("step-0-scene"));
+        await dragHandle("step-0-drag-handle", 50, 160);
+
+        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]);
+        expect(screen.getByTestId("scenario-reorder-status")).toHaveTextContent("");
+
+        await fireEvent.compositionEnd(screen.getByTestId("step-0-scene"));
+
+        expect(stepScenes()).toEqual(["手順シーンB", "手順シーンA"]);
+        expect(screen.getByTestId("scenario-reorder-status")).toHaveTextContent(
+            "手順 1 を 2 番目に移動しました",
+        );
+    });
+
+    it("ハンドル上の ArrowDown / ArrowUp で 1 段移動する", async () => {
+        renderDnd();
+
+        await fireEvent.keyDown(screen.getByTestId("step-0-drag-handle"), { key: "ArrowDown" });
+        expect(stepScenes()).toEqual(["手順シーンB", "手順シーンA"]);
+
+        await fireEvent.keyDown(screen.getByTestId("step-1-drag-handle"), { key: "ArrowUp" });
+        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]);
+    });
+
+    it("急所ハンドル上の ArrowDown でも 1 段移動する", async () => {
+        renderDnd();
+
+        await fireEvent.keyDown(screen.getByTestId("point-0-0-drag-handle"), { key: "ArrowDown" });
+
+        expect(screen.getByTestId("point-0-0-scene")).toHaveValue("急所A-2");
+        expect(screen.getByTestId("point-0-1-scene")).toHaveValue("急所A-1");
+    });
+
+    it("先頭行の ArrowUp は順序を変えず、理由を告知する (disabled にしない)", async () => {
+        renderDnd();
+
+        await fireEvent.keyDown(screen.getByTestId("step-0-drag-handle"), { key: "ArrowUp" });
+
+        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]);
+        expect(screen.getByTestId("scenario-reorder-status")).toHaveTextContent(
+            "これ以上、上へは移動できません",
+        );
+    });
+
+    it("末尾行の ▼ ボタンも順序を変えず理由を告知する", async () => {
+        renderDnd();
+
+        await fireEvent.click(screen.getByTestId("step-1-move-down"));
+
+        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]);
+        expect(screen.getByTestId("scenario-reorder-status")).toHaveTextContent(
+            "これ以上、下へは移動できません",
+        );
+    });
+
+    it("ハンドルは disabled 属性を持たない (禁止事項 8)", () => {
+        renderDnd();
+
+        for (const id of [
+            "step-0-drag-handle",
+            "step-1-drag-handle",
+            "point-0-0-drag-handle",
+            "point-1-1-drag-handle",
+        ]) {
+            expect(screen.getByTestId(id)).not.toHaveAttribute("disabled");
+        }
+    });
+});
diff --git a/tests/js/lib/dnd/list-reorder.test.ts b/tests/js/lib/dnd/list-reorder.test.ts
new file mode 100644
index 0000000..aa127f3
--- /dev/null
+++ b/tests/js/lib/dnd/list-reorder.test.ts
@@ -0,0 +1,162 @@
+import { describe, expect, it } from "vitest";
+import {
+    insertionIndexFromRects,
+    moveItem,
+    toFinalIndex,
+    type RowBounds,
+} from "@/lib/dnd/list-reorder";
+
+/*
+ * 並べ替えの意味論 (層 1)。DOM に触れない純関数なので、off-by-one をここで網羅する。
+ * 「挿入 index (隙間 0..n)」と「最終 index (移動後の配列 0..n-1)」を混同しないことが本丸
+ * (受け入れ条件 A5)。
+ */
+
+describe("moveItem", () => {
+    it("前方へ動かす (index 2 → 0)", () => {
+        expect(moveItem(["A", "B", "C"], 2, 0)).toEqual(["C", "A", "B"]);
+    });
+
+    it("後方へ動かす (index 0 → 2)", () => {
+        expect(moveItem(["A", "B", "C"], 0, 2)).toEqual(["B", "C", "A"]);
+    });
+
+    it("端から端へ動かす", () => {
+        expect(moveItem(["A", "B", "C", "D"], 0, 3)).toEqual(["B", "C", "D", "A"]);
+        expect(moveItem(["A", "B", "C", "D"], 3, 0)).toEqual(["D", "A", "B", "C"]);
+    });
+
+    it("from === to は動かさない", () => {
+        expect(moveItem(["A", "B", "C"], 1, 1)).toEqual(["A", "B", "C"]);
+    });
+
+    it("to が範囲外なら端へ丸める (throw しない)", () => {
+        expect(moveItem(["A", "B", "C"], 0, 99)).toEqual(["B", "C", "A"]);
+        expect(moveItem(["A", "B", "C"], 2, -5)).toEqual(["C", "A", "B"]);
+    });
+
+    it("from が範囲外なら動かさない", () => {
+        expect(moveItem(["A", "B", "C"], 3, 0)).toEqual(["A", "B", "C"]);
+        expect(moveItem(["A", "B", "C"], -1, 0)).toEqual(["A", "B", "C"]);
+    });
+
+    it("非整数は動かさない", () => {
+        expect(moveItem(["A", "B", "C"], 0.5, 2)).toEqual(["A", "B", "C"]);
+        expect(moveItem(["A", "B", "C"], 0, Number.NaN)).toEqual(["A", "B", "C"]);
+    });
+
+    it("空配列でも throw しない", () => {
+        expect(moveItem([], 0, 0)).toEqual([]);
+    });
+
+    it("入力配列を変更しない (新しい配列を返す)", () => {
+        const source = ["A", "B", "C"];
+        const result = moveItem(source, 0, 2);
+
+        expect(source).toEqual(["A", "B", "C"]);
+        expect(result).not.toBe(source);
+    });
+
+    it("要素に undefined を含む配列でも正しく動く (値を存在判定に使っていない)", () => {
+        const list: Array<number | undefined> = [undefined, 1, 2];
+
+        expect(moveItem(list, 0, 2)).toEqual([1, 2, undefined]);
+        expect(moveItem(list, 2, 0)).toEqual([2, undefined, 1]);
+    });
+});
+
+describe("insertionIndexFromRects", () => {
+    /** top = index * 100, height = 100 の等間隔リスト */
+    const rows: RowBounds[] = [
+        { top: 0, height: 100 },
+        { top: 100, height: 100 },
+        { top: 200, height: 100 },
+    ];
+
+    it("空配列は常に 0", () => {
+        expect(insertionIndexFromRects([], 500)).toBe(0);
+    });
+
+    it("1 行目の中点より上なら 0", () => {
+        expect(insertionIndexFromRects(rows, 10)).toBe(0);
+        expect(insertionIndexFromRects(rows, 49)).toBe(0);
+    });
+
+    it("1 行目の中点より下なら 1", () => {
+        expect(insertionIndexFromRects(rows, 51)).toBe(1);
+    });
+
+    it("最終行の中点より下なら rows.length", () => {
+        expect(insertionIndexFromRects(rows, 260)).toBe(3);
+        expect(insertionIndexFromRects(rows, 9999)).toBe(3);
+    });
+
+    it("行の高さが不揃いでも各行の中点で切り替わる", () => {
+        const uneven: RowBounds[] = [
+            { top: 0, height: 40 }, // 中点 20
+            { top: 40, height: 200 }, // 中点 140
+        ];
+
+        expect(insertionIndexFromRects(uneven, 19)).toBe(0);
+        expect(insertionIndexFromRects(uneven, 21)).toBe(1);
+        expect(insertionIndexFromRects(uneven, 139)).toBe(1);
+        expect(insertionIndexFromRects(uneven, 141)).toBe(2);
+    });
+});
+
+describe("toFinalIndex", () => {
+    it("insertion <= from は素通し", () => {
+        expect(toFinalIndex(0, 2)).toBe(0);
+        expect(toFinalIndex(2, 2)).toBe(2);
+    });
+
+    it("insertion > from は 1 減る (掴んだ行が抜けるぶん詰まる)", () => {
+        expect(toFinalIndex(3, 1)).toBe(2);
+        expect(toFinalIndex(4, 0)).toBe(3);
+    });
+
+    it("掴んだ行の前後の隙間 (from / from+1) はどちらも from になる", () => {
+        expect(toFinalIndex(2, 2)).toBe(2);
+        expect(toFinalIndex(3, 2)).toBe(2);
+    });
+});
+
+describe("挿入 index → 最終 index → 並べ替え の合成 (off-by-one の恒久回帰)", () => {
+    const LIST = ["A", "B", "C", "D"] as const;
+
+    /** 手で並べた期待値 (from, insertion) → 結果 */
+    const CASES: ReadonlyArray<readonly [number, number, readonly string[]]> = [
+        // from = 0 (A を掴む)
+        [0, 0, ["A", "B", "C", "D"]],
+        [0, 1, ["A", "B", "C", "D"]],
+        [0, 2, ["B", "A", "C", "D"]],
+        [0, 3, ["B", "C", "A", "D"]],
+        [0, 4, ["B", "C", "D", "A"]],
+        // from = 1 (B を掴む)
+        [1, 0, ["B", "A", "C", "D"]],
+        [1, 1, ["A", "B", "C", "D"]],
+        [1, 2, ["A", "B", "C", "D"]],
+        [1, 3, ["A", "C", "B", "D"]],
+        [1, 4, ["A", "C", "D", "B"]],
+        // from = 2 (C を掴む)
+        [2, 0, ["C", "A", "B", "D"]],
+        [2, 1, ["A", "C", "B", "D"]],
+        [2, 2, ["A", "B", "C", "D"]],
+        [2, 3, ["A", "B", "C", "D"]],
+        [2, 4, ["A", "B", "D", "C"]],
+        // from = 3 (D を掴む)
+        [3, 0, ["D", "A", "B", "C"]],
+        [3, 1, ["A", "D", "B", "C"]],
+        [3, 2, ["A", "B", "D", "C"]],
+        [3, 3, ["A", "B", "C", "D"]],
+        [3, 4, ["A", "B", "C", "D"]],
+    ];
+
+    it.each(CASES)("from=%i を隙間 %i へ落とす", (from, insertion, expected) => {
+        expect(moveItem(LIST, from, toFinalIndex(insertion, from))).toEqual(expected);
+    });
+
+    it("全 (from, insertion) の組み合わせを網羅している", () => {
+        expect(CASES).toHaveLength(LIST.length * (LIST.length + 1));
+    });
+});
diff --git a/tests/js/lib/dnd/pointer-drag.test.ts b/tests/js/lib/dnd/pointer-drag.test.ts
new file mode 100644
index 0000000..883f09c
--- /dev/null
+++ b/tests/js/lib/dnd/pointer-drag.test.ts
@@ -0,0 +1,323 @@
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import {
+    createPointerDrag,
+    type PointerDragController,
+    type PointerDragState,
+} from "@/lib/dnd/pointer-drag";
+import { withoutPointerCapture } from "../../support/pointer-capture";
+
+/*
+ * ポインタの生死 (層 2)。開始・確定・取消・解放が漏れないことを jsdom 上で固定する。
+ * 行の実測 (getBoundingClientRect) は spy で固定値へ差し替え、座標だけを入力にする。
+ *
+ * **保証範囲を誇張しない**: ここが緑でも iOS Safari の実挙動 (タッチの取りこぼし・
+ * 慣性スクロールとの競合) は保証しない。それは実機確認 (受け入れ条件 A3) が担う。
+ */
+
+/** 行 3 つ。top = index * 100, height = 100 (中点は 50 / 150 / 250) */
+let rects: Array<{ top: number; height: number }> = [];
+
+let container: HTMLDivElement;
+let rows: HTMLElement[] = [];
+let handles: HTMLButtonElement[] = [];
+/** ハンドルの pointerdown で start() が返した値 (受理/拒否) の履歴 */
+let startResults: boolean[] = [];
+
+let ctl: PointerDragController;
+const onState = vi.fn<(state: PointerDragState) => void>();
+const onCommit = vi.fn<(from: number, to: number) => void>();
+const onCancel = vi.fn<() => void>();
+
+function setRects(next: Array<{ top: number; height: number }>): void {
+    rects = next;
+}
+
+function pointerEvent(
+    type: string,
+    init: { pointerId?: number; clientY?: number; button?: number; pointerType?: string } = {},
+): PointerEvent {
+    return new PointerEvent(type, {
+        bubbles: true,
+        cancelable: true,
+        pointerId: init.pointerId ?? 1,
+        clientY: init.clientY ?? 0,
+        button: init.button ?? 0,
+        pointerType: init.pointerType ?? "touch",
+    });
+}
+
+/** index 行のハンドルで pointerdown する (currentTarget = ハンドル要素になる) */
+function down(index: number, clientY: number, pointerId = 1): void {
+    handles[index]?.dispatchEvent(pointerEvent("pointerdown", { clientY, pointerId }));
+}
+
+function move(clientY: number, pointerId = 1): void {
+    window.dispatchEvent(pointerEvent("pointermove", { clientY, pointerId }));
+}
+
+function up(clientY: number, pointerId = 1): void {
+    window.dispatchEvent(pointerEvent("pointerup", { clientY, pointerId }));
+}
+
+function cancel(pointerId = 1): void {
+    window.dispatchEvent(pointerEvent("pointercancel", { pointerId }));
+}
+
+function pressEscape(): void {
+    window.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }));
+}
+
+/** onState の最終通知 */
+function lastState(): PointerDragState | undefined {
+    return onState.mock.calls[onState.mock.calls.length - 1]?.[0];
+}
+
+beforeEach(() => {
+    setRects([
+        { top: 0, height: 100 },
+        { top: 100, height: 100 },
+        { top: 200, height: 100 },
+    ]);
+    container = document.createElement("div");
+    rows = [];
+    handles = [];
+    startResults = [];
+    for (let i = 0; i < 3; i += 1) {
+        const row = document.createElement("div");
+        row.dataset.rowIndex = String(i);
+        const handle = document.createElement("button");
+        handle.type = "button";
+        handle.addEventListener("pointerdown", (event) => {
+            startResults.push(ctl.start(i, event));
+        });
+        row.append(handle);
+        container.append(row);
+        rows.push(row);
+        handles.push(handle);
+    }
+    document.body.append(container);
+
+    // 行の実測は data-row-index から rects を引く (座標だけを入力にする)
+    vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockImplementation(function (
+        this: HTMLElement,
+    ): DOMRect {
+        const index = Number(this.dataset.rowIndex ?? "-1");
+        const rect = rects[index] ?? { top: 0, height: 0 };
+        return {
+            top: rect.top,
+            height: rect.height,
+            bottom: rect.top + rect.height,
+            left: 0,
+            right: 0,
+            width: 0,
+            x: 0,
+            y: rect.top,
+            toJSON: () => ({}),
+        } as DOMRect;
+    });
+
+    onState.mockReset();
+    onCommit.mockReset();
+    onCancel.mockReset();
+    ctl = createPointerDrag({
+        rows: () => rows,
+        onState,
+        onCommit,
+        onCancel,
+    });
+});
+
+afterEach(() => {
+    ctl.destroy();
+    container.remove();
+    vi.restoreAllMocks();
+    vi.unstubAllGlobals();
+});
+
+describe("createPointerDrag", () => {
+    it("閾値未満の移動はドラッグにならない (タップが並べ替えにならない)", () => {
+        down(0, 50);
+        move(53);
+        up(53);
+
+        expect(onCommit).not.toHaveBeenCalled();
+        expect(
+            onState.mock.calls.filter(([state]) => state.activeIndex !== null),
+        ).toHaveLength(0);
+    });
+
+    it("閾値を超えると activeIndex / insertionIndex を通知する", () => {
+        down(0, 50);
+        move(160);
+
+        expect(lastState()).toEqual({ activeIndex: 0, insertionIndex: 2 });
+    });
+
+    it("pointerup で onCommit(from, 最終 index) が 1 回だけ呼ばれる", () => {
+        down(0, 50);
+        move(260); // 最終行の中点より下 → 挿入 index 3
+        up(260);
+
+        expect(onCommit).toHaveBeenCalledTimes(1);
+        expect(onCommit).toHaveBeenCalledWith(0, 2); // toFinalIndex(3, 0) = 2
+        expect(lastState()).toEqual({ activeIndex: null, insertionIndex: null });
+    });
+
+    it("掴んだ行の直後の隙間へ落としても最終 index は変わらない", () => {
+        down(1, 150);
+        move(190); // 行 1 の中点より下・行 2 の中点より上 → 挿入 index 2
+        up(190);
+
+        expect(onCommit).not.toHaveBeenCalled();
+        expect(onCancel).toHaveBeenCalledTimes(1);
+    });
+
+    it("位置が変わらない drop は onCommit ではなく onCancel", () => {
+        down(0, 50);
+        move(10); // 挿入 index 0 → 最終 index 0 = from
+        up(10);
+
+        expect(onCommit).not.toHaveBeenCalled();
+        expect(onCancel).toHaveBeenCalledTimes(1);
+    });
+
+    it("pointercancel は onCommit を呼ばず onCancel を呼ぶ", () => {
+        down(0, 50);
+        move(260);
+        cancel();
+
+        expect(onCommit).not.toHaveBeenCalled();
+        expect(onCancel).toHaveBeenCalledTimes(1);
+        expect(lastState()).toEqual({ activeIndex: null, insertionIndex: null });
+    });
+
+    it("Escape は onCommit を呼ばず onCancel を呼ぶ", () => {
+        down(0, 50);
+        move(260);
+        pressEscape();
+
+        expect(onCommit).not.toHaveBeenCalled();
+        expect(onCancel).toHaveBeenCalledTimes(1);
+    });
+
+    it("異なる pointerId の move / up は無視する (2 本目の指で確定しない)", () => {
+        down(0, 50, 1);
+        move(260, 2); // 別の指
+        up(260, 2);
+
+        expect(onCommit).not.toHaveBeenCalled();
+        expect(onCancel).not.toHaveBeenCalled();
+
+        move(260, 1);
+        up(260, 1);
+
+        expect(onCommit).toHaveBeenCalledWith(0, 2);
+    });
+
+    it("start() は進行中に 2 回目を拒否し、1 本目の対象を保持する", () => {
+        down(0, 50, 1);
+        down(2, 250, 2); // 2 本目の指: 拒否される
+
+        expect(startResults).toEqual([true, false]);
+
+        move(260, 1);
+        up(260, 1);
+
+        expect(onCommit).toHaveBeenCalledWith(0, 2); // 1 本目 (from=0) のまま
+    });
+
+    it("マウスの左ボタン以外では開始しない", () => {
+        handles[0]?.dispatchEvent(
+            pointerEvent("pointerdown", { clientY: 50, pointerType: "mouse", button: 2 }),
+        );
+
+        expect(startResults).toEqual([false]);
+
+        move(260);
+        up(260);
+
+        expect(onCommit).not.toHaveBeenCalled();
+    });
+
+    it("destroy() 後は listener が外れ callback が来ない", () => {
+        down(0, 50);
+        ctl.destroy();
+        onState.mockReset();
+
+        move(260);
+        up(260);
+
+        expect(onState).not.toHaveBeenCalled();
+        expect(onCommit).not.toHaveBeenCalled();
+    });
+
+    it("ドラッグ中の destroy() は onCommit も onCancel も呼ばず onState だけをリセットする", () => {
+        down(0, 50);
+        move(260);
+        onState.mockReset();
+
+        ctl.destroy();
+
+        expect(onCommit).not.toHaveBeenCalled();
+        expect(onCancel).not.toHaveBeenCalled();
+        expect(onState).toHaveBeenCalledTimes(1);
+        expect(lastState()).toEqual({ activeIndex: null, insertionIndex: null });
+    });
+
+    it("pointer capture が無い環境でも開始 → 移動 → 確定まで完走する", async () => {
+        await withoutPointerCapture(() => {
+            down(0, 50);
+            move(260);
+            up(260);
+
+            expect(onCommit).toHaveBeenCalledWith(0, 2);
+        });
+    });
+
+    describe("端の自動スクロール", () => {
+        let frame: FrameRequestCallback | null = null;
+
+        beforeEach(() => {
+            frame = null;
+            // rAF を「callback を保存するだけ」の fake にする。同期即時実行にすると
+            // tickAutoScroll が末尾で次フレームを登録するため無限再帰になる (design-review R2)。
+            vi.stubGlobal("requestAnimationFrame", (cb: FrameRequestCallback) => {
+                frame = cb;
+                return 1;
+            });
+            vi.stubGlobal("cancelAnimationFrame", () => {
+                frame = null;
+            });
+            vi.spyOn(window, "scrollBy").mockImplementation(() => undefined);
+        });
+
+        it("指を止めたまま端でスクロールしても挿入位置を採り直す", () => {
+            down(0, 50);
+            move(750); // 画面下端 (innerHeight=768 - 64 = 704 より下)
+
+            expect(lastState()).toEqual({ activeIndex: 0, insertionIndex: 3 });
+            expect(frame).not.toBeNull();
+
+            // スクロールで行が下へずれた状態を作る (pointermove は出さない)
+            setRects([
+                { top: 600, height: 100 },
+                { top: 700, height: 100 },
+                { top: 800, height: 100 },
+            ]);
+            frame?.(0);
+
+            expect(window.scrollBy).toHaveBeenCalledWith(0, 12);
+            expect(lastState()).toEqual({ activeIndex: 0, insertionIndex: 2 });
+        });
+
+        it("端から離れるとスクロールを止める", () => {
+            down(0, 50);
+            move(750);
+            expect(frame).not.toBeNull();
+
+            move(400); // 端から離れる
+
+            expect(frame).toBeNull();
+        });
+    });
+});
diff --git a/tests/js/setup.ts b/tests/js/setup.ts
index ae883ec..6265fbf 100644
--- a/tests/js/setup.ts
+++ b/tests/js/setup.ts
@@ -72,6 +72,25 @@ if (typeof Element !== "undefined") {
     }
 }
 
+// jsdom は Pointer capture (setPointerCapture / releasePointerCapture / hasPointerCapture) を
+// 実装しない。並べ替えの制御 (resources/js/lib/dnd/pointer-drag.ts) は機能検出して
+// 無い環境でも完走するが、「capture がある環境」の分岐もテストで通せるよう
+// 実際の捕捉状態を覚える最小スタブを入れる。
+if (typeof Element !== "undefined" && typeof Element.prototype.setPointerCapture !== "function") {
+    const captured = new WeakMap<Element, Set<number>>();
+    Element.prototype.setPointerCapture = function (pointerId: number): void {
+        const ids = captured.get(this) ?? new Set<number>();
+        ids.add(pointerId);
+        captured.set(this, ids);
+    };
+    Element.prototype.releasePointerCapture = function (pointerId: number): void {
+        captured.get(this)?.delete(pointerId);
+    };
+    Element.prototype.hasPointerCapture = function (pointerId: number): boolean {
+        return captured.get(this)?.has(pointerId) ?? false;
+    };
+}
+
 // テスト間の DOM 汚染を防ぐ明示 cleanup。
 // さらに bits-ui の body-scroll-lock は Dialog/Popover/Select の unmount 時に
 // `<body>` スタイルを戻す setTimeout(~24ms) を予約する (huntabyte/bits-ui#1639)。
diff --git a/tests/js/support/pointer-capture.ts b/tests/js/support/pointer-capture.ts
new file mode 100644
index 0000000..3006531
--- /dev/null
+++ b/tests/js/support/pointer-capture.ts
@@ -0,0 +1,25 @@
+/**
+ * pointer capture が実装されていない環境 (古い WebKit 等) を模して run を実行する。
+ *
+ * `Element.prototype.setPointerCapture = undefined` は
+ * `(pointerId: number) => void` 型への undefined 代入になり型エラーになるため、
+ * `Object.defineProperty` で差し替えて finally で必ず戻す
+ * (delete も生の代入も使わない。design-review R1 の指摘)。
+ */
+export async function withoutPointerCapture(run: () => void | Promise<void>): Promise<void> {
+    const original = Object.getOwnPropertyDescriptor(Element.prototype, "setPointerCapture");
+    Object.defineProperty(Element.prototype, "setPointerCapture", {
+        value: undefined,
+        configurable: true,
+        writable: true,
+    });
+    try {
+        await run();
+    } finally {
+        if (original === undefined) {
+            Reflect.deleteProperty(Element.prototype, "setPointerCapture");
+        } else {
+            Object.defineProperty(Element.prototype, "setPointerCapture", original);
+        }
+    }
+}
```

## design system 参照 (DESIGN.md 抜粋)

### Elevation & Depth
**`box-shadow` は使わない。** Neutral(背景)と Surface(カード)の明度差、および 1px の
ボーダーで階層を表現する。ホバー時も影を出さず、ボーダー色や文字色の変化で反応を示す。
グラデーション・scale 効果も使わない。

### Shapes
角丸 ramp は **`rounded-sm`(4px)/ `rounded-md`(6px)/ `rounded-lg`(8px)の 3 段のみ**。
DOM 役割で選ぶ(上から優先): カード・モーダル=`lg` / 中間 box(パネル・`<pre>`)=`md` /
ボタン・入力・バッジ等の小コントロール=`sm`。
素の `rounded`・`rounded-xl` 以上・任意値・方向別(`rounded-t-*` 等)は使わない。

### 使用した token
`bg-primary` / `text-text` / `text-text-secondary` / `border-border-strong` /
`border-transparent` / `focus-visible:ring-primary/35`

### 触れた atomic ディレクトリ
```
resources/js/components/atoms/
  DragHandle.svelte      (新規。@lucide/svelte の GripVertical のみ / .types.ts と対で置く既存流儀)
  DragHandle.types.ts    (新規。Badge.types.ts / Button.types.ts と同じ companion 型ファイル方式)
resources/js/components/features/manual/ScenarioEditor.svelte  (改修)
resources/js/components/features/capture/TakeStrip.svelte      (改修)
resources/js/lib/dnd/
  list-reorder.ts        (新規。util 層 = 全層から import 可)
  pointer-drag.ts        (新規。util 層)
```

## 特に見てほしい点

1. `pointer-drag.ts` の `finish(commit, notify)` が本当に**唯一の出口**になっているか。
   資源 (pointer capture / rAF / window listener) が漏れる経路が残っていないか
2. 挿入 index (0..n) と最終 index (0..n-1) の変換が、
   撮影 PWA がサーバへ渡す `position` の意味と一致しているか
3. `ScenarioEditor.svelte` の `dragOwner` による排他が、
   手順ドラッグと急所ドラッグの取り違えを本当に塞いでいるか
4. 落とし先の目印 `<li>` を測定対象から外すため `directRows()` を
   `:scope > li[data-reorder-index]` に絞った判断 (設計書は `:scope > li` だった)。
   設計からの意図的な逸脱なので妥当性を判定してほしい
5. `TakeStrip.svelte` の `run()` の戻り値を `Promise<void>` → `Promise<boolean>` に
   変えたことで、既存 4 呼び出し (adopt / remove / downloadAndAck / confirmDelete) が
   壊れていないか
