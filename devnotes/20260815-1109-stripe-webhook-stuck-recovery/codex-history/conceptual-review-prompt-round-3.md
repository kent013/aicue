# Round 3: 概念設計の修正版 (Round 2 の指摘反映)

## 対応マトリクス (要約)

| 指摘 | 判断 | 対応 |
|------|------|------|
| [Critical] `report()` / `Log` をトランザクション内で呼ぶ | 対応する | `claimStale()` は状態遷移だけを確定し、型付き結果 `WebhookStaleClaimOutcome` を返す。通知は commit 後に `recoverStale()` が出す |
| [Critical] 未知 / 未分類の `type` の扱いが無い | 対応する | 境界変換を `tryFrom()` にし、未知値は deny-by-default で自動再実行しない (`recovery_pending` + 理由 `UnknownEventType`)。行単位の異常で cron を止めない |
| [Warning] 回収を止めた理由が復元できない | 対応する | `WebhookRecoveryReason` (3 値) + `recovery_reason` 列 (nullable string) を追加。migration 1 本 |
| [Warning] `recoverStale(): int` の意味が不明確 | 対応する | `WebhookRecoveryResultDto` (`replayed` / `rested` / `skipped`) を返す。既存 `BillingRetentionPurgeResultDto` と同じ作法 |
| [Warning] 条件付き UPDATE は単一 SQL にする | 対応する | `WHERE event_id = ? AND status = 'received' AND attempts = 受理時の値` の単一 UPDATE。更新件数 1 のときだけ終局化成功 |
| [Warning] 並行 `process()` の副作用は CAS では止まらない | 対応する | 「CAS が守るのは行の世代だけ / 一回性は台帳の UNIQUE が担う / 真の同時実行はテストしない」を明記。種類ごとの再実行冪等テストを追加 |
| [Warning] テストが実装方針に無い | 対応する | 実装方針にテストファイル 2 本と固定する 7 観点を明記 |
| [Suggestion] `report()` は配送を保証しない | 対応する | 「常設の観測点は件数のほう」と書き直した |
| [Warning] 型安全性 | 対応する | 境界変換 `tryFrom()` / `claimStale()` の enum 返却 / 回収理由の enum 化 / payload 契約の維持を明記 |

## 追加で判断したこと

- 主キー同一性クエリの目録 (`DirectFetchInventory`) について: 行の取り直しと条件付き UPDATE の
  handle を **`event_id` (UNIQUE 列)** にする。本クラスは元々 `claim()` が
  `where('event_id', …)` で行を引いており、識別子を 2 本立てにしない方が読みやすい。
  実装時に `ModelDirectFetchInvariantTest` を実行し、検出されたら目録へ登録する。

## 確認してほしい点

- (a) Round 2 の Critical 2 件は解消しているか
- (b) `recovery_reason` 列の追加 (migration 1 本) は妥当な最小か。過大なら代案を示してほしい
- (c) 固定するテスト観点 7 つに抜けはあるか
- (d) 概念設計として承認できるか。できないなら残る Critical / Warning を挙げてほしい

---

## 修正後の概念設計 (全文)
# 概念設計: stripe-webhook-stuck-recovery

## 背景・課題

### 現状の webhook 冪等マシン (aicue)

`StripeWebhookProcessor::handle()` は次の順で動く。

1. `claim()` — `DB::transaction` + `lockForUpdate` で `stripe_webhook_events` 行を取得し、
   状態を `received` にして返す (新規なら INSERT、`failed` なら `attempts+1` して `received` へ戻す)
2. **トランザクションの外**で `process()` を呼ぶ (本処理)
3. 成功なら `processed`、例外なら `failed` + `failure_reason` を記録して再 throw

状態は `received` / `processed` / `failed` の 3 値しかない。

### 穴: 本処理中にプロセスが落ちた行が二度と処理されない

手順 2 の最中に PHP プロセスが落ちる (OOM kill / デプロイ時の worker 停止 / fatal error) と、
行は `received` のまま残る。このとき:

- HTTP 応答が返らないので **Stripe は再送する** (ここまでは正常)
- しかし再送を受けた `claim()` は `$existing->status !== Failed` で **`null` を返す**
  (`received` = 「他プロセスが処理中」とみなす設計)
