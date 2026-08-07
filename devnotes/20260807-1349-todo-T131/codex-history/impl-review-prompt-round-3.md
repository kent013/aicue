# impl-review Round 3

Round 2 で残った 2 件の [Warning] に**どちらも対応**しました。
Round 1 の反論のうち 1 件 (cleanup ログの `error`) は**撤回**しています。

---

## 対応マトリクス

# 対応マトリクス: impl-review Round 2

Round 2 は Round 1 の [Critical] 1 件・[Warning] 3 件への対応を提示し、
残った 2 件の [Warning] を受けたラウンド。

## Round 1 由来で **Round 2 で解消** と判定されたもの

| 指摘 | Round 2 での Codex 判定 |
|---|---|
| [Critical] `writeProgress()` が cast を通らない | **指摘なし** (`forceFill()->getAttributes()` は妥当) |
| [Warning] `queue.default` のハードコード | **指摘なし** (ソースの production fallback を固定する方式に合理性あり) |
| [Warning] `docs/architecture.md` の S7 差分が無い | **指摘なし** (実在を確認。AGENTS.md も「S7 は実在し設計を十分反映」) |

## [Warning] cleanup ログの `error` に例外メッセージをそのまま入れている

- 判断: **対応する (Round 1 の反論を撤回する)**
- 根拠: 反論の軸は「PII が入らないこと」だったが、レビュー観点の禁止対象は
  **PII だけでなく「外部 payload をログへ漏らさないこと」**である。
  Stripe SDK の例外メッセージは**外部サービスが生成する可変文字列**であり、
  いま既知の内容 (invoice id / status) だけに留まるという契約はどこにも無い。
  「現状の実装がそうなっている」は将来の安全性の根拠にならない、という指摘は正しい。
  既存 `tryTerminateInvoice()` との一貫性は、**新規経路を安全側へ倒さない理由にはならない**。
- 対応内容:
  - `terminateInvoiceBestEffort()` の `error` に入れる値を
    **`$exception::class` (例外クラス名) だけ**に変更した。
    構造化ログにはアプリが決めた有界な語彙のみを載せる。
  - 失われる原因の詳細は **`report($exception)`** で既存の例外報告経路へ渡す
    (`RenderPipeline` の後始末失敗が `report()` しているのと同じ作法)。
    抑止ログ (`JobOwnershipLostException`) を `report()` しないのは
    「正常だが観測したい事象」だからであり、**invoice 終端の失敗は異常事象**なので
    ここで `report()` することは設計の意図と矛盾しない。
  - **7 キー schema は変えない** (`error` のキー名も維持)。
    値の性質は新設テスト
    `後始末ログの error は例外クラス名のみで、外部由来のメッセージを含まない`
    が固定する (fake の例外メッセージ「fake gateway: …」が混入しないことを検査)。
  - 判断の理由を `terminateInvoiceBestEffort()` の docblock に残した。
  - `docs/architecture.md` の検知手順を「`error` = 例外クラス名。メッセージ本文は
    `report()` 側の例外報告に残る」と更新した。
- 再検証: `composer test -- tests/Feature/Billing/AutoRechargeServiceTest.php` 33 passed /
  `composer phpstan` OK / mutation **M13 / M14 / M15 / M16 / M17 の赤化を再確認**。

## [Warning] `docs/architecture.md`: open invoice (b) の**検知方法**が書かれていない

- 判断: **対応する**
- 根拠: 指摘のとおり。(b) は `stripe_invoice_id` 保存前の死亡なので
  **アプリログにも DB にも痕跡が残らない**。「metadata から逆引きできる」は
  収束手順であって発見手順ではなく、書いた運用契約が実行できない状態だった。
- 対応内容: (a) / (b) を表に分け、**発生条件 / 検知元 / 収束手順**を列にした。
  - (a) 検知元 = アプリログ (`job_ownership_lost_cleanup` かつ `terminated=false`)
  - (b) 検知元 = **Stripe 側を起点にする**。metadata `purpose=auto_recharge` を持つ
    `draft` / `open` invoice を列挙し、`recharge_attempt_ulid` に対応する
    `ticket_auto_recharge_attempts` 行の `stripe_invoice_id` が
    **NULL または別 id** のものを孤児として抽出する。
    収束は「attempt が terminal なら手動 void / attempt が pending なら
    次の `executeAttempt` が同一 idempotency key で同じ invoice に収束するため放置可」。
  - 照合の実施主体 (課金運用担当) と、自動化を今回スコープ外とする理由も明記した。


---

## 修正差分 (1) 実装 + テスト

