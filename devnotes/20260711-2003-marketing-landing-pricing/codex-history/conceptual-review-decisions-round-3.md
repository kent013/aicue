# 対応マトリクス: conceptual-review Round 3

## [Critical] DB 未記録 webhook を「skip + report = processed」扱いにすると決済済み・付与なしが恒久化する
- 判断: 対応する
- 根拠: 指摘どおり。Stripe session 作成後・DB 保存前の crash で webhook が先着すると、processed 化された event は再処理されず、同一 attempt 再試行で DB 行ができても付与に到達しない。
- 対応内容: fail-closed の実現方法を「silent skip」から「**retryable failure（例外 throw）**」に変更する。既存 `StripeWebhookProcessor` の冪等マシンは failed → Stripe 再送で received 復帰 → 再処理（attempts 上限 8 回 / Stripe 再送窓 ~3 日、上限到達で terminal-ack + failure_reason 保存）を既に備えているため、この機構に乗せる:
  - **DB 行不在**（purpose=ticket_purchase なのに `ticket_checkout_sessions` が引けない）→ 例外 throw = failed。同一 attempt の再試行（Stripe idempotency key で同一 session に収束）で DB 行が記録されれば、Stripe の event 再送時に grantPurchased へ収束する。
  - **payment_status ≠ paid / amount_subtotal・currency 不一致 / org 照合不一致** → 同じく例外 throw = failed。再送で直らない恒久不整合は attempts 上限で terminal-ack され failure_reason で運用調査に回る（既存機構の設計どおり。silent processed で隠さない）。
  - purpose ガード（metadata `purpose=ticket_purchase` 以外）はこれまでどおり no-op（サブスク checkout / 他 purpose を failed にしない）。
  - Feature テストで固定: 「session 作成成功 → DB 保存前障害（行なし）→ webhook 先着 = failed（付与なし）→ DB 行記録後の event 再送で一度だけ付与」+ 「既存冪等マシンが failed event の再試行を許容する」。