- `handle()` は何もせず return し、Cashier が **200 を返す**
- Stripe は「配信成功」と判断して**再送を打ち切る**

結果、そのイベントは**永久に未処理**のまま残る。しかも 200 を返しているので Stripe 側にも
失敗として残らず、`stripe_webhook_events.failure_reason` も NULL なので運用調査の手掛かりも無い。
**完全に無音で失われる**。

失われるものは金銭に直結する:

| イベント | 失われるもの |
|---------|------------|
| `checkout.session.completed` (ticket_purchase) | 決済済みチケットの付与 (顧客は払ったのに残高が増えない) |
| `invoice.paid` (subscription_cycle) | 月次チケット付与 |
| `invoice.paid` (auto_recharge) | 自動購入分の付与 + attempt の paid 確定 |
| `customer.subscription.created` | 初回無償チケット付与 + `plan_code` 同期 |

### 実装内の説明が事実と食い違っている

`StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS` の docblock はこう書いている。

> claim() が transaction + lockForUpdate で状態遷移を直列化するため
> "processing 残留 stale" は生じず、復帰 sweep は不要。

これは誤りである。`claim()` が直列化するのは**状態遷移そのもの**であって、
**遷移のあとに走る本処理**ではない。本処理はトランザクションの外にあるため、
そこで落ちれば `received` が残る。

同一機能の台帳 (lctl feature `stripe-webhook-idempotency`) では、2026-08-04 の精査がこの主張を
明確に否定しており、テンプレート (laravel-claude-template) は同じ位置に
「回収しないと月次付与が無音で失われる」と真逆の説明を置いている。
テンプレートは既に回収経路 (回収待ち状態 + 再実行安全性の 2 値分類) を実装済みで、
aicue にだけ穴が残っている。

## 改善アイデア

**「本処理中に落ちて `received` のまま滞留した行」を検出して再処理へ戻す経路を足す。**
既に aicue にある滞留回収 (`RenderJobService::recoverStale` /
`TicketLedgerService::releaseStale` / `StaleUploadReservationSweeper::sweep`) と**同じ作法**で作る。

### (0) 前提: 再実行してよい種類とそうでない種類がある

保存済み payload の**再実行**は、イベントの種類によって安全性が違う。

- **再実行しても追加の被害を生まない** (`SafeToReplay`):
  `invoice.paid` / `checkout.session.completed` / `charge.refunded` / `invoice.payment_failed`。
  付与はすべて台帳の `idempotency_key` UNIQUE (`monthly:` / `purchase:` / `recharge:` /
  `refund:`) で冪等、checkout 行の遷移は `Completed` が終局で no-op になる
- **順序に依存する** (`OrderSensitive`):
  `customer.subscription.created` / `updated` / `deleted`。
  `SubscriptionService::applySubscriptionSnapshot` は**後勝ちで上書きする** (順序判定を持たない)
  ため、古い payload を後から流すと `plan_code` / `current_period_end` /
  `stripe_status` が**巻き戻る**

`HandledStripeWebhookEvent::replaySafety()` (網羅 `match`) を単一出典にする。
DB に保存された `type` 文字列からの変換は **`tryFrom()`** で行い、
**enum に無い type (未知の種類) は自動再実行しない** (deny-by-default)。
`config('cashier.webhook.events')` は Cashier の DEFAULT_EVENTS も含むため、
`HandledStripeWebhookEvent` に無い type の行 (`customer.updated` 等) が実際に記録される
(`process()` の `null => null` arm で受理のみ) — この行を安全側へ倒す必要がある。

> **語の意味を広げないこと**: `SafeToReplay` は「再実行しても追加の被害を生まない」であって
> 「再実行すれば必ず復旧する」ではない。復旧するかどうかは各ハンドラの事情による。

### (1) 「回収待ち」の状態と、そこへ置いた理由を足す

`WebhookEventStatus` に `RecoveryPending`(`recovery_pending`、表示名「回収待ち」) を足す。
これは**自動再実行の対象外と判定して置いた行の静止状態**であり、途中経過ではない。