```diff
diff --git a/app/Services/Billing/AutoRechargeService.php b/app/Services/Billing/AutoRechargeService.php
index 0c09624..65ea410 100644
--- a/app/Services/Billing/AutoRechargeService.php
+++ b/app/Services/Billing/AutoRechargeService.php
@@ -13,6 +13,7 @@
 use App\Enums\Billing\SignupFundingChoice;
 use App\Enums\CheckoutIntent;
 use App\Enums\CheckoutSessionStatus;
+use App\Enums\Security\ExternalCallKind;
 use App\Exceptions\Billing\CheckoutInProgressException;
 use App\Jobs\Billing\ExecuteAutoRechargeAttemptJob;
 use App\Models\Billing\BillingCheckoutSession;
@@ -26,6 +27,7 @@
 use App\Notifications\Billing\AutoRechargeEnabledNotification;
 use App\Notifications\Billing\AutoRechargeFailedNotification;
 use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
+use App\Support\JobExecution\AttemptOwnershipPreflight;
 use Carbon\CarbonImmutable;
 use Illuminate\Contracts\Cache\LockTimeoutException;
 use Illuminate\Database\QueryException;
@@ -58,18 +60,30 @@
 final class AutoRechargeService
 {
     /**
-     * org lock の TTL。updateSettings (cancelPendingAttempts の terminateInvoice) と
-     * executeAttempt (invoice create/pay) の両方が lock 内で外向き Stripe API を呼ぶため、
-     * Stripe client timeout より十分長く統一する (TTL 失効による直列化の破れを防ぐ)。
-     * block 待機は短いまま (競合時は no-op / リコンサイル再試行)。
+     * org 単位 `Cache::lock` の TTL (秒)。updateSettings (cancelPendingAttempts の
+     * terminateInvoice) と executeAttempt (invoice create/pay) の両方が lock 内で外向き
+     * Stripe API を呼ぶため、Stripe client timeout より十分長く統一する
+     * (TTL 失効による直列化の破れを防ぐ)。block 待機は短いまま
+     * (競合時は no-op / リコンサイル再試行)。
+     *
+     * ★これは**入口の排他**であり、結果の一回性を保証しない (裁定 AG-082)。
+     *   保証は (a) 外部呼び出し直前の preflight、(b) `where status=pending` の条件付き UPDATE、
+     *   (c) Stripe idempotency key が担う。
+     * ★したがって値は「保証を代替できる長さ」ではなく**短い側**に倒す。
+     *   `JobExclusionOrderingInvariantTest` が
+     *   `LOCK_TTL_SECONDS < queue.connections.database.retry_after` を CI 固定する
+     *   (鍵の残留が正当な再実行を封鎖する時間が、キューの再配送間隔を超えない)。
+     *   **可視性が public なのは不変条件の契約としての意図的な公開**である
+     *   (T127 で既定キュー接続が分割されたら、上記テストの比較先を差し替えること)。
      */
-    private const int LOCK_TTL_SECONDS = 180;
+    public const int LOCK_TTL_SECONDS = 180;
 
     public function __construct(
         private readonly TicketLedgerService $tickets,
         private readonly TicketPricingService $pricing,
         private readonly AutoRechargeGatewayInterface $gateway,
         private readonly BillingNotificationDispatcher $notifications,
+        private readonly AttemptOwnershipPreflight $preflight,
     ) {}
 
     // ------------------------------------------------------------------
@@ -544,6 +558,12 @@ private function executeAttemptLocked(Organization $organization, TicketAutoRech
 
         $invoiceId = $attempt->stripe_invoice_id;
         if ($invoiceId === null) {
+            // ★ preflight 1: invoice 作成の直前。org lock は TTL 180 秒で切れうるため
+            //   (lock は best-effort。保証は本再検証と条件付き UPDATE と Stripe 冪等キー)。
+            if (! $this->preflight->stillPending($attempt, ExternalCallKind::StripeInvoiceCreate)) {
+                return; // invoice 未作成なので収束は自明 (残す open invoice が無い)
+            }
+
             $invoiceId = $this->gateway->createAutoRechargeInvoice(
                 $organization,
                 $attempt->stripe_price_id,
@@ -551,8 +571,40 @@ private function executeAttemptLocked(Organization $organization, TicketAutoRech
                 $this->metadataFor($organization, $attempt),
                 $keyBase,
             );
+
             // invoice_id の永続化は pay より必ず前 (プロセス死でも迷子 invoice を作らない)。
-            $attempt->forceFill(['stripe_invoice_id' => $invoiceId])->save();
+            // ★ **条件付き UPDATE** にする: 素の save() だと停止側が先に canceled 化した
+            //   terminal 行へ invoice_id を後から書き込むことになり、状態機械の例外を作る。
+            //   0 行なら「attempt へ紐付けられなかった invoice」であり、
+            //   DB の値に依存せずローカルの $invoiceId で終端する。
+            $attached = TicketAutoRechargeAttempt::query()
+                ->whereKey($attempt->id)
+                ->where('status', AutoRechargeAttemptStatus::Pending->value)
+                ->update([
+                    'stripe_invoice_id' => $invoiceId,
+                    'updated_at' => CarbonImmutable::now(),
+                ]);
+
+            if ($attached !== 1) {
+                // ★ attach 失敗は **status を問わず**終端する。
+                //   この invoice ID を知っているのは自分だけであり、
+                //   terminal 化させた側は stripe_invoice_id === null を見ているため終端できない。
+                $this->terminateUnattachedInvoice($attempt->refresh(), $invoiceId);
+
+                return;
+            }
+            // in-memory 同期 (再 save しない)
+            $attempt->forceFill(['stripe_invoice_id' => $invoiceId])->syncOriginal();
+        }
+
+        // ★ preflight 2: pay の直前。**直前に自前の書き込み (invoice_id の永続化) を挟んだため
+        //   必ずもう一度検証する** (裁定 AG-082: 検証の後に自前の書き込みを挟むと、
+        //   接続断で旧担当が送信できる窓が開く)。
+        //   既存 invoice を再利用する経路 (上の if を通らない場合) でもここが唯一の関門になる。
+        if (! $this->preflight->stillPending($attempt, ExternalCallKind::StripeInvoicePay)) {
+            $this->terminateInvoiceAfterOwnershipLost($attempt, $invoiceId);
+
+            return;
         }
 
         $result = $this->gateway->payOffSessionInvoice($invoiceId, $keyBase);
@@ -570,6 +622,97 @@ private function executeAttemptLocked(Organization $organization, TicketAutoRech
         $this->handleChargeFailure($organization, $attempt, $result->failureCode, $result->requiresAction());
     }
 
+    /**
+     * preflight 2 で中断したときの invoice 後始末。
+     *
+     * **canceled のときだけ**終端する:
+     *  - paid  … void できない (付与経路の管轄)
+     *  - failed… `terminateAndFail()` が **`stripe_invoice_id` を DB 経由で見えている状態**で
+     *    終端済み (attach 済みだからこの分岐に来ている)
+     *  - canceled … 停止側の `tryTerminateInvoice()` は `stripe_invoice_id === null` を
+     *    「invoice 未作成」と解釈して素通りするため、こちらの永続化が停止より後だと
+     *    **誰も void しない open invoice が残る**。ここで拾う。
+     *
+     * ★ attach に失敗した invoice は本メソッドではなく `terminateUnattachedInvoice()` の担当
+     *   (あちらは status を問わず終端する)。
+     */
+    private function terminateInvoiceAfterOwnershipLost(
+        TicketAutoRechargeAttempt $attempt,
+        string $invoiceId,
+    ): void {
+        if ($attempt->status !== AutoRechargeAttemptStatus::Canceled) {
+            return; // アーリーリターン
+        }
+
+        $this->terminateInvoiceBestEffort($attempt, $invoiceId);
+    }
+
+    /**
+     * attempt 行へ紐付けられなかった (条件付き UPDATE が 0 行だった) invoice の後始末。
+     *
+     * ★ **status を問わず終端を試みる**。この invoice ID を知っているのは自分だけであり、
+     *   terminal 化させた側は `stripe_invoice_id === null` を見ているため終端できない。
+     *   canceled 限定にすると failed 経路で**誰も終端しない open invoice**が残る。
+     * ★ `paid` の可能性は `CashierAutoRechargeGateway::terminateInvoice()` の状態検査が
+     *   `Assert` で fail-closed に分類する (例外 → `terminated=false` としてログに残る)。
+     */
+    private function terminateUnattachedInvoice(
+        TicketAutoRechargeAttempt $attempt,
+        string $invoiceId,
+    ): void {
+        $this->terminateInvoiceBestEffort($attempt, $invoiceId);
+    }
+
+    /**
+     * invoice の best-effort 終端 + 固定 event 名でのログ (上 2 つの共通部)。
+     *
+     * ★ `$invoiceId` を**引数で受ける**。attempt 行に永続化できなかった invoice も
+     *   終端したいため、DB の値に依存しない。
+     * ★ `tryTerminateInvoice($attempt)` を再利用しない理由: あちらは
+     *   `$attempt->stripe_invoice_id` を読むため「永続化できなかった invoice」を扱えず、
+     *   かつ独自の warning を出すのでログが二重になる。ここは固定 event の 1 行に閉じる。
+     * ★ `CashierAutoRechargeGateway::terminateInvoice()` は Stripe から retrieve して
+     *   void/deleted/404 → 成功扱い、paid → `Assert` で明示的な非成功、draft → delete、
+     *   open/uncollectible → void と**状態検査で冪等化**されている
+     *   (idempotency key より強い — 期限が無い)。
+     * ★ 失敗しても**課金処理へは進まない** (呼び出し側が無条件に return する)。
+     *   残った open invoice は reconcile の母集団外なので、運用契約 (docs/architecture.md) の
+     *   手動収束に委ねる。
+     * ★ **cleanup 専用の event 名**を使う。送信抑止の記録 (`LOG_EVENT`) は最小 7 キー schema を
+     *   持つ契約であり、キー集合の違うログを同じ event 名に混ぜない。
+     * ★ `error` に入れるのは**例外クラス名だけ**である (impl-review Round 2 反映)。
+     *   Stripe SDK の例外メッセージは**外部サービスが生成する可変文字列**であり、
+     *   いま既知の内容が invoice id と status だけでも、将来の SDK / API 応答で
+     *   何が混ざるかの契約は無い。構造化ログには**アプリが決めた有界な語彙**だけを載せ、
+     *   原因の詳細は既存の例外報告経路 (`report()`) に委ねる。
+     */
+    private function terminateInvoiceBestEffort(
+        TicketAutoRechargeAttempt $attempt,
+        string $invoiceId,
+    ): void {
+        $terminated = true;
+        $error = null;
+        try {
+            $this->gateway->terminateInvoice($invoiceId);
+        } catch (Throwable $exception) {
+            $terminated = false;
+            // paid 等の「明示的な非成功」もここに落ちる。分類できる有界な値 (クラス名) のみ記録し、
+            // メッセージ本文は report() 経由の例外報告に残す (外部生成文字列をログ集計へ流さない)。
+            $error = $exception::class;
+            report($exception);
+        }
+
+        Log::warning('auto-recharge: 所有権喪失後の invoice 終端', [
+            'event' => ExternalCallKind::CLEANUP_LOG_EVENT,
+            'job_type' => TicketAutoRechargeAttempt::class,
+            'job_id' => $attempt->id,
+            'attempt_ulid' => $attempt->attempt_ulid,
+            'invoice_id' => $invoiceId,
+            'terminated' => $terminated,
+            'error' => $error,
+        ]);
+    }
+
     /**
      * 課金成功の確定: 冪等付与 + attempt paid 遷移 + failure_count リセット。
      * webhook (invoice.paid) / 同期 pay / リコンサイル (ii) の全経路がここに合流する。
diff --git a/tests/Feature/Billing/AutoRechargeServiceTest.php b/tests/Feature/Billing/AutoRechargeServiceTest.php
index 97133dd..73d3df9 100644
--- a/tests/Feature/Billing/AutoRechargeServiceTest.php
+++ b/tests/Feature/Billing/AutoRechargeServiceTest.php
@@ -7,6 +7,8 @@
 use App\Enums\Billing\AutoRechargeAttemptStatus;
 use App\Enums\Billing\AutoRechargeDisabledReason;
 use App\Enums\Billing\BillingNotificationType;
+use App\Enums\Billing\TicketLedgerKind;
+use App\Enums\Security\ExternalCallKind;
 use App\Models\Billing\BillingNotification;
 use App\Models\Billing\TicketAutoRecharge;
 use App\Models\Billing\TicketAutoRechargeAttempt;
@@ -17,7 +19,10 @@
 use App\Services\Billing\AutoRechargeService;
 use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
 use App\Services\Billing\TicketLedgerService;
+use App\Support\JobExecution\AttemptOwnershipPreflight;
+use Illuminate\Support\Facades\Log;
 use Illuminate\Validation\ValidationException;
+use Tests\Support\FakeAttemptOwnershipPreflight;
 use Tests\Support\FakeAutoRechargeGateway;
 
 /*
@@ -452,3 +457,353 @@ function grantTickets(Organization $organization, int $amount): void
     // hard invariant: 実請求額 (attempt に pin した単価 × 数量) は同意上限を超えない
     expect($attempt->unit_amount * $attempt->quantity)->toBeLessThanOrEqual($consentedAmount);
 });
+
+/*
+ * ─────────────────────────────────────────────────────────────────────
+ * T131 / S4: Stripe 呼び出し直前の所有権再検証 (preflight suppression) と
+ *            中断時の invoice 終端 (裁定 AG-082)
+ *
+ * 配置 (placement) は `FakeAttemptOwnershipPreflight` (競合注入シーム) が固定する。
+ * シームは **verdict を差し替えない** — checkpoint 直前に attempt 行を terminal 化して
+ * `parent::stillPending()` へ委譲するだけなので、refresh / status 判定 /
+ * 所有権喪失ログは常に本番実装が実行する。
+ * ─────────────────────────────────────────────────────────────────────
+ */
+
+/**
+ * 抑止ログ (`job_ownership_lost`) の必須キー集合。
+ *
+ * ★他テストファイルのグローバル定数を参照しない (Pest の --parallel はファイル単位で
+ *   プロセスを分けるため未定義になりうる)。Manual 側 (JobOwnershipLostContextTest) と
+ *   同じ集合をここにも書き、両者が一致していることを人が読める形で残す。
+ *
+ * @var list<string>
+ */
+const AUTO_RECHARGE_OWNERSHIP_LOST_REQUIRED_KEYS = [
+    'event',
+    'job_type',
+    'job_id',
+    'expected_status',
+    'actual_status',
+    'stage',
+    'external_call',
+];
+
+/**
+ * preflight シームを差し込んだ setup (service 解決より前に instance() する)。
+ *
+ * @return array{Organization, User, FakeAutoRechargeGateway, AutoRechargeService, FakeAttemptOwnershipPreflight}
+ */
+function autoRechargePreflightSetup(): array
+{
+    $preflight = new FakeAttemptOwnershipPreflight;
+    app()->instance(AttemptOwnershipPreflight::class, $preflight);
+
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+
+    return [$organization, $owner, $gateway, $service, $preflight];
+}
+
+/** enabled 設定 + pending attempt を 1 件作る (残高 0 = 閾値割れ)。 */
+function autoRechargePendingAttempt(
+    Organization $organization,
+    User $owner,
+    AutoRechargeService $service,
+): TicketAutoRechargeAttempt {
+    $service->updateSettings($organization, $owner, true, 5, 50, new AutoRechargeConsentDto(
+        config()->string('billing.auto_recharge.consent_version'),
+    ));
+
+    $attempt = $service->maybeCreateAttempt($organization);
+    expect($attempt)->not->toBeNull();
+    assert($attempt instanceof TicketAutoRechargeAttempt);
+
+    return $attempt;
+}
+
+test('配置: create の直前に preflight がある (terminalizeAt=create で invoice を作らない)', function (): void {
+    Log::spy();
+    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoiceCreate];
+
+    $service->executeAttempt($attempt);
+
+    // create checkpoint で止まる = pay checkpoint へ到達しない
+    expect($preflight->calls)->toBe([ExternalCallKind::StripeInvoiceCreate->value]);
+    expect($gateway->createdInvoices)->toBe([]);
+    expect($gateway->payCalls)->toBe([]);
+    expect($gateway->terminated)->toBe([]); // 未作成なので終端対象が無い
+    expect($attempt->refresh()->stripe_invoice_id)->toBeNull();
+
+    // 所有権喪失ログ: Manual 側と必須 7 キーが一致し、Billing 固有の追加は attempt_ulid のみ
+    Log::shouldHaveReceived('warning')
+        ->withArgs(function (string $message, array $context) use ($attempt): bool {
+            if (($context['event'] ?? null) !== ExternalCallKind::LOG_EVENT) {
+                return false;
+            }
+            $keys = array_keys($context);
+            sort($keys);
+            $expected = array_merge(AUTO_RECHARGE_OWNERSHIP_LOST_REQUIRED_KEYS, ['attempt_ulid']);
+            sort($expected);
+
+            return $keys === $expected
+                && $context['job_type'] === TicketAutoRechargeAttempt::class
+                && $context['job_id'] === $attempt->id
+                && $context['expected_status'] === 'pending'
+                && $context['actual_status'] === 'canceled'
+                && $context['stage'] === 'execute_attempt'
+                && $context['external_call'] === ExternalCallKind::StripeInvoiceCreate->value
+                && $context['attempt_ulid'] === $attempt->attempt_ulid;
+        })
+        ->once();
+});
+
+test('配置: pay の直前に preflight がある (terminalizeAt=pay で pay せず invoice を終端する)', function (): void {
+    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
+
+    $service->executeAttempt($attempt);
+
+    // preflight 1 は Pending で通過 → create → attach 1 行 → preflight 2 直前に canceled 化
+    expect($preflight->calls)->toBe([
+        ExternalCallKind::StripeInvoiceCreate->value,
+        ExternalCallKind::StripeInvoicePay->value,
+    ]);
+    expect($gateway->createdInvoices)->toHaveCount(1);
+    expect($gateway->payCalls)->toBe([]);
+
+    $attempt->refresh();
+    $invoiceId = $attempt->stripe_invoice_id;
+    expect($invoiceId)->not->toBeNull(); // attach は成功している (DB に残る)
+    // 作成された invoice id で 1 回だけ終端される (Canceled 分岐)
+    expect($gateway->terminated)->toBe([$invoiceId]);
+    expect($attempt->status)->toBe(AutoRechargeAttemptStatus::Canceled);
+});
+
+test('後始末: terminalStatus=failed のとき terminateInvoice を呼ばない (二重終端の抑止)', function (): void {
+    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
+    $preflight->terminalStatus = AutoRechargeAttemptStatus::Failed;
+
+    $service->executeAttempt($attempt);
+
+    // failed へ遷移させた側 (terminateAndFail) が既に終端済みという前提に立つ
+    expect($gateway->terminated)->toBe([]);
+    expect($gateway->payCalls)->toBe([]);
+});
+
+test('後始末: terminalStatus=paid のとき terminateInvoice を呼ばない (void 不可の分類)', function (): void {
+    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
+    $preflight->terminalStatus = AutoRechargeAttemptStatus::Paid;
+
+    $service->executeAttempt($attempt);
+
+    expect($gateway->terminated)->toBe([]);
+    expect($gateway->payCalls)->toBe([]);
+});
+
+test('配置: 行が Pending のままなら create → pay が従来どおり進む (回帰)', function (): void {
+    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $gateway->payAmountPaid = $attempt->unit_amount * $attempt->quantity;
+
+    $service->executeAttempt($attempt);
+
+    // 2 つの checkpoint を**両方**通る
+    expect($preflight->calls)->toBe([
+        ExternalCallKind::StripeInvoiceCreate->value,
+        ExternalCallKind::StripeInvoicePay->value,
+    ]);
+    expect($attempt->refresh()->status)->toBe(AutoRechargeAttemptStatus::Paid);
+    expect($gateway->payCalls)->toHaveCount(1);
+});
+
+test('preflight 2: terminateInvoice が例外を投げても課金処理へ進まない', function (): void {
+    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
+    $gateway->failOnTerminate = true;
+
+    $service->executeAttempt($attempt);
+
+    expect($gateway->payCalls)->toBe([]);
+    expect(TicketLedgerEntry::query()->where('kind', TicketLedgerKind::Grant)->count())->toBe(0);
+});
+
+test('後始末ログは別 event 名 job_ownership_lost_cleanup を使い独自 schema を持つ', function (): void {
+    Log::spy();
+    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
+
+    $service->executeAttempt($attempt);
+
+    Log::shouldHaveReceived('warning')
+        ->withArgs(function (string $message, array $context) use ($attempt): bool {
+            if (($context['event'] ?? null) !== ExternalCallKind::CLEANUP_LOG_EVENT) {
+                return false;
+            }
+            $keys = array_keys($context);
+            sort($keys);
+            $expected = ['attempt_ulid', 'error', 'event', 'invoice_id', 'job_id', 'job_type', 'terminated'];
+
+            return $keys === $expected
+                && $context['terminated'] === true
+                && $context['error'] === null
+                && $context['attempt_ulid'] === $attempt->attempt_ulid;
+        })
+        ->once();
+
+    // 抑止ログと後始末ログが同じ event 名に混ざらない (同一 event = 同一集計 schema)
+    Log::shouldHaveReceived('warning')
+        ->withArgs(fn (string $message, array $context): bool => ($context['event'] ?? null) === ExternalCallKind::LOG_EVENT
+            && ! array_key_exists('invoice_id', $context))
+        ->once();
+});
+
+test('後始末ログの error は例外クラス名のみで、外部由来のメッセージを含まない', function (): void {
+    // Stripe SDK の例外メッセージは外部サービスが生成する可変文字列であり、構造化ログの
+    // 集計語彙へ流さない (原因の詳細は report() 経由の例外報告に残す)。
+    Log::spy();
+    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
+    $gateway->failOnTerminate = true; // メッセージ「fake gateway: invoice 終端失敗」で throw する
+
+    $service->executeAttempt($attempt);
+
+    Log::shouldHaveReceived('warning')
+        ->withArgs(function (string $message, array $context): bool {
+            if (($context['event'] ?? null) !== ExternalCallKind::CLEANUP_LOG_EVENT) {
+                return false;
+            }
+
+            return $context['terminated'] === false
+                && $context['error'] === RuntimeException::class
+                && ! str_contains((string) $context['error'], 'fake gateway');
+        })
+        ->once();
+});
+
+test('attach 0 行: invoice 作成成功と同時に canceled 化 → invoice_id を書かず invoice を終端する', function (): void {
+    // 実 preflight を使う (競合点は gateway の duringCreateInvoice hook が作る)
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $gateway->duringCreateInvoice = function () use ($attempt): void {
+        // Stripe 側の作成は成功したが、返る前に停止側が canceled 化した
+        TicketAutoRechargeAttempt::query()->whereKey($attempt->id)->update([
+            'status' => AutoRechargeAttemptStatus::Canceled->value,
+        ]);
+    };
+
+    $service->executeAttempt($attempt);
+
+    $attempt->refresh();
+    expect($attempt->stripe_invoice_id)->toBeNull();  // DB には書かない
+    expect($gateway->createdInvoices)->toHaveCount(1);
+    // DB に保存済みであることに依存せず、ローカルの invoice id で終端する
+    expect($gateway->terminated)->toHaveCount(1);
+    expect($gateway->payCalls)->toBe([]);
+});
+
+test('attach 0 行: failed へ遷移していた場合も invoice を終端する (status を問わない)', function (): void {
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $gateway->duringCreateInvoice = function () use ($attempt): void {
+        TicketAutoRechargeAttempt::query()->whereKey($attempt->id)->update([
+            'status' => AutoRechargeAttemptStatus::Failed->value,
+        ]);
+    };
+
+    $service->executeAttempt($attempt);
+
+    // failed へ遷移させた側は stripe_invoice_id === null を見ているため終端できない。
+    // ここで終端しないと「誰も終端しない open invoice」が残る
+    expect($gateway->terminated)->toHaveCount(1);
+    expect($attempt->refresh()->stripe_invoice_id)->toBeNull();
+});
+
+test('前提: Failed へ遷移した attempt は invoice が終端済みである', function (): void {
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $attempt->forceFill(['stripe_invoice_id' => 'in_precondition'])->save();
+    $gateway->invoiceStatuses['in_precondition'] = 'open';
+
+    $service->terminateAndFail($organization, $attempt);
+
+    // terminateAndFail は「invoice 終端成功 → failed 遷移」の順序を守る。
+    // この前提が崩れると terminateInvoiceAfterOwnershipLost の Canceled 限定が壊れる
+    expect($attempt->refresh()->status)->toBe(AutoRechargeAttemptStatus::Failed);
+    expect($gateway->terminated)->toBe(['in_precondition']);
+});
+
+test('前提: terminateInvoice が失敗したら attempt は Pending のまま (Failed へ遷移しない)', function (): void {
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $attempt->forceFill(['stripe_invoice_id' => 'in_stuck_precondition'])->save();
+    $gateway->failOnTerminate = true;
+
+    $service->terminateAndFail($organization, $attempt);
+
+    expect($attempt->refresh()->status)->toBe(AutoRechargeAttemptStatus::Pending);
+});
+
+test('冪等キーは 2 本ある: 同一 invoice の付与は台帳 1 件・attempt 遷移も 1 回', function (): void {
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $due = $attempt->unit_amount * $attempt->quantity;
+
+    // 付与の一回性 = 台帳の recharge:{invoiceId} UNIQUE (invoice 単位)
+    // attempt 遷移の一回性 = where status=pending の条件付き UPDATE (attempt 単位)
+    $service->recordSuccessfulCharge($organization, $attempt, 'in_two_keys', $due, $due, 'pi_1');
+    $resolvedAt = $attempt->refresh()->resolved_at;
+    $service->recordSuccessfulCharge($organization, $attempt->fresh(), 'in_two_keys', $due, $due, 'pi_1');
+
+    expect(TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_two_keys')->count())->toBe(1);
+    $attempt->refresh();
+    expect($attempt->status)->toBe(AutoRechargeAttemptStatus::Paid);
+    // 2 回目は 0 行更新 = resolved_at が動かない
+    expect((string) $attempt->resolved_at?->toJSON())->toBe((string) $resolvedAt?->toJSON());
+});
+
+test('Stripe idempotency key は操作ごとに異なり attempt_ulid に pin されている', function (): void {
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $gateway->payAmountPaid = $attempt->unit_amount * $attempt->quantity;
+
+    $service->executeAttempt($attempt);
+
+    // key base は attempt_ulid に pin される (attempt が変われば必ず別キーになる)
+    $expectedBase = "auto-recharge:{$attempt->attempt_ulid}";
+    expect($gateway->createdInvoices[0]['keyBase'])->toBe($expectedBase);
+    expect($gateway->payCalls[0]['keyBase'])->toBe($expectedBase);
+
+    // gateway 実装が組む 4 キーは互いに異なる (同一キーだと Stripe が別操作を replay 扱いする)。
+    // Stripe SDK へ到達させずに固定するため、実装ソースの接尾辞集合を検査する。
+    $source = file_get_contents(app_path('Services/Billing/CashierAutoRechargeGateway.php'));
+    expect($source)->toBeString();
+    $suffixes = ['invoice', 'item', 'finalize', 'pay'];
+    foreach ($suffixes as $suffix) {
+        expect($source)->toContain("{\$idempotencyKeyBase}:{$suffix}");
+    }
+    expect(count(array_unique($suffixes)))->toBe(4);
+});

```

