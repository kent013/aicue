Round 1 の指摘への対応が終わった。対応マトリクスと修正差分を示すので再レビューしてほしい。

# 対応マトリクス: impl-review Round 1

## [Warning] 施策 6 の中核テストが足りない (チケット予約の並行競合・述語再評価)

- 判断: 対応する
- 根拠: 指摘のとおり。行ロック下の述語再評価が今回の主眼で、実装だけあってテストが無いのは
  「テストなしの実装完了報告」にあたる (禁止事項 1)。
- 対応内容: `tests/Feature/Billing/TicketCommitWinsTest.php` に 2 本追加した。
  - 「候補列挙後に commit された予約は Skipped で、回収は成功のまま終わる」
    (`candidateIds` で id を取る → `commit()` → `recover()` の順で再現。例外を投げないことも見る)
  - 「候補列挙後に expires_at が延長された予約は解放されない」
    (述語が不成立になった行を名指しで回収しても `Skipped` で、status が Reserved のまま)

## [Warning] 施策 7 の「4 つの結果の種類がコマンド出力に現れる」テストが不足

- 判断: 対応する
- 根拠: 旧語彙で監視していた運用者が新語彙で探せることは docs の対応表だけでは固定できない。
  出力そのものを見るテストが要る。
- 対応内容: `tests/Feature/Billing/StripeWebhookStaleRecoveryTest.php` に 3 本追加した。
  コマンド (`work:recover-stuck --stream=webhook_event --apply`) の出力 1 行を完全一致で見て、
  `replayed → recovered` / `moved-to-recovery-pending → escalated` /
  `retry-scheduled → deferred` (かつ `errors=0` のまま) / 世代を追い越された回収 → `skipped`
  の 4 つを固定した。

## [Suggestion] `possibleOutcomes` の検査が「空でない」だけで弱い

- 判断: 対応する (ただし Codex の言う exact-fit そのものは採らない)
- 根拠: 「各系列の申告が期待集合と一致する」形にすると、期待集合を書く場所が目録しか無く
  同語反復になる (目録が目録と一致する、としか言えない)。代わりに**目録の外側から効く 2 つ**を足した。
- 対応内容: `StuckWorkRecoveryInventoryTest` に 2 本追加した。
  - 全系列が `Recovered` と `Skipped` を必ず申告する
    (回収の系列である以上この 2 つは必ず起こりうる。起こりえないなら回収ではない)
  - 申告の合併が `RecoveryOutcome` の全 case を覆う
    (どの系列も返さない値を enum に残さない = 死んだ語彙を作らない)
  併せて「申告は申告であって、実際にその種類を返しうるかは各系列の Feature テストが担う」ことを
  検査の中に明記した (保証範囲を誇張しない)。

## [Suggestion] `staleEventIds()` は主キーを返すのに `event_id` と読める

- 判断: 対応する
- 根拠: このクラスでは `event_id` が Stripe 側の識別子として重要語彙であり、
  同じ語で主キーを指すのは将来の誤読を招く。名前は役割を示すべきである。
- 対応内容: `staleEventIds` → `staleRecordIds` へ改名した
  (`StripeWebhookProcessor` / `StaleWebhookEventStream` / `DirectFetchInventory` の根拠文)。


---

## 修正差分 (Round 1 の指摘に対応した箇所のみ)

