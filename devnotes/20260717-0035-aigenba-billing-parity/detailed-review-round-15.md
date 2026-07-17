**CHANGES REQUESTED**

1. **Critical — T1004 が通常の Stripe payload で発火しない**
   - `subscriptionIdFrom()` が `data.object.subscription` の **展開済み array** しか扱っていません。
   - `checkout.session.completed` の `subscription` は通常 **string ID**（expandable field）です。この場合 Job が dispatch されません。
   - `string` と `array{id}` の両方を受理し、テスト 50 に string payload を必須追加してください。

このため、まだ APPROVED ではありません。