# 対応マトリクス: impl-review Round 1

全体判定: **APPROVED**（全ファイル APPROVE、Critical / Warning なし。Suggestion 2 件のみ）

## [Suggestion] SaveFailure.kind 網羅を testId で固定化しておくと回帰検知が強まる
- 判断: 見送る（今回のスコープでは十分）
- 根拠: 既に 3 kind (conflict / forbidden / generic) それぞれの testId とメッセージを個別ケースで
  検証済み。加えて `unreachableFailureView(_value: never)` により kind 追加時は tsc の never 不一致で
  コンパイルエラーになるため、表示漏れは型で検出される。網羅表テストの追加は YAGNI。

## [Suggestion] ManualPageTitleTest の期待文字列組み立てをヘルパー化
- 判断: 見送る（現状で可読・問題なし）
- 根拠: `title_separator` は config 固定で変更予定なし。参考実装 `SeoHeadCompositionTest` も
  リテラルで期待文字列を書いており、様式を揃える方が保守しやすい。

→ 追加対応なし。実装確定。