## 修正差分 (2) docs/architecture.md (open invoice の検知手順)

```diff
diff --git a/docs/architecture.md b/docs/architecture.md
index a2514cc..d3e13f0 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -280,6 +280,93 @@ ### キューのリース期間とワーカー制限時間の規約
   (静的 gate は config をテスト環境の値で読むため、env 上書きを残すと
   「gate は通るが本番の実値は別」を作れてしまう)。
 
+### ジョブの重複実行と結果の一回性
+
+キューは at-least-once であり、上のリース規約を守っても**二重実行そのものは無くならない**
+(worker 停止・再開、リース切れ、cron による stale 回復)。したがって守るのは「実行が 1 回」ではなく
+**「結果が 1 回」**である (裁定 AG-082 の追従。設計は
+`devnotes/20260807-1235-job-execution-dedup/`)。
+
+1. **2 層の役割** — 入口の排他 (`ShouldBeUnique` / `Cache::lock`) は **best-effort** であり、
+   保証を担わない (鍵は失敗・timeout で解放されないことがあり、TTL でも切れる)。
+   結果の一回性は**永続状態遷移** (条件付き UPDATE / 悲観ロック + status guard / 予約 CAS) と
+   **外部側の冪等性** (Stripe idempotency key / invoice の状態検査) が担う。
+   **preflight** (外部呼び出し直前の所有権再検証) は「既に失われた所有権を検出して送信を止める」
+   **抑止策**であって保証ではない。
+2. **所有権の定義** — **(行の主キー, 進行中 status)**。`AnalysisJob` / `RenderJob` /
+   `TicketAutoRechargeAttempt` はいずれも単調な状態機械で、再実行は**新しい行を起票する**ため、
+   `status` の再読込がそのまま所有権の再検証になる (claim token 列を持たない根拠)。
+   行が消えている場合も所有権喪失として扱う (deny-by-default)。
+3. **preflight の配置規則** — **外部呼び出しの直前**に置く。再検証と外部呼び出しの間に
+   **自前の書き込みを挟まない**。挟んだ場合は書き込みの**後**に再度置く
+   (auto-recharge は `invoice_id` の永続化を挟むため create 前と pay 前の 2 箇所)。
+4. **終端後のジョブ状態・進捗書き込みの禁止** — preflight を置いた経路では、terminal 化された後に
+   旧ワーカーが自前の書き込みを行う経路も同時に塞ぐ。**ジョブ行**への進捗書き込み
+   (`step` / `progress` / `result_json` / `stripe_invoice_id`) は `where status=…` の
+   **条件付き UPDATE** にする (「failed なのに progress=65」を作らない。副次的に `updated_at` の
+   更新も止まるため stale 判定の基準が terminal 行で動かない)。
+   **対象はジョブ行に限る** — `SourceDocument::extracted_json` のような write-only の
+   監査スナップショットは状態機械の一部ではないため対象外である。
+5. **auto-recharge の保証層** (課金は最も高価なので 4 層で持つ):
+
+   | 層 | 機構 | 何を保証するか |
+   |---|---|---|
+   | 入口 | org `Cache::lock` (TTL 180s) / `AutoRechargeTriggerJob::$uniqueFor` (30s) | best-effort の直列化のみ |
+   | 起票 | `tar_attempts_org_pending_unique` (partial unique) | org に pending は 1 つまで |
+   | 遷移 | `where status='pending'` の条件付き UPDATE | 1 attempt = 1 遷移 |
+   | 効果 | 台帳 `recharge:{invoiceId}` の UNIQUE + Stripe idempotency key | 付与と課金の一回性 |
+
+   **冪等キーは 2 本ある**: 付与の一回性は台帳の `recharge:{invoiceId}` (**invoice 単位**)、
+   attempt 遷移の一回性は条件付き UPDATE (**attempt 単位**)。`recordSuccessfulCharge()` が
+   「grant → attempt 遷移」の順なのはこのためで、**逆順にしない**
+   (逆順は「Stripe で課金済みなのにチケット未付与」というより悪い不整合を生む)。
+6. **閉じない窓 (受容済み)** —
+   (a) **送信権の競合**: preflight 通過から送信までの間に terminal 化されうる。
+   (b) **送信結果の不明**: 送信直後にプロセスが死ぬと結果が分からない (S3 PUT / Stripe pay 同型)。
+   (c) **LLM に冪等キーが無い**: provider 側で重複排除できない (だから preflight を置く)。
+   (d) **`queue:listen` ではジョブ側 `$timeout` が効かない** (dev / bug-hunt)。
+7. **序列** — `LOCK_TTL_SECONDS` / `uniqueFor` < 既定接続の `retry_after`
+   (鍵の残留が正当な再実行を封鎖する時間を、キューの再配送間隔の内側に収める)。
+   ジョブ側 `$timeout` < `retry_after` < 予約 TTL ≤ stale 閾値 (上節)。
+   成立前提は「pcntl 有効 / 遅延なし / 時計ずれが小さい / シグナル順序 / supervisor 設定」。
+8. **運用契約 (所有者 = 課金運用担当)** —
+   - `event = job_ownership_lost` の**連続発生**は「ワーカーの停止・再開が多い」または
+     「序列の前提が崩れた」の兆候。頻度を監視する。
+   - **恒久回収を持たない open invoice が 2 種ある**。どちらも `reconcile()` は
+     DB の pending attempt を走査するため**母集団外**であり、手動収束が必要。
+     **検知元がそれぞれ違う**ので分けて書く:
+
+     | # | 発生条件 | 検知元 | 収束手順 |
+     |---|---|---|---|
+     | (a) | 所有権喪失後の void / delete に失敗した | **アプリログ**: `event = job_ownership_lost_cleanup` かつ `terminated=false` (原因の分類は同ログの `error` = 例外クラス名。メッセージ本文は `report()` 側の例外報告に残る) | 同ログの `invoice_id` を Stripe で確認し、`paid` でなければ手動 void |
+     | (b) | invoice 作成成功 → `stripe_invoice_id` の永続化前にワーカーが死亡した | **アプリログには何も残らない**。Stripe 側を起点に探す — metadata `purpose=auto_recharge` を持つ `draft` / `open` invoice を列挙し、その `recharge_attempt_ulid` に対応する `ticket_auto_recharge_attempts` 行の `stripe_invoice_id` が **NULL または別 id** のものが孤児 | attempt が terminal (paid/failed/canceled) なら手動 void。attempt が pending なら次の `executeAttempt` が**同一 idempotency key で同じ invoice に収束する**ため放置してよい |
+
+     どちらも Stripe metadata の `recharge_attempt_ulid` から attempt を逆引きできる
+     (`metadataFor()` が全 invoice に付与している)。
+     照合は**課金運用担当が定期的に行う** (自動化は母集団が Stripe 側にあるため
+     本節のスコープ外。必要になったら独立の TODO として起票する)。
+
+**規約 ↔ テスト対応表** (AGENTS.md 禁止事項 1 = 不変条件はテスト登録まで含めて「実装済み」):
+
+| 規約の文 | 保証するテスト |
+|---|---|
+| キューに載る全クラスが保証側 or 免除に分類される | `JobExecutionDedupInventoryTest` |
+| 登録された**すべての** preflight checkpoint が実在し、制御方式 (`PreflightControlFlow`) に一致する戻り型を持つ (**存在まで**) | `JobExecutionDedupInventoryTest` |
+| 期待する外部呼び出し種別 (`jobDedupRequiredExternalCalls()` が正本) と checkpoint 登録の集合一致 / `NoExternalCall` と混在しない | `JobExecutionDedupInventoryTest` |
+| preflight が**外部呼び出しの直前に置かれている** (配置) | `AnalysisPipelineTest` / `RenderPipelineTest` / `AutoRechargeServiceTest`。★**分担**: Architecture gate = 集合一致 + 実在 + 戻り型 / Feature テスト = 配置。Manual は既存 fake のフック (`onAttempt` / `duringCompose`)、**Billing は注入可能な `FakeAttemptOwnershipPreflight`** (競合注入シーム) で配置を赤化する |
+| 終端後にジョブ行の進捗を書き戻さない (条件付き UPDATE) | `AnalysisPipelineTest` / `RenderPipelineTest` |
+| 終端後に `stripe_invoice_id` を書き込まない (条件付き UPDATE) | `AutoRechargeServiceTest` |
+| 同一 invoice への付与は台帳に 1 件しか入らない | `AutoRechargeServiceTest` |
+| 免除は型付き enum + 30 文字以上の根拠 / 件数は宣言と一致 | `JobExecutionDedupInventoryTest` + value object の `Assert` |
+| 入口の排他 TTL / `uniqueFor` < `retry_after` | `JobExclusionOrderingInvariantTest` |
+| `$timeout < retry_after < 予約 TTL ≤ stale 閾値` | `AnalysisTimeBudgetInvariantTest` / `RenderTimeBudgetInvariantTest` |
+| worker `--timeout` < `retry_after` | `QueueWorkerLeaseInvariantTest` |
+| 所有権喪失時に LLM を呼ばない | `AnalysisPipelineTest` |
+| 所有権喪失時に S3 PUT しない | `RenderPipelineTest` |
+| 所有権喪失時に invoice 作成・支払いを抑止し、必要な既作成 invoice を終端する | `AutoRechargeServiceTest` |
+| ログコンテキストに PII を含めない | `JobOwnershipLostContextTest` |
+| 固定 event 名の literal が 1 箇所に閉じる | `JobExecutionDedupInventoryTest` |
+
 ### AI 解析ジョブの運用契約
 
 - 解析ジョブ (`RunManualAnalysis`) は専用 queue connection **`database-analysis`**

```

