# Round 2: 概念設計の修正版

Round 1 の指摘への対応マトリクスと、修正後の概念設計を送る。再レビューを頼む。

## 対応マトリクス (要約)

| 指摘 | 判断 | 対応 |
|------|------|------|
| [Critical] `recovery_pending` を `claim()` が無条件受理すると OrderSensitive が自動再実行される | 対応する | `claim()` を**一切変更しない**設計に作り替えた。滞留の判定と受理は新設 `claimStale()` が持つ |
| [Critical] `OrderSensitive` の巻き戻りリスク | 対応する | `WebhookReplaySafety` を分類 enum に留めず `claimStale()` の**状態遷移の分岐そのもの**にした |
| [Warning] スコープを SafeToReplay に絞れ | 対応する | 順序判定列の追加はスコープ外と明記 |
| [Warning] 生存中 worker との競合 | 対応する | 終局書き込みを `status=received AND attempts=受理時の値` の条件付き UPDATE にする施策を追加 |
| [Warning] 「構造的に消える」は言い過ぎ | 対応する | 「クラッシュ滞留の 1 経路を塞ぐ」に弱めた |
| [Warning] tenant キー不信を明記 | 対応する | 制約・前提に追加 |
| [Warning] docs / ログ項目 | 対応する | `docs/architecture.md` の追記項目と、ログ必須項目 (`event_id` / `type` / `attempts` / `status`) を明記 |
| [Warning] 型安全性 (payload 直参照) | 対応する | 行の `type` 列と `payload` 列を既存 `process()` にそのまま渡す。読み出しは `stringAt()` / `data_get` 経由のみ |
| [Warning] 回収結果の JSON endpoint 化 | 対応する | Artisan コマンドのみ。route を追加しない |

## 重要な設計変更の要点 (Round 1 との差分)

Round 1 では「cron が `recovery_pending` に落とす → `claim()` が `recovery_pending` を受理して再処理」
という 2 段だった。これを次に変えた。

1. **`claim()` は変更しない**。`recovery_pending` の行に後着の Stripe 再送が当たっても
   `claim()` は `null` を返す (= 現行の `received` に当たったときと**同じ挙動**)。
   巻き戻りの経路を新設しない
2. 滞留の判定と受理は新設 `claimStale(int $id)` が**1 つのトランザクション**で確定させる:
   - 状態が `received` でない / まだ滞留していない → `null`
   - `OrderSensitive` → `recovery_pending` にして `null` (再実行しない)
   - `attempts >= MAX_PROCESSING_ATTEMPTS` → `recovery_pending` にして `null`
   - それ以外 (`SafeToReplay` かつ上限未満) → `attempts+1` して `received` のまま返す
3. `RecoveryPending` を「自動再実行の対象外と判定して置いた**静止状態**」と定義した。
   ここに入るのは上記 2 通りだけで、**二度と自動では動かない**。
   置いた瞬間に `report()` が 1 行につき 1 回だけ飛ぶ (行はもう `received` ではないので
   次回以降の cron に拾われない = 通知が洪水にならない)
4. 再実行中にまたクラッシュした行は `received` のまま残るので、次回実行で自己回復する
   (中間状態に取り残される窓が無い)

確認してほしい点:

- (a) `claim()` を触らずに `claimStale()` を別に置く形で、Round 1 の Critical は解消しているか
- (b) `RecoveryPending` に入る 2 通り (順序に依存する種類 / 上限到達) を 1 つの状態で表すのは妥当か。
  分けるべきなら理由を示してほしい
- (c) 終局書き込みの条件付き UPDATE (`status=received AND attempts=受理時の値`) に
  見落としている競合は無いか
- (d) 残っているスコープ過大 / 過小があるか

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

> **語の意味を広げないこと**: `SafeToReplay` は「再実行しても追加の被害を生まない」であって
> 「再実行すれば必ず復旧する」ではない。復旧するかどうかは各ハンドラの事情による。

### (1) 「回収待ち」の状態を足す

`WebhookEventStatus` に `RecoveryPending`(`recovery_pending`、表示名「回収待ち」) を足す。
これは**自動再実行の対象外と判定して置いた行の静止状態**であり、途中経過ではない。ここに入るのは

- 順序に依存する種類 (`OrderSensitive`) の滞留行
- 試行上限 (`MAX_PROCESSING_ATTEMPTS`) に到達していた滞留行

の 2 通りだけで、どちらも**自動では二度と動かさない**。運用が Stripe ダッシュボードで
実際の決済状態を確認して手当てするための行である。

### (2) 滞留回収の cron を足す

`billing:recover-stale-webhook-events` (5 分ごと) →
`StripeWebhookProcessor::recoverStale(): int`。

