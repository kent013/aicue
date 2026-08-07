## Round 5 指摘の解消判定

### S4 [Critical] Stripe preflight の配置を赤化できるテストシームが無い → 解消
`AttemptOwnershipPreflight` を注入可能 collaborator に切り出し、`FakeAttemptOwnershipPreflight` を `app()->instance()` で差し込む設計なら、M16 / M17 は決定論的に赤化できます。

`denyKinds=[StripeInvoiceCreate]` で create 直前 preflight を削除すると invoice が作られるため赤化します。`denyKinds=[StripeInvoicePay]` では create / attach は通り、pay 直前 preflight を削除すると pay が走るため赤化します。他方の preflight が残っていても検出できます。

### S4 [Warning] 新設メソッドの列挙 → 解消
`terminateUnattachedInvoice()` / `terminateInvoiceBestEffort()` / `terminateInvoiceAfterOwnershipLost()` と constructor 変更が変更箇所に列挙されています。

### S7 [Warning] Stripe の「配置を保証する」主張 → 解消
Architecture gate と Feature test の分担が明確になり、Billing は fake preflight で配置を赤化する、と具体化されています。

### S6 [Suggestion] 期待集合の重複検査 → 解消
`jobDedupRequiredExternalCalls()` 側の重複検査が追加されています。

## 新規 [Critical] (対応が持ち込んだもの。無ければ「なし」)

[Critical] `preflight 2: attempt が canceled のとき既作成 invoice を終端する` suppression テストに、決定論的な注入点がありません。

現設計の `duringCreateInvoice` は attach 前の競合、つまり `attach 0 行` しか再現できません。attach 成功後、pay preflight 直前に attempt を `canceled` へ変えるシームが無いため、`terminateInvoiceAfterOwnershipLost()` の canceled 分岐を Feature test で固定できません。

不足しているのは「attach 済み invoice を持つ attempt が、preflight 2 直前に canceled へ変わる」経路の再現手段です。最小対応は、test fake preflight 側で `StripeInvoicePay` チェック直前に DB status を `canceled` へ変えてから本物の `stillPending()` 相当を通す、などです。本番コードへ closure を足す必要はありません。

## 全体判定

CHANGES_REQUESTED