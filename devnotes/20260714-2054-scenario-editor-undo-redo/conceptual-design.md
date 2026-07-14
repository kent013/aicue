# 概念設計: scenario-editor-undo-redo

## 背景・課題

- ユースケース・カバレッジ監査ギャップ #3 (Medium)。
- `doc/04_PCサイト機能仕様.md` L42 は、シナリオ編集画面の機能として
  **「Undo / Redo: 一つ戻る / 一つ進む で直前操作の取消・やり直し」** を明記している。
- しかし `resources/js/components/features/manual/ScenarioEditor.svelte` に該当実装が無い
  (`grep -n "undo|redo|元に戻す|やり直"` → 0 hit)。
- v1 スコープ判定: この機能は `doc/04`(PC サイト機能仕様)の確定要件であり、v1 スコープ
  (字幕のみ / PWA 撮影 / ffmpeg 合成 / 単一 Default Project) の対象外ではない。
  編集 UX の要件であって「TTS」「マルチモーダル」等の後回し項目にも該当しない。
  → **v1 対象・未実装。実装が必要**。

## 改善アイデア

ScenarioEditor のクライアント編集状態(作業コピー `steps: DraftStep[]`)に対する
**undo / redo スタック**を実装する。**保存前のローカル編集のみ**を対象とし、
サーバ状態(`version` / `snapshot`)は不変に保つ(doc/09 §9.4 の document 単位保存・
楽観ロックモデルと整合)。

対象操作(brief 準拠):

- 行の追加/削除(`addStep` / `addPoint` / `removeStep` / `removePoint`)
- 並べ替え(▲▼: `moveStep` / `movePoint`)
- セル編集(各 `bind:value` フィールド)
- 手順削除に伴う配下急所の連動削除(step 削除は配下 points ごと消えるため、
  スナップショット復元で自然に巻き戻る)

UI:

- 「元に戻す(Undo)」「やり直す(Redo)」ボタンを操作領域に追加。
- キーボード: `Ctrl/Cmd+Z` = Undo、`Ctrl/Cmd+Shift+Z` = Redo。

## 実装方針(概要)

### 履歴モデル: 正規形スナップショット方式(命令方式は採らない)

既存の正規化シリアライザ `serializeSteps(list: DraftStep[]): string`(payload 対象
フィールド + id + points をキー順固定で JSON 化)が、編集状態を**過不足なく**表現している
(編集可能フィールドは全て `rowOf` + `id` + `points` に含まれる)。これを再利用し、
履歴 1 エントリ = `serializeSteps` 文字列とする**スナップショット方式**を採る。

- 命令(操作差分)方式は、操作種別ごとの逆操作実装が必要で複雑(思考原則: 過剰実装禁止)。
- スナップショット方式なら復元は `deserializeSteps(serialized): DraftStep[]` 1 本で済み、
  `steps` を丸ごと再代入 → `{#each steps as step (step)}` の identity キーが更新され
  再描画・再バインドされる。`dirty` は `snapshot` 比較で自動再計算される。

### スタック構造

- `undoStack: string[]` … 「変更前」状態の系列(古→新)。
- `redoStack: string[]` … redo 用に退避した状態。
- 現在状態はスタックに持たず `steps`(ライブ)に置く。
- **メモリ上限** `MAX_HISTORY`(例 100 エントリ)を超えたら `undoStack` の先頭を捨てる
  (brief:「過大な履歴はメモリ上限で打ち切ってよい」)。

### コミット点(いつ履歴エントリを積むか)

セル編集は `bind:value` の連続 input でキーストローク毎に発火するため、
**1 打鍵 = 1 エントリにしない**(UX 破綻・メモリ肥大回避)。ネイティブの focus イベントで
「フィールド編集セッション」単位に coalesce する:

- **構造操作**(add/remove/move ボタン): ハンドラ内で `flushPendingEdit()` →
  「変更前 `serializeSteps` を `undoStack` に積む → 変異」。move は境界 early-return するため
  実変化時のみ積む。
- **テキスト編集**: セクションに委譲した `onfocusin` で編集フィールド初回フォーカス時の
  状態を `editBaseline` として退避、`onfocusout` で状態が変化していれば `editBaseline` を
  `undoStack` に積む(フィールド単位で 1 エントリ)。Select/number も focusout で拾える。
- どのコミット(push)でも **`redoStack` をクリア**(brief:「新規編集で redo スタッククリア」)。

**二重 push の回避(Codex R1 指摘)**: `flushPendingEdit()` は commit 後に必ず
`editBaseline=null` を立てる**冪等**関数。構造操作は「flush(進行中テキスト編集を 1 エントリに確定)
→ 現在状態を before として push(構造変更分)」の順で、テキスト分と構造分を別エントリに
分離する(同一遷移の二重計上は起きない)。`push` は変化がある場合のみ(before !== 現在)。

### IME(観点3 対応 / R2・R3 補強)

