# 対応マトリクス: design-review Round 7 (最終)

Round 7 は Round 6 の新規 [Critical] に対する確認ラウンド。

## 判定結果 (Codex)

| 指摘 | 判定 |
|---|---|
| [Critical] (Round 6) attach 成功後・pay preflight 直前に canceled 化する注入点が無い | **解消** |
| 全体判定 | **APPROVED** |

Codex の確認内容:

- `terminalizeAt=[StripeInvoicePay]` で
  `create → attach 1 行 → preflight 2 直前に canceled → pay 抑止 → terminateInvoice 1 回`
  が決定論的に再現できる。`parent::stillPending()` へ委譲するため、
  `refresh()` / status 判定 / 所有権喪失ログは**本番実装が実行する**。
- M16 / M17 の赤化論法が成立する
  (preflight 呼び出しを削除すると fake の terminal 化が発火せず、外部呼び出しが走って観測が崩れる)。
- `terminalStatus=Failed/Paid` による非終端分岐の固定に穴なし。
- `duringCreateInvoice` (attach 0 行) と preflight シーム (checkpoint 直前の所有権喪失) の
  責務分離は妥当。

## 未対応・持ち越し

- なし。Round 1〜7 の [Critical] / [Warning] はすべて解消または明示的に反論・合意済み。
- 明示的に**反論して維持した**設計判断 (Codex も維持を了承済み):
  - `terminateInvoice()` に attempt 固定の idempotency key を使わない
    (Stripe 状態検査による冪等化のほうが期限が無く強い。Round 3)
  - `recordSuccessfulCharge()` の「grant → attempt 遷移」順序を変えない
    (逆順は「課金済みなのに未付与」というより悪い不整合を生む。Round 1)
  - 期待集合と目録を**同時に**書き換えた場合は宣言的 gate では検出できない
    (性質として明記済み。ソース走査による導出は複雑さが跳ねるため採らない。Round 4)
  - Manual 側には preflight collaborator を作らない
    (既存 fake のフックで配置を赤化できるため。Round 5)

## 実装者への申し送り

- 実装時に必ずやること: S6 の mutation 表 **M1〜M17 を 1 つずつ手で入れて赤化を確認し、
  必ず元へ戻す**。結果 (mutation → 失敗したテスト名) を PR 説明に記録する
  (とくに M16 / M17 は本設計で最後まで争点になった箇所)。
- `FakeAttemptOwnershipPreflight` は **verdict を差し替えない**。
  実装を変えるときもこの性質を壊さないこと (壊すとテストが実装から乖離する)。
