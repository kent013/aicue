# 対応マトリクス: design-review Round 1

## A [Suggestion] `SafeToReplay` の behavioral test は別 `event_id` で同じ Stripe object を処理する形にせよ
- 判断: 対応する
- 根拠: 同一 `event_id` だと webhook 行の冪等性しか見ておらず、分類の根拠である下位冪等キー
  (`purchase:` / `monthly:` / `recharge:` / `refund:`) を検証したことにならない。指摘のとおり。
- 対応内容: 施策 A のテストを「**別 `event_id`・同一 session id / invoice id** で 2 回処理しても
  台帳の付与行が 1 行だけ」に書き換えた。

## B [Warning] 不変条件がコメントとテストだけに依存している
- 判断: 対応する
- 根拠: 本リポジトリには driver guard 付きの CHECK 制約の前例がある
  (`2026_07_17_000600_create_ticket_auto_recharges_table.php`)。同じ作法で機械固定できる。
- 対応内容: migration に driver guard 付き CHECK 制約を足した。
  `(recovery_reason IS NULL AND status <> 'recovery_pending') OR
   (recovery_reason IS NOT NULL AND status = 'recovery_pending')`。
  `down()` では制約を先に落としてから列を落とす。

## B [Warning] `recoverStale()` の主クエリに index が無い
- 判断: 対応する
- 根拠: `stripe_webhook_events` は保持期限 (7 年) まで残るので単調に増える。
  5 分ごとの全表走査は避けるべき。
- 対応内容: 同じ migration に `index(['status', 'updated_at'])` を追加した。
  `id` は 3 列目に入れない — 並び替え (`orderBy('id')`) は取得件数を安定させるためのもので、
  絞り込みは `status` + `updated_at` で十分に効くから (列を増やしても走査量は変わらない)。
  監視で使う `recovery_pending` 件数も同じ index の先頭列で効く。

## C [Warning] `finalize()` の戻り値を `handle()` が無視する点をテストで固定せよ
- 判断: 対応する
- 対応内容: 施策 C のテストに「別世代へ進んでいたとき、HTTP 経路は**例外を投げずに完走し**、
  行の状態も `attempts` も上書きされない」を明示した。

## C [Suggestion] `finalize()` に `RecoveryPending` を誤って渡せないようにする
- 判断: 対応する (docblock + 呼び出し側テスト)
- 対応内容: docblock で受け付ける 3 値を明記し、テストで最終状態を固定する。
  型で閉じる (専用 enum を切る) ことはしない — 値が 3 つの private メソッドのために
  型を 1 つ増やす利益が無い。

## D [Warning] 未対応 type を `recovery_pending` に置くのは通常経路とズレる (運用ノイズ)
- 判断: **対応する (案 1 を採る)**
- 根拠: 指摘のとおり。通常経路では `process()` の `null` arm で no-op → `processed` になるのに、
  回収だけ静止状態へ置くのは同じ事実に 2 通りの決着を与えることになる。
  さらに `null` arm は**構造的に no-op** である — 副作用を持たせるには
  `HandledStripeWebhookEvent` に case を足すしかなく、足せば `replaySafety()` の網羅 match を
  必ず通る。したがって再実行の安全性は型で保証されており、deny-by-default の対象にする理由が無い。
- 対応内容: 未対応 type は**通常経路と同じく再実行して `processed` にする**。
  `WebhookRecoveryReason` から `UnhandledEventType` を削り、理由は
  `OrderSensitive` / `AttemptsExhausted` の 2 値にした。
  概念設計レビュー Round 2 で deny-by-default にした判断はここで撤回する
  (当時の懸念「`from()` で cron が止まる」は `tryFrom()` で解決済みで、
  「未知の副作用が走る」は `null` arm の構造で成立しないため)。

## D [Warning] 生存中 worker を誤検知したときの残り方
- 判断: 対応する (保証しないものとして明記 + テストで挙動を固定)
- 根拠: 外部 API 遅延で HTTP 処理が 15 分を超える可能性は理屈の上では残る。
- 対応内容: `docs/architecture.md` の「保証しないもの」に
  「閾値超過中の生存ワーカーを誤検知した場合、業務側の副作用は起きるが webhook 行は
  `recovery_pending` に残る (順序に依存する種類のとき)」を追加した。
  `processing_started_at` 相当の列は**足さない** — `updated_at` は claim の瞬間に更新されるので
  意味は同じで、列を増やしても誤検知の閾値問題は変わらないから。

## D [Warning] `logContext()` の戻り値 PHPDoc がコード例に無い
- 判断: 対応する
- 対応内容: `@return array{event_id: string, type: string, attempts: int, outcome: string,
  reason: string|null}` を明記した。

## D [Suggestion] `MovedToRecoveryPending` で payload を持ち回らない
- 判断: 対応する
- 対応内容: `movedToRecoveryPending()` は payload を受け取らず空配列を入れる。
  DTO の docblock に「`payload` に中身が入るのは `ClaimedForReplay` のときだけ
  (再実行しない場合は保持しない)」と書いた。

## E [Warning] 観測点が `recovery_pending` 件数に寄りすぎている
- 判断: 対応する
- 対応内容: 観測点を 3 つに増やした。
  (1) `status='received'` かつ `updated_at <= now - 閾値` の件数 (scheduler 停止の検知)、
  (2) 実行出力の `retry-scheduled` 件数 (再実行が失敗し続けている検知)、
  (3) `recovery_pending` 件数 (自動再実行しない行の滞留)。
  `docs/architecture.md` と `routes/console.php` のコメント両方に書く。

## E [Suggestion] cache driver 不備時の挙動を新しい保証として書かない
- 判断: 対応する
- 対応内容: 「既存 cron と同じ前提に乗るだけで、新しい前提を作らない」と書いてある記述を維持し、
  それ以上の保証を書かない。

## F [Suggestion] docblock を書きすぎない (二重管理)
- 判断: 対応する
- 対応内容: `MAX_PROCESSING_ATTEMPTS` の docblock を短くし、
  「なぜ回収が要るか」と「正本は `docs/architecture.md`」だけ残した。

## 追加テスト (すべて対応する)
- `recovery_reason` と `status` の双方向不変条件 (CHECK 制約 + Feature テスト)
- migration の rollback (`down()` で CHECK と index と列が落ちる)
- 未対応 type の滞留回収が `processed` になること (案 1 の仕様固定)
- `received` 滞留行が保持期限 purge で消えないこと
- 別 `event_id` で同じ Stripe object を処理しても付与が 1 回であること
