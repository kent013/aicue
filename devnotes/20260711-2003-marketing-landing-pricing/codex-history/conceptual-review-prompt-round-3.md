# conceptual-review Round 3: Round 2 指摘への対応報告

2 件の Critical と全 Warning に対応した。再判定を依頼する。

## 対応マトリクス

### [Critical] 同 count 再購入で Stripe 24h expire 済み pending URL を永続 replay → 対応
- `ticket_checkout_sessions` に **`expires_at`**（Stripe session 作成時の expires_at を pin）を追加。
- **live pending の定義を `status=pending AND expires_at > now()` に固定**し、dedup / replay（attempt_token 経路含む）はこの条件のみを対象にする。期限切れ pending は dedup 前に `expired` へ遷移させてから新規作成に進む（Stripe 照会不要 = pin 値で決定的、専用 cron 不要）。
- テスト計画に「期限切れ pending の URL を replay しない（expired 化され新 session が作られる）」の Feature テストを明記。

### [Critical] checkout.session.completed は非同期決済で未決済でも発火し得る → 対応
- v1 の Checkout 作成を **`payment_method_types=['card']`（即時決済のみ）に固定**。
- webhook は **`payment_status === 'paid'` を必須照合**。paid 以外は付与せず completed 化もしない（構造化ログ + report で可観測化）。
- 非同期決済の許可は将来スコープとし、その際の拡張点（`checkout.session.async_payment_succeeded` を `HandledStripeWebhookEvent` へ追加）を設計に記載。

### [Warning] 「FormRequest 422（Inertia 標準）」の記述が不正確 → 対応
- 「バリデーション失敗 = Laravel 標準の back redirect + session errors（Inertia が props で受ける）。XHR 向け独自 422 JSON は作らない」に修正。

### [Warning] Stripe 作成成功後・DB 保存前 crash で追跡不能 URL → 対応
- 期待効果の主張を「追跡済み Checkout の二重作成・二重付与の防止（4 層）」に限定。
- 復旧特性を明記: Stripe 作成 idempotency key = `purchase:{attempt_token}` により、同一 attempt の再試行は Stripe から**同一 session** が返り、その時点で DB 行が記録され追跡に収束（窓 24h）。DB 行が引けない completed webhook は report + 付与しない（fail-closed）ため、未追跡 session が黙って付与されることはなく運用調査に回る。

### [Warning] Cache::lock 取得失敗時の動作未定義 → 対応
- LockTimeoutException は fail-closed で「直前の購入手続きが進行中です」`back()->with('error')` に固定。ロックなし実行へのフォールバック禁止をテストで固定。

### [Suggestion] 同 count 期限切れ判定はスコープ内必須 → 対応（上記 Critical 対応に内包）
### [Suggestion] payload の amount_subtotal / currency / payment_status は nullable/untrusted → 対応（既存 `stringAt()` 流儀の型ガードで絞り込み、欠落は fail-closed skip + report。PHPStan lv10 で mixed を漏らさない、と明記）

## 改訂後設計の該当箇所（抜粋・全文反映済み）

冪等マシン:
- テーブル: organization_id / initiated_by_user_id / ticket_count / unit_amount(pin) / currency(pin) / stripe_session_id UNIQUE / attempt_token + UNIQUE(org, attempt_token) / status(pending|completed|expired) / checkout_url / **expires_at(pin)**。
- 収束順序: (1) 同一 attempt_token 行 = 同 count live pending → replay / completed → 受付済み / それ以外 → 再読み込みエラー。(2) (org, user) の live pending dedup = 同 count → replay、別 count → Stripe expire 成功時のみ expired 化して新規（expire 結果 complete は「処理中」エラー、失敗は新規作成しない）。(3) INSERT unique 違反 → re-read で replay / エラー収束（500 にしない）。lock timeout → fail-closed エラー。
- webhook 照合順序: mode/purpose ガード → DB 行真実源（行不在 fail-closed）→ **payment_status=paid 必須** → amount_subtotal × currency 照合（fail-closed）→ grantPurchased(purchase:{sessionId} 冪等) → completed 化。

再判定（APPROVED / CHANGES_REQUESTED）と、残る指摘があれば提示してほしい。