- `status=received` かつ `updated_at` が閾値 (既定 15 分) より古い行の id を列挙する
- 1 行ずつ `claimStale()` に渡す。`claimStale()` は `DB::transaction` + `lockForUpdate` で
  行を取り直し、**1 つのトランザクションの中で**次を確定させる:
  - 状態が `received` でない / まだ滞留していない → `null` (競合。何もしない)
  - `replaySafety()` が `OrderSensitive` → `recovery_pending` にして `null`
    (+ `report()` と `Log::warning` を 1 行につき 1 回だけ出す)
  - `attempts >= MAX_PROCESSING_ATTEMPTS` → `recovery_pending` にして `null` (同上)
  - それ以外 → `attempts+1` して `received` のまま返す (= 再実行してよい)
- 返ってきた行だけ、保存済み `type` / `payload` で `process()` を再実行し、
  成功なら `processed` / 失敗なら `failed` + `failure_reason` を記録する
  (**再 throw しない**。cron は次の行へ進む)

`claim()` (Stripe 再送の受理) は**一切変更しない**。滞留の判定と受理は `claimStale()` が持ち、
`received` からの再受理は今までどおり `claim()` では起こらない。
`recovery_pending` の行に後着の Stripe 再送が当たっても `claim()` は `null` を返す
(= 現行の `received` に当たったときと同じ挙動)。**巻き戻りの経路を新しく作らない**。

再実行中にまたプロセスが落ちた行は `received` のまま残るので、閾値経過後の次回実行で
再び拾われる (自己回復する)。回収も `attempts` を消費するので、上限 8 で必ず止まる。

### (3) 終局の書き込みを条件付きにする

`handle()` の終局書き込み (`processed` / `failed`) を、
`status = received` **かつ** `attempts = 受理したときの値` の条件付き UPDATE にする。

これが無いと、閾値を超えて生きていた元のワーカーが遅れて完了したときに、回収側が確定させた
結果を古い在メモリ状態で上書きしてしまう。ドメイン規約 6 の
「terminal 化された後に旧ワーカーが状態を書き戻さないよう条件付き UPDATE にする」に揃える。
競合で 0 行のときは書かずに `Log::warning` を出す (処理自体は冪等キーが守る)。

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
- **無音の解消**: 自動回収しない行が `recovery_pending` として 1 箇所に集まり、
  置いた瞬間に `report()` が 1 回飛ぶ。件数が常設の観測点になる
- **家系との収束**: テンプレートが持つ形 (回収待ち状態 + 再実行安全性の 2 値分類) に寄せる。
  2026-08-04 の裁定が決めた合成先の方向と一致し、将来のテンプレート追従の差分が小さくなる

## 実装方針（概要）

| 変更対象 | 内容 |
|---------|------|
| `app/Enums/Billing/WebhookEventStatus.php` | `RecoveryPending` を追加 |
| `app/Enums/Billing/WebhookReplaySafety.php` (新規) | `SafeToReplay` / `OrderSensitive` の 2 値 |
| `app/Enums/Billing/HandledStripeWebhookEvent.php` | `replaySafety()` (網羅 `match`) を追加 |
| `app/Services/Billing/StripeWebhookProcessor.php` | `recoverStale()` / `claimStale()` 追加、終局書き込みの条件付き化、誤コメント修正 |
| `config/billing.php` | 滞留判定の閾値 (`webhook_stale_after_minutes`) |
| `routes/console.php` | `billing:recover-stale-webhook-events` + 5 分スケジュール |
| `database/factories/Billing/StripeWebhookEventFactory.php` | `recoveryPending()` state |
| `tests/Support/Security/DirectFetchInventory.php` | 回収 cron の主キー再取得を目録登録 |
| `docs/architecture.md` | 回収経路・閾値・監視対象・運用手順・保証しないものを追記 |

DB migration は**不要** (`status` は `string` 列。既存 default `'received'` も変えない)。

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

## スコープ外

- **`customer.subscription.*` の自動再実行**。順序判定の列 (イベント生成時刻) を足して
  後勝ちを止める硬化は本 TODO では作らない。ここで必要なのは「付与が無音で失われないこと」であり、
  契約状態は後続の `customer.subscription.updated` で追随する。滞留した行は
  `recovery_pending` + `report()` で運用に渡す
- **`failed` 行の自動リトライ cron**。`failed` は Stripe の再送で再処理される既存経路がある。
  回収 cron の対象は `received` 滞留のみ
- **webhook 受信の非同期化** (受信即 200 + キュー処理への作り替え)
- **他リポジトリ (テンプレート等) への還流・収束作業**
