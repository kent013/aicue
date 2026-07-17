**CHANGES REQUESTED**

1. **Critical — P7 に v1 が大量残存**
   - `PlanCode` を3 case、Enterpriseを未知値扱い、`normalizeRaw` のEnterprise分岐削除としている。
   - v2の5 case方針に合わせ、TS型・テスト・契約・PHPStan節を全面更新する必要がある。

2. **Critical — P4 backfill集合が entitlement と不一致**
   - SQLはactive/trialingだけを除外するため、`past_due + PM有り`（P2では`Subscribed`）をgrandfatherしてしまう。
   - 分類表7もpast_dueを一律`¬entitled`扱いしており、P2契約と矛盾する。D22の集合同値も成立しない。

3. **Critical — P9のstale pendingが永久に再利用される**
   - `state()`は1日超を`ExpiredCheckout`とする一方、`startCheckout()`のreplay/dedupはPending＋URLだけでlive判定する。
   - そのためテスト16の「2日後に新tokenで新規Checkout」は成立しない。1日閾値を開始処理にも共有する必要がある。

4. **Critical — P9 webhook状態遷移が自己矛盾**
   - 状態図・テストは`Failed/Expired → Completed`を要求するが、実装契約は「Pending以外は触らない」。
   - 遅延成功を受理する遷移条件へ統一が必要。

5. **Warning — D28のDTO契約が不統一**
   - P1では`PricingPlanDto/Shape.monthlyTicketGrant`を削除。
   - P8bでは「DTOから外さない」とし、各shapeにも残っている。どちらかへ統一が必要。

6. **Warning — フェーズ記述の残骸**
   - P4非スコープに「D28 = P5」とあるが、本文ではD28はP1所管。
   - P8aの未決事項にも、既定値・通知併存など「解決済み」とした旧記述が残っている。

以上を直すまで APPROVED にはできません。