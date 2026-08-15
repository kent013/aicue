## Round 1 指摘への対応

# 対応マトリクス: impl-review Round 1

## [Warning] handle() の例外経路が CAS 0 件でも必ず再 throw する (StripeWebhookProcessor.php)

- 判断: 対応する
- 根拠: 指摘のとおり非対称だった。世代を追い越された実行は行の決着に関与しないので、
  成功経路で 200 を返すなら失敗経路でも 200 を返すべきである。500 を返しても Stripe の
  再送は `claim()` に弾かれて 200 で終わるため、得られるものが無く運用ノイズだけが残る。
- 対応内容: `handle()` の catch で `finalize()` の戻り値を受け、false のときは
  `report()` だけ行って `return` する (throw しない)。設計書 (施策 C の変更後コードと
  テスト計画) も同じ内容へ更新した。

## [Warning] 失敗 CAS 経路のテストが無い (StripeWebhookStaleRecoveryTest.php)

- 判断: 対応する
- 根拠: 上の挙動はテストが無ければ「実装済み」と言えない (禁止事項 1)。
- 対応内容: 「HTTP 経路で世代を追い越されたら処理が失敗しても例外を投げない」を追加。
  mock 内で `attempts` を進めたうえで例外を投げ、行が `received` のまま
  (`failed` にならず `failure_reason` も付かず) 例外が外へ出ないことを固定した。

## [Suggestion] migration の down()/up() を try/finally で戻す

- 判断: 対応する
- 根拠: assert 失敗時に同一プロセスの後続テストへ schema 破損を残し得るのは実害がある。
  対策のコストも小さい。
- 対応内容: `down()` と assert を `try`、`up()` を `finally` に置いた。

## 修正差分 (Round 1 からの追加分のみ。ファイル全体の diff ではなく HEAD からの diff の該当 3 ファイル)