理由は `status` からは復元できないので、`WebhookRecoveryReason` (3 値) を新設して
`stripe_webhook_events.recovery_reason` 列 (nullable) に保存する。

| 理由 | 意味 | 運用の次の行動 |
|------|------|--------------|
| `OrderSensitive` | 順序に依存する種類なので再実行しない | Stripe ダッシュボードで現在の契約状態を確認する |
| `AttemptsExhausted` | 試行上限 (`MAX_PROCESSING_ATTEMPTS`) に到達済み | `failure_reason` を見て手当てする (既存の terminal 手順と同じ) |
| `UnknownEventType` | `HandledStripeWebhookEvent` に無い種類 | 処理対象外のイベントなので通常は確認のみ |

自由文の `failure_reason` とは列を分ける (機械判定できる値と混ぜない)。

ここに入った行は**自動では二度と動かさない**。

### (2) 滞留回収の cron を足す

`billing:recover-stale-webhook-events` (5 分ごと) →
`StripeWebhookProcessor::recoverStale(): WebhookRecoveryResultDto`。

- `status=received` かつ `updated_at` が閾値 (既定 15 分) より古い行の `event_id` を列挙する
- 1 行ずつ `claimStale()` に渡す。`claimStale()` は `DB::transaction` + `lockForUpdate` で
  行を取り直し、**1 つのトランザクションの中で状態遷移だけを確定**して、
  何が起きたかを型付きの結果 (`WebhookStaleClaimOutcome`) で返す:
  - 状態が `received` でない / まだ滞留していない → `Skipped` (競合。何もしない)
  - `tryFrom()` が `null` → `recovery_pending` + 理由 `UnknownEventType`
  - `replaySafety()` が `OrderSensitive` → `recovery_pending` + 理由 `OrderSensitive`
  - `attempts >= MAX_PROCESSING_ATTEMPTS` → `recovery_pending` + 理由 `AttemptsExhausted`
  - それ以外 → `attempts+1` して `received` のまま `ClaimedForReplay`
- **commit 後**に、呼び出し側 (`recoverStale()`) が結果に応じて
  `Log::warning` と `report()` を出す (トランザクション内では通知しない = 状態が保存されて
  いないのに通知だけ出る / 同じ行に複数回出る、を避ける)
- `ClaimedForReplay` の行だけ、保存済み `type` / `payload` で `process()` を再実行し、
  終局書き込み (施策 3) を行う。**再 throw しない** (cron は次の行へ進む)

`claim()` (Stripe 再送の受理) は**一切変更しない**。滞留の判定と受理は `claimStale()` が持ち、
`received` からの再受理は今までどおり `claim()` では起こらない。
`recovery_pending` の行に後着の Stripe 再送が当たっても `claim()` は `null` を返す
(= 現行の `received` に当たったときと同じ挙動)。**巻き戻りの経路を新しく作らない**。

再実行中にまたプロセスが落ちた行は `received` のまま残るので、閾値経過後の次回実行で
再び拾われる (自己回復する)。回収も `attempts` を消費するので、上限 8 で必ず止まる。

### (3) 終局の書き込みを条件付きにする

`handle()` / 回収の終局書き込み (`processed` / `failed`) を、**単一の条件付き UPDATE** にする。

```
WHERE event_id = ? AND status = 'received' AND attempts = 受理したときの値
```

**更新件数が 1 のときだけ終局化成功**と判定し、0 件なら書かずに `Log::warning` を出す。
これが無いと、閾値を超えて生きていた元のワーカーが遅れて完了したときに、回収側が確定させた
結果を古い在メモリ状態で上書きしてしまう。ドメイン規約 6 の
「terminal 化された後に旧ワーカーが状態を書き戻さないよう条件付き UPDATE にする」に揃える。

**この CAS が守るのは `stripe_webhook_events` 行の世代だけ**である。元のワーカーと回収側の
`process()` は並行し得るので、付与の一回性は今までどおり台帳の `idempotency_key` UNIQUE と
各ハンドラの終局 guard が担う (誇張しない)。

### (4) 誤った説明コメントを実態に合わせる

