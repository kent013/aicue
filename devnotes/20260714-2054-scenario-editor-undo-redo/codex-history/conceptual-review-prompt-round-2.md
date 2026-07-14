# 概念設計レビュー Round 2 — 対応報告

Round 1 の指摘(全て Warning / Suggestion。Critical なし)に対し概念設計を改訂しました。
以下の対応で全体判定 APPROVED に至るか、残課題があれば指摘してください。

## 対応サマリー

### [Warning 観点4] Ctrl/Cmd+Z が native text undo を奪う
→ キーボード undo/redo を**フォーカス文脈依存**に変更。編集フィールド
(input/textarea/select/contenteditable)にフォーカスがある間は `preventDefault` せず
native 文字単位 undo に委ねる。フォーカスが編集フィールド外のときのみ app 層 document
undo/redo を実行。ボタンは常に app 層(明示操作、押下で input を blur→pending 確定)。
→ ショートカット要件は満たしつつ衝突回避。

### [Warning 観点3] focusin/focusout 順序・IME・二重 push
→ (a) keydown に `event.isComposing` ガード。(b) `flushPendingEdit()` は commit 後
`editBaseline=null` を立て冪等化(直後 focusout で再 push しない)。(c) 構造操作は
「flush(テキスト編集を 1 エントリ確定)→ 現在状態を before として push(構造変更分)」の順で
別エントリに分離、同一遷移の二重計上を排除。push は変化がある時のみ。(d) `onfocusout` は
IME 確定後に発火するため中間状態を積まない。テストで `blur→click` と `keydown while focused` を個別固定。

### [Warning 観点5] MAX_HISTORY 件数のみ
→ 件数 `MAX_HISTORY_ENTRIES=100` に加え総文字数 `MAX_HISTORY_CHARS=2_000_000`(≈数MB)を併用。
push 後どちらか超過の間、最古を捨てる。running total で O(1) 管理。

### [Warning 観点5] deserialize 失敗でエディタ全体が落ちる
→ 復元を `parseHistory(serialized): DraftStep[] | null` に集約。`JSON.parse` を try/catch +
shape 検証。undo/redo はスタックを **peek→validate してから pop/push**。失敗時は steps を
変えず `resetHistory()` + 警告トースト(fail-safe)。

### [Warning 観点7] deserializeSteps が実質キャスト
→ 履歴形を明示型 `SerializedStep`/`SerializedPoint` で定義し、
`unknown → validate(型ガード) → rowOf 正規化 → DraftStep[]` の薄いデコーダにする
(既存 `isScenarioRow`/`isScenarioDocument` と同粒度)。

### [Warning 観点2] テスト計画が粗い
→ fail-first で列挙: dirty 往復 / redo クリア / save→reseed 後の履歴リセット /
409・明示リロード後の履歴リセット / ショートカット(field 内 native 委譲・field 外 app undo・
isComposing 無視)/ blur→構造操作の二重 push 防止(undo 回数=操作数)/ 復元失敗 fail-safe /
メモリ上限打ち切り / undo で dirty=false 復帰。

### [Suggestion 観点1,4] 期待効果の誇大表現
→ 「編集ゼロの実現」ではなく「保存前の試行錯誤・誤操作復旧コストの低減」に表現を修正。
主効果を行削除/並べ替え/ポイント削除の復旧に定義。

## 改訂後の該当セクション(抜粋)

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

### IME(観点3 対応)

`onfocusout` はフィールド離脱時(=IME 変換確定後)に発火するため、変換途中の中間状態を
コミットしない。キーボード undo/redo ハンドラは `event.isComposing` を見て変換中は無視する。

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
生成する(identity キー更新 + $state proxy 分離。既存 `isScenarioRow`/`isScenarioDocument` と
同粒度の防御的パース)。復元は `steps` 再代入のみで focusin を誘発しないため `restoring`
フラグ等の追加状態は不要。

### 履歴サイズ管理(観点5 対応)

件数だけでなく総文字数でも打ち切る(全文書 JSON × N 件のメモリ非有界化を防ぐ):

- `MAX_HISTORY_ENTRIES = 100`、`MAX_HISTORY_CHARS = 2_000_000`(≈ 数 MB)。
- push 後、`undoStack.length > MAX_HISTORY_ENTRIES` または累計文字数 > `MAX_HISTORY_CHARS`
  の間、先頭(最古)を捨てる。累計文字数は running total で O(1) 管理。

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
- メモリ上限(件数 / 文字数)での最古エントリ打ち切り。
- undo で snapshot に一致まで戻ると `dirty=false`(離脱警告が解ける)。

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