`compositionend` と `focusout` の発火順序、さらに `focusout → 構造操作 click → compositionend`
の順序もブラウザ/IME 依存で保証できない。そこで**コミットを誘発する全経路を IME gate 化**する
(`flushPendingEdit()` 自体・構造操作・undo/redo)。`compositionstart`/`compositionend` で
`composing` 状態を管理し、変換中の要求は `compositionend` 後に保留実行する:

- `oncompositionstart`: `composing = true`。
- `flushPendingEdit()`(IME-aware): `composing` なら commit せず `flushDeferred = true` で return。
  そうでなければ `editBaseline` を(変化があれば)push して `editBaseline = null`。
- `onfocusout`: `flushPendingEdit()` を呼ぶ(composing なら上記で遅延される)。
- **構造操作 / undo / redo** は `runSettled(action)` を通す。`composing` なら
  `pendingAction = action` に退避して return、そうでなければ即実行。
- `oncompositionend`: `composing = false` → `flushDeferred` が立っていれば `flushPendingEdit()`
  (テキスト編集を 1 エントリ確定)→ `pendingAction` があれば取り出して実行(構造操作等を続行)。
- キーボード undo/redo ハンドラは `event.isComposing` で変換中を無視する
  (フォーカスが input 内なら元々 native 委譲)。

これにより `compositionstart → focusout → 構造 click → compositionend` の順でも、
テキスト編集と構造操作がそれぞれ 1 エントリになり、変換途中の中間文字列は履歴に積まれない。
`pendingAction` は同時に 1 つ(ユーザ操作は逐次)で足りる。

### 利用可否(canUndo / canRedo — R2 観点2 対応)

Undo ボタンはスタックだけでなく**未確定の pending 編集**も可否に含める(初回セル編集中は
undoStack が空でも戻せるべき。disabled だとクリック→blur→flush が成立しない):

```
canUndo = undoStack.length > 0
        || (editBaseline !== null && editBaseline !== serializeSteps(steps))
canRedo = redoStack.length > 0
```

### undo / redo(fail-safe 込み)

```
undo():
  flushPendingEdit()
  if undoStack 空: return               // no-op
  prev = undoStack[top]                  // peek(まだ pop しない)
  restored = parseHistory(prev)          // unknown→validate→normalize
  if restored === null:                  // 壊れた履歴
     resetHistory(); warnToast(); return // steps は変えない fail-safe
  undoStack.pop()
  redoStack.push(serializeSteps(steps))  // 現在状態を redo へ退避
  steps = restored

redo(): 対称(redoStack から取り、現在を undoStack へ)
```

`parseHistory(serialized): DraftStep[] | null` は `JSON.parse` を try/catch で包み、
`SerializedStep[]` の shape を型ガードで検証してから `rowOf` で正規形に写し**新規オブジェクト**を
生成する。検証は「配列であること」「各 step/point が `rowOf` の 8 フィールド + `id: number|null`
を持つこと」「`points` が配列であること」まで行い、内部に素の型アサーションを残さない
(既存 `isScenarioRow`/`isScenarioDocument` と同粒度の防御的パース)。復元は `steps` 再代入のみで
focusin を誘発しないため `restoring` フラグ等の追加状態は不要。

### 履歴サイズ管理(観点5 対応 / R2 補強)

件数だけでなく総文字数でも打ち切る(全文書 JSON × N 件のメモリ非有界化を防ぐ)。
純関数に切り出し**両スタックに適用**する:

- 定数 `MAX_HISTORY_ENTRIES = 100`、`MAX_HISTORY_CHARS = 2_000_000`(≈ 数 MB)。
- `boundHistory(stack, maxEntries, maxChars)`: 先頭(最古)から、件数 or 総文字数が上限内に
  なるまで捨てる。ただし**単一の巨大エントリで空にはしない**(`length > 1` を保持)。
  → `MAX_HISTORY_CHARS` は「単一エントリは残す」ためのソフト上限(1 エントリが上限超でも保持)。
- undo/redo いずれの push 後にも当該スタックへ適用(各スタック個別上限)。
- `resetHistory()` は `undoStack`/`redoStack` を空・`editBaseline=null`・`flushDeferred=false`。
- このスタック操作は純関数ユーティリティ
  `resources/js/lib/manual/scenario-history.ts` に切り出し単体テストする
  (brief「関連 util(履歴スタック)」に整合)。

### 保存/リロードとの整合(「保存後の履歴扱い」)

- 保存成功(`applySaved` → `reseed`)・409/明示リロード(`reseed`)時に
  **履歴をリセット**(`undoStack=[] / redoStack=[] / editBaseline=null`)。
  理由: `reseed` はサーバ最新で作業コピーを置換する時点。以降の undo は
  「保存前のローカル編集のみを対象」の原則に反する(サーバ状態は不変)。保存で
  ローカル編集は永続化済みであり、跨いで戻す先が無い。→ 保存境界で履歴を断つのが
  原則整合かつ最小実装。

### dirty / 離脱警告との整合

