# 実装レビュー依頼: T079 (決済 parity P8a = オートリチャージ / 裏チャージ)

## アプリの使命 (North Star / AGENTS.md 正本)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項 (AGENTS.md 正本)

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## セキュリティ不変条件 (アプリ都合で緩めない。AGENTS.md 正本)

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない(`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`)
2. **子は親に属する**: nested route の不整合は**認可より前に 404**(`NestedRouteIdorDefenseTest` の inventory に登録必須)
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`(平文 where は hit しない)
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**(`UrlSafetyInspector` / `PinnedHttpClient`)

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# あなたの役割

あなたは Laravel 12 + Inertia(Svelte 5 runes) + Stripe(Cashier) の課金系に精通したシニアレビュアーである。
以下の実装差分 (T079 = P8a) が、**設計書どおりか / 禁止事項・セキュリティ不変条件に抵触していないか / 実運用で二重課金や取りこぼしを起こさないか**を検証せよ。

## レビュー観点 (優先度順)

1. **課金の冪等性 (最重要)**: リトライ・並行実行・webhook 再送・プロセス死で **二重課金 / 二重付与 / 未付与 (資金回収済みなのにチケットが出ない)** が起きないか。特に:
   - attempt 起票 (org lock + DB partial unique) → invoice 作成 → invoice_id 永続化 → off-session pay → 付与、の各境界でプロセスが死んだ場合の収束先
   - webhook (invoice.paid / invoice.payment_failed / checkout.session.completed) と 同期 pay と リコンサイル cron の 3 経路が競合したとき
   - Stripe idempotency key の設計 (`auto-recharge:{attempt_ulid}` + suffix) が実際に二重課金を防ぐか
   - 停止 (updateSettings(enabled=false)) 直後の pending attempt が課金されないか
2. **閾値判定の出典**: 補充要否・数量確定が `TicketLedgerService::availableTrueBalance()` (真値・clamp 前) を使っているか。表示用 `balance()->totalAvailable()` (per-source clamp 済み) を判定に使うと過剰補充になる。
3. **既定 off の保証**: `ticket_auto_recharges` に行が無い組織の挙動が**完全に不変**か。既存経路 (reserve / webhook / 課金ページ / オンボーディング) に回帰が入っていないか。
4. **設計書 P8a 節との一致**: 逸脱があれば「どこが / なぜ問題か (または問題ないか)」を明示せよ。
5. **禁止事項・セキュリティ不変条件への抵触**: 特に #1 tenant キー不信 / #3 cross-org / #7 課金の冪等性 / 禁止事項 #4 `response()->json()` 直書き / #7 `redirect()->intended()` / #8 disabled ボタン。
6. **PHPStan level 10 適合**: 型を緩めた箇所・`mixed` の持ち回り・`@phpstan-ignore` の有無。
7. **テストが不変条件を実際に固定しているか (空振りしていないか)**: assert が本質を突いているか、Fake の実装がテストを通すためだけの都合になっていないか、テストが落ちるべきときに落ちるか。
8. **副作用・後退リスク**: 既存の低残高通知 / チケット購入 / サブスク webhook / オンボーディングへの影響。

## 前提コンテキスト (レビュー時に既知として扱ってよい)

- P1〜P7 はマージ済み。`BillingAccess::state()` は 5 状態を返し、未契約組織は onboarding へ遮断される。
- `TicketLedgerService` は P5 で aigenba verbatim 移植済み (per-source clamp / 消費優先 / commit-wins)。`availableTrueBalance()` (真値) と `balance(): TicketBalanceDto` (表示用 clamp 済み) の 2 系統がある。
- `BillingCheckoutSession` / `CheckoutIntent` (SubscriptionStart / SetupPaymentMethod) / `CheckoutSessionStatus` は P2 で導入済み。**`billing_checkout_sessions` の最初の writer が本 P8a** (intent=setup_payment_method)。`intent=subscription_start` 行の writer と stale sweeper (`expireStaleCheckouts`) は **P9 所管**。
- **T1004 (サブスク決済カードのオートリチャージ流用)・`consent_version='v2'` への改定・`ReuseSubscriptionPaymentMethodJob` は P9 所管**であり、P8a では意図的に非実装。これを「未実装」として指摘する必要はない (設計 D29(ii) の明示的分割)。
- 月次チケット付与は D28 で廃止済み (全 tier `monthly_ticket_grant = 0`)。
- 検証コマンドは全て green と報告されている: `composer test` (2353 tests, 2351 passed, 2 skipped, 0 failed) / `composer phpstan` (level 10, 726 files, No errors, phpstan.neon 無変更) / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` (853) / `pnpm build`。**green であることを根拠に安心せず、テストが実際に不変条件を固定しているかを疑え**。

## 出力形式

指摘は必ず以下のラベル付きで、**具体的なファイル・行・再現シナリオ**を添えて書け。

- `[Critical]` — マージをブロックすべき欠陥 (二重課金・資金回収済み未付与・認可漏れ・データ破壊・設計の重大逸脱)
- `[Warning]` — 修正が望ましいが単独ではブロックしない
- `[Suggestion]` — 改善案

各 `[Critical]` には **「この入力・この順序でこう壊れる」** という再現手順を書くこと。推測で Critical を積まないこと (根拠が薄いものは Warning に落とせ)。
最後に **総合判定 (APPROVED / CHANGES_REQUESTED)** を 1 行で書け。

---

# 実装差分 (`git diff main...HEAD`)

```diff
diff --git a/app/Console/Commands/Billing/ReconcileAutoRechargeAttempts.php b/app/Console/Commands/Billing/ReconcileAutoRechargeAttempts.php
new file mode 100644
index 0000000..8b08905
--- /dev/null
+++ b/app/Console/Commands/Billing/ReconcileAutoRechargeAttempts.php
@@ -0,0 +1,50 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Console\Commands\Billing;
+
+use App\Services\Billing\AutoRechargeService;
+use Illuminate\Console\Command;
+use Illuminate\Contracts\Cache\LockTimeoutException;
+use Illuminate\Support\Facades\Cache;
+use Illuminate\Support\Facades\Log;
+
+/**
+ * P8a: オートリチャージ pending attempt のリコンサイル (5 分岐)。
+ *
+ * webhook の terminal ack (MAX_PROCESSING_ATTEMPTS=8 で再送打ち切り) により恒久 drop した
+ * 「課金済み・付与なし」の**唯一のセーフティネット**。scheduler で 15 分毎に実行する
+ * (routes/console.php。失敗は onFailure → report() で運用アラートへ載る)。
+ */
+final class ReconcileAutoRechargeAttempts extends Command
+{
+    protected $signature = 'billing:reconcile-auto-recharge';
+
+    protected $description = 'オートリチャージの pending attempt を回収する (課金済み回収 / 再実行 / 期限切れ終端 / 取りこぼし起票)';
+
+    public function handle(AutoRechargeService $autoRecharge): int
+    {
+        try {
+            /** @var array{recovered_paid: int, retried: int, sca_reminded: int, expired: int, triggered: int} $stats */
+            $stats = Cache::lock('billing:auto-recharge-reconcile', 300)
+                ->block(5, fn (): array => $autoRecharge->reconcile());
+        } catch (LockTimeoutException $e) {
+            $this->error('別プロセスが billing:reconcile-auto-recharge を実行中。exit 1');
+            Log::warning('ReconcileAutoRechargeAttempts: lock timeout', ['error' => $e->getMessage()]);
+
+            return self::FAILURE;
+        }
+
+        $this->info(sprintf(
+            'auto-recharge reconcile: recovered_paid=%d retried=%d sca_reminded=%d expired=%d triggered=%d',
+            $stats['recovered_paid'],
+            $stats['retried'],
+            $stats['sca_reminded'],
+            $stats['expired'],
+            $stats['triggered'],
+        ));
+
+        return self::SUCCESS;
+    }
+}
diff --git a/app/DataTransferObjects/Billing/AutoRechargeConsentDto.php b/app/DataTransferObjects/Billing/AutoRechargeConsentDto.php
new file mode 100644
index 0000000..1dbdb92
--- /dev/null
+++ b/app/DataTransferObjects/Billing/AutoRechargeConsentDto.php
@@ -0,0 +1,18 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+/**
+ * P8a: オートリチャージ有効化時の off-session mandate 同意。
+ *
+ * client から受けるのは version のみ。同意金額 (consented_max_amount) はサーバが現行カタログ
+ * (TicketVolumePrice::currentTierFor) で再計算する — client hidden の金額は信用しない。
+ */
+final readonly class AutoRechargeConsentDto
+{
+    public function __construct(
+        public string $version,
+    ) {}
+}
diff --git a/app/DataTransferObjects/Billing/AutoRechargeConsentTermsDto.php b/app/DataTransferObjects/Billing/AutoRechargeConsentTermsDto.php
new file mode 100644
index 0000000..31c4ad3
--- /dev/null
+++ b/app/DataTransferObjects/Billing/AutoRechargeConsentTermsDto.php
@@ -0,0 +1,45 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+/**
+ * P8a (D29): オンボーディング同意提示 + 事前同意記録が共有する「同意条件」の単一計算源 DTO。
+ *
+ * AutoRechargeService::consentTermsFor() が返す値がそのまま画面に表示され、
+ * recordPreConsent() が同じ計算源で記録する (表示金額 = 記録金額の一致をテストで固定)。
+ * TS 側 `AutoRechargeConsentTerms` (resources/js/types/billing.ts) と厳密一致契約。
+ *
+ * @phpstan-type AutoRechargeConsentTermsShape array{
+ *   thresholdCount: int,
+ *   maxCount: int,
+ *   maxAmountJpy: int,
+ *   unitAmountJpy: int,
+ *   consentVersion: string
+ * }
+ */
+final readonly class AutoRechargeConsentTermsDto
+{
+    public function __construct(
+        public int $thresholdCount,
+        public int $maxCount,
+        /** 1 回の自動購入の上限額 (税込・整数円) = currentTierFor(maxCount)->unitAmount * maxCount */
+        public int $maxAmountJpy,
+        /** maxCount 適用時の単価 (税込・整数円) */
+        public int $unitAmountJpy,
+        public string $consentVersion,
+    ) {}
+
+    /** @return AutoRechargeConsentTermsShape */
+    public function toArray(): array
+    {
+        return [
+            'thresholdCount' => $this->thresholdCount,
+            'maxCount' => $this->maxCount,
+            'maxAmountJpy' => $this->maxAmountJpy,
+            'unitAmountJpy' => $this->unitAmountJpy,
+            'consentVersion' => $this->consentVersion,
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/Billing/AutoRechargeSettingsDto.php b/app/DataTransferObjects/Billing/AutoRechargeSettingsDto.php
new file mode 100644
index 0000000..653b721
--- /dev/null
+++ b/app/DataTransferObjects/Billing/AutoRechargeSettingsDto.php
@@ -0,0 +1,88 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+/**
+ * P8a: オートリチャージ設定カード (AutoRechargeCard) 用の Inertia props DTO。
+ *
+ * subscription 有無に依存せず常に非 null で渡す (無料パーソナル含む全プランが対象)。
+ * TS 側 `AutoRechargeProps` (resources/js/types/billing.ts) と厳密一致契約。
+ *
+ * @phpstan-import-type PurchaseTierShape from PurchaseTierDto
+ *
+ * @phpstan-type AutoRechargeShape array{
+ *   enabled: bool,
+ *   thresholdCount: int,
+ *   maxCount: int,
+ *   minCount: int,
+ *   maxCountLimit: int,
+ *   canManage: bool,
+ *   hasPaymentMethod: bool,
+ *   paymentMethodBrand: string|null,
+ *   paymentMethodLast4: string|null,
+ *   setupPending: bool,
+ *   requiresReconsent: bool,
+ *   pendingAutoEnable: bool,
+ *   disabledReason: string|null,
+ *   failureCount: int,
+ *   consentVersion: string,
+ *   baseUnitAmountJpy: int,
+ *   tiers: list<PurchaseTierShape>
+ * }
+ */
+final readonly class AutoRechargeSettingsDto
+{
+    /** @param list<PurchaseTierDto> $tiers */
+    public function __construct(
+        public bool $enabled,
+        public int $thresholdCount,
+        public int $maxCount,
+        public int $minCount,
+        public int $maxCountLimit,
+        public bool $canManage,
+        public bool $hasPaymentMethod,
+        public ?string $paymentMethodBrand,
+        public ?string $paymentMethodLast4,
+        /** setup 完了 (30 分以内) だが PM snapshot 未反映 = 「カード登録処理中」表示。 */
+        public bool $setupPending,
+        /** 価格改定等で現行最大請求額が同意額を超過 = 再同意まで自動購入停止中。 */
+        public bool $requiresReconsent,
+        /** 有効な事前同意が待機中 (PM 未登録) = カード登録完了で自動有効化される。 */
+        public bool $pendingAutoEnable,
+        public ?string $disabledReason,
+        public int $failureCount,
+        /** 現行の同意文言バージョン (有効化 submit の hidden に載せる)。 */
+        public string $consentVersion,
+        public int $baseUnitAmountJpy,
+        public array $tiers,
+    ) {}
+
+    /** @return AutoRechargeShape */
+    public function toArray(): array
+    {
+        return [
+            'enabled' => $this->enabled,
+            'thresholdCount' => $this->thresholdCount,
+            'maxCount' => $this->maxCount,
+            'minCount' => $this->minCount,
+            'maxCountLimit' => $this->maxCountLimit,
+            'canManage' => $this->canManage,
+            'hasPaymentMethod' => $this->hasPaymentMethod,
+            'paymentMethodBrand' => $this->paymentMethodBrand,
+            'paymentMethodLast4' => $this->paymentMethodLast4,
+            'setupPending' => $this->setupPending,
+            'requiresReconsent' => $this->requiresReconsent,
+            'pendingAutoEnable' => $this->pendingAutoEnable,
+            'disabledReason' => $this->disabledReason,
+            'failureCount' => $this->failureCount,
+            'consentVersion' => $this->consentVersion,
+            'baseUnitAmountJpy' => $this->baseUnitAmountJpy,
+            'tiers' => array_map(
+                static fn (PurchaseTierDto $t): array => $t->toArray(),
+                $this->tiers,
+            ),
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/Billing/DefaultPaymentMethodDto.php b/app/DataTransferObjects/Billing/DefaultPaymentMethodDto.php
new file mode 100644
index 0000000..9c53960
--- /dev/null
+++ b/app/DataTransferObjects/Billing/DefaultPaymentMethodDto.php
@@ -0,0 +1,29 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+/**
+ * P8a: Stripe customer の default payment method 状態
+ * (invoice_settings.default_payment_method)。
+ * オートリチャージ有効化の fail-closed 判定・UI 表示 (brand/last4) に使う。
+ */
+final readonly class DefaultPaymentMethodDto
+{
+    public function __construct(
+        public ?string $paymentMethodId,
+        public ?string $brand,
+        public ?string $last4,
+    ) {}
+
+    public static function none(): self
+    {
+        return new self(null, null, null);
+    }
+
+    public function exists(): bool
+    {
+        return $this->paymentMethodId !== null;
+    }
+}
diff --git a/app/DataTransferObjects/Billing/InvoiceStateDto.php b/app/DataTransferObjects/Billing/InvoiceStateDto.php
new file mode 100644
index 0000000..33482f1
--- /dev/null
+++ b/app/DataTransferObjects/Billing/InvoiceStateDto.php
@@ -0,0 +1,28 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+/**
+ * P8a: リコンサイルが参照する Stripe invoice の現在状態
+ * (raw payload を Service に持ち込まない境界)。
+ * status: 'draft' | 'open' | 'paid' | 'void' | 'uncollectible' | 'deleted'
+ */
+final readonly class InvoiceStateDto
+{
+    public function __construct(
+        public string $status,
+        public ?int $amountPaid,
+        /** 請求額 (invoice.amount_due)。amount cross-check の照合対象。 */
+        public ?int $amountDue,
+        public ?string $paymentIntentId,
+        /**
+         * PaymentIntent が SCA (requires_action / authentication_required) 待ちか。
+         * true の間は attempt を終端させない (復旧可能)。
+         */
+        public bool $requiresAction,
+        /** SCA (追加認証) の復旧導線。Stripe ホスト決済ページ。 */
+        public ?string $hostedInvoiceUrl,
+    ) {}
+}
diff --git a/app/DataTransferObjects/Billing/OffSessionChargeResultDto.php b/app/DataTransferObjects/Billing/OffSessionChargeResultDto.php
new file mode 100644
index 0000000..4b8c841
--- /dev/null
+++ b/app/DataTransferObjects/Billing/OffSessionChargeResultDto.php
@@ -0,0 +1,49 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+/**
+ * P8a: off-session Invoice 課金 (payOffSessionInvoice) の typed 結果。
+ *
+ * カード起因の失敗 (card_declined / authentication_required 等) は例外ではなくこの DTO で
+ * 返す — リトライ/通知/終端の判断は Service 層の責務。Stripe 障害・設定不備は
+ * 従来どおり例外で伝播する (fail-closed)。
+ */
+final readonly class OffSessionChargeResultDto
+{
+    private function __construct(
+        public bool $paid,
+        public string $invoiceId,
+        /** 実回収額 (税込 JPY, invoice.amount_paid。credit balance 適用で amount_due より小さいことがある)。失敗時は null。 */
+        public ?int $amountPaid,
+        /** 請求額 (税込 JPY, invoice.amount_due)。amount cross-check の照合対象。失敗時は null。 */
+        public ?int $amountDue,
+        /** InvoicePayment 経由で解決した PaymentIntent id。 */
+        public ?string $paymentIntentId,
+        /** Stripe error code (例: card_declined, authentication_required)。成功時は null。 */
+        public ?string $failureCode,
+        /** decline code (例: insufficient_funds)。失敗時のみ。 */
+        public ?string $declineCode,
+    ) {}
+
+    public static function paid(string $invoiceId, int $amountPaid, int $amountDue, ?string $paymentIntentId): self
+    {
+        return new self(true, $invoiceId, $amountPaid, $amountDue, $paymentIntentId, null, null);
+    }
+
+    public static function failed(string $invoiceId, ?string $failureCode, ?string $declineCode): self
+    {
+        return new self(false, $invoiceId, null, null, null, $failureCode, $declineCode);
+    }
+
+    /**
+     * SCA (追加認証) 要求か。true なら終端させず pending 維持 + 復旧導線
+     * (hosted_invoice_url) を案内する。
+     */
+    public function requiresAction(): bool
+    {
+        return $this->failureCode === 'authentication_required';
+    }
+}
diff --git a/app/DataTransferObjects/Billing/PurchaseTicketsPageDto.php b/app/DataTransferObjects/Billing/PurchaseTicketsPageDto.php
index d64679f..a71ef3b 100644
--- a/app/DataTransferObjects/Billing/PurchaseTicketsPageDto.php
+++ b/app/DataTransferObjects/Billing/PurchaseTicketsPageDto.php
@@ -19,7 +19,8 @@
  *   balance: int,
  *   canManage: bool,
  *   attemptToken: string,
- *   purchased: bool
+ *   purchased: bool,
+ *   autoRechargeEnabled: bool
  * }
  */
 final readonly class PurchaseTicketsPageDto
@@ -36,6 +37,8 @@ public function __construct(
         public bool $canManage,
         public string $attemptToken,
         public bool $purchased,
+        /** P8a: オートリチャージが有効か (購入導線の案内文言の出し分けに使う。既定 false)。 */
+        public bool $autoRechargeEnabled = false,
     ) {}
 
     /**
@@ -55,6 +58,7 @@ public function toArray(): array
             'canManage' => $this->canManage,
             'attemptToken' => $this->attemptToken,
             'purchased' => $this->purchased,
+            'autoRechargeEnabled' => $this->autoRechargeEnabled,
         ];
     }
 }
diff --git a/app/DataTransferObjects/Onboarding/OnboardingCheckoutDto.php b/app/DataTransferObjects/Onboarding/OnboardingCheckoutDto.php
index 2303104..d712bd5 100644
--- a/app/DataTransferObjects/Onboarding/OnboardingCheckoutDto.php
+++ b/app/DataTransferObjects/Onboarding/OnboardingCheckoutDto.php
@@ -4,6 +4,7 @@
 
 namespace App\DataTransferObjects\Onboarding;
 
+use App\DataTransferObjects\Billing\AutoRechargeConsentTermsDto;
 use App\DataTransferObjects\Billing\PersonalPlanEligibilityDto;
 use App\DataTransferObjects\Billing\PlanDto;
 
@@ -16,6 +17,7 @@
  *
  * @phpstan-import-type PlanDtoShape from PlanDto
  * @phpstan-import-type PersonalPlanEligibilityShape from PersonalPlanEligibilityDto
+ * @phpstan-import-type AutoRechargeConsentTermsShape from AutoRechargeConsentTermsDto
  *
  * @phpstan-type OnboardingCheckoutShape array{
  *   plans: list<PlanDtoShape>,
@@ -24,7 +26,9 @@
  *   contactUrl: string,
  *   personalEligibility: PersonalPlanEligibilityShape|null,
  *   signupGrantTickets: int,
- *   intendedPlanCode: string|null
+ *   intendedPlanCode: string|null,
+ *   consentTerms: AutoRechargeConsentTermsShape,
+ *   fundingChoices: list<string>
  * }
  */
 final readonly class OnboardingCheckoutDto
@@ -45,6 +49,18 @@ public function __construct(
         public ?PersonalPlanEligibilityDto $personalEligibility = null,
         public int $signupGrantTickets = 10,
         public ?string $intendedPlanCode = null,
+        /**
+         * P8a (D29(i)): オートリチャージ事前同意の提示条件。表示値と記録値の単一計算源
+         * (AutoRechargeService::consentTermsFor)。
+         */
+        public ?AutoRechargeConsentTermsDto $consentTerms = null,
+        /**
+         * 画面に出す資金選択の並び (enum 値)。`tickets` は UI から出さない
+         * (validation では引き続き受理する)。
+         *
+         * @var list<string>
+         */
+        public array $fundingChoices = [],
     ) {}
 
     /**
@@ -63,6 +79,8 @@ public function toArray(): array
             'personalEligibility' => $this->personalEligibility?->toArray(),
             'signupGrantTickets' => $this->signupGrantTickets,
             'intendedPlanCode' => $this->intendedPlanCode,
+            'consentTerms' => ($this->consentTerms ?? new AutoRechargeConsentTermsDto(0, 0, 0, 0, ''))->toArray(),
+            'fundingChoices' => $this->fundingChoices,
         ];
     }
 }
diff --git a/app/Enums/Billing/AutoRechargeAttemptStatus.php b/app/Enums/Billing/AutoRechargeAttemptStatus.php
new file mode 100644
index 0000000..35e3f23
--- /dev/null
+++ b/app/Enums/Billing/AutoRechargeAttemptStatus.php
@@ -0,0 +1,23 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Billing;
+
+/**
+ * P8a: オートリチャージ attempt の状態機械。
+ *
+ * pending → paid   : invoice.paid webhook / リコンサイルの冪等付与後
+ * pending → failed : invoice の終端 (void/delete) 成功後のみ (課金不能の確定。failure_count +1)
+ * pending → canceled: invoice の終端成功後のみ (決済手段の問題ではない破棄。failure_count 増分なし)
+ *
+ * failed / canceled は「invoice が課金され得ない」ことが保証された終端。open invoice を
+ * 残したまま終端しない (遅延支払いによる二重課金・二重付与の構造的排除)。
+ */
+enum AutoRechargeAttemptStatus: string
+{
+    case Pending = 'pending';
+    case Paid = 'paid';
+    case Failed = 'failed';
+    case Canceled = 'canceled';
+}
diff --git a/app/Enums/Billing/AutoRechargeDisabledReason.php b/app/Enums/Billing/AutoRechargeDisabledReason.php
new file mode 100644
index 0000000..f473291
--- /dev/null
+++ b/app/Enums/Billing/AutoRechargeDisabledReason.php
@@ -0,0 +1,16 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Billing;
+
+/**
+ * P8a: オートリチャージが無効化された理由。
+ * PaymentFailures = 連続課金失敗による自動停止 (再有効化は UI から)。
+ * User = ユーザーによる明示的な停止。
+ */
+enum AutoRechargeDisabledReason: string
+{
+    case PaymentFailures = 'payment_failures';
+    case User = 'user';
+}
diff --git a/app/Enums/Billing/BillingNotificationType.php b/app/Enums/Billing/BillingNotificationType.php
index 026ceb7..dff2969 100644
--- a/app/Enums/Billing/BillingNotificationType.php
+++ b/app/Enums/Billing/BillingNotificationType.php
@@ -16,4 +16,10 @@ enum BillingNotificationType: string
 {
     case PaymentFailed = 'payment_failed';
     case RenewalReminder = 'renewal_reminder';
+
+    /* P8a: オートリチャージ (裏チャージ) 系。すべて reminder 経路 (type, dedup_key) で冪等管理する。 */
+    case AutoRechargeFailed = 'auto_recharge_failed';
+    case AutoRechargeDisabled = 'auto_recharge_disabled';
+    case AutoRechargeActionRequired = 'auto_recharge_action_required';
+    case AutoRechargeEnabled = 'auto_recharge_enabled';
 }
diff --git a/app/Enums/Billing/SignupFundingChoice.php b/app/Enums/Billing/SignupFundingChoice.php
new file mode 100644
index 0000000..12dea58
--- /dev/null
+++ b/app/Enums/Billing/SignupFundingChoice.php
@@ -0,0 +1,22 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Billing;
+
+/**
+ * P8a (D29): 新規登録フローの資金選択。
+ *
+ *  - AutoRecharge: オートリチャージを設定する (既定・おすすめ)。activate 完了後に
+ *    カード登録 (mode=setup Checkout) へ誘導する。登録だけでは課金されない。
+ *  - Later       : あとで決める (初回 signup grant で試用)。
+ *  - Tickets     : チケットを買う (購入ページ直行)。
+ *    UI からは出さない (移植元 T1002 で撤去済み) — 永続値の読み出し互換のため case は残し、
+ *    validation 上も引き続き受理する (**3 case verbatim**。case 縮小はしない)。
+ */
+enum SignupFundingChoice: string
+{
+    case AutoRecharge = 'auto_recharge';
+    case Tickets = 'tickets';
+    case Later = 'later';
+}
diff --git a/app/Http/Controllers/Billing/BillingController.php b/app/Http/Controllers/Billing/BillingController.php
index 4669678..0a1efad 100644
--- a/app/Http/Controllers/Billing/BillingController.php
+++ b/app/Http/Controllers/Billing/BillingController.php
@@ -4,14 +4,22 @@
 
 namespace App\Http\Controllers\Billing;
 
+use App\DataTransferObjects\Billing\AutoRechargeConsentDto;
 use App\Enums\Billing\PlanPriceKind;
+use App\Enums\CheckoutIntent;
+use App\Enums\CheckoutSessionStatus;
+use App\Exceptions\Billing\CheckoutInProgressException;
 use App\Exceptions\Billing\StripePriceNotSyncedException;
 use App\Http\Concerns\ResolvesCurrentOrganization;
 use App\Http\Controllers\Controller;
 use App\Http\Requests\Billing\BillingCheckoutRequest;
+use App\Http\Requests\Billing\StartAutoRechargeSetupRequest;
+use App\Http\Requests\Billing\UpdateAutoRechargeRequest;
+use App\Models\Billing\BillingCheckoutSession;
 use App\Models\Billing\Plan;
 use App\Models\Organization;
 use App\Models\User;
+use App\Services\Billing\AutoRechargeService;
 use App\Services\Billing\BillingAccess;
 use App\Services\Billing\SubscriptionService;
 use App\Services\Billing\TicketLedgerService;
@@ -20,6 +28,7 @@
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
 use Illuminate\Support\Facades\Gate;
+use Illuminate\Support\Str;
 use Inertia\Inertia;
 use Inertia\Response;
 use InvalidArgumentException;
@@ -31,7 +40,8 @@
  *
  * - プラン変更は Stripe Checkout / Customer Portal 経由のみ (アプリは plan_code を
  *   直接書かない。organizations.plan_code は webhook で同期される)
- * - 閲覧は組織メンバー全員、Checkout / Portal は manageBilling (owner / admin) のみ
+ * - 閲覧は組織メンバー全員、Checkout / Portal / オートリチャージ設定は
+ *   manageBilling (owner / admin) のみ
  */
 class BillingController extends Controller
 {
@@ -41,10 +51,11 @@ public function __construct(
         private readonly BillingAccess $access,
         private readonly IntendedPlanResolver $intendedPlanResolver,
         private readonly OnboardingReturnResolver $returnResolver,
+        private readonly AutoRechargeService $autoRecharge,
     ) {}
 
-    /** 課金ページ (現在プラン / チケット残高 / プラン一覧) */
-    public function index(Request $request, TicketLedgerService $tickets): Response
+    /** 課金ページ (現在プラン / チケット残高 / プラン一覧 / オートリチャージ設定) */
+    public function index(Request $request, TicketLedgerService $tickets): Response|RedirectResponse
     {
         $organization = $this->resolveCurrentOrganization($request);
         Gate::authorize('view', $organization);
@@ -52,6 +63,14 @@ public function index(Request $request, TicketLedgerService $tickets): Response
         $user = $request->user();
         Assert::isInstanceOf($user, User::class);
 
+        // カード登録 (mode=setup) の着地。GET で副作用を起こさないよう、検証済みの
+        // ?setup_session_id を消費して 303 + flash で canonical URL へ倒す
+        // (リロード・共有時に query が残らない)。
+        $landing = $this->resolveAutoRechargeSetupLanding($request, $organization);
+        if ($landing !== null) {
+            return $landing;
+        }
+
         $plans = Plan::query()->orderBy('sort_order')->get()
             ->map(function (Plan $plan): array {
                 $price = $plan->currentPrice(PlanPriceKind::Base);
@@ -68,12 +87,20 @@ public function index(Request $request, TicketLedgerService $tickets): Response
             ->values()
             ->all();
 
+        $canManageBilling = $user->can('manageBilling', $organization);
+
         return Inertia::render('Billing/Index', [
             'plans' => $plans,
             'currentPlanCode' => $organization->plan_code,
             'ticketBalance' => $tickets->balance($organization)->totalAvailable(),
-            'canManageBilling' => $user->can('manageBilling', $organization),
+            'canManageBilling' => $canManageBilling,
             'continueUrl' => $this->resolveOnboardingContinue($organization),
+            // P8a: オートリチャージ設定カード。subscription 有無に依存せず常に非 null
+            // (無料パーソナル含む全プランが対象。**既定は enabled=false の opt-in**)。
+            'autoRecharge' => $this->autoRecharge->settingsFor($organization, $canManageBilling)->toArray(),
+            // カード登録開始 POST の attempt_token (render 単位。setup は課金を伴わないため
+            // 購入導線のようなサーバ側安定化は不要 — 同一 token の再送は台帳 unique で冪等)。
+            'autoRechargeSetupToken' => strtolower((string) Str::ulid()),
         ]);
     }
 
@@ -118,6 +145,113 @@ public function checkout(BillingCheckoutRequest $request, SubscriptionService $s
         return Inertia::location($redirect->url);
     }
 
+    /**
+     * P8a: オートリチャージ設定の更新 (有効化 / 停止 / 閾値・上限の変更)。
+     *
+     * 有効化は Service 側で fail-closed (default PM 必須 + 同意必須)。停止は同一 lock 下で
+     * pending attempt をキャンセルする (停止後課金の禁止)。
+     */
+    public function updateAutoRecharge(UpdateAutoRechargeRequest $request): RedirectResponse
+    {
+        $organization = $this->resolveCurrentOrganization($request);
+        Gate::authorize('manageBilling', $organization);
+
+        $user = $request->user();
+        Assert::isInstanceOf($user, User::class);
+
+        $enabled = $request->boolean('enabled');
+        // Laravel の integer ルールは値を cast しないため、明示的に型を確定してから渡す
+        // (範囲・相関の検証は FormRequest が済ませている)。
+        $threshold = $request->integer('threshold_count');
+        $max = $request->integer('max_count');
+
+        $consentVersion = $request->validated('consent_version');
+        $consent = is_string($consentVersion) && $consentVersion !== ''
+            ? new AutoRechargeConsentDto($consentVersion)
+            : null;
+
+        $wasEnabled = $this->autoRecharge->isEnabledFor($organization);
+
+        try {
+            $this->autoRecharge->updateSettings($organization, $user, $enabled, $threshold, $max, $consent);
+        } catch (CheckoutInProgressException $e) {
+            return back()->with('error', $e->getMessage());
+        }
+
+        $message = match (true) {
+            $enabled => 'オートリチャージを設定しました。残高が少なくなったら自動で補充します。',
+            $wasEnabled => 'オートリチャージを停止しました。今後、自動購入は行われません。再開はいつでもこの画面からできます。',
+            default => 'オートリチャージ設定を保存しました。カード登録後にこの内容で有効化できます。',
+        };
+
+        // 操作系 POST は back() で完結させる (禁止事項 #7: redirect()->intended() は使わない)
+        return back()->with('success', $message);
+    }
+
+    /**
+     * P8a: オートリチャージ用カード登録 (Checkout mode=setup) を開始する。
+     * attempt_token 冪等は purchase-tickets と同型 (二重 submit で別 session を作らない)。
+     */
+    public function startAutoRechargeSetup(StartAutoRechargeSetupRequest $request): SymfonyResponse|RedirectResponse
+    {
+        $organization = $this->resolveCurrentOrganization($request);
+        Gate::authorize('manageBilling', $organization);
+
+        $user = $request->user();
+        Assert::isInstanceOf($user, User::class);
+
+        $token = $request->validated('attempt_token');
+        Assert::stringNotEmpty($token);
+
+        $result = $this->autoRecharge->startSetupCheckout(
+            $organization,
+            $user,
+            route('billing.index').'?setup_session_id={CHECKOUT_SESSION_ID}',
+            route('billing.index'),
+            $token,
+        );
+
+        if ($result['url'] === null) {
+            return back()->with('warning', '既に進行中のカード登録があります。');
+        }
+
+        return Inertia::location($result['url']);
+    }
+
+    /**
+     * カード登録着地 (`?setup_session_id=...`) を検証して 303 + flash に倒す。
+     *
+     * - session id は**自 org の SetupPaymentMethod 台帳行**に一致する場合のみ成功文言を出す
+     *   (cross-org の session id を投げ込んでも成功と誤認させない = IDOR 防御)
+     * - 状態の書き込みは webhook (SetDefaultPaymentMethodJob) の管轄。ここは表示のみ
+     *   = **GET で副作用を起こさない**
+     * - 欠落時は素通し (通常の課金ページ表示)
+     */
+    private function resolveAutoRechargeSetupLanding(Request $request, Organization $organization): ?RedirectResponse
+    {
+        $sessionId = $request->query('setup_session_id');
+        if (! is_string($sessionId) || $sessionId === '') {
+            return null;
+        }
+
+        $session = BillingCheckoutSession::query()
+            ->where('organization_id', $organization->getKey())
+            ->where('intent', CheckoutIntent::SetupPaymentMethod->value)
+            ->where('stripe_session_id', $sessionId)
+            ->first();
+
+        if ($session === null) {
+            // 未追跡 session — 成功文言は出さず canonical URL へ倒すだけ (query を残さない)。
+            return redirect()->route('billing.index', [], 303);
+        }
+
+        $message = $session->status === CheckoutSessionStatus::Completed->value
+            ? 'お支払いカードを登録しました。'
+            : 'お支払いカードの登録を受け付けました。反映まで少しお待ちください。';
+
+        return redirect()->route('billing.index', [], 303)->with('success', $message);
+    }
+
     /**
      * 契約成立着地でのみ「元の画面に戻る」導線を出す (1 回限り = リロードで CTA が残らない)。
      *
diff --git a/app/Http/Controllers/Billing/TicketPurchaseController.php b/app/Http/Controllers/Billing/TicketPurchaseController.php
index 8453669..e9c2025 100644
--- a/app/Http/Controllers/Billing/TicketPurchaseController.php
+++ b/app/Http/Controllers/Billing/TicketPurchaseController.php
@@ -13,6 +13,7 @@
 use App\Http\Requests\Billing\TicketCheckoutRequest;
 use App\Models\Billing\TicketVolumePrice;
 use App\Models\User;
+use App\Services\Billing\AutoRechargeService;
 use App\Services\Billing\TicketCheckoutService;
 use App\Services\Billing\TicketLedgerService;
 use App\Services\Billing\TicketPricingService;
@@ -45,6 +46,7 @@ public function show(
         TicketPricingService $pricing,
         TicketLedgerService $tickets,
         TicketCheckoutService $checkout,
+        AutoRechargeService $autoRecharge,
     ): Response {
         $organization = $this->resolveCurrentOrganization($request);
         Gate::authorize('view', $organization);
@@ -67,6 +69,9 @@ public function show(
             canManage: $user->can('manageBilling', $organization),
             attemptToken: (string) Str::ulid(),
             purchased: $purchased,
+            // P8a: 有効なら「自動購入が設定済み」であることを購入導線でも示せるようにする
+            // (軽量な enabled 判定のみ。カタログ解決コストは払わない)。
+            autoRechargeEnabled: $autoRecharge->isEnabledFor($organization),
         );
 
         return Inertia::render('Billing/PurchaseTickets', ['page' => $dto->toArray()]);
diff --git a/app/Http/Controllers/Onboarding/ActivatePersonalController.php b/app/Http/Controllers/Onboarding/ActivatePersonalController.php
index 8b9f6b1..a4a9bfb 100644
--- a/app/Http/Controllers/Onboarding/ActivatePersonalController.php
+++ b/app/Http/Controllers/Onboarding/ActivatePersonalController.php
@@ -4,17 +4,25 @@
 
 namespace App\Http\Controllers\Onboarding;
 
+use App\DataTransferObjects\Billing\AutoRechargeConsentDto;
+use App\Enums\Billing\SignupFundingChoice;
+use App\Exceptions\Billing\CheckoutInProgressException;
 use App\Exceptions\Billing\PersonalPlanNotEligibleException;
 use App\Http\Concerns\ResolvesCurrentOrganization;
 use App\Http\Controllers\Controller;
 use App\Http\Requests\Onboarding\ActivatePersonalRequest;
+use App\Models\Organization;
 use App\Models\User;
+use App\Services\Billing\AutoRechargeService;
 use App\Services\Billing\PersonalPlanService;
 use App\Services\Billing\TicketPricingService;
 use App\Services\Onboarding\OnboardingReturnResolver;
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Support\Facades\Gate;
+use Illuminate\Support\Str;
 use Illuminate\Validation\ValidationException;
+use Inertia\Inertia;
+use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
 use Webmozart\Assert\Assert;
 
 /**
@@ -32,9 +40,10 @@ public function __construct(
         private readonly PersonalPlanService $personalPlan,
         private readonly TicketPricingService $ticketPricing,
         private readonly OnboardingReturnResolver $returnResolver,
+        private readonly AutoRechargeService $autoRecharge,
     ) {}
 
-    public function __invoke(ActivatePersonalRequest $request): RedirectResponse
+    public function __invoke(ActivatePersonalRequest $request): RedirectResponse|SymfonyResponse
     {
         $organization = $this->resolveMemberCurrentOrganization($request);
         Gate::authorize('manageBilling', $organization);
@@ -62,6 +71,74 @@ public function __invoke(ActivatePersonalRequest $request): RedirectResponse
         $continue = $this->returnResolver->peekForOrganization($organization);
         $this->returnResolver->forgetForOrganization($organization);
 
+        $fundingRaw = $request->validated('funding_choice');
+        $funding = is_string($fundingRaw) ? SignupFundingChoice::from($fundingRaw) : null;
+
+        // P8a (D29(i)): 「オートリチャージを設定する」を明示選択した場合は、activate 完了済みの
+        // まま カード登録 (mode=setup Checkout) へ直行する。cancel しても請求ページ着地で
+        // カード登録 CTA が残る (詰まない)。continuation は上で消費済み。
+        if ($funding === SignupFundingChoice::AutoRecharge) {
+            // 事前同意の記録 (enabled=false)。version は FormRequest (Rule::in) で activate 前に
+            // 検証済み — Service 内の再検証はリクエスト処理中の version 改定 (TOCTOU) に対する
+            // defense-in-depth。カード登録完了 webhook が自動有効化する。
+            $consentVersion = $request->validated('consent_version');
+            Assert::stringNotEmpty($consentVersion);
+
+            try {
+                $this->autoRecharge->recordPreConsent($organization, $user, new AutoRechargeConsentDto($consentVersion));
+
+                $result = $this->autoRecharge->startSetupCheckout(
+                    $organization,
+                    $user,
+                    route('billing.index').'?setup_session_id={CHECKOUT_SESSION_ID}',
+                    route('billing.index'),
+                    // session 保持の安定 token で二重 submit を冪等化
+                    // (SetupPaymentMethod 台帳を増殖させない)。
+                    $this->setupAttemptToken($request, $organization),
+                );
+            } catch (CheckoutInProgressException $e) {
+                return back()->with('error', $e->getMessage());
+            }
+
+            if ($result['url'] !== null) {
+                // flash は startSetupCheckout 成功後にのみ積む (Stripe 例外時に flash だけ残さない)。
+                $request->session()->flash(
+                    'success',
+                    $message.' カード登録が完了すると、オートリチャージが自動で有効になります。',
+                );
+
+                return Inertia::location($result['url']);
+            }
+
+            // url=null (進行中 session の replay) は請求ページへ fallback (カード登録 CTA が残る)。
+            return redirect()->route('billing.index')->with('success', $message);
+        }
+
+        // 「チケットを買う」(UI 非提示・永続互換値) は購入ページへ直行する。
+        if ($funding === SignupFundingChoice::Tickets) {
+            return redirect()->route('billing.tickets.show')->with('success', $message);
+        }
+
         return redirect()->to($continue ?? route('dashboard'))->with('success', $message);
     }
+
+    /**
+     * カード登録 (mode=setup) の attempt_token を activation フロー単位で安定化する。
+     *
+     * render ごとに発行すると二重 submit で SetupPaymentMethod 台帳が増殖するため、
+     * org スコープの session キーに ULID を保持して再利用する。
+     */
+    private function setupAttemptToken(ActivatePersonalRequest $request, Organization $organization): string
+    {
+        $key = "auto_recharge_setup_token:{$organization->id}";
+        $token = $request->session()->get($key);
+        if (is_string($token) && $token !== '') {
+            return $token;
+        }
+
+        $token = strtolower((string) Str::ulid());
+        $request->session()->put($key, $token);
+
+        return $token;
+    }
 }
diff --git a/app/Http/Controllers/Onboarding/OnboardingController.php b/app/Http/Controllers/Onboarding/OnboardingController.php
index 44c7d72..c9e4194 100644
--- a/app/Http/Controllers/Onboarding/OnboardingController.php
+++ b/app/Http/Controllers/Onboarding/OnboardingController.php
@@ -6,6 +6,7 @@
 
 use App\DataTransferObjects\Billing\PlanDto;
 use App\DataTransferObjects\Onboarding\OnboardingCheckoutDto;
+use App\Enums\Billing\SignupFundingChoice;
 use App\Enums\Inquiry\InquirySource;
 use App\Enums\PlanCode;
 use App\Http\Concerns\ResolvesCurrentOrganization;
@@ -13,6 +14,7 @@
 use App\Models\Billing\Plan;
 use App\Models\Organization;
 use App\Models\User;
+use App\Services\Billing\AutoRechargeService;
 use App\Services\Billing\BillingAccess;
 use App\Services\Billing\PersonalPlanService;
 use App\Services\Billing\TicketPricingService;
@@ -41,6 +43,7 @@ public function __construct(
         private readonly TicketPricingService $ticketPricing,
         private readonly ContactUrl $contactUrl,
         private readonly IntendedPlanResolver $intendedPlanResolver,
+        private readonly AutoRechargeService $autoRecharge,
     ) {}
 
     public function show(Request $request): Response|RedirectResponse
@@ -81,6 +84,15 @@ public function show(Request $request): Response|RedirectResponse
             signupGrantTickets: $this->ticketPricing->signupGrantTickets(),
             // peek = 残す (リロード耐性)。Enterprise / 未知値は正規化で null に倒れる。
             intendedPlanCode: $this->intendedPlanResolver->peekForOrganization($organization)?->value,
+            // P8a (D29(i)): 事前同意の提示条件。画面表示値と recordPreConsent の記録値は
+            // consentTermsFor() の単一計算源から出る (表示と記録の一致をテストで固定)。
+            consentTerms: $this->autoRecharge->consentTermsFor(),
+            // UI に出す資金選択は 2 択 (auto_recharge 既定 / later)。
+            // `tickets` は UI から出さない (enum・validation では受理継続)。
+            fundingChoices: [
+                SignupFundingChoice::AutoRecharge->value,
+                SignupFundingChoice::Later->value,
+            ],
         );
 
         return Inertia::render('Onboarding/Checkout', [
diff --git a/app/Http/Requests/Billing/StartAutoRechargeSetupRequest.php b/app/Http/Requests/Billing/StartAutoRechargeSetupRequest.php
new file mode 100644
index 0000000..b76e7ed
--- /dev/null
+++ b/app/Http/Requests/Billing/StartAutoRechargeSetupRequest.php
@@ -0,0 +1,34 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Requests\Billing;
+
+use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
+use Illuminate\Foundation\Http\FormRequest;
+
+/**
+ * P8a: オートリチャージ用カード登録 (Checkout mode=setup) の開始。
+ *
+ * attempt_token は render 単位の ULID (二重 submit の台帳冪等アンカー)。
+ * 認可は Controller の Gate::authorize('manageBilling') が担う。
+ */
+final class StartAutoRechargeSetupRequest extends FormRequest
+{
+    use ProhibitsProtectedKeys;
+
+    public function authorize(): bool
+    {
+        return true;
+    }
+
+    /**
+     * @return array<string, mixed>
+     */
+    public function rules(): array
+    {
+        return array_replace([
+            'attempt_token' => ['required', 'ulid'],
+        ], $this->protectedKeyMissingRules());
+    }
+}
diff --git a/app/Http/Requests/Billing/UpdateAutoRechargeRequest.php b/app/Http/Requests/Billing/UpdateAutoRechargeRequest.php
new file mode 100644
index 0000000..8ace22e
--- /dev/null
+++ b/app/Http/Requests/Billing/UpdateAutoRechargeRequest.php
@@ -0,0 +1,78 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Requests\Billing;
+
+use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
+use App\Models\Billing\TicketVolumePrice;
+use Illuminate\Foundation\Http\FormRequest;
+
+/**
+ * P8a: オートリチャージ設定更新。
+ *
+ * - max_count > threshold_count (gt) + 上限は config billing.auto_recharge.max_count
+ *   (= TicketVolumePrice::PURCHASE_MAX_COUNT と単一真実源。超過は tier 解決で例外死するため
+ *   入口で拘束する)
+ * - enabled=true のとき consent_version 必須。version の現行一致・再同意要否の判定は
+ *   Service 側 (サーバ状態依存のため)
+ * - 認可は Controller の Gate::authorize('manageBilling') が担う
+ */
+final class UpdateAutoRechargeRequest extends FormRequest
+{
+    use ProhibitsProtectedKeys;
+
+    public function authorize(): bool
+    {
+        return true;
+    }
+
+    /**
+     * @return array<string, mixed>
+     */
+    public function rules(): array
+    {
+        return array_replace([
+            'enabled' => ['required', 'boolean'],
+            'threshold_count' => ['required', 'integer', 'min:0'],
+            'max_count' => [
+                'required',
+                'integer',
+                'gt:threshold_count',
+                'max:'.$this->maxCountLimit(),
+            ],
+            'consent_version' => ['required_if_accepted:enabled', 'string', 'max:16'],
+        ], $this->protectedKeyMissingRules());
+    }
+
+    private function maxCountLimit(): int
+    {
+        return min(
+            config()->integer('billing.auto_recharge.max_count'),
+            TicketVolumePrice::PURCHASE_MAX_COUNT,
+        );
+    }
+
+    /**
+     * 'enabled' は 2 段階認証必須化フォームと同名キーのため、本フォーム専用ラベルで上書きする。
+     *
+     * @return array<string, string>
+     */
+    public function attributes(): array
+    {
+        return [
+            'enabled' => 'オートリチャージ',
+        ];
+    }
+
+    /**
+     * @return array<string, string>
+     */
+    public function messages(): array
+    {
+        return [
+            'max_count.gt' => 'リチャージ後の残高は開始残高より大きい値を指定してください。',
+            'consent_version.required_if_accepted' => '自動購入への同意が必要です。',
+        ];
+    }
+}
diff --git a/app/Http/Requests/Onboarding/ActivatePersonalRequest.php b/app/Http/Requests/Onboarding/ActivatePersonalRequest.php
index 6b72e65..c3b7bb3 100644
--- a/app/Http/Requests/Onboarding/ActivatePersonalRequest.php
+++ b/app/Http/Requests/Onboarding/ActivatePersonalRequest.php
@@ -4,8 +4,10 @@
 
 namespace App\Http\Requests\Onboarding;
 
+use App\Enums\Billing\SignupFundingChoice;
 use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
 use Illuminate\Foundation\Http\FormRequest;
+use Illuminate\Validation\Rule;
 
 /**
  * Personal (free) プラン有効化のリクエスト。
@@ -31,6 +33,20 @@ public function rules(): array
     {
         return array_replace([
             'declaration' => ['required', 'accepted'],
+            // P8a (D29(i)): 資金選択。省略時は「あとで決める」相当 = 既存挙動 (dashboard 着地)。
+            // `tickets` は UI から出さないが永続値・旧クライアント互換のため受理する。
+            'funding_choice' => ['nullable', Rule::in(array_map(
+                static fn (SignupFundingChoice $c): string => $c->value,
+                SignupFundingChoice::cases(),
+            ))],
+            // auto_recharge を選んだときのみ同意 version 必須。**activate より前に**現行版との
+            // 完全一致を検証して fail-closed する (画面表示と異なる条件での同意記録を排除)。
+            'consent_version' => [
+                'required_if:funding_choice,'.SignupFundingChoice::AutoRecharge->value,
+                'string',
+                'max:16',
+                Rule::in([config()->string('billing.auto_recharge.consent_version')]),
+            ],
         ], $this->protectedKeyMissingRules());
     }
 
@@ -42,6 +58,8 @@ public function messages(): array
         return [
             'declaration.required' => '個人利用であることの確認が必要です。',
             'declaration.accepted' => '個人利用であることの確認が必要です。',
+            'consent_version.required_if' => '自動購入への同意が必要です。',
+            'consent_version.in' => '自動購入の同意内容が更新されています。ページを再読み込みして内容を確認してください。',
         ];
     }
 }
diff --git a/app/Jobs/Billing/AutoRechargeTriggerJob.php b/app/Jobs/Billing/AutoRechargeTriggerJob.php
new file mode 100644
index 0000000..b365e04
--- /dev/null
+++ b/app/Jobs/Billing/AutoRechargeTriggerJob.php
@@ -0,0 +1,65 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Jobs\Billing;
+
+use App\Models\Billing\TicketAutoRecharge;
+use App\Models\Organization;
+use App\Services\Billing\AutoRechargeService;
+use Illuminate\Bus\Queueable;
+use Illuminate\Contracts\Queue\ShouldBeUnique;
+use Illuminate\Contracts\Queue\ShouldQueue;
+use Illuminate\Foundation\Bus\Dispatchable;
+use Illuminate\Queue\InteractsWithQueue;
+use Illuminate\Queue\SerializesModels;
+
+/**
+ * P8a: チケット消費 (reserve) 後の残高閾値判定 → attempt 起票の薄い箱。
+ *
+ * 判定は Job 側に完全委譲 (reserve hot path で閾値を見ない)。**enabled 設定の存在確認で
+ * 早期 return する** = opt-in 未設定の組織では何も起きない (既定 off の回帰点)。
+ * 重複 dispatch は maybeCreateAttempt の pending 検査 / DB partial unique が吸収する。
+ *
+ * $tries = 1: 自動リトライしない (取りこぼしはリコンサイル (v) の管轄 — 二重課金面の安全側)。
+ */
+final class AutoRechargeTriggerJob implements ShouldBeUnique, ShouldQueue
+{
+    use Dispatchable;
+    use InteractsWithQueue;
+    use Queueable;
+    use SerializesModels;
+
+    public int $tries = 1;
+
+    public int $uniqueFor = 30;
+
+    public function __construct(public readonly int $organizationId) {}
+
+    public function uniqueId(): string
+    {
+        return (string) $this->organizationId;
+    }
+
+    public function handle(AutoRechargeService $autoRecharge): void
+    {
+        // enabled 設定がない org は即 return (opt-in / 既定 off のガード)。
+        $configured = TicketAutoRecharge::query()
+            ->where('organization_id', $this->organizationId)
+            ->where('enabled', true)
+            ->exists();
+        if (! $configured) {
+            return;
+        }
+
+        $organization = Organization::query()->find($this->organizationId);
+        if (! $organization instanceof Organization) {
+            return;
+        }
+
+        $attempt = $autoRecharge->maybeCreateAttempt($organization);
+        if ($attempt !== null) {
+            ExecuteAutoRechargeAttemptJob::dispatch($attempt->id);
+        }
+    }
+}
diff --git a/app/Jobs/Billing/ExecuteAutoRechargeAttemptJob.php b/app/Jobs/Billing/ExecuteAutoRechargeAttemptJob.php
new file mode 100644
index 0000000..96f21e3
--- /dev/null
+++ b/app/Jobs/Billing/ExecuteAutoRechargeAttemptJob.php
@@ -0,0 +1,42 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Jobs\Billing;
+
+use App\Models\Billing\TicketAutoRechargeAttempt;
+use App\Services\Billing\AutoRechargeService;
+use Illuminate\Bus\Queueable;
+use Illuminate\Contracts\Queue\ShouldQueue;
+use Illuminate\Foundation\Bus\Dispatchable;
+use Illuminate\Queue\InteractsWithQueue;
+use Illuminate\Queue\SerializesModels;
+
+/**
+ * P8a: pending attempt の課金実行 (invoice 作成 → invoice_id 永続化 → off-session pay)。
+ *
+ * $tries = 1: queue の自動リトライを使わない。再試行はリコンサイル (i) の管轄 —
+ * 同一 idempotency key base で Stripe 冪等が効くため、リトライ主体を一本化して
+ * 二重課金の検討面を減らす。
+ */
+final class ExecuteAutoRechargeAttemptJob implements ShouldQueue
+{
+    use Dispatchable;
+    use InteractsWithQueue;
+    use Queueable;
+    use SerializesModels;
+
+    public int $tries = 1;
+
+    public function __construct(public readonly int $attemptId) {}
+
+    public function handle(AutoRechargeService $autoRecharge): void
+    {
+        $attempt = TicketAutoRechargeAttempt::query()->find($this->attemptId);
+        if (! $attempt instanceof TicketAutoRechargeAttempt) {
+            return;
+        }
+
+        $autoRecharge->executeAttempt($attempt);
+    }
+}
diff --git a/app/Jobs/Billing/HandleAutoRechargeChargeFailureJob.php b/app/Jobs/Billing/HandleAutoRechargeChargeFailureJob.php
new file mode 100644
index 0000000..679131e
--- /dev/null
+++ b/app/Jobs/Billing/HandleAutoRechargeChargeFailureJob.php
@@ -0,0 +1,77 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Jobs\Billing;
+
+use App\Enums\Billing\AutoRechargeAttemptStatus;
+use App\Models\Billing\TicketAutoRechargeAttempt;
+use App\Models\Organization;
+use App\Services\Billing\AutoRechargeService;
+use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
+use Illuminate\Bus\Queueable;
+use Illuminate\Contracts\Queue\ShouldQueue;
+use Illuminate\Foundation\Bus\Dispatchable;
+use Illuminate\Queue\InteractsWithQueue;
+use Illuminate\Queue\SerializesModels;
+use Webmozart\Assert\Assert;
+
+/**
+ * P8a: invoice.payment_failed (webhook) からの失敗振り分け。
+ *
+ * SCA 判定は local failure_code に依存せず Stripe 側 PaymentIntent 状態 (gateway の
+ * invoice state) を出典にする — webhook が同期 pay の記録より先に来ても SCA を誤終端しない。
+ * **外向き Stripe API は webhook 同期処理から Job へ退避する**。
+ *
+ * $tries = 1: 取りこぼしはリコンサイル (iii)/(iv) が同じ判定で回収する。
+ */
+final class HandleAutoRechargeChargeFailureJob implements ShouldQueue
+{
+    use Dispatchable;
+    use InteractsWithQueue;
+    use Queueable;
+    use SerializesModels;
+
+    public int $tries = 1;
+
+    public function __construct(public readonly int $attemptId) {}
+
+    public function handle(AutoRechargeGatewayInterface $gateway, AutoRechargeService $autoRecharge): void
+    {
+        $attempt = TicketAutoRechargeAttempt::query()->find($this->attemptId);
+        if (! $attempt instanceof TicketAutoRechargeAttempt) {
+            return;
+        }
+        if ($attempt->status !== AutoRechargeAttemptStatus::Pending) {
+            return;
+        }
+
+        $organization = $attempt->organization;
+        Assert::isInstanceOf($organization, Organization::class);
+
+        $invoiceId = $attempt->stripe_invoice_id;
+        if ($invoiceId === null) {
+            return; // invoice 未作成の failed イベントはあり得ない (防御的 no-op)
+        }
+
+        $state = $gateway->retrieveInvoiceState($invoiceId);
+
+        if ($state->status === 'paid') {
+            // 順序逆転 (failed → 実は復旧して paid): 冪等付与で収束。
+            $amountPaid = $state->amountPaid;
+            $amountDue = $state->amountDue;
+            Assert::integer($amountPaid);
+            Assert::integer($amountDue);
+            $autoRecharge->recordSuccessfulCharge($organization, $attempt, $invoiceId, $amountPaid, $amountDue, $state->paymentIntentId);
+
+            return;
+        }
+
+        $autoRecharge->handleChargeFailure(
+            $organization,
+            $attempt,
+            $attempt->failure_code ?? 'payment_failed',
+            $state->requiresAction,
+        );
+    }
+}
diff --git a/app/Jobs/Billing/SetDefaultPaymentMethodJob.php b/app/Jobs/Billing/SetDefaultPaymentMethodJob.php
new file mode 100644
index 0000000..9164fd0
--- /dev/null
+++ b/app/Jobs/Billing/SetDefaultPaymentMethodJob.php
@@ -0,0 +1,53 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Jobs\Billing;
+
+use App\Models\Organization;
+use App\Services\Billing\AutoRechargeService;
+use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
+use Illuminate\Bus\Queueable;
+use Illuminate\Contracts\Queue\ShouldQueue;
+use Illuminate\Foundation\Bus\Dispatchable;
+use Illuminate\Queue\InteractsWithQueue;
+use Illuminate\Queue\SerializesModels;
+
+/**
+ * P8a: mode=setup Checkout 完了 (webhook) からの PM default 設定。
+ *
+ * **外向き Stripe API は webhook 同期処理から Job に退避する**。
+ * setup_intent → payment_method 解決 → attach + default 設定
+ * (gateway::setDefaultPaymentMethod に一元化) → ticket_auto_recharges の snapshot 更新
+ * (+ 有効な事前同意があれば同一 TX で自動有効化)。
+ */
+final class SetDefaultPaymentMethodJob implements ShouldQueue
+{
+    use Dispatchable;
+    use InteractsWithQueue;
+    use Queueable;
+    use SerializesModels;
+
+    public int $tries = 3;
+
+    public int $backoff = 30;
+
+    public function __construct(
+        public readonly int $organizationId,
+        public readonly string $setupIntentId,
+    ) {}
+
+    public function handle(AutoRechargeGatewayInterface $gateway, AutoRechargeService $autoRecharge): void
+    {
+        $organization = Organization::query()->find($this->organizationId);
+        if (! $organization instanceof Organization) {
+            return;
+        }
+
+        // Stripe API 接触は全て gateway 境界に集約 (fake 差し替え可能・Job は API を直接触らない)。
+        $paymentMethodId = $gateway->resolveSetupIntentPaymentMethod($this->setupIntentId);
+
+        $gateway->setDefaultPaymentMethod($organization, $paymentMethodId);
+        $autoRecharge->applySetupCompletion($organization, $paymentMethodId);
+    }
+}
diff --git a/app/Models/Billing/TicketAutoRecharge.php b/app/Models/Billing/TicketAutoRecharge.php
new file mode 100644
index 0000000..e817a12
--- /dev/null
+++ b/app/Models/Billing/TicketAutoRecharge.php
@@ -0,0 +1,97 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Models\Billing;
+
+use App\Enums\Billing\AutoRechargeDisabledReason;
+use App\Models\Organization;
+use App\Models\User;
+use Database\Factories\Billing\TicketAutoRechargeFactory;
+use Illuminate\Database\Eloquent\Factories\HasFactory;
+use Illuminate\Database\Eloquent\Model;
+use Illuminate\Database\Eloquent\Relations\BelongsTo;
+use Illuminate\Support\Carbon;
+
+/**
+ * P8a: 組織のオートリチャージ設定 (1 org 1 行)。
+ *
+ * 無料パーソナル (Stripe サブスクなし) でも持てるため subscription には依存しない。
+ * 残高の真実源は ledger (TicketLedgerEntry)、課金プロセスの状態は TicketAutoRechargeAttempt、
+ * 本モデルは「設定 + 同意スナップショット + 連続失敗状態」のみを持つ。
+ *
+ * @property int $id
+ * @property int $organization_id
+ * @property bool $enabled
+ * @property int $threshold_count
+ * @property int $max_count
+ * @property string|null $stripe_payment_method_id
+ * @property int $failure_count
+ * @property AutoRechargeDisabledReason|null $disabled_reason
+ * @property Carbon|null $consented_at
+ * @property string|null $consent_version
+ * @property int|null $consented_max_count
+ * @property int|null $consented_max_amount
+ * @property int|null $created_by_user_id
+ * @property Carbon|null $created_at
+ * @property Carbon|null $updated_at
+ */
+class TicketAutoRecharge extends Model
+{
+    /** @use HasFactory<TicketAutoRechargeFactory> */
+    use HasFactory;
+
+    /**
+     * tenant / actor キー (organization_id / created_by_user_id) は移植元と異なり
+     * $fillable に載せない (MassAssignmentProtectedKeys の不変条件。relation 経由のみ)。
+     *
+     * @var list<string>
+     */
+    protected $fillable = [
+        'enabled',
+        'threshold_count',
+        'max_count',
+        'stripe_payment_method_id',
+        'failure_count',
+        'disabled_reason',
+        'consented_at',
+        'consent_version',
+        'consented_max_count',
+        'consented_max_amount',
+    ];
+
+    /** @var array<string, string> */
+    protected $casts = [
+        'enabled' => 'boolean',
+        'threshold_count' => 'integer',
+        'max_count' => 'integer',
+        'failure_count' => 'integer',
+        'disabled_reason' => AutoRechargeDisabledReason::class,
+        'consented_at' => 'datetime',
+        'consented_max_count' => 'integer',
+        'consented_max_amount' => 'integer',
+        'organization_id' => 'integer',
+        'created_by_user_id' => 'integer',
+    ];
+
+    protected static function newFactory(): TicketAutoRechargeFactory
+    {
+        return TicketAutoRechargeFactory::new();
+    }
+
+    /**
+     * @return BelongsTo<Organization, $this>
+     */
+    public function organization(): BelongsTo
+    {
+        return $this->belongsTo(Organization::class);
+    }
+
+    /**
+     * @return BelongsTo<User, $this>
+     */
+    public function createdByUser(): BelongsTo
+    {
+        return $this->belongsTo(User::class, 'created_by_user_id');
+    }
+}
diff --git a/app/Models/Billing/TicketAutoRechargeAttempt.php b/app/Models/Billing/TicketAutoRechargeAttempt.php
new file mode 100644
index 0000000..4b3a4cd
--- /dev/null
+++ b/app/Models/Billing/TicketAutoRechargeAttempt.php
@@ -0,0 +1,82 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Models\Billing;
+
+use App\Enums\Billing\AutoRechargeAttemptStatus;
+use App\Models\Organization;
+use Database\Factories\Billing\TicketAutoRechargeAttemptFactory;
+use Illuminate\Database\Eloquent\Factories\HasFactory;
+use Illuminate\Database\Eloquent\Model;
+use Illuminate\Database\Eloquent\Relations\BelongsTo;
+use Illuminate\Support\Carbon;
+
+/**
+ * P8a: オートリチャージ試行の状態機械 (課金プロセス管理専用)。
+ *
+ * - quantity は attempt 作成時 (org 行ロック TX 内) に一度だけ確定し、以降の真実源
+ * - unit_amount は webhook amount cross-check の pin
+ * - attempt_ulid は Stripe idempotency key / invoice metadata に載せる外部識別子
+ * - 「org に pending は 1 つまで」は partial unique index (tar_attempts_org_pending_unique) が保証
+ * - failed / canceled への遷移は invoice の終端 (void/delete) 成功後のみ
+ *
+ * @property int $id
+ * @property string $attempt_ulid
+ * @property int $organization_id
+ * @property AutoRechargeAttemptStatus $status
+ * @property int $quantity
+ * @property int $unit_amount
+ * @property string $stripe_price_id
+ * @property string|null $stripe_invoice_id
+ * @property string|null $stripe_payment_intent_id
+ * @property string|null $failure_code
+ * @property Carbon|null $resolved_at
+ * @property Carbon|null $created_at
+ * @property Carbon|null $updated_at
+ */
+class TicketAutoRechargeAttempt extends Model
+{
+    /** @use HasFactory<TicketAutoRechargeAttemptFactory> */
+    use HasFactory;
+
+    /**
+     * tenant キー (organization_id) は移植元と異なり $fillable に載せない
+     * (MassAssignmentProtectedKeys の不変条件。relation 経由のみ)。
+     *
+     * @var list<string>
+     */
+    protected $fillable = [
+        'attempt_ulid',
+        'status',
+        'quantity',
+        'unit_amount',
+        'stripe_price_id',
+        'stripe_invoice_id',
+        'stripe_payment_intent_id',
+        'failure_code',
+        'resolved_at',
+    ];
+
+    /** @var array<string, string> */
+    protected $casts = [
+        'status' => AutoRechargeAttemptStatus::class,
+        'quantity' => 'integer',
+        'unit_amount' => 'integer',
+        'resolved_at' => 'datetime',
+        'organization_id' => 'integer',
+    ];
+
+    protected static function newFactory(): TicketAutoRechargeAttemptFactory
+    {
+        return TicketAutoRechargeAttemptFactory::new();
+    }
+
+    /**
+     * @return BelongsTo<Organization, $this>
+     */
+    public function organization(): BelongsTo
+    {
+        return $this->belongsTo(Organization::class);
+    }
+}
diff --git a/app/Notifications/Billing/AutoRechargeActionRequiredNotification.php b/app/Notifications/Billing/AutoRechargeActionRequiredNotification.php
new file mode 100644
index 0000000..c98b9e4
--- /dev/null
+++ b/app/Notifications/Billing/AutoRechargeActionRequiredNotification.php
@@ -0,0 +1,65 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Notifications\Billing;
+
+use App\Enums\Billing\BillingNotificationType;
+use App\Notifications\Billing\Concerns\TracksBillingReminderDelivery;
+use App\Support\Billing\BillingNotificationRecorder;
+use Illuminate\Bus\Queueable;
+use Illuminate\Contracts\Queue\ShouldQueue;
+use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
+use Illuminate\Notifications\Messages\MailMessage;
+use Illuminate\Notifications\Notification;
+use Illuminate\Support\Facades\Config;
+use Throwable;
+
+/**
+ * P8a: オートリチャージ課金の SCA (3D セキュア) 認証要求通知。
+ * dedup_key = auto_recharge_sca:{invoice_id}:{JST date} (日次で再通知を許す — 放置での失効を防ぐ)。
+ * action URL は invoice の hosted_invoice_url (Stripe ホストページで認証完了できる)。
+ */
+class AutoRechargeActionRequiredNotification extends Notification implements ShouldQueue, ShouldQueueAfterCommit, TracksBillingReminderDelivery
+{
+    use Queueable;
+
+    public function __construct(
+        public readonly string $dedupKey,
+        public readonly string $organizationName,
+        public readonly string $actionUrl,
+    ) {}
+
+    public function deliveryType(): BillingNotificationType
+    {
+        return BillingNotificationType::AutoRechargeActionRequired;
+    }
+
+    public function deliveryDedupKey(): string
+    {
+        return $this->dedupKey;
+    }
+
+    /**
+     * @return list<string>
+     */
+    public function via(object $notifiable): array
+    {
+        return ['mail'];
+    }
+
+    public function toMail(object $notifiable): MailMessage
+    {
+        return (new MailMessage)
+            ->subject('【'.Config::string('app.name').'】チケット自動購入に本人認証 (3D セキュア) が必要です')
+            ->greeting("{$this->organizationName} 様")
+            ->line('チケットのオートリチャージ (自動購入) を完了するために、カード発行会社による本人認証 (3D セキュア / SCA) が必要です。')
+            ->line('期限内に認証が完了しない場合、今回の自動購入はキャンセルされます。')
+            ->action('お支払いを完了する', $this->actionUrl);
+    }
+
+    public function failed(Throwable $e): void
+    {
+        BillingNotificationRecorder::markFailedByDedupKey($this->deliveryType(), $this->dedupKey, $e);
+    }
+}
diff --git a/app/Notifications/Billing/AutoRechargeDisabledNotification.php b/app/Notifications/Billing/AutoRechargeDisabledNotification.php
new file mode 100644
index 0000000..0622311
--- /dev/null
+++ b/app/Notifications/Billing/AutoRechargeDisabledNotification.php
@@ -0,0 +1,65 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Notifications\Billing;
+
+use App\Enums\Billing\BillingNotificationType;
+use App\Notifications\Billing\Concerns\TracksBillingReminderDelivery;
+use App\Support\Billing\BillingNotificationRecorder;
+use Illuminate\Bus\Queueable;
+use Illuminate\Contracts\Queue\ShouldQueue;
+use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
+use Illuminate\Notifications\Messages\MailMessage;
+use Illuminate\Notifications\Notification;
+use Illuminate\Support\Facades\Config;
+use Throwable;
+
+/**
+ * P8a: 連続失敗によるオートリチャージ自動停止の通知。
+ * dedup_key = auto_recharge_disabled:{attempt_ulid} (停止イベント単位)。
+ */
+class AutoRechargeDisabledNotification extends Notification implements ShouldQueue, ShouldQueueAfterCommit, TracksBillingReminderDelivery
+{
+    use Queueable;
+
+    public function __construct(
+        public readonly string $dedupKey,
+        public readonly string $organizationName,
+        public readonly string $billingUrl,
+    ) {}
+
+    public function deliveryType(): BillingNotificationType
+    {
+        return BillingNotificationType::AutoRechargeDisabled;
+    }
+
+    public function deliveryDedupKey(): string
+    {
+        return $this->dedupKey;
+    }
+
+    /**
+     * @return list<string>
+     */
+    public function via(object $notifiable): array
+    {
+        return ['mail'];
+    }
+
+    public function toMail(object $notifiable): MailMessage
+    {
+        return (new MailMessage)
+            ->subject('【'.Config::string('app.name').'】オートリチャージを停止しました')
+            ->greeting("{$this->organizationName} 様")
+            ->line('チケットの自動購入 (オートリチャージ) の決済が続けて失敗したため、オートリチャージを自動的に停止しました。')
+            ->line('お支払いカードを更新のうえ、請求設定からオートリチャージを再度有効にしてください。')
+            ->action('請求設定を開く', $this->billingUrl)
+            ->line('停止中はチケットの自動補充は行われません。残高にご注意ください。');
+    }
+
+    public function failed(Throwable $e): void
+    {
+        BillingNotificationRecorder::markFailedByDedupKey($this->deliveryType(), $this->dedupKey, $e);
+    }
+}
diff --git a/app/Notifications/Billing/AutoRechargeEnabledNotification.php b/app/Notifications/Billing/AutoRechargeEnabledNotification.php
new file mode 100644
index 0000000..93bdc40
--- /dev/null
+++ b/app/Notifications/Billing/AutoRechargeEnabledNotification.php
@@ -0,0 +1,86 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Notifications\Billing;
+
+use App\Enums\Billing\BillingNotificationType;
+use App\Notifications\Billing\Concerns\TracksBillingReminderDelivery;
+use App\Support\Billing\BillingNotificationRecorder;
+use Illuminate\Bus\Queueable;
+use Illuminate\Contracts\Queue\ShouldQueue;
+use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
+use Illuminate\Notifications\Messages\MailMessage;
+use Illuminate\Notifications\Notification;
+use Illuminate\Support\Facades\Config;
+use Throwable;
+
+/**
+ * P8a: カード登録完了によるオートリチャージ自動有効化の事後通知。
+ *
+ * 同意の代替ではない (同意成立はオンボーディング画面の affirmative action)。有効化された
+ * 条件 (閾値 / 補充後枚数 / 1 回の上限額 = 同意時金額) と停止方法を明記する。
+ * dedup_key = auto_recharge_enabled:{org_id}:{payment_method_id} (setup 完了イベント単位)。
+ */
+class AutoRechargeEnabledNotification extends Notification implements ShouldQueue, ShouldQueueAfterCommit, TracksBillingReminderDelivery
+{
+    use Queueable;
+
+    public function __construct(
+        public readonly string $dedupKey,
+        public readonly string $organizationName,
+        public readonly int $thresholdCount,
+        public readonly int $maxCount,
+        public readonly ?int $consentedMaxAmountJpy,
+        public readonly ?string $paymentMethodLast4,
+        public readonly string $billingUrl,
+    ) {}
+
+    public function deliveryType(): BillingNotificationType
+    {
+        return BillingNotificationType::AutoRechargeEnabled;
+    }
+
+    public function deliveryDedupKey(): string
+    {
+        return $this->dedupKey;
+    }
+
+    /**
+     * @return list<string>
+     */
+    public function via(object $notifiable): array
+    {
+        return ['mail'];
+    }
+
+    public function toMail(object $notifiable): MailMessage
+    {
+        $mail = (new MailMessage)
+            ->subject('【'.Config::string('app.name').'】オートリチャージを有効にしました')
+            ->greeting("{$this->organizationName} 様")
+            ->line('お支払いカードの設定が完了したため、ご同意いただいた内容でチケットのオートリチャージを有効にしました。')
+            ->line(sprintf(
+                '設定内容: チケット残高が %d 枚を下回ると、%d 枚になるまで自動購入します。',
+                $this->thresholdCount,
+                $this->maxCount,
+            ));
+
+        if ($this->consentedMaxAmountJpy !== null) {
+            $mail->line(sprintf('1 回の自動購入の上限額: ¥%s (税込)', number_format($this->consentedMaxAmountJpy)));
+        }
+
+        if ($this->paymentMethodLast4 !== null) {
+            $mail->line("お支払いカード: 末尾 {$this->paymentMethodLast4}");
+        }
+
+        return $mail
+            ->action('請求設定を開く', $this->billingUrl)
+            ->line('オートリチャージは請求設定からいつでも停止できます。');
+    }
+
+    public function failed(Throwable $e): void
+    {
+        BillingNotificationRecorder::markFailedByDedupKey($this->deliveryType(), $this->dedupKey, $e);
+    }
+}
diff --git a/app/Notifications/Billing/AutoRechargeFailedNotification.php b/app/Notifications/Billing/AutoRechargeFailedNotification.php
new file mode 100644
index 0000000..583b932
--- /dev/null
+++ b/app/Notifications/Billing/AutoRechargeFailedNotification.php
@@ -0,0 +1,66 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Notifications\Billing;
+
+use App\Enums\Billing\BillingNotificationType;
+use App\Notifications\Billing\Concerns\TracksBillingReminderDelivery;
+use App\Support\Billing\BillingNotificationRecorder;
+use Illuminate\Bus\Queueable;
+use Illuminate\Contracts\Queue\ShouldQueue;
+use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
+use Illuminate\Notifications\Messages\MailMessage;
+use Illuminate\Notifications\Notification;
+use Illuminate\Support\Facades\Config;
+use Throwable;
+
+/**
+ * P8a: オートリチャージ課金失敗の通知。dedup_key = auto_recharge_failed:{attempt_ulid}
+ * (attempt 単位 — 同一 attempt の webhook 再送で再通知しない)。
+ */
+class AutoRechargeFailedNotification extends Notification implements ShouldQueue, ShouldQueueAfterCommit, TracksBillingReminderDelivery
+{
+    use Queueable;
+
+    public function __construct(
+        public readonly string $dedupKey,
+        public readonly string $organizationName,
+        public readonly string $billingUrl,
+        public readonly string $purchaseUrl,
+    ) {}
+
+    public function deliveryType(): BillingNotificationType
+    {
+        return BillingNotificationType::AutoRechargeFailed;
+    }
+
+    public function deliveryDedupKey(): string
+    {
+        return $this->dedupKey;
+    }
+
+    /**
+     * @return list<string>
+     */
+    public function via(object $notifiable): array
+    {
+        return ['mail'];
+    }
+
+    public function toMail(object $notifiable): MailMessage
+    {
+        return (new MailMessage)
+            ->subject('【'.Config::string('app.name').'】チケットの自動購入に失敗しました')
+            ->greeting("{$this->organizationName} 様")
+            ->line('チケットのオートリチャージ (自動購入) の決済に失敗しました。今回の自動購入は行われていません (課金は発生していません)。')
+            ->line('お支払いカードの有効期限や利用限度額をご確認のうえ、カード情報の更新をお願いします。')
+            ->action('請求設定を確認する', $this->billingUrl)
+            ->line("すぐにチケットが必要な場合は、手動での追加購入もご利用いただけます: {$this->purchaseUrl}");
+    }
+
+    public function failed(Throwable $e): void
+    {
+        BillingNotificationRecorder::markFailedByDedupKey($this->deliveryType(), $this->dedupKey, $e);
+    }
+}
diff --git a/app/Providers/AppServiceProvider.php b/app/Providers/AppServiceProvider.php
index 147f316..bd8502a 100644
--- a/app/Providers/AppServiceProvider.php
+++ b/app/Providers/AppServiceProvider.php
@@ -20,8 +20,10 @@
 use App\Models\Organization;
 use App\Models\User;
 use App\Notifications\Channels\OrganizationScopedDatabaseChannel;
+use App\Services\Billing\CashierAutoRechargeGateway;
 use App\Services\Billing\CashierStripeGateway;
 use App\Services\Billing\CashierTicketCheckoutGateway;
+use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
 use App\Services\Billing\Contracts\StripeGatewayInterface;
 use App\Services\Billing\StripeWebhookProcessor;
 use App\Services\Billing\TicketCheckoutGateway;
@@ -110,6 +112,10 @@ public function register(): void
         // FakeExternalsServiceProvider が fake に rebind する (providers.php で後勝ち)
         $this->app->bind(StripeGatewayInterface::class, CashierStripeGateway::class);
 
+        // オートリチャージ (P8a) の Stripe 抽象 (setup Checkout / off-session invoice)。
+        // fake_externals 時は FakeExternalsServiceProvider が fake に rebind する
+        $this->app->bind(AutoRechargeGatewayInterface::class, CashierAutoRechargeGateway::class);
+
         // アプリ内通知 (T008): database channel を薄い拡張へ差し替え、AppNotification の
         // organization_id を notifications テーブルの first-class 列として書き込む
         // (ChannelManager::createDatabaseDriver は container 解決のため binding が効く。
diff --git a/app/Providers/FakeExternalsServiceProvider.php b/app/Providers/FakeExternalsServiceProvider.php
index fb2a15b..0d23367 100644
--- a/app/Providers/FakeExternalsServiceProvider.php
+++ b/app/Providers/FakeExternalsServiceProvider.php
@@ -7,7 +7,9 @@
 use App\Http\Controllers\Testing\GetFakeStorageObjectController;
 use App\Http\Controllers\Testing\PutFakeStorageObjectController;
 use App\Services\AI\Testing\CannedPromptFakeRegistrar;
+use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
 use App\Services\Billing\Contracts\StripeGatewayInterface;
+use App\Services\Billing\Fakes\FakeAutoRechargeGateway;
 use App\Services\Billing\Fakes\FakeStripeGateway;
 use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;
 use App\Services\Billing\TicketCheckoutGateway;
@@ -78,6 +80,7 @@ private function registerPaymentFakes(): void
         // Stripe 到達点を fake へ rebind (課金状態の正本は BughuntBillingSeeder)
         $this->app->bind(TicketCheckoutGateway::class, FakeTicketCheckoutGateway::class);
         $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
+        $this->app->bind(AutoRechargeGatewayInterface::class, FakeAutoRechargeGateway::class);
     }
 
     /** LLM (Prism) fake (fake_llm + LLM_FAKE_ENVIRONMENTS。挙動不変) */
diff --git a/app/Services/Billing/AutoRechargeService.php b/app/Services/Billing/AutoRechargeService.php
new file mode 100644
index 0000000..9e8642f
--- /dev/null
+++ b/app/Services/Billing/AutoRechargeService.php
@@ -0,0 +1,1206 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing;
+
+use App\DataTransferObjects\Billing\AutoRechargeConsentDto;
+use App\DataTransferObjects\Billing\AutoRechargeConsentTermsDto;
+use App\DataTransferObjects\Billing\AutoRechargeSettingsDto;
+use App\Enums\Billing\AutoRechargeAttemptStatus;
+use App\Enums\Billing\AutoRechargeDisabledReason;
+use App\Enums\Billing\BillingNotificationType;
+use App\Enums\CheckoutIntent;
+use App\Enums\CheckoutSessionStatus;
+use App\Exceptions\Billing\CheckoutInProgressException;
+use App\Jobs\Billing\ExecuteAutoRechargeAttemptJob;
+use App\Models\Billing\BillingCheckoutSession;
+use App\Models\Billing\TicketAutoRecharge;
+use App\Models\Billing\TicketAutoRechargeAttempt;
+use App\Models\Billing\TicketVolumePrice;
+use App\Models\Organization;
+use App\Models\User;
+use App\Notifications\Billing\AutoRechargeActionRequiredNotification;
+use App\Notifications\Billing\AutoRechargeDisabledNotification;
+use App\Notifications\Billing\AutoRechargeEnabledNotification;
+use App\Notifications\Billing\AutoRechargeFailedNotification;
+use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
+use Carbon\CarbonImmutable;
+use Illuminate\Contracts\Cache\LockTimeoutException;
+use Illuminate\Database\QueryException;
+use Illuminate\Support\Facades\Cache;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Log;
+use Illuminate\Support\Str;
+use Illuminate\Validation\ValidationException;
+use RuntimeException;
+use Throwable;
+use Webmozart\Assert\Assert;
+
+/**
+ * P8a: オートリチャージ (裏チャージ) の中核サービス。**opt-in・既定 off**。
+ *
+ * 責務境界: ledger (TicketLedgerEntry) = 残高の唯一の真実源 (返金逆引きの正本も同一台帳の
+ * payment_intent_id / purchase_amount。D30 で `ticket_purchases` の両建ては作らない) /
+ * attempt = リチャージ試行の状態機械 (本サービスの管轄)。
+ *
+ * 課金の不変条件 (AGENTS.md セキュリティ不変条件 #7):
+ *  - quantity は attempt 作成 (org 行ロック TX 内) で一度だけ確定する
+ *  - org に pending attempt は 1 つまで (アプリロック + DB partial unique の二層)
+ *  - failed / canceled への遷移は invoice の終端 (void/delete) 成功後のみ (遅延成功の二重課金排除)
+ *  - SCA (authentication_required) は終端させない (pending 維持 + 復旧導線。期限切れはリコンサイル)
+ *  - 付与は `recharge:{invoiceId}` の ledger 冪等 (webhook / 同期 pay / リコンサイルのどれが先でも 1 回)
+ *
+ * 閾値判定・数量確定は **`TicketLedgerService::availableTrueBalance()`** を使う
+ * (表示用 `balance()->totalAvailable()` は clamp 済みで判定に使うと過剰補充になる)。
+ */
+final class AutoRechargeService
+{
+    /**
+     * org lock の TTL。updateSettings (cancelPendingAttempts の terminateInvoice) と
+     * executeAttempt (invoice create/pay) の両方が lock 内で外向き Stripe API を呼ぶため、
+     * Stripe client timeout より十分長く統一する (TTL 失効による直列化の破れを防ぐ)。
+     * block 待機は短いまま (競合時は no-op / リコンサイル再試行)。
+     */
+    private const int LOCK_TTL_SECONDS = 180;
+
+    public function __construct(
+        private readonly TicketLedgerService $tickets,
+        private readonly TicketPricingService $pricing,
+        private readonly AutoRechargeGatewayInterface $gateway,
+        private readonly BillingNotificationDispatcher $notifications,
+    ) {}
+
+    // ------------------------------------------------------------------
+    // 設定 (Inertia props / upsert)
+    // ------------------------------------------------------------------
+
+    /**
+     * 設定行の enabled のみの軽量解決 (PM 状態は見ない)。購入ページの転換バナー出し分け用途 —
+     * props 構築で settingsFor のカタログ解決コストを払わない。
+     */
+    public function isEnabledFor(Organization $organization): bool
+    {
+        return TicketAutoRecharge::query()
+            ->where('organization_id', $organization->getKey())
+            ->where('enabled', true)
+            ->exists();
+    }
+
+    public function settingsFor(Organization $organization, bool $canManage): AutoRechargeSettingsDto
+    {
+        $config = $this->configFor($organization);
+
+        $pmBrand = null;
+        $pmLast4 = null;
+        $hasPm = false;
+        if ($config?->stripe_payment_method_id !== null) {
+            $hasPm = true;
+            // brand/last4 は organizations の Cashier snapshot (pm_type/pm_last_four) を第一出典に
+            // する (props 構築で Stripe API を撃たない)。
+            $pmBrand = $organization->pm_type;
+            $pmLast4 = $organization->pm_last_four;
+        }
+
+        // 再同意要否 (共通判定 reconsentRequiredFor): version 改定・価格改定・上限超過のいずれか。
+        // true の間は createAttemptLocked の同一判定により自動購入が停止している。
+        $requiresReconsent = $config !== null && $config->enabled
+            && $this->reconsentRequiredFor($config, $config->max_count);
+
+        // 有効な事前同意が待機中 (= カード登録が完了すれば applySetupCompletion が自動有効化する
+        // 状態)。PM 有無は必ず local snapshot (stripe_payment_method_id) で判定する — gateway 側
+        // default PM を参照すると setDefaultPaymentMethod 後〜snapshot 反映前の窓で false になり、
+        // フォールバック同意ダイアログが誤オープンする。
+        $pendingAutoEnable = $config !== null
+            && $config->stripe_payment_method_id === null
+            && $this->autoEnableEligible($config);
+
+        // 「処理中」判定 = setup Checkout 完了済みだが PM snapshot 未反映。
+        // (P9 の signup-funding 契約経由の PM 流用は本フェーズでは配線されない。)
+        $setupPending = ! $hasPm && $this->hasRecentCompletedSetup($organization);
+
+        return new AutoRechargeSettingsDto(
+            enabled: $config !== null && $config->enabled,
+            thresholdCount: $config !== null ? $config->threshold_count : $this->defaultThreshold(),
+            maxCount: $config !== null ? $config->max_count : $this->defaultMax(),
+            minCount: TicketVolumePrice::PURCHASE_MIN_COUNT,
+            maxCountLimit: $this->maxCountLimit(),
+            canManage: $canManage,
+            hasPaymentMethod: $hasPm,
+            paymentMethodBrand: $pmBrand,
+            paymentMethodLast4: $pmLast4,
+            setupPending: $setupPending,
+            requiresReconsent: $requiresReconsent,
+            pendingAutoEnable: $pendingAutoEnable,
+            disabledReason: $config?->disabled_reason?->value,
+            failureCount: $config !== null ? $config->failure_count : 0,
+            consentVersion: $this->currentConsentVersion(),
+            baseUnitAmountJpy: $this->pricing->spotUnitAmount(),
+            tiers: $this->pricing->volumeTiersForDisplay(),
+        );
+    }
+
+    /**
+     * 設定 upsert。有効化は fail-closed (default PM 必須 + 同意必須)。無効化は常に成功する。
+     */
+    public function updateSettings(
+        Organization $organization,
+        User $user,
+        bool $enabled,
+        int $threshold,
+        int $max,
+        ?AutoRechargeConsentDto $consent,
+    ): TicketAutoRecharge {
+        Assert::greaterThan($max, $threshold, 'リチャージ上限は閾値より大きい必要があります');
+        Assert::lessThanEq($max, $this->maxCountLimit());
+        Assert::greaterThanEq($threshold, 0);
+
+        $lock = Cache::lock($this->lockName($organization), self::LOCK_TTL_SECONDS);
+
+        try {
+            /** @var TicketAutoRecharge $result */
+            $result = $lock->block(5, function () use ($organization, $user, $enabled, $threshold, $max, $consent): TicketAutoRecharge {
+                if ($enabled) {
+                    // 有効化 fail-closed: default PM が存在しなければ拒否 (422)。
+                    if (! $this->gateway->getDefaultPaymentMethodState($organization)->exists()) {
+                        throw ValidationException::withMessages([
+                            'enabled' => 'オートリチャージを有効にするには、先にお支払いカードを登録してください。',
+                        ]);
+                    }
+                }
+
+                $config = DB::transaction(function () use ($organization, $user, $enabled, $threshold, $max, $consent): TicketAutoRecharge {
+                    $config = $this->lockedConfigFor($organization);
+
+                    $attrs = [
+                        'enabled' => $enabled,
+                        'threshold_count' => $threshold,
+                        'max_count' => $max,
+                    ];
+
+                    if ($enabled) {
+                        // 同意判定: 共通判定 reconsentRequiredFor (version 改定/上限超過/価格改定)。
+                        // 新しい $max で評価する (Max 引き上げは同意時上限超過として検出される)。
+                        $needsConsent = $config === null || $this->reconsentRequiredFor($config, $max);
+
+                        if ($needsConsent) {
+                            if ($consent === null || $consent->version !== $this->currentConsentVersion()) {
+                                throw ValidationException::withMessages([
+                                    'consent_version' => '自動購入の同意内容が更新されています。内容を確認して再度同意してください。',
+                                ]);
+                            }
+                            // 同意金額はサーバ再計算 (client hidden は信用しない)。
+                            $attrs['consented_at'] = CarbonImmutable::now();
+                            $attrs['consent_version'] = $consent->version;
+                            $attrs['consented_max_count'] = $max;
+                            $attrs['consented_max_amount'] = $this->maxChargeAmountFor($max);
+                        }
+
+                        // 再有効化で失敗状態をリセット。
+                        $attrs['failure_count'] = 0;
+                        $attrs['disabled_reason'] = null;
+                        // PM snapshot が空なら gateway の現状で補完 (setup Job 未達の間に有効化された場合)。
+                        if ($config?->stripe_payment_method_id === null) {
+                            $attrs['stripe_payment_method_id'] = $this->gateway->getDefaultPaymentMethodState($organization)->paymentMethodId;
+                        }
+                    } else {
+                        $attrs['disabled_reason'] = AutoRechargeDisabledReason::User;
+                    }
+
+                    return $this->persistConfig($organization, $config, $attrs, $user);
+                });
+
+                if (! $enabled) {
+                    // 停止後課金の禁止。停止時点の pending attempt を同一 lock 下でキャンセルする
+                    // (invoice 終端成功後のみ canceled 遷移。既に paid の invoice は終端できず
+                    // pending 維持 → リコンサイルが付与して収束 = 回収済み資金は必ずチケットになる)。
+                    $this->cancelPendingAttempts($organization);
+                }
+
+                return $config;
+            });
+
+            return $result;
+        } catch (LockTimeoutException $e) {
+            // ユーザーの明示操作のみ UX エラーへ変換 (background trigger は structured no-op)。
+            throw new CheckoutInProgressException('別の変更操作が進行中です。数秒お待ちください。', previous: $e);
+        }
+    }
+
+    /**
+     * カード登録 (Checkout mode=setup) を開始する。attempt_token 冪等は purchase-tickets と同型。
+     *
+     * @return array{id: string, url: string|null}
+     */
+    public function startSetupCheckout(
+        Organization $organization,
+        User $user,
+        string $successUrl,
+        string $cancelUrl,
+        string $attemptToken,
+    ): array {
+        Assert::stringNotEmpty($attemptToken);
+
+        $idempotencyKey = 'auto-recharge-setup:'.$attemptToken;
+
+        $result = $this->gateway->createSetupCheckout(
+            $organization,
+            $successUrl,
+            $cancelUrl,
+            [
+                'purpose' => 'auto_recharge_setup',
+                'organization_id' => (string) $this->orgId($organization),
+            ],
+            $idempotencyKey,
+        );
+
+        // 台帳記録 (webhook の intent 照合 / setupPending 判定の出典)。attempt_token unique で
+        // 二重 submit は冪等 (unique violation は既存行の再利用として握る)。
+        // insert は DB::transaction (= 外側 TX 下では savepoint) で包む — unique violation が
+        // 呼び出し元 TX を abort させない (pgsql の 25P02 連鎖を避ける)。
+        try {
+            DB::transaction(function () use ($organization, $user, $result, $attemptToken, $idempotencyKey): void {
+                $session = new BillingCheckoutSession;
+                // tenant / actor キーは relation 経由で明示代入する (mass assignment しない)
+                $session->organization()->associate($organization);
+                $session->initiated_by_user_id = $user->id;
+                $session->fill([
+                    'intent' => CheckoutIntent::SetupPaymentMethod->value,
+                    'plan_code' => null,
+                    'stripe_session_id' => $result['id'],
+                    'status' => CheckoutSessionStatus::Pending->value,
+                    'idempotency_key' => $idempotencyKey,
+                    'attempt_token' => $attemptToken,
+                    'checkout_url' => $result['url'],
+                ]);
+                $session->save();
+            });
+        } catch (QueryException $e) {
+            if (! $this->isUniqueViolation($e)) {
+                throw $e;
+            }
+            // 同一 attempt_token の replay — Stripe 側も同一冪等キーで同一 session を返している。
+        }
+
+        return $result;
+    }
+
+    /**
+     * D29(i): オンボーディング同意提示 + 事前同意記録が共有する「同意条件」の単一計算源。
+     * ここで返す値がそのまま画面に表示され、recordPreConsent がそのまま記録する。
+     */
+    public function consentTermsFor(): AutoRechargeConsentTermsDto
+    {
+        $threshold = $this->defaultThreshold();
+        $max = $this->defaultMax();
+        $tier = TicketVolumePrice::currentTierFor($max);
+
+        return new AutoRechargeConsentTermsDto(
+            thresholdCount: $threshold,
+            maxCount: $max,
+            maxAmountJpy: $tier->unitAmount * $max,
+            unitAmountJpy: $tier->unitAmount,
+            consentVersion: $this->currentConsentVersion(),
+        );
+    }
+
+    /**
+     * D29(i): オンボーディングでの事前同意を記録する (enabled=false のまま)。
+     *
+     * fail-closed: client から受けるのは consent_version のみで、現在版と完全一致しなければ 422
+     * (画面表示と異なる条件での同意記録を排除)。記録する枚数・金額はサーバ再計算値のみ
+     * (consentTermsFor と同一計算源)。enabled 済み設定は上書きしない (運用値と同意の保全)。
+     * 既存 row が disabled_reason を持つ場合もそれを消さない (自動有効化は autoEnableEligible で
+     * 止まる)。既に PM snapshot がある row への同意も enabled にしない (pendingAutoEnable も
+     * false — 有効化は請求ページの既存 UI に委ねる)。
+     */
+    public function recordPreConsent(Organization $organization, User $user, AutoRechargeConsentDto $consent): TicketAutoRecharge
+    {
+        if ($consent->version !== $this->currentConsentVersion()) {
+            throw ValidationException::withMessages([
+                'consent_version' => '自動購入の同意内容が更新されています。ページを再読み込みして内容を確認してください。',
+            ]);
+        }
+
+        $terms = $this->consentTermsFor();
+        $lock = Cache::lock($this->lockName($organization), self::LOCK_TTL_SECONDS);
+
+        try {
+            /** @var TicketAutoRecharge $result */
+            $result = $lock->block(5, fn (): TicketAutoRecharge => DB::transaction(
+                function () use ($organization, $user, $terms): TicketAutoRecharge {
+                    $config = $this->lockedConfigFor($organization);
+
+                    if ($config !== null && $config->enabled) {
+                        return $config; // 稼働中設定は上書きしない
+                    }
+
+                    return $this->persistConfig($organization, $config, [
+                        'enabled' => false,
+                        'threshold_count' => $terms->thresholdCount,
+                        'max_count' => $terms->maxCount,
+                        'consented_at' => CarbonImmutable::now(),
+                        'consent_version' => $terms->consentVersion,
+                        'consented_max_count' => $terms->maxCount,
+                        'consented_max_amount' => $terms->maxAmountJpy,
+                    ], $user);
+                },
+            ));
+
+            return $result;
+        } catch (LockTimeoutException $e) {
+            throw new CheckoutInProgressException('別の変更操作が進行中です。数秒お待ちください。', previous: $e);
+        }
+    }
+
+    /**
+     * org の pending attempt を全てキャンセル試行する (ユーザー停止時)。
+     * 終端 (void/delete) に失敗した attempt は pending 維持 — 遅延成功はリコンサイル (ii) が
+     * 付与で収束し、未回収はリコンサイル (iv) が期限切れ終端する。
+     */
+    private function cancelPendingAttempts(Organization $organization): void
+    {
+        $pendings = TicketAutoRechargeAttempt::query()
+            ->where('organization_id', $organization->getKey())
+            ->where('status', AutoRechargeAttemptStatus::Pending->value)
+            ->get();
+
+        foreach ($pendings as $attempt) {
+            $this->terminateAndCancel($attempt);
+        }
+    }
+
+    // ------------------------------------------------------------------
+    // attempt 起票 (トリガ/リコンサイル共通の唯一の起票口)
+    // ------------------------------------------------------------------
+
+    /**
+     * 閾値判定 + attempt 起票。作られなかったら null (無効/閾値以上/pending あり/ロック競合)。
+     * lock 取得失敗は structured no-op — バックグラウンドトリガの競合は次回 reserve /
+     * リコンサイルが拾うため UX エラーにしない。
+     */
+    public function maybeCreateAttempt(Organization $organization): ?TicketAutoRechargeAttempt
+    {
+        $lock = Cache::lock($this->lockName($organization), self::LOCK_TTL_SECONDS);
+
+        try {
+            /** @var TicketAutoRechargeAttempt|null $attempt */
+            $attempt = $lock->block(3, fn (): ?TicketAutoRechargeAttempt => $this->createAttemptLocked($organization));
+
+            return $attempt;
+        } catch (LockTimeoutException) {
+            Log::info('auto-recharge: lock busy, skipping trigger (background no-op)', [
+                'organization_id' => $organization->getKey(),
+            ]);
+
+            return null;
+        }
+    }
+
+    private function createAttemptLocked(Organization $organization): ?TicketAutoRechargeAttempt
+    {
+        try {
+            return DB::transaction(function () use ($organization): ?TicketAutoRechargeAttempt {
+                // reserve() と同順の organizations 行ロックで残高評価〜起票を直列化する
+                // (ロック順序の交差を作らない)。
+                $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->first();
+                Assert::isInstanceOf($locked, Organization::class);
+
+                $config = $this->configFor($locked);
+                if ($config === null || ! $config->enabled) {
+                    return null;
+                }
+
+                $pendingExists = TicketAutoRechargeAttempt::query()
+                    ->where('organization_id', $locked->getKey())
+                    ->where('status', AutoRechargeAttemptStatus::Pending->value)
+                    ->exists();
+                if ($pendingExists) {
+                    return null;
+                }
+
+                // 真値残高 (与信と同一意味論) で再評価。閾値以上に回復していれば no-op。
+                // **表示用 balance() ではなく availableTrueBalance()** — clamp 済みの表示値で
+                // 判定すると返金債務を隠して過剰補充する。
+                $balance = $this->tickets->availableTrueBalance($locked);
+                if ($balance >= $config->threshold_count) {
+                    return null;
+                }
+
+                // quantity はこの一点で確定し、以降 attempt.quantity が真実源。
+                // availableTrueBalance は構造的に >= 0 (per-source max(...,0)) のため
+                // quantity <= max_count (= 同意上限の不変条件)。
+                $quantity = min($config->max_count - $balance, TicketVolumePrice::PURCHASE_MAX_COUNT);
+                Assert::greaterThan($quantity, 0);
+
+                // 同意の hard invariant。UI の requiresReconsent / updateSettings の needsConsent と
+                // **同一の共通判定** (version 改定・上限超過・価格改定) で評価し、再同意が必要な間は
+                // 起票しない (UI 文言「再同意まで自動購入は行われません」と完全に一致する。設定上の
+                // max_count 基準 — quantity <= max_count かつ総額は数量に単調のため、これが binding)。
+                if ($this->reconsentRequiredFor($config, $config->max_count)) {
+                    Log::warning('auto-recharge: skipping attempt, re-consent required', [
+                        'organization_id' => $locked->getKey(),
+                        'consent_version' => $config->consent_version,
+                        'consented_max_count' => $config->consented_max_count,
+                        'consented_max_amount' => $config->consented_max_amount,
+                    ]);
+
+                    return null;
+                }
+
+                $tier = TicketVolumePrice::currentTierFor($quantity);
+
+                $attempt = new TicketAutoRechargeAttempt;
+                $attempt->organization()->associate($locked);
+                $attempt->fill([
+                    'attempt_ulid' => strtolower((string) Str::ulid()),
+                    'status' => AutoRechargeAttemptStatus::Pending->value,
+                    'quantity' => $quantity,
+                    'unit_amount' => $tier->unitAmount,
+                    'stripe_price_id' => $tier->stripePriceId,
+                ]);
+                $attempt->save();
+
+                return $attempt;
+            });
+        } catch (QueryException $e) {
+            if ($this->isUniqueViolation($e)) {
+                // DB partial unique (tar_attempts_org_pending_unique) が最終防衛。並行起票は no-op。
+                return null;
+            }
+
+            throw $e;
+        }
+    }
+
+    // ------------------------------------------------------------------
+    // attempt 実行 (課金)
+    // ------------------------------------------------------------------
+
+    /**
+     * pending attempt を実行する: invoice 作成 → invoice_id 永続化 (pay より前・必達) → 課金。
+     *
+     * updateSettings (停止 + pending キャンセル) と**同一の org lock**で直列化する。lock 内では
+     * disable が割り込めないため、「enabled 確認 → invoice 作成 → invoice_id 保存 → pay」の
+     * 全区間で停止後課金が構造的に起こらない。
+     * lock 取得失敗は structured no-op — リコンサイル (i) が再実行する。
+     */
+    public function executeAttempt(TicketAutoRechargeAttempt $attempt): void
+    {
+        $organization = $attempt->organization;
+        Assert::isInstanceOf($organization, Organization::class);
+
+        $lock = Cache::lock($this->lockName($organization), self::LOCK_TTL_SECONDS);
+
+        try {
+            $lock->block(10, function () use ($organization, $attempt): void {
+                $this->executeAttemptLocked($organization, $attempt);
+            });
+        } catch (LockTimeoutException) {
+            Log::info('auto-recharge: lock busy, skipping execution (reconcile will retry)', [
+                'attempt_ulid' => $attempt->attempt_ulid,
+            ]);
+        }
+    }
+
+    private function executeAttemptLocked(Organization $organization, TicketAutoRechargeAttempt $attempt): void
+    {
+        // lock 取得後に fresh 再読込 (停止側のキャンセルが先行していたら no-op)。
+        $attempt->refresh();
+        if ($attempt->status !== AutoRechargeAttemptStatus::Pending) {
+            return;
+        }
+
+        // 停止後課金の禁止: lock 内で enabled を確認 (以降 disable は本実行の完了まで割り込めない)。
+        if (! $this->isEnabledFor($organization)) {
+            $this->terminateAndCancel($attempt);
+
+            return;
+        }
+
+        $keyBase = $this->idempotencyKeyBase($attempt);
+
+        $invoiceId = $attempt->stripe_invoice_id;
+        if ($invoiceId === null) {
+            $invoiceId = $this->gateway->createAutoRechargeInvoice(
+                $organization,
+                $attempt->stripe_price_id,
+                $attempt->quantity,
+                $this->metadataFor($organization, $attempt),
+                $keyBase,
+            );
+            // invoice_id の永続化は pay より必ず前 (プロセス死でも迷子 invoice を作らない)。
+            $attempt->forceFill(['stripe_invoice_id' => $invoiceId])->save();
+        }
+
+        $result = $this->gateway->payOffSessionInvoice($invoiceId, $keyBase);
+
+        if ($result->paid) {
+            $amountPaid = $result->amountPaid;
+            $amountDue = $result->amountDue;
+            Assert::integer($amountPaid);
+            Assert::integer($amountDue);
+            $this->recordSuccessfulCharge($organization, $attempt, $invoiceId, $amountPaid, $amountDue, $result->paymentIntentId);
+
+            return;
+        }
+
+        $this->handleChargeFailure($organization, $attempt, $result->failureCode, $result->requiresAction());
+    }
+
+    /**
+     * 課金成功の確定: 冪等付与 + attempt paid 遷移 + failure_count リセット。
+     * webhook (invoice.paid) / 同期 pay / リコンサイル (ii) の全経路がここに合流する。
+     */
+    public function recordSuccessfulCharge(
+        Organization $organization,
+        TicketAutoRechargeAttempt $attempt,
+        string $invoiceId,
+        int $amountPaid,
+        int $amountDue,
+        ?string $paymentIntentId,
+    ): void {
+        // amount cross-check (fail-closed): attempt に pin した単価 × 数量 = 請求額 (amount_due)。
+        // 実回収額 (amount_paid) は customer credit balance の適用で amount_due より小さくなり得る
+        // 正当ケースがあるため照合対象にしない。台帳の purchase_amount には実回収額を記録する。
+        $expected = $attempt->unit_amount * $attempt->quantity;
+        if ($amountDue !== $expected) {
+            throw new RuntimeException(
+                "auto-recharge amount mismatch for invoice {$invoiceId}: expected due {$expected}, got {$amountDue}",
+            );
+        }
+
+        DB::transaction(function () use ($organization, $attempt, $invoiceId, $amountPaid, $paymentIntentId): void {
+            $this->tickets->grantAutoRecharge($organization, $attempt->quantity, $invoiceId, $amountPaid, $paymentIntentId);
+
+            $updated = TicketAutoRechargeAttempt::query()
+                ->whereKey($attempt->id)
+                ->where('status', AutoRechargeAttemptStatus::Pending->value)
+                ->update([
+                    'status' => AutoRechargeAttemptStatus::Paid->value,
+                    'stripe_payment_intent_id' => $paymentIntentId,
+                    'resolved_at' => CarbonImmutable::now(),
+                    'updated_at' => CarbonImmutable::now(),
+                ]);
+
+            if ($updated === 1) {
+                TicketAutoRecharge::query()
+                    ->where('organization_id', $organization->getKey())
+                    ->update(['failure_count' => 0, 'updated_at' => CarbonImmutable::now()]);
+            }
+        });
+    }
+
+    /**
+     * 課金失敗の処理。SCA (authentication_required) は終端させない —
+     * pending 維持 + failure_code 記録 + 復旧導線通知 (期限切れ終端はリコンサイル (iv) の管轄)。
+     * それ以外 (card_declined 等の再試行不能失敗) は invoice 終端 → failed 遷移 + failure_count+1。
+     */
+    public function handleChargeFailure(
+        Organization $organization,
+        TicketAutoRechargeAttempt $attempt,
+        ?string $failureCode,
+        bool $requiresAction,
+    ): void {
+        // failure_code は観測のため常に記録 (pending のまま)。
+        TicketAutoRechargeAttempt::query()
+            ->whereKey($attempt->id)
+            ->where('status', AutoRechargeAttemptStatus::Pending->value)
+            ->update(['failure_code' => $failureCode, 'updated_at' => CarbonImmutable::now()]);
+
+        if ($requiresAction) {
+            $this->notifyActionRequired($organization, $attempt);
+
+            return;
+        }
+
+        $this->terminateAndFail($organization, $attempt);
+    }
+
+    /**
+     * invoice 終端 → failed 遷移 (+failure_count/自動停止)。終端失敗時は pending 維持で
+     * リコンサイルが再試行する (終端保証を破らない)。
+     */
+    public function terminateAndFail(Organization $organization, TicketAutoRechargeAttempt $attempt): void
+    {
+        if (! $this->tryTerminateInvoice($attempt)) {
+            return; // pending 維持 → リコンサイル再試行
+        }
+
+        if ($this->transitionToTerminal($attempt, AutoRechargeAttemptStatus::Failed)) {
+            $this->notifyFailed($organization, $attempt);
+        }
+    }
+
+    /**
+     * invoice 終端 → canceled 遷移 (決済手段の問題ではない破棄。failure_count 増分なし)。
+     */
+    public function terminateAndCancel(TicketAutoRechargeAttempt $attempt): void
+    {
+        if (! $this->tryTerminateInvoice($attempt)) {
+            return;
+        }
+
+        $this->transitionToTerminal($attempt, AutoRechargeAttemptStatus::Canceled);
+    }
+
+    private function tryTerminateInvoice(TicketAutoRechargeAttempt $attempt): bool
+    {
+        if ($attempt->stripe_invoice_id === null) {
+            return true; // invoice 未作成 = 課金され得ない
+        }
+
+        try {
+            $this->gateway->terminateInvoice($attempt->stripe_invoice_id);
+
+            return true;
+        } catch (Throwable $e) {
+            Log::warning('auto-recharge: invoice termination failed, keeping attempt pending', [
+                'attempt_ulid' => $attempt->attempt_ulid,
+                'invoice_id' => $attempt->stripe_invoice_id,
+                'error' => $e->getMessage(),
+            ]);
+
+            return false;
+        }
+    }
+
+    /**
+     * failed / canceled への唯一の遷移口。WHERE status='pending' ガードで 1 attempt = 1 遷移。
+     * failed のときのみ failure_count+1 (= 1 attempt で複数の payment_failed イベントが来ても
+     * 多重加算しない) し、連続失敗上限で自動停止する。
+     *
+     * @return bool 遷移が起きたか (false = 既に終端済みの再送)
+     */
+    private function transitionToTerminal(TicketAutoRechargeAttempt $attempt, AutoRechargeAttemptStatus $terminal): bool
+    {
+        Assert::true(
+            $terminal === AutoRechargeAttemptStatus::Failed || $terminal === AutoRechargeAttemptStatus::Canceled,
+            'transitionToTerminal は failed / canceled のみ',
+        );
+
+        return DB::transaction(function () use ($attempt, $terminal): bool {
+            $updated = TicketAutoRechargeAttempt::query()
+                ->whereKey($attempt->id)
+                ->where('status', AutoRechargeAttemptStatus::Pending->value)
+                ->update([
+                    'status' => $terminal->value,
+                    'resolved_at' => CarbonImmutable::now(),
+                    'updated_at' => CarbonImmutable::now(),
+                ]);
+
+            if ($updated !== 1) {
+                return false;
+            }
+
+            if ($terminal === AutoRechargeAttemptStatus::Failed) {
+                $config = TicketAutoRecharge::query()
+                    ->where('organization_id', $attempt->organization_id)
+                    ->lockForUpdate()
+                    ->first();
+
+                if ($config !== null) {
+                    $config->failure_count += 1;
+                    if ($config->enabled && $config->failure_count >= $this->maxFailures()) {
+                        $config->enabled = false;
+                        $config->disabled_reason = AutoRechargeDisabledReason::PaymentFailures;
+                        $organization = $config->organization;
+                        Assert::isInstanceOf($organization, Organization::class);
+                        $this->notifyDisabled($organization, $attempt);
+                    }
+                    $config->save();
+                }
+            }
+
+            return true;
+        });
+    }
+
+    // ------------------------------------------------------------------
+    // リコンサイル (scheduler)
+    // ------------------------------------------------------------------
+
+    /**
+     * pending attempt の回収と取りこぼし起票 (5 分岐)。
+     * webhook が terminal-ack で恒久 drop した「課金済み・付与なし」の唯一のセーフティネット。
+     *
+     * @return array{recovered_paid: int, retried: int, sca_reminded: int, expired: int, triggered: int}
+     */
+    public function reconcile(): array
+    {
+        $stats = ['recovered_paid' => 0, 'retried' => 0, 'sca_reminded' => 0, 'expired' => 0, 'triggered' => 0];
+        $now = CarbonImmutable::now();
+        $expiryHours = $this->pendingExpiryHours();
+
+        $pendings = TicketAutoRechargeAttempt::query()
+            ->where('status', AutoRechargeAttemptStatus::Pending->value)
+            ->orderBy('id')
+            ->get();
+
+        foreach ($pendings as $attempt) {
+            $organization = $attempt->organization;
+            Assert::isInstanceOf($organization, Organization::class);
+            $createdAt = $attempt->created_at;
+            Assert::notNull($createdAt);
+            $age = CarbonImmutable::instance($createdAt);
+
+            try {
+                if ($attempt->stripe_invoice_id === null) {
+                    // (i) invoice 未作成: scheduler 周期 (15 分) 超で再実行。同一 key base で
+                    // Stripe 冪等が効くため二重課金しない。
+                    if ($age->addMinutes(15) <= $now) {
+                        $this->executeAttempt($attempt);
+                        $stats['retried']++;
+                    }
+
+                    continue;
+                }
+
+                $state = $this->gateway->retrieveInvoiceState($attempt->stripe_invoice_id);
+
+                if ($state->status === 'paid') {
+                    // (ii) webhook 未着 / terminal drop の回収。付与は ledger 冪等。
+                    $amountPaid = $state->amountPaid;
+                    $amountDue = $state->amountDue;
+                    Assert::integer($amountPaid);
+                    Assert::integer($amountDue);
+                    $this->recordSuccessfulCharge($organization, $attempt, $attempt->stripe_invoice_id, $amountPaid, $amountDue, $state->paymentIntentId);
+                    $stats['recovered_paid']++;
+
+                    continue;
+                }
+
+                if ($state->status === 'void' || $state->status === 'deleted') {
+                    // invoice は既に課金不能 — attempt を canceled で閉じる (終端保証は満たされている)。
+                    $this->transitionToTerminal($attempt, AutoRechargeAttemptStatus::Canceled);
+                    $stats['expired']++;
+
+                    continue;
+                }
+
+                // SCA 判定は Stripe 側 PaymentIntent 状態 (state) を第一出典、attempt の
+                // failure_code (同期 pay の CardException 記録) を補助にする (webhook 到着順に依存しない)。
+                $isSca = $state->requiresAction || $attempt->failure_code === 'authentication_required';
+
+                if ($age->addHours($expiryHours) <= $now) {
+                    // (iv) 期限切れ終端。SCA 放置は failed (+failure_count) — 放置ループ防止。
+                    // それ以外 (draft のまま等、決済手段の問題ではない) は canceled。
+                    if ($isSca) {
+                        $this->terminateAndFail($organization, $attempt);
+                    } else {
+                        $this->terminateAndCancel($attempt);
+                    }
+                    $stats['expired']++;
+
+                    continue;
+                }
+
+                if ($isSca) {
+                    // (iii) SCA 待ち: 日次リマインダ (dedup は JST date bucket)。
+                    $this->notifyActionRequired($organization, $attempt);
+                    $stats['sca_reminded']++;
+                }
+            } catch (Throwable $e) {
+                // 1 attempt の失敗が他 org の回収を止めないよう隔離 (次周期で再試行)。
+                Log::warning('auto-recharge reconcile: attempt processing failed', [
+                    'attempt_ulid' => $attempt->attempt_ulid,
+                    'error' => $e->getMessage(),
+                ]);
+            }
+        }
+
+        // (v) 取りこぼし起票: enabled な org で閾値割れ・pending なし (job 消失の回収)。
+        $configs = TicketAutoRecharge::query()->where('enabled', true)->orderBy('id')->get();
+        foreach ($configs as $config) {
+            $organization = $config->organization;
+            Assert::isInstanceOf($organization, Organization::class);
+
+            try {
+                $attempt = $this->maybeCreateAttempt($organization);
+                if ($attempt !== null) {
+                    ExecuteAutoRechargeAttemptJob::dispatch($attempt->id);
+                    $stats['triggered']++;
+                }
+            } catch (Throwable $e) {
+                Log::warning('auto-recharge reconcile: trigger failed', [
+                    'organization_id' => $organization->getKey(),
+                    'error' => $e->getMessage(),
+                ]);
+            }
+        }
+
+        return $stats;
+    }
+
+    // ------------------------------------------------------------------
+    // webhook 連携ヘルパ
+    // ------------------------------------------------------------------
+
+    public function findPendingAttemptByUlid(string $attemptUlid): ?TicketAutoRechargeAttempt
+    {
+        return TicketAutoRechargeAttempt::query()
+            ->where('attempt_ulid', $attemptUlid)
+            ->where('status', AutoRechargeAttemptStatus::Pending->value)
+            ->first();
+    }
+
+    public function findAttemptByUlid(string $attemptUlid): ?TicketAutoRechargeAttempt
+    {
+        return TicketAutoRechargeAttempt::query()->where('attempt_ulid', $attemptUlid)->first();
+    }
+
+    /**
+     * D29(i): setup Checkout 完了の適用 (SetDefaultPaymentMethodJob から): PM snapshot 更新 +
+     * 有効な事前同意があれば自動有効化。
+     *
+     * PM snapshot と enabled=true は同一 DB TX で確定する — 「PM あり && enabled=false」の
+     * 中間状態を props (settingsFor) に見せない。updateSettings / executeAttempt と同一の
+     * org lock で直列化する (停止操作との交錯を防ぐ)。
+     *
+     * @return bool 今回の呼び出しで enabled に遷移したか (カード差し替えの再 setup では false)
+     */
+    public function applySetupCompletion(Organization $organization, string $paymentMethodId): bool
+    {
+        Assert::stringNotEmpty($paymentMethodId);
+
+        $lock = Cache::lock($this->lockName($organization), self::LOCK_TTL_SECONDS);
+
+        try {
+            /** @var bool $enabledNow */
+            $enabledNow = $lock->block(10, fn (): bool => DB::transaction(
+                function () use ($organization, $paymentMethodId): bool {
+                    $config = $this->lockedConfigFor($organization);
+
+                    if ($config === null) {
+                        // 事前同意なしの手動カード登録: snapshot のみ。
+                        $this->persistConfig($organization, null, [
+                            'enabled' => false,
+                            'stripe_payment_method_id' => $paymentMethodId,
+                            'threshold_count' => $this->defaultThreshold(),
+                            'max_count' => $this->defaultMax(),
+                        ], null);
+
+                        return false;
+                    }
+
+                    $wasEnabled = $config->enabled;
+                    $config->stripe_payment_method_id = $paymentMethodId;
+
+                    if ($this->autoEnableEligible($config)) {
+                        // 自動有効化: default PM は直前に gateway::setDefaultPaymentMethod 済み
+                        // (SetDefaultPaymentMethodJob の呼び出し順で保証)。
+                        $config->enabled = true;
+                        $config->failure_count = 0;
+                    }
+
+                    $config->save();
+
+                    return $config->enabled && ! $wasEnabled;
+                },
+            ));
+        } catch (LockTimeoutException $e) {
+            // webhook Job (tries=3, backoff=30) の再試行に乗せる — snapshot 未反映のまま握り潰さない。
+            throw new RuntimeException('auto-recharge setup completion lock busy for org '.$this->orgId($organization), previous: $e);
+        }
+
+        if ($enabledNow) {
+            // 通知失敗で webhook Job を失敗させない (enabled は commit 済み。Job retry では
+            // enabled 遷移が再発しないため通知は再送されず、失敗だけが残る — ここで握って report)。
+            try {
+                $this->notifyAutoEnabled($organization, $paymentMethodId);
+            } catch (Throwable $e) {
+                report($e);
+            }
+        }
+
+        return $enabledNow;
+    }
+
+    /**
+     * 有効な事前同意が待機中か (= PM が届けば自動有効化される状態。settingsFor の
+     * pendingAutoEnable と同一定義の共通判定)。
+     */
+    public function isAutoEnablePending(Organization $organization): bool
+    {
+        $config = $this->configFor($organization);
+
+        return $config !== null
+            && $config->stripe_payment_method_id === null
+            && $this->autoEnableEligible($config);
+    }
+
+    /**
+     * 自動有効化の成立条件 (fail-closed)。同意証跡の完全性 + 既存共通判定
+     * reconsentRequiredFor (version 改定・価格改定・上限超過 — consented_* の null もここで
+     * 検出される) + 停止状態 (ユーザー停止/連続失敗停止) でないこと。
+     */
+    private function autoEnableEligible(TicketAutoRecharge $config): bool
+    {
+        if ($config->enabled || $config->disabled_reason !== null) {
+            return false;
+        }
+        if ($config->consented_at === null) {
+            return false;
+        }
+
+        return ! $this->reconsentRequiredFor($config, $config->max_count);
+    }
+
+    // ------------------------------------------------------------------
+    // 通知
+    // ------------------------------------------------------------------
+
+    private function notifyFailed(Organization $organization, TicketAutoRechargeAttempt $attempt): void
+    {
+        // dedup は attempt 単位 (同一 attempt の webhook 再送で再通知しない)。
+        $dedupKey = 'auto_recharge_failed:'.$attempt->attempt_ulid;
+
+        $this->notifications->sendReminderOnce(
+            $organization,
+            BillingNotificationType::AutoRechargeFailed,
+            $dedupKey,
+            new AutoRechargeFailedNotification(
+                $dedupKey,
+                $organization->name,
+                route('billing.index'),
+                route('billing.tickets.show'),
+            ),
+        );
+    }
+
+    private function notifyDisabled(Organization $organization, TicketAutoRechargeAttempt $attempt): void
+    {
+        $dedupKey = 'auto_recharge_disabled:'.$attempt->attempt_ulid;
+
+        $this->notifications->sendReminderOnce(
+            $organization,
+            BillingNotificationType::AutoRechargeDisabled,
+            $dedupKey,
+            new AutoRechargeDisabledNotification(
+                $dedupKey,
+                $organization->name,
+                route('billing.index'),
+            ),
+        );
+    }
+
+    /**
+     * 自動有効化の事後通知 (同意の代替ではない — 同意成立はオンボーディング画面の
+     * affirmative action)。金額は保存済みの同意値 (consented_max_amount) — ユーザーが同意した
+     * 金額そのものを通知する (現行 tier の再計算ではない)。
+     */
+    private function notifyAutoEnabled(Organization $organization, string $paymentMethodId): void
+    {
+        $config = $this->configFor($organization);
+        if ($config === null) {
+            report(new RuntimeException('auto-recharge enabled notification: config missing for org '.$this->orgId($organization)));
+
+            return;
+        }
+
+        // dedup は org + PM 単位 (同一 setup 完了 webhook の再送で二重送信しない)。
+        $dedupKey = 'auto_recharge_enabled:'.$this->orgId($organization).':'.$paymentMethodId;
+
+        $this->notifications->sendReminderOnce(
+            $organization,
+            BillingNotificationType::AutoRechargeEnabled,
+            $dedupKey,
+            new AutoRechargeEnabledNotification(
+                $dedupKey,
+                $organization->name,
+                $config->threshold_count,
+                $config->max_count,
+                $config->consented_max_amount,
+                $organization->pm_last_four,
+                route('billing.index'),
+            ),
+        );
+    }
+
+    private function notifyActionRequired(Organization $organization, TicketAutoRechargeAttempt $attempt): void
+    {
+        $invoiceId = $attempt->stripe_invoice_id;
+        if ($invoiceId === null) {
+            return;
+        }
+
+        // 復旧リンクは invoice の hosted_invoice_url (Stripe ホストページで認証完了できる)。
+        $hostedUrl = $this->gateway->retrieveInvoiceState($invoiceId)->hostedInvoiceUrl;
+
+        // dedup は JST date bucket (日次で再通知を許す — 放置での失効を防ぐ)。
+        $bucket = CarbonImmutable::now('Asia/Tokyo')->format('Y-m-d');
+        $dedupKey = 'auto_recharge_sca:'.$invoiceId.':'.$bucket;
+
+        $this->notifications->sendReminderOnce(
+            $organization,
+            BillingNotificationType::AutoRechargeActionRequired,
+            $dedupKey,
+            new AutoRechargeActionRequiredNotification(
+                $dedupKey,
+                $organization->name,
+                $hostedUrl ?? route('billing.index'),
+            ),
+        );
+    }
+
+    // ------------------------------------------------------------------
+    // 内部ヘルパ
+    // ------------------------------------------------------------------
+
+    /**
+     * 再同意が必要か (UI 表示 / 設定更新 / 自動有効化 / attempt 起票停止の **4 箇所で共有**
+     * する単一述語)。$max は評価対象の上限 (設定更新時は新値、それ以外は現設定の max_count)。
+     *
+     * - 同意文言 version の不一致 (文言改定で既存同意を失効させる)
+     * - 同意記録の欠落
+     * - $max が同意時上限を超過
+     * - 現行カタログでの最大請求額が同意時金額を超過 (価格改定)
+     */
+    private function reconsentRequiredFor(TicketAutoRecharge $config, int $max): bool
+    {
+        if ($config->consent_version !== $this->currentConsentVersion()) {
+            return true;
+        }
+        if ($config->consented_max_count === null || $max > $config->consented_max_count) {
+            return true;
+        }
+        if ($config->consented_max_amount === null) {
+            return true;
+        }
+
+        return $this->maxChargeAmountFor($max) > $config->consented_max_amount;
+    }
+
+    /** 同意上限額のサーバ再計算 (client hidden の金額は信用しない)。 */
+    private function maxChargeAmountFor(int $max): int
+    {
+        return TicketVolumePrice::currentTierFor($max)->unitAmount * $max;
+    }
+
+    private function configFor(Organization $organization): ?TicketAutoRecharge
+    {
+        return TicketAutoRecharge::query()->where('organization_id', $organization->getKey())->first();
+    }
+
+    private function lockedConfigFor(Organization $organization): ?TicketAutoRecharge
+    {
+        return TicketAutoRecharge::query()
+            ->where('organization_id', $organization->getKey())
+            ->lockForUpdate()
+            ->first();
+    }
+
+    /**
+     * 設定行の upsert。tenant / actor キー (organization_id / created_by_user_id) は
+     * $fillable に無いため relation 経由で明示代入する (mass assignment しない)。
+     *
+     * @param  array<string, mixed>  $attrs
+     */
+    private function persistConfig(
+        Organization $organization,
+        ?TicketAutoRecharge $config,
+        array $attrs,
+        ?User $user,
+    ): TicketAutoRecharge {
+        if ($config === null) {
+            $config = new TicketAutoRecharge;
+            $config->organization()->associate($organization);
+        }
+
+        if ($user !== null) {
+            $config->createdByUser()->associate($user);
+        }
+
+        $config->fill($attrs);
+        $config->save();
+
+        return $config;
+    }
+
+    private function lockName(Organization $organization): string
+    {
+        return 'billing:auto-recharge:'.$this->orgId($organization);
+    }
+
+    /** Organization の主キー (PHPStan level 10 で mixed を持ち回らないための narrowing 点)。 */
+    private function orgId(Organization $organization): int
+    {
+        $id = $organization->getKey();
+        Assert::integer($id, 'Organization の主キーは整数を想定しています');
+
+        return $id;
+    }
+
+    private function idempotencyKeyBase(TicketAutoRechargeAttempt $attempt): string
+    {
+        return 'auto-recharge:'.$attempt->attempt_ulid;
+    }
+
+    /**
+     * @return array<string, string>
+     */
+    private function metadataFor(Organization $organization, TicketAutoRechargeAttempt $attempt): array
+    {
+        return [
+            'purpose' => 'auto_recharge',
+            'organization_id' => (string) $this->orgId($organization),
+            'recharge_attempt_ulid' => $attempt->attempt_ulid,
+        ];
+    }
+
+    private function hasRecentCompletedSetup(Organization $organization): bool
+    {
+        // stale 対策: completed から 30 分以内の setup session のみ「処理中」判定の対象にする
+        // (SetDefaultPaymentMethodJob の恒久失敗で永続「処理中」表示にならない)。
+        $windowMinutes = config()->integer('billing.auto_recharge.setup_pending_window_minutes');
+
+        return BillingCheckoutSession::query()
+            ->where('organization_id', $organization->getKey())
+            ->where('intent', CheckoutIntent::SetupPaymentMethod->value)
+            ->where('status', CheckoutSessionStatus::Completed->value)
+            ->where('updated_at', '>=', CarbonImmutable::now()->subMinutes($windowMinutes))
+            ->exists();
+    }
+
+    private function defaultThreshold(): int
+    {
+        return config()->integer('billing.auto_recharge.default_threshold');
+    }
+
+    private function defaultMax(): int
+    {
+        return config()->integer('billing.auto_recharge.default_max');
+    }
+
+    private function maxCountLimit(): int
+    {
+        // tier 解決の PURCHASE_MAX_COUNT Assert と単一真実源 (超過設定は attempt 起票で例外死する)。
+        return min(config()->integer('billing.auto_recharge.max_count'), TicketVolumePrice::PURCHASE_MAX_COUNT);
+    }
+
+    private function maxFailures(): int
+    {
+        return config()->integer('billing.auto_recharge.max_failures');
+    }
+
+    private function pendingExpiryHours(): int
+    {
+        return config()->integer('billing.auto_recharge.pending_expiry_hours');
+    }
+
+    private function currentConsentVersion(): string
+    {
+        $version = config()->string('billing.auto_recharge.consent_version');
+        Assert::stringNotEmpty($version, 'config billing.auto_recharge.consent_version は非空で設定してください');
+
+        return $version;
+    }
+
+    private function isUniqueViolation(QueryException $e): bool
+    {
+        // driver 差吸収 (23505 = pgsql / 23000 = sqlite・mysql)。
+        $sqlState = $e->errorInfo[0] ?? null;
+
+        return $sqlState === '23505' || $sqlState === '23000';
+    }
+}
diff --git a/app/Services/Billing/CashierAutoRechargeGateway.php b/app/Services/Billing/CashierAutoRechargeGateway.php
new file mode 100644
index 0000000..cf48c01
--- /dev/null
+++ b/app/Services/Billing/CashierAutoRechargeGateway.php
@@ -0,0 +1,306 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing;
+
+use App\DataTransferObjects\Billing\DefaultPaymentMethodDto;
+use App\DataTransferObjects\Billing\InvoiceStateDto;
+use App\DataTransferObjects\Billing\OffSessionChargeResultDto;
+use App\Models\Organization;
+use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
+use Laravel\Cashier\Cashier;
+use Laravel\Cashier\PaymentMethod;
+use Stripe\Exception\CardException;
+use Stripe\Exception\InvalidRequestException;
+use Stripe\Invoice;
+use Stripe\PaymentIntent;
+use Webmozart\Assert\Assert;
+
+/**
+ * AutoRechargeGatewayInterface の Cashier (Stripe SDK) 実装。
+ *
+ * Cashier のヘルパは per-request idempotency key を公開しないため、Cashier の Stripe
+ * クライアント (`$organization->stripe()`) を直接使う (CashierTicketCheckoutGateway と同型)。
+ *
+ * **税の扱い**: AI-CUE のチケット価格は税込単価 (`ticket_volume_prices.unit_amount`) で、
+ * Checkout 経路も `automatic_tax` / `tax_rates` を使わない (`CashierTicketCheckoutGateway`
+ * の invariant)。invoice 経路も同一にすることで `amount_due = quantity × unit_amount` が
+ * 成立し、`AutoRechargeService::recordSuccessfulCharge` の amount cross-check が機能する。
+ */
+final class CashierAutoRechargeGateway implements AutoRechargeGatewayInterface
+{
+    public function createSetupCheckout(
+        Organization $organization,
+        string $successUrl,
+        string $cancelUrl,
+        array $metadata,
+        string $idempotencyKey,
+    ): array {
+        // mode=setup は決済を伴わないカード保存。無料パーソナル (サブスクなし) でも
+        // createOrGetStripeCustomer が customer を作成して通す。
+        $organization->createOrGetStripeCustomer();
+        $customerId = $organization->stripe_id;
+        Assert::stringNotEmpty($customerId, 'Stripe customer 未作成の組織ではカード登録を開始できません');
+
+        $session = $organization->stripe()->checkout->sessions->create([
+            'mode' => 'setup',
+            'customer' => $customerId,
+            'currency' => 'jpy',
+            'success_url' => $successUrl,
+            'cancel_url' => $cancelUrl,
+            'metadata' => $metadata,
+        ], ['idempotency_key' => $idempotencyKey]);
+
+        return [
+            'id' => $session->id,
+            'url' => is_string($session->url) ? $session->url : null,
+        ];
+    }
+
+    public function createAutoRechargeInvoice(
+        Organization $organization,
+        string $priceId,
+        int $quantity,
+        array $metadata,
+        string $idempotencyKeyBase,
+    ): string {
+        Assert::greaterThan($quantity, 0);
+
+        $organization->createOrGetStripeCustomer();
+        $customerId = $organization->stripe_id;
+        Assert::stringNotEmpty($customerId, 'Stripe customer 未作成の組織では invoice を作れません');
+
+        $stripe = $organization->stripe();
+
+        // 順序: draft invoice を先に作り、invoice 指定で item を作る (dangling invoice item 防止)。
+        // auto_advance=false で Stripe の自動 finalize / 自動回収 (Smart Retries/dunning) を切る —
+        // 「失敗 = void/delete による終端保証」の前提 (遅延成功の二重課金を構造的に排除)。
+        $invoice = $stripe->invoices->create([
+            'customer' => $customerId,
+            'collection_method' => 'charge_automatically',
+            'auto_advance' => false,
+            'metadata' => $metadata,
+        ], ['idempotency_key' => "{$idempotencyKeyBase}:invoice"]);
+
+        $invoiceId = $invoice->id;
+        Assert::stringNotEmpty($invoiceId, 'Stripe invoice id missing');
+
+        // basil (2025-08-27) API: トップレベル 'price' は廃止。'pricing' => ['price' => ...] を使う。
+        $stripe->invoiceItems->create([
+            'customer' => $customerId,
+            'invoice' => $invoiceId,
+            'pricing' => ['price' => $priceId],
+            'quantity' => $quantity,
+            'metadata' => $metadata,
+        ], ['idempotency_key' => "{$idempotencyKeyBase}:item"]);
+
+        return $invoiceId;
+    }
+
+    public function payOffSessionInvoice(string $invoiceId, string $idempotencyKeyBase): OffSessionChargeResultDto
+    {
+        $stripe = Cashier::stripe();
+
+        // Stripe invoice 状態機械: draft → finalize → open → pay → paid。
+        // 既 finalize 済 (リコンサイル再実行) は invalid_request になり得るため許容して pay へ進む。
+        try {
+            $stripe->invoices->finalizeInvoice(
+                $invoiceId,
+                ['auto_advance' => false],
+                ['idempotency_key' => "{$idempotencyKeyBase}:finalize"],
+            );
+        } catch (InvalidRequestException $e) {
+            if (! str_contains((string) $e->getMessage(), 'finalized')) {
+                throw $e;
+            }
+        }
+
+        try {
+            // basil API では Invoice に payment_intent が直載りしない。InvoicePayment を expand し
+            // payments.data[].payment.payment_intent から PI id を解決する。
+            $paid = $stripe->invoices->pay($invoiceId, [
+                'off_session' => true,
+                'expand' => ['payments.data.payment'],
+            ], ['idempotency_key' => "{$idempotencyKeyBase}:pay"]);
+        } catch (CardException $e) {
+            // card_declined / authentication_required 等 → typed 失敗 (終端判断は Service 層)
+            return OffSessionChargeResultDto::failed(
+                $invoiceId,
+                is_string($e->getStripeCode()) ? $e->getStripeCode() : null,
+                is_string($e->getDeclineCode()) ? $e->getDeclineCode() : null,
+            );
+        }
+
+        $amountPaid = $paid->amount_paid;
+        $amountDue = $paid->amount_due;
+        Assert::integer($amountPaid);
+        Assert::integer($amountDue);
+
+        return OffSessionChargeResultDto::paid($invoiceId, $amountPaid, $amountDue, $this->extractPaymentIntentId($paid));
+    }
+
+    public function terminateInvoice(string $invoiceId): void
+    {
+        $stripe = Cashier::stripe();
+
+        try {
+            $invoice = $stripe->invoices->retrieve($invoiceId);
+        } catch (InvalidRequestException $e) {
+            if ($e->getHttpStatus() === 404) {
+                return; // 冪等: 存在しない (draft delete 済み含む) は成功扱い
+            }
+
+            throw $e;
+        }
+
+        $status = $invoice->status;
+
+        if ($status === 'void' || $status === 'deleted') {
+            return; // 冪等: 終端済み
+        }
+
+        // paid を誤って終端しない (付与経路の管轄)。uncollectible は Stripe 上 void 可能かつ
+        // 後から支払われ得るため、終端保証の対象に含めて void する (放置すると遅延成功の穴になる)。
+        Assert::true(
+            $status === 'draft' || $status === 'open' || $status === 'uncollectible',
+            "invoice {$invoiceId} は終端できない状態です (status={$status})",
+        );
+
+        if ($status === 'draft') {
+            // draft は void 不可 (Stripe 制約) — delete で終端する
+            $stripe->invoices->delete($invoiceId);
+
+            return;
+        }
+
+        $stripe->invoices->voidInvoice($invoiceId);
+    }
+
+    public function retrieveInvoiceState(string $invoiceId): InvoiceStateDto
+    {
+        $stripe = Cashier::stripe();
+
+        try {
+            // nested expand で PaymentIntent object まで取得する — SCA (requires_action) 判定の出典。
+            $invoice = $stripe->invoices->retrieve($invoiceId, ['expand' => ['payments.data.payment.payment_intent']]);
+        } catch (InvalidRequestException $e) {
+            if ($e->getHttpStatus() === 404) {
+                return new InvoiceStateDto('deleted', null, null, null, false, null);
+            }
+
+            throw $e;
+        }
+
+        $status = $invoice->status;
+        Assert::stringNotEmpty($status, 'Stripe invoice status missing');
+
+        return new InvoiceStateDto(
+            $status,
+            $invoice->amount_paid,
+            $invoice->amount_due,
+            $this->extractPaymentIntentId($invoice),
+            $this->invoiceRequiresAction($invoice),
+            is_string($invoice->hosted_invoice_url) ? $invoice->hosted_invoice_url : null,
+        );
+    }
+
+    public function getDefaultPaymentMethodState(Organization $organization): DefaultPaymentMethodDto
+    {
+        $pm = $organization->defaultPaymentMethod();
+
+        if (! $pm instanceof PaymentMethod) {
+            return DefaultPaymentMethodDto::none();
+        }
+
+        $stripePm = $pm->asStripePaymentMethod();
+        $card = $stripePm->card;
+
+        return new DefaultPaymentMethodDto(
+            $stripePm->id,
+            $card?->brand,
+            $card?->last4,
+        );
+    }
+
+    public function resolveSetupIntentPaymentMethod(string $setupIntentId): string
+    {
+        $setupIntent = Cashier::stripe()->setupIntents->retrieve($setupIntentId);
+        $paymentMethod = $setupIntent->payment_method;
+
+        if ($paymentMethod instanceof \Stripe\PaymentMethod) {
+            return $paymentMethod->id;
+        }
+
+        Assert::stringNotEmpty($paymentMethod, "setup_intent {$setupIntentId} に payment_method がありません");
+
+        return $paymentMethod;
+    }
+
+    public function setDefaultPaymentMethod(Organization $organization, string $paymentMethodId): void
+    {
+        // Cashier の updateDefaultPaymentMethod は「既 default なら no-op / attach 済みなら再 attach
+        // しない / invoice_settings.default_payment_method 設定 / pm_type・pm_last_four snapshot」まで
+        // 面倒を見る冪等実装。Stripe 側状態の取得・更新をここに一元化する。
+        $organization->updateDefaultPaymentMethod($paymentMethodId);
+    }
+
+    /**
+     * expanded InvoicePayment の PaymentIntent から SCA (requires_action) 待ちかを判定する。
+     * webhook 到着順・local failure_code に依存しない判定源。
+     */
+    private function invoiceRequiresAction(Invoice $invoice): bool
+    {
+        $payments = $invoice->payments;
+        if ($payments === null) {
+            return false;
+        }
+
+        foreach ($payments->data as $invoicePayment) {
+            $paymentIntent = $invoicePayment->payment->payment_intent ?? null;
+
+            // expand が効かず string id の形状でも SCA を見逃さない — gateway 内で
+            // PaymentIntent を retrieve して状態を読む (fallback)。
+            if (is_string($paymentIntent) && $paymentIntent !== '') {
+                $paymentIntent = Cashier::stripe()->paymentIntents->retrieve($paymentIntent);
+            }
+            if (! $paymentIntent instanceof PaymentIntent) {
+                continue;
+            }
+            if ($paymentIntent->status === 'requires_action') {
+                return true;
+            }
+            $lastError = $paymentIntent->last_payment_error;
+            if ($lastError !== null && ($lastError->toArray()['code'] ?? null) === 'authentication_required') {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
+    /**
+     * basil API: invoice.payments (InvoicePayment collection) から default payment の
+     * PaymentIntent id を解決する。
+     */
+    private function extractPaymentIntentId(Invoice $invoice): ?string
+    {
+        $payments = $invoice->payments;
+        if ($payments === null) {
+            return null;
+        }
+
+        foreach ($payments->data as $invoicePayment) {
+            $paymentIntent = $invoicePayment->payment->payment_intent ?? null;
+
+            if (is_string($paymentIntent) && $paymentIntent !== '') {
+                return $paymentIntent;
+            }
+            if ($paymentIntent instanceof PaymentIntent) {
+                return $paymentIntent->id;
+            }
+        }
+
+        return null;
+    }
+}
diff --git a/app/Services/Billing/Contracts/AutoRechargeGatewayInterface.php b/app/Services/Billing/Contracts/AutoRechargeGatewayInterface.php
new file mode 100644
index 0000000..55397f9
--- /dev/null
+++ b/app/Services/Billing/Contracts/AutoRechargeGatewayInterface.php
@@ -0,0 +1,91 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing\Contracts;
+
+use App\DataTransferObjects\Billing\DefaultPaymentMethodDto;
+use App\DataTransferObjects\Billing\InvoiceStateDto;
+use App\DataTransferObjects\Billing\OffSessionChargeResultDto;
+use App\Models\Organization;
+
+/**
+ * P8a (D31): オートリチャージ系 Stripe 呼び出しの抽象
+ * (実装: CashierAutoRechargeGateway。fake_externals 時は FakeAutoRechargeGateway を bind)。
+ *
+ * AI-CUE の「狭い gateway + gateway 単位の Fake bind」規約を維持する
+ * (サブスク系 = StripeGatewayInterface / チケット checkout 系 = TicketCheckoutGateway と
+ * 責務境界を分ける。移植元の 30+ メソッド単一 interface へは寄せない)。
+ */
+interface AutoRechargeGatewayInterface
+{
+    /**
+     * オートリチャージ用カード保存 Checkout (mode=setup)。off-session mandate 同意を伴う。
+     * 無料パーソナル (サブスクなし) でも Stripe Customer を作成して通す唯一のカード登録経路。
+     *
+     * @param  array<string, string>  $metadata
+     * @return array{id: string, url: string|null}
+     */
+    public function createSetupCheckout(
+        Organization $organization,
+        string $successUrl,
+        string $cancelUrl,
+        array $metadata,
+        string $idempotencyKey,
+    ): array;
+
+    /**
+     * オートリチャージ Invoice の作成 (段階 1/2)。
+     * draft invoice 作成 → invoice item (price × quantity) 追加、までを行い invoice id を返す。
+     * 呼び出し側 (Service) はこの戻り値を attempt.stripe_invoice_id に**保存してから**
+     * payOffSessionInvoice (段階 2/2) を呼ぶこと — 保存前にプロセスが落ちても、リコンサイルが
+     * 同一 $idempotencyKeyBase で再実行すれば Stripe 冪等により同一 invoice が返る。
+     *
+     * @param  array<string, string>  $metadata  purpose / organization_id / recharge_attempt_ulid 必須
+     */
+    public function createAutoRechargeInvoice(
+        Organization $organization,
+        string $priceId,
+        int $quantity,
+        array $metadata,
+        string $idempotencyKeyBase,
+    ): string;
+
+    /**
+     * オートリチャージ Invoice の確定と回収 (段階 2/2)。
+     * finalize (draft→open) → pay(off_session)。カード起因の失敗は例外ではなく typed 結果で返す。
+     * Stripe 障害・設定不備は例外のまま伝播 (fail-closed)。
+     */
+    public function payOffSessionInvoice(string $invoiceId, string $idempotencyKeyBase): OffSessionChargeResultDto;
+
+    /**
+     * 失敗 invoice の終端保証。open → void / draft (finalize 前) → delete。
+     * 冪等 (void/delete 済み・存在しないは成功扱い)。paid の invoice に対しては例外
+     * (誤 void の防止 — paid は付与経路の管轄)。
+     */
+    public function terminateInvoice(string $invoiceId): void;
+
+    /**
+     * リコンサイル用の invoice 現在状態取得。
+     * 存在しない (draft delete 済み含む) は status='deleted' として返す。
+     */
+    public function retrieveInvoiceState(string $invoiceId): InvoiceStateDto;
+
+    /**
+     * customer の default PM 状態 (有無・brand・last4)。
+     * リチャージ有効化の fail-closed 判定に使う。
+     */
+    public function getDefaultPaymentMethodState(Organization $organization): DefaultPaymentMethodDto;
+
+    /**
+     * setup_intent から payment_method id を解決する
+     * (Job から Stripe API を直接触らないための境界)。
+     */
+    public function resolveSetupIntentPaymentMethod(string $setupIntentId): string;
+
+    /**
+     * PM を customer に attach し invoice_settings.default_payment_method に設定する。
+     * 既 attach の PM は attach を skip する冪等実装。
+     */
+    public function setDefaultPaymentMethod(Organization $organization, string $paymentMethodId): void;
+}
diff --git a/app/Services/Billing/Fakes/FakeAutoRechargeGateway.php b/app/Services/Billing/Fakes/FakeAutoRechargeGateway.php
new file mode 100644
index 0000000..350ca5a
--- /dev/null
+++ b/app/Services/Billing/Fakes/FakeAutoRechargeGateway.php
@@ -0,0 +1,80 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing\Fakes;
+
+use App\DataTransferObjects\Billing\DefaultPaymentMethodDto;
+use App\DataTransferObjects\Billing\InvoiceStateDto;
+use App\DataTransferObjects\Billing\OffSessionChargeResultDto;
+use App\Models\Organization;
+use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
+
+/**
+ * AutoRechargeGatewayInterface の runtime fake (fake_externals 環境専用。Stripe に到達しない)。
+ *
+ * 契約 = FakeTicketCheckoutGateway と同じ「外部ステップを skip した中立帰還」:
+ * - setup Checkout は決定的な session id + アプリ内帰還 URL を返す
+ * - **課金は一切成立させない** (payOffSessionInvoice は常に card_declined の typed 失敗)。
+ *   fake 環境で自動購入が「成功」すると台帳に偽の付与行が残るため、成功側には倒さない
+ * - default PM は「無し」を返す = fake 環境ではオートリチャージを有効化できない (fail-closed)
+ */
+final class FakeAutoRechargeGateway implements AutoRechargeGatewayInterface
+{
+    public function createSetupCheckout(
+        Organization $organization,
+        string $successUrl,
+        string $cancelUrl,
+        array $metadata,
+        string $idempotencyKey,
+    ): array {
+        // idempotency key から決定的に導出 (同一 attempt の再送は同一 session に収束)。
+        $token = substr(hash('sha256', $idempotencyKey), 0, 32);
+
+        return [
+            'id' => "cs_bughuntfake_setup_{$token}",
+            'url' => FakeExternalUrl::neutralReturn($cancelUrl),
+        ];
+    }
+
+    public function createAutoRechargeInvoice(
+        Organization $organization,
+        string $priceId,
+        int $quantity,
+        array $metadata,
+        string $idempotencyKeyBase,
+    ): string {
+        return 'in_bughuntfake_'.substr(hash('sha256', $idempotencyKeyBase), 0, 24);
+    }
+
+    public function payOffSessionInvoice(string $invoiceId, string $idempotencyKeyBase): OffSessionChargeResultDto
+    {
+        // fake は決済を成立させない (偽の付与行を台帳に残さない)。
+        return OffSessionChargeResultDto::failed($invoiceId, 'card_declined', 'generic_decline');
+    }
+
+    public function terminateInvoice(string $invoiceId): void
+    {
+        // no-op: fake 環境は実 Stripe を叩かない (終端は常に成功扱い)。
+    }
+
+    public function retrieveInvoiceState(string $invoiceId): InvoiceStateDto
+    {
+        return new InvoiceStateDto('void', null, null, null, false, null);
+    }
+
+    public function getDefaultPaymentMethodState(Organization $organization): DefaultPaymentMethodDto
+    {
+        return DefaultPaymentMethodDto::none();
+    }
+
+    public function resolveSetupIntentPaymentMethod(string $setupIntentId): string
+    {
+        return 'pm_bughuntfake_'.substr(hash('sha256', $setupIntentId), 0, 20);
+    }
+
+    public function setDefaultPaymentMethod(Organization $organization, string $paymentMethodId): void
+    {
+        // no-op: fake 環境は Stripe customer を更新しない。
+    }
+}
diff --git a/app/Services/Billing/StripeWebhookProcessor.php b/app/Services/Billing/StripeWebhookProcessor.php
index 3da396b..e595ac7 100644
--- a/app/Services/Billing/StripeWebhookProcessor.php
+++ b/app/Services/Billing/StripeWebhookProcessor.php
@@ -8,6 +8,11 @@
 use App\Enums\Billing\HandledStripeWebhookEvent;
 use App\Enums\Billing\TicketCheckoutSessionStatus;
 use App\Enums\Billing\WebhookEventStatus;
+use App\Enums\CheckoutIntent;
+use App\Enums\CheckoutSessionStatus;
+use App\Jobs\Billing\HandleAutoRechargeChargeFailureJob;
+use App\Jobs\Billing\SetDefaultPaymentMethodJob;
+use App\Models\Billing\BillingCheckoutSession;
 use App\Models\Billing\Plan;
 use App\Models\Billing\PlanPrice;
 use App\Models\Billing\StripeWebhookEvent;
@@ -71,6 +76,7 @@ public function __construct(
         private readonly BillingNotificationDispatcher $notifications,
         private readonly PersonalPlanService $personalPlan,
         private readonly SubscriptionService $subscriptions,
+        private readonly AutoRechargeService $autoRecharge,
     ) {}
 
     public function handle(WebhookReceived $event): void
@@ -175,11 +181,11 @@ private function process(string $type, array $payload): void
             HandledStripeWebhookEvent::SubscriptionCreated,
             HandledStripeWebhookEvent::SubscriptionUpdated => $this->syncSubscriptionState($payload, terminated: false),
             HandledStripeWebhookEvent::SubscriptionDeleted => $this->syncSubscriptionState($payload, terminated: true),
-            HandledStripeWebhookEvent::InvoicePaid => $this->grantMonthlyTickets($payload),
+            HandledStripeWebhookEvent::InvoicePaid => $this->handleInvoicePaid($payload),
             HandledStripeWebhookEvent::ChargeRefunded => $this->clawbackRefundedTickets($payload),
             HandledStripeWebhookEvent::InvoicePaymentFailed => $this->handleInvoicePaymentFailed($payload),
             // チケットスポット購入の冪等付与 (T007。真実源は ticket_checkout_sessions 行)
-            HandledStripeWebhookEvent::CheckoutSessionCompleted => $this->grantPurchasedTickets($payload),
+            HandledStripeWebhookEvent::CheckoutSessionCompleted => $this->handleCheckoutSessionCompleted($payload),
             null => null, // 未対応 type は受理のみ (processed として記録)
         };
     }
@@ -296,6 +302,79 @@ private function intAt(array $payload, string $path): ?int
         return is_int($value) ? $value : null;
     }
 
+    /**
+     * invoice.paid の振り分け。
+     *
+     * P8a: `metadata.purpose === 'auto_recharge'` の invoice は**オートリチャージの付与経路**へ
+     * 振る (billing_reason='manual' のため既存 GRANTING_BILLING_REASONS allowlist では月次付与に
+     * 混入しないが、分岐を明示して意図を固定する)。それ以外は従来どおり月次付与。
+     *
+     * @param  array<mixed>  $payload
+     */
+    private function handleInvoicePaid(array $payload): void
+    {
+        if ($this->stringAt($payload, 'data.object.metadata.purpose') === 'auto_recharge') {
+            $this->recordAutoRechargePaid($payload);
+
+            return;
+        }
+
+        $this->grantMonthlyTickets($payload);
+    }
+
+    /**
+     * P8a: オートリチャージ invoice の paid 確定 (冪等付与 + attempt paid 遷移)。
+     *
+     * **metadata は照合専用**で org 解決・認可には使わない (tenant キー不信 / 不変条件 #1)。
+     * org は attempt 行 (自 DB) の relation から解決し、payload の customer と突き合わせる。
+     * 付与は `recharge:{invoiceId}` の ledger UNIQUE で冪等 (webhook 再送・同期 pay・
+     * リコンサイルのどれが先でも 1 回)。
+     *
+     * @param  array<mixed>  $payload
+     */
+    private function recordAutoRechargePaid(array $payload): void
+    {
+        $attemptUlid = $this->stringAt($payload, 'data.object.metadata.recharge_attempt_ulid');
+        $invoiceId = $this->stringAt($payload, 'data.object.id');
+        if ($attemptUlid === null || $invoiceId === null) {
+            throw new RuntimeException('invoice.paid (auto_recharge): metadata.recharge_attempt_ulid / invoice id 欠落');
+        }
+
+        $attempt = $this->autoRecharge->findAttemptByUlid($attemptUlid);
+        if ($attempt === null) {
+            // 自 DB 行が真実源。crash 先着 webhook は Stripe の再送で本経路に収束する (retryable)。
+            throw new RuntimeException("invoice.paid (auto_recharge): 未追跡 attempt {$attemptUlid} (DB 行なし、再送待ち)");
+        }
+
+        $organization = $attempt->organization;
+        Assert::isInstanceOf($organization, Organization::class);
+
+        // customer 照合 (tenant キー不信の fail-closed。metadata.organization_id は認可に使わない)
+        $customerId = $this->stringAt($payload, 'data.object.customer');
+        if ($customerId === null || $organization->stripe_id !== $customerId) {
+            throw new RuntimeException("invoice.paid (auto_recharge): customer 照合不一致 (attempt {$attemptUlid})");
+        }
+        // attempt に pin 済みの invoice と一致すること (別 invoice の混入を弾く)
+        if ($attempt->stripe_invoice_id !== null && $attempt->stripe_invoice_id !== $invoiceId) {
+            throw new RuntimeException("invoice.paid (auto_recharge): invoice 照合不一致 (attempt {$attemptUlid})");
+        }
+
+        $amountPaid = data_get($payload, 'data.object.amount_paid');
+        $amountDue = data_get($payload, 'data.object.amount_due');
+        if (! is_int($amountPaid) || ! is_int($amountDue)) {
+            throw new RuntimeException("invoice.paid (auto_recharge): amount 欠落 (invoice {$invoiceId})");
+        }
+
+        $this->autoRecharge->recordSuccessfulCharge(
+            $organization,
+            $attempt,
+            $invoiceId,
+            $amountPaid,
+            $amountDue,
+            $this->resolveStripeIdField(data_get($payload, 'data.object.payment_intent')),
+        );
+    }
+
     /**
      * invoice.paid: 契約プランの monthly_ticket_grant を月次付与する。
      * 初回 (billing_reason=subscription_create) はあわせて signup grant を付与する。
@@ -382,6 +461,19 @@ private function handleInvoicePaymentFailed(array $payload): void
             'attempt_count' => data_get($payload, 'data.object.attempt_count'),
         ]);
 
+        // P8a: オートリチャージ invoice の失敗は専用 Job へ振る (SCA 判定に外向き Stripe API が
+        // 要るため webhook 同期処理では判定しない)。汎用の支払い失敗通知は送らない
+        // (専用の失敗 / SCA 通知が Job 経由で出る)。
+        if ($this->stringAt($payload, 'data.object.metadata.purpose') === 'auto_recharge') {
+            $attemptUlid = $this->stringAt($payload, 'data.object.metadata.recharge_attempt_ulid');
+            $attempt = $attemptUlid === null ? null : $this->autoRecharge->findPendingAttemptByUlid($attemptUlid);
+            if ($attempt !== null) {
+                HandleAutoRechargeChargeFailureJob::dispatch($attempt->id);
+            }
+
+            return;
+        }
+
         if ($invoiceId === null || $organization === null) {
             return;
         }
@@ -422,6 +514,73 @@ private function safelyNotify(callable $notify): void
      *
      * @param  array<mixed>  $payload
      */
+    private function handleCheckoutSessionCompleted(array $payload): void
+    {
+        // P8a: オートリチャージ用カード登録 (mode=setup) の着地。真実源は自 DB 行
+        // (billing_checkout_sessions の intent=setup_payment_method)。
+        if ($this->stringAt($payload, 'data.object.mode') === 'setup') {
+            $this->completeAutoRechargeSetup($payload);
+
+            return;
+        }
+
+        $this->grantPurchasedTickets($payload);
+    }
+
+    /**
+     * P8a: mode=setup Checkout の完了。台帳行を completed 化し、PM の default 設定 +
+     * 事前同意の自動有効化を Job へ退避する (外向き Stripe API は webhook 同期処理で叩かない)。
+     *
+     * @param  array<mixed>  $payload
+     */
+    private function completeAutoRechargeSetup(array $payload): void
+    {
+        if ($this->stringAt($payload, 'data.object.metadata.purpose') !== 'auto_recharge_setup') {
+            return; // 他 purpose の setup session は受理のみ
+        }
+
+        $sessionId = $this->stringAt($payload, 'data.object.id');
+        if ($sessionId === null) {
+            throw new RuntimeException('checkout.session.completed: session id 欠落 (auto_recharge_setup)');
+        }
+
+        // 真実源は自 DB 行 (crash 先着 webhook は Stripe の再送で収束する = retryable)
+        $session = BillingCheckoutSession::query()
+            ->where('stripe_session_id', $sessionId)
+            ->where('intent', CheckoutIntent::SetupPaymentMethod->value)
+            ->first();
+        if ($session === null) {
+            throw new RuntimeException("auto-recharge setup webhook: 未追跡 session {$sessionId} (DB 行なし、再送待ち)");
+        }
+
+        $organization = $session->organization;
+        Assert::isInstanceOf($organization, Organization::class);
+
+        // tenant キー不信: payload の customer は照合のみ (org 解決は DB 行 → relation)
+        $customerId = $this->stringAt($payload, 'data.object.customer');
+        if ($customerId === null || $organization->stripe_id !== $customerId) {
+            throw new RuntimeException("auto-recharge setup webhook: customer 照合不一致 (session {$sessionId})");
+        }
+
+        $setupIntentId = $this->resolveStripeIdField(data_get($payload, 'data.object.setup_intent'));
+        if ($setupIntentId === null) {
+            throw new RuntimeException("auto-recharge setup webhook: setup_intent 欠落 (session {$sessionId})");
+        }
+
+        if ($session->status !== CheckoutSessionStatus::Completed->value) {
+            $session->status = CheckoutSessionStatus::Completed->value;
+            $session->completed_at = now();
+            $session->save();
+        }
+
+        $organizationId = $organization->getKey();
+        Assert::integer($organizationId);
+        SetDefaultPaymentMethodJob::dispatch($organizationId, $setupIntentId);
+    }
+
+    /**
+     * @param  array<mixed>  $payload
+     */
     private function grantPurchasedTickets(array $payload): void
     {
         // (1) purpose ガード: ticket_purchase 以外 (サブスク checkout / 他 purpose / mode≠payment) は受理のみ
diff --git a/app/Services/Billing/TicketLedgerService.php b/app/Services/Billing/TicketLedgerService.php
index 8af3a90..cc2331f 100644
--- a/app/Services/Billing/TicketLedgerService.php
+++ b/app/Services/Billing/TicketLedgerService.php
@@ -10,6 +10,7 @@
 use App\Enums\Billing\TicketReservationStatus;
 use App\Enums\Billing\TicketSource;
 use App\Exceptions\Billing\InsufficientTicketsException;
+use App\Jobs\Billing\AutoRechargeTriggerJob;
 use App\Models\Billing\TicketLedgerEntry;
 use App\Models\Billing\TicketReservation;
 use App\Models\Organization;
@@ -156,6 +157,71 @@ public function grantPurchased(
         ]);
     }
 
+    /**
+     * P8a: オートリチャージ (off-session Invoice 課金) の冪等付与。
+     *
+     * D30 により `ticket_purchases` の両建ては作らない — 購入の正本は台帳のインライン列
+     * (`payment_intent_id` + `purchase_amount` + `stripe_invoice_id`) で、返金逆仕訳
+     * (clawbackPurchasedByPaymentIntent) がそのまま機能する。
+     *
+     * 冪等キーは `recharge:{invoiceId}` (UNIQUE)。webhook / 同期 pay / リコンサイルの
+     * どれが先に到達しても **1 invoice = 1 回付与**。
+     *
+     * $amount (実回収額) は customer credit balance 全額適用で 0 になり得るため 0 を許す。
+     */
+    public function grantAutoRecharge(
+        Organization $organization,
+        int $count,
+        string $stripeInvoiceId,
+        int $amount,
+        ?string $paymentIntentId,
+    ): void {
+        Assert::greaterThan($count, 0, 'grantAutoRecharge の count は正の整数のみ');
+        Assert::greaterThanEq($amount, 0, 'grantAutoRecharge の amount は 0 以上 (credit balance 全額適用で 0 は正当)');
+        Assert::stringNotEmpty($stripeInvoiceId);
+
+        $inserted = $this->insertIdempotent($organization, "recharge:{$stripeInvoiceId}", [
+            'delta' => $count,
+            'kind' => TicketLedgerKind::Grant->value,
+            'source' => TicketSource::Purchased->value,
+            'description' => "チケット自動購入 (invoice: {$stripeInvoiceId})",
+            'granted_at' => CarbonImmutable::now(),
+            'expires_at' => null,
+            'stripe_invoice_id' => $stripeInvoiceId,
+            'payment_intent_id' => $paymentIntentId,
+            'purchase_amount' => $amount,
+        ]);
+
+        if ($inserted === 0 && $paymentIntentId !== null) {
+            $this->backfillPaymentIntentId($organization, $stripeInvoiceId, $paymentIntentId);
+        }
+    }
+
+    /**
+     * 付与済み recharge 行への payment_intent_id の **null → 値の単調 backfill**。
+     *
+     * 背景: PI は webhook / 同期 pay / リコンサイルの到達順で欠落し得る (basil API では
+     * invoice に PI が直載りしないため)。PI が無いと返金逆仕訳
+     * (clawbackPurchasedByPaymentIntent) が引けず「返金したのにチケットが残る」穴が開く。
+     *
+     * **append-only 不変条件との関係**: 本メソッドは `WHERE payment_intent_id IS NULL` を
+     * 満たす行の当該 1 列のみを埋める (値 → 別値の上書き・delete は行わない)。金額・枚数・
+     * 冪等キーといった会計値は一切触らないため、監査痕跡としての append-only 性 (計上の
+     * 事後改竄をしない) は保たれる。ここだけが台帳への唯一の UPDATE 経路であり、
+     * Eloquent の append-only guard を迂回する Query Builder 直書きに閉じ込めてある。
+     */
+    private function backfillPaymentIntentId(
+        Organization $organization,
+        string $stripeInvoiceId,
+        string $paymentIntentId,
+    ): void {
+        DB::table('ticket_ledger_entries')
+            ->where('organization_id', $organization->getKey())
+            ->where('idempotency_key', "recharge:{$stripeInvoiceId}")
+            ->whereNull('payment_intent_id')
+            ->update(['payment_intent_id' => $paymentIntentId]);
+    }
+
     /**
      * charge.refunded 受信時に買い切りチケットを逆仕訳 (clawback) する。
      *
@@ -353,6 +419,21 @@ public function reserve(Organization $organization, int $amount): TicketReservat
                 DB::afterCommit(fn () => $this->notifications->notifyTicketBalanceLow($organization, $after, $threshold));
             }
 
+            // P8a: オートリチャージ (裏チャージ) のトリガ点。**低残高通知と同居**させる
+            // (parity の名で既存の低残高通知を置き換えない)。
+            //
+            // AI-CUE の実効残高が減る唯一の消費イベントは reserve であり、commit は拘束 −amount と
+            // 台帳 −amount が相殺して balance 不変。よって移植元の commit ではなく reserve に置く
+            // (commit に置くと閾値クロスを取り逃す)。
+            //
+            // 閾値判定・pending 検査・数量確定は Job 側 (AutoRechargeService) が org 行ロック下で
+            // 再評価するため、ここでは条件を絞らない = 過剰 dispatch は無害
+            // (設定行なし org は Job 冒頭で即 return。既定 off の org には何も起きない)。
+            // afterCommit で rollback 時は発火しない。
+            $organizationId = $organization->getKey();
+            Assert::integer($organizationId);
+            DB::afterCommit(static fn () => AutoRechargeTriggerJob::dispatch($organizationId));
+
             return $reservation;
         });
     }
diff --git a/config/billing.php b/config/billing.php
index 6f3756c..38d164b 100644
--- a/config/billing.php
+++ b/config/billing.php
@@ -41,4 +41,47 @@
     */
     'ticket_low_balance_threshold' => (int) env('BILLING_TICKET_LOW_BALANCE_THRESHOLD', 5),
 
+    /*
+    |----------------------------------------------------------------------
+    | オートリチャージ (裏チャージ。P8a)
+    |----------------------------------------------------------------------
+    |
+    | **opt-in・既定 off**。ticket_auto_recharges に行が無い組織の挙動は完全に不変。
+    | 値は移植元 (aigenba) の既定値をそのまま採る。
+    |
+    | 同意文言バージョン (consent_version) の改定履歴:
+    |   v1 = 初版 (カード登録経路のみ = mode=setup Checkout で登録したカードを使う)
+    |
+    | 提示条件の実質 (開始残高・補充枚数・上限額の提示形式・停止方法・即時課金可能性・
+    | **カードの取得手段**) を変える改定では**必ず version を上げること**。
+    | 版を上げると reconsentRequiredFor 経由で既存同意が自動失効し、再同意まで
+    | 自動購入が停止する (fail-closed)。
+    | サブスク決済カードの流用 (P9 / T1004) を配線する際は v2 へ上げる。
+    */
+    'auto_recharge' => [
+        /* 残高がこの枚数を下回ると補充する (既定値。org ごとに設定で上書き) */
+        'default_threshold' => (int) env('BILLING_AUTO_RECHARGE_DEFAULT_THRESHOLD', 5),
+
+        /* 補充後の目標残高 (既定値) */
+        'default_max' => (int) env('BILLING_AUTO_RECHARGE_DEFAULT_MAX', 50),
+
+        /*
+        | max_count の上限。TicketVolumePrice::PURCHASE_MAX_COUNT と単一真実源で揃える
+        | (超過設定は tier 解決の Assert で例外死するため入口で拘束する)。
+        */
+        'max_count' => (int) env('BILLING_AUTO_RECHARGE_MAX_COUNT', 1000),
+
+        /* 連続課金失敗でオートリチャージを自動停止する回数 */
+        'max_failures' => (int) env('BILLING_AUTO_RECHARGE_MAX_FAILURES', 3),
+
+        /* pending attempt の期限 (時間)。超過でリコンサイルが終端する */
+        'pending_expiry_hours' => (int) env('BILLING_AUTO_RECHARGE_PENDING_EXPIRY_HOURS', 24),
+
+        /* setup Checkout 完了から PM snapshot 反映を待つ「処理中」表示の窓 (分) */
+        'setup_pending_window_minutes' => (int) env('BILLING_AUTO_RECHARGE_SETUP_PENDING_WINDOW_MINUTES', 30),
+
+        /* 現行の同意文言バージョン (上記の改定規約に従う) */
+        'consent_version' => env('BILLING_AUTO_RECHARGE_CONSENT_VERSION', 'v1'),
+    ],
+
 ];
diff --git a/database/factories/Billing/TicketAutoRechargeAttemptFactory.php b/database/factories/Billing/TicketAutoRechargeAttemptFactory.php
new file mode 100644
index 0000000..f1a741e
--- /dev/null
+++ b/database/factories/Billing/TicketAutoRechargeAttemptFactory.php
@@ -0,0 +1,74 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Database\Factories\Billing;
+
+use App\Enums\Billing\AutoRechargeAttemptStatus;
+use App\Models\Billing\TicketAutoRechargeAttempt;
+use App\Models\Organization;
+use Illuminate\Database\Eloquent\Factories\Factory;
+use Illuminate\Support\Str;
+
+/**
+ * 既定は pending (invoice 未作成) の試行行。
+ *
+ * @extends Factory<TicketAutoRechargeAttempt>
+ */
+class TicketAutoRechargeAttemptFactory extends Factory
+{
+    protected $model = TicketAutoRechargeAttempt::class;
+
+    /**
+     * @return array<string, mixed>
+     */
+    public function definition(): array
+    {
+        return [
+            'attempt_ulid' => strtolower((string) Str::ulid()),
+            'organization_id' => Organization::factory(),
+            'status' => AutoRechargeAttemptStatus::Pending,
+            'quantity' => 45,
+            'unit_amount' => 80,
+            'stripe_price_id' => 'price_'.Str::random(24),
+            'stripe_invoice_id' => null,
+            'stripe_payment_intent_id' => null,
+            'failure_code' => null,
+            'resolved_at' => null,
+        ];
+    }
+
+    /** invoice 作成済み (pay 前 / webhook 待ち) の pending。 */
+    public function withInvoice(?string $invoiceId = null): static
+    {
+        return $this->state(fn (): array => [
+            'stripe_invoice_id' => $invoiceId ?? 'in_'.Str::random(24),
+        ]);
+    }
+
+    public function paid(): static
+    {
+        return $this->withInvoice()->state(fn (): array => [
+            'status' => AutoRechargeAttemptStatus::Paid,
+            'stripe_payment_intent_id' => 'pi_'.Str::random(24),
+            'resolved_at' => now(),
+        ]);
+    }
+
+    public function failed(): static
+    {
+        return $this->withInvoice()->state(fn (): array => [
+            'status' => AutoRechargeAttemptStatus::Failed,
+            'failure_code' => 'card_declined',
+            'resolved_at' => now(),
+        ]);
+    }
+
+    public function canceled(): static
+    {
+        return $this->withInvoice()->state(fn (): array => [
+            'status' => AutoRechargeAttemptStatus::Canceled,
+            'resolved_at' => now(),
+        ]);
+    }
+}
diff --git a/database/factories/Billing/TicketAutoRechargeFactory.php b/database/factories/Billing/TicketAutoRechargeFactory.php
new file mode 100644
index 0000000..f4b8b48
--- /dev/null
+++ b/database/factories/Billing/TicketAutoRechargeFactory.php
@@ -0,0 +1,89 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Database\Factories\Billing;
+
+use App\Enums\Billing\AutoRechargeDisabledReason;
+use App\Models\Billing\TicketAutoRecharge;
+use App\Models\Organization;
+use Illuminate\Database\Eloquent\Factories\Factory;
+use Illuminate\Support\Str;
+
+/**
+ * 既定は「行はあるが off」= opt-in 未有効の設定行。
+ *
+ * @extends Factory<TicketAutoRecharge>
+ */
+class TicketAutoRechargeFactory extends Factory
+{
+    protected $model = TicketAutoRecharge::class;
+
+    /**
+     * @return array<string, mixed>
+     */
+    public function definition(): array
+    {
+        return [
+            'organization_id' => Organization::factory(),
+            'enabled' => false,
+            'threshold_count' => config()->integer('billing.auto_recharge.default_threshold'),
+            'max_count' => config()->integer('billing.auto_recharge.default_max'),
+            'stripe_payment_method_id' => null,
+            'failure_count' => 0,
+            'disabled_reason' => null,
+            'consented_at' => null,
+            'consent_version' => null,
+            'consented_max_count' => null,
+            'consented_max_amount' => null,
+            'created_by_user_id' => null,
+        ];
+    }
+
+    /** 有効化済み (PM 保存 + 同意記録済み) 状態。 */
+    public function enabled(): static
+    {
+        return $this->state(fn (array $attributes): array => [
+            'enabled' => true,
+            'stripe_payment_method_id' => 'pm_'.Str::random(24),
+            'consented_at' => now(),
+            'consent_version' => config()->string('billing.auto_recharge.consent_version'),
+            'consented_max_count' => $attributes['max_count'] ?? config()->integer('billing.auto_recharge.default_max'),
+            // 上限額は「テストが価格改定で壊れない」よう十分大きく取る
+            // (価格改定シナリオは consentedMaxAmount() で明示的に絞る)。
+            'consented_max_amount' => PHP_INT_MAX >> 32,
+        ]);
+    }
+
+    /** 事前同意のみ記録済み (enabled=false / PM 未登録) = pendingAutoEnable 状態。 */
+    public function preConsented(): static
+    {
+        return $this->state(fn (array $attributes): array => [
+            'enabled' => false,
+            'stripe_payment_method_id' => null,
+            'disabled_reason' => null,
+            'consented_at' => now(),
+            'consent_version' => config()->string('billing.auto_recharge.consent_version'),
+            'consented_max_count' => $attributes['max_count'] ?? config()->integer('billing.auto_recharge.default_max'),
+            'consented_max_amount' => PHP_INT_MAX >> 32,
+        ]);
+    }
+
+    /** 同意時上限額を明示する (価格改定 → 再同意要求のシナリオ用)。 */
+    public function consentedMaxAmount(int $amount): static
+    {
+        return $this->state(fn (): array => [
+            'consented_max_amount' => $amount,
+        ]);
+    }
+
+    /** 連続失敗で自動停止された状態。 */
+    public function disabledByFailures(): static
+    {
+        return $this->enabled()->state(fn (): array => [
+            'enabled' => false,
+            'failure_count' => config()->integer('billing.auto_recharge.max_failures'),
+            'disabled_reason' => AutoRechargeDisabledReason::PaymentFailures,
+        ]);
+    }
+}
diff --git a/database/migrations/2026_07_17_000600_create_ticket_auto_recharges_table.php b/database/migrations/2026_07_17_000600_create_ticket_auto_recharges_table.php
new file mode 100644
index 0000000..5a66a5e
--- /dev/null
+++ b/database/migrations/2026_07_17_000600_create_ticket_auto_recharges_table.php
@@ -0,0 +1,50 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Schema;
+
+/**
+ * P8a: オートリチャージ設定 (1 org 1 行)。
+ *
+ * 無料パーソナル (Stripe サブスクなし) でも持てるため subscription FK は持たない。
+ * 同意スナップショット (consent_*) は off-session mandate の記録 (再同意判定の出典)。
+ * **既定 off の opt-in**: 行が無い org の挙動は完全不変。
+ */
+return new class extends Migration
+{
+    public function up(): void
+    {
+        Schema::create('ticket_auto_recharges', function (Blueprint $table): void {
+            $table->id();
+            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
+            $table->boolean('enabled')->default(false);
+            $table->unsignedInteger('threshold_count');
+            $table->unsignedInteger('max_count');
+            // 保存 PM の表示用 snapshot (真実源は Stripe customer の invoice_settings.default_payment_method)
+            $table->string('stripe_payment_method_id', 64)->nullable();
+            $table->unsignedTinyInteger('failure_count')->default(0);
+            $table->string('disabled_reason', 32)->nullable();
+            $table->timestamp('consented_at')->nullable();
+            $table->string('consent_version', 16)->nullable();
+            $table->unsignedInteger('consented_max_count')->nullable();
+            $table->unsignedInteger('consented_max_amount')->nullable();
+            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
+            $table->timestamps();
+        });
+
+        // CHECK は sqlite の ALTER TABLE ADD CONSTRAINT 非対応のため driver guard。
+        // 全 driver 共通の防御はアプリ層 (FormRequest + Service Assert) が担う。
+        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)) {
+            DB::statement('ALTER TABLE ticket_auto_recharges ADD CONSTRAINT ticket_auto_recharges_max_gt_threshold CHECK (max_count > threshold_count)');
+        }
+    }
+
+    public function down(): void
+    {
+        Schema::dropIfExists('ticket_auto_recharges');
+    }
+};
diff --git a/database/migrations/2026_07_17_000610_create_ticket_auto_recharge_attempts_table.php b/database/migrations/2026_07_17_000610_create_ticket_auto_recharge_attempts_table.php
new file mode 100644
index 0000000..f605c1d
--- /dev/null
+++ b/database/migrations/2026_07_17_000610_create_ticket_auto_recharge_attempts_table.php
@@ -0,0 +1,61 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Schema;
+use RuntimeException;
+
+/**
+ * P8a: リチャージ試行の状態機械 (pending → paid | failed | canceled)。
+ *
+ * quantity は attempt 作成時に一度だけ確定し以降の真実源。unit_amount は
+ * webhook amount cross-check の pin。
+ */
+return new class extends Migration
+{
+    private const string PENDING_INDEX_NAME = 'tar_attempts_org_pending_unique';
+
+    public function up(): void
+    {
+        Schema::create('ticket_auto_recharge_attempts', function (Blueprint $table): void {
+            $table->id();
+            // Stripe idempotency key / invoice metadata に載せる外部識別子 (連番 id を漏らさない)
+            $table->ulid('attempt_ulid')->unique();
+            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
+            $table->string('status', 16);
+            $table->unsignedInteger('quantity');
+            $table->unsignedInteger('unit_amount');
+            $table->string('stripe_price_id', 64);
+            $table->string('stripe_invoice_id', 64)->nullable()->unique();
+            $table->string('stripe_payment_intent_id', 64)->nullable();
+            $table->string('failure_code', 64)->nullable();
+            $table->timestamp('resolved_at')->nullable();
+            $table->timestamps();
+            $table->index(['organization_id', 'status']);
+            $table->index(['status', 'created_at']);
+        });
+
+        // 「org に pending は同時に 1 つまで」の hard invariant。
+        // 部分 UNIQUE index の driver guard は既存前例
+        // (2026_07_13_180622_add_signup_grant_unique_index_to_ticket_ledger_entries) に揃える
+        // = 非対応 driver は fail-closed (黙って invariant を落とさない)。
+        $driver = DB::connection()->getDriverName();
+        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
+            throw new RuntimeException("部分 UNIQUE index 未対応の driver: {$driver} (pgsql/sqlite のみ対応)");
+        }
+
+        DB::statement(
+            'CREATE UNIQUE INDEX '.self::PENDING_INDEX_NAME.
+            " ON ticket_auto_recharge_attempts (organization_id) WHERE status = 'pending'",
+        );
+    }
+
+    public function down(): void
+    {
+        DB::statement('DROP INDEX IF EXISTS '.self::PENDING_INDEX_NAME);
+        Schema::dropIfExists('ticket_auto_recharge_attempts');
+    }
+};
diff --git a/database/migrations/2026_07_17_000620_add_stripe_invoice_id_to_ticket_ledger_entries.php b/database/migrations/2026_07_17_000620_add_stripe_invoice_id_to_ticket_ledger_entries.php
new file mode 100644
index 0000000..fc2428d
--- /dev/null
+++ b/database/migrations/2026_07_17_000620_add_stripe_invoice_id_to_ticket_ledger_entries.php
@@ -0,0 +1,37 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\Schema;
+
+/**
+ * P8a (D30): オートリチャージ (Checkout 非経由の off-session Invoice 課金) の
+ * 「購入アンカー」を台帳に足す。
+ *
+ * - checkout 購入: stripe_checkout_session_id (従来どおり)
+ * - リチャージ購入: stripe_invoice_id (本列)
+ *
+ * `ticket_purchases` は移植しない (D30 = ユーザー決定 F3「台帳の置換ではない」)。
+ * 返金逆引きの正本は既存どおり ledger の payment_intent_id + purchase_amount で、
+ * 本列は invoice 起点の監査逆引き用アンカー。既存行・既存経路は不変。
+ */
+return new class extends Migration
+{
+    public function up(): void
+    {
+        Schema::table('ticket_ledger_entries', function (Blueprint $table): void {
+            $table->string('stripe_invoice_id', 64)->nullable()->after('stripe_checkout_session_id');
+            $table->index('stripe_invoice_id');
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::table('ticket_ledger_entries', function (Blueprint $table): void {
+            $table->dropIndex(['stripe_invoice_id']);
+            $table->dropColumn('stripe_invoice_id');
+        });
+    }
+};
diff --git a/docs/architecture.md b/docs/architecture.md
index fed336e..3a392ac 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -99,6 +99,8 @@ ## ドメインモデル (テンプレート同梱)
 | `Billing/TicketCheckoutSession` | チケットスポット購入の Stripe Checkout Session 追跡 (attempt_token 冪等 + 単価 pin = webhook 金額照合の出典。status: pending/completed/expired) | Organization 従属 |
 | `Billing/BillingCheckoutSession` | サブスク契約 Stripe Checkout Session の追跡 (attempt_token 冪等。`BillingAccess::state()` の PendingCheckout / ExpiredCheckout の出典。status: pending/completed/failed/expired) | Organization 従属 |
 | `Billing/Subscription` | Cashier Subscription のテンプレート拡張 (current_period_end / has_payment_method / Subscription Schedule の部分完了追跡列) | Organization 従属 |
+| `Billing/TicketAutoRecharge` | オートリチャージ設定 (1 org 1 行。**既定 off の opt-in**。同意 snapshot 4 列 + 連続失敗状態。`max_count > threshold_count` は DB CHECK) | Organization 従属 |
+| `Billing/TicketAutoRechargeAttempt` | オートリチャージ試行の状態機械 (pending → paid / failed / canceled。quantity・unit_amount は起票時 pin = webhook 金額照合の出典。partial unique `tar_attempts_org_pending_unique` で org あたり pending は 1 件) | Organization 従属 |
 | `Billing/BillingNotification` | 請求通知の delivery record (通知台帳。(type, invoice_id) / (type, dedup_key) 複合 UNIQUE で send-once を構造保証) | Organization 従属 |
 
 ## 主要 Service (テンプレート同梱)
@@ -253,6 +255,56 @@ ## チケットスポット購入 (T007) の運用契約
   DB 行は checkout 開始時の期限切れ回収 (`status=pending AND expires_at <= now` → expired) で
   局所回収する (専用 cron は作らない)
 
+## チケット オートリチャージ (P8a) の運用契約
+
+**opt-in・既定 off**。`ticket_auto_recharges` に行が無い組織の課金挙動は完全に不変
+(`reserve` の低残高通知も含む)。残高が閾値を割ったら off-session の Stripe Invoice で
+自動購入する。
+
+- **経路**: `POST /billing/auto-recharge` (設定更新) / `POST /billing/auto-recharge/setup`
+  (カード登録 = Checkout mode=setup)。いずれも current org スコープ + `manageBilling`。
+  課金ゲート (`require-active-subscription`) の対象外 = 支払い不健全な組織でも停止・
+  カード更新に到達できる
+- **トリガ点は `reserve`** (移植元の `commit` ではない)。AI-CUE の実効残高が減る唯一の
+  消費イベントは `reserve` で、`commit` は拘束 −amount と台帳 −amount が相殺して balance
+  不変のため、`commit` に置くと閾値クロスを取り逃す。`TicketLedgerService::reserve` の
+  `DB::afterCommit` で既存の低残高通知と**同居**させる (parity の名で既存通知を削らない)
+- **閾値判定・数量確定は `availableTrueBalance()`** (表示用 `balance()` は clamp 済みで、
+  判定に使うと返金債務を隠して過剰補充する)。`quantity = min(max_count − 真値残高,
+  PURCHASE_MAX_COUNT)` を attempt 作成時に一度だけ確定し、以降 `attempt.quantity` が真実源
+- **二重課金防止 3 層**: (1) Stripe idempotency key `auto-recharge:{attempt_ulid}` で
+  invoice create / pay が同一 invoice に収束 → (2) partial unique
+  `tar_attempts_org_pending_unique` で org あたり pending attempt は同時 1 つ →
+  (3) 付与は台帳 `idempotency_key = recharge:{invoiceId}` UNIQUE。加えて **failed / canceled
+  への遷移は invoice 終端 (void/delete) 成功後のみ** = open invoice を残して終端しないため
+  遅延成功による二重課金が構造的に起きない
+- **並行制御**: 全ミューテータ (`updateSettings` / `recordPreConsent` / `applySetupCompletion` /
+  `executeAttempt`) が同一ロック `billing:auto-recharge:{orgId}` (TTL 180 秒) を取るため、
+  停止後課金と部分適用が構造的に起こらない。`createAttemptLocked` は `reserve` と同順で
+  `organizations` 行を `lockForUpdate` する (ロック順序の交差を作らない)
+- **SCA (authentication_required) は終端させない**: pending 維持 + 日次リマインダ
+  (dedup = JST date bucket)。`pending_expiry_hours` 超過でリコンサイルが failed 終端する
+- **再同意 (`reconsentRequiredFor`) は単一述語**: version 改定 ∨ 同意記録欠落 ∨ 上限超過 ∨
+  現行カタログ最大請求額 > 同意時金額。UI 表示 / 設定更新 / 自動有効化 / attempt 起票停止の
+  **4 箇所で共有**する。同意金額は必ずサーバ再計算 (client hidden の金額は受け取らない)
+- **同意文言バージョン (`config('billing.auto_recharge.consent_version')`)**: 提示条件の実質
+  (開始残高・補充枚数・上限額の提示形式・停止方法・即時課金可能性・**カードの取得手段**) を
+  変える改定では必ず version を上げる。上げると既存同意が自動失効し、再同意まで自動購入が
+  止まる (fail-closed)
+- **監視対象 (必須項目として登録する)**: **`php artisan billing:reconcile-auto-recharge`
+  (scheduler で `*/15 * * * *`・`onOneServer()` + `withoutOverlapping()`)**。
+  webhook が `MAX_PROCESSING_ATTEMPTS = 8` で恒久 drop した「課金済み・チケット未付与」を
+  回収する**唯一の**経路であり、停止・失敗が続くと資金回収済み・未付与が滞留する。
+  失敗は `onFailure` → `report()` で既存の運用アラート経路に載る (routes/console.php)。
+  **滞留の観測点**: `ticket_auto_recharge_attempts` の `status='pending'` 件数
+  (および `created_at` が `pending_expiry_hours` を超えた行の有無)
+- **terminal failure の運用手順**: `stripe_webhook_events.failure_reason` を確認したうえで、
+  復旧は手動付与ではなく `billing:reconcile-auto-recharge` の 1 回実行で行う
+  (Stripe 上 paid の invoice を検出して `recharge:{invoiceId}` 冪等で付与する)
+- **rollback**: 全変更が additive (新テーブル 2 + 列 1 + 新 route/Job/Command)。既定 off の
+  ため設定行が存在せず、コード revert で即時復帰できる。pending attempt が残る場合のみ
+  revert 前に `billing:reconcile-auto-recharge` を 1 回流して収束させる
+
 ## アプリ内通知センター (T008) の運用契約
 
 - **格納**: Laravel 標準 `notifications` テーブル (Eloquent 標準 `DatabaseNotification` を使う。
diff --git a/docs/factories.md b/docs/factories.md
index 83d979e..8dc8279 100644
--- a/docs/factories.md
+++ b/docs/factories.md
@@ -41,6 +41,8 @@ ## Factory 一覧 (テンプレート同梱)
 | `Billing\TicketCheckoutSessionFactory` | Billing/TicketCheckoutSession | `forOrganization($org)`, `initiatedBy($user)`, `completed()`, `expired()`, `stale()` (pending のまま expires_at 過去) |
 | `Billing\TicketReservationFactory` | Billing/TicketReservation | `forOrganization($org)`, `legacy()` (P5 前の in-flight 予約 = `consume_*` null), `monthlyHold(?CarbonImmutable $consumeExpiresAt = null)`, `purchasedHold()`, `stale()` (reserved のまま TTL 超過) |
 | `Billing\BillingCheckoutSessionFactory` | Billing/BillingCheckoutSession | `withAttemptToken($token, ?$checkoutUrl)`, `initiatedBy(int $userId)`, `completed()`, `setupPaymentMethod()`, `expired()`, `failed()`, `stale()` (pending のまま created_at が stale 境界より過去) |
+| `Billing\TicketAutoRechargeFactory` | Billing/TicketAutoRecharge | `enabled()` (PM + 同意記録済み), `preConsented()` (事前同意のみ = pendingAutoEnable), `consentedMaxAmount(int $amount)` (価格改定 → 再同意シナリオ), `disabledByFailures()` |
+| `Billing\TicketAutoRechargeAttemptFactory` | Billing/TicketAutoRechargeAttempt | `withInvoice(?string $invoiceId = null)`, `paid()`, `failed()`, `canceled()` (既定は invoice 未作成の pending。**org あたり pending は DB partial unique で 1 件まで**) |
 
 Factory を持たないモデル (Role / Permission / Team 等) は seed 固定値
 または Service (`OrganizationProvisioningService` 等) 経由で作る。
diff --git a/lang/ja/validation.php b/lang/ja/validation.php
index 731e771..7e90b67 100644
--- a/lang/ja/validation.php
+++ b/lang/ja/validation.php
@@ -212,6 +212,12 @@
         'declaration' => '個人利用の確認',
         'count' => '購入枚数',
         'attempt_token' => '操作トークン',
+        // オートリチャージ (P8a)。'enabled' は 2 段階認証と同名キーのため
+        // UpdateAutoRechargeRequest::attributes() で個別に上書きする
+        'threshold_count' => 'リチャージ開始残高',
+        'max_count' => 'リチャージ後の残高',
+        'consent_version' => '自動購入への同意',
+        'funding_choice' => 'チケットの補充方法',
         // --- プロジェクト・マニュアル ---
         'description' => '説明',
         'note' => 'メモ',
diff --git a/resources/js/components/features/billing/AutoRechargeCard.svelte b/resources/js/components/features/billing/AutoRechargeCard.svelte
new file mode 100644
index 0000000..f8b0fb2
--- /dev/null
+++ b/resources/js/components/features/billing/AutoRechargeCard.svelte
@@ -0,0 +1,482 @@
+<script lang="ts">
+    import { router } from "@inertiajs/svelte";
+    import { BatteryCharging, CreditCard, TriangleAlert } from "@lucide/svelte";
+    import Alert from "@/components/atoms/Alert.svelte";
+    import Badge from "@/components/atoms/Badge.svelte";
+    import Button from "@/components/atoms/Button.svelte";
+    import Card from "@/components/atoms/Card.svelte";
+    import Input from "@/components/atoms/Input.svelte";
+    import FormField from "@/components/molecules/FormField.svelte";
+    import type { AutoRechargeProps } from "@/types/billing";
+
+    /**
+     * AutoRechargeCard — 請求画面の「チケット オートリチャージ (自動補充)」セクション (P8a)。
+     *
+     * 残高が閾値を下回ったら、保存済みカードで上限 (Max) まで自動購入する。
+     * subscription 非依存 — 無料パーソナルを含む全プランで利用できる。**既定 off の opt-in**。
+     *
+     * 表示状態:
+     *  - カード未登録: 有効化 CTA を出さず「カードを登録する」CTA (Checkout mode=setup へ)
+     *  - 登録処理中 (setupPending): 「カード登録処理中」表示 (webhook Job 反映待ち、30 分窓)
+     *  - 失敗停止 (disabledReason='payment_failures'): danger バナー + 再有効化導線
+     *  - 有効/無効: 閾値・Max の編集 + 有効化 (同意パネル経由) / 停止 (常に押せる)
+     *
+     * fail-closed の対称性: 有効化は同意を要求し、停止は一切妨げない (ワンクリック停止)。
+     * **ボタンは条件未充足で disabled にしない** (AGENTS.md 禁止事項 #8) — 押下時に
+     * 入力エラーを表示する。in-flight 中の多重送信抑止は Button の loading で表現する。
+     */
+    interface Props {
+        autoRecharge: AutoRechargeProps;
+        /** 設定更新 POST 先 (billing.auto-recharge.update) */
+        updateUrl: string;
+        /** カード登録開始 POST 先 (billing.auto-recharge.setup) */
+        setupUrl: string;
+        /** setup 開始 POST の attempt_token (props 由来・render 固定で二重 submit を冪等化) */
+        setupAttemptToken: string;
+    }
+
+    let { autoRecharge, updateUrl, setupUrl, setupAttemptToken }: Props = $props();
+
+    // 一方向 value + oninput (type=number への two-way bind 禁止規約)。props 更新で正準値へ再同期。
+    let thresholdText = $derived(String(autoRecharge.thresholdCount));
+    let maxText = $derived(String(autoRecharge.maxCount));
+    let submitting = $state(false);
+    let showConsent = $state(false);
+    /** 押下時に初めて出す入力エラー (disabled でブロックしない代わりの提示点) */
+    let inputError = $state<string | null>(null);
+    /** サーバ 422 の可視化 (flash toast は errors bag を運ばないため silent failure を防ぐ) */
+    let serverError = $state<string | null>(null);
+
+    const pickServerError = (errors: Record<string, string>): string | null => {
+        for (const key of [
+            "enabled",
+            "consent_version",
+            "threshold_count",
+            "max_count",
+            "attempt_token",
+        ]) {
+            const message = errors[key];
+            if (typeof message === "string" && message !== "") return message;
+        }
+        return Object.values(errors).find((v) => typeof v === "string" && v !== "") ?? null;
+    };
+
+    const formatYen = (v: number): string => new Intl.NumberFormat("ja-JP").format(v);
+
+    const parseIntStrict = (raw: string): number | null => {
+        const trimmed = raw.trim();
+        if (trimmed === "" || !/^\d+$/.test(trimmed)) return null;
+        const n = Number.parseInt(trimmed, 10);
+        return Number.isNaN(n) ? null : n;
+    };
+
+    const parsedThreshold = $derived.by<number | null>(() => {
+        const n = parseIntStrict(thresholdText);
+        return n === null || n < 0 ? null : n;
+    });
+
+    const parsedMax = $derived.by<number | null>(() => {
+        const n = parseIntStrict(maxText);
+        if (n === null || n < autoRecharge.minCount || n > autoRecharge.maxCountLimit) return null;
+        return n;
+    });
+
+    const rangeError = $derived.by<string | null>(() => {
+        if (parsedThreshold === null) {
+            return "リチャージ開始残高は 0 以上の整数で入力してください";
+        }
+        if (parsedMax === null) {
+            return `リチャージ後の残高は ${autoRecharge.minCount} 〜 ${autoRecharge.maxCountLimit} の整数で入力してください`;
+        }
+        if (parsedMax <= parsedThreshold) {
+            return "リチャージ後の残高は開始残高より大きい値を指定してください";
+        }
+        return null;
+    });
+
+    // 適用単価: Max 枚をまとめ買いした場合の tier 単価 (同意文言の上限額と同じ計算)。
+    const appliedUnit = $derived.by<number>(() => {
+        const c = parsedMax;
+        if (c === null) return autoRecharge.baseUnitAmountJpy;
+        let unit = autoRecharge.tiers[0]?.unitAmount ?? autoRecharge.baseUnitAmountJpy;
+        for (const t of autoRecharge.tiers) {
+            if (c >= t.minCount) unit = t.unitAmount;
+        }
+        return unit;
+    });
+
+    const maxChargeAmount = $derived(
+        parsedMax !== null && rangeError === null ? parsedMax * appliedUnit : null,
+    );
+
+    const consentLines = $derived.by<string[]>(() => {
+        const lines = [
+            `残高が ${parsedThreshold ?? autoRecharge.thresholdCount} 枚を下回ると、登録済みのカードで不足分をまとめて購入し、${parsedMax ?? autoRecharge.maxCount} 枚まで補充します。`,
+        ];
+        if (maxChargeAmount !== null) {
+            lines.push(`1 回の自動購入の上限額は ¥${formatYen(maxChargeAmount)} (税込) です。`);
+        }
+        lines.push("この設定はあとからいつでも変更・停止できます。");
+        return lines;
+    });
+
+    const stateBadge = $derived.by<{ label: string; tone: "success" | "danger" | "neutral" }>(
+        () => {
+            if (autoRecharge.enabled) return { label: "有効", tone: "success" };
+            if (autoRecharge.disabledReason === "payment_failures") {
+                return { label: "自動停止中", tone: "danger" };
+            }
+            return { label: "無効", tone: "neutral" };
+        },
+    );
+
+    interface UpdatePayload {
+        enabled: boolean;
+        threshold_count: number;
+        max_count: number;
+        consent_version?: string;
+        [key: string]: boolean | number | string | undefined;
+    }
+
+    function post(payload: UpdatePayload): void {
+        submitting = true;
+        serverError = null;
+        router.post(updateUrl, payload, {
+            preserveScroll: true,
+            onError: (errors: Record<string, string>) => {
+                serverError = pickServerError(errors);
+            },
+            onSuccess: () => {
+                serverError = null;
+                inputError = null;
+                showConsent = false;
+            },
+            onFinish: () => {
+                submitting = false;
+            },
+        });
+    }
+
+    /** 入力値の妥当性を押下時に確定する (disabled でブロックしない = 禁止事項 #8)。 */
+    function ensureValidRange(): boolean {
+        inputError = rangeError;
+        return rangeError === null;
+    }
+
+    function openConsent(): void {
+        if (submitting) return;
+        if (!ensureValidRange()) return;
+        showConsent = true;
+    }
+
+    function confirmEnable(): void {
+        if (submitting) return;
+        if (!ensureValidRange() || parsedThreshold === null || parsedMax === null) return;
+        post({
+            enabled: true,
+            threshold_count: parsedThreshold,
+            max_count: parsedMax,
+            // 同意文言バージョンのみ送る。金額はサーバが現行カタログで再計算する。
+            consent_version: autoRecharge.consentVersion,
+        });
+    }
+
+    /** 有効のまま閾値/Max を更新。上限引き上げ・再同意要求時は同意パネルを経由する。 */
+    function handleUpdate(): void {
+        if (submitting) return;
+        if (!ensureValidRange() || parsedThreshold === null || parsedMax === null) return;
+        if (autoRecharge.requiresReconsent || parsedMax > autoRecharge.maxCount) {
+            showConsent = true;
+            return;
+        }
+        post({
+            enabled: true,
+            threshold_count: parsedThreshold,
+            max_count: parsedMax,
+            consent_version: autoRecharge.consentVersion,
+        });
+    }
+
+    /** カード未登録時の設定保存 (enabled=false の upsert)。有効化はしない。 */
+    function handleSaveDraft(): void {
+        if (submitting) return;
+        if (!ensureValidRange() || parsedThreshold === null || parsedMax === null) return;
+        post({ enabled: false, threshold_count: parsedThreshold, max_count: parsedMax });
+    }
+
+    /** 停止は常に成立させる (入力値が壊れていても現在値で送る = ワンクリック停止の保証)。 */
+    function handleDisable(): void {
+        if (submitting) return;
+        inputError = null;
+        const threshold = parsedThreshold ?? autoRecharge.thresholdCount;
+        const max =
+            parsedMax !== null && parsedMax > threshold ? parsedMax : autoRecharge.maxCount;
+        post({ enabled: false, threshold_count: threshold, max_count: max });
+    }
+
+    function handleStartSetup(): void {
+        if (submitting) return;
+        submitting = true;
+        serverError = null;
+        router.post(
+            setupUrl,
+            { attempt_token: setupAttemptToken },
+            {
+                onError: (errors: Record<string, string>) => {
+                    serverError = pickServerError(errors);
+                },
+                onSuccess: () => {
+                    serverError = null;
+                },
+                onFinish: () => {
+                    submitting = false;
+                },
+            },
+        );
+    }
+
+    // setupPending (カード登録の webhook/Job 反映待ち) の間、autoRecharge props だけを
+    // partial reload でポーリングし、反映され次第 UI を自動で切り替える (手動リロード不要)。
+    // 30 分窓 (サーバ側 stale 判定) を超えると props 側が false になるため無限ポーリングはしない。
+    $effect(() => {
+        if (!autoRecharge.setupPending) return;
+
+        const intervalId = window.setInterval(() => {
+            router.reload({ only: ["autoRecharge"] });
+        }, 4000);
+
+        return () => window.clearInterval(intervalId);
+    });
+</script>
+
+<Card padding="lg" testId="auto-recharge-card">
+    <div class="flex flex-wrap items-center gap-2">
+        <BatteryCharging class="h-5 w-5 text-text-secondary" aria-hidden="true" />
+        <h2 class="text-h3">チケット オートリチャージ</h2>
+        <Badge tone={stateBadge.tone} bordered testId="auto-recharge-state-badge">
+            {stateBadge.label}
+        </Badge>
+    </div>
+    <p class="mt-1 text-body text-text-secondary">
+        残高が少なくなったら、不足分をまとめて自動購入し、設定した枚数まで補充します。
+        設定しない限り自動購入は行われません。
+    </p>
+
+    {#if autoRecharge.enabled && autoRecharge.requiresReconsent}
+        <div class="mt-4">
+            <Alert type="warning" testId="auto-recharge-reconsent-banner">
+                チケット単価の改定により、自動購入の上限額が変わりました。内容を確認して再度同意するまで、自動購入は行われません。
+            </Alert>
+        </div>
+    {/if}
+
+    {#if autoRecharge.disabledReason === "payment_failures"}
+        <div class="mt-4">
+            <Alert type="danger" testId="auto-recharge-failure-banner">
+                決済が続けて失敗したため、オートリチャージを自動停止しました。カード情報を更新のうえ、再度有効にしてください。
+            </Alert>
+        </div>
+    {/if}
+
+    <!-- カード状態 (未登録 CTA / 処理中 / 登録済み表示)。設定入力はカード有無に関わらず常時
+         表示する — 「開始残高・補充枚数が見えない」を防ぐ。有効化だけカード登録後 (fail-closed)。 -->
+    {#if !autoRecharge.hasPaymentMethod}
+        {#if autoRecharge.setupPending}
+            <p class="mt-4 text-body text-text-secondary" data-testid="auto-recharge-setup-pending">
+                {autoRecharge.pendingAutoEnable
+                    ? "お支払い情報を処理しています。反映後、オートリチャージは自動で有効になります (同意済み)。"
+                    : "カード登録を処理しています。完了すると自動で表示が切り替わります。"}
+            </p>
+        {:else}
+            <p class="mt-4 text-body text-text-secondary" data-testid="auto-recharge-no-pm">
+                {autoRecharge.pendingAutoEnable
+                    ? "カード登録が完了すると、同意済みの内容でオートリチャージが自動で有効になります。"
+                    : "オートリチャージには、自動購入に使うカードの登録が必要です。開始残高・補充枚数はカード登録前でも設定・保存できます。"}
+            </p>
+            {#if autoRecharge.canManage}
+                <div class="mt-3">
+                    <Button
+                        variant="primary"
+                        loading={submitting}
+                        onclick={handleStartSetup}
+                        testId="auto-recharge-setup"
+                    >
+                        <CreditCard class="h-4 w-4" aria-hidden="true" />
+                        カードを登録する
+                    </Button>
+                </div>
+            {/if}
+        {/if}
+    {:else}
+        <div
+            class="mt-4 flex items-center gap-2 text-body text-text-secondary"
+            data-testid="auto-recharge-pm"
+        >
+            <CreditCard class="h-4 w-4 shrink-0" aria-hidden="true" />
+            <span>
+                お支払いカード: {autoRecharge.paymentMethodBrand ?? "カード"}
+                {#if autoRecharge.paymentMethodLast4}
+                    •••• {autoRecharge.paymentMethodLast4}
+                {/if}
+            </span>
+        </div>
+    {/if}
+
+    <div class="mt-4 grid gap-4 md:grid-cols-2">
+        <FormField label="リチャージ開始残高 (残りがこの枚数を下回ったら購入)" id="auto-recharge-threshold">
+            {#snippet children({ id, describedBy, invalid })}
+                <Input
+                    {id}
+                    type="number"
+                    min="0"
+                    step="1"
+                    value={thresholdText}
+                    error={invalid}
+                    aria-describedby={describedBy}
+                    readonly={!autoRecharge.canManage}
+                    testId="auto-recharge-threshold-input"
+                    oninput={(e: Event) => {
+                        const t = e.currentTarget;
+                        if (t instanceof HTMLInputElement) thresholdText = t.value;
+                    }}
+                />
+            {/snippet}
+        </FormField>
+        <FormField label="リチャージ後の残高 (この枚数まで補充)" id="auto-recharge-max">
+            {#snippet children({ id, describedBy, invalid })}
+                <Input
+                    {id}
+                    type="number"
+                    min={autoRecharge.minCount}
+                    max={autoRecharge.maxCountLimit}
+                    step="1"
+                    value={maxText}
+                    error={invalid}
+                    aria-describedby={describedBy}
+                    readonly={!autoRecharge.canManage}
+                    testId="auto-recharge-max-input"
+                    oninput={(e: Event) => {
+                        const t = e.currentTarget;
+                        if (t instanceof HTMLInputElement) maxText = t.value;
+                    }}
+                />
+            {/snippet}
+        </FormField>
+    </div>
+
+    {#if maxChargeAmount !== null}
+        <p class="mt-2 text-body text-text-secondary" data-testid="auto-recharge-max-amount">
+            1 回の自動購入の上限額: ¥{formatYen(maxChargeAmount)} (税込・1 枚あたり ¥{formatYen(
+                appliedUnit,
+            )})
+        </p>
+    {/if}
+
+    {#if inputError !== null}
+        <p
+            class="mt-2 text-caption text-danger"
+            aria-live="polite"
+            data-testid="auto-recharge-range-error"
+        >
+            {inputError}
+        </p>
+    {/if}
+
+    {#if showConsent}
+        <div class="mt-4">
+            <Alert type="info" title="自動購入への同意" testId="auto-recharge-consent">
+                <ul class="flex flex-col gap-1">
+                    {#each consentLines as line (line)}
+                        <li>{line}</li>
+                    {/each}
+                </ul>
+                {#snippet action()}
+                    <div class="flex flex-wrap gap-3">
+                        <Button
+                            variant="primary"
+                            loading={submitting}
+                            onclick={confirmEnable}
+                            testId="auto-recharge-consent-confirm"
+                        >
+                            同意して有効にする
+                        </Button>
+                        <Button
+                            variant="ghost"
+                            onclick={() => {
+                                showConsent = false;
+                            }}
+                            testId="auto-recharge-consent-cancel"
+                        >
+                            今は有効にしない
+                        </Button>
+                    </div>
+                {/snippet}
+            </Alert>
+        </div>
+    {/if}
+
+    {#if autoRecharge.canManage}
+        <div class="mt-4 flex flex-wrap gap-3">
+            {#if autoRecharge.enabled}
+                <Button
+                    variant="primary"
+                    loading={submitting}
+                    onclick={handleUpdate}
+                    testId="auto-recharge-update"
+                >
+                    設定を更新する
+                </Button>
+                <Button
+                    variant="ghost"
+                    loading={submitting}
+                    onclick={handleDisable}
+                    testId="auto-recharge-disable"
+                >
+                    停止する
+                </Button>
+            {:else if autoRecharge.hasPaymentMethod}
+                <Button
+                    variant="primary"
+                    loading={submitting}
+                    onclick={openConsent}
+                    testId="auto-recharge-enable"
+                >
+                    <BatteryCharging class="h-4 w-4" aria-hidden="true" />
+                    有効にする
+                </Button>
+            {:else}
+                <!-- カード未登録: 有効化は出さず (fail-closed)、設定値の保存だけ許可する -->
+                <Button
+                    variant="ghost"
+                    loading={submitting}
+                    onclick={handleSaveDraft}
+                    testId="auto-recharge-save-draft"
+                >
+                    設定を保存する
+                </Button>
+            {/if}
+        </div>
+    {:else}
+        <p class="mt-4 text-caption text-text-secondary" data-testid="auto-recharge-readonly">
+            オートリチャージの設定には組織の管理者権限が必要です。
+        </p>
+    {/if}
+
+    {#if serverError !== null}
+        <p
+            class="mt-2 text-caption text-danger"
+            aria-live="polite"
+            data-testid="auto-recharge-server-error"
+        >
+            <TriangleAlert class="inline h-4 w-4" aria-hidden="true" />
+            {serverError}
+        </p>
+    {/if}
+
+    {#if autoRecharge.enabled}
+        <p class="mt-3 text-caption text-text-secondary" data-testid="auto-recharge-status">
+            オートリチャージが有効です。残高が {autoRecharge.thresholdCount} 枚を下回ったら、不足分をまとめて自動購入し、{autoRecharge.maxCount}
+            枚まで補充します。
+        </p>
+    {/if}
+</Card>
diff --git a/resources/js/pages/Billing/Index.svelte b/resources/js/pages/Billing/Index.svelte
index 034dd65..c264615 100644
--- a/resources/js/pages/Billing/Index.svelte
+++ b/resources/js/pages/Billing/Index.svelte
@@ -8,9 +8,14 @@
     import PageContainer from "@/components/templates/PageContainer.svelte";
     import PageContent from "@/components/templates/PageContent.svelte";
     import PageHeader from "@/components/molecules/PageHeader.svelte";
+    import AutoRechargeCard from "@/components/features/billing/AutoRechargeCard.svelte";
     import { CreditCard } from "@lucide/svelte";
     import type { SharedProps } from "@/lib/shared-props";
-    import type { BillingIndexPlan, BillingIndexPlanPrice } from "@/types/billing";
+    import type {
+        AutoRechargeProps,
+        BillingIndexPlan,
+        BillingIndexPlanPrice,
+    } from "@/types/billing";
 
     /**
      * 課金ページ (現在プラン / チケット残高 / プラン一覧)。
@@ -31,6 +36,10 @@
          * 非 null で届く (サーバが same-origin 内部 path に正規化済み)。
          */
         continueUrl?: string | null;
+        /** P8a: オートリチャージ設定 (常に非 null。既定は enabled=false の opt-in) */
+        autoRecharge: AutoRechargeProps;
+        /** P8a: カード登録 (mode=setup) 開始 POST の attempt_token (render 単位) */
+        autoRechargeSetupToken: string;
     }
 
     let {
@@ -39,6 +48,8 @@
         ticketBalance,
         canManageBilling,
         continueUrl = null,
+        autoRecharge,
+        autoRechargeSetupToken,
     }: Props = $props();
 
     const shared = $derived(page.props as unknown as SharedProps);
@@ -150,6 +161,18 @@
                 {/if}
             </Card>
 
+            <!--
+                P8a: オートリチャージ (裏チャージ) 設定カード。
+                差し込み位置と ?highlight=auto-recharge anchor は P8b (T080) 所管のため、
+                ここでは実体の追加に留める (P8b が後からマージされる前提)。
+            -->
+            <AutoRechargeCard
+                {autoRecharge}
+                updateUrl="/billing/auto-recharge"
+                setupUrl="/billing/auto-recharge/setup"
+                setupAttemptToken={autoRechargeSetupToken}
+            />
+
             <section>
                 <h2 class="text-h3">プラン一覧</h2>
                 <ul class="mt-4 flex flex-col gap-4" data-testid="plan-list">
diff --git a/resources/js/pages/Onboarding/Checkout.svelte b/resources/js/pages/Onboarding/Checkout.svelte
index ca9eebe..61995f0 100644
--- a/resources/js/pages/Onboarding/Checkout.svelte
+++ b/resources/js/pages/Onboarding/Checkout.svelte
@@ -61,6 +61,17 @@
     let submitting = $state(false);
     let declarationChecked = $state(false);
 
+    // P8a (D29(i)): 資金選択。既定は「オートリチャージを設定する」(おすすめ)。
+    // fundingChoices に含まれる値のみ選べる (サーバ確定の並び)。
+    const AUTO_RECHARGE = "auto_recharge";
+    const LATER = "later";
+    let fundingChoice = $state<string>(AUTO_RECHARGE);
+    const fundingChoiceError = $derived(
+        !submitting ? (serverErrors.consent_version ?? serverErrors.funding_choice ?? null) : null,
+    );
+    const consentTerms = $derived(pageData.consentTerms);
+    const formatYen = (v: number): string => new Intl.NumberFormat("ja-JP").format(v);
+
     // サーバ由来エラーを「発生したプラン」にキー付けし、別プランへ切替えると旧エラーが消える。
     let lastSubmittedPlanCode = $state<string | null>(null);
 
@@ -107,7 +118,14 @@
         lastSubmittedPlanCode = "personal";
         router.post(
             "/onboarding/activate-personal",
-            { declaration: declarationChecked ? "1" : "0" },
+            {
+                declaration: declarationChecked ? "1" : "0",
+                funding_choice: fundingChoice,
+                // auto_recharge のときだけ同意 version を送る (金額は送らない = サーバ再計算)。
+                ...(fundingChoice === AUTO_RECHARGE
+                    ? { consent_version: consentTerms.consentVersion }
+                    : {}),
+            },
             {
                 onStart: () => {
                     submitting = true;
@@ -228,6 +246,59 @@
                             testId="personal-declaration"
                         />
 
+                        <!-- P8a (D29(i)): チケットの補充方法の 2 択。既定は自動購入 (おすすめ) だが、
+                             「あとで決める」を選べば課金設定なしで始められる (opt-in を強制しない)。 -->
+                        <fieldset class="flex flex-col gap-2" data-testid="funding-choice">
+                            <legend class="text-caption font-medium text-text">
+                                チケットの補充方法
+                            </legend>
+                            {#each pageData.fundingChoices as choice (choice)}
+                                <label class="flex items-start gap-2">
+                                    <input
+                                        type="radio"
+                                        name="funding_choice"
+                                        value={choice}
+                                        checked={fundingChoice === choice}
+                                        onchange={() => {
+                                            fundingChoice = choice;
+                                        }}
+                                        class="mt-1 h-4 w-4 accent-primary"
+                                        data-testid={`funding-choice-${choice}`}
+                                    />
+                                    <span class="text-body text-text">
+                                        {#if choice === AUTO_RECHARGE}
+                                            残高が少なくなったら自動で購入する（おすすめ）
+                                        {:else}
+                                            あとで決める（無償チケットだけで始める）
+                                        {/if}
+                                    </span>
+                                </label>
+                            {/each}
+
+                            {#if fundingChoice === AUTO_RECHARGE}
+                                <div
+                                    class="rounded-sm border border-border p-3"
+                                    data-testid="funding-consent-terms"
+                                >
+                                    <p class="text-caption text-text-secondary">
+                                        残高が {consentTerms.thresholdCount} 枚を下回ると、登録済みのカードで不足分をまとめて購入し、{consentTerms.maxCount}
+                                        枚まで補充します。1 回の自動購入の上限額は ¥{formatYen(
+                                            consentTerms.maxAmountJpy,
+                                        )}（税込・1 枚あたり ¥{formatYen(consentTerms.unitAmountJpy)}）です。
+                                    </p>
+                                    <p class="mt-1 text-caption text-text-secondary">
+                                        次の画面でカードを登録します。登録しただけでは課金されません。設定はいつでも変更・停止できます。
+                                    </p>
+                                </div>
+                            {/if}
+
+                            {#if fundingChoiceError !== null}
+                                <p class="text-caption text-danger" data-testid="funding-choice-error">
+                                    {fundingChoiceError}
+                                </p>
+                            {/if}
+                        </fieldset>
+
                         <div>
                             <Button
                                 onclick={submitPersonalFree}
diff --git a/resources/js/types/billing.ts b/resources/js/types/billing.ts
index b790a31..2b595e6 100644
--- a/resources/js/types/billing.ts
+++ b/resources/js/types/billing.ts
@@ -15,6 +15,8 @@ export interface PurchaseTicketsPageProps {
     readonly canManage: boolean;
     readonly attemptToken: string;
     readonly purchased: boolean;
+    /** P8a: オートリチャージが有効か (既定 false) */
+    readonly autoRechargeEnabled: boolean;
 }
 
 /** Billing/Index (課金ページ) の Inertia props */
@@ -39,4 +41,44 @@ export interface BillingIndexProps {
      * 契約成立着地でのみ 1 回だけ非 null で届く (リロードでは null に戻る)。
      */
     readonly continueUrl: string | null;
+    /** P8a: オートリチャージ設定 (常に非 null。既定は enabled=false の opt-in) */
+    readonly autoRecharge: AutoRechargeProps;
+    /** P8a: カード登録 (mode=setup) 開始 POST の attempt_token (render 単位) */
+    readonly autoRechargeSetupToken: string;
+}
+
+/**
+ * PHP: AutoRechargeSettingsDto (AutoRechargeShape) と exact 対。
+ * P8a のオートリチャージ (裏チャージ) 設定カードの props。
+ */
+export interface AutoRechargeProps {
+    readonly enabled: boolean;
+    readonly thresholdCount: number;
+    readonly maxCount: number;
+    readonly minCount: number;
+    readonly maxCountLimit: number;
+    readonly canManage: boolean;
+    readonly hasPaymentMethod: boolean;
+    readonly paymentMethodBrand: string | null;
+    readonly paymentMethodLast4: string | null;
+    /** setup 完了 (30 分以内) だが PM snapshot 未反映 = 「カード登録処理中」表示 */
+    readonly setupPending: boolean;
+    /** 価格改定等で現行最大請求額が同意額を超過 = 再同意まで自動購入停止中 */
+    readonly requiresReconsent: boolean;
+    /** 有効な事前同意が待機中 (PM 未登録) = カード登録完了で自動有効化される */
+    readonly pendingAutoEnable: boolean;
+    readonly disabledReason: string | null;
+    readonly failureCount: number;
+    readonly consentVersion: string;
+    readonly baseUnitAmountJpy: number;
+    readonly tiers: readonly PurchaseTierShape[];
+}
+
+/** PHP: AutoRechargeConsentTermsDto (AutoRechargeConsentTermsShape) と exact 対 */
+export interface AutoRechargeConsentTerms {
+    readonly thresholdCount: number;
+    readonly maxCount: number;
+    readonly maxAmountJpy: number;
+    readonly unitAmountJpy: number;
+    readonly consentVersion: string;
 }
diff --git a/resources/js/types/onboarding.ts b/resources/js/types/onboarding.ts
index c02cee1..3383c4a 100644
--- a/resources/js/types/onboarding.ts
+++ b/resources/js/types/onboarding.ts
@@ -1,3 +1,5 @@
+import type { AutoRechargeConsentTerms } from "@/types/billing";
+
 /**
  * 課金オンボーディング (Onboarding/Checkout・Onboarding/BillingRequired) の Inertia props。
  * PHP 側 DTO (App\DataTransferObjects\Onboarding\* / App\DataTransferObjects\Billing\PlanDto) の
@@ -40,6 +42,10 @@ export interface OnboardingCheckoutShape {
      * `plans` への包含は保証しない = 該当 code があるときだけ preselect する。
      */
     readonly intendedPlanCode: string | null;
+    /** P8a (D29(i)): オートリチャージ事前同意の提示条件 (表示値 = 記録値の単一計算源) */
+    readonly consentTerms: AutoRechargeConsentTerms;
+    /** 画面に出す資金選択の並び (enum 値。`tickets` は UI に出さない) */
+    readonly fundingChoices: readonly string[];
 }
 
 /** PHP: BillingRequiredDto (BillingRequiredShape) と対 */
diff --git a/routes/console.php b/routes/console.php
index 953647c..1df9b81 100644
--- a/routes/console.php
+++ b/routes/console.php
@@ -37,6 +37,27 @@
 Schedule::command('billing:send-billing-reminders')->daily()->onOneServer()->withoutOverlapping();
 Schedule::command('billing:reconcile-schedules')->daily();
 
+/*
+|--------------------------------------------------------------------------
+| 課金 cron (オートリチャージ / P8a)
+|--------------------------------------------------------------------------
+| reconcile-auto-recharge: pending attempt の回収 (課金済み回収 / 再実行 / SCA リマインド /
+| 期限切れ終端 / 取りこぼし起票)。
+|
+| **監視対象 (必須)**: webhook が MAX_PROCESSING_ATTEMPTS=8 で恒久 drop した
+| 「課金済み・付与なし」を回収する**唯一の**経路であり、停止すると資金回収済み・チケット
+| 未付与が滞留する。AI-CUE の運用アラート経路は report() のみのため、onFailure をそこへ繋ぐ。
+| 滞留の観測点は ticket_auto_recharge_attempts.status='pending' の件数
+| (docs/architecture.md の監視対象リストを参照)。
+*/
+Schedule::command('billing:reconcile-auto-recharge')
+    ->everyFifteenMinutes()
+    ->onOneServer()
+    ->withoutOverlapping()
+    ->onFailure(static fn () => report(new RuntimeException(
+        'billing:reconcile-auto-recharge 失敗 — 資金回収済み・チケット未付与が滞留する可能性',
+    )));
+
 /*
 |--------------------------------------------------------------------------
 | 問い合わせ (Inquiry) retention purge
diff --git a/routes/web.php b/routes/web.php
index 6f0ee60..e51e200 100644
--- a/routes/web.php
+++ b/routes/web.php
@@ -326,6 +326,18 @@
     Route::post('/billing/portal', [BillingController::class, 'portal'])
         ->name('billing.portal');
 
+    /*
+    | オートリチャージ (裏チャージ。P8a)。**opt-in・既定 off**。
+    | current org スコープ (route parameter なし) で billing.* と同一の解決規約。
+    | 課金ゲート allowlist (require-active-subscription group の外) — 支払い不健全で
+    | 遮断中でも停止・カード更新に到達できることを保証する。
+    | 認可は Controller 冒頭の Gate::authorize('manageBilling')。
+    */
+    Route::post('/billing/auto-recharge', [BillingController::class, 'updateAutoRecharge'])
+        ->name('billing.auto-recharge.update');
+    Route::post('/billing/auto-recharge/setup', [BillingController::class, 'startAutoRechargeSetup'])
+        ->name('billing.auto-recharge.setup');
+
     /*
     | 課金オンボーディング (current org スコープ)。登録直後の Plan 選択 +
     | 未契約 manageBilling なし member 向け説明画面。billing.* と同じく課金ゲート
diff --git a/tests/Feature/Billing/AutoRechargeEndpointTest.php b/tests/Feature/Billing/AutoRechargeEndpointTest.php
new file mode 100644
index 0000000..6bd552b
--- /dev/null
+++ b/tests/Feature/Billing/AutoRechargeEndpointTest.php
@@ -0,0 +1,216 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\OnboardingBillingState;
+use App\Enums\CheckoutIntent;
+use App\Enums\CheckoutSessionStatus;
+use App\Enums\OrganizationRole;
+use App\Models\Billing\BillingCheckoutSession;
+use App\Models\Billing\TicketAutoRecharge;
+use App\Services\Billing\BillingAccess;
+use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
+use Illuminate\Support\Str;
+use Tests\Support\FakeAutoRechargeGateway;
+
+/*
+ * P8a: オートリチャージ endpoint の認可 / validation / 着地。
+ */
+
+beforeEach(function (): void {
+    $this->gateway = new FakeAutoRechargeGateway;
+    app()->instance(AutoRechargeGatewayInterface::class, $this->gateway);
+});
+
+test('manageBilling を持たない member は設定更新できない (403)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization, OrganizationRole::Member);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $this->actingAs($member)
+        ->post('/billing/auto-recharge', [
+            'enabled' => false,
+            'threshold_count' => 5,
+            'max_count' => 50,
+        ])
+        ->assertForbidden();
+
+    expect(TicketAutoRecharge::query()->count())->toBe(0);
+});
+
+test('manageBilling を持たない member はカード登録を開始できない (403)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization, OrganizationRole::Member);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $this->actingAs($member)
+        ->post('/billing/auto-recharge/setup', ['attempt_token' => strtolower((string) Str::ulid())])
+        ->assertForbidden();
+
+    expect(BillingCheckoutSession::query()->count())->toBe(0);
+});
+
+test('他組織の設定は触れない — current org スコープで解決されるため cross-org 書き込みが起きない', function (): void {
+    [$organizationA, $ownerA] = createOrganizationWithOwner('組織A');
+    [$organizationB] = createOrganizationWithOwner('組織B');
+
+    $this->actingAs($ownerA)
+        ->post('/billing/auto-recharge', [
+            'enabled' => false,
+            'threshold_count' => 3,
+            'max_count' => 30,
+        ])
+        ->assertRedirect();
+
+    expect(TicketAutoRecharge::query()->where('organization_id', $organizationA->id)->exists())->toBeTrue()
+        ->and(TicketAutoRecharge::query()->where('organization_id', $organizationB->id)->exists())->toBeFalse();
+});
+
+test('enabled=true で consent_version 欠落は 422', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)
+        ->post('/billing/auto-recharge', [
+            'enabled' => true,
+            'threshold_count' => 5,
+            'max_count' => 50,
+        ])
+        ->assertSessionHasErrors('consent_version');
+});
+
+test('max_count <= threshold_count は 422', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)
+        ->post('/billing/auto-recharge', [
+            'enabled' => false,
+            'threshold_count' => 50,
+            'max_count' => 50,
+        ])
+        ->assertSessionHasErrors('max_count');
+});
+
+test('max_count が config 上限を超えると 422', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)
+        ->post('/billing/auto-recharge', [
+            'enabled' => false,
+            'threshold_count' => 5,
+            'max_count' => config()->integer('billing.auto_recharge.max_count') + 1,
+        ])
+        ->assertSessionHasErrors('max_count');
+});
+
+test('保護キー (organization_id) を payload に載せると 422 (mass assignment 入口防御)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)
+        ->post('/billing/auto-recharge', [
+            'enabled' => false,
+            'threshold_count' => 5,
+            'max_count' => 50,
+            'organization_id' => 999,
+        ])
+        ->assertSessionHasErrors('organization_id');
+});
+
+test('カード登録開始で SetupPaymentMethod 台帳行が 1 行だけ作られる (二重 submit で増殖しない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $token = strtolower((string) Str::ulid());
+
+    foreach ([1, 2] as $ignored) {
+        $this->actingAs($owner)
+            ->post('/billing/auto-recharge/setup', ['attempt_token' => $token]);
+    }
+
+    $sessions = BillingCheckoutSession::query()
+        ->where('organization_id', $organization->id)
+        ->where('intent', CheckoutIntent::SetupPaymentMethod->value)
+        ->get();
+
+    expect($sessions)->toHaveCount(1)
+        ->and($sessions->firstOrFail()->attempt_token)->toBe($token)
+        ->and($sessions->firstOrFail()->idempotency_key)->toBe('auto-recharge-setup:'.$token);
+});
+
+test('カード登録着地は 303 + flash で canonical URL に倒れる (GET で副作用を起こさない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    $session = BillingCheckoutSession::factory()
+        ->for($organization)
+        ->setupPaymentMethod()
+        ->completed()
+        ->create(['stripe_session_id' => 'cs_setup_landing']);
+
+    $this->actingAs($owner)
+        ->get('/billing?setup_session_id='.$session->stripe_session_id)
+        ->assertStatus(303)
+        ->assertRedirect(route('billing.index'))
+        ->assertSessionHas('success');
+});
+
+test('他組織の setup session id を投げ込んでも成功文言は出ない (IDOR 防御)', function (): void {
+    [$organizationA, $ownerA] = createOrganizationWithOwner('組織A');
+    [$organizationB] = createOrganizationWithOwner('組織B');
+
+    BillingCheckoutSession::factory()
+        ->for($organizationB)
+        ->setupPaymentMethod()
+        ->completed()
+        ->create(['stripe_session_id' => 'cs_setup_other_org']);
+
+    $this->actingAs($ownerA)
+        ->get('/billing?setup_session_id=cs_setup_other_org')
+        ->assertStatus(303)
+        ->assertSessionMissing('success');
+});
+
+test('課金ページ props に autoRecharge が常に含まれる (既定 off)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    $response = $this->actingAs($owner)
+        ->get('/billing')
+        ->assertOk();
+
+    $props = $response->viewData('page')['props'];
+
+    expect($props)->toHaveKey('autoRecharge')
+        ->and($props['autoRecharge']['enabled'])->toBeFalse()
+        ->and($props['autoRecharge']['canManage'])->toBeTrue()
+        ->and($props)->toHaveKey('autoRechargeSetupToken');
+});
+
+test('member でも autoRecharge props は届くが canManage=false (閲覧は全員)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization, OrganizationRole::Member);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $response = $this->actingAs($member)
+        ->get('/billing')
+        ->assertOk();
+
+    expect($response->viewData('page')['props']['autoRecharge']['canManage'])->toBeFalse();
+});
+
+test('setup 台帳行があっても BillingAccess::state() は PendingCheckout にならない (P2 契約の回帰)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner(); // free plan 申告済み = ActiveFreePlan
+
+    BillingCheckoutSession::factory()
+        ->for($organization)
+        ->setupPaymentMethod()
+        ->create([
+            'stripe_session_id' => 'cs_setup_state',
+            'status' => CheckoutSessionStatus::Pending->value,
+        ]);
+
+    $state = app(BillingAccess::class)->state($organization->fresh());
+
+    expect($state)->toBe(OnboardingBillingState::ActiveFreePlan)
+        ->and($state->grantsAccess())->toBeTrue();
+
+    // ついでに課金ページが到達可能なままであること
+    $this->actingAs($owner)
+        ->get('/billing')
+        ->assertOk();
+});
diff --git a/tests/Feature/Billing/AutoRechargePreConsentTest.php b/tests/Feature/Billing/AutoRechargePreConsentTest.php
new file mode 100644
index 0000000..926700b
--- /dev/null
+++ b/tests/Feature/Billing/AutoRechargePreConsentTest.php
@@ -0,0 +1,263 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Billing\AutoRechargeConsentDto;
+use App\Enums\Billing\AutoRechargeDisabledReason;
+use App\Enums\Billing\BillingNotificationType;
+use App\Enums\CheckoutIntent;
+use App\Jobs\Billing\SetDefaultPaymentMethodJob;
+use App\Models\Billing\BillingCheckoutSession;
+use App\Models\Billing\BillingNotification;
+use App\Models\Billing\TicketAutoRecharge;
+use App\Models\Billing\TicketVolumePrice;
+use App\Services\Billing\AutoRechargeService;
+use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
+use Illuminate\Validation\ValidationException;
+use Tests\Support\FakeAutoRechargeGateway;
+
+/*
+ * P8a (D29(i)): 事前同意 → カード登録完了による自動有効化 (fail-closed)。
+ *
+ * recordPreConsent は enabled=false のまま同意証跡だけを記録し、
+ * applySetupCompletion が PM snapshot と enabled=true を同一 TX で確定する。
+ */
+
+beforeEach(function (): void {
+    $this->gateway = new FakeAutoRechargeGateway;
+    app()->instance(AutoRechargeGatewayInterface::class, $this->gateway);
+    $this->service = app(AutoRechargeService::class);
+});
+
+test('consentTermsFor は表示値と記録値の単一計算源 (サーバ再計算)', function (): void {
+    $terms = $this->service->consentTermsFor();
+    $max = config()->integer('billing.auto_recharge.default_max');
+    $tier = TicketVolumePrice::currentTierFor($max);
+
+    expect($terms->thresholdCount)->toBe(config()->integer('billing.auto_recharge.default_threshold'))
+        ->and($terms->maxCount)->toBe($max)
+        ->and($terms->unitAmountJpy)->toBe($tier->unitAmount)
+        ->and($terms->maxAmountJpy)->toBe($tier->unitAmount * $max)
+        ->and($terms->consentVersion)->toBe(config()->string('billing.auto_recharge.consent_version'));
+});
+
+test('recordPreConsent は enabled=false のまま同意 4 列を記録し pendingAutoEnable=true になる', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    $config = $this->service->recordPreConsent(
+        $organization,
+        $owner,
+        new AutoRechargeConsentDto(config()->string('billing.auto_recharge.consent_version')),
+    );
+
+    $terms = $this->service->consentTermsFor();
+    expect($config->enabled)->toBeFalse()
+        ->and($config->consented_at)->not->toBeNull()
+        ->and($config->consent_version)->toBe($terms->consentVersion)
+        ->and($config->consented_max_count)->toBe($terms->maxCount)
+        ->and($config->consented_max_amount)->toBe($terms->maxAmountJpy);
+
+    expect($this->service->isAutoEnablePending($organization))->toBeTrue();
+    expect($this->service->settingsFor($organization, canManage: true)->pendingAutoEnable)->toBeTrue();
+});
+
+test('consent_version が現行版と不一致なら 422 (画面表示と異なる条件で同意記録しない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    expect(fn () => $this->service->recordPreConsent($organization, $owner, new AutoRechargeConsentDto('v0-old')))
+        ->toThrow(ValidationException::class);
+
+    expect(TicketAutoRecharge::query()->count())->toBe(0);
+});
+
+test('カード登録完了で自動有効化され通知は 1 回だけ送られる', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $this->service->recordPreConsent($organization, $owner, new AutoRechargeConsentDto(
+        config()->string('billing.auto_recharge.consent_version'),
+    ));
+
+    expect($this->service->applySetupCompletion($organization, 'pm_new_card'))->toBeTrue();
+
+    $config = TicketAutoRecharge::query()->where('organization_id', $organization->id)->firstOrFail();
+    expect($config->enabled)->toBeTrue()
+        ->and($config->stripe_payment_method_id)->toBe('pm_new_card')
+        ->and($config->failure_count)->toBe(0);
+
+    // 再送 (同一 webhook の replay) では enabled 遷移が起きないため通知も増えない
+    expect($this->service->applySetupCompletion($organization, 'pm_new_card'))->toBeFalse();
+
+    expect(
+        BillingNotification::query()
+            ->where('organization_id', $organization->id)
+            ->where('type', BillingNotificationType::AutoRechargeEnabled->value)
+            ->count(),
+    )->toBe(1);
+});
+
+test('fail-closed 1: 稼働中設定 (enabled=true) は事前同意で上書きされない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $this->gateway->withDefaultPaymentMethod();
+    $this->service->updateSettings($organization, $owner, true, 7, 70, new AutoRechargeConsentDto(
+        config()->string('billing.auto_recharge.consent_version'),
+    ));
+
+    $config = $this->service->recordPreConsent($organization, $owner, new AutoRechargeConsentDto(
+        config()->string('billing.auto_recharge.consent_version'),
+    ));
+
+    expect($config->enabled)->toBeTrue()
+        ->and($config->threshold_count)->toBe(7)
+        ->and($config->max_count)->toBe(70);
+});
+
+test('fail-closed 2: disabled_reason を持つ行は自動有効化されない (停止の意思を尊重)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    TicketAutoRecharge::factory()->for($organization)->preConsented()->create([
+        'disabled_reason' => AutoRechargeDisabledReason::User,
+    ]);
+
+    expect($this->service->isAutoEnablePending($organization))->toBeFalse();
+    expect($this->service->applySetupCompletion($organization, 'pm_after_stop'))->toBeFalse();
+
+    $config = TicketAutoRecharge::query()->where('organization_id', $organization->id)->firstOrFail();
+    expect($config->enabled)->toBeFalse()
+        // PM snapshot は更新される (次回の手動有効化に使える) が enabled にはしない
+        ->and($config->stripe_payment_method_id)->toBe('pm_after_stop')
+        ->and($config->disabled_reason)->toBe(AutoRechargeDisabledReason::User);
+});
+
+test('fail-closed 3: PM snapshot 済みの行は pendingAutoEnable=false (有効化は請求ページに委ねる)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    TicketAutoRecharge::factory()->for($organization)->preConsented()->create([
+        'stripe_payment_method_id' => 'pm_already',
+    ]);
+
+    expect($this->service->isAutoEnablePending($organization))->toBeFalse();
+    expect($this->service->settingsFor($organization, canManage: true)->pendingAutoEnable)->toBeFalse();
+});
+
+test('事前同意なしの手動カード登録は snapshot のみ (勝手に有効化しない)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+
+    expect($this->service->applySetupCompletion($organization, 'pm_manual'))->toBeFalse();
+
+    $config = TicketAutoRecharge::query()->where('organization_id', $organization->id)->firstOrFail();
+    expect($config->enabled)->toBeFalse()
+        ->and($config->stripe_payment_method_id)->toBe('pm_manual')
+        ->and($config->consented_at)->toBeNull();
+});
+
+test('SetDefaultPaymentMethodJob は gateway で default PM を設定してから適用する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $this->service->recordPreConsent($organization, $owner, new AutoRechargeConsentDto(
+        config()->string('billing.auto_recharge.consent_version'),
+    ));
+
+    (new SetDefaultPaymentMethodJob($organization->id, 'seti_test'))
+        ->handle($this->gateway, $this->service);
+
+    expect($this->gateway->defaultPaymentMethodsSet)->toHaveCount(1);
+
+    $config = TicketAutoRecharge::query()->where('organization_id', $organization->id)->firstOrFail();
+    expect($config->enabled)->toBeTrue()
+        ->and($config->stripe_payment_method_id)->toBe($this->gateway->defaultPaymentMethodsSet[0]['paymentMethodId']);
+});
+
+test('価格改定後は事前同意が失効し自動有効化されない (再同意の 4 箇所一致)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $this->service->recordPreConsent($organization, $owner, new AutoRechargeConsentDto(
+        config()->string('billing.auto_recharge.consent_version'),
+    ));
+
+    TicketVolumePrice::query()->where('is_current', true)->update(['unit_amount' => 500]);
+
+    expect($this->service->isAutoEnablePending($organization))->toBeFalse();
+    expect($this->service->applySetupCompletion($organization, 'pm_after_price_change'))->toBeFalse();
+    expect(TicketAutoRecharge::query()->where('organization_id', $organization->id)->firstOrFail()->enabled)->toBeFalse();
+});
+
+test('activate-personal の funding_choice 省略時は dashboard 着地のまま (既存挙動が変わらない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('個人組織', grandfatherFreePlan: false);
+
+    $this->actingAs($owner)
+        ->post('/onboarding/activate-personal', ['declaration' => '1'])
+        ->assertRedirect(route('dashboard'));
+
+    expect(TicketAutoRecharge::query()->count())->toBe(0);
+});
+
+test('activate-personal + funding_choice=auto_recharge で事前同意を記録し setup Checkout へ送る', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('個人組織', grandfatherFreePlan: false);
+
+    $response = $this->actingAs($owner)->post('/onboarding/activate-personal', [
+        'declaration' => '1',
+        'funding_choice' => 'auto_recharge',
+        'consent_version' => config()->string('billing.auto_recharge.consent_version'),
+    ]);
+
+    // Inertia::location は 非 Inertia リクエストでは通常の 302 リダイレクトになる
+    // (Inertia リクエストでは 409 + X-Inertia-Location)
+    $response->assertRedirect($this->gateway->setupUrl);
+
+    $config = TicketAutoRecharge::query()->where('organization_id', $organization->id)->firstOrFail();
+    expect($config->enabled)->toBeFalse()
+        ->and($config->consented_at)->not->toBeNull()
+        ->and($config->consented_max_amount)->toBe($this->service->consentTermsFor()->maxAmountJpy);
+});
+
+test('activate-personal の consent_version 欠落 / 現行版不一致は 422 (activate 前に fail-closed)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('個人組織', grandfatherFreePlan: false);
+
+    $this->actingAs($owner)
+        ->post('/onboarding/activate-personal', ['declaration' => '1', 'funding_choice' => 'auto_recharge'])
+        ->assertSessionHasErrors('consent_version');
+
+    $this->actingAs($owner)
+        ->post('/onboarding/activate-personal', [
+            'declaration' => '1',
+            'funding_choice' => 'auto_recharge',
+            'consent_version' => 'v0-old',
+        ])
+        ->assertSessionHasErrors('consent_version');
+
+    // activate 自体が起きていない (free 有効化も同意記録も無い)
+    expect(TicketAutoRecharge::query()->count())->toBe(0);
+    expect($organization->fresh()->free_plan_code)->toBeNull();
+});
+
+test('二重 submit でも SetupPaymentMethod 台帳が増殖しない (session 保持 token)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('個人組織', grandfatherFreePlan: false);
+    $payload = [
+        'declaration' => '1',
+        'funding_choice' => 'auto_recharge',
+        'consent_version' => config()->string('billing.auto_recharge.consent_version'),
+    ];
+
+    $this->actingAs($owner)->post('/onboarding/activate-personal', $payload);
+    $this->actingAs($owner)->post('/onboarding/activate-personal', $payload);
+
+    expect(
+        BillingCheckoutSession::query()
+            ->where('organization_id', $organization->id)
+            ->where('intent', CheckoutIntent::SetupPaymentMethod->value)
+            ->count(),
+    )->toBe(1);
+});
+
+test('funding_choice=tickets は購入ページへ直行する (UI 非提示だが永続値互換で受理)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('個人組織', grandfatherFreePlan: false);
+
+    $this->actingAs($owner)
+        ->post('/onboarding/activate-personal', ['declaration' => '1', 'funding_choice' => 'tickets'])
+        ->assertRedirect(route('billing.tickets.show'));
+});
+
+test('onboarding checkout の props に consentTerms / fundingChoices が届く (tickets は出さない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('個人組織', grandfatherFreePlan: false);
+
+    $response = $this->actingAs($owner)->get('/onboarding/checkout')->assertOk();
+    $pageData = $response->viewData('page')['props']['pageData'];
+
+    expect($pageData['fundingChoices'])->toBe(['auto_recharge', 'later'])
+        ->and($pageData['consentTerms'])->toBe($this->service->consentTermsFor()->toArray());
+});
diff --git a/tests/Feature/Billing/AutoRechargeReconcileTest.php b/tests/Feature/Billing/AutoRechargeReconcileTest.php
new file mode 100644
index 0000000..a2fecfd
--- /dev/null
+++ b/tests/Feature/Billing/AutoRechargeReconcileTest.php
@@ -0,0 +1,199 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Billing\AutoRechargeConsentDto;
+use App\DataTransferObjects\Billing\InvoiceStateDto;
+use App\Enums\Billing\AutoRechargeAttemptStatus;
+use App\Enums\Billing\BillingNotificationType;
+use App\Models\Billing\BillingNotification;
+use App\Models\Billing\TicketAutoRechargeAttempt;
+use App\Models\Billing\TicketLedgerEntry;
+use App\Models\Organization;
+use App\Services\Billing\AutoRechargeService;
+use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
+use Carbon\CarbonImmutable;
+use Illuminate\Console\Scheduling\Event;
+use Illuminate\Console\Scheduling\Schedule;
+use Illuminate\Support\Facades\Queue;
+use Tests\Support\FakeAutoRechargeGateway;
+
+/*
+ * P8a: リコンサイル (5 分岐) + D20 の監視 DoD。
+ *
+ * webhook が terminal-ack で恒久 drop した「課金済み・付与なし」の唯一のセーフティネット。
+ */
+
+beforeEach(function (): void {
+    $this->gateway = new FakeAutoRechargeGateway;
+    app()->instance(AutoRechargeGatewayInterface::class, $this->gateway);
+});
+
+test('(i) invoice 未作成の pending は 15 分超で再実行される', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $attempt = TicketAutoRechargeAttempt::factory()->for($organization)->create([
+        'created_at' => CarbonImmutable::now()->subMinutes(20),
+    ]);
+    // 停止後課金の禁止により enabled が必要
+    enableAutoRecharge($organization);
+    $this->gateway->payAmountPaid = $attempt->quantity * $attempt->unit_amount;
+
+    $stats = app(AutoRechargeService::class)->reconcile();
+
+    expect($stats['retried'])->toBe(1);
+    expect($attempt->fresh()->status)->toBe(AutoRechargeAttemptStatus::Paid);
+});
+
+test('(i) 15 分未満の pending は再実行しない', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    TicketAutoRechargeAttempt::factory()->for($organization)->create([
+        'created_at' => CarbonImmutable::now()->subMinutes(3),
+    ]);
+
+    $stats = app(AutoRechargeService::class)->reconcile();
+
+    expect($stats['retried'])->toBe(0);
+});
+
+test('(ii) Stripe 上 paid だが webhook 未着なら付与を回収する (terminal drop の唯一の救済)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $attempt = TicketAutoRechargeAttempt::factory()
+        ->for($organization)
+        ->withInvoice('in_recovered')
+        ->create(['quantity' => 40, 'unit_amount' => 70]);
+
+    $this->gateway->invoiceState = new InvoiceStateDto('paid', 40 * 70, 40 * 70, 'pi_recovered', false, null);
+
+    $stats = app(AutoRechargeService::class)->reconcile();
+
+    expect($stats['recovered_paid'])->toBe(1);
+    expect($attempt->fresh()->status)->toBe(AutoRechargeAttemptStatus::Paid);
+    expect(TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_recovered')->count())->toBe(1);
+});
+
+test('(iii) SCA 待ちは日次リマインダを送り、同日 2 回目は dedup で送られない', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    TicketAutoRechargeAttempt::factory()
+        ->for($organization)
+        ->withInvoice('in_sca_pending')
+        ->create(['failure_code' => 'authentication_required']);
+
+    $this->gateway->invoiceState = new InvoiceStateDto('open', 0, 2800, 'pi_sca', true, 'https://invoice.stripe.test/i/in_sca_pending');
+
+    $first = app(AutoRechargeService::class)->reconcile();
+    expect($first['sca_reminded'])->toBe(1);
+
+    $second = app(AutoRechargeService::class)->reconcile();
+    expect($second['sca_reminded'])->toBe(1); // 呼び出しはされるが…
+
+    // …通知台帳は同日 1 件のまま (dedup key = JST date bucket)
+    expect(
+        BillingNotification::query()
+            ->where('organization_id', $organization->id)
+            ->where('type', BillingNotificationType::AutoRechargeActionRequired->value)
+            ->count(),
+    )->toBe(1);
+});
+
+test('(iv) 期限切れ — SCA は failed、それ以外は canceled になる', function (): void {
+    [$organizationA] = createOrganizationWithOwner('組織A');
+    [$organizationB] = createOrganizationWithOwner('組織B');
+    $expiryHours = config()->integer('billing.auto_recharge.pending_expiry_hours');
+
+    $scaAttempt = TicketAutoRechargeAttempt::factory()
+        ->for($organizationA)
+        ->withInvoice('in_expired_sca')
+        ->create([
+            'failure_code' => 'authentication_required',
+            'created_at' => CarbonImmutable::now()->subHours($expiryHours + 1),
+        ]);
+    $draftAttempt = TicketAutoRechargeAttempt::factory()
+        ->for($organizationB)
+        ->withInvoice('in_expired_draft')
+        ->create(['created_at' => CarbonImmutable::now()->subHours($expiryHours + 1)]);
+
+    $this->gateway->invoiceStatuses = ['in_expired_sca' => 'open', 'in_expired_draft' => 'draft'];
+
+    $stats = app(AutoRechargeService::class)->reconcile();
+
+    expect($stats['expired'])->toBe(2);
+    expect($scaAttempt->fresh()->status)->toBe(AutoRechargeAttemptStatus::Failed);
+    expect($draftAttempt->fresh()->status)->toBe(AutoRechargeAttemptStatus::Canceled);
+});
+
+test('(v) enabled + 閾値割れ + pending なしで取りこぼしを起票する', function (): void {
+    Queue::fake();
+    [$organization] = createOrganizationWithOwner();
+    enableAutoRecharge($organization);
+
+    $stats = app(AutoRechargeService::class)->reconcile();
+
+    expect($stats['triggered'])->toBe(1);
+    expect(TicketAutoRechargeAttempt::query()->where('organization_id', $organization->id)->count())->toBe(1);
+});
+
+test('既定 off の組織はリコンサイルでも一切起票されない', function (): void {
+    [$organization] = createOrganizationWithOwner();
+
+    $stats = app(AutoRechargeService::class)->reconcile();
+
+    expect($stats['triggered'])->toBe(0);
+    expect(TicketAutoRechargeAttempt::query()->count())->toBe(0);
+});
+
+test('1 attempt の例外が他 org の回収を止めない (隔離)', function (): void {
+    [$organizationA] = createOrganizationWithOwner('組織A');
+    [$organizationB] = createOrganizationWithOwner('組織B');
+
+    // A: amount 不一致で例外になる paid invoice
+    TicketAutoRechargeAttempt::factory()->for($organizationA)->withInvoice('in_bad')->create([
+        'quantity' => 10, 'unit_amount' => 100,
+    ]);
+    // B: 正常に回収できる paid invoice
+    $good = TicketAutoRechargeAttempt::factory()->for($organizationB)->withInvoice('in_good')->create([
+        'quantity' => 40, 'unit_amount' => 70,
+    ]);
+
+    // amount 不一致 (in_bad) は例外になり、正常な in_good は回収される
+    $this->gateway->invoiceStates = [
+        'in_bad' => new InvoiceStateDto('paid', 1, 1, 'pi_bad', false, null),
+        'in_good' => new InvoiceStateDto('paid', 40 * 70, 40 * 70, 'pi_good', false, null),
+    ];
+
+    $stats = app(AutoRechargeService::class)->reconcile();
+
+    expect($stats['recovered_paid'])->toBe(1);
+    expect($good->fresh()->status)->toBe(AutoRechargeAttemptStatus::Paid);
+});
+
+test('リコンサイルコマンドは 0 で終了し統計を出力する', function (): void {
+    $this->artisan('billing:reconcile-auto-recharge')
+        ->expectsOutputToContain('auto-recharge reconcile:')
+        ->assertExitCode(0);
+});
+
+test('D20: scheduler に 15 分毎で登録されている (監視 DoD の回帰)', function (): void {
+    $events = collect(app(Schedule::class)->events())
+        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'billing:reconcile-auto-recharge'));
+
+    expect($events)->toHaveCount(1);
+    expect($events->firstOrFail()->getExpression())->toBe('*/15 * * * *');
+});
+
+/** テスト用にオートリチャージを直接有効化する (PM 注入 + 同意記録)。 */
+function enableAutoRecharge(Organization $organization): void
+{
+    $owner = $organization->users()->firstOrFail();
+    /** @var FakeAutoRechargeGateway $gateway */
+    $gateway = app(AutoRechargeGatewayInterface::class);
+    $gateway->withDefaultPaymentMethod();
+
+    app(AutoRechargeService::class)->updateSettings(
+        $organization,
+        $owner,
+        enabled: true,
+        threshold: 5,
+        max: 50,
+        consent: new AutoRechargeConsentDto(config()->string('billing.auto_recharge.consent_version')),
+    );
+}
diff --git a/tests/Feature/Billing/AutoRechargeServiceTest.php b/tests/Feature/Billing/AutoRechargeServiceTest.php
new file mode 100644
index 0000000..f252d25
--- /dev/null
+++ b/tests/Feature/Billing/AutoRechargeServiceTest.php
@@ -0,0 +1,425 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Billing\AutoRechargeConsentDto;
+use App\DataTransferObjects\Billing\OffSessionChargeResultDto;
+use App\Enums\Billing\AutoRechargeAttemptStatus;
+use App\Enums\Billing\AutoRechargeDisabledReason;
+use App\Enums\Billing\BillingNotificationType;
+use App\Models\Billing\BillingNotification;
+use App\Models\Billing\TicketAutoRecharge;
+use App\Models\Billing\TicketAutoRechargeAttempt;
+use App\Models\Billing\TicketLedgerEntry;
+use App\Models\Billing\TicketVolumePrice;
+use App\Models\Organization;
+use App\Models\User;
+use App\Services\Billing\AutoRechargeService;
+use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
+use App\Services\Billing\TicketLedgerService;
+use Illuminate\Validation\ValidationException;
+use Tests\Support\FakeAutoRechargeGateway;
+
+/*
+ * P8a: オートリチャージ中核サービス。**opt-in・既定 off** が最上位の回帰点。
+ */
+
+/** @return array{Organization, User, FakeAutoRechargeGateway, AutoRechargeService} */
+function autoRechargeSetup(): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    $gateway = new FakeAutoRechargeGateway;
+    app()->instance(AutoRechargeGatewayInterface::class, $gateway);
+
+    return [$organization, $owner, $gateway, app(AutoRechargeService::class)];
+}
+
+/** 指定種別の請求通知が台帳に記録されたか。 */
+function billingNotificationExists(Organization $organization, BillingNotificationType $type): bool
+{
+    return BillingNotification::query()
+        ->where('organization_id', $organization->getKey())
+        ->where('type', $type->value)
+        ->exists();
+}
+
+/** 与信残高を作る (無期限の purchased 付与)。 */
+function grantTickets(Organization $organization, int $amount): void
+{
+    app(TicketLedgerService::class)->grant($organization, $amount, 'テスト付与');
+}
+
+test('既定は off — 設定行が無い組織では isEnabledFor / settingsFor.enabled が false で attempt も起票されない', function (): void {
+    [$organization, , , $service] = autoRechargeSetup();
+
+    expect($service->isEnabledFor($organization))->toBeFalse();
+
+    $settings = $service->settingsFor($organization, canManage: true);
+    expect($settings->enabled)->toBeFalse()
+        ->and($settings->hasPaymentMethod)->toBeFalse()
+        ->and($settings->pendingAutoEnable)->toBeFalse()
+        ->and($settings->requiresReconsent)->toBeFalse()
+        // 既定値は config 由来 (設定行が無くてもフォーム初期値が出る)
+        ->and($settings->thresholdCount)->toBe(config()->integer('billing.auto_recharge.default_threshold'))
+        ->and($settings->maxCount)->toBe(config()->integer('billing.auto_recharge.default_max'));
+
+    // 残高 0 (= 閾値割れ) でも設定行が無ければ起票しない
+    expect($service->maybeCreateAttempt($organization))->toBeNull();
+    expect(TicketAutoRechargeAttempt::query()->count())->toBe(0);
+});
+
+test('有効化は fail-closed — default PM が無ければ ValidationException', function (): void {
+    [$organization, $owner, , $service] = autoRechargeSetup();
+
+    expect(fn () => $service->updateSettings(
+        $organization,
+        $owner,
+        enabled: true,
+        threshold: 5,
+        max: 50,
+        consent: new AutoRechargeConsentDto(config()->string('billing.auto_recharge.consent_version')),
+    ))->toThrow(ValidationException::class);
+
+    expect(TicketAutoRecharge::query()->count())->toBe(0);
+});
+
+test('有効化は fail-closed — 同意 version 不一致は ValidationException', function (): void {
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+    $gateway->withDefaultPaymentMethod();
+
+    expect(fn () => $service->updateSettings(
+        $organization,
+        $owner,
+        enabled: true,
+        threshold: 5,
+        max: 50,
+        consent: new AutoRechargeConsentDto('v0-obsolete'),
+    ))->toThrow(ValidationException::class);
+});
+
+test('同意金額はサーバ再計算される (client の申告値を信用しない)', function (): void {
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+    $gateway->withDefaultPaymentMethod();
+
+    $config = $service->updateSettings(
+        $organization,
+        $owner,
+        enabled: true,
+        threshold: 5,
+        max: 50,
+        consent: new AutoRechargeConsentDto(config()->string('billing.auto_recharge.consent_version')),
+    );
+
+    $expected = TicketVolumePrice::currentTierFor(50)->unitAmount * 50;
+    expect($config->enabled)->toBeTrue()
+        ->and($config->consented_max_count)->toBe(50)
+        ->and($config->consented_max_amount)->toBe($expected)
+        ->and($config->stripe_payment_method_id)->toBe('pm_test_default');
+});
+
+test('再同意は 4 箇所で同一判定 — 価格改定で表示・起票・自動有効化が同時に止まる', function (): void {
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+    $gateway->withDefaultPaymentMethod();
+
+    $service->updateSettings(
+        $organization,
+        $owner,
+        enabled: true,
+        threshold: 5,
+        max: 50,
+        consent: new AutoRechargeConsentDto(config()->string('billing.auto_recharge.consent_version')),
+    );
+
+    // 価格改定 (単価を引き上げ) → 現行最大請求額が同意額を超過する
+    TicketVolumePrice::query()->where('is_current', true)->update(['unit_amount' => 200]);
+
+    // (1) UI 表示
+    expect($service->settingsFor($organization, canManage: true)->requiresReconsent)->toBeTrue();
+
+    // (2) attempt 起票停止 (残高 0 = 閾値割れでも起票しない)
+    expect($service->maybeCreateAttempt($organization))->toBeNull();
+    expect(TicketAutoRechargeAttempt::query()->count())->toBe(0);
+
+    // (3) 自動有効化の適格性 (停止 → 事前同意待ち相当に落として検査)
+    TicketAutoRecharge::query()->where('organization_id', $organization->id)->update([
+        'enabled' => false,
+        'disabled_reason' => null,
+        'stripe_payment_method_id' => null,
+    ]);
+    expect($service->isAutoEnablePending($organization))->toBeFalse();
+});
+
+test('quantity は attempt 作成時に一度だけ確定する (以降の残高変動で変わらない)', function (): void {
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+    $gateway->withDefaultPaymentMethod();
+    $service->updateSettings($organization, $owner, true, 5, 50, new AutoRechargeConsentDto(
+        config()->string('billing.auto_recharge.consent_version'),
+    ));
+
+    grantTickets($organization, 2); // 残高 2 (< 閾値 5)
+
+    $attempt = $service->maybeCreateAttempt($organization);
+    expect($attempt)->not->toBeNull();
+    expect($attempt->quantity)->toBe(48); // max 50 − 真値残高 2
+
+    // 起票後に残高が増えても attempt.quantity は不変
+    grantTickets($organization, 30);
+    $attempt->refresh();
+    expect($attempt->quantity)->toBe(48);
+});
+
+test('閾値判定は availableTrueBalance を使う — 返金債務で clamp された表示残高では判定しない', function (): void {
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+    $gateway->withDefaultPaymentMethod();
+    $service->updateSettings($organization, $owner, true, 5, 50, new AutoRechargeConsentDto(
+        config()->string('billing.auto_recharge.consent_version'),
+    ));
+
+    // monthly 10 枚 (閾値 5 以上) → 真値残高 10 なので起票しない
+    app(TicketLedgerService::class)->grantMonthly($organization, 10, null, 'monthly:test', '月次');
+    expect($service->maybeCreateAttempt($organization))->toBeNull();
+});
+
+test('org に pending attempt があるうちは新しい attempt を起票しない', function (): void {
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+    $gateway->withDefaultPaymentMethod();
+    $service->updateSettings($organization, $owner, true, 5, 50, new AutoRechargeConsentDto(
+        config()->string('billing.auto_recharge.consent_version'),
+    ));
+
+    expect($service->maybeCreateAttempt($organization))->not->toBeNull();
+    expect($service->maybeCreateAttempt($organization))->toBeNull();
+    expect(TicketAutoRechargeAttempt::query()->count())->toBe(1);
+});
+
+test('停止後課金の禁止 — 停止で pending attempt が invoice 終端 + canceled になり以降 execute は no-op', function (): void {
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+    $gateway->withDefaultPaymentMethod();
+    $service->updateSettings($organization, $owner, true, 5, 50, new AutoRechargeConsentDto(
+        config()->string('billing.auto_recharge.consent_version'),
+    ));
+
+    $attempt = $service->maybeCreateAttempt($organization);
+    expect($attempt)->not->toBeNull();
+    // invoice を作らせておく (終端対象を実在させる)
+    $attempt->forceFill(['stripe_invoice_id' => 'in_test_pending'])->save();
+    $gateway->invoiceStatuses['in_test_pending'] = 'open';
+
+    $service->updateSettings($organization, $owner, false, 5, 50, null);
+
+    $attempt->refresh();
+    expect($attempt->status)->toBe(AutoRechargeAttemptStatus::Canceled)
+        ->and($gateway->terminated)->toContain('in_test_pending');
+
+    // 停止後の execute は課金しない
+    $payCallsBefore = count($gateway->payCalls);
+    $service->executeAttempt($attempt);
+    expect($gateway->payCalls)->toHaveCount($payCallsBefore);
+});
+
+test('連続失敗で自動無効化される (max_failures 到達で disabled_reason=payment_failures + 通知)', function (): void {
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+    $gateway->withDefaultPaymentMethod();
+    $service->updateSettings($organization, $owner, true, 5, 50, new AutoRechargeConsentDto(
+        config()->string('billing.auto_recharge.consent_version'),
+    ));
+
+    $maxFailures = config()->integer('billing.auto_recharge.max_failures');
+
+    for ($i = 0; $i < $maxFailures; $i++) {
+        $attempt = $service->maybeCreateAttempt($organization);
+        expect($attempt)->not->toBeNull();
+        $invoiceId = "in_fail_{$i}";
+        $attempt->forceFill(['stripe_invoice_id' => $invoiceId])->save();
+        $gateway->invoiceStatuses[$invoiceId] = 'open';
+
+        $service->handleChargeFailure($organization, $attempt, 'card_declined', requiresAction: false);
+
+        $attempt->refresh();
+        expect($attempt->status)->toBe(AutoRechargeAttemptStatus::Failed);
+
+        if ($i < $maxFailures - 1) {
+            // 自動停止前は再有効化せずとも次の attempt が起票できる
+            TicketAutoRecharge::query()->where('organization_id', $organization->id)->update(['enabled' => true]);
+        }
+    }
+
+    $config = TicketAutoRecharge::query()->where('organization_id', $organization->id)->firstOrFail();
+    expect($config->enabled)->toBeFalse()
+        ->and($config->failure_count)->toBe($maxFailures)
+        ->and($config->disabled_reason)->toBe(AutoRechargeDisabledReason::PaymentFailures);
+
+    expect(
+        billingNotificationExists($organization, BillingNotificationType::AutoRechargeDisabled),
+    )->toBeTrue();
+});
+
+test('SCA は終端させない — pending 維持 + failure_count 据え置き + 認証要求通知', function (): void {
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+    $gateway->withDefaultPaymentMethod();
+    $service->updateSettings($organization, $owner, true, 5, 50, new AutoRechargeConsentDto(
+        config()->string('billing.auto_recharge.consent_version'),
+    ));
+
+    $attempt = $service->maybeCreateAttempt($organization);
+    $attempt->forceFill(['stripe_invoice_id' => 'in_sca'])->save();
+    $gateway->invoiceStatuses['in_sca'] = 'open';
+
+    $service->handleChargeFailure($organization, $attempt, 'authentication_required', requiresAction: true);
+
+    $attempt->refresh();
+    expect($attempt->status)->toBe(AutoRechargeAttemptStatus::Pending)
+        ->and($attempt->failure_code)->toBe('authentication_required')
+        ->and($gateway->terminated)->toBe([]);
+
+    $config = TicketAutoRecharge::query()->where('organization_id', $organization->id)->firstOrFail();
+    expect($config->failure_count)->toBe(0);
+
+    expect(
+        billingNotificationExists($organization, BillingNotificationType::AutoRechargeActionRequired),
+    )->toBeTrue();
+});
+
+test('invoice 終端に失敗したら pending を維持する (終端保証を破らない)', function (): void {
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+    $gateway->withDefaultPaymentMethod();
+    $service->updateSettings($organization, $owner, true, 5, 50, new AutoRechargeConsentDto(
+        config()->string('billing.auto_recharge.consent_version'),
+    ));
+
+    $attempt = $service->maybeCreateAttempt($organization);
+    $attempt->forceFill(['stripe_invoice_id' => 'in_stuck'])->save();
+    $gateway->failOnTerminate = true;
+
+    $service->terminateAndFail($organization, $attempt);
+
+    $attempt->refresh();
+    expect($attempt->status)->toBe(AutoRechargeAttemptStatus::Pending);
+});
+
+test('execute は invoice_id を pay より先に永続化し、成功でチケットを冪等付与する', function (): void {
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+    $gateway->withDefaultPaymentMethod();
+    $service->updateSettings($organization, $owner, true, 5, 50, new AutoRechargeConsentDto(
+        config()->string('billing.auto_recharge.consent_version'),
+    ));
+
+    $attempt = $service->maybeCreateAttempt($organization);
+    $expectedAmount = $attempt->unit_amount * $attempt->quantity;
+    $gateway->payAmountPaid = $expectedAmount;
+
+    $service->executeAttempt($attempt);
+
+    $attempt->refresh();
+    expect($attempt->status)->toBe(AutoRechargeAttemptStatus::Paid)
+        ->and($attempt->stripe_invoice_id)->not->toBeNull()
+        // invoice 作成 → 保存 → pay の順序 (同一 invoice に対して pay が呼ばれている)
+        ->and($gateway->payCalls[0]['invoiceId'])->toBe($attempt->stripe_invoice_id);
+
+    $entry = TicketLedgerEntry::query()
+        ->where('idempotency_key', "recharge:{$attempt->stripe_invoice_id}")
+        ->firstOrFail();
+    expect($entry->delta)->toBe($attempt->quantity)
+        ->and($entry->purchase_amount)->toBe($expectedAmount)
+        ->and($entry->payment_intent_id)->toBe('pi_test_autorecharge');
+});
+
+test('amount cross-check は fail-closed — amount_due 不一致で例外・付与なし', function (): void {
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+    $gateway->withDefaultPaymentMethod();
+    $service->updateSettings($organization, $owner, true, 5, 50, new AutoRechargeConsentDto(
+        config()->string('billing.auto_recharge.consent_version'),
+    ));
+
+    $attempt = $service->maybeCreateAttempt($organization);
+
+    expect(fn () => $service->recordSuccessfulCharge(
+        $organization,
+        $attempt,
+        'in_mismatch',
+        amountPaid: 1,
+        amountDue: 1, // pin した unit × quantity と一致しない
+        paymentIntentId: null,
+    ))->toThrow(RuntimeException::class);
+
+    expect(TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_mismatch')->exists())->toBeFalse();
+});
+
+test('credit balance 適用 (amount_paid < amount_due) は正当 — 付与は成立し purchase_amount は実回収額', function (): void {
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+    $gateway->withDefaultPaymentMethod();
+    $service->updateSettings($organization, $owner, true, 5, 50, new AutoRechargeConsentDto(
+        config()->string('billing.auto_recharge.consent_version'),
+    ));
+
+    $attempt = $service->maybeCreateAttempt($organization);
+    $due = $attempt->unit_amount * $attempt->quantity;
+
+    $service->recordSuccessfulCharge($organization, $attempt, 'in_credit', amountPaid: 0, amountDue: $due, paymentIntentId: null);
+
+    $entry = TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_credit')->firstOrFail();
+    expect($entry->purchase_amount)->toBe(0)
+        ->and($entry->delta)->toBe($attempt->quantity);
+});
+
+test('同一 invoice の付与は 1 回だけ (webhook 再送・リコンサイル重複でも二重付与しない)', function (): void {
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+    $gateway->withDefaultPaymentMethod();
+    $service->updateSettings($organization, $owner, true, 5, 50, new AutoRechargeConsentDto(
+        config()->string('billing.auto_recharge.consent_version'),
+    ));
+
+    $attempt = $service->maybeCreateAttempt($organization);
+    $due = $attempt->unit_amount * $attempt->quantity;
+
+    $service->recordSuccessfulCharge($organization, $attempt, 'in_dup', $due, $due, 'pi_1');
+    $service->recordSuccessfulCharge($organization, $attempt->fresh(), 'in_dup', $due, $due, 'pi_1');
+
+    expect(TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_dup')->count())->toBe(1);
+});
+
+test('payment_intent_id は null → 値の単調 backfill のみ行う (値の上書きはしない)', function (): void {
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+    $gateway->withDefaultPaymentMethod();
+    $service->updateSettings($organization, $owner, true, 5, 50, new AutoRechargeConsentDto(
+        config()->string('billing.auto_recharge.consent_version'),
+    ));
+
+    $attempt = $service->maybeCreateAttempt($organization);
+    $due = $attempt->unit_amount * $attempt->quantity;
+    $ledger = app(TicketLedgerService::class);
+
+    // 1 回目: PI 欠落
+    $ledger->grantAutoRecharge($organization, $attempt->quantity, 'in_backfill', $due, null);
+    expect(TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_backfill')->firstOrFail()->payment_intent_id)->toBeNull();
+
+    // 2 回目: PI つきの再送 → backfill される
+    $ledger->grantAutoRecharge($organization, $attempt->quantity, 'in_backfill', $due, 'pi_late');
+    expect(TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_backfill')->firstOrFail()->payment_intent_id)->toBe('pi_late');
+
+    // 3 回目: 別 PI での上書きは起きない (改竄防止)
+    $ledger->grantAutoRecharge($organization, $attempt->quantity, 'in_backfill', $due, 'pi_other');
+    expect(TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_backfill')->firstOrFail()->payment_intent_id)->toBe('pi_late');
+
+    // 付与行は 1 行のまま
+    expect(TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_backfill')->count())->toBe(1);
+});
+
+test('card_declined の同期 pay 失敗は invoice を終端して failed にする', function (): void {
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+    $gateway->withDefaultPaymentMethod();
+    $service->updateSettings($organization, $owner, true, 5, 50, new AutoRechargeConsentDto(
+        config()->string('billing.auto_recharge.consent_version'),
+    ));
+
+    $attempt = $service->maybeCreateAttempt($organization);
+    $gateway->payResult = OffSessionChargeResultDto::failed('placeholder', 'card_declined', 'generic_decline');
+
+    $service->executeAttempt($attempt);
+
+    $attempt->refresh();
+    expect($attempt->status)->toBe(AutoRechargeAttemptStatus::Failed)
+        ->and($attempt->failure_code)->toBe('card_declined')
+        ->and($gateway->terminated)->toHaveCount(1);
+});
diff --git a/tests/Feature/Billing/AutoRechargeTriggerTest.php b/tests/Feature/Billing/AutoRechargeTriggerTest.php
new file mode 100644
index 0000000..117e8b6
--- /dev/null
+++ b/tests/Feature/Billing/AutoRechargeTriggerTest.php
@@ -0,0 +1,161 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Billing\AutoRechargeConsentDto;
+use App\Jobs\Billing\AutoRechargeTriggerJob;
+use App\Jobs\Billing\ExecuteAutoRechargeAttemptJob;
+use App\Models\Billing\TicketAutoRecharge;
+use App\Models\Billing\TicketAutoRechargeAttempt;
+use App\Models\Organization;
+use App\Models\User;
+use App\Services\Billing\AutoRechargeService;
+use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
+use App\Services\Billing\TicketLedgerService;
+use App\Services\Notification\NotificationCenterService;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Queue;
+use Tests\Support\FakeAutoRechargeGateway;
+
+/*
+ * P8a の AI-CUE 固有の要: トリガ点は commit ではなく **reserve**。
+ *
+ * AI-CUE の balance() は reserve で減り commit では不変 (拘束 −amount と台帳 −amount が相殺) の
+ * ため、閾値クロスを取り逃さない唯一の点が reserve。既存の低残高通知と同居させる
+ * (parity の名で既存機能を後退させない)。
+ */
+
+beforeEach(function (): void {
+    $this->gateway = new FakeAutoRechargeGateway;
+    app()->instance(AutoRechargeGatewayInterface::class, $this->gateway);
+});
+
+test('reserve で AutoRechargeTriggerJob が dispatch される', function (): void {
+    Queue::fake();
+    [$organization] = createOrganizationWithOwner();
+    app(TicketLedgerService::class)->grant($organization, 10, '初期付与');
+
+    app(TicketLedgerService::class)->reserve($organization, 1);
+
+    Queue::assertPushed(
+        AutoRechargeTriggerJob::class,
+        fn (AutoRechargeTriggerJob $job): bool => $job->organizationId === $organization->id,
+    );
+});
+
+test('既存の低残高通知が消えていない (parity の名での機能後退を防ぐ回帰)', function (): void {
+    Queue::fake();
+    [$organization] = createOrganizationWithOwner();
+
+    $notifications = Mockery::mock(NotificationCenterService::class);
+    $notifications->shouldReceive('notifyTicketBalanceLow')->once();
+    app()->instance(NotificationCenterService::class, $notifications);
+
+    $threshold = config()->integer('billing.ticket_low_balance_threshold');
+    app(TicketLedgerService::class)->grant($organization, $threshold, '閾値ちょうど');
+
+    // 閾値以上 → 閾値未満 のクロス
+    app(TicketLedgerService::class)->reserve($organization, 1);
+
+    // 低残高通知とオートリチャージ trigger が同居している
+    Queue::assertPushed(AutoRechargeTriggerJob::class);
+});
+
+test('commit では dispatch されない (balance 不変のため)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    app(TicketLedgerService::class)->grant($organization, 10, '初期付与');
+    $reservation = app(TicketLedgerService::class)->reserve($organization, 1);
+
+    // reserve 後に fake すると commit 由来の dispatch のみを観測できる
+    Queue::fake();
+    app(TicketLedgerService::class)->commit($reservation);
+
+    Queue::assertNotPushed(AutoRechargeTriggerJob::class);
+});
+
+test('reserve が rollback したら dispatch されない (afterCommit の保証)', function (): void {
+    Queue::fake();
+    [$organization] = createOrganizationWithOwner();
+    app(TicketLedgerService::class)->grant($organization, 10, '初期付与');
+
+    try {
+        DB::transaction(function () use ($organization): void {
+            app(TicketLedgerService::class)->reserve($organization, 1);
+
+            throw new RuntimeException('意図的な rollback');
+        });
+    } catch (RuntimeException) {
+        // 期待どおり
+    }
+
+    Queue::assertNotPushed(AutoRechargeTriggerJob::class);
+});
+
+test('amount ベース reserve (可変コスト) が壊れていない', function (): void {
+    Queue::fake();
+    [$organization] = createOrganizationWithOwner();
+    app(TicketLedgerService::class)->grant($organization, 10, '初期付与');
+
+    $reservation = app(TicketLedgerService::class)->reserve($organization, 7);
+
+    expect($reservation->amount)->toBe(7);
+    expect(app(TicketLedgerService::class)->availableTrueBalance($organization))->toBe(3);
+});
+
+test('reserve→commit/release の 2 フェーズが維持されている', function (): void {
+    Queue::fake();
+    [$organization] = createOrganizationWithOwner();
+    $ledger = app(TicketLedgerService::class);
+    $ledger->grant($organization, 10, '初期付与');
+
+    $committed = $ledger->reserve($organization, 2);
+    $ledger->commit($committed);
+    expect($ledger->availableTrueBalance($organization))->toBe(8);
+
+    $released = $ledger->reserve($organization, 3);
+    $ledger->release($released);
+    expect($ledger->availableTrueBalance($organization))->toBe(8);
+});
+
+test('設定行が無い組織では TriggerJob が即 return する (既定 off の回帰)', function (): void {
+    Queue::fake();
+    [$organization] = createOrganizationWithOwner();
+
+    (new AutoRechargeTriggerJob($organization->id))->handle(app(AutoRechargeService::class));
+
+    expect(TicketAutoRechargeAttempt::query()->count())->toBe(0);
+    Queue::assertNotPushed(ExecuteAutoRechargeAttemptJob::class);
+});
+
+test('enabled な組織では TriggerJob が attempt を起票し ExecuteJob を dispatch する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    enableAutoRechargeFor($organization, $owner, $this->gateway);
+
+    Queue::fake();
+    (new AutoRechargeTriggerJob($organization->id))->handle(app(AutoRechargeService::class));
+
+    expect(TicketAutoRechargeAttempt::query()->count())->toBe(1);
+    Queue::assertPushed(ExecuteAutoRechargeAttemptJob::class);
+});
+
+/** テスト用にオートリチャージを有効化する (default PM を注入してから updateSettings)。 */
+function enableAutoRechargeFor(
+    Organization $organization,
+    User $owner,
+    FakeAutoRechargeGateway $gateway,
+    int $threshold = 5,
+    int $max = 50,
+): TicketAutoRecharge {
+    $gateway->withDefaultPaymentMethod();
+
+    return app(AutoRechargeService::class)->updateSettings(
+        $organization,
+        $owner,
+        enabled: true,
+        threshold: $threshold,
+        max: $max,
+        consent: new AutoRechargeConsentDto(
+            config()->string('billing.auto_recharge.consent_version'),
+        ),
+    );
+}
diff --git a/tests/Feature/Billing/AutoRechargeWebhookTest.php b/tests/Feature/Billing/AutoRechargeWebhookTest.php
new file mode 100644
index 0000000..5a1fd54
--- /dev/null
+++ b/tests/Feature/Billing/AutoRechargeWebhookTest.php
@@ -0,0 +1,260 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\AutoRechargeAttemptStatus;
+use App\Enums\CheckoutSessionStatus;
+use App\Jobs\Billing\HandleAutoRechargeChargeFailureJob;
+use App\Jobs\Billing\SetDefaultPaymentMethodJob;
+use App\Models\Billing\BillingCheckoutSession;
+use App\Models\Billing\TicketAutoRechargeAttempt;
+use App\Models\Billing\TicketLedgerEntry;
+use App\Models\Organization;
+use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
+use App\Services\Billing\StripeWebhookProcessor;
+use App\Services\Billing\TicketLedgerService;
+use Illuminate\Support\Facades\Queue;
+use Laravel\Cashier\Events\WebhookReceived;
+use Tests\Support\FakeAutoRechargeGateway;
+
+/*
+ * P8a: オートリチャージ関連の Stripe webhook。
+ *
+ * - invoice.paid (metadata.purpose=auto_recharge) → 冪等付与 + attempt paid
+ * - invoice.payment_failed (同上) → 失敗振り分け Job (外向き API は webhook から退避)
+ * - checkout.session.completed (mode=setup) → PM default 設定 Job
+ * - 月次付与 (billing_reason allowlist) には混入しない
+ */
+
+beforeEach(function (): void {
+    $this->gateway = new FakeAutoRechargeGateway;
+    app()->instance(AutoRechargeGatewayInterface::class, $this->gateway);
+});
+
+/** @return array{Organization, TicketAutoRechargeAttempt} */
+function autoRechargeWebhookFixture(int $quantity = 40, int $unitAmount = 70): array
+{
+    [$organization] = createOrganizationWithOwner();
+    $organization->stripe_id = 'cus_autorecharge_1';
+    $organization->save();
+
+    $attempt = TicketAutoRechargeAttempt::factory()
+        ->for($organization)
+        ->withInvoice('in_autorecharge_1')
+        ->create(['quantity' => $quantity, 'unit_amount' => $unitAmount]);
+
+    return [$organization, $attempt];
+}
+
+/**
+ * @param  array<string, mixed>  $overrides
+ * @return array<string, mixed>
+ */
+function autoRechargeInvoicePaidPayload(string $eventId, array $overrides = []): array
+{
+    $object = array_merge([
+        'id' => 'in_autorecharge_1',
+        'customer' => 'cus_autorecharge_1',
+        'billing_reason' => 'manual',
+        'amount_paid' => 40 * 70,
+        'amount_due' => 40 * 70,
+        'payment_intent' => 'pi_autorecharge_1',
+        'metadata' => [
+            'purpose' => 'auto_recharge',
+            'organization_id' => '999999', // 照合専用 (org 解決には使わない)
+            'recharge_attempt_ulid' => 'PLACEHOLDER',
+        ],
+    ], $overrides);
+
+    return [
+        'id' => $eventId,
+        'type' => 'invoice.paid',
+        'data' => ['object' => $object],
+    ];
+}
+
+function dispatchWebhook(array $payload): void
+{
+    app(StripeWebhookProcessor::class)->handle(new WebhookReceived($payload));
+}
+
+test('auto_recharge invoice.paid でチケットが冪等付与され attempt が paid になる', function (): void {
+    [$organization, $attempt] = autoRechargeWebhookFixture();
+
+    $payload = autoRechargeInvoicePaidPayload('evt_ar_1');
+    $payload['data']['object']['metadata']['recharge_attempt_ulid'] = $attempt->attempt_ulid;
+
+    dispatchWebhook($payload);
+
+    $attempt->refresh();
+    expect($attempt->status)->toBe(AutoRechargeAttemptStatus::Paid)
+        ->and($attempt->stripe_payment_intent_id)->toBe('pi_autorecharge_1');
+
+    $entry = TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_autorecharge_1')->firstOrFail();
+    expect($entry->delta)->toBe(40)
+        ->and($entry->purchase_amount)->toBe(40 * 70)
+        ->and($entry->stripe_invoice_id)->toBe('in_autorecharge_1');
+});
+
+test('同一 invoice の invoice.paid を 2 回処理しても付与は 1 行 (二重課金・二重付与しない)', function (): void {
+    [$organization, $attempt] = autoRechargeWebhookFixture();
+
+    foreach (['evt_ar_dup_1', 'evt_ar_dup_2'] as $eventId) {
+        $payload = autoRechargeInvoicePaidPayload($eventId);
+        $payload['data']['object']['metadata']['recharge_attempt_ulid'] = $attempt->attempt_ulid;
+        dispatchWebhook($payload);
+    }
+
+    expect(TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_autorecharge_1')->count())->toBe(1);
+    expect(app(TicketLedgerService::class)->availableTrueBalance($organization->fresh()))->toBe(40);
+});
+
+test('同期 pay が先に付与済みでも webhook 到着で二重付与しない', function (): void {
+    [$organization, $attempt] = autoRechargeWebhookFixture();
+
+    // 同期 pay 経路が先に付与した状態を作る
+    app(TicketLedgerService::class)->grantAutoRecharge($organization, 40, 'in_autorecharge_1', 40 * 70, 'pi_autorecharge_1');
+
+    $payload = autoRechargeInvoicePaidPayload('evt_ar_race');
+    $payload['data']['object']['metadata']['recharge_attempt_ulid'] = $attempt->attempt_ulid;
+    dispatchWebhook($payload);
+
+    expect(TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_autorecharge_1')->count())->toBe(1);
+    expect($attempt->fresh()->status)->toBe(AutoRechargeAttemptStatus::Paid);
+});
+
+test('amount_due 不一致は fail-closed (例外 + 付与なし)', function (): void {
+    [$organization, $attempt] = autoRechargeWebhookFixture();
+
+    $payload = autoRechargeInvoicePaidPayload('evt_ar_mismatch', ['amount_due' => 1, 'amount_paid' => 1]);
+    $payload['data']['object']['metadata']['recharge_attempt_ulid'] = $attempt->attempt_ulid;
+
+    expect(fn () => dispatchWebhook($payload))->toThrow(RuntimeException::class);
+
+    expect(TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_autorecharge_1')->exists())->toBeFalse();
+    expect($attempt->fresh()->status)->toBe(AutoRechargeAttemptStatus::Pending);
+});
+
+test('amount_paid < amount_due (credit balance 適用) は正当 — 付与成立 + purchase_amount は実回収額', function (): void {
+    [$organization, $attempt] = autoRechargeWebhookFixture();
+
+    $payload = autoRechargeInvoicePaidPayload('evt_ar_credit', ['amount_paid' => 0]);
+    $payload['data']['object']['metadata']['recharge_attempt_ulid'] = $attempt->attempt_ulid;
+    dispatchWebhook($payload);
+
+    $entry = TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_autorecharge_1')->firstOrFail();
+    expect($entry->purchase_amount)->toBe(0)->and($entry->delta)->toBe(40);
+});
+
+test('customer 照合不一致は fail-closed (metadata の organization_id を信用しない)', function (): void {
+    [$organization, $attempt] = autoRechargeWebhookFixture();
+
+    $payload = autoRechargeInvoicePaidPayload('evt_ar_cross', ['customer' => 'cus_other_org']);
+    $payload['data']['object']['metadata']['recharge_attempt_ulid'] = $attempt->attempt_ulid;
+
+    expect(fn () => dispatchWebhook($payload))->toThrow(RuntimeException::class);
+    expect(TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_autorecharge_1')->exists())->toBeFalse();
+});
+
+test('未追跡 attempt の invoice.paid は retryable failure (Stripe 再送待ち)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $organization->stripe_id = 'cus_autorecharge_1';
+    $organization->save();
+
+    $payload = autoRechargeInvoicePaidPayload('evt_ar_untracked');
+    $payload['data']['object']['metadata']['recharge_attempt_ulid'] = 'unknown-ulid';
+
+    expect(fn () => dispatchWebhook($payload))->toThrow(RuntimeException::class);
+});
+
+test('auto-recharge invoice (billing_reason=manual) は月次付与に混入しない', function (): void {
+    [$organization, $attempt] = autoRechargeWebhookFixture();
+    contractPaidPlan($organization);
+
+    $payload = autoRechargeInvoicePaidPayload('evt_ar_no_monthly');
+    $payload['data']['object']['metadata']['recharge_attempt_ulid'] = $attempt->attempt_ulid;
+    dispatchWebhook($payload);
+
+    // 月次付与 (monthly:{invoiceId}) / signup grant は 1 件も増えない
+    expect(TicketLedgerEntry::query()->where('idempotency_key', 'like', 'monthly:%')->exists())->toBeFalse();
+});
+
+test('auto_recharge の invoice.payment_failed は専用 Job に振られる (外向き API は webhook から退避)', function (): void {
+    Queue::fake();
+    [$organization, $attempt] = autoRechargeWebhookFixture();
+
+    dispatchWebhook([
+        'id' => 'evt_ar_failed',
+        'type' => 'invoice.payment_failed',
+        'data' => ['object' => [
+            'id' => 'in_autorecharge_1',
+            'customer' => 'cus_autorecharge_1',
+            'metadata' => [
+                'purpose' => 'auto_recharge',
+                'recharge_attempt_ulid' => $attempt->attempt_ulid,
+            ],
+        ]],
+    ]);
+
+    Queue::assertPushed(
+        HandleAutoRechargeChargeFailureJob::class,
+        fn (HandleAutoRechargeChargeFailureJob $job): bool => $job->attemptId === $attempt->id,
+    );
+});
+
+test('mode=setup の checkout.session.completed は台帳を completed 化し PM 設定 Job を dispatch する', function (): void {
+    Queue::fake();
+    [$organization] = createOrganizationWithOwner();
+    $organization->stripe_id = 'cus_setup_1';
+    $organization->save();
+
+    $session = BillingCheckoutSession::factory()
+        ->for($organization)
+        ->setupPaymentMethod()
+        ->create(['stripe_session_id' => 'cs_setup_webhook_1']);
+
+    dispatchWebhook([
+        'id' => 'evt_setup_1',
+        'type' => 'checkout.session.completed',
+        'data' => ['object' => [
+            'id' => 'cs_setup_webhook_1',
+            'mode' => 'setup',
+            'customer' => 'cus_setup_1',
+            'setup_intent' => 'seti_test_1',
+            'metadata' => ['purpose' => 'auto_recharge_setup'],
+        ]],
+    ]);
+
+    expect($session->fresh()->status)->toBe(CheckoutSessionStatus::Completed->value);
+    Queue::assertPushed(
+        SetDefaultPaymentMethodJob::class,
+        fn (SetDefaultPaymentMethodJob $job): bool => $job->organizationId === $organization->id
+            && $job->setupIntentId === 'seti_test_1',
+    );
+});
+
+test('mode=setup でも他組織の customer なら fail-closed (IDOR)', function (): void {
+    Queue::fake();
+    [$organization] = createOrganizationWithOwner();
+    $organization->stripe_id = 'cus_setup_1';
+    $organization->save();
+
+    BillingCheckoutSession::factory()
+        ->for($organization)
+        ->setupPaymentMethod()
+        ->create(['stripe_session_id' => 'cs_setup_webhook_2']);
+
+    expect(fn () => dispatchWebhook([
+        'id' => 'evt_setup_2',
+        'type' => 'checkout.session.completed',
+        'data' => ['object' => [
+            'id' => 'cs_setup_webhook_2',
+            'mode' => 'setup',
+            'customer' => 'cus_someone_else',
+            'setup_intent' => 'seti_test_2',
+            'metadata' => ['purpose' => 'auto_recharge_setup'],
+        ]],
+    ]))->toThrow(RuntimeException::class);
+
+    Queue::assertNotPushed(SetDefaultPaymentMethodJob::class);
+});
diff --git a/tests/Feature/Billing/TicketAutoRechargeModelTest.php b/tests/Feature/Billing/TicketAutoRechargeModelTest.php
new file mode 100644
index 0000000..9db9b4f
--- /dev/null
+++ b/tests/Feature/Billing/TicketAutoRechargeModelTest.php
@@ -0,0 +1,113 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\AutoRechargeAttemptStatus;
+use App\Models\Billing\TicketAutoRecharge;
+use App\Models\Billing\TicketAutoRechargeAttempt;
+use App\Support\Security\MassAssignmentProtectedKeys;
+use Illuminate\Database\QueryException;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Str;
+
+/*
+ * P8a: オートリチャージのモデル / DB 不変条件。
+ */
+
+test('org あたり pending attempt は同時に 1 つ (partial unique index が最終防衛)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+
+    TicketAutoRechargeAttempt::factory()->for($organization)->create();
+
+    // 2 件目の pending は DB の partial unique が弾く (pgsql は TX を abort させるため
+    // 例外後に同一 TX でクエリを続けない)
+    expect(fn () => TicketAutoRechargeAttempt::factory()->for($organization)->create())
+        ->toThrow(QueryException::class);
+});
+
+test('終端済み attempt は何件でも共存できる (partial index の述語が pending のみ)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+
+    TicketAutoRechargeAttempt::factory()->for($organization)->paid()->create();
+    TicketAutoRechargeAttempt::factory()->for($organization)->failed()->create();
+    TicketAutoRechargeAttempt::factory()->for($organization)->canceled()->create();
+    TicketAutoRechargeAttempt::factory()->for($organization)->create(); // pending 1 件
+
+    expect(TicketAutoRechargeAttempt::query()->where('organization_id', $organization->id)->count())->toBe(4);
+});
+
+test('別 org の pending は互いに干渉しない', function (): void {
+    [$organizationA] = createOrganizationWithOwner('組織A');
+    [$organizationB] = createOrganizationWithOwner('組織B');
+
+    TicketAutoRechargeAttempt::factory()->for($organizationA)->create();
+    TicketAutoRechargeAttempt::factory()->for($organizationB)->create();
+
+    expect(TicketAutoRechargeAttempt::query()->where('status', AutoRechargeAttemptStatus::Pending)->count())->toBe(2);
+});
+
+test('stripe_invoice_id は全体で UNIQUE (同一 invoice が 2 attempt に紐づかない)', function (): void {
+    [$organizationA] = createOrganizationWithOwner('組織A');
+    [$organizationB] = createOrganizationWithOwner('組織B');
+
+    TicketAutoRechargeAttempt::factory()->for($organizationA)->paid()->create(['stripe_invoice_id' => 'in_shared']);
+
+    expect(fn () => TicketAutoRechargeAttempt::factory()->for($organizationB)->paid()->create(['stripe_invoice_id' => 'in_shared']))
+        ->toThrow(QueryException::class);
+});
+
+test('設定行は 1 org 1 行 (organization_id UNIQUE)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+
+    TicketAutoRecharge::factory()->for($organization)->create();
+
+    expect(fn () => TicketAutoRecharge::factory()->for($organization)->create())
+        ->toThrow(QueryException::class);
+});
+
+test('max_count > threshold_count は DB CHECK で強制される (pgsql/mysql)', function (): void {
+    if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)) {
+        $this->markTestSkipped('CHECK 制約は pgsql/mysql のみ (sqlite は ALTER ADD CONSTRAINT 非対応)');
+    }
+
+    [$organization] = createOrganizationWithOwner();
+
+    expect(fn () => TicketAutoRecharge::factory()->for($organization)->create([
+        'threshold_count' => 50,
+        'max_count' => 50,
+    ]))->toThrow(QueryException::class);
+});
+
+test('保護キーは $fillable に載っていない (mass assignment 出口防御)', function (): void {
+    $protected = MassAssignmentProtectedKeys::all();
+
+    foreach ([new TicketAutoRecharge, new TicketAutoRechargeAttempt] as $model) {
+        expect(array_intersect($protected, $model->getFillable()))->toBe([]);
+    }
+});
+
+test('attempt_ulid は UNIQUE (Stripe 冪等キーの外部識別子)', function (): void {
+    [$organizationA] = createOrganizationWithOwner('組織A');
+    [$organizationB] = createOrganizationWithOwner('組織B');
+    $ulid = strtolower((string) Str::ulid());
+
+    TicketAutoRechargeAttempt::factory()->for($organizationA)->paid()->create(['attempt_ulid' => $ulid]);
+
+    expect(fn () => TicketAutoRechargeAttempt::factory()->for($organizationB)->paid()->create(['attempt_ulid' => $ulid]))
+        ->toThrow(QueryException::class);
+});
+
+test('組織の物理削除で設定・試行行が cascade 削除される (Organization は soft delete のため forceDelete)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    TicketAutoRecharge::factory()->for($organization)->create();
+    TicketAutoRechargeAttempt::factory()->for($organization)->create();
+
+    // soft delete では FK cascade は走らない (行が残るのが正しい挙動)
+    $organization->delete();
+    expect(TicketAutoRecharge::query()->count())->toBe(1)
+        ->and(TicketAutoRechargeAttempt::query()->count())->toBe(1);
+
+    $organization->forceDelete();
+    expect(TicketAutoRecharge::query()->count())->toBe(0)
+        ->and(TicketAutoRechargeAttempt::query()->count())->toBe(0);
+});
diff --git a/tests/Support/FakeAutoRechargeGateway.php b/tests/Support/FakeAutoRechargeGateway.php
new file mode 100644
index 0000000..5d84d1c
--- /dev/null
+++ b/tests/Support/FakeAutoRechargeGateway.php
@@ -0,0 +1,191 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support;
+
+use App\DataTransferObjects\Billing\DefaultPaymentMethodDto;
+use App\DataTransferObjects\Billing\InvoiceStateDto;
+use App\DataTransferObjects\Billing\OffSessionChargeResultDto;
+use App\Models\Organization;
+use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
+use RuntimeException;
+
+/**
+ * AutoRechargeGatewayInterface のテスト用 spy (Stripe に到達しない)。
+ *
+ * 呼び出しを記録し、テスト側が結果を注入できる。invoice の状態は内部 map で保持し、
+ * terminate / retrieve が現実の Stripe と同じ状態機械 (draft/open → void / paid は終端不可)
+ * を近似する。
+ */
+final class FakeAutoRechargeGateway implements AutoRechargeGatewayInterface
+{
+    /** @var list<array{organizationId: int, successUrl: string, cancelUrl: string, metadata: array<string, string>, idempotencyKey: string}> */
+    public array $setupCheckouts = [];
+
+    /** @var list<array{organizationId: int, priceId: string, quantity: int, metadata: array<string, string>, keyBase: string}> */
+    public array $createdInvoices = [];
+
+    /** @var list<array{invoiceId: string, keyBase: string}> */
+    public array $payCalls = [];
+
+    /** @var list<string> */
+    public array $terminated = [];
+
+    /** @var list<array{organizationId: int, paymentMethodId: string}> */
+    public array $defaultPaymentMethodsSet = [];
+
+    /** default PM 状態 (有効化 fail-closed 判定の注入点)。 */
+    public ?DefaultPaymentMethodDto $defaultPaymentMethod = null;
+
+    /** payOffSessionInvoice の返り値 (null なら paid を合成する)。 */
+    public ?OffSessionChargeResultDto $payResult = null;
+
+    /** retrieveInvoiceState の返り値 (null なら invoiceStates → 内部 status map の順で解決する)。 */
+    public ?InvoiceStateDto $invoiceState = null;
+
+    /** invoiceId => InvoiceStateDto の個別注入 (org ごとに異なる状態を作るテスト用)。 */
+    /** @var array<string, InvoiceStateDto> */
+    public array $invoiceStates = [];
+
+    /** invoiceId => status (retrieveInvoiceState / terminateInvoice の内部状態)。 */
+    /** @var array<string, string> */
+    public array $invoiceStatuses = [];
+
+    /** true にすると terminateInvoice が throw する (終端失敗 → pending 維持の再現)。 */
+    public bool $failOnTerminate = false;
+
+    /** createSetupCheckout が返す url (null = 進行中 replay の再現)。 */
+    public ?string $setupUrl = 'https://checkout.stripe.test/c/setup/cs_setup_test';
+
+    /** paid 合成時に使う実回収額 (null なら quantity × unit を使う呼び出し側の期待に委ねる)。 */
+    public ?int $payAmountPaid = null;
+
+    public ?string $payPaymentIntentId = 'pi_test_autorecharge';
+
+    public function createSetupCheckout(
+        Organization $organization,
+        string $successUrl,
+        string $cancelUrl,
+        array $metadata,
+        string $idempotencyKey,
+    ): array {
+        $this->setupCheckouts[] = [
+            'organizationId' => (int) $organization->getKey(),
+            'successUrl' => $successUrl,
+            'cancelUrl' => $cancelUrl,
+            'metadata' => $metadata,
+            'idempotencyKey' => $idempotencyKey,
+        ];
+
+        // idempotency key から決定的に導出 (同一 token の再送は同一 session に収束)
+        return [
+            'id' => 'cs_setup_'.substr(hash('sha256', $idempotencyKey), 0, 24),
+            'url' => $this->setupUrl,
+        ];
+    }
+
+    public function createAutoRechargeInvoice(
+        Organization $organization,
+        string $priceId,
+        int $quantity,
+        array $metadata,
+        string $idempotencyKeyBase,
+    ): string {
+        $this->createdInvoices[] = [
+            'organizationId' => (int) $organization->getKey(),
+            'priceId' => $priceId,
+            'quantity' => $quantity,
+            'metadata' => $metadata,
+            'keyBase' => $idempotencyKeyBase,
+        ];
+
+        // Stripe 冪等: 同一 key base は同一 invoice id に収束する
+        $invoiceId = 'in_'.substr(hash('sha256', $idempotencyKeyBase), 0, 24);
+        $this->invoiceStatuses[$invoiceId] ??= 'draft';
+
+        return $invoiceId;
+    }
+
+    public function payOffSessionInvoice(string $invoiceId, string $idempotencyKeyBase): OffSessionChargeResultDto
+    {
+        $this->payCalls[] = ['invoiceId' => $invoiceId, 'keyBase' => $idempotencyKeyBase];
+
+        if ($this->payResult !== null) {
+            if ($this->payResult->paid) {
+                $this->invoiceStatuses[$invoiceId] = 'paid';
+            } else {
+                $this->invoiceStatuses[$invoiceId] = 'open';
+            }
+
+            return $this->payResult;
+        }
+
+        $this->invoiceStatuses[$invoiceId] = 'paid';
+        $amount = $this->payAmountPaid ?? 0;
+
+        return OffSessionChargeResultDto::paid($invoiceId, $amount, $amount, $this->payPaymentIntentId);
+    }
+
+    public function terminateInvoice(string $invoiceId): void
+    {
+        if ($this->failOnTerminate) {
+            throw new RuntimeException('fake gateway: invoice 終端失敗');
+        }
+
+        $status = $this->invoiceStatuses[$invoiceId] ?? 'open';
+        if ($status === 'paid') {
+            throw new RuntimeException("fake gateway: paid invoice {$invoiceId} は終端できない");
+        }
+
+        $this->terminated[] = $invoiceId;
+        $this->invoiceStatuses[$invoiceId] = $status === 'draft' ? 'deleted' : 'void';
+    }
+
+    public function retrieveInvoiceState(string $invoiceId): InvoiceStateDto
+    {
+        if (isset($this->invoiceStates[$invoiceId])) {
+            return $this->invoiceStates[$invoiceId];
+        }
+
+        if ($this->invoiceState !== null) {
+            return $this->invoiceState;
+        }
+
+        return new InvoiceStateDto(
+            $this->invoiceStatuses[$invoiceId] ?? 'open',
+            null,
+            null,
+            null,
+            false,
+            'https://invoice.stripe.test/i/'.$invoiceId,
+        );
+    }
+
+    public function getDefaultPaymentMethodState(Organization $organization): DefaultPaymentMethodDto
+    {
+        return $this->defaultPaymentMethod ?? DefaultPaymentMethodDto::none();
+    }
+
+    public function resolveSetupIntentPaymentMethod(string $setupIntentId): string
+    {
+        return 'pm_test_'.substr(hash('sha256', $setupIntentId), 0, 16);
+    }
+
+    public function setDefaultPaymentMethod(Organization $organization, string $paymentMethodId): void
+    {
+        $this->defaultPaymentMethodsSet[] = [
+            'organizationId' => (int) $organization->getKey(),
+            'paymentMethodId' => $paymentMethodId,
+        ];
+        $this->defaultPaymentMethod = new DefaultPaymentMethodDto($paymentMethodId, 'visa', '4242');
+    }
+
+    /** 有効化 fail-closed を通過させる (default PM ありの状態を注入する)。 */
+    public function withDefaultPaymentMethod(string $paymentMethodId = 'pm_test_default'): self
+    {
+        $this->defaultPaymentMethod = new DefaultPaymentMethodDto($paymentMethodId, 'visa', '4242');
+
+        return $this;
+    }
+}
diff --git a/tests/js/components/features/billing/AutoRechargeCard.test.ts b/tests/js/components/features/billing/AutoRechargeCard.test.ts
new file mode 100644
index 0000000..3b2306b
--- /dev/null
+++ b/tests/js/components/features/billing/AutoRechargeCard.test.ts
@@ -0,0 +1,127 @@
+import { describe, it, expect, beforeEach } from "vitest";
+import { render, cleanup, screen, fireEvent } from "@testing-library/svelte";
+import AutoRechargeCard from "@/components/features/billing/AutoRechargeCard.svelte";
+import { autoRechargeProps } from "../../../support/autoRechargeProps";
+import type { AutoRechargeProps } from "@/types/billing";
+
+// P8a: オートリチャージ設定カード。既定 off の opt-in で、有効化は同意を挟む fail-closed。
+
+const renderCard = (overrides: Partial<AutoRechargeProps> = {}) =>
+    render(AutoRechargeCard, {
+        props: {
+            autoRecharge: autoRechargeProps(overrides),
+            updateUrl: "/billing/auto-recharge",
+            setupUrl: "/billing/auto-recharge/setup",
+            setupAttemptToken: "01hzzzzzzzzzzzzzzzzzzzzzzz",
+        },
+    });
+
+describe("AutoRechargeCard", () => {
+    beforeEach(() => cleanup());
+
+    it("既定は無効表示で、有効時のステータス文は出ない", () => {
+        renderCard();
+
+        expect(screen.getByTestId("auto-recharge-state-badge").textContent?.trim()).toBe("無効");
+        expect(screen.queryByTestId("auto-recharge-status")).toBeNull();
+    });
+
+    it("カード未登録ではカード登録 CTA を出し、有効化ボタンは出さない (fail-closed)", () => {
+        renderCard({ hasPaymentMethod: false });
+
+        expect(screen.getByTestId("auto-recharge-setup")).not.toBeNull();
+        expect(screen.queryByTestId("auto-recharge-enable")).toBeNull();
+        // 設定値の保存だけは許可する (カード登録前でも閾値・上限を決められる)
+        expect(screen.getByTestId("auto-recharge-save-draft")).not.toBeNull();
+    });
+
+    it("カード登録済みなら有効化ボタンとカード情報を出す", () => {
+        renderCard({ hasPaymentMethod: true, paymentMethodBrand: "visa", paymentMethodLast4: "4242" });
+
+        expect(screen.getByTestId("auto-recharge-enable")).not.toBeNull();
+        expect(screen.getByTestId("auto-recharge-pm").textContent).toContain("4242");
+    });
+
+    it("有効化ボタン押下で同意パネルを開く (同意なしに課金設定を確定させない)", async () => {
+        renderCard({ hasPaymentMethod: true });
+
+        expect(screen.queryByTestId("auto-recharge-consent")).toBeNull();
+        await fireEvent.click(screen.getByTestId("auto-recharge-enable"));
+
+        const consent = screen.getByTestId("auto-recharge-consent");
+        expect(consent.textContent).toContain("残高が 5 枚を下回ると");
+        expect(consent.textContent).toContain("50 枚まで補充します");
+        expect(screen.getByTestId("auto-recharge-consent-confirm")).not.toBeNull();
+    });
+
+    it("有効時は「設定を更新する」と「停止する」を出す (停止は常に押せる)", () => {
+        renderCard({ enabled: true, hasPaymentMethod: true });
+
+        expect(screen.getByTestId("auto-recharge-update")).not.toBeNull();
+        const disable = screen.getByTestId("auto-recharge-disable");
+        expect(disable.hasAttribute("disabled")).toBe(false);
+        expect(screen.getByTestId("auto-recharge-status")).not.toBeNull();
+    });
+
+    it("requiresReconsent で「再同意まで自動購入は行われません」旨のバナーを出す", () => {
+        renderCard({ enabled: true, hasPaymentMethod: true, requiresReconsent: true });
+
+        const banner = screen.getByTestId("auto-recharge-reconsent-banner");
+        expect(banner.textContent).toContain("再度同意するまで、自動購入は行われません");
+    });
+
+    it("連続失敗の自動停止では danger バナーと「自動停止中」バッジを出す", () => {
+        renderCard({ disabledReason: "payment_failures", failureCount: 3 });
+
+        expect(screen.getByTestId("auto-recharge-failure-banner")).not.toBeNull();
+        expect(screen.getByTestId("auto-recharge-state-badge").textContent?.trim()).toBe(
+            "自動停止中",
+        );
+    });
+
+    it("pendingAutoEnable ではカード登録完了で自動有効化される旨を出す", () => {
+        renderCard({ pendingAutoEnable: true });
+
+        expect(screen.getByTestId("auto-recharge-no-pm").textContent).toContain(
+            "カード登録が完了すると",
+        );
+    });
+
+    it("setupPending では処理中表示に切り替わる", () => {
+        renderCard({ setupPending: true });
+
+        expect(screen.getByTestId("auto-recharge-setup-pending")).not.toBeNull();
+        expect(screen.queryByTestId("auto-recharge-setup")).toBeNull();
+    });
+
+    it("canManage=false では操作ボタンを出さず理由を提示する (disabled でブロックしない)", () => {
+        renderCard({ canManage: false, hasPaymentMethod: true });
+
+        expect(screen.queryByTestId("auto-recharge-enable")).toBeNull();
+        expect(screen.queryByTestId("auto-recharge-disable")).toBeNull();
+        expect(screen.getByTestId("auto-recharge-readonly").textContent).toContain(
+            "管理者権限が必要です",
+        );
+    });
+
+    it("上限額は tier 単価で算出して表示する (maxCount=50 → 70 円 × 50)", () => {
+        renderCard();
+
+        expect(screen.getByTestId("auto-recharge-max-amount").textContent).toContain("¥3,500");
+    });
+
+    it("不正な入力でもボタンは押せて、押下時にエラーを表示する (禁止事項 #8)", async () => {
+        renderCard({ hasPaymentMethod: true });
+
+        const maxInput = screen.getByTestId("auto-recharge-max-input");
+        await fireEvent.input(maxInput, { target: { value: "0" } });
+
+        const enable = screen.getByTestId("auto-recharge-enable");
+        expect(enable.hasAttribute("disabled")).toBe(false);
+
+        await fireEvent.click(enable);
+        expect(screen.getByTestId("auto-recharge-range-error")).not.toBeNull();
+        // エラー時は同意パネルを開かない
+        expect(screen.queryByTestId("auto-recharge-consent")).toBeNull();
+    });
+});
diff --git a/tests/js/pages/OnboardingCheckout.test.ts b/tests/js/pages/OnboardingCheckout.test.ts
index fb72df0..3bf3e1c 100644
--- a/tests/js/pages/OnboardingCheckout.test.ts
+++ b/tests/js/pages/OnboardingCheckout.test.ts
@@ -40,6 +40,14 @@ const basePageData: OnboardingCheckoutShape = {
     personalEligibility: { eligible: true, reason: null, reasonLabel: null },
     signupGrantTickets: 10,
     intendedPlanCode: null,
+    consentTerms: {
+        thresholdCount: 5,
+        maxCount: 50,
+        maxAmountJpy: 3500,
+        unitAmountJpy: 70,
+        consentVersion: "v1",
+    },
+    fundingChoices: ["auto_recharge", "later"],
 };
 
 afterEach(() => {
@@ -120,7 +128,8 @@ describe("Onboarding/Checkout", () => {
         await fireEvent.click(submit);
         expect(routerPostMock).toHaveBeenCalledWith(
             "/onboarding/activate-personal",
-            { declaration: "0" },
+            // P8a: funding_choice の既定は auto_recharge (同意 version 同送。金額は送らない)
+            { declaration: "0", funding_choice: "auto_recharge", consent_version: "v1" },
             expect.anything(),
         );
     });
@@ -143,7 +152,7 @@ describe("Onboarding/Checkout", () => {
 
         expect(routerPostMock).toHaveBeenCalledWith(
             "/onboarding/activate-personal",
-            { declaration: "1" },
+            { declaration: "1", funding_choice: "auto_recharge", consent_version: "v1" },
             expect.anything(),
         );
     });
@@ -255,4 +264,41 @@ describe("Onboarding/Checkout", () => {
             "/contact?source=onboarding",
         );
     });
+
+    it("P8a: 資金選択の既定は auto_recharge で同意条件をサーバ確定値で提示する", async () => {
+        renderPage();
+        await choosePersonal();
+
+        const autoRecharge = screen.getByTestId("funding-choice-auto_recharge");
+        expect((autoRecharge as HTMLInputElement).checked).toBe(true);
+
+        const terms = screen.getByTestId("funding-consent-terms");
+        expect(terms).toHaveTextContent("残高が 5 枚を下回ると");
+        expect(terms).toHaveTextContent("50");
+        expect(terms).toHaveTextContent("¥3,500");
+        expect(terms).toHaveTextContent("¥70");
+    });
+
+    it("P8a: 「あとで決める」を選ぶと同意条件を隠し consent_version を送らない", async () => {
+        renderPage();
+        await choosePersonal();
+
+        await fireEvent.click(screen.getByTestId("funding-choice-later"));
+        expect(screen.queryByTestId("funding-consent-terms")).toBeNull();
+
+        await fireEvent.click(screen.getByTestId("personal-free-submit"));
+
+        expect(routerPostMock).toHaveBeenCalledWith(
+            "/onboarding/activate-personal",
+            { declaration: "0", funding_choice: "later" },
+            expect.anything(),
+        );
+    });
+
+    it("P8a: UI には tickets 選択肢を出さない (fundingChoices はサーバ確定の並び)", async () => {
+        renderPage();
+        await choosePersonal();
+
+        expect(screen.queryByTestId("funding-choice-tickets")).toBeNull();
+    });
 });
diff --git a/tests/js/pages/PurchaseTickets.test.ts b/tests/js/pages/PurchaseTickets.test.ts
index 8599280..6fb632c 100644
--- a/tests/js/pages/PurchaseTickets.test.ts
+++ b/tests/js/pages/PurchaseTickets.test.ts
@@ -40,6 +40,7 @@ const basePage: PurchaseTicketsPageProps = {
     canManage: true,
     attemptToken: "01J0000000000000000000TEST",
     purchased: false,
+    autoRechargeEnabled: false,
 };
 
 afterEach(() => {
diff --git a/tests/js/support/autoRechargeProps.ts b/tests/js/support/autoRechargeProps.ts
new file mode 100644
index 0000000..8af0e87
--- /dev/null
+++ b/tests/js/support/autoRechargeProps.ts
@@ -0,0 +1,32 @@
+import type { AutoRechargeProps } from "@/types/billing";
+
+/**
+ * AutoRechargeCard の props factory (P8a)。
+ * 既定は「opt-in 未設定」= enabled=false / カード未登録 の状態。
+ */
+export function autoRechargeProps(overrides: Partial<AutoRechargeProps> = {}): AutoRechargeProps {
+    return {
+        enabled: false,
+        thresholdCount: 5,
+        maxCount: 50,
+        minCount: 1,
+        maxCountLimit: 1000,
+        canManage: true,
+        hasPaymentMethod: false,
+        paymentMethodBrand: null,
+        paymentMethodLast4: null,
+        setupPending: false,
+        requiresReconsent: false,
+        pendingAutoEnable: false,
+        disabledReason: null,
+        failureCount: 0,
+        consentVersion: "v1",
+        baseUnitAmountJpy: 100,
+        tiers: [
+            { minCount: 1, unitAmount: 100 },
+            { minCount: 20, unitAmount: 80 },
+            { minCount: 50, unitAmount: 70 },
+        ],
+        ...overrides,
+    };
+}
```

---

# 設計書 (正本) の P8a 節

> 出典: `devnotes/20260717-0035-aigenba-billing-parity/detailed-design.md` L1768-2092。Codex 合議 16 ラウンドで APPROVED 済み。

### P8a: 裏チャージ = オートリチャージ（opt-in・既定 off）

残高が閾値を割ったら Stripe invoice で自動補充する。AI-CUE には実装・語彙が **0 件**（audit `ticket-charge-1` / `billing-subscription-2`）。aigenba の `AutoRechargeService`（1290 行 / 43 メソッド）を中核に **verbatim 移植**する。決済実行を伴うため、冪等キーと並行制御を契約として固定する。

**前提フェーズ**: P2（`BillingCheckoutSession` / `CheckoutIntent`（`SetupPaymentMethod` 済み）/ `Contracts\StripeGatewayInterface` / `BillingPermissionService`）、P3（`Onboarding/Checkout.svelte` / `ActivatePersonalController` / `ActivatePersonalRequest`）、P5（`availableTrueBalance`）、P7（`OnboardingReturnResolver` / `?plan=` handoff）。

**DoD**: **既定 off の opt-in**。設定行が無い org の挙動は完全不変（`reserve` の低残高通知も含む）。migration は additive のみ（新テーブル 2 + 列 1）。**値は aigenba 既定値のまま**（`default_threshold=5` / `default_max=50` / `max_count=1000` / `max_failures=3`）。**D20 の監視 DoD（後述）を満たすこと**。

#### 未決事項の決定（Round 13 Warning の解消。3 件とも本文へ昇格）

| ID | 論点 | 決定 | 根拠（実ファイル / 条項） |
|---|---|---|---|
| **D29** | signup-funding 事前同意層 | **移植する**（原則 1）。AI-CUE には宿主が実在するため原則 4 は適用できない（`ActivatePersonalController` / `Onboarding/Checkout.svelte` = P3 産出、`BillingCheckoutSession` = P2 産出）。AGENTS.md 抵触も無い（UI の disabled 回避のみ既決 = D4）。**所管を 2 つに確定**: **(i) P8a = free（personal）経路の全部** — `SignupFundingChoice`（**verbatim 3 case**）/ `ActivatePersonalRequest.{funding_choice, consent_version}` / `ActivatePersonalController` の `AutoRecharge` 分岐・`Tickets` 分岐・`setupAttemptToken` / `recordPreConsent` / `startSetupCheckout` / `applySetupCompletion` / `autoEnableEligible` / `isAutoEnablePending` / `pendingAutoEnable` / `hasRecentCompletedSetup`。**(ii) P9 = T1004 のサブスク決済カード流用** — `ReuseSubscriptionPaymentMethodJob` / `applyReusedPaymentMethod` / `resolveSubscriptionPaymentMethod` / `hasRecentAutoRechargeFundedSignup` / `billing_checkout_sessions.{funding_choice, pm_reuse_dispatched_at}` / 着地 flash の分岐 | P3 本文が既に `funding_choice` / `consent_version` / `startSetupCheckout` / `setupAttemptToken` を「**P8a**」へ明示委譲済み（設計本文 P3 変更箇所表）。(ii) の唯一の入力は **subscription checkout の `BillingCheckoutSession` 行（intent + funding_choice + attempt token）**で、その writer は **D25 により P9 所管**（P2 本文「`BillingCheckoutSession` の writer も P2 では存在しない（行 0 件）… writer は P9」）。P8a 時点では入力行を作る経路が AI-CUE に存在しない = **原則 4 の時点適用**であり、呼び出し元の無い `applyReusedPaymentMethod` を P8a に置くのは **P2 の「dead code を作らない」規約**（`getStatus()` 非移植と同一）に反する。**新 intent は不要**: AI-CUE の契約 checkout は `CheckoutIntent::SubscriptionStart`（`SignupFunding` は P2 が原則 4 で非移植）であり、P9 は `funding_choice` 列を additive に足すだけで T1004 が成立する |
| **D29-b** | `consent_version` の既定 | **P8a = `'v1'`／P9（T1004 配線）と同時に `'v2'` へ上げる**。値の発明ではなく **aigenba が定義した版の意味に機械的に従った結果** | `/tmp/aigenba/config/billing.php:39-46` が版の定義そのものを明記: 「改定履歴: **v1 = T1003 初版（カード登録経路のみ）** / **v2 = T1004 有償契約でサブスク決済カードをオートリチャージへ流用することを明示**」「提示条件の実質（…**カードの取得手段**）を変える変更では**必ず version を上げること**」。P8a が実装するのは T1003 = カード登録経路のみ ⇒ **v1 が aigenba の版管理規約に照らした正しい版**。P9 で流用を配線した瞬間に v2 へ上げると、`reconsentRequiredFor` 経由で既存同意が自動失効し再同意が要る = **aigenba の版管理契約そのもの**（fail-closed）。逆に P8a で v2 を置くと「未実装の副作用への同意」を記録することになり、版の定義に反する |
| **D30** | `ticket_purchases` 正本化 | **移植しない**（parity 逸脱の「承認待ち」ではなく**決定済み**）。`grantAutoRecharge` は **ledger インライン 1 本書き**。両建てが無いため片肺検証（`ledgerInserted !== $purchaseInserted` の `RuntimeException`）も**構造的に不要** | **ユーザー決定 F3**（設計本文 §ユーザー決定「チケット会計 = 残高会計の**精緻化**。**台帳の置換ではない**」。再検討しない前提）。AI-CUE の「購入の返金逆引き正本」は `ticket_ledger_entries` のインライン列（`payment_intent_id` + `purchase_amount`）として**既に存在する**（`TicketLedgerService.php:152-215 clawbackPurchasedByPaymentIntent` が PI で引く）ため、`ticket_purchases` は「AI-CUE に対象が存在しない機能」ではなく**同一機能の別構造**であり、両建て化は F3 が禁じた台帳の置換に当たる。`stripe_invoice_id` 列 1 本の additive 追加で invoice アンカーの返金逆引きが成立する。audit `ticket-charge-4`（「単独での先行導入はしない」）とも整合。**`AutoRechargeService` は `TicketPurchase` を一切参照しない**（aigenba でも参照は `TicketService::grantAutoRecharge` のみ）ため、移植範囲に穴は空かない |
| **D31** | Gateway 粒度 | **AI-CUE の狭い gateway + Fake 規約を維持**（P8a は `Contracts\AutoRechargeGatewayInterface`（**8 メソッド**）を新設）。aigenba の単一 `StripeGatewayInterface`（30+ メソッド）/ `CashierStripeGateway`（41KB）へは寄せない | **P2 v2 本文で既に確定済み**（`Contracts\StripeGatewayInterface` は 3 メソッドに限定 / 「aigenba の 30+ メソッド単一 interface へは寄せず、AI-CUE の狭い gateway + チケット系 Gateway 分割の境界を維持」）。**既存規約 = AI-CUE 側の構造**であり、単一巨大 interface へ寄せると **gateway 単位の Fake bind 契約が壊れる**（`app/Providers/FakeExternalsServiceProvider.php:79-80` の `TicketCheckoutGateway → FakeTicketCheckoutGateway` / `SubscriptionCheckoutGateway → FakeSubscriptionCheckoutGateway` と、それを検査する `tests/Feature/Providers/FakeExternalsServiceProviderTest.php`）。P8a は同規約に沿って **3 本目の bind を足すだけ** |

#### 変更箇所

**マイグレーション（additive のみ）**

| AI-CUE（新規） | 内容 | 移植元 |
|---|---|---|
| `database/migrations/XXXX_create_ticket_auto_recharges_table.php` | 設定 1 org 1 行。`organization_id` unique / `enabled` default false / `threshold_count` / `max_count` / `stripe_payment_method_id` / `failure_count` / `disabled_reason` / 同意 snapshot 4 列（`consented_at` / `consent_version` / `consented_max_count` / `consented_max_amount`）/ `created_by_user_id`。`max_count > threshold_count` CHECK は pgsql/mysql のみ（sqlite は ALTER ADD CONSTRAINT 非対応 → driver guard） | `/tmp/aigenba/database/migrations/2026_07_09_000100_create_ticket_auto_recharges_table.php` |
| `database/migrations/XXXX_create_ticket_auto_recharge_attempts_table.php` | 試行の状態機械。`attempt_ulid` unique / `status` / `quantity` / `unit_amount` / `stripe_price_id` / `stripe_invoice_id` unique nullable / `stripe_payment_intent_id` / `failure_code` / `resolved_at`。**partial unique `tar_attempts_org_pending_unique ON (organization_id) WHERE status='pending'`** | `2026_07_09_000200_create_ticket_auto_recharge_attempts_table.php`（verbatim） |
| `database/migrations/XXXX_add_stripe_invoice_id_to_ticket_ledger_entries.php` | `ticket_ledger_entries.stripe_invoice_id` nullable + index（現行は `stripe_checkout_session_id` のみ）。**D30 の invoice アンカーはこの 1 列で成立** | `2026_07_09_000300_add_invoice_anchor_to_ticket_purchases_and_ledger.php` の **ledger 側のみ** |

> **partial unique index の driver guard**: AI-CUE 内に前例がある（`2026_07_13_180622_add_signup_grant_unique_index_to_ticket_ledger_entries.php` = pgsql/sqlite 限定 + 非対応 driver は `RuntimeException` で fail-closed）。attempts の partial unique も**同一様式に揃える**（aigenba の raw `DB::statement` は driver チェックを持たないため、そこだけ AI-CUE の既存前例に合わせる）。

**Enum / DTO**

| AI-CUE（新規） | 移植元 |
|---|---|
| `app/Enums/Billing/AutoRechargeDisabledReason.php`（`PaymentFailures` / `User`） | 同名（verbatim） |
| `app/Enums/Billing/AutoRechargeAttemptStatus.php`（`Pending`/`Paid`/`Failed`/`Canceled`） | 同名（verbatim） |
| `app/Enums/Billing/SignupFundingChoice.php`（`AutoRecharge` / `Tickets` / `Later`。**3 case verbatim**。case 縮小は D1/D2 撤回済みの禁じ手） | 同名（verbatim。docblock も） |
| `app/DataTransferObjects/Billing/AutoRechargeConsentDto.php`（`version` のみ） | 同名（verbatim） |
| `app/DataTransferObjects/Billing/AutoRechargeConsentTermsDto.php`（`thresholdCount` / `maxCount` / `maxAmountJpy` / `unitAmountJpy` / `consentVersion`） | 同名（verbatim） |
| `app/DataTransferObjects/Billing/AutoRechargeSettingsDto.php`（**17 フィールド verbatim**。`pendingAutoEnable` / `setupPending` を含む） | 同名（verbatim） |
| `app/DataTransferObjects/Billing/DefaultPaymentMethodDto.php` / `OffSessionChargeResultDto.php` / `InvoiceStateDto.php` | 同名（gateway 戻り値） |
| `app/Enums/Billing/BillingNotificationType.php` に **4 case 追加**（`AutoRechargeFailed` / `AutoRechargeDisabled` / `AutoRechargeActionRequired` / `AutoRechargeEnabled`） | 同 enum L27-30（現行 AI-CUE は `PaymentFailed` / `RenewalReminder` の 2 case） |

> `PurchaseTicketsDto` は **AI-CUE では `PurchaseTicketsPageDto`** が現行名。P8a では `autoRechargeEnabled: bool` の 1 フィールド追加に留める（`formState`/`resumeUrl`/`returnTo` は audit `ticket-charge-5`/`-6` = **P8b の別 finding**）。`CheckoutIntent::SetupPaymentMethod` は **P2 で既に存在**（追加不要）。

**Model / Factory**

- `app/Models/Billing/TicketAutoRecharge.php` / `TicketAutoRechargeAttempt.php` ← aigenba 同名（verbatim。`disabled_reason` は enum cast）
- `database/factories/Billing/{TicketAutoRechargeFactory,TicketAutoRechargeAttemptFactory}.php` ← aigenba 同名（**新モデルには Factory を作る**規約 = テストデータ手組み禁止）

**Service / Gateway（D31）**

- `app/Services/Billing/AutoRechargeService.php` ← aigenba 同名（**AI-CUE 接地の 3 点**は「主要な契約」参照。T1004 の 2 メソッド（`applyReusedPaymentMethod` / `hasRecentAutoRechargeFundedSignup`）は **D29 により P9**）
- `app/Services/Billing/Contracts/AutoRechargeGatewayInterface.php`（新規）+ `app/Services/Billing/CashierAutoRechargeGateway.php` + `app/Services/Billing/Fakes/FakeAutoRechargeGateway.php` ← aigenba `Contracts/StripeGatewayInterface.php` の **auto-recharge 8 メソッドのみ**を切り出す（`resolveSubscriptionPaymentMethod` は P9 で追加）
- `app/Providers/{AppServiceProvider,FakeExternalsServiceProvider}.php`: 3 本目の gateway bind を追加（`FakeExternalsServiceProvider.php:79-80` と同一様式）
- `app/Services/Billing/TicketLedgerService.php`: `grantAutoRecharge()` 追加 + **`reserve()` に trigger dispatch を追加**（`TicketLedgerService.php:277-279` の既存 `DB::afterCommit` に同居）

**Job / Command / Notification**

- `app/Jobs/Billing/{AutoRechargeTriggerJob,ExecuteAutoRechargeAttemptJob,HandleAutoRechargeChargeFailureJob,SetDefaultPaymentMethodJob}.php` ← aigenba 同名
- `app/Console/Commands/Billing/ReconcileAutoRechargeAttempts.php` ← aigenba 同名（**verbatim**）+ `routes/console.php` に scheduler 登録（**D20**。既存「課金 cron」ブロックの様式に合わせる）
- `app/Notifications/Billing/{AutoRechargeFailed,AutoRechargeDisabled,AutoRechargeActionRequired,AutoRechargeEnabled}Notification.php` ← aigenba 同名。AI-CUE の `TracksBillingDelivery` / `TracksBillingReminderDelivery` contract を実装（`BillingNotificationDispatcher::sendOnce` / `sendReminderOnce` が Assert で delivery key 一致を強制）

**Controller / Request / Route / Config**

- `app/Http/Controllers/Billing/BillingController.php`: `updateAutoRecharge` / `startAutoRechargeSetup` / `index` に setup 着地解決（303 + flash）を追加 ← aigenba `BillingController.php:737` / `:778` / `:216`
- `app/Http/Requests/Billing/{UpdateAutoRechargeRequest,StartAutoRechargeSetupRequest}.php` ← aigenba 同名（`ProhibitsProtectedKeys` は AI-CUE にも `app/Http/Requests/Concerns/` に実在。P3 で `ActivatePersonalRequest` に配線済み）
- **D29(i) の onboarding 部分**: `app/Http/Requests/Onboarding/ActivatePersonalRequest.php` に `funding_choice`（`Rule::in(SignupFundingChoice::cases())`）+ `consent_version`（`required_if:funding_choice,auto_recharge` + `Rule::in([currentConsentVersion()])`）を **additive 追加**（`messages()` の 2 文言も verbatim）/ `app/Http/Controllers/Onboarding/ActivatePersonalController.php` に `AutoRecharge` 分岐（`recordPreConsent` → `startSetupCheckout` → `Inertia::location`）・`Tickets` 分岐（`billing.tickets.show` へ redirect）・`setupAttemptToken()`（session 保持 ULID）を追加
- `routes/web.php`: `POST /billing/auto-recharge` → `billing.auto-recharge.update` / `POST /billing/auto-recharge/setup` → `billing.auto-recharge.setup`。**current-org スコープ**（D6/D21。aigenba の org-slug スコープは移植しない）。既存 `billing.*` と同じく**課金ゲート allowlist**（`require-active-subscription` group の外）
- `config/billing.php`: `auto_recharge` ブロック追加 ← `/tmp/aigenba/config/billing.php:31-47`（`default_threshold=5` / `default_max=50` / `max_count=1000` / `max_failures=3` / `pending_expiry_hours=24` / `setup_pending_window_minutes=30` / **`consent_version='v1'`（D29-b）**）
- `docs/architecture.md`: **監視対象リストへ登録**（D20。既存 L138 / L150 / L266 の「デプロイ手順・監視対象に … を必須項目として登録する」様式）

**UI（最小。情報密度の作り込みは P8b）**

- `resources/js/components/features/billing/AutoRechargeCard.svelte` ← aigenba 同名。T071 primitive（`molecules/PageHeaderSection` 配下）に載せ `Billing/Index.svelte` に組み込む。**P8a に含める理由**: これが無いと opt-in 導線が存在せず機能が到達不能なまま merge される
- `resources/js/pages/Onboarding/Checkout.svelte`: **funding 2 択（`auto_recharge`（既定・おすすめ）/ `later`）+ 同意条件の提示**を追加（D29(i)。`consentTermsFor()` の値をそのまま表示 = 単一計算源）。`tickets` は aigenba T1002 で UI 撤去済みのため**出さない**（enum・validation では受理継続 = verbatim）。**disabled でブロックしない**（禁止事項 #8 / D4）

#### 波及変更

**TypeScript 型定義**
- `resources/js/types/billing.ts`: `AutoRechargeProps`（= `AutoRechargeShape` と exact 対）/ `AutoRechargeConsentTerms` 新規、`PurchaseTicketsPageProps` に `autoRechargeEnabled: boolean`、`BillingIndexProps` に `autoRecharge: AutoRechargeProps` を追加 ← aigenba `resources/js/types/Billing.ts`
- `resources/js/types/onboarding.ts`（P3 産出）: `OnboardingCheckoutShape` に `consentTerms` / `fundingChoices` を additive 追加
- `resources/js/types/notification.ts`: 通知種別 union に auto_recharge 系 4 種を追加

**DTO / JsonResource**
- 新規 DTO 6 本（上記）。`AutoRechargeSettingsDto` は `@phpstan-type AutoRechargeShape` を持ち、**subscription 有無に依存せず常に非 null**（free 組織も対象）
- `PurchaseTicketsPageDto` に `autoRechargeEnabled` 追加（+ shape 更新）/ `OnboardingCheckoutDto` に `consentTerms: AutoRechargeConsentTermsShape` 追加（P3 が「フィールド名は aigenba と同一にし P8a は additive に足すだけ」と規定済み）
- `BillingController::index` の Inertia props に `autoRecharge` 追加（**DTO 経由。`response()->json()` 直書きなし**）。JsonResource の新設なし（auto-recharge は API 公開面を持たない）

**Inertia props**: `Billing/Index` に `autoRecharge: AutoRechargeShape` / `Billing/PurchaseTickets` に `autoRechargeEnabled: bool` / `Onboarding/Checkout` に `consentTerms`。

**P9 への申し送り（D29(ii)。未割当を残さない）**: P9 の DoD に **T1004 一式**（`billing_checkout_sessions.{funding_choice, pm_reuse_dispatched_at}` additive 追加 / `ReuseSubscriptionPaymentMethodJob` / `AutoRechargeService::{applyReusedPaymentMethod, isAutoEnablePending 呼び出し, hasRecentAutoRechargeFundedSignup}` / `AutoRechargeGatewayInterface::resolveSubscriptionPaymentMethod` / `settingsFor.setupPending` の (b) 条件 / 着地 flash 分岐 / **`consent_version` を `'v2'` へ改定**）を記載する。**`AutoRechargeSettingsDto` の shape は P8a で既に aigenba verbatim（`pendingAutoEnable` / `setupPending` を保持）**のため、P9 は DTO を変更せず配線のみで済む。

**テストファイル（新規）**
`tests/Feature/Billing/{AutoRechargeServiceTest,AutoRechargeEndpointTest,AutoRechargeWebhookTest,AutoRechargeReconcileTest,AutoRechargeTriggerTest,AutoRechargePreConsentTest,TicketAutoRechargeModelTest}.php` / `tests/js/components/features/billing/AutoRechargeCard.test.ts` + `tests/js/support/autoRechargeProps.ts`（aigenba 同名を移植）
（参考: aigenba 側対応 `tests/Feature/Billing/{AutoRechargeServiceTest,AutoRechargeEndpointTest,AutoRechargeWebhookTest,AutoRechargeReconcileTest,AutoRechargeAutoEnableTest,TicketServiceAutoRechargeGrantTest,TicketAutoRechargeModelTest}.php` / `tests/Feature/Onboarding/ActivatePersonalEndpointTest.php`）

**テストファイル（更新。削除しない）**
- `tests/Feature/Billing/TicketLedgerTest.php` — `reserve()` に trigger dispatch が増える（`Queue::fake()` 追加。**既存の低残高通知期待は維持**）
- `tests/Feature/Billing/BillingPageTest.php` — Index props に `autoRecharge` 追加
- `tests/Feature/Billing/TicketRefundClawbackTest.php` — invoice アンカー付与（`stripe_invoice_id`）の逆仕訳ケース追加
- `tests/Feature/Billing/{WebhookIdempotencyTest,TicketPurchaseWebhookTest}.php` — `invoice.paid` の auto_recharge 分岐追加に伴う期待更新
- `tests/Feature/Billing/BillingNotificationDispatchTest.php` — 新 4 種の dispatch 期待
- `tests/Feature/Onboarding/ActivatePersonalTest.php`（P3 産出）— `funding_choice` 省略時は **dashboard 着地のまま**（既存期待不変）+ `auto_recharge` 分岐を追加
- `tests/Architecture/{MassAssignmentSafetyTest,FormRequestProhibitedKeyTest}.php` — inventory に新 Model 2 / 新 FormRequest 2 が乗る
- `tests/Feature/Providers/FakeExternalsServiceProviderTest.php` — 3 本目の gateway bind の期待追加（D31）

#### 主要な契約

**冪等キー（全経路の合流点）**

| アンカー | キー | 保証 |
|---|---|---|
| 付与 | `recharge:{stripeInvoiceId}`（`ticket_ledger_entries.idempotency_key` UNIQUE） | webhook / 同期 pay / リコンサイルのどれが先でも **1 invoice = 1 回付与** |
| Stripe 呼び出し | `idempotencyKeyBase = "auto-recharge:{attempt_ulid}"` | invoice create / pay の再送で同一 invoice に収束（プロセス死からの復帰でも二重 invoice を作らない） |
| カード登録 | `auto-recharge-setup:{attemptToken}`（`billing_checkout_sessions.idempotency_key` / `attempt_token` UNIQUE + Stripe 冪等キー） | 二重 submit で SetupPaymentMethod 台帳を増殖させない（`setupAttemptToken()` が session 保持 ULID を再利用） |
| pending | partial unique `tar_attempts_org_pending_unique` | **org あたり pending attempt は同時 1 つ**（アプリロックの最終防衛） |

**並行制御（契約）**
- ロック名 `billing:auto-recharge:{orgId}` / **TTL 180 秒**（`LOCK_TTL_SECONDS`）。**全ミューテータ**（`updateSettings` / `recordPreConsent` / `applySetupCompletion` / `executeAttempt`）が同一ロックを取るため、**停止後課金と部分適用が構造的に起こらない**。TTL は Stripe client timeout より十分長く取る。
- `createAttemptLocked` は `Organization` 行を `lockForUpdate()` してから残高評価〜起票する（**`reserve()` と同順の org 行ロック** = ロック順序の交差を作らない）。
- lock 取得失敗はバックグラウンド経路では **structured no-op**（`Log::info`）、ユーザー明示操作（`updateSettings` / `recordPreConsent`）のみ `CheckoutInProgressException`、webhook Job 経路（`applySetupCompletion`）は **`RuntimeException` で Job retry に乗せる**（snapshot 未反映を握り潰さない。verbatim）。

**`AutoRechargeService` 主要シグネチャ**（aigenba verbatim）

```php
public function isEnabledFor(Organization $org): bool
public function settingsFor(Organization $org, bool $canManage): AutoRechargeSettingsDto
public function updateSettings(Organization $org, User $user, bool $enabled, int $threshold, int $max, ?AutoRechargeConsentDto $consent): TicketAutoRecharge
public function consentTermsFor(): AutoRechargeConsentTermsDto
public function recordPreConsent(Organization $org, User $user, AutoRechargeConsentDto $consent): TicketAutoRecharge  // D29(i)
/** @return array{id: string, url: string|null} */
public function startSetupCheckout(Organization $org, User $user, string $successUrl, string $cancelUrl, string $attemptToken): array
public function maybeCreateAttempt(Organization $org): ?TicketAutoRechargeAttempt
public function executeAttempt(TicketAutoRechargeAttempt $attempt): void
public function recordSuccessfulCharge(Organization $org, TicketAutoRechargeAttempt $attempt, string $invoiceId, int $amountPaid, int $amountDue, ?string $paymentIntentId): void
public function handleChargeFailure(Organization $org, TicketAutoRechargeAttempt $attempt, ?string $failureCode, bool $requiresAction): void
public function terminateAndFail(Organization $org, TicketAutoRechargeAttempt $attempt): void
public function terminateAndCancel(TicketAutoRechargeAttempt $attempt): void
/** @return array{recovered_paid: int, retried: int, sca_reminded: int, expired: int, triggered: int} */
public function reconcile(): array
public function applySetupCompletion(Organization $org, string $paymentMethodId): bool
public function isAutoEnablePending(Organization $org): bool
```

**`AutoRechargeGatewayInterface`（D31。8 メソッド）**

```php
namespace App\Services\Billing\Contracts;

interface AutoRechargeGatewayInterface {
    /** @param array<string, string> $metadata @return array{id: string, url: string|null} */
    public function createSetupCheckout(Organization $org, string $successUrl, string $cancelUrl, array $metadata, string $idempotencyKey): array;
    /** @param array<string, string> $metadata  purpose / organization_id / recharge_attempt_ulid 必須 */
    public function createAutoRechargeInvoice(Organization $org, string $priceId, int $quantity, array $metadata, string $idempotencyKeyBase): string;
    public function payOffSessionInvoice(string $invoiceId, string $idempotencyKeyBase): OffSessionChargeResultDto;
    public function terminateInvoice(string $invoiceId): void;          // open→void / draft→delete。paid は例外
    public function retrieveInvoiceState(string $invoiceId): InvoiceStateDto;  // 不在は status='deleted'
    public function getDefaultPaymentMethodState(Organization $org): DefaultPaymentMethodDto;
    public function resolveSetupIntentPaymentMethod(string $setupIntentId): string;
    public function setDefaultPaymentMethod(Organization $org, string $paymentMethodId): void;
}
```

**AI-CUE 接地のための 3 点の差分**（機械移植できない箇所。いずれも実コード由来）

1. **trigger 点は `commit` ではなく `reserve`**。aigenba は `TicketService::commit` で `-1` が書かれた経路のみ発火する（`TicketService.php:558-566`）。**AI-CUE は `balance() = SUM(delta) − SUM(reserved)` のため実効残高が減るのは `reserve`、`commit` は拘束 −amount と台帳 −amount が相殺して balance 不変**（`TicketLedgerService.php:270-280` の docblock が明示）。よって `AutoRechargeTriggerJob::dispatch` は **`reserve()` の `DB::afterCommit`（`TicketLedgerService.php:277-279`）に、既存 `notifyTicketBalanceLow` と同居**させる。audit `ticket-charge-9` が「同じ『残高が減った』イベントへの応答」と両者を同一点として記録しており、これが接地された対応点。閾値判定は Job 側で再評価するため過剰 dispatch は無害（pending 検査 + partial unique が吸収）。
2. **`grantAutoRecharge` は ledger インライン 1 本書き**（**D30**）。
   ```php
   public function grantAutoRecharge(Organization $org, int $count, string $stripeInvoiceId, int $amount, ?string $paymentIntentId): void
   // Assert::greaterThan($count, 0); Assert::greaterThanEq($amount, 0);  // credit balance 全額適用で 0 は正当
   // insertIdempotent($org, "recharge:{$stripeInvoiceId}", [
   //   delta: $count, kind: Grant, source: TicketSource::Purchased, granted_at: now, expires_at: null,
   //   stripe_invoice_id: $stripeInvoiceId, payment_intent_id: $paymentIntentId, purchase_amount: $amount ])
   ```
   **clawback は `payment_intent_id` で引く**（`clawbackPurchasedByPaymentIntent`）ため、auto-recharge invoice の PI を書けば既存の返金経路がそのまま効く。PI が webhook 欠落で null のときは aigenba と同型の **null→値の単調 backfill のみ**（値→別値の上書きはしない = 冪等・改竄防止）を ledger 行に対して行う。
3. **`resolveVolumeTier` / `PURCHASE_MAX_COUNT` の出典**。aigenba は `TicketService::PURCHASE_MAX_COUNT` / `resolveVolumeTier` / `currentUnitPriceAmount` / `volumeTiersForDisplay`。AI-CUE は **`TicketVolumePrice::PURCHASE_MAX_COUNT`(=1000) / `PURCHASE_MIN_COUNT`(=1)**（`app/Models/Billing/TicketVolumePrice.php:44,47`）と **`TicketVolumePrice::currentTierFor(int $count): TicketVolumeTier`**（`:72`）、**`TicketPricingService::{volumeTiersForDisplay,spotUnitAmount}`**（`:27,:52`）。invoice の `priceId` は `TicketVolumeTier::stripePriceId`、金額検証は `TicketVolumeTier::unitAmount`。`config('billing.auto_recharge.max_count')` は `TicketVolumePrice::PURCHASE_MAX_COUNT` と**単一真実源で揃える**（両者とも 1000 = aigenba 既定値と一致）。

**`BillingCheckoutSession` の最初の writer は P8a になる（P2 との契約）**
`startSetupCheckout` が `intent=SetupPaymentMethod` / `status=pending` の行を書く。`BillingAccess::state()` は **intent を見ない**（aigenba verbatim）が、setup 導線への到達には必ず **`ActiveFreePlan`（activate-personal 完了済み）または `Subscribed`** が先行するため、`state()` の分岐 2/1 で確定し **分岐 4（`PendingCheckout`）には落ちない**（aigenba でも同じ理由で不到達）。**`state()` は改変しない**。この不変条件は回帰テストで固定する。

**amount cross-check（fail-closed）**
`recordSuccessfulCharge` は `attempt.unit_amount * attempt.quantity === invoice.amount_due` を検証し、不一致は `RuntimeException`。**照合対象は `amount_due` であって `amount_paid` ではない**（customer credit balance 適用で `amount_paid < amount_due` は正当）。付与額（`purchase_amount`）には**実回収額 `amount_paid`** を記録する。

**状態機械（終端保証）**
`pending → paid`（冪等付与後）/ `pending → failed`（invoice void/delete **成功後のみ**。`failure_count+1`）/ `pending → canceled`（終端成功後のみ。`failure_count` 増分なし）。**open invoice を残したまま終端しない** = 遅延支払いによる二重課金・二重付与の構造的排除。invoice 終端に失敗したら pending 維持 → リコンサイルが再試行。SCA（`authentication_required`）は**終端させない**（pending 維持 + 日次リマインダ。`pending_expiry_hours` 超過で failed）。

**再同意判定（単一述語）**
`reconsentRequiredFor(TicketAutoRecharge $config, int $max): bool` を **UI 表示（`settingsFor.requiresReconsent`）/ 設定更新（`updateSettings.needsConsent`）/ 自動有効化（`autoEnableEligible`）/ attempt 起票停止（`createAttemptLocked`）の 4 箇所で共有**する。条件 = version 不一致 ∨ 同意記録欠落 ∨ `$max > consented_max_count` ∨ 現行カタログ最大請求額 > `consented_max_amount`。**同意金額は必ずサーバ再計算**（`TicketVolumePrice::currentTierFor($max)->unitAmount * $max`）。client hidden の金額は信用しない（`AutoRechargeConsentDto` は `version` のみを受ける）。

**事前同意 → 自動有効化（D29(i)。fail-closed）**
`recordPreConsent` は `enabled=false` のまま同意証跡のみ記録し、**稼働中設定（`enabled=true`）は上書きしない** / **`disabled_reason` を消さない** / **PM snapshot が既にある row を enabled にしない**（= `pendingAutoEnable` も false。有効化は請求ページの既存 UI に委ねる）。`autoEnableEligible($config)` = `! enabled && disabled_reason === null && consented_at !== null && ! reconsentRequiredFor($config, $config->max_count)`。`applySetupCompletion` は同一 org lock 内で PM snapshot を書き、`autoEnableEligible` のときのみ `enabled=true` + `failure_count=0` に遷移して `AutoRechargeEnabled` を通知する（**通知失敗で webhook Job を落とさない** = `report()` で握る。verbatim）。`pendingAutoEnable` の PM 有無判定は**必ず local snapshot（`stripe_payment_method_id`）**で行う（gateway の default PM を見ると `setDefaultPaymentMethod` 後〜snapshot 反映前の窓で同意ダイアログが誤オープンする）。

**quantity 確定**
`quantity = min($config->max_count - availableTrueBalance($org), TicketVolumePrice::PURCHASE_MAX_COUNT)`、`Assert::greaterThan($quantity, 0)`。attempt 作成時に**一度だけ**確定し以降 `attempt.quantity` が真実源。`availableTrueBalance` が構造的に非負（P5 の per-source `max(...,0)`）であることが `quantity <= max_count` = 同意上限 invariant の根拠。**P5 側 docblock に「変更時は AutoRechargeService の契約も見直す」旨を追記**する。

**webhook 分岐（`StripeWebhookProcessor`）**
現行 `invoice.paid` は `GRANTING_BILLING_REASONS = ['subscription_create','subscription_cycle']` の allowlist で弾くため、auto-recharge invoice（`billing_reason='manual'`）は**月次付与に誤混入しない**（既存ガードで安全。D28 で付与枚数も 0）。新たに `metadata.purpose === 'auto_recharge'` かつ `metadata.recharge_attempt_ulid` を持つ invoice を `recordSuccessfulCharge` へ、`invoice.payment_failed` を `HandleAutoRechargeChargeFailureJob` へ、`checkout.session.completed`（`intent=SetupPaymentMethod`）を `SetDefaultPaymentMethodJob` へ振る分岐を追加。**metadata は照合専用**（org 解決・認可には使わない = 既存 `grantPurchasedTickets` の tenant キー不信規約 / 不変条件 #1 に従う）。**外向き Stripe API は webhook 同期処理から Job へ退避**（aigenba T710 invariant と AI-CUE の既存 webhook 規約が一致）。

**通知 dedup**
`AutoRechargeFailed` / `AutoRechargeDisabled` / `AutoRechargeEnabled` → `sendOnce($org, $type, invoiceId: "recharge:{$attempt->attempt_ulid}", ...)`（`sendOnce` は `Assert::stringNotEmpty($invoiceId)` のため invoice 未作成でもキーが立つ ULID を使う）。`AutoRechargeActionRequired` → `sendReminderOnce($org, $type, dedupKey: "auto_recharge_sca:{$invoiceId}:{JST Y-m-d}", ...)`（日次で再通知 = 放置失効の防止）。**低残高通知（`notifyTicketBalanceLow`）は無改変で併存**（既定 off の opt-in のため既存挙動は変わらない。AI-CUE 独自の抑制ロジックは発明しない）。

**ルート / 認可**
```
POST /billing/auto-recharge        → billing.auto-recharge.update   Gate::authorize('manageBilling', $org)
POST /billing/auto-recharge/setup  → billing.auto-recharge.setup    Gate::authorize('manageBilling', $org)
```
Gate ability 名は **AI-CUE の `manageBilling`**（`OrganizationPolicy::manageBilling`。既存 `BillingController.php:75,101` と同一。P3 の adaptation 規約）。permission 文字列は P2 の `BillingPermissionService::PERMISSION_MANAGE_BILLING = 'manage-billing'`。両 route とも課金ゲート allowlist（既存 `billing.*` と同扱い）。閲覧（Index の card 表示）は組織メンバー全員、変更は owner/admin。

**D20: リコンサイルの監視（DoD 必須。「注意喚起」で終わらせない）**

**既存監視への接続確認（実施済み）**: AI-CUE に scheduler 失敗の専用アラート機構は**存在しない**（`routes/console.php` / `app/Console/Commands/**` / `bootstrap/app.php` に `onFailure` / 外形監視の実装は 0 件）。**唯一の運用アラート経路は `report()`**（`docs/architecture.md:207`「attempts 上限 (8) に到達すると terminal-ack + `report()`（運用アラート）」）。よって本フェーズは**その既存経路へ接続する**（新機構を発明しない）。DoD:

```php
// routes/console.php（既存「課金 daily バッチ」ブロックの隣）
Schedule::command('billing:reconcile-auto-recharge')
    ->everyFifteenMinutes()->onOneServer()->withoutOverlapping()
    ->onFailure(static fn () => report(new RuntimeException(
        'billing:reconcile-auto-recharge 失敗 — 資金回収済み・チケット未付与が滞留する可能性',
    )));
```
1. 上記 `onFailure` → `report()` 配線（`ReconcileAutoRechargeAttempts` 本体は **verbatim**。lock timeout は `Log::warning` + exit 1 = aigenba のまま）。
2. `docs/architecture.md` の**監視対象リストへ必須項目として登録**（既存 L138/150/266 の様式）: コマンド名・実行間隔（15 分）・**失敗/停止の意味（webhook が `MAX_PROCESSING_ATTEMPTS=8` で恒久 drop した「課金済み・付与なし」の唯一の回収経路）**・滞留の観測点（`ticket_auto_recharge_attempts.status='pending'` の滞留件数）。
3. 回帰テスト（下記テスト計画）で **scheduler 登録そのもの**（コマンド + cron 式 `*/15 * * * *`）を固定する。

#### PHPStan 適合チェック（level 10 / widen・baseline 禁止）

- **`reconcile(): array` は `@return array{recovered_paid: int, retried: int, sca_reminded: int, expired: int, triggered: int}`** を付し、`ReconcileAutoRechargeAttempts::handle` 側は `Cache::lock(...)->block(5, fn (): array => ...)` の戻りが `mixed` になるため **`/** @var array{...} $stats */` で narrowing**（aigenba 同型）。
- **`Cache::lock()->block()` のクロージャ戻り値は `mixed`**。`updateSettings`（`TicketAutoRecharge`）/ `recordPreConsent`（`TicketAutoRecharge`）/ `applySetupCompletion`（`bool`）/ `maybeCreateAttempt` / `executeAttempt` の各所で `/** @var T $result */` + `Assert` により narrowing。
- **`$attempt->organization` は `BelongsTo` の nullable 解決**のため `Assert::isInstanceOf($org, Organization::class)` で narrowing（`reconcile` ループ / `executeAttempt`）。`$attempt->created_at` は `Carbon|null` → `Assert::notNull` 後に `CarbonImmutable::instance()`。
- **`OffSessionChargeResultDto::$amountPaid` / `$amountDue` は `int|null`**（Stripe 応答由来）→ `Assert::integer()` で narrowing してから `recordSuccessfulCharge(int, int)` へ渡す。**戻り型に nullable を漏らさない**。
- **`config()` 戻り値は `mixed`** → `TicketLedgerService` が使う **`config()->integer('billing.…')` に揃える**（`intConfig` helper を新設せず既存規約に寄せる）。`currentConsentVersion(): string` のみ `config()->string(...)` + 空文字ガード。
- **`SignupFundingChoice` は enum で比較**（`$funding === SignupFundingChoice::AutoRecharge`）。`$request->validated('funding_choice')` は `mixed` → `is_string()` 判定後に `::from()`（aigenba T1002 Codex R3 と同じ理由 = 分岐網羅を PHPStan に見せる）。
- **generics**: `TicketAutoRecharge` / `TicketAutoRechargeAttempt` に `/** @use HasFactory<TicketAutoRechargeFactory> */`、`organization(): BelongsTo` に `@return BelongsTo<Organization, $this>`。Factory は `/** @extends Factory<TicketAutoRecharge> */`。
- **DTO 返却**: `settingsFor` / `consentTermsFor` は DTO を返し Controller は `->toArray()` を Inertia props に渡す（`response()->json()` 直書きなし）。`@phpstan-type AutoRechargeShape` / `@phpstan-import-type PurchaseTierShape from PurchaseTierDto` で TS 側と shape を固定。
- **`disabled_reason`** は `AutoRechargeDisabledReason|null` cast → DTO へは `$config?->disabled_reason?->value`（`string|null`）。
- **`isUniqueViolation(QueryException $e): bool`** は driver 別 SQLSTATE（`23505` pgsql / sqlite）判定。`$e->getCode()` は `mixed` のため文字列比較前に narrowing。

#### テスト計画（テストファースト。既存テストは削除せず期待を更新）

**先に red を作るテスト**

`tests/Feature/Billing/AutoRechargeServiceTest.php`
- `既定は off` — 設定行が無い org で `isEnabledFor` false / `settingsFor.enabled` false / trigger しても attempt が起票されない（**opt-in の回帰**）
- `有効化は fail-closed` — default PM 無しで `updateSettings(enabled: true)` → `ValidationException`（422）/ 同意 version 不一致 → `ValidationException`
- `同意金額はサーバ再計算` — client が偽の金額を送っても `consented_max_amount = currentTierFor($max)->unitAmount * $max`
- `再同意の 4 箇所一致` — 価格改定後に `settingsFor.requiresReconsent === true` **かつ** `createAttemptLocked` が起票しない **かつ** `autoEnableEligible` が false（UI 文言と実挙動の一致）
- `quantity は attempt 作成時に一度だけ確定` — 作成後に残高が動いても `attempt.quantity` 不変
- `停止後課金の禁止` — pending attempt がある状態で `updateSettings(enabled: false)` → invoice 終端 + `canceled` 遷移、以降 `executeAttempt` は no-op
- `連続失敗で自動無効化` — `max_failures`(3) 回目の failed で `enabled=false` + `disabled_reason=payment_failures` + `AutoRechargeDisabled` 通知
- `SCA は終端しない` — `requires_action` で pending 維持 + `failure_count` 増えない + `AutoRechargeActionRequired` 通知

`tests/Feature/Billing/AutoRechargePreConsentTest.php`（**D29(i)**）
- `activate-personal + funding_choice=auto_recharge` — `recordPreConsent` が `enabled=false` + 同意 4 列を記録し、setup Checkout へ `Inertia::location`
- `consent_version 欠落 / 現行版不一致 → 422`（`ActivatePersonalRequest` で activate 前に fail-closed）
- `二重 submit で SetupPaymentMethod 台帳が増殖しない`（`setupAttemptToken` の session 安定化 + `attempt_token` unique）
- `カード登録完了で自動有効化` — `applySetupCompletion` → `enabled=true` + `AutoRechargeEnabled` 通知（1 回だけ）
- **`fail-closed の 3 条件`** — 稼働中設定は上書きされない / `disabled_reason` 保持の row は自動有効化しない / **PM snapshot 済み row は `pendingAutoEnable=false`**
- `funding_choice=later（既定）は dashboard 着地のまま`（P3 の既存挙動が変わらない回帰）/ `funding_choice=tickets` は `billing.tickets.show` へ
- **`setup session は state() を PendingCheckout にしない`** — activate-personal 済み org は `ActiveFreePlan` 優先（P2 契約の回帰）

`tests/Feature/Billing/AutoRechargeTriggerTest.php`（**AI-CUE 固有の要**）
- `reserve で閾値クロス → AutoRechargeTriggerJob が dispatch される`（`Queue::fake()`）
- **`既存の低残高通知が消えていない`** — 同一 reserve で `notifyTicketBalanceLow` も発火する（**parity の名での機能後退を防ぐ回帰**。audit `ticket-charge-9`）
- `commit では dispatch されない`（balance 不変のため）— AI-CUE 特有の意味論を固定
- `reserve が rollback したら dispatch されない`（`afterCommit` の保証）
- **`amount ベース reserve が壊れていない`** — 可変コスト（`reserve($org, 7)`）が従来どおり成立（D5 のドメイン境界の回帰）
- **`reserve→commit/release の 2 フェーズが維持されている`**（AGENTS.md 不変条件 #7）

`tests/Feature/Billing/AutoRechargeWebhookTest.php`
- **`二重課金・二重付与しない`** — 同一 invoice の `invoice.paid` を 2 回処理しても ledger は 1 行（`recharge:{invoiceId}` 冪等）
- `webhook と同期 pay の競合` — どちらが先でも付与 1 回、`attempt.status=paid` 1 回
- `auto-recharge invoice が月次付与に混入しない` — `billing_reason='manual'` の invoice.paid で `grantMonthlyTickets` が呼ばれない（既存 allowlist の回帰）
- `amount_due 不一致で fail-closed` — `RuntimeException` + 付与なし
- `amount_paid < amount_due（credit balance 適用）は正当` — 付与成立 + `purchase_amount = amount_paid`
- **`PI の単調 backfill`** — PI 欠落で付与された行に後続再送で PI が載る（値→別値の上書きはしない）

`tests/Feature/Billing/AutoRechargeReconcileTest.php`（**5 分岐すべて + D20**）
- (i) invoice 未作成 + 15 分超 → 再実行（`retried`）
- (ii) Stripe 上 paid だが webhook 未着 → **付与回収**（`recovered_paid`）。**terminal drop の唯一のセーフティネット**
- (iii) SCA 待ち → 日次リマインダ（`sca_reminded`）、同日 2 回目は dedup で送られない
- (iv) `pending_expiry_hours` 超過 → SCA は failed / それ以外は canceled（`expired`）
- (v) enabled + 閾値割れ + pending なし → 取りこぼし起票（`triggered`）
- `1 attempt の例外が他 org の回収を止めない`（隔離）/ `lock 競合で exit 1`（`LockTimeoutException` 経路 + `Log::warning`）
- **D20: scheduler 登録の回帰** — `app(Schedule::class)->events()` に `billing:reconcile-auto-recharge` が **`*/15 * * * *`** で登録されている（`getExpression()` / コマンド文字列で照合）

`tests/Feature/Billing/AutoRechargeEndpointTest.php`
- `manageBilling を持たない member は 403`（update / setup 両方）/ `他 org の設定を触れない`（IDOR）
- `enabled=true で consent_version 欠落 → 422` / `max_count <= threshold_count → 422` / `max_count > config max → 422`
- setup 着地が 303 + flash（GET で副作用を起こさない）

`tests/Feature/Billing/TicketAutoRechargeModelTest.php`
- **`org に pending は同時 1 つ`** — 並行起票で `tar_attempts_org_pending_unique` が効き、後着は **500 にせず no-op**（`isUniqueViolation` 吸収）
- `max_count > threshold_count` CHECK（pgsql のみ。sqlite は skip）/ append-only / mass assignment 安全性

`tests/js/components/features/billing/AutoRechargeCard.test.ts` / `tests/js/pages/OnboardingCheckout.test.ts`
- 既定 off の表示 / PM 未登録時はカード登録 CTA / `requiresReconsent` 時に「再同意まで自動購入は行われません」/ `pendingAutoEnable` 時に「カード登録完了で自動的に有効になります」/ `canManage=false` で操作不可（**disabled にしない** = 押下時にエラー表示。禁止事項 #8）
- Onboarding の funding 2 択（`auto_recharge` 既定 + `later`）と同意条件の表示値が `consentTerms` と一致する
- `tests/js/support/autoRechargeProps.ts` に props factory（aigenba 同名を移植）

**既存テストの更新（削除禁止・期待の更新のみ）**: `TicketLedgerTest`（`Queue::fake()` 追加。**低残高通知期待はそのまま残す**）/ `BillingPageTest`（`autoRecharge` props）/ `TicketRefundClawbackTest`（`stripe_invoice_id` 経由付与の返金按分ケース追加。既存 checkout 経路の期待は不変）/ `WebhookIdempotencyTest`・`TicketPurchaseWebhookTest`（`invoice.paid` 分岐）/ `BillingNotificationDispatchTest`（新 4 type）/ `ActivatePersonalTest`（`funding_choice` 省略時の既存期待は不変）/ `FakeExternalsServiceProviderTest`（3 本目の bind）/ arch テスト inventory。

**arch テスト（UI 分）**: `AutoRechargeCard.svelte` / `Billing/Index.svelte` / `Onboarding/Checkout.svelte` が `page-shell-structure` / `ds-purity`（token のみ・hex 直書き禁止）/ `atomic-import-graph` / `lucide-scoped-import` を満たす。

**共通 DoD**: Factory 必須（手組み禁止）/ 個別 `DatabaseTransactions` 不使用（`RefreshDatabase` グローバル・`--parallel`）/ Stripe は `FakeAutoRechargeGateway` を bind（実 API を撃たない）。

#### リスク

| リスク | 緩和 |
|---|---|
| **二重課金（最重大）** | 3 層: (1) Stripe idempotency key `auto-recharge:{ulid}` で invoice create/pay が収束、(2) `tar_attempts_org_pending_unique` で org あたり pending 1 つ、(3) 付与は `recharge:{invoiceId}` の ledger UNIQUE。加えて **failed/canceled は invoice 終端（void/delete）成功後のみ** = open invoice を残して終端しないため遅延成功による二重課金が構造的に起きない |
| **停止後課金** | `updateSettings` / `recordPreConsent` / `applySetupCompletion` / `executeAttempt` が**同一ロック** `billing:auto-recharge:{orgId}`。lock 内で `enabled` を再確認してから invoice 作成 → 停止側は実行完了後にしか pending を終端できない |
| **課金済み・付与なし（webhook terminal drop）** | `MAX_PROCESSING_ATTEMPTS = 8` で webhook は恒久 drop し得る。**リコンサイル (ii) が唯一のセーフティネット**。scheduler 15 分毎 + `onOneServer()` + `withoutOverlapping()`。**D20 の監視 DoD（`onFailure` → `report()` / `docs/architecture.md` の監視対象登録 / scheduler 登録の回帰テスト）を満たさない限り本フェーズは完了しない** |
| **迷子 invoice（プロセス死）** | `stripe_invoice_id` の永続化を `pay` より**必ず前**に行う。復帰時は同一 key base で Stripe 冪等により同一 invoice が返る |
| **trigger 点の変更（commit→reserve）が aigenba と非対称** | 意図的。AI-CUE の `balance()` は reserve で減り commit で不変（実コード docblock + audit `ticket-charge-9`）。commit に置くと**閾値クロスを取り逃す**。`AutoRechargeTriggerTest` で両方向（reserve で発火 / commit で発火しない）を固定 |
| **低残高通知との二重通知** | **aigenba のまま両立**（既定 off の opt-in のため既存挙動は無変更）。**独自の抑制ロジックは発明しない**（audit `ticket-charge-9`「AI-CUE 固有の低残高通知は parity の名で削除しない」） |
| **`ticket_purchases` を持たない差分（D30）** | **F3（台帳の置換ではない）の帰結**であり P8a 単独の逸脱ではない。`payment_intent_id` + `purchase_amount` + 新 `stripe_invoice_id` で返金逆引きが成立することを `TicketRefundClawbackTest` で固定。両建てが無いため片肺検証は構造的に不要 |
| **signup-funding の 2 分割（D29）** | P8a 時点では `pendingAutoEnable` は「setup Checkout 経路」でのみ true になり、サブスク決済カード流用（T1004）は P9 まで働かない。**`consent_version='v1'` が「カード登録経路のみ」を意味する**ため、同意文言と実挙動は P8a 時点で一致する（P9 で v2 へ上げると既存同意は自動失効 → 再同意 = aigenba の版管理契約どおり）。**P9 の DoD に T1004 一式と version 改定を明記**することで未割当を残さない |
| **`BillingCheckoutSession` の最初の writer になる** | `state()` は intent を見ない（verbatim）が、setup 導線の到達には `ActiveFreePlan` / `Subscribed` が先行するため `PendingCheckout` には落ちない。**`state()` を改変せず**回帰テストで固定する |
| **P5 依存（`availableTrueBalance`）** | P5 未達だと閾値判定が保守的近似（過小評価）になり**過剰補充**する。P5 マージ後に着手する順序を DoD に固定。P5 側 docblock に本契約への依存を明記 |
| **ロック TTL 失効による直列化の破れ** | TTL 180 秒（Stripe client timeout より十分長い）。`block` 待機は短く（3〜10 秒）し、競合時は no-op → リコンサイルが再試行 |
| **消費者保護 / 特商法** | 同意文言の実質（開始残高・補充枚数・上限額の提示形式・停止方法・即時課金可能性・**カードの取得手段**）を変える改定では **`consent_version` を上げる** = `reconsentRequiredFor` 経由で既存同意が自動失効し自動購入が停止する（fail-closed）。**既定値・文言・版番号は aigenba verbatim**（D29-b / 原則 3） |
| **rollback** | 全変更が additive（新テーブル 2 + 列 1 + 新 route/Job/Command）。**コード revert で即時復帰** — 既定 off のため設定行が存在せず、`reserve` の dispatch も消える。pending attempt が残る場合のみ、revert 前に `billing:reconcile-auto-recharge` を 1 回流して収束させる（資金回収済みは必ずチケットになる） |

---

