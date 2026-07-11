# conceptual-review Round 4: Round 3 Critical への対応報告

## 対応マトリクス

### [Critical] DB 未記録 webhook を正常処理扱いすると「決済済み・付与なし」が恒久化 → 対応

fail-closed の実現方法を「silent skip（processed 化）」から「**retryable failure（例外 throw = failed）**」へ変更した。既存 `StripeWebhookProcessor` の冪等マシンは failed event を Stripe 再送時に received へ復帰させ再処理する（attempts 上限 8 回 / Stripe 再送窓 ~3 日 / 上限到達で terminal-ack + failure_reason 保存）ため、この既存機構に乗せる:

1. **purpose ガードは no-op のまま**: metadata `purpose=ticket_purchase` 以外（サブスク checkout / 他 purpose / mode≠payment）は従来どおり受理のみ。無関係 event を failed にしない。
2. **DB 行不在 → 例外 throw（retryable failure）**: crash 先着 webhook は failed で終わり付与しない。同一 attempt の再試行が Stripe idempotency key（purchase:{attempt_token}）で同一 session に収束して DB 行が記録された後、Stripe の event 再送で grantPurchased（purchase:{sessionId} 冪等）へ収束し**一度だけ**付与される。
3. **payment_status ≠ paid / amount_subtotal・currency 不一致 / org 照合不一致 → 同じく例外 throw**: 再送で直らない恒久不整合は attempts 上限で terminal-ack され failure_reason で運用調査に回る（silent processed で隠さない = 既存機構の設計どおり）。
4. **Feature テストで固定**: 指摘のシナリオそのまま —「Session 作成成功 → DB 保存前障害（行なし）→ webhook 先着 = failed（付与なし）→ 同一 attempt 再試行で DB 記録 → webhook 再処理（event 再送）で一度だけ付与」。加えて「既存冪等マシンが failed event の再試行を許容する」ことも検証（既存 WebhookIdempotencyTest の流儀）。

概念設計本文（webhook 冪等付与の節）を上記の順序（purpose ガード → retryable failure 方針 → DB 行真実源 → payment_status=paid → amount_subtotal/currency 照合 → 冪等付与 → clawback 不変）で書き直し済み。

再判定（APPROVED / CHANGES_REQUESTED）を依頼する。
