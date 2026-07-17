**CHANGES REQUESTED**

1. **Critical — P9 に T1004 が実装されていない**
   - P8a は P9 へ `funding_choice` / `pm_reuse_dispatched_at`、`ReuseSubscriptionPaymentMethodJob`、PM流用Service/Gateway、`consent_version='v2'` を明示移譲している。
   - しかしP9の変更一覧・migration・DoD・テストには一式がなく、非スコープでは逆に除外されている。P9本文へ実装契約を追加してください。

2. **Critical — `BillingCheckoutSession` writer時期が矛盾**
   - P8aで`SetupPaymentMethod`行を書き、「最初のwriterはP8a」と確定。
   - P9には「P9が初めてのwriter」「P2では行0件」が複数残る。P9前提・DBコメント・波及記述をP8a writer済みに更新してください。

3. **Warning — stale境界に旧`<=`が残存**
   - P9変更箇所表の`expireStaleCheckouts()`が依然 `created_at <= threshold`。
   - C-1の確定契約はlive `>=` / stale `<`。変更箇所表も `<` に統一が必要です。

以上のため、まだ APPROVED ではありません。