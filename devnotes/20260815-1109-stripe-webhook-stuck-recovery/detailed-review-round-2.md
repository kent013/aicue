## 各施策の判定

### A. 再実行安全性の分類を型で持つ: APPROVE

別 `event_id`・同一 Stripe object ID で下位の冪等キーを検証する形になり、Round 1 の指摘は解消しています。

### B. 回収待ちの状態と理由を足す: REQUEST_CHANGES

[Warning] MySQL の制約削除構文を PostgreSQL と共通化できる保証がありません。

`down()` の次の SQL は PostgreSQL では妥当ですが、MySQL のバージョンによっては `DROP CONSTRAINT IF EXISTS` が使えず、`DROP CHECK` が必要です。

```php
ALTER TABLE ... DROP CONSTRAINT IF EXISTS ...
```

修正案: driver ごとに SQL を分け、既存の `ticket_auto_recharges` migration と完全に同じ構文・バージョン前提へ合わせてください。

```php
$driver = DB::connection()->getDriverName();

if ($driver === 'pgsql') {
    DB::statement(
        'ALTER TABLE stripe_webhook_events '
        .'DROP CONSTRAINT IF EXISTS stripe_webhook_events_recovery_reason_state_check'
    );
}

if ($driver === 'mysql') {
    DB::statement(
        'ALTER TABLE stripe_webhook_events '
        .'DROP CHECK stripe_webhook_events_recovery_reason_state_check'
    );
}
```

PostgreSQL だけをサポートする設計なら、guard から `mysql` を外す方が明確です。

CHECK の論理式と書き込み順序自体には問題ありません。`claimStale()` の同一 `save()` と `finalize()` の単一 UPDATE は、制約の中間違反を発生させません。

[Suggestion] CHECK 制約テストでは、片方向だけでなく次の両方を DB レベルで拒否できることを固定すると堅牢です。

- `received + recovery_reason 非NULL`
- `recovery_pending + recovery_reason NULL`

### C. 終局書き込みを世代付き条件付き UPDATE にする: APPROVE

CAS 条件、旧世代の書き戻し防止、HTTP 経路で `finalize() === false` を例外にしない契約は整合しています。

[Suggestion] 「`RecoveryPending` を渡せないようにする」は型では実現されておらず、今回はコメントと呼び出し元テストによる制約です。表現は「渡さないことを固定する」に直すと正確です。より強く守るなら `finalize()` 冒頭で許可する3状態を assert する方法がありますが、現時点で専用 enum までは不要です。

### D. 滞留回収を足す: REQUEST_CHANGES

[Warning] 未対応 type の扱いが、試行上限到達時だけ設計方針と矛盾します。

現在の判定順では、未対応 type でも `attempts >= MAX_PROCESSING_ATTEMPTS` なら `AttemptsExhausted` になります。

```php
$event = HandledStripeWebhookEvent::tryFrom($record->type);

if ($event?->replaySafety() === WebhookReplaySafety::OrderSensitive) {
    return WebhookRecoveryReason::OrderSensitive;
}

if ($record->attempts >= self::MAX_PROCESSING_ATTEMPTS) {
    return WebhookRecoveryReason::AttemptsExhausted;
}
```

これは「未対応 type は通常経路と同じく必ず no-op で `processed`」という説明と一致しません。テストも既定の `attempts=0` だけでは見落とします。

修正案: 未対応 type を最初に明示的に通過させてください。

```php
$event = HandledStripeWebhookEvent::tryFrom($record->type);

if ($event === null) {
    return null;
}

if ($event->replaySafety() === WebhookReplaySafety::OrderSensitive) {
    return WebhookRecoveryReason::OrderSensitive;
}

if ($record->attempts >= self::MAX_PROCESSING_ATTEMPTS) {
    return WebhookRecoveryReason::AttemptsExhausted;
}

return null;
```

加えて、`customer.updated` かつ `attempts = MAX_PROCESSING_ATTEMPTS` でも `processed` になるテストが必要です。

これを直せば、Round 1 の未対応 type に関する指摘は解消します。生存 worker の誤検知についても、保証範囲と採用しない代替案が明確になっています。

### E. cron を配線する: APPROVE

`received` 滞留、`retry-scheduled`、`recovery_pending` の3観測点が定義され、Round 1 の観測性に関する指摘は解消しています。

### F. コメント・ドキュメント更新: APPROVE

正本を `docs/architecture.md` とし、コード側を要約に限定する方針は妥当です。

## Index 評価

`(status, updated_at)` で十分です。

主目的の次の絞り込みには適合します。

```sql
WHERE status = 'received'
  AND updated_at <= ?
```

`ORDER BY id` のために3列目へ `id` を加えても、`updated_at` が範囲条件なので一般にはその後ろの `id` を並び替えに有効利用できません。したがって、今回 `id` を含めない判断は妥当です。

将来、滞留件数が大量になった場合は index 追加より、候補取得のバッチ上限や keyset pagination を別途検討する問題です。現段階で先回りして足す必要はありません。

## 全体判定: CHANGES_REQUESTED

Round 1 の主要3件のうち、E は解消、B は実質解消していますが migration の driver 別 DDL を修正する必要があります。D は未対応 type が上限到達時だけ `recovery_pending` になる判定漏れが残っています。

以下の2点を直せば、詳細設計として承認可能です。

1. CHECK 制約の `down()` を driver 別構文にする。
2. `recoveryReasonFor()` で未対応 type を attempts 判定より前に `null` 返却し、上限到達済み未対応 type のテストを追加する。