```diff
diff --git a/app/Services/Billing/StripeWebhookProcessor.php b/app/Services/Billing/StripeWebhookProcessor.php
index c4a8246..fe5e5ff 100644
--- a/app/Services/Billing/StripeWebhookProcessor.php
+++ b/app/Services/Billing/StripeWebhookProcessor.php
@@ -4,11 +4,16 @@
 
 namespace App\Services\Billing;
 
+use App\DataTransferObjects\Billing\StaleWebhookClaimDto;
+use App\DataTransferObjects\Billing\WebhookRecoveryResultDto;
 use App\Enums\Billing\BillingNotificationType;
 use App\Enums\Billing\HandledStripeWebhookEvent;
 use App\Enums\Billing\SignupFundingChoice;
 use App\Enums\Billing\TicketCheckoutSessionStatus;
 use App\Enums\Billing\WebhookEventStatus;
+use App\Enums\Billing\WebhookRecoveryReason;
+use App\Enums\Billing\WebhookReplaySafety;
+use App\Enums\Billing\WebhookStaleClaimOutcome;
 use App\Enums\CheckoutIntent;
 use App\Enums\CheckoutSessionStatus;
 use App\Jobs\Billing\HandleAutoRechargeChargeFailureJob;
@@ -49,6 +54,12 @@
  * 4. 再送上限: failed→received 復帰のたびに attempts をインクリメントし、
  *    MAX_PROCESSING_ATTEMPTS 到達後は処理せず skip (= 200 terminal-ack) して
  *    恒久失敗イベントの無限 500 ストームを打ち切る (運用は failure_reason で調査する)
+ * 5. 滞留回収: 本処理中にプロセスが落ちて received のまま残った行を
+ *    recoverStale() が拾い直す (cron: billing:recover-stale-webhook-events)。
+ *    再実行してよい種類かは HandledStripeWebhookEvent::replaySafety() が決め、
+ *    対象外・上限到達は recovery_pending + recovery_reason へ置いて止める。
+ *    終局書き込みは受理した世代 (attempts) を握っている実行だけが行う条件付き UPDATE。
+ *    運用契約の正本は docs/architecture.md の「Stripe webhook の滞留回収」。
  *
  * subscriptions **行の作成** (updateOrCreate) は Cashier の WebhookController が唯一の
  * writer。本クラス (WebhookReceived listener) は Cashier のハンドラより先に走るため、
@@ -64,10 +75,18 @@
 class StripeWebhookProcessor
 {
     /**
-     * webhook 処理失敗の再送上限。attempts (failed→received 復帰回数) がこれに到達したら
-     * terminal とみなし処理せず 200 ack して Stripe の自動再送を打ち切る。
-     * claim() が transaction + lockForUpdate で状態遷移を直列化するため
-     * "processing 残留 stale" は生じず、復帰 sweep は不要。
+     * webhook 処理の試行上限。`attempts` がこれに到達したら terminal とみなす。
+     *
+     * `attempts` を増やす経路は 2 つある — `claim()` (Stripe 再送による failed→received 復帰) と
+     * `claimStale()` (滞留回収による受理)。上限は共通で、到達後は HTTP 経路なら処理せず
+     * 200 ack、回収経路なら `recovery_pending` + `AttemptsExhausted` へ置いて止める。
+     *
+     * **`claim()` の直列化は本処理までは覆わない** (守るのは状態遷移だけで `process()` は
+     * トランザクションの外で走る)。そこで落ちた行は `received` のまま残り、Stripe の再送も
+     * `claim()` に弾かれて 200 で終わるため付与が無音で失われる。これを塞ぐのが
+     * `recoverStale()` である。運用契約の正本は `docs/architecture.md`
+     * の「Stripe webhook の滞留回収」。
+     *
      * Stripe の自動再送窓 (~3 日) に対し 8 回で十分。
      */
     public const int MAX_PROCESSING_ATTEMPTS = 8;
@@ -97,21 +116,255 @@ public function handle(WebhookReceived $event): void
             return; // 同一 event_id を処理済み (冪等 skip)
         }
 
+        // 受理したときの世代 (claim 直後の attempts)。以降の書き込みはこの世代を握っている
+        // 実行だけが行える (滞留回収が attempts を進めた後の追い越し書き込みを防ぐ)。
+        $claimedAttempts = $record->attempts;
+
         try {
             $this->process($type, $payload);
         } catch (Throwable $exception) {
-            $record->status = WebhookEventStatus::Failed;
-            $record->failure_reason = $exception->getMessage();
-            $record->save();
+            $finalized = $this->finalize($eventId, $claimedAttempts, WebhookEventStatus::Failed, $exception->getMessage());
             report($exception);
 
+            if (! $finalized) {
+                // 行は既に別の世代 (滞留回収など) が持っている。こちらから再送を促す理由が無い
+                // — 再送しても claim() に弾かれて 200 で終わり、500 の運用ノイズだけが残る。
+                // 成功経路と同じ扱いにする (世代を失った実行は行の決着に関与しない)。
+                return;
+            }
+
             throw $exception; // 200 を返さず Stripe の再送を促す (failed は再送で再処理)
         }
 
-        $record->status = WebhookEventStatus::Processed;
-        $record->failure_reason = null;
-        $record->processed_at = CarbonImmutable::now();
-        $record->save();
+        $this->finalize($eventId, $claimedAttempts, WebhookEventStatus::Processed, null);
+    }
+
+    /**
+     * 受理した世代を握っている実行だけが行える条件付き書き込み (CAS)。
+     *
+     * `status='received'` かつ `attempts=受理時の値` の 1 行だけを更新する。
+     * 0 件のときは**別の実行がその行を先に進めている** (滞留回収が claim し直した等) ので
+     * 何も書かずに記録だけ残す — 旧ワーカーが新しい世代の結果を上書きしない
+     * (ドメイン規約 6 の「条件付き UPDATE」)。
+     *
+     * `recovery_reason` は必ず NULL を置く
+     * (不変条件: 非 NULL ⟺ status = recovery_pending)。
+     *
+     * **保証範囲を誇張しない**: これが守るのは `stripe_webhook_events` 行の世代だけである。
+     * 旧ワーカーと回収側の `process()` は並行し得るので、付与の一回性は台帳の
+     * `idempotency_key` UNIQUE と各ハンドラの終局 guard が担う。
+     *
+     * @param  WebhookEventStatus  $status  Processed (終局) / Failed (HTTP 経路の失敗) /
+     *                                      Received (回収経路の失敗 = 終局させず次の回収へ回す)
+     * @return bool 書き込めたら true
+     */
+    private function finalize(
+        string $eventId,
+        int $claimedAttempts,
+        WebhookEventStatus $status,
+        ?string $failureReason,
+    ): bool {
+        $updated = StripeWebhookEvent::query()
+            ->where('event_id', $eventId)
+            ->where('status', WebhookEventStatus::Received->value)
+            ->where('attempts', $claimedAttempts)
+            ->update([
+                'status' => $status->value,
+                'failure_reason' => $failureReason,
+                'recovery_reason' => null,
+                'processed_at' => $status === WebhookEventStatus::Processed
+                    ? CarbonImmutable::now()
+                    : null,
+            ]);
+
+        if ($updated !== 1) {
+            Log::warning('stripe webhook: 別の実行が先に進めたため終局書き込みを見送った', [
+                'event_id' => $eventId,
+                'attempts' => $claimedAttempts,
+                'status' => $status->value,
+            ]);
+
+            return false;
+        }
+
+        return true;
+    }
+
+    /**
+     * 処理中に滞留した webhook 記録の回収 (cron: billing:recover-stale-webhook-events)。
+     *
+     * 対象は `status=received` かつ `updated_at` が滞留の閾値より古い行**だけ**。
+     * `failed` は Stripe の再送が再試行の駆動者なので拾わない。
+     *
+     * 作法は既存の滞留回収 (`RenderJobService::recoverStale` /
+     * `TicketLedgerService::releaseStale`) と同じ = 対象を列挙 → 1 件ずつ行ロックで
+     * 取り直して再検証 → 件数を返す。**共通の回収基盤は作らない** (ドメインごとの個別実装)。
+     *
+     * 通知 (`Log::warning` / `report()`) は**トランザクションの外**で出す
+     * (状態が保存されていないのに通知だけ出る / 同じ行に複数回出るのを避ける)。
+     * ただし commit 後に落ちれば 0 回になる = 送信を 1 回試みるだけで、
+     * 厳密な一回配送は保証しない (常設の観測点は `recovery_pending` の件数のほう)。
+     */
+    public function recoverStale(): WebhookRecoveryResultDto
+    {
+        $threshold = CarbonImmutable::now()
+            ->subMinutes(config()->integer('billing.webhook_stale_after_minutes'));
+
+        /** @var list<string> $staleEventIds */
+        $staleEventIds = StripeWebhookEvent::query()
+            ->where('status', WebhookEventStatus::Received->value)
+            ->where('updated_at', '<=', $threshold)
+            ->orderBy('id')
+            ->pluck('event_id')
+            ->all();
+
+        $replayed = 0;
+        $retryScheduled = 0;
+        $movedToRecoveryPending = 0;
+        $skipped = 0;
+
+        foreach ($staleEventIds as $eventId) {
+            $claim = $this->claimStale($eventId, $threshold);
+            if ($claim === null) {
+                $skipped++; // 行が消えた / 別の実行が先に進めた
+
+                continue;
+            }
+
+            if ($claim->outcome === WebhookStaleClaimOutcome::MovedToRecoveryPending) {
+                $movedToRecoveryPending++;
+                $this->reportRecoveryPending($claim);
+
+                continue;
+            }
+
+            try {
+                $this->process($claim->type, $claim->payload);
+            } catch (Throwable $exception) {
+                report($exception);
+                // **終局させない**: failed にすると回収対象 (received) から外れ、
+                // Stripe も配信成功と認識しているため二度と再試行されない。
+                // received のまま失敗理由だけ書いて次回の回収へ回す (attempts は消費済み)。
+                $this->finalize($claim->eventId, $claim->attempts, WebhookEventStatus::Received, $exception->getMessage())
+                    ? $retryScheduled++
+                    : $skipped++;
+
+                continue;
+            }
+
+            $this->finalize($claim->eventId, $claim->attempts, WebhookEventStatus::Processed, null)
+                ? $replayed++
+                : $skipped++;
+        }
+
+        return new WebhookRecoveryResultDto(
+            replayed: $replayed,
+            retryScheduled: $retryScheduled,
+            movedToRecoveryPending: $movedToRecoveryPending,
+            skipped: $skipped,
+        );
+    }
+
+    /**
+     * 滞留 1 件の受理。**状態遷移だけ**を 1 つのトランザクションで確定させ、
+     * commit 後に要る値をスナップショットで返す (通知はここでは出さない)。
+     *
+     * `claim()` (Stripe 再送の受理) とは入口が別なので分けてある。
+     * `claim()` は変更しない = `received` からの再受理は今までどおり起こらない。
+     *
+     * 滞留の再検証は**クエリの WHERE に入れる** (ロック取得後に PostgreSQL が述語を
+     * 再評価するため、ロック待ちの間に他の実行が前進させた行は 1 行も返らない)。
+     *
+     * @return StaleWebhookClaimDto|null 処置をしなかったとき (行が無い / 条件を満たさない) は null
+     */
+    private function claimStale(string $eventId, CarbonImmutable $threshold): ?StaleWebhookClaimDto
+    {
+        return DB::transaction(function () use ($eventId, $threshold): ?StaleWebhookClaimDto {
+            $record = StripeWebhookEvent::query()
+                ->where('event_id', $eventId)
+                ->where('status', WebhookEventStatus::Received->value)
+                ->where('updated_at', '<=', $threshold)
+                ->lockForUpdate()
+                ->first();
+
+            if (! $record instanceof StripeWebhookEvent) {
+                return null;
+            }
+
+            $reason = $this->recoveryReasonFor($record);
+            if ($reason !== null) {
+                $record->status = WebhookEventStatus::RecoveryPending;
+                $record->recovery_reason = $reason;
+                $record->save();
+
+                return StaleWebhookClaimDto::movedToRecoveryPending(
+                    $record->event_id,
+                    $record->type,
+                    $record->attempts,
+                    $reason,
+                );
+            }
+
+            // 世代を 1 つ進める (status は received のまま = 状態機械を増やさない)。
+            // updated_at も進むので、次の実行は閾値を超えるまでこの行を拾わない。
+            $record->attempts += 1;
+            $record->save();
+
+            return StaleWebhookClaimDto::claimedForReplay(
+                $record->event_id,
+                $record->type,
+                $record->attempts,
+                $record->payload,
+            );
+        });
+    }
+
+    /**
+     * 自動再実行の対象外と判定する理由 (無ければ null = 再実行してよい)。
+     *
+     * DB の `type` 文字列は **`tryFrom()`** で境界変換する (`from()` は未知値で例外になり
+     * cron 全体を止める)。`null` (本アプリが処理しない種類) は**再実行してよい側**に落ちる —
+     * `process()` の `null` arm は構造的に no-op で、通常経路でも `processed` になるため
+     * (同じ事実に 2 通りの決着を与えない)。
+     */
+    private function recoveryReasonFor(StripeWebhookEvent $record): ?WebhookRecoveryReason
+    {
+        $event = HandledStripeWebhookEvent::tryFrom($record->type);
+
+        // 本アプリが処理しない種類は**必ず**通常経路と同じ決着にする (再実行 → no-op → processed)。
+        // 試行上限より前に返すのが要点 — no-op に上限を適用して回収待ちへ置くと、
+        // 「未対応 type は通常経路と同じ」という契約が上限到達時だけ破れる。
+        if ($event === null) {
+            return null;
+        }
+
+        if ($event->replaySafety() === WebhookReplaySafety::OrderSensitive) {
+            return WebhookRecoveryReason::OrderSensitive;
+        }
+
+        if ($record->attempts >= self::MAX_PROCESSING_ATTEMPTS) {
+            return WebhookRecoveryReason::AttemptsExhausted;
+        }
+
+        return null;
+    }
+
+    /**
+     * 回収待ちへ置いたことの可観測化 (commit 後に 1 回だけ送信を試みる)。
+     * payload 本体は載せない (外部由来の可変データを運用ログへ流さない)。
+     */
+    private function reportRecoveryPending(StaleWebhookClaimDto $claim): void
+    {
+        Log::warning('stripe webhook: 滞留を回収待ちへ移した (自動再実行しない)', $claim->logContext());
+
+        report(new RuntimeException(sprintf(
+            'stripe webhook 回収待ち: %s (%s) reason=%s attempts=%d',
+            $claim->eventId,
+            $claim->type,
+            // 回収待ち以外の DTO では reason が無い (呼び出し側で絞っているが型では閉じていない)
+            $claim->reason->value ?? '',
+            $claim->attempts,
+        )));
     }
 
     /**
@@ -162,11 +415,15 @@ private function claim(string $eventId, string $type, array $payload): ?StripeWe
             }
 
             $record = new StripeWebhookEvent;
-            // 全カラム明示代入 (クライアント入力は入らない)
+            // 全カラム明示代入 (クライアント入力は入らない)。
+            // attempts は DB カラム default に依存せず INSERT 時に明示代入する —
+            // 受理直後の世代 (finalize の条件付き UPDATE が握る値) を
+            // 在メモリの instance から必ず読めるようにするため。
             $record->event_id = $eventId;
             $record->type = $type;
             $record->status = WebhookEventStatus::Received;
             $record->payload = $payload;
+            $record->attempts = 0;
             $record->save();
 
             return $record;
diff --git a/devnotes/20260815-1109-stripe-webhook-stuck-recovery/detailed-design.md b/devnotes/20260815-1109-stripe-webhook-stuck-recovery/detailed-design.md
index 53dba80..d2236fc 100644
--- a/devnotes/20260815-1109-stripe-webhook-stuck-recovery/detailed-design.md
+++ b/devnotes/20260815-1109-stripe-webhook-stuck-recovery/detailed-design.md
@@ -496,15 +496,27 @@ ### 変更後コード
         try {
             $this->process($type, $payload);
         } catch (Throwable $exception) {
-            $this->finalize($eventId, $claimedAttempts, WebhookEventStatus::Failed, $exception->getMessage());
+            $finalized = $this->finalize($eventId, $claimedAttempts, WebhookEventStatus::Failed, $exception->getMessage());
             report($exception);
 
+            if (! $finalized) {
+                // 行は既に別の世代 (滞留回収など) が持っている。こちらから再送を促す理由が無い
+                // — 再送しても claim() に弾かれて 200 で終わり、500 の運用ノイズだけが残る。
+                // 成功経路と同じ扱いにする (世代を失った実行は行の決着に関与しない)。
+                return;
+            }
+
             throw $exception; // 200 を返さず Stripe の再送を促す (failed は再送で再処理)
         }
 
         $this->finalize($eventId, $claimedAttempts, WebhookEventStatus::Processed, null);
 ```
 
