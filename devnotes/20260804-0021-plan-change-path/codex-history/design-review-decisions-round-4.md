# 対応マトリクス: design-review Round 4

判定: C/D/E は APPROVE、A/B が REQUEST_CHANGES (Warning 2 件 + Suggestion 2 件)。全件対応した。

## [Warning] A の gateway が B で新設する `PlanChangeFailedException` に依存し、A 単体で実装できない

- 判断: 対応する
- 対応内容: 実装順に **段 0「例外 2 種 (`PlanChangeFailedException` / `StalePlanChangeException`)」**
  を追加し、A と B の共通前提と明記。施策一覧・施策 A の変更箇所にも
  `PlanChangeFailedException`(新) を「A の前提。定義は施策 B に記載」として載せた。

## [Warning] 「gateway が throw するのは `PlanChangeFailedException` だけ」は `Assert` の `InvalidArgumentException` と矛盾

- 判断: 対応する
- 対応内容: 契約を **「想定される外部障害 (Stripe API) と remote shape 異常は
  `PlanChangeFailedException` に統一」**へ限定し、**前提違反・実装不備は fail-fast で
  そのまま外へ出す** (`Assert` の `InvalidArgumentException` / `TypeError`。Service が段 1 で
  契約の存在を保証しているため、到達したら実装不備) と interface docblock / 注記に明記。

## [Suggestion] `ChangePlanRequest` のコメントが「表示用 currentPlanCode」のまま

- 判断: 対応する
- 対応内容: 送信元が `planChangeExpectedPlanCode` (= `organizations.plan_code` そのもの) で
  あることを docblock に明記。

## [Suggestion] 実装順の「例外 3 種」は 2 種 / null 一致テストの「段 3」は「段 5」

- 判断: 対応する
- 対応内容: 実装順の記述を段 0 の「例外 2 種」に整理し、B のテスト計画の段番号を段 5 に修正。
