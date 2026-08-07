## 新規 [Critical] (Round 6) の解消判定
解消

提示された `FakeAttemptOwnershipPreflight` は、`terminalizeAt=[StripeInvoicePay]` で pay preflight 到達時に attempt 行を `canceled` へ条件付き UPDATE し、その後 `parent::stillPending()` に委譲しています。これにより本番実装の `refresh()` / status 判定 / 所有権喪失ログを通したまま、`create → attach 1 行 → preflight 2 直前に canceled → pay 抑止 → terminateInvoice 1 回` が決定論的に再現できます。

M16 / M17 の赤化論法も成立します。preflight 呼び出しを削除すると fake の terminal 化自体が発火しないため、外部呼び出しが進み、期待する `createdInvoices` / `payCalls` / `terminated` の観測が崩れます。

`terminalStatus=Failed/Paid` による非終端分岐の固定も、`parent::stillPending()` 後に本番側の `terminateInvoiceAfterOwnershipLost()` が `Canceled` 限定で判定するため穴は見当たりません。`duringCreateInvoice` は attach 0 行、preflight fake は checkpoint 直前の ownership loss という責務分離も妥当です。

## 全体判定
APPROVED