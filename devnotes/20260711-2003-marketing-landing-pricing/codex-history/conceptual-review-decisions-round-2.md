# 対応マトリクス: conceptual-review Round 2

## [Critical] 同 count 再購入時に Stripe 側 24h expire 済みの pending URL を永続 replay して購入不能
- 判断: 対応する
- 根拠: 指摘どおり「別 count 購入時の回収」だけでは最頻パターン（同数再購入）を扱えない。
- 対応内容: `ticket_checkout_sessions` に `expires_at` を追加し、Stripe session 作成時の `expires_at` を pin する。checkout の dedup / replay は必ず `status=pending AND expires_at > now()` の「live pending」のみを対象にし、期限切れ pending は dedup 前に `expired` へ遷移させてから新規作成に進む（Stripe 照会不要 = pin 値で決定的）。attempt_token replay 経路も同条件（期限切れは非 replayable → 再読み込み誘導）。Feature テスト「期限切れ pending の URL を replay しない（新 session が作られる）」を追加。

## [Critical] checkout.session.completed は非同期決済手段だと未決済でも発火し得る
- 判断: 対応する
- 対応内容: (1) v1 は Checkout 作成を `payment_method_types=['card']`（即時決済）に固定。(2) webhook は `payment_status === 'paid'` を必須照合し、paid 以外は付与せず行も completed 化しない（構造化ログで可観測化。card 固定下では発生しない想定の防御線）。`checkout.session.async_payment_succeeded` の追加は非同期決済を許可する将来スコープ（HandledStripeWebhookEvent への case 追加箇所として設計に記載）。

## [Warning] 「FormRequest 422（Inertia 標準）」の記述が不正確
- 判断: 対応する
- 対応内容: 「バリデーション失敗 = Laravel 標準の back redirect + session errors（Inertia が props で受ける）」に修正。XHR 向け独自 422 JSON は作らない。

## [Warning] Stripe 作成成功後・DB 保存前 crash で追跡不能 URL が残る
- 判断: 対応する（主張の限定 + 復旧特性の明記）
- 対応内容: (1) 期待効果の「二重課金ゼロ」を「追跡済み Checkout の二重作成・二重付与の防止」に限定。(2) 復旧特性を明記: Stripe 作成の idempotency key は `purchase:{attempt_token}` なので、同一 attempt の再送は Stripe から**同一 session** が返り、その時点で DB 行が記録される（= crash 後の再試行が自然に追跡へ収束。窓は Stripe idempotency key の 24h）。加えて webhook 側は DB 行が引けない completed を report + 付与しない（fail-closed）ので、未追跡 session が決済完了しても黙って付与されることはない（運用調査に回る）。

## [Warning] Cache::lock 取得失敗時の動作が未定義
- 判断: 対応する
- 対応内容: `block(N)` の LockTimeoutException は fail-closed で「直前の購入手続きが進行中です。数秒お待ちください」の `back()->with('error')` に固定（aigenba 同様）。ロックなし実行へのフォールバックはしない。テストで固定。

## [Suggestion] 同 count 期限切れ判定は局所回収の成立条件（スコープ外にできない）
- 判断: 対応する（上記 Critical 対応に内包 = スコープ内）

## [Suggestion] Stripe payload の amount_subtotal / currency / payment_status は nullable/untrusted
- 判断: 対応する
- 対応内容: webhook handler は既存 `stringAt()` 流儀の型ガード（is_int / is_string で絞り込み、欠落は fail-closed skip + report）で扱う。PHPStan level 10 で mixed を漏らさない。