`MAX_PROCESSING_ATTEMPTS` の docblock から「復帰 sweep は不要」を削り、
回収経路の存在と役割分担 (`claim()` は Stripe 再送の受理まで / 滞留の判定と受理は
`claimStale()`) を書く。

## 期待効果

- **使命への貢献**: クラッシュで `received` に残った付与系イベント
  (`checkout.session.completed` / `invoice.paid`) が**無音で失われる経路を塞ぐ**。
  AI-CUE のチケットは撮影・レンダの実行権そのものなので、付与の取りこぼしは
  「現場作業者がマニュアル動画を作れない」に直結する。
  なお本改善は付与漏れを全部消すものではない — 試行上限到達・payload 不整合・実装不具合による
  未付与は残る。塞ぐのは「クラッシュ滞留」の 1 経路である
- **観測点ができる**: 自動回収しない行が `recovery_pending` として 1 箇所に集まり、
  理由が `recovery_reason` に残る。件数が常設の観測点になる。
  併せて置いた瞬間に `report()` を 1 回出すが、**`report()` の配送は通知基盤の設定次第**なので
  「運用へ確実に届く」とは言えない (常設の観測点は件数のほう)
- **家系との収束**: テンプレートが持つ形 (回収待ち状態 + 再実行安全性の 2 値分類) に寄せる。
  2026-08-04 の裁定が決めた合成先の方向と一致し、将来のテンプレート追従の差分が小さくなる

## 実装方針（概要）

| 変更対象 | 内容 |
|---------|------|
| `app/Enums/Billing/WebhookEventStatus.php` | `RecoveryPending` を追加 |
| `app/Enums/Billing/WebhookReplaySafety.php` (新規) | `SafeToReplay` / `OrderSensitive` の 2 値 |
| `app/Enums/Billing/WebhookRecoveryReason.php` (新規) | `OrderSensitive` / `AttemptsExhausted` / `UnknownEventType` |
| `app/Enums/Billing/WebhookStaleClaimOutcome.php` (新規) | `claimStale()` の結果 4 値 |
| `app/Enums/Billing/HandledStripeWebhookEvent.php` | `replaySafety()` (網羅 `match`) を追加 |
| `app/DataTransferObjects/Billing/WebhookRecoveryResultDto.php` (新規) | `replayed` / `rested` / `skipped` の件数 |
| `app/Services/Billing/StripeWebhookProcessor.php` | `recoverStale()` / `claimStale()` 追加、終局書き込みの条件付き化、誤コメント修正 |
| `app/Models/Billing/StripeWebhookEvent.php` | `recovery_reason` の property / cast |
| `database/migrations/…_add_recovery_reason_to_stripe_webhook_events_table.php` (新規) | nullable string 1 列 |
| `config/billing.php` | 滞留判定の閾値 (`webhook_stale_after_minutes`) |
| `routes/console.php` | `billing:recover-stale-webhook-events` + 5 分スケジュール |
| `database/factories/Billing/StripeWebhookEventFactory.php` | `recoveryPending()` state |
| `tests/Feature/Billing/StripeWebhookStaleRecoveryTest.php` (新規) | 回収の受入テスト (下記 7 観点) |
| `tests/Feature/Billing/WebhookReplaySafetyTest.php` (新規) | 分類の網羅と、種類ごとの再実行での付与一回性 |
| `tests/Support/Security/DirectFetchInventory.php` | 検出された場合のみ目録登録 (下記) |
| `docs/architecture.md` | 回収経路・閾値・監視対象・運用手順・保証しないものを追記 |

固定するテスト観点 (詳細設計で 1 テストずつに落とす):

1. `SafeToReplay` の滞留行が回収で一度だけ再処理され、付与が 1 回だけ起きる
   (`checkout.session.completed` / `invoice.paid` の 2 系統を個別に固定する)
2. `OrderSensitive` の滞留行は `recovery_pending` + 理由 `OrderSensitive` になり、
   再処理されない。その後の Stripe 再送でも `claim()` が受理しない (契約状態が巻き戻らない)
