# 対応マトリクス: design-review Round 2

## B [Warning] CHECK 制約の削除構文が driver で違う
- 判断: 対応する
- 根拠: 指摘のとおり。`DROP CONSTRAINT IF EXISTS` は PostgreSQL の構文で、
  MySQL は `DROP CHECK` が要る。`up()` の guard は 2 driver を許しているのに
  `down()` が片方の構文しか書いていないのは不整合。
- 対応内容: `down()` を driver 別に分けた
  (`pgsql` → `DROP CONSTRAINT IF EXISTS` / `mysql` → `DROP CHECK` / `sqlite` は何もしない)。
  `up()` の guard 集合 (`pgsql` / `mysql`) はそのまま維持する
  (既存 `ticket_auto_recharges` の CHECK と同じ前提に乗せる)。

## B [Suggestion] CHECK のテストは両方向を固定する
- 判断: 対応する
- 対応内容: テスト計画を 2 本にした。
  `received` + `recovery_reason` 非 NULL の UPDATE が失敗すること、
  `recovery_pending` + `recovery_reason` NULL の UPDATE が失敗すること。

## C [Suggestion] 「渡せないようにする」は型では実現されていない
- 判断: 対応する (表現の訂正)
- 対応内容: テスト計画の表現を「`finalize()` へ `RecoveryPending` を**渡さない**ことを固定する
  (型では閉じていない)」に直した。専用 enum は切らない。

## D [Warning] 未対応 type が試行上限到達時だけ `recovery_pending` になる判定漏れ
- 判断: 対応する
- 根拠: 指摘のとおりの穴だった。`$event?->replaySafety()` で null を素通しすると、
  その後の `attempts >= MAX` に引っかかって `AttemptsExhausted` になり、
  「未対応 type は通常経路と同じ決着」という契約が上限到達時だけ破れる。
- 対応内容: `recoveryReasonFor()` の先頭で `$event === null` を明示的に `null` 返却にし、
  なぜ上限判定より前に置くのかをコメントに書いた。
  テスト観点 6b (`customer.updated` かつ `attempts = MAX_PROCESSING_ATTEMPTS` でも
  `processed` になる) を追加した。

## Index 評価 (指摘なし)
- Codex の評価: `(status, updated_at)` で十分。`updated_at` が範囲条件なので
  3 列目の `id` は並び替えに使えない。バッチ上限や keyset pagination は先回りして作らない。
- 判断: 現行のままとする (追加変更なし)。