---

## 再検証の結果

- `composer phpstan` (level 10): **No errors**
- `vendor/bin/pint --test`: passed
- `composer test -- tests/Feature/Billing/AutoRechargeServiceTest.php`: **33 passed**
- mutation の再確認 (AutoRechargeService を触ったため再実施):
  **M13 / M14 / M15 / M16 / M17 すべて赤化を再確認**
  (M15 / M16 / M17 は新設した「後始末ログの error は例外クラス名のみで、
  外部由来のメッセージを含まない」も巻き込んで赤くなる = 新テストが有効に働いている)
- この返答のあと `composer test` 全件と `pnpm` 系の検証コマンドを最終確認として回します。

---

## 確認してほしいこと

1. `error` を `$exception::class` に限定し、詳細を `report()` に委ねる形で
   「外部 payload をログへ漏らさない」を満たせているか。
   7 キー schema (`error` のキー名) を維持したまま値の性質だけを変えた判断に問題はないか。
2. `report($exception)` をここで呼ぶことが、抑止ログ側 (`JobOwnershipLostException` は
   `report()` しない) の設計意図と矛盾しないという整理に同意できるか。
3. `docs/architecture.md` の open invoice (a)/(b) の表 —
   特に (b) の検知手順 (Stripe 側の draft/open invoice を metadata `purpose=auto_recharge` で
   列挙 → `recharge_attempt_ulid` で DB の attempt を逆引き → `stripe_invoice_id` が
   NULL または別 id なら孤児) が実装と整合しているか。
   また「attempt が pending なら次の executeAttempt が同一 idempotency key で
   同じ invoice に収束するため放置してよい」という記述は正しいか
   (`idempotencyKeyBase()` は `auto-recharge:{attempt_ulid}` で attempt に pin されており、
   gateway 側は `{base}:invoice` / `{base}:item` を使う)。

全体判定 (APPROVED / CHANGES_REQUESTED) を最後に 1 行で書いてください。