3. 試行上限に到達済みの滞留行は再処理されず `recovery_pending` + 理由 `AttemptsExhausted`
4. 未知 type の滞留行は再処理されず `recovery_pending` + 理由 `UnknownEventType`
5. 元ワーカーが遅れて終局書き込みを行っても、回収側が確定させた結果を上書きしない
   (条件付き UPDATE が 0 件になる)
6. 回収中に再びクラッシュした行 (= `received` のまま) は次回実行で再び拾われる
7. 閾値内の `received` 行は回収対象にならない (処理中の行に触らない)

**`DirectFetchInventory` について**: 行の取り直しと条件付き UPDATE は
`event_id` (UNIQUE 列) を handle にする — 本クラスは元々 `claim()` が `where('event_id', …)` で
行を引いており、識別子を 2 本立てにしない。副次的に主キー同一性クエリの母集団にも入らない。
ただし実装時に `ModelDirectFetchInvariantTest` を実行し、検出されたら失敗メッセージのキーで
目録へ登録する (deny-by-default を迂回しない)。

DB migration は `recovery_reason` 列の追加 1 本のみ
(`status` は `string` 列なので値追加に migration は要らない。既存 default `'received'` も変えない)。

## 制約・前提

- **`claim()` の直列化契約を壊さない**: `claim()` は変更しない。`received` / `processed` /
  `recovery_pending` からの再受理は起きない
- **上限 8 (`MAX_PROCESSING_ATTEMPTS`) を壊さない**: 回収も `attempts` を消費する
- **終局で 200 を壊さない**: 上限到達時は現行どおり処理せず例外も投げない。
  回収 cron は HTTP 経路ではないので Stripe への応答に影響しない
- **tenant キー不信を壊さない**: 回収は保存済み payload を既存の `process()` にそのまま渡すだけで、
  組織の解決は今までどおり各ハンドラが自 DB 行 (`ticket_checkout_sessions` /
  `billing_checkout_sessions` / `ticket_auto_recharge_attempts`) または `stripe_id` 照合で行う。
  **payload の `metadata` を組織解決・認可に使う経路を新しく作らない**
- **既存の滞留回収と同じ作法**: 「id を列挙 → 1 行ずつ行ロックで取り直して再検証 → 件数を返す」
  (`RenderJobService::recoverStale` / `TicketLedgerService::releaseStale` と同型)。
  **別層 (共通の回収基盤) は作らない** — aicue には共通基盤が無く、
  ドメインごとの個別実装が既定の作法だから
- **保持期限 purge との関係**: `StripeWebhookEventPurger` は `processed_at IS NULL` の行を
  「異常として計上するだけで消さない」ため、回収待ちの行が purge で消えることはない
- **ログの必須項目**: 回収に関する `Log` / `report()` には
  `event_id` / `type` / `attempts` / `status` を必ず載せる (payload 本体は載せない)
- **通知はトランザクションの外**: `claimStale()` は状態遷移だけを確定し、
  `Log` / `report()` は commit 後に呼び出し側が出す
- **境界変換は `tryFrom()`**: DB の `type` 文字列を `from()` で変換しない
  (未知値で cron 全体が止まる)。行単位の異常で cron を止めず次の行へ進む
- **保証しないもの**: 本設計が守るのは `stripe_webhook_events` 行の世代管理までである。
  元ワーカーと回収側の `process()` の**真の同時実行は防がない**し、テストもしない。
  付与の一回性は台帳の `idempotency_key` UNIQUE が担う

## スコープ外

- **`customer.subscription.*` の自動再実行**。順序判定の列 (イベント生成時刻) を足して
  後勝ちを止める硬化は本 TODO では作らない。ここで必要なのは「付与が無音で失われないこと」であり、
  契約状態は後続の `customer.subscription.updated` で追随する。滞留した行は
  `recovery_pending` + `report()` で運用に渡す
- **`failed` 行の自動リトライ cron**。`failed` は Stripe の再送で再処理される既存経路がある。
  回収 cron の対象は `received` 滞留のみ
- **webhook 受信の非同期化** (受信即 200 + キュー処理への作り替え)
- **他リポジトリ (テンプレート等) への還流・収束作業**