+> **失敗経路も成功経路と同じ扱いにする** (Codex 実装レビュー Round 1 の指摘)。
+> 世代を追い越された実行は行の決着に関与しないので、`process()` が失敗していても
+> Stripe へ 500 を返さない。返しても再送は `claim()` に弾かれて 200 で終わるため、
+> 得られるものは無く運用ノイズだけが残る。
+
 ```php
     /**
      * 受理した世代を握っている実行だけが行える条件付き書き込み (CAS)。
@@ -580,7 +592,9 @@ ### テスト計画
       (単一プロセスで「追い越し」だけを再現する)
 - [ ] 同じケースで **HTTP 経路は例外を投げずに完走する** こと
       (`finalize()` の戻り値 false は throw に変換しない = Stripe には 200 が返る。
-      その行は既に別の世代が持っているので、こちらから再送を促す理由が無い)
+      その行は既に別の世代が持っているので、こちらから再送を促す理由が無い)。
+      **`process()` が成功した場合と失敗した場合の両方**を固定する
+      (失敗経路だけ 500 を返すと契約が非対称になる)
 - [ ] `finalize()` へ `RecoveryPending` を**渡さない**ことを固定する
       (型では閉じていない。回収失敗の据え置きで行が `recovery_pending` にならないことを
       最終状態で assert する)
diff --git a/tests/Feature/Billing/StripeWebhookStaleRecoveryTest.php b/tests/Feature/Billing/StripeWebhookStaleRecoveryTest.php
new file mode 100644
index 0000000..0a556b3
--- /dev/null
+++ b/tests/Feature/Billing/StripeWebhookStaleRecoveryTest.php
@@ -0,0 +1,568 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\PlanPriceKind;
+use App\Enums\Billing\WebhookEventStatus;
+use App\Enums\Billing\WebhookRecoveryReason;
+use App\Models\Billing\Plan;
+use App\Models\Billing\StripeWebhookEvent;
+use App\Models\Billing\TicketCheckoutSession;
+use App\Models\Organization;
+use App\Models\User;
+use App\Services\Billing\StripeWebhookProcessor;
+use App\Services\Billing\TicketLedgerService;
+use App\Support\Legal\BillingRetention;
+use Carbon\CarbonImmutable;
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\QueryException;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Schema;
+use Laravel\Cashier\Events\WebhookReceived;
+use Webmozart\Assert\Assert;
+
+/*
+ * 滞留 webhook の回収 (StripeWebhookProcessor::recoverStale) と、
+ * 受理した世代を握っている実行だけが行う終局書き込み (finalize の条件付き UPDATE)。
+ *
+ * 背景: claim() が直列化するのは状態遷移だけで process() はトランザクションの外にある。
+ * そこで落ちた行は received のまま残り、Stripe の再送は claim() に弾かれて 200 で終わるため、
+ * 決済済みチケットの付与が無音で失われる。
+ */
+
+/**
+ * stripe_id を持つ組織と owner を作る。
+ *
+ * @return array{Organization, User}
+ */
+function staleRecoveryFixture(): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    $organization->stripe_id = 'cus_stale_recovery_1';
+    $organization->save();
+
+    return [$organization, $owner];
+}
+
+/** standard プランの現行 base Price の Stripe Price ID。 */
+function staleRecoveryBasePriceId(): string
+{
+    $price = Plan::query()->where('code', 'standard')->firstOrFail()
+        ->currentPrice(PlanPriceKind::Base);
+    Assert::notNull($price, 'standard プランの current base price が未 seed');
+
+    return $price->stripe_price_id;
+}
+
+/**
+ * @return array<string, mixed>
+ */
+function staleRecoveryInvoicePaidPayload(string $eventId, string $invoiceId = 'in_stale_1'): array
+{
+    return [
+        'id' => $eventId,
+        'type' => 'invoice.paid',
+        'data' => [
+            'object' => [
+                'id' => $invoiceId,
+                'customer' => 'cus_stale_recovery_1',
+                'billing_reason' => 'subscription_cycle',
+                'lines' => [
+                    'data' => [
+                        ['price' => ['id' => staleRecoveryBasePriceId()]],
+                    ],
+                ],
+            ],
+        ],
+    ];
+}
+
+/**
+ * @return array<string, mixed>
+ */
+function staleRecoveryTicketPurchasePayload(string $eventId, Organization $organization): array
+{
+    return [
+        'id' => $eventId,
+        'type' => 'checkout.session.completed',
+        'data' => [
+            'object' => [
+                'id' => 'cs_stale_1',
+                'mode' => 'payment',
+                'customer' => 'cus_stale_recovery_1',
+                'payment_status' => 'paid',
+                'payment_intent' => 'pi_stale_1',
+                'amount_subtotal' => 30 * 80,
+                'currency' => 'jpy',
+                'metadata' => [
+                    'purpose' => 'ticket_purchase',
+                    'org_ref' => (string) $organization->id,
+                    'count' => '30',
+                ],
+            ],
+        ],
+    ];
+}
+
+/**
+ * @return array<string, mixed>
+ */
+function staleRecoverySubscriptionPayload(string $eventId, string $type = 'customer.subscription.updated'): array
+{
+    return [
+        'id' => $eventId,
+        'type' => $type,
+        'data' => [
+            'object' => [
+                'id' => 'sub_stale_1',
+                'customer' => 'cus_stale_recovery_1',
+                'status' => 'active',
+                'items' => [
+                    'data' => [
+                        ['price' => ['id' => staleRecoveryBasePriceId()]],
+                    ],
+                ],
+            ],
+        ],
+    ];
+}
+
+/**
+ * received のまま滞留している記録を作る。
+ *
+ * Eloquent は保存時に updated_at を now へ書き換えるため、保存後に明示的に押し戻す
+ * (Factory の state だけでは滞留行にならない)。
+ *
+ * @param  array<mixed>  $payload
+ */
+function staleWebhookRecord(
+    string $eventId,
+    string $type,
+    array $payload,
+    int $attempts = 0,
+    int $minutesAgo = 60,
+): StripeWebhookEvent {
+    $record = StripeWebhookEvent::factory()->stale($minutesAgo)->create([
+        'event_id' => $eventId,
+        'type' => $type,
+        'payload' => $payload,
+        'attempts' => $attempts,
+    ]);
+
+    pushBackWebhookUpdatedAt($eventId, $minutesAgo);
+
+    return $record->refresh();
+}
+
+/** updated_at を過去へ押し戻す (滞留判定を跨がせる)。 */
+function pushBackWebhookUpdatedAt(string $eventId, int $minutesAgo): void
+{
+    StripeWebhookEvent::query()
+        ->where('event_id', $eventId)
+        ->update(['updated_at' => CarbonImmutable::now()->subMinutes($minutesAgo)]);
+}
+
+/** 台帳の不変条件: recovery_reason が非 NULL ⟺ status = recovery_pending。 */
+function assertRecoveryReasonInvariant(): void
+{
+    expect(StripeWebhookEvent::query()
+        ->whereNotNull('recovery_reason')
+        ->where('status', '!=', WebhookEventStatus::RecoveryPending->value)
+        ->count())->toBe(0);
+    expect(StripeWebhookEvent::query()
+        ->where('status', WebhookEventStatus::RecoveryPending->value)
+        ->whereNull('recovery_reason')
+        ->count())->toBe(0);
+}
+
+test('滞留した checkout.session.completed は回収で付与され processed になる', function (): void {
+    [$organization, $owner] = staleRecoveryFixture();
+    TicketCheckoutSession::factory()
+        ->forOrganization($organization)
+        ->initiatedBy($owner)
+        ->create([
+            'ticket_count' => 30,
+            'unit_amount' => 80,
+            'currency' => 'jpy',
+            'stripe_session_id' => 'cs_stale_1',
+        ]);
+    staleWebhookRecord(
+        'evt_stale_purchase',
+        'checkout.session.completed',
+        staleRecoveryTicketPurchasePayload('evt_stale_purchase', $organization),
+    );
+
+    $result = app(StripeWebhookProcessor::class)->recoverStale();
+
+    expect($result->replayed)->toBe(1);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(30);
+
+    $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_purchase')->firstOrFail();
+    expect($record->status)->toBe(WebhookEventStatus::Processed);
+    expect($record->processed_at)->not->toBeNull();
+    expect($record->recovery_reason)->toBeNull();
+    assertRecoveryReasonInvariant();
+});
+
+test('滞留した invoice.paid は回収で月次付与される', function (): void {
+    [$organization] = staleRecoveryFixture();
+    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
+    staleWebhookRecord(
+        'evt_stale_invoice',
+        'invoice.paid',
+        staleRecoveryInvoicePaidPayload('evt_stale_invoice'),
+    );
+
+    $result = app(StripeWebhookProcessor::class)->recoverStale();
+
+    expect($result->replayed)->toBe(1);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(100);
+    expect(StripeWebhookEvent::query()->where('event_id', 'evt_stale_invoice')->firstOrFail()->status)
+        ->toBe(WebhookEventStatus::Processed);
+});
+
+test('回収で付与した後に Stripe 再送が来ても二重付与しない', function (): void {
+    [$organization, $owner] = staleRecoveryFixture();
+    TicketCheckoutSession::factory()
+        ->forOrganization($organization)
+        ->initiatedBy($owner)
+        ->create([
+            'ticket_count' => 30,
+            'unit_amount' => 80,
+            'currency' => 'jpy',
+            'stripe_session_id' => 'cs_stale_1',
+        ]);
+    staleWebhookRecord(
+        'evt_stale_purchase',
+        'checkout.session.completed',
+        staleRecoveryTicketPurchasePayload('evt_stale_purchase', $organization),
+    );
+
+    app(StripeWebhookProcessor::class)->recoverStale();
+    // 別 event_id での再通知 (event_id 冪等では防げない経路)
+    event(new WebhookReceived(staleRecoveryTicketPurchasePayload('evt_resend_purchase', $organization)));
+
+    expect($organization->ticketLedgerEntries()->where('idempotency_key', 'purchase:cs_stale_1')->count())
+        ->toBe(1);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(30);
+});
+
+test('順序に依存する種類の滞留は再実行せず回収待ちへ置く', function (): void {
+    [$organization] = staleRecoveryFixture();
+    expect($organization->plan_code)->toBeNull();
+    staleWebhookRecord(
+        'evt_stale_sub',
+        'customer.subscription.updated',
+        staleRecoverySubscriptionPayload('evt_stale_sub'),
+    );
+
+    $result = app(StripeWebhookProcessor::class)->recoverStale();
+
+    expect($result->movedToRecoveryPending)->toBe(1);
+    expect($result->replayed)->toBe(0);
+
+    $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_sub')->firstOrFail();
+    expect($record->status)->toBe(WebhookEventStatus::RecoveryPending);
+    expect($record->recovery_reason)->toBe(WebhookRecoveryReason::OrderSensitive);
+    // 状態は書き換わっていない (再実行していない)
+    expect($organization->refresh()->plan_code)->toBeNull();
+
+    // 回収待ちの行に Stripe 再送が来ても claim() が受理しない (状態が巻き戻らない)
+    event(new WebhookReceived(staleRecoverySubscriptionPayload('evt_stale_sub')));
+
+    expect($organization->refresh()->plan_code)->toBeNull();
+    expect(StripeWebhookEvent::query()->where('event_id', 'evt_stale_sub')->firstOrFail()->status)
+        ->toBe(WebhookEventStatus::RecoveryPending);
+    assertRecoveryReasonInvariant();
+});
+
+test('試行上限に到達した滞留は再実行せず回収待ちへ置く', function (): void {
+    [$organization] = staleRecoveryFixture();
+    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
+    staleWebhookRecord(
+        'evt_stale_exhausted',
+        'invoice.paid',
+        staleRecoveryInvoicePaidPayload('evt_stale_exhausted'),
+        attempts: StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS,
+    );
+
+    $result = app(StripeWebhookProcessor::class)->recoverStale();
+
+    expect($result->movedToRecoveryPending)->toBe(1);
+    $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_exhausted')->firstOrFail();
+    expect($record->status)->toBe(WebhookEventStatus::RecoveryPending);
+    expect($record->recovery_reason)->toBe(WebhookRecoveryReason::AttemptsExhausted);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
+    assertRecoveryReasonInvariant();
+});
+
+test('本アプリが処理しない種類の滞留は通常経路と同じく processed になる', function (): void {
+    [$organization] = staleRecoveryFixture();
+    staleWebhookRecord('evt_stale_unhandled', 'customer.updated', [
+        'id' => 'evt_stale_unhandled',
+        'type' => 'customer.updated',
+        'data' => ['object' => ['id' => 'cus_stale_recovery_1']],
+    ]);
+
+    $result = app(StripeWebhookProcessor::class)->recoverStale();
+
+    expect($result->replayed)->toBe(1);
+    expect($result->movedToRecoveryPending)->toBe(0);
+    $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_unhandled')->firstOrFail();
+    expect($record->status)->toBe(WebhookEventStatus::Processed);
+    expect($record->recovery_reason)->toBeNull();
+    // 副作用が何も起きない
+    expect($organization->ticketLedgerEntries()->count())->toBe(0);
+    expect($organization->refresh()->plan_code)->toBeNull();
+});
+
+test('本アプリが処理しない種類は試行上限に到達していても processed になる', function (): void {
+    staleRecoveryFixture();
+    staleWebhookRecord('evt_stale_unhandled_max', 'customer.updated', [
+        'id' => 'evt_stale_unhandled_max',
+        'type' => 'customer.updated',
+        'data' => ['object' => ['id' => 'cus_stale_recovery_1']],
+    ], attempts: StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS);
+
+    app(StripeWebhookProcessor::class)->recoverStale();
+
+    expect(StripeWebhookEvent::query()->where('event_id', 'evt_stale_unhandled_max')->firstOrFail()->status)
+        ->toBe(WebhookEventStatus::Processed);
+});
+
+test('滞留の閾値内の received 行には触らない', function (): void {
+    [$organization] = staleRecoveryFixture();
+    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
+    staleWebhookRecord(
+        'evt_fresh',
+        'invoice.paid',
+        staleRecoveryInvoicePaidPayload('evt_fresh'),
+        minutesAgo: 5,
+    );
+
+    $result = app(StripeWebhookProcessor::class)->recoverStale();
+
+    expect($result->replayed)->toBe(0);
+    expect($result->skipped)->toBe(0);
+    $record = StripeWebhookEvent::query()->where('event_id', 'evt_fresh')->firstOrFail();
+    expect($record->status)->toBe(WebhookEventStatus::Received);
+    expect($record->attempts)->toBe(0);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
+});
+
+test('回収の再実行が失敗しても終局させず次回の回収へ回す (最後は試行上限で止まる)', function (): void {
+    staleRecoveryFixture();
+    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
+    $this->mock(TicketLedgerService::class)
+        ->shouldReceive('grantMonthly')
+        ->andThrow(new RuntimeException('付与処理の一時故障'));
+
+    staleWebhookRecord(
+        'evt_stale_retry',
+        'invoice.paid',
+        staleRecoveryInvoicePaidPayload('evt_stale_retry'),
+    );
+
+    $result = app(StripeWebhookProcessor::class)->recoverStale();
+
+    expect($result->retryScheduled)->toBe(1);
+    $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_retry')->firstOrFail();
+    expect($record->status)->toBe(WebhookEventStatus::Received); // 終局させない
+    expect($record->failure_reason)->toBe('付与処理の一時故障');
+    expect($record->attempts)->toBe(1);
+
+    // 閾値を再び超えさせて繰り返すと attempts が上限まで進み、最後は回収待ちで止まる
+    for ($i = 0; $i < StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS + 1; $i++) {
+        pushBackWebhookUpdatedAt('evt_stale_retry', 60);
+        app(StripeWebhookProcessor::class)->recoverStale();
+    }
+
+    $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_retry')->firstOrFail();
+    expect($record->status)->toBe(WebhookEventStatus::RecoveryPending);
+    expect($record->recovery_reason)->toBe(WebhookRecoveryReason::AttemptsExhausted);
+    expect($record->attempts)->toBe(StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS);
+    assertRecoveryReasonInvariant();
+});
+
+test('回収中に別の実行が世代を進めたら件数は skipped に計上する', function (): void {
+    staleRecoveryFixture();
+    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
+    $this->mock(TicketLedgerService::class)
+        ->shouldReceive('grantMonthly')
+        ->andReturnUsing(function (): void {
+            // 別の実行が世代を進めた状況 (単一プロセスで追い越しだけを再現する)
+            StripeWebhookEvent::query()->where('event_id', 'evt_stale_overtaken')->update(['attempts' => 5]);
+
+            throw new RuntimeException('付与処理の一時故障');
+        });
+
+    staleWebhookRecord(
+        'evt_stale_overtaken',
+        'invoice.paid',
+        staleRecoveryInvoicePaidPayload('evt_stale_overtaken'),
+    );
+
+    $result = app(StripeWebhookProcessor::class)->recoverStale();
+
+    expect($result->skipped)->toBe(1);
+    expect($result->retryScheduled)->toBe(0);
+    $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_overtaken')->firstOrFail();
+    expect($record->attempts)->toBe(5); // 追い越した側の値が残る
+    expect($record->failure_reason)->toBeNull(); // 旧世代は何も書かない
+});
+
+test('HTTP 経路で世代を追い越されたら終局書き込みを見送り例外も投げない', function (): void {
+    [$organization] = staleRecoveryFixture();
+    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
+    $this->mock(TicketLedgerService::class)
+        ->shouldReceive('grantMonthly')
+        ->andReturnUsing(function (): void {
+            StripeWebhookEvent::query()->where('event_id', 'evt_overtaken_http')->update(['attempts' => 5]);
+        });
+
+    // 例外を投げない = Cashier が 200 を返す (行は既に別の世代が持っている)
+    event(new WebhookReceived(staleRecoveryInvoicePaidPayload('evt_overtaken_http')));
+
+    $record = StripeWebhookEvent::query()->where('event_id', 'evt_overtaken_http')->firstOrFail();
+    expect($record->status)->toBe(WebhookEventStatus::Received); // processed にならない
+    expect($record->attempts)->toBe(5);
+    expect($record->processed_at)->toBeNull();
+    // 回収経路の据え置きと違い recovery_pending にはしない
+    expect($record->recovery_reason)->toBeNull();
+    expect($organization->refresh()->plan_code)->toBeNull();
+});
+
+test('HTTP 経路で世代を追い越されたら処理が失敗しても例外を投げない', function (): void {
+    [$organization] = staleRecoveryFixture();
+    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
+    $this->mock(TicketLedgerService::class)
+        ->shouldReceive('grantMonthly')
+        ->andReturnUsing(function (): void {
+            StripeWebhookEvent::query()->where('event_id', 'evt_overtaken_http_fail')->update(['attempts' => 5]);
+
+            throw new RuntimeException('付与処理の一時故障');
+        });
+
+    // 行は既に別の世代が持っているので、失敗しても Stripe の再送を促さない (200 で終わる)
+    event(new WebhookReceived(staleRecoveryInvoicePaidPayload('evt_overtaken_http_fail')));
+
+    $record = StripeWebhookEvent::query()->where('event_id', 'evt_overtaken_http_fail')->firstOrFail();
+    expect($record->status)->toBe(WebhookEventStatus::Received); // failed にもならない
+    expect($record->attempts)->toBe(5);
+    expect($record->failure_reason)->toBeNull();
+    expect($organization->refresh()->plan_code)->toBeNull();
+});
+
+test('回収の件数は処置と一致する (replayed / movedToRecoveryPending / skipped)', function (): void {
+    [$organization] = staleRecoveryFixture();
+    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
+
+    staleWebhookRecord('evt_count_replay', 'invoice.paid', staleRecoveryInvoicePaidPayload('evt_count_replay'));
+    staleWebhookRecord(
+        'evt_count_pending',
+        'customer.subscription.updated',
+        staleRecoverySubscriptionPayload('evt_count_pending'),
+    );
+    staleWebhookRecord('evt_count_fresh', 'invoice.paid', staleRecoveryInvoicePaidPayload('evt_count_fresh', 'in_fresh'), minutesAgo: 5);
+
+    $result = app(StripeWebhookProcessor::class)->recoverStale();
+
+    expect($result->replayed)->toBe(1);
+    expect($result->movedToRecoveryPending)->toBe(1);
+    expect($result->retryScheduled)->toBe(0);
+    expect($result->skipped)->toBe(0);
+    expect($organization->ticketLedgerEntries()->where('idempotency_key', 'monthly:in_stale_1')->count())->toBe(1);
+    assertRecoveryReasonInvariant();
+});
+
+test('cron コマンドが滞留を回収し 4 件数を出力する', function (): void {
+    [$organization] = staleRecoveryFixture();
+    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
+    staleWebhookRecord('evt_cron', 'invoice.paid', staleRecoveryInvoicePaidPayload('evt_cron'));
+
+    $this->artisan('billing:recover-stale-webhook-events')
+        ->expectsOutputToContain('replayed 1 / retry-scheduled 0 / moved-to-recovery-pending 0 / skipped 0')
+        ->assertExitCode(0);
+
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(100);
+});
+
+test('滞留判定の閾値は整数で設定されている', function (): void {
+    expect(config()->integer('billing.webhook_stale_after_minutes'))->toBeGreaterThan(0);
+});
+
+test('recovery_reason は recovery_pending 以外の行に入れられない (DB CHECK)', function (): void {
+    if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)) {
+        $this->markTestSkipped('CHECK 制約は pgsql/mysql のみ (sqlite は ALTER ADD CONSTRAINT 非対応)');
+    }
+
+    StripeWebhookEvent::factory()->create(['event_id' => 'evt_check_1']);
+
+    expect(fn () => StripeWebhookEvent::query()
+        ->where('event_id', 'evt_check_1')
+        ->update(['recovery_reason' => WebhookRecoveryReason::OrderSensitive->value]))
+        ->toThrow(QueryException::class);
+});
+
+test('recovery_pending の行から recovery_reason を外せない (DB CHECK)', function (): void {
+    if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)) {
+        $this->markTestSkipped('CHECK 制約は pgsql/mysql のみ (sqlite は ALTER ADD CONSTRAINT 非対応)');
+    }
+
+    StripeWebhookEvent::factory()
+        ->recoveryPending(WebhookRecoveryReason::AttemptsExhausted)
+        ->create(['event_id' => 'evt_check_2']);
+
+    expect(fn () => StripeWebhookEvent::query()
+        ->where('event_id', 'evt_check_2')
+        ->update(['recovery_reason' => null]))
+        ->toThrow(QueryException::class);
+});
+
+test('保持期限を超えても回収待ち・滞留 received の行は purge が消さない', function (): void {
+    // 起算点 (processed_at) が NULL の行は「異常として計上するだけで消さない」契約
+    $expired = BillingRetention::threshold()->subSecond();
+    StripeWebhookEvent::factory()
+        ->recoveryPending(WebhookRecoveryReason::OrderSensitive)
+        ->create(['event_id' => 'evt_purge_pending', 'created_at' => $expired]);
+    StripeWebhookEvent::factory()
+        ->stale()
+        ->create(['event_id' => 'evt_purge_stale', 'created_at' => $expired]);
+
+    $this->artisan('billing:purge-retention-expired', ['--apply' => true])
+        ->expectsOutputToContain('stripe_webhook_event: expired=0 processed=0 fail_closed=2')
+        ->assertExitCode(0);
+
+    expect(StripeWebhookEvent::query()->where('event_id', 'evt_purge_pending')->exists())->toBeTrue();
+    expect(StripeWebhookEvent::query()->where('event_id', 'evt_purge_stale')->exists())->toBeTrue();
+});
+
+test('新しい状態と理由は表示名を持つ', function (): void {
+    expect(WebhookEventStatus::RecoveryPending->label())->toBe('回収待ち');
+    foreach (WebhookRecoveryReason::cases() as $reason) {
+        expect($reason->label())->not->toBe('');
+    }
+});
+
+test('migration の down() で CHECK 制約・index・列が落ち、再適用できる', function (): void {
+    $migration = require database_path(
+        'migrations/2026_08_15_000100_add_recovery_reason_to_stripe_webhook_events_table.php'
+    );
+    expect($migration)->toBeInstanceOf(Migration::class);
+
+    try {
+        $migration->down();
+
+        expect(Schema::hasColumn('stripe_webhook_events', 'recovery_reason'))->toBeFalse();
+        expect(Schema::hasIndex('stripe_webhook_events', 'stripe_webhook_events_status_updated_at_index'))
+            ->toBeFalse();
+    } finally {
+        // assert が落ちても schema を必ず戻す (同一プロセスの後続テストへ破損を残さない)。
+        // 再適用が通ること自体が「CHECK 制約 (同名) も確かに落ちている」ことの証明になる。
+        $migration->up();
+    }
+
+    expect(Schema::hasColumn('stripe_webhook_events', 'recovery_reason'))->toBeTrue();
+    expect(Schema::hasIndex('stripe_webhook_events', 'stripe_webhook_events_status_updated_at_index'))
+        ->toBeTrue();
+});
```

## 再検証の結果

- `composer test`: 4590 tests / 4588 passed / 0 failed / 2 skipped
- `composer phpstan` (level 10): No errors
- `vendor/bin/pint --test`: passed
- フロント側の差分は無いため他レーンは Round 1 と同じ (すべて green)

Round 1 の 3 指摘はすべて対応済みである。追加で見落としがあれば指摘し、
無ければ全体判定を APPROVED で明示せよ。
