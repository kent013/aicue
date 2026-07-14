# アプリの使命 (North Star) — AGENTS.md より

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項 — AGENTS.md より

1. テストなしの実装完了報告(不変条件は対応するテストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

# 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。想定外のパターンも判断材料になる。
先人の知恵を探せ(Laravel/Svelte エコシステムの既存解を使う)。
機能の名前に立ち返れ。
仕組みが機能していない段階で値を弄るな。

# ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは Web アプリケーション(Laravel + Svelte)の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善は使命(North Star)に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か(Laravel 12 + Svelte 5 runes + Inertia.js + TypeScript)
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResource パターン(今回はフロントのみだが TS 型安全性)。PHPStan L10 を通せるか

【重要な前提コンテキスト】
- 対象は Svelte 5 の単一コンポーネント `ScenarioEditor.svelte`(クライアント編集のみ)。サーバ API 変更なし。
- 既存実装: 作業コピー `steps = $state<DraftStep[]>` を編集し、`serializeSteps()` で正規化 JSON 文字列を生成、`snapshot`(保存済み正規形)との差分で `dirty` を `$derived` 判定。保存は document 全体を 1 回の PUT(楽観ロック `expected_version`)。409/419/403/422 を自前描画。保存成功/明示リロードは `reseed()` で作業コピーを再 seed。`beforeunload` + Inertia `before` で dirty 離脱警告。
- `{#each steps as step (step)}` はオブジェクト identity をキーにしている。
- テキスト編集は各 `bind:value` の連続 input で発火する(単一のコミットイベントが無い)。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（以下は devnotes/20260714-2054-scenario-editor-undo-redo/conceptual-design.md の内容）

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

- **構造操作**(add/remove/move ボタン): ハンドラ内で「変更前 `serializeSteps` を
  `undoStack` に積む → 変異」。move は境界 early-return するため実変化時のみ積む。
- **テキスト編集**: セクションに委譲した `onfocusin` で編集フィールド初回フォーカス時の
  状態を `editBaseline` として退避、`onfocusout` で状態が変化していれば `editBaseline` を
  `undoStack` に積む(フィールド単位で 1 エントリ)。Select/number も focusout で拾える。
- どのコミットでも **`redoStack` をクリア**(brief:「新規編集で redo スタッククリア」)。

`push`(実変化を伴うコミット)は `flushPendingEdit()` を内包し、
構造操作直前に進行中のテキスト編集を確定してから積む(粒度の正しさ)。

### undo / redo

```
undo(): flushPendingEdit() → prev = undoStack.pop()（無ければ no-op）
        → redoStack.push(現在の serialize) → steps = deserializeSteps(prev)
redo(): next = redoStack.pop()（無ければ no-op）
        → undoStack.push(現在の serialize) → steps = deserializeSteps(next)
```

`deserializeSteps` は `JSON.parse` 後、`rowOf` で正規形へ写して**新規オブジェクト**を生成
(identity キー更新 + $state proxy 分離)。復元は `steps` 再代入のみで focusin を誘発しないため
`restoring` フラグ等の追加状態は不要。

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

### キーボード

- `$effect` で `window` の `keydown` を購読(cleanup 付き)。`Ctrl/Cmd+Z`=undo、
  `Ctrl/Cmd+Shift+Z`=redo。ハンドル時は `preventDefault`。
- `saving` 中・ConfirmDialog 表示中はガードして無視。
- 注: input 内でのブラウザ標準テキスト undo より**アプリ層の document undo を優先**する
  (doc/04 の要件は document 単位の取消)。トレードオフとして設計判断に明記。

### UI ボタン

- 既存 Button atom(`variant="neutral"` / `size="sm"`)を利用。操作領域(「シナリオを更新」
  付近)に「元に戻す」「やり直す」を配置。アイコンは Lucide(`Undo2` / `Redo2`)。
- スタックが空のとき当該ボタンは `disabled`。これは DESIGN.md L399「必須条件未充足を理由に
  disabled でブロックしない」の**禁止対象ではない**: 空スタックは「不足している入力」ではなく
  「戻る/進む先が存在しない」という機能の内在的不可用状態(ConfirmDialog の processing 中
  disabled と同種)。押下しても伝えるべき不足が無い純 no-op のため、活性化して無反応にする
  方が不親切。→ **disabled + `aria-disabled` を採用**(設計判断として明示)。

## 期待効果

- 使命への貢献:「編集ゼロ」を掲げる AI-CUE で、AI 生成シナリオの微修正を安心して行える
  (誤操作の即時取消)ことは編集体験の基礎。doc/04 の確定要件を満たしカバレッジギャップを閉じる。
- 具体効果: 誤った行削除/並べ替え/編集を 1 操作で復旧でき、保存前の試行錯誤コストを下げる。

## 制約・前提

- 変更は ScenarioEditor.svelte + 履歴ユーティリティ(純関数)に限定。サーバ API・DTO・
  ルート・PHP 側は**一切変更しない**(ローカル編集状態のみが対象。波及なし)。
- Svelte 5 runes / DS token / Lucide のみ(AGENTS.md フロント規約)。
- テストは Vitest。既存 `ScenarioEditor.test.ts` を壊さず追記する。

## スコープ外

- サーバ側の編集履歴永続化・複数タブ間同期・操作ログ(v1 不要。過剰実装)。
- input 内テキストのネイティブ undo との厳密な段階連動(アプリ層 undo を優先で割り切る)。
- Redo の Windows 慣習 `Ctrl+Y`(brief は Shift+Z のみ指定。最小実装で追加しない)。
- TTS / 撮影 PWA / 合成など v1 スコープの他領域。