```diff
diff --git a/app/Services/Billing/StripeWebhookProcessor.php b/app/Services/Billing/StripeWebhookProcessor.php
index 23c6a0e..1f31905 100644
--- a/app/Services/Billing/StripeWebhookProcessor.php
+++ b/app/Services/Billing/StripeWebhookProcessor.php
@@ -5,7 +5,6 @@
 namespace App\Services\Billing;
 
 use App\DataTransferObjects\Billing\StaleWebhookClaimDto;
-use App\DataTransferObjects\Billing\WebhookRecoveryResultDto;
 use App\Enums\Billing\BillingNotificationType;
 use App\Enums\Billing\HandledStripeWebhookEvent;
 use App\Enums\Billing\SignupFundingChoice;
@@ -16,6 +15,7 @@
 use App\Enums\Billing\WebhookStaleClaimOutcome;
 use App\Enums\CheckoutIntent;
 use App\Enums\CheckoutSessionStatus;
+use App\Enums\Recovery\RecoveryOutcome;
 use App\Jobs\Billing\HandleAutoRechargeChargeFailureJob;
 use App\Jobs\Billing\ReuseSubscriptionPaymentMethodJob;
 use App\Jobs\Billing\SetDefaultPaymentMethodJob;
@@ -28,6 +28,7 @@
 use App\Models\Organization;
 use App\Notifications\Billing\PaymentFailedNotification;
 use Carbon\CarbonImmutable;
+use Illuminate\Database\Eloquent\Builder;
 use Illuminate\Support\Facades\DB;
 use Illuminate\Support\Facades\Log;
 use Laravel\Cashier\Events\WebhookReceived;
@@ -55,7 +56,7 @@
  *    MAX_PROCESSING_ATTEMPTS 到達後は処理せず skip (= 200 terminal-ack) して
  *    恒久失敗イベントの無限 500 ストームを打ち切る (運用は failure_reason で調査する)
  * 5. 滞留回収: 本処理中にプロセスが落ちて received のまま残った行を
- *    recoverStale() が拾い直す (cron: billing:recover-stale-webhook-events)。
+ *    recoverStuckEvent() が拾い直す (定期実行: work:recover-stuck --stream=webhook_event)。
  *    再実行してよい種類かは HandledStripeWebhookEvent::replaySafety() が決め、
  *    対象外・上限到達は recovery_pending + recovery_reason へ置いて止める。
  *    終局書き込みは受理した世代 (attempts) を握っている実行だけが行う条件付き UPDATE。
@@ -84,7 +85,7 @@ class StripeWebhookProcessor
      * **`claim()` の直列化は本処理までは覆わない** (守るのは状態遷移だけで `process()` は
      * トランザクションの外で走る)。そこで落ちた行は `received` のまま残り、Stripe の再送も
      * `claim()` に弾かれて 200 で終わるため付与が無音で失われる。これを塞ぐのが
-     * `recoverStale()` である。運用契約の正本は `docs/architecture.md`
+     * `recoverStuckEvent()` である。運用契約の正本は `docs/architecture.md`
      * の「Stripe webhook の滞留回収」。
      *
      * Stripe の自動再送窓 (~3 日) に対し 8 回で十分。
@@ -192,78 +193,83 @@ private function finalize(
     }
 
     /**
-     * 処理中に滞留した webhook 記録の回収 (cron: billing:recover-stale-webhook-events)。
+     * 滞留した webhook 記録 1 件の回収 (定期実行: work:recover-stuck --stream=webhook_event)。
      *
-     * 対象は `status=received` かつ `updated_at` が滞留の閾値より古い行**だけ**。
-     * `failed` は Stripe の再送が再試行の駆動者なので拾わない。
-     *
-     * 作法は既存の滞留回収 (`RenderJobService::recoverStale` /
-     * `TicketLedgerService::releaseStale`) と同じ = 対象を列挙 → 1 件ずつ行ロックで
-     * 取り直して再検証 → 件数を返す。**共通の回収基盤は作らない** (ドメインごとの個別実装)。
+     * **掃引 (候補の列挙とループ) は滞留回収の共通基盤が持つ**ので、本メソッドは 1 件だけを
+     * 受け持つ。判断材料と決着の規則は従来どおり:
+     *   - 再実行してよいかは HandledStripeWebhookEvent::replaySafety() だけが決める
+     *   - 回収の失敗は終局させない (received のまま次回へ回す = Deferred)
+     *   - 対象外・試行上限は recovery_pending へ置いて止める (= Escalated)
      *
      * 通知 (`Log::warning` / `report()`) は**トランザクションの外**で出す
      * (状態が保存されていないのに通知だけ出る / 同じ行に複数回出るのを避ける)。
      * ただし commit 後に落ちれば 0 回になる = 送信を 1 回試みるだけで、
      * 厳密な一回配送は保証しない (常設の観測点は `recovery_pending` の件数のほう)。
+     *
+     * @param  positive-int  $id  滞留回収の候補列挙 (StaleWebhookEventStream::candidateIds) が返した主キー
      */
-    public function recoverStale(): WebhookRecoveryResultDto
+    public function recoverStuckEvent(int $id, CarbonImmutable $sweptAt): RecoveryOutcome
     {
-        $threshold = CarbonImmutable::now()
-            ->subMinutes(config()->integer('billing.webhook_stale_after_minutes'));
+        $threshold = self::staleThreshold($sweptAt);
 
-        /** @var list<string> $staleEventIds */
-        $staleEventIds = StripeWebhookEvent::query()
-            ->where('status', WebhookEventStatus::Received->value)
-            ->where('updated_at', '<=', $threshold)
-            ->orderBy('id')
-            ->pluck('event_id')
-            ->all();
+        $claim = $this->claimStale($id, $threshold);
+        if ($claim === null) {
+            return RecoveryOutcome::Skipped; // 行が消えた / 別の実行が先に進めた
+        }
 
-        $replayed = 0;
-        $retryScheduled = 0;
-        $movedToRecoveryPending = 0;
-        $skipped = 0;
+        if ($claim->outcome === WebhookStaleClaimOutcome::MovedToRecoveryPending) {
+            $this->reportRecoveryPending($claim);
 
-        foreach ($staleEventIds as $eventId) {
-            $claim = $this->claimStale($eventId, $threshold);
-            if ($claim === null) {
-                $skipped++; // 行が消えた / 別の実行が先に進めた
+            return RecoveryOutcome::Escalated;
+        }
 
-                continue;
-            }
+        try {
+            $this->process($claim->type, $claim->payload);
+        } catch (Throwable $exception) {
+            report($exception);
 
-            if ($claim->outcome === WebhookStaleClaimOutcome::MovedToRecoveryPending) {
-                $movedToRecoveryPending++;
-                $this->reportRecoveryPending($claim);
+            // **終局させない**: failed にすると回収対象 (received) から外れ、
+            // Stripe も配信成功と認識しているため二度と再試行されない。
+            // received のまま失敗理由だけ書いて次回の回収へ回す (attempts は消費済み)。
+            return $this->finalize($claim->eventId, $claim->attempts, WebhookEventStatus::Received, $exception->getMessage())
+                ? RecoveryOutcome::Deferred
+                : RecoveryOutcome::Skipped;
+        }
 
-                continue;
-            }
+        return $this->finalize($claim->eventId, $claim->attempts, WebhookEventStatus::Processed, null)
+            ? RecoveryOutcome::Recovered
+            : RecoveryOutcome::Skipped;
+    }
 
-            try {
-                $this->process($claim->type, $claim->payload);
-            } catch (Throwable $exception) {
-                report($exception);
-                // **終局させない**: failed にすると回収対象 (received) から外れ、
-                // Stripe も配信成功と認識しているため二度と再試行されない。
-                // received のまま失敗理由だけ書いて次回の回収へ回す (attempts は消費済み)。
-                $this->finalize($claim->eventId, $claim->attempts, WebhookEventStatus::Received, $exception->getMessage())
-                    ? $retryScheduled++
-                    : $skipped++;
-
-                continue;
-            }
+    /**
+     * 滞留候補の主キーを昇順で返す (回収の候補列挙)。
+     *
+     * 対象は `status=received` かつ `updated_at` が滞留の閾値より古い行**だけ**。
+     * `failed` は Stripe の再送が再試行の駆動者なので拾わない。
+     *
+     * @param  positive-int|null  $afterId
+     * @param  positive-int  $pageSize
+     * @return list<positive-int>
+     */
+    public function staleRecordIds(CarbonImmutable $sweptAt, ?int $afterId, int $pageSize): array
+    {
+        /** @var list<positive-int> $ids */
+        $ids = StripeWebhookEvent::query()
+            ->where('status', WebhookEventStatus::Received->value)
+            ->where('updated_at', '<=', self::staleThreshold($sweptAt))
+            ->when($afterId !== null, fn (Builder $query) => $query->where('id', '>', $afterId))
+            ->orderBy('id')
+            ->limit($pageSize)
+            ->pluck('id')
+            ->all();
 
-            $this->finalize($claim->eventId, $claim->attempts, WebhookEventStatus::Processed, null)
-                ? $replayed++
-                : $skipped++;
-        }
+        return $ids;
+    }
 
-        return new WebhookRecoveryResultDto(
-            replayed: $replayed,
-            retryScheduled: $retryScheduled,
-            movedToRecoveryPending: $movedToRecoveryPending,
-            skipped: $skipped,
-        );
+    /** 滞留とみなす境界時刻 (候補列挙と受理で同じ式を使う) */
+    private static function staleThreshold(CarbonImmutable $sweptAt): CarbonImmutable
+    {
+        return $sweptAt->subMinutes(config()->integer('billing.webhook_stale_after_minutes'));
     }
 
     /**
@@ -276,13 +282,14 @@ public function recoverStale(): WebhookRecoveryResultDto
      * 滞留の再検証は**クエリの WHERE に入れる** (ロック取得後に PostgreSQL が述語を
      * 再評価するため、ロック待ちの間に他の実行が前進させた行は 1 行も返らない)。
      *
+     * @param  positive-int  $id  滞留回収の候補列挙 (staleRecordIds) が返した主キー
      * @return StaleWebhookClaimDto|null 処置をしなかったとき (行が無い / 条件を満たさない) は null
      */
-    private function claimStale(string $eventId, CarbonImmutable $threshold): ?StaleWebhookClaimDto
+    private function claimStale(int $id, CarbonImmutable $threshold): ?StaleWebhookClaimDto
     {
-        return DB::transaction(function () use ($eventId, $threshold): ?StaleWebhookClaimDto {
+        return DB::transaction(function () use ($id, $threshold): ?StaleWebhookClaimDto {
             $record = StripeWebhookEvent::query()
-                ->where('event_id', $eventId)
+                ->whereKey($id)
                 ->where('status', WebhookEventStatus::Received->value)
                 ->where('updated_at', '<=', $threshold)
                 ->lockForUpdate()
diff --git a/app/Services/Recovery/Streams/StaleWebhookEventStream.php b/app/Services/Recovery/Streams/StaleWebhookEventStream.php
new file mode 100644
index 0000000..b1a8588
--- /dev/null
+++ b/app/Services/Recovery/Streams/StaleWebhookEventStream.php
@@ -0,0 +1,44 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Recovery\Streams;
+
+use App\Contracts\Recovery\StuckWorkStream;
+use App\Enums\Recovery\RecoveryOutcome;
+use App\Enums\Recovery\RecoveryStream;
+use App\Services\Billing\StripeWebhookProcessor;
+use Carbon\CarbonImmutable;
+
+/**
+ * 本処理中にプロセスが落ちて received のまま残った Stripe webhook 記録。
+ *
+ * 放置すると Stripe の再送は受理側に弾かれて 200 で終わり、Stripe 側も配信成功と
+ * 判断して再送を打ち切るため、決済済みチケットの付与が**無音で失われる**。
+ *
+ * 5 値のうち 4 値を使う唯一の stream である (Recovered / Deferred / Escalated / Skipped)。
+ */
+final readonly class StaleWebhookEventStream implements StuckWorkStream
+{
+    public function __construct(private StripeWebhookProcessor $webhooks) {}
+
+    public function stream(): RecoveryStream
+    {
+        return RecoveryStream::WebhookEvent;
+    }
+
+    public function candidateIds(CarbonImmutable $sweptAt, ?int $afterId, int $pageSize): array
+    {
+        return $this->webhooks->staleRecordIds($sweptAt, $afterId, $pageSize);
+    }
+
+    public function recover(int $id, CarbonImmutable $sweptAt): RecoveryOutcome
+    {
+        return $this->webhooks->recoverStuckEvent($id, $sweptAt);
+    }
+
+    public function sweepItemLimit(): ?int
+    {
+        return null;
+    }
+}
diff --git a/tests/Architecture/StuckWorkRecoveryInventoryTest.php b/tests/Architecture/StuckWorkRecoveryInventoryTest.php
new file mode 100644
index 0000000..3bb4324
--- /dev/null
+++ b/tests/Architecture/StuckWorkRecoveryInventoryTest.php
@@ -0,0 +1,292 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Recovery\RecoveryOutcome;
+use App\Enums\Recovery\RecoveryStream;
+use App\Services\Recovery\StuckWorkStreamRegistry;
+use Illuminate\Console\Scheduling\Event;
+use Illuminate\Support\Facades\Exceptions;
+use Illuminate\Support\Facades\Schedule;
+use Tests\Support\Recovery\NonRecoveryScheduleEntry;
+use Tests\Support\Recovery\RecoveryStreamEntry;
+use Tests\Support\Recovery\StuckWorkRecoveryInventory;
+
+/*
+ * 滞留回収の目録 (deny-by-default / exact-fit)。
+ *
+ * 本 gate が固定すること:
+ * 1. registry の系列集合 == RecoveryStream の全 case == 目録の申告集合
+ * 2. Schedule に載る work:recover-stuck --stream=<key> の集合が系列のキーと一致する
+ *    (突き合わせは**コマンド名ではなく系列のキー**で行う。全部が同じコマンド名のため)
+ * 3. 各系列の Schedule が --apply / onOneServer / withoutOverlapping / onFailure の 4 点と
+ *    目録の実行間隔を持ち、多重起動抑止の有効期限が既定 (24 時間) ではないこと
+ *    (**--apply の付け忘れは無音で回収を全面停止させるため、この検査が本 gate の主目的**)
+ * 4. 各系列の sweepItemLimit() が目録の申告値と一致する
+ * 5. 各系列が取りうる結果の種類を目録で申告している
+ * 6. Schedule に載っている全コマンドが、上の回収の入口か NonRecoveryScheduleEntry
+ *    (区分 + 30 文字以上の理由) のどちらかに属する (未分類は fail)
+ *
+ * **保証しないもの (誇張しない)**:
+ * - 目録は申告の集合一致を見るだけで、recover() が実際に行ロック下で述語を再評価しているかは
+ *   検査できない (それは各系列の Feature テストが担う)
+ * - Schedule の検査は**登録内容**を見るだけで、定期実行の仕組みが実際に動いているかは
+ *   検査できない (運用側の監視対象)
+ */
+
+/** Schedule に登録された全イベント */
+function recoveryScheduledEvents(): array
+{
+    return array_values(Schedule::events());
+}
+
+/** イベントのコマンド文字列から artisan のコマンド名と引数部分を取り出す */
+function recoveryCommandLine(Event $event): string
+{
+    $command = (string) $event->command;
+    // "'/usr/bin/php' 'artisan' foo:bar --baz" の形から artisan 以降だけを残す
+    $position = strpos($command, "'artisan'");
+
+    return $position === false ? $command : trim(substr($command, $position + strlen("'artisan'")));
+}
+
+/** コマンド行の先頭 (引数を除いたコマンド名。artisan の引用符も外す) */
+function recoveryCommandName(Event $event): string
+{
+    $first = explode(' ', trim(recoveryCommandLine($event)))[0];
+
+    return trim($first, "'\"");
+}
+
+/** work:recover-stuck の登録だけを系列キー => Event で返す */
+function recoveryStreamEvents(): array
+{
+    $events = [];
+    foreach (recoveryScheduledEvents() as $event) {
+        if (recoveryCommandName($event) !== StuckWorkRecoveryInventory::RECOVERY_COMMAND) {
+            continue;
+        }
+        $line = recoveryCommandLine($event);
+        if (preg_match('/--stream=([a-z_]+)/', $line, $matches) !== 1) {
+            continue;
+        }
+        $events[$matches[1]][] = $event;
+    }
+
+    return $events;
+}
+
+test('registry の系列集合と RecoveryStream の全 case と目録の申告集合が一致する', function (): void {
+    $cases = array_map(static fn (RecoveryStream $stream): string => $stream->value, RecoveryStream::cases());
+    sort($cases);
+
+    $registered = array_map(
+        static fn (object $stream): string => $stream->stream()->value,
+        app(StuckWorkStreamRegistry::class)->all(),
+    );
+    sort($registered);
+
+    $declared = array_keys(StuckWorkRecoveryInventory::streams());
+    sort($declared);
+
+    expect($registered)->toBe($cases, 'registry の登録が RecoveryStream の case と一致していません');
+    expect($declared)->toBe($cases,
+        '滞留回収の目録 (StuckWorkRecoveryInventory::streams) に未登録の系列があります。'
+        .'系列を増やしたら目録・registry・Schedule の 3 つを同時に更新してください。');
+});
+
+test('目録の申告する実装クラスが registry の解決結果と一致する', function (): void {
+    $registry = app(StuckWorkStreamRegistry::class);
+    $violations = [];
+
+    foreach (StuckWorkRecoveryInventory::streams() as $key => $entry) {
+        $resolved = $registry->get($entry->stream);
+        if ($resolved::class !== $entry->implementation) {
+            $violations[] = $key.' — 目録: '.$entry->implementation.' / 実際: '.$resolved::class;
+        }
+    }
+
+    expect($violations)->toBe([], '目録の implementation が registry の解決結果と違います。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('各系列の 1 掃引の上限が目録の申告値と一致する', function (): void {
+    $registry = app(StuckWorkStreamRegistry::class);
+    $violations = [];
+
+    foreach (StuckWorkRecoveryInventory::streams() as $key => $entry) {
+        $actual = $registry->get($entry->stream)->sweepItemLimit();
+        if ($actual !== $entry->sweepItemLimit) {
+            $violations[] = $key.' — 目録: '.var_export($entry->sweepItemLimit, true).' / 実際: '.var_export($actual, true);
+        }
+    }
+
+    expect($violations)->toBe([], '1 掃引の上限が目録と食い違っています (上限を変えたら目録も変える)。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('各系列が取りうる結果の種類を目録で申告し、説明を持つ', function (): void {
+    foreach (StuckWorkRecoveryInventory::streams() as $key => $entry) {
+        expect($entry->possibleOutcomes)->not->toBe([], $key);
+        expect(mb_strlen($entry->description))
+            ->toBeGreaterThanOrEqual(RecoveryStreamEntry::DESCRIPTION_MIN_LENGTH, $key);
+    }
+});
+
+test('全系列が「前へ進めた」と「競合で何もしなかった」を必ず申告する', function (): void {
+    // 回収の系列である以上、この 2 つは必ず起こりうる (起こりえないなら回収ではない)。
+    // これを落とすと申告が「実際に取りうる種類」ではなく飾りになる
+    $violations = [];
+    foreach (StuckWorkRecoveryInventory::streams() as $key => $entry) {
+        foreach ([RecoveryOutcome::Recovered, RecoveryOutcome::Skipped] as $required) {
+            if (! in_array($required, $entry->possibleOutcomes, true)) {
+                $violations[] = $key.' — '.$required->value.' を申告していない';
+            }
+        }
+    }
+
+    expect($violations)->toBe([],
+        '回収の系列である以上「前へ進めた」と「競合で何もしなかった」は必ず起こりうる。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('申告された結果の種類の合併が RecoveryOutcome の全 case を覆う (死んだ値を残さない)', function (): void {
+    $declared = [];
+    foreach (StuckWorkRecoveryInventory::streams() as $entry) {
+        foreach ($entry->possibleOutcomes as $outcome) {
+            $declared[$outcome->value] = true;
+        }
+    }
+    $declared = array_keys($declared);
+    sort($declared);
+
+    $cases = array_map(static fn (RecoveryOutcome $outcome): string => $outcome->value, RecoveryOutcome::cases());
+    sort($cases);
+
+    // ★保証しないもの: 目録は**申告**の集合を見るだけで、各系列の recover() が実際にその種類を
+    //   返しうるかは検査できない (それは各系列の Feature テストが担う)。
+    //   ここで固定するのは「どの系列も返さない結果の種類が enum に残っていない」ことである
+    expect($declared)->toBe($cases,
+        'どの系列も申告していない結果の種類があります (使われない値を enum に残さない)。'
+        .'値を増やすなら、それを返す系列の申告も同時に足してください。');
+});
+
+test('Schedule の work:recover-stuck は系列ごとにちょうど 1 本ずつ登録されている', function (): void {
+    $events = recoveryStreamEvents();
+
+    $keys = array_keys($events);
+    sort($keys);
+    $declared = array_keys(StuckWorkRecoveryInventory::streams());
+    sort($declared);
+
+    expect($keys)->toBe($declared,
+        'Schedule に載っている系列と目録の系列が一致しません '
+        .'(突き合わせはコマンド名ではなく系列のキーで行う。全系列が同じコマンド名のため)。');
+
+    foreach ($events as $key => $registered) {
+        expect($registered)->toHaveCount(1, $key.' の Schedule 登録が 1 本ではありません');
+    }
+});
+
+test('各系列の Schedule が --apply / onOneServer / withoutOverlapping / 実行間隔を持つ', function (): void {
+    $violations = [];
+
+    foreach (recoveryStreamEvents() as $key => $registered) {
+        $event = $registered[0];
+        $line = recoveryCommandLine($event);
+        $stream = RecoveryStream::from($key);
+
+        if (! str_contains($line, '--apply')) {
+            // ここが本 gate の主目的。--apply が落ちると回収は 1 件も実行されないのに
+            // 終了コードも出力も正常に見えるため、無音で全面停止する
+            $violations[] = $key.' — Schedule に --apply が無い (回収が 1 件も実行されない)';
+        }
+        if (! $event->onOneServer) {
+            $violations[] = $key.' — onOneServer() が無い';
+        }
+        if (! $event->withoutOverlapping) {
+            $violations[] = $key.' — withoutOverlapping() が無い';
+        }
+        if ($event->expiresAt !== $stream->overlapExpiryMinutes()) {
+            $violations[] = $key.' — 多重起動抑止の有効期限が '.$stream->overlapExpiryMinutes()
+                .' 分でない (実際: '.$event->expiresAt.' 分)。既定の 1440 分だと'
+                .'異常終了で残ったロックが丸 1 日回収を止める';
+        }
+        $expected = '*/'.$stream->cadenceMinutes().' * * * *';
+        if ($event->expression !== $expected) {
+            $violations[] = $key.' — 実行間隔が目録 (RecoveryStream::cadenceMinutes) と違う: '
+                .$event->expression.' (期待: '.$expected.')';
+        }
+    }
+
+    expect($violations)->toBe([], '滞留回収の Schedule 配線が契約を満たしていません。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('各系列の Schedule 失敗が報告される (onFailure が繋がっている)', function (): void {
+    $violations = [];
+
+    foreach (recoveryStreamEvents() as $key => $registered) {
+        $event = $registered[0];
+        $property = new ReflectionProperty(Event::class, 'afterCallbacks');
+        /** @var list<Closure> $callbacks */
+        $callbacks = $property->getValue($event);
+
+        Exceptions::fake();
+        $event->exitCode = 1;
+        foreach ($callbacks as $callback) {
+            $callback(app());
+        }
+
+        $messages = array_map(
+            static fn (Throwable $exception): string => $exception->getMessage(),
+            Exceptions::reported(),
+        );
+        $matched = array_filter(
+            $messages,
+            static fn (string $message): bool => str_contains($message, 'work:recover-stuck --stream='.$key),
+        );
+        if ($matched === []) {
+            $violations[] = $key.' — 失敗時に報告が出ない (onFailure が繋がっていない)';
+        }
+    }
+
+    expect($violations)->toBe([],
+        '回収が止まったことが無音にならないよう、全系列の Schedule に onFailure → report() を付けてください。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('Schedule の全コマンドが回収の入口か非回収の申告のどちらかに属する (未分類は fail)', function (): void {
+    $declared = StuckWorkRecoveryInventory::nonRecoverySchedules();
+    $unclassified = [];
+    $seen = [];
+
+    foreach (recoveryScheduledEvents() as $event) {
+        $name = recoveryCommandName($event);
+        if ($name === StuckWorkRecoveryInventory::RECOVERY_COMMAND) {
+            continue; // 回収の入口 (上のテスト群が担当)
+        }
+        $seen[$name] = true;
+        if (! array_key_exists($name, $declared)) {
+            $unclassified[] = $name;
+        }
+    }
+
+    expect(array_values(array_unique($unclassified)))->toBe([],
+        '定期実行に未分類のコマンドがあります。滞留回収なら work:recover-stuck の系列として '
+        .'RecoveryStream へ足し、そうでなければ StuckWorkRecoveryInventory::nonRecoverySchedules() へ '
+        .'区分と 30 文字以上の理由付きで登録してください (6 本目の独自回収を素通しで足せない)。'
+        .PHP_EOL.implode(PHP_EOL, $unclassified));
+
+    $stale = array_values(array_diff(array_keys($declared), array_keys($seen)));
+    expect($stale)->toBe([],
+        '非回収の申告に、Schedule へ登録されていないコマンドが残っています (申告を消してください)。'
+        .PHP_EOL.implode(PHP_EOL, $stale));
+});
+
+test('非回収の申告はすべて区分と 30 文字以上の理由を持つ', function (): void {
+    foreach (StuckWorkRecoveryInventory::nonRecoverySchedules() as $name => $entry) {
+        expect(mb_strlen($entry->reason))
+            ->toBeGreaterThanOrEqual(NonRecoveryScheduleEntry::REASON_MIN_LENGTH, $name);
+    }
+});
diff --git a/tests/Feature/Billing/StripeWebhookStaleRecoveryTest.php b/tests/Feature/Billing/StripeWebhookStaleRecoveryTest.php
index 0a556b3..d2550c1 100644
--- a/tests/Feature/Billing/StripeWebhookStaleRecoveryTest.php
+++ b/tests/Feature/Billing/StripeWebhookStaleRecoveryTest.php
@@ -5,6 +5,8 @@
 use App\Enums\Billing\PlanPriceKind;
 use App\Enums\Billing\WebhookEventStatus;
 use App\Enums\Billing\WebhookRecoveryReason;
+use App\Enums\Recovery\RecoveryOutcome;
+use App\Enums\Recovery\RecoveryStream;
 use App\Models\Billing\Plan;
 use App\Models\Billing\StripeWebhookEvent;
 use App\Models\Billing\TicketCheckoutSession;
@@ -16,13 +18,14 @@
 use Carbon\CarbonImmutable;
 use Illuminate\Database\Migrations\Migration;
 use Illuminate\Database\QueryException;
+use Illuminate\Support\Facades\Artisan;
 use Illuminate\Support\Facades\DB;
 use Illuminate\Support\Facades\Schema;
 use Laravel\Cashier\Events\WebhookReceived;
 use Webmozart\Assert\Assert;
 
 /*
- * 滞留 webhook の回収 (StripeWebhookProcessor::recoverStale) と、
+ * 滞留 webhook の回収 (StripeWebhookProcessor::recoverStuckEvent) と、
  * 受理した世代を握っている実行だけが行う終局書き込み (finalize の条件付き UPDATE)。
  *
  * 背景: claim() が直列化するのは状態遷移だけで process() はトランザクションの外にある。
@@ -192,9 +195,9 @@ function assertRecoveryReasonInvariant(): void
         staleRecoveryTicketPurchasePayload('evt_stale_purchase', $organization),
     );
 
-    $result = app(StripeWebhookProcessor::class)->recoverStale();
+    $result = sweepStuckWorkStream(RecoveryStream::WebhookEvent);
 
-    expect($result->replayed)->toBe(1);
+    expect($result->count(RecoveryOutcome::Recovered))->toBe(1);
     expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(30);
 
     $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_purchase')->firstOrFail();
@@ -213,9 +216,9 @@ function assertRecoveryReasonInvariant(): void
         staleRecoveryInvoicePaidPayload('evt_stale_invoice'),
     );
 
-    $result = app(StripeWebhookProcessor::class)->recoverStale();
+    $result = sweepStuckWorkStream(RecoveryStream::WebhookEvent);
 
-    expect($result->replayed)->toBe(1);
+    expect($result->count(RecoveryOutcome::Recovered))->toBe(1);
     expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(100);
     expect(StripeWebhookEvent::query()->where('event_id', 'evt_stale_invoice')->firstOrFail()->status)
         ->toBe(WebhookEventStatus::Processed);
@@ -238,7 +241,7 @@ function assertRecoveryReasonInvariant(): void
         staleRecoveryTicketPurchasePayload('evt_stale_purchase', $organization),
     );
 
-    app(StripeWebhookProcessor::class)->recoverStale();
+    sweepStuckWorkStream(RecoveryStream::WebhookEvent);
     // 別 event_id での再通知 (event_id 冪等では防げない経路)
     event(new WebhookReceived(staleRecoveryTicketPurchasePayload('evt_resend_purchase', $organization)));
 
@@ -256,10 +259,10 @@ function assertRecoveryReasonInvariant(): void
         staleRecoverySubscriptionPayload('evt_stale_sub'),
     );
 
-    $result = app(StripeWebhookProcessor::class)->recoverStale();
+    $result = sweepStuckWorkStream(RecoveryStream::WebhookEvent);
 
-    expect($result->movedToRecoveryPending)->toBe(1);
-    expect($result->replayed)->toBe(0);
+    expect($result->count(RecoveryOutcome::Escalated))->toBe(1);
+    expect($result->count(RecoveryOutcome::Recovered))->toBe(0);
 
     $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_sub')->firstOrFail();
     expect($record->status)->toBe(WebhookEventStatus::RecoveryPending);
@@ -286,9 +289,9 @@ function assertRecoveryReasonInvariant(): void
         attempts: StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS,
     );
 
-    $result = app(StripeWebhookProcessor::class)->recoverStale();
+    $result = sweepStuckWorkStream(RecoveryStream::WebhookEvent);
 
-    expect($result->movedToRecoveryPending)->toBe(1);
+    expect($result->count(RecoveryOutcome::Escalated))->toBe(1);
     $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_exhausted')->firstOrFail();
     expect($record->status)->toBe(WebhookEventStatus::RecoveryPending);
     expect($record->recovery_reason)->toBe(WebhookRecoveryReason::AttemptsExhausted);
@@ -304,10 +307,10 @@ function assertRecoveryReasonInvariant(): void
         'data' => ['object' => ['id' => 'cus_stale_recovery_1']],
     ]);
 
-    $result = app(StripeWebhookProcessor::class)->recoverStale();
+    $result = sweepStuckWorkStream(RecoveryStream::WebhookEvent);
 
-    expect($result->replayed)->toBe(1);
-    expect($result->movedToRecoveryPending)->toBe(0);
+    expect($result->count(RecoveryOutcome::Recovered))->toBe(1);
+    expect($result->count(RecoveryOutcome::Escalated))->toBe(0);
     $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_unhandled')->firstOrFail();
     expect($record->status)->toBe(WebhookEventStatus::Processed);
     expect($record->recovery_reason)->toBeNull();
@@ -324,7 +327,7 @@ function assertRecoveryReasonInvariant(): void
         'data' => ['object' => ['id' => 'cus_stale_recovery_1']],
     ], attempts: StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS);
 
-    app(StripeWebhookProcessor::class)->recoverStale();
+    sweepStuckWorkStream(RecoveryStream::WebhookEvent);
 
     expect(StripeWebhookEvent::query()->where('event_id', 'evt_stale_unhandled_max')->firstOrFail()->status)
         ->toBe(WebhookEventStatus::Processed);
@@ -340,10 +343,10 @@ function assertRecoveryReasonInvariant(): void
         minutesAgo: 5,
     );
 
-    $result = app(StripeWebhookProcessor::class)->recoverStale();
+    $result = sweepStuckWorkStream(RecoveryStream::WebhookEvent);
 
-    expect($result->replayed)->toBe(0);
-    expect($result->skipped)->toBe(0);
+    expect($result->count(RecoveryOutcome::Recovered))->toBe(0);
+    expect($result->count(RecoveryOutcome::Skipped))->toBe(0);
     $record = StripeWebhookEvent::query()->where('event_id', 'evt_fresh')->firstOrFail();
     expect($record->status)->toBe(WebhookEventStatus::Received);
     expect($record->attempts)->toBe(0);
@@ -363,9 +366,9 @@ function assertRecoveryReasonInvariant(): void
         staleRecoveryInvoicePaidPayload('evt_stale_retry'),
     );
 
-    $result = app(StripeWebhookProcessor::class)->recoverStale();
+    $result = sweepStuckWorkStream(RecoveryStream::WebhookEvent);
 
-    expect($result->retryScheduled)->toBe(1);
+    expect($result->count(RecoveryOutcome::Deferred))->toBe(1);
     $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_retry')->firstOrFail();
     expect($record->status)->toBe(WebhookEventStatus::Received); // 終局させない
     expect($record->failure_reason)->toBe('付与処理の一時故障');
@@ -374,7 +377,7 @@ function assertRecoveryReasonInvariant(): void
     // 閾値を再び超えさせて繰り返すと attempts が上限まで進み、最後は回収待ちで止まる
     for ($i = 0; $i < StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS + 1; $i++) {
         pushBackWebhookUpdatedAt('evt_stale_retry', 60);
-        app(StripeWebhookProcessor::class)->recoverStale();
+        sweepStuckWorkStream(RecoveryStream::WebhookEvent);
     }
 
     $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_retry')->firstOrFail();
@@ -402,10 +405,10 @@ function assertRecoveryReasonInvariant(): void
         staleRecoveryInvoicePaidPayload('evt_stale_overtaken'),
     );
 
-    $result = app(StripeWebhookProcessor::class)->recoverStale();
+    $result = sweepStuckWorkStream(RecoveryStream::WebhookEvent);
 
-    expect($result->skipped)->toBe(1);
-    expect($result->retryScheduled)->toBe(0);
+    expect($result->count(RecoveryOutcome::Skipped))->toBe(1);
+    expect($result->count(RecoveryOutcome::Deferred))->toBe(0);
     $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_overtaken')->firstOrFail();
     expect($record->attempts)->toBe(5); // 追い越した側の値が残る
     expect($record->failure_reason)->toBeNull(); // 旧世代は何も書かない
@@ -453,7 +456,7 @@ function assertRecoveryReasonInvariant(): void
     expect($organization->refresh()->plan_code)->toBeNull();
 });
 
-test('回収の件数は処置と一致する (replayed / movedToRecoveryPending / skipped)', function (): void {
+test('回収の件数は処置と一致する (recovered / escalated / deferred / skipped)', function (): void {
     [$organization] = staleRecoveryFixture();
     Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
 
@@ -465,23 +468,25 @@ function assertRecoveryReasonInvariant(): void
     );
     staleWebhookRecord('evt_count_fresh', 'invoice.paid', staleRecoveryInvoicePaidPayload('evt_count_fresh', 'in_fresh'), minutesAgo: 5);
 
-    $result = app(StripeWebhookProcessor::class)->recoverStale();
+    $result = sweepStuckWorkStream(RecoveryStream::WebhookEvent);
 
-    expect($result->replayed)->toBe(1);
-    expect($result->movedToRecoveryPending)->toBe(1);
-    expect($result->retryScheduled)->toBe(0);
-    expect($result->skipped)->toBe(0);
+    expect($result->count(RecoveryOutcome::Recovered))->toBe(1);
+    expect($result->count(RecoveryOutcome::Escalated))->toBe(1);
+    expect($result->count(RecoveryOutcome::Deferred))->toBe(0);
+    expect($result->count(RecoveryOutcome::Skipped))->toBe(0);
     expect($organization->ticketLedgerEntries()->where('idempotency_key', 'monthly:in_stale_1')->count())->toBe(1);
     assertRecoveryReasonInvariant();
 });
 
-test('cron コマンドが滞留を回収し 4 件数を出力する', function (): void {
+test('定期実行のコマンドが滞留を回収し結果の種類ごとの件数を出力する', function (): void {
     [$organization] = staleRecoveryFixture();
     Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
     staleWebhookRecord('evt_cron', 'invoice.paid', staleRecoveryInvoicePaidPayload('evt_cron'));
 
-    $this->artisan('billing:recover-stale-webhook-events')
-        ->expectsOutputToContain('replayed 1 / retry-scheduled 0 / moved-to-recovery-pending 0 / skipped 0')
+    $this->artisan('work:recover-stuck --stream=webhook_event --apply')
+        ->expectsOutputToContain(
+            'webhook_event: mode=apply candidates=1 recovered=1 cleanup-failed=0 skipped=0 deferred=0 escalated=0 errors=0 limit-reached=no',
+        )
         ->assertExitCode(0);
 
     expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(100);
@@ -566,3 +571,62 @@ function assertRecoveryReasonInvariant(): void
     expect(Schema::hasIndex('stripe_webhook_events', 'stripe_webhook_events_status_updated_at_index'))
         ->toBeTrue();
 });
+
+/*
+ * 旧語彙 (replayed / retry-scheduled / moved-to-recovery-pending / skipped) から
+ * 新語彙 (recovered / deferred / escalated / skipped) への対応が、
+ * **コマンドの出力**に現れることの behavioral な固定 (docs/architecture.md の対応表と 1 対 1)。
+ */
+
+test('コマンド出力で replayed は recovered に、moved-to-recovery-pending は escalated になる', function (): void {
+    staleRecoveryFixture();
+    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
+    staleWebhookRecord('evt_vocab_replay', 'invoice.paid', staleRecoveryInvoicePaidPayload('evt_vocab_replay'));
+    staleWebhookRecord(
+        'evt_vocab_pending',
+        'customer.subscription.updated',
+        staleRecoverySubscriptionPayload('evt_vocab_pending'),
+    );
+
+    Artisan::call('work:recover-stuck', ['--stream' => 'webhook_event', '--apply' => true]);
+
+    expect(Artisan::output())->toContain(
+        'webhook_event: mode=apply candidates=2 recovered=1 cleanup-failed=0 skipped=0 deferred=0 escalated=1 errors=0 limit-reached=no',
+    );
+});
+
+test('コマンド出力で retry-scheduled は deferred になる (errors には出ない)', function (): void {
+    staleRecoveryFixture();
+    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
+    $this->mock(TicketLedgerService::class)
+        ->shouldReceive('grantMonthly')
+        ->andThrow(new RuntimeException('付与処理の一時故障'));
+    staleWebhookRecord('evt_vocab_defer', 'invoice.paid', staleRecoveryInvoicePaidPayload('evt_vocab_defer'));
+
+    Artisan::call('work:recover-stuck', ['--stream' => 'webhook_event', '--apply' => true]);
+
+    // 失敗を行に書き戻して次回へ回すため errors=0 のまま deferred だけが増える
+    // (deferred が errors に出ない = 独立した監視対象である、という運用契約)
+    expect(Artisan::output())->toContain(
+        'webhook_event: mode=apply candidates=1 recovered=0 cleanup-failed=0 skipped=0 deferred=1 escalated=0 errors=0 limit-reached=no',
+    );
+});
+
+test('コマンド出力で世代を追い越された回収は skipped になる', function (): void {
+    staleRecoveryFixture();
+    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
+    $this->mock(TicketLedgerService::class)
+        ->shouldReceive('grantMonthly')
+        ->andReturnUsing(function (): void {
+            StripeWebhookEvent::query()->where('event_id', 'evt_vocab_skip')->update(['attempts' => 5]);
+
+            throw new RuntimeException('付与処理の一時故障');
+        });
+    staleWebhookRecord('evt_vocab_skip', 'invoice.paid', staleRecoveryInvoicePaidPayload('evt_vocab_skip'));
+
+    Artisan::call('work:recover-stuck', ['--stream' => 'webhook_event', '--apply' => true]);
+
+    expect(Artisan::output())->toContain(
+        'webhook_event: mode=apply candidates=1 recovered=0 cleanup-failed=0 skipped=1 deferred=0 escalated=0 errors=0 limit-reached=no',
+    );
+});
diff --git a/tests/Feature/Billing/TicketCommitWinsTest.php b/tests/Feature/Billing/TicketCommitWinsTest.php
index 9160bed..73e061e 100644
--- a/tests/Feature/Billing/TicketCommitWinsTest.php
+++ b/tests/Feature/Billing/TicketCommitWinsTest.php
@@ -5,8 +5,11 @@
 use App\Enums\Billing\TicketCommitResult;
 use App\Enums\Billing\TicketLedgerKind;
 use App\Enums\Billing\TicketReservationStatus;
+use App\Enums\Recovery\RecoveryOutcome;
 use App\Models\Billing\TicketLedgerEntry;
+use App\Models\Billing\TicketReservation;
 use App\Services\Billing\TicketLedgerService;
+use App\Services\Recovery\Streams\ExpiredTicketReservationStream;
 use Carbon\CarbonImmutable;
 
 /*
@@ -27,7 +30,7 @@ function commitWinsService(): TicketLedgerService
 
     $reservation = commitWinsService()->reserve($organization, 3);
     $this->travel(31)->minutes();
-    commitWinsService()->releaseStale();
+    releaseStaleTicketReservations();
     expect($reservation->refresh()->status)->toBe(TicketReservationStatus::Released);
 
     $result = commitWinsService()->commit($reservation);
@@ -82,7 +85,7 @@ function commitWinsService(): TicketLedgerService
     expect(commitWinsService()->balance($organization)->totalAvailable())->toBe(7);
 });
 
-test('releaseStale は TTL 未超過でも失効 monthly hold を解放する', function (): void {
+test('滞留回収は TTL 未超過でも失効 monthly hold を解放する', function (): void {
     [$organization] = createOrganizationWithOwner();
     // monthly 期限 (10 分後) < reserve TTL (30 分) にして「TTL 切れ」枝と切り分ける
     $expiresAt = CarbonImmutable::now()->addMinutes(10);
@@ -93,6 +96,47 @@ function commitWinsService(): TicketLedgerService
 
     $this->travel(11)->minutes(); // TTL (30 分) は未超過だが monthly hold は失効
 
-    expect(commitWinsService()->releaseStale())->toBe(1);
+    expect(releaseStaleTicketReservations())->toBe(1);
     expect($reservation->refresh()->status)->toBe(TicketReservationStatus::Released);
 });
+
+/*
+ * 滞留回収の並行競合 (T171): 候補列挙とロック取得の間に状態が動いたときの振る舞い。
+ * 行ロック下で滞留の述語ごと再評価するため、競合は例外ではなく Skipped になる。
+ */
+
+test('候補列挙後に commit された予約は Skipped で、回収は成功のまま終わる', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    commitWinsService()->grantPurchased($organization, 10, 'cs_race', 'pi_race', 10000);
+    $reservation = commitWinsService()->reserve($organization, 3);
+    $this->travel(31)->minutes();
+
+    $stream = app(ExpiredTicketReservationStream::class);
+    $sweptAt = CarbonImmutable::now();
+    expect($stream->candidateIds($sweptAt, null, 10))->toBe([$reservation->id]);
+
+    // 別プロセスが先に commit した状況を再現する
+    expect(commitWinsService()->commit($reservation))->toBe(TicketCommitResult::Committed);
+
+    // 例外を投げない = 運用アラートを鳴らさない (正常事象として数える)
+    expect($stream->recover($reservation->id, $sweptAt))->toBe(RecoveryOutcome::Skipped);
+    expect($reservation->refresh()->status)->toBe(TicketReservationStatus::Committed);
+});
+
+test('候補列挙後に expires_at が延長された予約は解放されない', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    commitWinsService()->grantPurchased($organization, 10, 'cs_extend', 'pi_extend', 10000);
+    $reservation = commitWinsService()->reserve($organization, 3);
+    $this->travel(31)->minutes();
+
+    $stream = app(ExpiredTicketReservationStream::class);
+    $sweptAt = CarbonImmutable::now();
+    expect($stream->candidateIds($sweptAt, null, 10))->toBe([$reservation->id]);
+
+    // 述語が成立しなくなる状況 (有効期限の延長) を作る
+    TicketReservation::query()->whereKey($reservation->id)
+        ->update(['expires_at' => $sweptAt->addMinutes(30)]);
+
+    expect($stream->recover($reservation->id, $sweptAt))->toBe(RecoveryOutcome::Skipped);
+    expect($reservation->refresh()->status)->toBe(TicketReservationStatus::Reserved);
+});
diff --git a/tests/Support/Security/DirectFetchInventory.php b/tests/Support/Security/DirectFetchInventory.php
index 3d7a0ae..6fa9b65 100644
--- a/tests/Support/Security/DirectFetchInventory.php
+++ b/tests/Support/Security/DirectFetchInventory.php
@@ -4,6 +4,7 @@
 
 namespace Tests\Support\Security;
 
+use App\Enums\Security\RecoveryFetchShape;
 use Illuminate\Database\Eloquent\Model;
 use ReflectionClass;
 use SplFileInfo;
@@ -291,23 +292,49 @@ public static function inventory(): array
                 .'掛けると JOIN 先までロックするため、単一テーブルの主キーロックに落としている',
             ),
 
-            // --- 同一メソッド内の走査クエリ由来 (保守処理) ---
-            'Services/Billing/TicketLedgerService.php#releaseStale#TicketReservation.whereKey:$id#1' => DirectFetchJustificationEntry::sameMethodQuery(
-                'id は同一メソッドが status / expires_at で列挙した TicketReservation の主キー。'
-                .'期限切れ予約の解放は全テナント横断の保守処理であり cron から呼ばれる (HTTP 入力を経由しない)',
-            ),
-            'Services/Capture/StaleUploadReservationSweeper.php#sweep#TakeUploadReservation.whereKey:$reservation->id#1' => DirectFetchJustificationEntry::sameMethodQuery(
-                'id は同一メソッドが status / expires_at で列挙した予約行の主キー。孤児オブジェクト回収は'
-                .'全テナント横断の保守処理で cron から呼ばれる。whereKey は CAS 更新の対象行指定に使っている',
-            ),
-            'Services/Manual/AnalysisJobService.php#recoverStale#AnalysisJob.whereKey:$id#1' => DirectFetchJustificationEntry::sameMethodQuery(
-                'id は同一メソッドが status / 経過時間で列挙した AnalysisJob の主キー。'
-                .'stale ジョブの回復は全テナント横断の保守処理で cron から呼ばれる (HTTP 入力を経由しない)',
-            ),
-            'Services/Manual/RenderJobService.php#recoverStale#RenderJob.whereKey:$id#1' => DirectFetchJustificationEntry::sameMethodQuery(
-                'id は同一メソッドが status / 経過時間で列挙した RenderJob の主キー。'
-                .'stale ジョブの回復は全テナント横断の保守処理で cron から呼ばれる (HTTP 入力を経由しない)',
+            // --- 滞留回収の候補列挙が返した主キー (aicue:T171 で新設した分類) ---
+            'Services/Manual/AnalysisJobService.php#lockStaleJob#AnalysisJob.whereKey:$id#1' => DirectFetchJustificationEntry::recoveryCandidate(
+                'id は滞留回収の候補列挙 (staleJobIds) が status / 経過時間で選んだ AnalysisJob の主キー。'
+                .'全テナント横断の保守処理で定期実行から呼ばれ HTTP 入力を経由しない。'
+                .'候補列挙と同じ述語を WHERE に入れて行ロック下で再評価するため誤回収も起きない',
+                entryPoint: 'App\Services\Manual\AnalysisJobService::failStaleJob',
+                stream: 'analysis_job',
+                shape: RecoveryFetchShape::DomainService,
+            ),
+            'Services/Manual/RenderJobService.php#lockStaleJob#RenderJob.whereKey:$id#1' => DirectFetchJustificationEntry::recoveryCandidate(
+                'id は滞留回収の候補列挙 (staleJobIds) が status / 経過時間で選んだ RenderJob の主キー。'
+                .'全テナント横断の保守処理で定期実行から呼ばれ HTTP 入力を経由しない。'
+                .'投入待ちと実行中で閾値が分かれるが述語は 1 か所に集約してある',
+                entryPoint: 'App\Services\Manual\RenderJobService::failStaleJob',
+                stream: 'render_job',
+                shape: RecoveryFetchShape::DomainService,
+            ),
+            'Services/Billing/TicketLedgerService.php#lockExpiredReservation#TicketReservation.whereKey:$id#1' => DirectFetchJustificationEntry::recoveryCandidate(
+                'id は滞留回収の候補列挙 (expiredReservationIds) が選んだ TicketReservation の主キー。'
+                .'期限切れ予約の解放は全テナント横断の保守処理で定期実行から呼ばれる。'
+                .'失効した月次 hold の判定式は会計の一部なので台帳サービスの中に閉じている',
+                entryPoint: 'App\Services\Billing\TicketLedgerService::releaseExpiredReservation',
+                stream: 'ticket_reservation',
+                shape: RecoveryFetchShape::DomainService,
+            ),
+            'Services/Billing/StripeWebhookProcessor.php#claimStale#StripeWebhookEvent.whereKey:$id#1' => DirectFetchJustificationEntry::recoveryCandidate(
+                'id は滞留回収の候補列挙 (staleRecordIds) が status / 経過時間で選んだ通知記録の主キー。'
+                .'受理は行ロック下で滞留の述語を再評価するため、待っている間に他の実行が'
+                .'前へ進めた行は 1 行も返らない。HTTP 入力を経由しない保守処理である',
+                entryPoint: 'App\Services\Billing\StripeWebhookProcessor::recoverStuckEvent',
+                stream: 'webhook_event',
+                shape: RecoveryFetchShape::DomainService,
+            ),
+            'Services/Recovery/Streams/StaleUploadReservationStream.php#releaseIfStillStale#TakeUploadReservation.whereKey:$id#1' => DirectFetchJustificationEntry::recoveryCandidate(
+                'id は同じ系列の候補列挙が status / 期限で選んだアップロード予約の主キー。'
+                .'解放とパスの取得を 1 本の行ロックで済ませており、登録処理が勝った行は'
+                .'述語の再評価で 0 行になる (正当なテイクの実体を消さない)',
+                entryPoint: 'App\Services\Recovery\Streams\StaleUploadReservationStream::recover',
+                stream: 'upload_reservation',
+                shape: RecoveryFetchShape::StreamInternal,
             ),
+
+            // --- 同一メソッド内の走査クエリ由来 (保守処理) ---
             'Services/Manual/RenderJobService.php#reconcileOutputs#RenderJob.whereKey:$id#1' => DirectFetchJustificationEntry::sameMethodQuery(
                 'id は同一メソッドが output_path 非 NULL で列挙した RenderJob の主キー。'
                 .'世代交代済み出力の整合回復は全テナント横断の保守処理で cron から呼ばれる',

```

---

## テスト結果 (修正後)

- `composer test`: 4974 tests, 4972 passed, 2 skipped, 0 failed (20990 assertions)
- `composer phpstan`: No errors (level 10 / 938 files)
- `vendor/bin/pint --test`: passed

Round 1 の 2 件の [Warning] と 2 件の [Suggestion] がすべて解消しているか確認し、
残る指摘があれば [Critical] / [Warning] / [Suggestion] で示したうえで、
最後に**全体判定を APPROVED または CHANGES_REQUESTED** で明示してほしい。
