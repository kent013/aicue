# 対応マトリクス: design-review Round 14（CHANGES REQUESTED / Critical 2・Warning 1）

3 点とも P9 周辺の整合。全て対応。

## [Critical] P9 に T1004 が実装されていない（P8a が P9 へ移譲しているのに P9 は非スコープで除外）
- 判断: 対応する / 対応: **P9 を再生成**し、P8a から移譲された **T1004（PM 流用）一式を実装契約として本文へ追加**:
  `ReuseSubscriptionPaymentMethodJob` / `applyReusedPaymentMethod` / `resolveSubscriptionPaymentMethod` /
  `hasRecentAutoRechargeFundedSignup` / `billing_checkout_sessions.{funding_choice, pm_reuse_dispatched_at}`（**additive 列**）/
  `settingsFor.setupPending` の条件 / 着地 flash 分岐 / **`consent_version` を `'v2'` へ改定**（v1 同意は
  `reconsentRequiredFor` で自動失効 = fail-closed）。**非スコープからは削除**。
  適格性は**先行 fail-closed**（同意なし・失効・停止状態では customer default PM にもローカル snapshot にも触れない）。

## [Critical] `BillingCheckoutSession` の writer 時期が矛盾（P8a が先行して書くのに P9 が「自分が最初の writer」）
- 判断: 対応する / 対応: P9 の前提を「**最初の writer は P8a**（`intent=setup_payment_method`）。**P9 が新規に書くのは
  `intent=subscription_start` の行**であり、冪等状態機械・dedup・feedback・sweeper はすべて **P8a の setup 行と同居する前提**」へ。
  **P2 のリスク行**（「P2 では行 0 件（writer 不在）」）も、writer 時期（P8a → P9）と sweeper 所管（P9）の事実へ更新。

## [Warning] stale 境界に旧 `<=` が残存（P9 変更箇所表）
- 判断: 対応する（**私の直し漏れ**。1 箇所直して変更箇所表を見落としていた）
- 対応: `expireStaleCheckouts()` を **`created_at < staleThresholdAt()`** へ統一し、変更箇所表にも
  「**境界は排他: live 判定 `>=` の補集合**」を明記。機械確認で旧 `<=` は 0 件。