- `dirty = serializeSteps(steps) !== snapshot` は undo/redo による `steps` 変化で自動再計算。
  undo で保存済みスナップショットに一致まで戻れば `dirty=false` に戻る(特別扱い不要)。
- 既存の `beforeunload` / Inertia `before` 離脱ガードは `dirty` 駆動のため変更不要。

### キーボード(native undo との両立 — 観点4 対応)

- `$effect` で `window` の `keydown` を購読(cleanup 付き)。`Ctrl/Cmd+Z`=undo、
  `Ctrl/Cmd+Shift+Z`=redo。
- **フォーカス文脈依存**にして native text undo と両立する:
  - 編集フィールド(input / textarea / select / contenteditable)にフォーカスがある間は
    `preventDefault` せず、ブラウザ標準の文字単位 undo に委ねる(アプリ層は介入しない)。
  - フォーカスが編集フィールド外(ボタン / body 等)のときのみ `preventDefault` +
    アプリ層 document undo/redo を実行。
- **ボタンは常にアプリ層 undo/redo**(明示操作)。ボタン押下は先に input を blur
  (focusout→pending 編集を確定)するため、確定済み履歴に対して働く。
- `event.isComposing`(IME 変換中)・`saving` 中・ConfirmDialog 表示中はガードして無視。
- → brief のショートカット要件を満たしつつ、テキスト編集中の native undo を奪わない。

### UI ボタン

- 既存 Button atom(`variant="neutral"` / `size="sm"`)を利用。操作領域(「シナリオを更新」
  付近)に「元に戻す」「やり直す」を配置。アイコンは Lucide(`Undo2` / `Redo2`)。
- スタックが空のとき当該ボタンは `disabled`。これは DESIGN.md L399「必須条件未充足を理由に
  disabled でブロックしない」の**禁止対象ではない**: 空スタックは「不足している入力」ではなく
  「戻る/進む先が存在しない」という機能の内在的不可用状態(ConfirmDialog の processing 中
  disabled と同種)。押下しても伝えるべき不足が無い純 no-op のため、活性化して無反応にする
  方が不親切。→ **disabled + `aria-disabled` を採用**(設計判断として明示)。

## 期待効果

- 使命への貢献: AI 生成シナリオの微修正を安心して行える編集体験の基礎。doc/04 の確定要件を
  満たしカバレッジギャップを閉じる(「編集ゼロの実現」そのものではなく、その UX を支える下地)。
- 具体効果(主効果): 誤った**行削除 / 並べ替え / ポイント削除**を 1 操作で復旧できる。
  副次的にセル編集も往復でき、保存前の試行錯誤・誤操作復旧コストを下げる。

## テスト計画(fail-first / Vitest。詳細設計で具体化)

- 編集 → undo で前状態 → redo で再適用(セル編集・行追加/削除・並べ替え各種)。
- 複数操作の連続 undo/redo。
- 新規編集で redo スタッククリア。
- 保存成功 → reseed 後に履歴リセット(undo が無効化される)。
- 409 / 明示リロード → reseed 後に履歴リセット。
- ショートカット: 編集フィールド内は native 委譲(preventDefault しない)/ フィールド外は
  app undo。`isComposing` 中は無視。
- `blur → 構造操作`(button click)で二重 push しない(undo 回数が操作数と一致)。
- 復元失敗時の fail-safe(壊れた履歴で steps を破壊せず履歴リセット)。
- メモリ上限(件数 / 文字数)での最古エントリ打ち切り(`boundHistory` 純関数の単体テスト。
  単一巨大エントリは残す)。
- undo で snapshot に一致まで戻ると `dirty=false`(離脱警告が解ける)。
- 初回セル編集の focus 中に Undo ボタンで戻せる(canUndo が pending 編集を含む)。
- IME: `compositionstart → focusout(composing) → compositionend` の順序で 1 エントリに確定
  (focusout 先行でも中間状態を積まない)。
- IME: `compositionstart → focusout → 構造操作 click → compositionend` の順序で、テキスト編集と
  構造操作がそれぞれ 1 エントリ(中間文字列を積まず、構造操作は compositionend 後に実行)。

## 制約・前提

- 変更は ScenarioEditor.svelte + 履歴ユーティリティ純関数
  (`resources/js/lib/manual/scenario-history.ts`)に限定。サーバ API・DTO・ルート・PHP 側は
  **一切変更しない**(ローカル編集状態のみが対象。波及なし)。
- Svelte 5 runes / DS token / Lucide のみ(AGENTS.md フロント規約)。
- テストは Vitest。既存 `ScenarioEditor.test.ts` を壊さず追記する。

## スコープ外

- サーバ側の編集履歴永続化・複数タブ間同期・操作ログ(v1 不要。過剰実装)。
- 編集フィールド内は native undo に委ね、document undo との厳密な履歴統合は行わない。
- Redo の Windows 慣習 `Ctrl+Y`(brief は Shift+Z のみ指定。最小実装で追加しない)。
- TTS / 撮影 PWA / 合成など v1 スコープの他領域。
