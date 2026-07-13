# 概念設計レビュー Round 3

Round 2 の 2 つの [Warning] と Suggestion を反映した。判定を再確認してほしい。

## 対応サマリ

### [Warning] 観点3: 署名比較は「新スナップショット」を識別できない → 対応 (path A: narrow fix に統一)
署名比較を撤回。本 finding の根本原因に忠実な narrow fix に統一した。
- 残留エラーは 422「手順書をアップロードしてください。」= start error であり、その precondition
  (手順書なし) が解消される契機は `hasDocument` prop の **false→true 遷移** に一意に対応する。
- トリガー: `hasDocument` の false→true 遷移を `$effect` で検知し、かつ start error が
  `missing_document` 種別のときだけ overlay を破棄する。前回 hasDocument は非リアクティブな
  ローカル変数で保持。マウント初回は was===now で no-op。ポーリングは props を変えないので発火しない。

### [Warning] 観点2/禁止事項#1: 回帰固定テストが未明記 → 対応
受け入れ基準に新規 vitest ケースを明示:
- 「422 表示後に hasDocument: false→true の rerender で start-error alert が消える」
- 「hasDocument: false→true でも missing_document 以外 (402 相当種別) の start error は消えない」(種別ゲート)
- 「ポーリング state / failedJob 表示が rerender で維持される」(非退行)

### [Suggestion] 観点7: エラー種別を state に持ち型安全に 422 だけ消す → 対応
`type StartErrorKind = "missing_document" | "insufficient_tickets" | "conflict" | "generic"` と
`startErrorKind` state を導入。handleStartResponse で res.status/code から設定
(422→missing_document / 402(insufficient_tickets)→insufficient_tickets / 409→conflict / 他→generic)。
overlay 破棄は startErrorKind === "missing_document" のときのみ。errorMessage の文字列一致では判定しない
(国際化・文言変更に脆いため)。startAnalyze 冒頭のリセットに startErrorKind = null を追加。

### [Suggestion] 観点4/6: 効果と責務を「Show 内 SOP アップロード後に残る 422」に限定 → 対応
「transient overlay 無条件破棄」の一般化表現を削除し、narrow fix の効果記述に書き換えた。

## 更新後の設計要点

- 変更は AnalysisPanel.svelte 1 ファイル。
- StartErrorKind union + startErrorKind state を追加。
- `$effect`: hasDocument が false→true かつ startErrorKind==="missing_document" のとき
  errorMessage=null / showPurchaseLink=false / startErrorKind=null。
- currentJob / status / sessionExpiredMessage は不変 (server-truth / poll 系)。
- SourceDocumentUpload / backend は変更不要。

これで APPROVED か、残る Critical/Warning があれば指摘してほしい。
