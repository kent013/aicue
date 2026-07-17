**CHANGES REQUESTED**

1. **Critical — P2/P4 の同値性説明が誤り**
   - `active|trialing + trial終了 + PM無し` は、現行 gate では status により許可される一方、P2 `deriveEntitlement()` 導入時点で遮断される。
   - よって「P2の唯一の結論変更はpast_due」「P4分類2が反転の目的」は成立しない。これはP2で既に起きる変更としてDoD・分類表・テストを統一する必要がある。

2. **Critical — stale境界が重複**
   - `isLivePending()` は `created_at >= threshold`、sweeperは `created_at <= threshold`。
   - 境界時刻ちょうどの行がliveでありながらExpiredへ更新される。sweeperを `< threshold` にするなど、境界を排他的に統一してください。

3. **Warning — v2原則に反する未決事項が残存**
   - P6のpaid grant契機、subscription行marker。
   - P8aのsignup-funding、`ticket_purchases`、Gateway粒度。
   - 「逸脱はAGENTS.md由来のみ」「実装着手可能」とするなら、これらは決定済み本文へ昇格するか、実装前ブロッカーとして明示する必要があります。

このため、まだ APPROVED ではありません。