# 対応マトリクス: conceptual-review Round 4

## [Critical] remote price 照合の不変条件を `CashierStripeGateway` 経由で検証するテストが無い (禁止事項 1)

- 判断: **対応する** (指摘が正しい。Service の gateway mock では gateway 内部の制御フローを
  証明できず、`buildSwapPayload()` の純関数テストも制御フローは対象外)
- 対応内容: 検証方針に **層 0 (gateway 自体のテスト)** を追加した。
  - `Cashier::stripe()` の取得を protected seam (`stripe(): StripeClient`) に切り出し、
    partial mock で `Mockery::mock(StripeClient::class)` を差し込む
    (`StripeClient::__get()` は public なので service プロパティを stub できる。
    subscription object は `\Stripe\Subscription::constructFrom([...])` で組み立てるため
    実ネットワークに出ない)。
  - 固定する事項: (a) remote base item price が対象と同一 → `subscriptions->update()` は 0 回 /
    戻り値 `AlreadyOnTargetPrice` (b) 異なる → `update()` が 1 回・期待 payload と
    idempotency key で呼ばれ戻り値 `Applied` (c) `retrieve` と `update` が同一 subscription id。
  - 実装方針 1 にも seam の追加を明記した。

## [Suggestion] 使命 / 実現可能性 / 期待効果 / リスク / スコープ / 型安全性

- 判断: 反映済み (変更なし)。
