## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則
## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## セキュリティ不変条件(アプリ都合で緩めない)
【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

# 役割: 実装レビュー (impl-review / 最終ラウンド)

あなたはシニア Laravel/Svelte エンジニアとして、TODO T015「bug-hunt 基盤整備」の実装 diff (branch todo/T015 vs main) を最終レビューする。

## 背景

- T015 の内容: F-05 Stripe fake 配線 (SubscriptionCheckoutGateway 抽象化 + FakeExternalsServiceProvider)、F-13 Filament アセット、bughunt 用 seeder (BughuntBillingSeeder / subscription)。
- 前ラウンドのレビューで Critical / Warning は 0 件だった。
- その後の変更点は 1 箇所のみ: `tests/Feature/Capture/TakeObjectStorageTest.php` の presigned URL 期限アサーションの flake 修正。
  - 旧: `X-Amz-Expires=1800` の固定文字列包含 (SDK 内部 time() とテスト側 now() の間に S3 クライアント初回ビルド遅延が入り秒境界を跨ぐと 1799 になり fail)
  - 新: URL の `X-Amz-Date` + `X-Amz-Expires` を抽出し「失効時刻 = 渡した expiresAt」を厳密検証 (決定的かつ従来より強い不変条件)

## レビュー観点

1. flake 修正がテストの検証力を弱めていないか (widen になっていないか)
2. T015 diff 全体に残存する Critical (セキュリティ不変条件違反・本番混入リスク・冪等性破壊) が無いか
3. bughunt 専用機構が本番環境で完全に no-op になっているか (ProductionEnvGuard 等)

## 出力形式

- `## 判定` (Critical 有無を明記)
- `## Critical` / `## Warning` / `## Suggestion` の各セクション (無ければ「なし」)
- 各指摘: タイトル / 対象ファイル:行 / 根拠 / 推奨対応

---

# レビュー対象 diff (branch todo/T015 vs main)

```diff
diff --git a/.env.bughunt.local.example b/.env.bughunt.local.example
index dcfbc59..200cbfc 100644
--- a/.env.bughunt.local.example
+++ b/.env.bughunt.local.example
@@ -50,8 +50,9 @@ CACHE_STORE=database
 QUEUE_CONNECTION=sync               # 非同期ジョブを同期実行 (探索の決定論性)
 
 # 外部サービス (LLM/Stripe/Captcha/SSO 等) を fake 化する capability flag。
-# config('testing.fake_externals') を通して fake セットを有効化する前提
-# (fake 基盤が未導入のテンプレートでは各 fake は no-op。導入後に有効化される)。
+# config('testing.fake_externals') を通して fake セットを有効化する
+# (Stripe: FakeExternalsServiceProvider が checkout/portal gateway を fake に bind。
+#  fake は決済せず中立帰還する。課金状態の正本は BughuntBillingSeeder)。
 TESTING_FAKE_EXTERNALS=true
 MAIL_MAILER=log
 
diff --git a/app/DataTransferObjects/Billing/ExternalBillingRedirect.php b/app/DataTransferObjects/Billing/ExternalBillingRedirect.php
new file mode 100644
index 0000000..bfc9675
--- /dev/null
+++ b/app/DataTransferObjects/Billing/ExternalBillingRedirect.php
@@ -0,0 +1,22 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+use Webmozart\Assert\Assert;
+
+/**
+ * 課金系外部ページ (Stripe Checkout / Customer Portal) への遷移先。
+ *
+ * gateway (SubscriptionCheckoutGateway) の戻り値契約。Response 化
+ * (Inertia::location) は Controller の責務で、gateway は URL のみ返す。
+ */
+final readonly class ExternalBillingRedirect
+{
+    public function __construct(
+        public string $url,
+    ) {
+        Assert::stringNotEmpty($url, '外部遷移先 URL が空です');
+    }
+}
diff --git a/app/Http/Controllers/Billing/BillingController.php b/app/Http/Controllers/Billing/BillingController.php
index 56cbff9..66a4405 100644
--- a/app/Http/Controllers/Billing/BillingController.php
+++ b/app/Http/Controllers/Billing/BillingController.php
@@ -10,7 +10,7 @@
 use App\Http\Requests\Billing\BillingCheckoutRequest;
 use App\Models\Billing\Plan;
 use App\Models\User;
-use App\Services\Billing\PortalConfigurationSpec;
+use App\Services\Billing\SubscriptionCheckoutGateway;
 use App\Services\Billing\TicketLedgerService;
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
@@ -65,8 +65,11 @@ public function index(Request $request, TicketLedgerService $tickets): Response
         ]);
     }
 
-    /** Stripe Checkout を開始し、Checkout URL へリダイレクトする */
-    public function checkout(BillingCheckoutRequest $request): SymfonyResponse|RedirectResponse
+    /**
+     * Stripe Checkout を開始し、Checkout URL へリダイレクトする
+     * (戻り型に RedirectResponse を含むのは price 不在時の back() 分岐のため)
+     */
+    public function checkout(BillingCheckoutRequest $request, SubscriptionCheckoutGateway $gateway): SymfonyResponse|RedirectResponse
     {
         $organization = $this->resolveCurrentOrganization($request);
         Gate::authorize('manageBilling', $organization);
@@ -80,32 +83,23 @@ public function checkout(BillingCheckoutRequest $request): SymfonyResponse|Redir
             return back()->with('error', '選択したプランは現在お申し込みいただけません。');
         }
 
-        $checkout = $organization
-            ->newSubscription('default', $price->stripe_price_id)
-            ->checkout([
-                'success_url' => route('billing.index'),
-                'cancel_url' => route('billing.index'),
-            ]);
-
-        $url = $checkout->asStripeCheckoutSession()->url;
-        Assert::string($url, 'Checkout Session に URL がありません (ui_mode: hosted のみ対応)');
+        $redirect = $gateway->createSubscriptionCheckout(
+            $organization,
+            $price->stripe_price_id,
+            route('billing.index'),
+            route('billing.index'),
+        );
 
         // 外部 URL への遷移は Inertia::location (full page redirect)
-        return Inertia::location($url);
+        return Inertia::location($redirect->url);
     }
 
     /** Stripe Customer Portal へリダイレクトする (支払い方法・解約の自己管理) */
-    public function portal(Request $request): SymfonyResponse
+    public function portal(Request $request, SubscriptionCheckoutGateway $gateway): SymfonyResponse
     {
         $organization = $this->resolveCurrentOrganization($request);
         Gate::authorize('manageBilling', $organization);
 
-        // configuration id (billing:ensure-portal-configuration で生成) が設定されていれば
-        // subscription_update 無効の spec 準拠 configuration で portal session を作る
-        // (未設定なら Dashboard 既定 configuration。PortalConfigurationSpec 参照)
-        return Inertia::location($organization->billingPortalUrl(
-            route('billing.index'),
-            PortalConfigurationSpec::sessionOptions(config('cashier.portal_configuration_id')),
-        ));
+        return Inertia::location($gateway->portalRedirect($organization, route('billing.index'))->url);
     }
 }
diff --git a/app/Providers/AppServiceProvider.php b/app/Providers/AppServiceProvider.php
index 8baf31f..9ab637f 100644
--- a/app/Providers/AppServiceProvider.php
+++ b/app/Providers/AppServiceProvider.php
@@ -19,8 +19,10 @@
 use App\Models\Organization;
 use App\Models\User;
 use App\Notifications\Channels\OrganizationScopedDatabaseChannel;
+use App\Services\Billing\CashierSubscriptionCheckoutGateway;
 use App\Services\Billing\CashierTicketCheckoutGateway;
 use App\Services\Billing\StripeWebhookProcessor;
+use App\Services\Billing\SubscriptionCheckoutGateway;
 use App\Services\Billing\TicketCheckoutGateway;
 use App\Services\Mail\Sns\AwsSnsSignatureVerifier;
 use App\Services\Mail\Sns\SnsSignatureVerifier;
@@ -103,6 +105,10 @@ public function register(): void
         // チケットスポット購入の Stripe Checkout 抽象 (T007)。テストは fake を bind する
         $this->app->bind(TicketCheckoutGateway::class, CashierTicketCheckoutGateway::class);
 
+        // サブスク Checkout / Customer Portal の Stripe 抽象。fake_externals 時は
+        // FakeExternalsServiceProvider が fake に rebind する (providers.php で後勝ち)
+        $this->app->bind(SubscriptionCheckoutGateway::class, CashierSubscriptionCheckoutGateway::class);
+
         // アプリ内通知 (T008): database channel を薄い拡張へ差し替え、AppNotification の
         // organization_id を notifications テーブルの first-class 列として書き込む
         // (ChannelManager::createDatabaseDriver は container 解決のため binding が効く。
diff --git a/app/Providers/FakeExternalsServiceProvider.php b/app/Providers/FakeExternalsServiceProvider.php
new file mode 100644
index 0000000..86a5077
--- /dev/null
+++ b/app/Providers/FakeExternalsServiceProvider.php
@@ -0,0 +1,49 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Providers;
+
+use App\Services\Billing\Fakes\FakeSubscriptionCheckoutGateway;
+use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;
+use App\Services\Billing\SubscriptionCheckoutGateway;
+use App\Services\Billing\TicketCheckoutGateway;
+use Illuminate\Support\Facades\Log;
+use Illuminate\Support\ServiceProvider;
+
+/**
+ * 外部サービス fake の配線 (config('testing.fake_externals') が capability flag)。
+ *
+ * bootstrap/providers.php で AppServiceProvider より後に登録する (後勝ち rebind)。
+ * fail-secure 二軸:
+ * 1. flag === true (既定 false = 完全 no-op)
+ * 2. 環境 allowlist (local / testing / bughunt.local)。denylist (非 production) ではなく
+ *    allowlist で倒す = staging 等の未知環境で flag が誤設定されても fake しない
+ *    (warning ログで検出可能にする)。production は加えて ProductionEnvGuard が
+ *    flag=true を deploy 時 fail-fast で拒否する (二重防御)。
+ */
+class FakeExternalsServiceProvider extends ServiceProvider
+{
+    /** fake bind を許可する環境 allowlist */
+    private const array ALLOWED_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];
+
+    public function register(): void
+    {
+        if (config('testing.fake_externals') !== true) {
+            return;
+        }
+
+        $environment = $this->app->environment();
+        if (! in_array($environment, self::ALLOWED_ENVIRONMENTS, true)) {
+            Log::warning('TESTING_FAKE_EXTERNALS=true ですが allowlist 外の環境のため fake を bind しません。', [
+                'environment' => $environment,
+            ]);
+
+            return;
+        }
+
+        // Stripe 到達点を fake へ rebind (課金状態の正本は BughuntBillingSeeder)
+        $this->app->bind(TicketCheckoutGateway::class, FakeTicketCheckoutGateway::class);
+        $this->app->bind(SubscriptionCheckoutGateway::class, FakeSubscriptionCheckoutGateway::class);
+    }
+}
diff --git a/app/Services/Billing/CashierSubscriptionCheckoutGateway.php b/app/Services/Billing/CashierSubscriptionCheckoutGateway.php
new file mode 100644
index 0000000..c125285
--- /dev/null
+++ b/app/Services/Billing/CashierSubscriptionCheckoutGateway.php
@@ -0,0 +1,47 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing;
+
+use App\DataTransferObjects\Billing\ExternalBillingRedirect;
+use App\Models\Organization;
+use Webmozart\Assert\Assert;
+
+/**
+ * SubscriptionCheckoutGateway の Cashier (Stripe SDK) 実装。
+ * ロジックは BillingController から移動 (挙動不変)。
+ * PortalConfigurationSpec は同一名前空間 (App\Services\Billing) のため use 不要。
+ */
+final class CashierSubscriptionCheckoutGateway implements SubscriptionCheckoutGateway
+{
+    public function createSubscriptionCheckout(
+        Organization $organization,
+        string $stripePriceId,
+        string $successUrl,
+        string $cancelUrl,
+    ): ExternalBillingRedirect {
+        $checkout = $organization
+            ->newSubscription('default', $stripePriceId)
+            ->checkout([
+                'success_url' => $successUrl,
+                'cancel_url' => $cancelUrl,
+            ]);
+
+        $url = $checkout->asStripeCheckoutSession()->url;
+        Assert::string($url, 'Checkout Session に URL がありません (ui_mode: hosted のみ対応)');
+
+        return new ExternalBillingRedirect($url);
+    }
+
+    public function portalRedirect(Organization $organization, string $returnUrl): ExternalBillingRedirect
+    {
+        // configuration id (billing:ensure-portal-configuration で生成) が設定されていれば
+        // subscription_update 無効の spec 準拠 configuration で portal session を作る
+        // (未設定なら Dashboard 既定 configuration。PortalConfigurationSpec 参照)
+        return new ExternalBillingRedirect($organization->billingPortalUrl(
+            $returnUrl,
+            PortalConfigurationSpec::sessionOptions(config('cashier.portal_configuration_id')),
+        ));
+    }
+}
diff --git a/app/Services/Billing/Fakes/FakeExternalUrl.php b/app/Services/Billing/Fakes/FakeExternalUrl.php
new file mode 100644
index 0000000..0946c35
--- /dev/null
+++ b/app/Services/Billing/Fakes/FakeExternalUrl.php
@@ -0,0 +1,24 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing\Fakes;
+
+use Webmozart\Assert\Assert;
+
+/**
+ * fake externals の中立帰還 URL (アプリ内画面 + 観測用 marker query)。
+ * marker はアプリが解釈しない (TicketCheckoutTest が purchased=false を固定)。
+ * bug-hunt のブラウザログから「外部ステップを skip した」ことを観測するためだけの query。
+ */
+final class FakeExternalUrl
+{
+    public const string MARKER = 'fake_external=stripe';
+
+    public static function neutralReturn(string $appUrl): string
+    {
+        Assert::stringNotEmpty($appUrl, '中立帰還先のアプリ内 URL が空です');
+
+        return $appUrl.(str_contains($appUrl, '?') ? '&' : '?').self::MARKER;
+    }
+}
diff --git a/app/Services/Billing/Fakes/FakeSubscriptionCheckoutGateway.php b/app/Services/Billing/Fakes/FakeSubscriptionCheckoutGateway.php
new file mode 100644
index 0000000..d144971
--- /dev/null
+++ b/app/Services/Billing/Fakes/FakeSubscriptionCheckoutGateway.php
@@ -0,0 +1,31 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing\Fakes;
+
+use App\DataTransferObjects\Billing\ExternalBillingRedirect;
+use App\Models\Organization;
+use App\Services\Billing\SubscriptionCheckoutGateway;
+
+/**
+ * SubscriptionCheckoutGateway の runtime fake (fake_externals 環境専用)。
+ * 契約は FakeTicketCheckoutGateway と同じ「中立帰還」。subscription 状態は変更しない
+ * (active subscription の正本は BughuntBillingSeeder)。
+ */
+final class FakeSubscriptionCheckoutGateway implements SubscriptionCheckoutGateway
+{
+    public function createSubscriptionCheckout(
+        Organization $organization,
+        string $stripePriceId,
+        string $successUrl,
+        string $cancelUrl,
+    ): ExternalBillingRedirect {
+        return new ExternalBillingRedirect(FakeExternalUrl::neutralReturn($cancelUrl));
+    }
+
+    public function portalRedirect(Organization $organization, string $returnUrl): ExternalBillingRedirect
+    {
+        return new ExternalBillingRedirect(FakeExternalUrl::neutralReturn($returnUrl));
+    }
+}
diff --git a/app/Services/Billing/Fakes/FakeTicketCheckoutGateway.php b/app/Services/Billing/Fakes/FakeTicketCheckoutGateway.php
new file mode 100644
index 0000000..5a75583
--- /dev/null
+++ b/app/Services/Billing/Fakes/FakeTicketCheckoutGateway.php
@@ -0,0 +1,54 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing\Fakes;
+
+use App\DataTransferObjects\Billing\CreatedCheckoutSession;
+use App\Models\Organization;
+use App\Services\Billing\TicketCheckoutGateway;
+use Carbon\CarbonImmutable;
+
+/**
+ * TicketCheckoutGateway の runtime fake (fake_externals 環境専用。Stripe に到達しない)。
+ *
+ * 契約 = 「外部ステップを skip した中立帰還」:
+ * - session id は idempotency key から決定的に導出 (Stripe の idempotency replay と同じ収束特性)
+ * - 遷移先はアプリ内帰還画面 ($cancelUrl) + 観測用 marker query `fake_external=stripe`。
+ *   アプリはこの query を一切解釈しない (purchased 偽装なし / cancel の意味付けもなし)
+ * - 決済・チケット付与・状態変更は一切行わない (課金状態の正本は BughuntBillingSeeder)
+ *
+ * テスト専用 spy (Tests\Support\FakeTicketCheckoutGateway) とは責務が異なる:
+ * spy は呼び出し記録と失敗注入を持つが、本クラスは無状態 stub (serve プロセスで動く前提)。
+ */
+final class FakeTicketCheckoutGateway implements TicketCheckoutGateway
+{
+    /**
+     * @param  array<string, string>  $metadata  照合専用 (fake は参照しない)
+     */
+    public function createTicketCheckout(
+        Organization $organization,
+        string $stripePriceId,
+        int $quantity,
+        string $successUrl,
+        string $cancelUrl,
+        string $idempotencyKey,
+        array $metadata,
+    ): CreatedCheckoutSession {
+        // idempotency key から決定的に導出 (同一 attempt の再送は同一 session に収束)。
+        // key の文字種・長さに依存しないよう sha256 の先頭 32 桁で固定長トークン化する
+        // (stripe_session_id 列・URL への混入安全性)
+        $token = substr(hash('sha256', $idempotencyKey), 0, 32);
+
+        return new CreatedCheckoutSession(
+            sessionId: "cs_bughuntfake_{$token}",
+            url: FakeExternalUrl::neutralReturn($cancelUrl),
+            expiresAt: CarbonImmutable::now()->addDay(), // Stripe hosted checkout の既定 24h に合わせる
+        );
+    }
+
+    public function expireCheckoutSession(string $sessionId): string
+    {
+        return 'expired';
+    }
+}
diff --git a/app/Services/Billing/SubscriptionCheckoutGateway.php b/app/Services/Billing/SubscriptionCheckoutGateway.php
new file mode 100644
index 0000000..866a415
--- /dev/null
+++ b/app/Services/Billing/SubscriptionCheckoutGateway.php
@@ -0,0 +1,33 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing;
+
+use App\DataTransferObjects\Billing\ExternalBillingRedirect;
+use App\Models\Organization;
+
+/**
+ * サブスクリプションの Stripe Checkout / Customer Portal 抽象
+ * (実装: CashierSubscriptionCheckoutGateway。fake_externals 時は fake を bind)。
+ * Stripe 呼び出しを本 interface に閉じ、Controller は戻り値 DTO の URL へ
+ * Inertia::location するのみ。
+ */
+interface SubscriptionCheckoutGateway
+{
+    /**
+     * subscription (type=default) の hosted Checkout Session を作り遷移先を返す。
+     */
+    public function createSubscriptionCheckout(
+        Organization $organization,
+        string $stripePriceId,
+        string $successUrl,
+        string $cancelUrl,
+    ): ExternalBillingRedirect;
+
+    /**
+     * Customer Portal セッションを作り遷移先を返す
+     * (configuration は PortalConfigurationSpec 準拠。実装側で解決する)。
+     */
+    public function portalRedirect(Organization $organization, string $returnUrl): ExternalBillingRedirect;
+}
diff --git a/app/Support/ProductionEnvGuard.php b/app/Support/ProductionEnvGuard.php
index a5f602f..5382a34 100644
--- a/app/Support/ProductionEnvGuard.php
+++ b/app/Support/ProductionEnvGuard.php
@@ -18,6 +18,7 @@
  * - APP_DEBUG=false (stack trace / 設定露出防止)
  * - SECURITY_HSTS_ENABLED / SECURITY_CSP_ENABLED=true (セキュリティヘッダ必須)
  * - DEBUG_LOGIN_USER / DEBUG_LOGIN_PASSWORD が空 (local 専用機構の誤投入防止)
+ * - TESTING_FAKE_EXTERNALS=false (外部 fake の本番混入防止)
  * - TrustHosts allowlist (Host header injection 防御の allowlist 非空・書式)
  */
 class ProductionEnvGuard
@@ -78,6 +79,14 @@ public function violations(): array
                 .'(both are local-dev only; presence indicates dangerous misconfiguration).';
         }
 
+        // 外部 fake flag は非本番専用。production で true なら課金 (Stripe) が fake に
+        // 差し替わり得る危険設定のため fail-fast する (FakeExternalsServiceProvider の
+        // allowlist で bind 自体は起きないが、設定として存在すること自体を拒否する)
+        if (config('testing.fake_externals') === true) {
+            $errors[] = 'TESTING_FAKE_EXTERNALS must be false in production '
+                .'(external fakes must never be enabled in production).';
+        }
+
         // Host header injection 防御の TrustHosts allowlist を起動時検証。
         // 純粋クラス TrustedHostsConfigValidator に委譲し、throw を violation メッセージへ写像する。
         $exact = $this->stringList(config('trusted_hosts.exact_hosts', []));
diff --git a/bootstrap/providers.php b/bootstrap/providers.php
index e80d346..f2100b0 100644
--- a/bootstrap/providers.php
+++ b/bootstrap/providers.php
@@ -1,6 +1,7 @@
 <?php
 
 use App\Providers\AppServiceProvider;
+use App\Providers\FakeExternalsServiceProvider;
 use App\Providers\Filament\AdminPanelProvider;
 use App\Providers\FortifyServiceProvider;
 use App\Providers\McpPassportServiceProvider;
@@ -14,4 +15,7 @@
     // grant / repository を差し替えた本 Provider を唯一の登録点にする (WP23)
     McpPassportServiceProvider::class,
     SeoServiceProvider::class,
+    // 外部 fake の条件付き rebind (flag 既定 false = no-op)。
+    // AppServiceProvider の実装 bind を後勝ちで上書きするため必ず末尾側に置く
+    FakeExternalsServiceProvider::class,
 ];
diff --git a/config/testing.php b/config/testing.php
new file mode 100644
index 0000000..13f5ce4
--- /dev/null
+++ b/config/testing.php
@@ -0,0 +1,22 @@
+<?php
+
+declare(strict_types=1);
+
+return [
+
+    /*
+    |--------------------------------------------------------------------------
+    | 外部サービス fake 化の capability flag
+    |--------------------------------------------------------------------------
+    |
+    | true のとき FakeExternalsServiceProvider が外部サービス (Stripe) の
+    | gateway を fake 実装に bind する (bughunt / local 検証用)。
+    | 有効化は allowlist 環境 (local / testing / bughunt.local) に限定され、
+    | production では ProductionEnvGuard が true を deploy 時 fail-fast で拒否する。
+    | 既定 false = 本 flag 未設定の環境では完全 no-op。
+    |
+    */
+
+    'fake_externals' => (bool) env('TESTING_FAKE_EXTERNALS', false),
+
+];
diff --git a/database/seeders/AdminUserSeeder.php b/database/seeders/AdminUserSeeder.php
index 8087f58..5808553 100644
--- a/database/seeders/AdminUserSeeder.php
+++ b/database/seeders/AdminUserSeeder.php
@@ -15,10 +15,14 @@
  * (env ADMIN_INITIAL_* による初期 admin 投入は廃止済み)。
  * **本番では本 Seeder を使わず admin:create を使うこと**。
  * 誤って production / staging / CI で db:seed されても作成しないよう
- * local 環境以外では skip する。
+ * 許可環境以外では skip する。許可は二段構え:
+ * - local: 無条件 (開発 DX)
+ * - bughunt.local: DB 名 guard (^bug_hunt(_[1-8])?$) 必須 (bug-hunt 管理画面探索用)
  */
 class AdminUserSeeder extends Seeder
 {
+    use Concerns\DetectsBughuntDatabase;
+
     private const EMAIL = 'admin@example.com';
 
     private const PASSWORD = 'password12345';
@@ -27,8 +31,11 @@ class AdminUserSeeder extends Seeder
 
     public function run(): void
     {
-        // local 専用 (本番の初期 admin は admin:create コマンドで発行する)
-        if (! app()->environment('local')) {
+        // local (無条件) と bughunt.local (bug_hunt DB のみ) 専用。
+        // bughunt.local でも DB 名を検証するのは、誤って dev DB を向いた
+        // APP_ENV=bughunt.local 実行で既知資格情報の admin を dev DB に作らないため
+        // (bughunt seeder 群の fail-secure と同強度)。本番の初期 admin は admin:create。
+        if (! $this->shouldSeed()) {
             return;
         }
 
@@ -49,4 +56,13 @@ public function run(): void
             self::PASSWORD,
         ));
     }
+
+    private function shouldSeed(): bool
+    {
+        if (app()->environment('local')) {
+            return true;
+        }
+
+        return app()->environment('bughunt.local') && $this->isBughuntDatabase();
+    }
 }
diff --git a/database/seeders/BughuntBillingSeeder.php b/database/seeders/BughuntBillingSeeder.php
new file mode 100644
index 0000000..66fbd9a
--- /dev/null
+++ b/database/seeders/BughuntBillingSeeder.php
@@ -0,0 +1,118 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Database\Seeders;
+
+use App\Enums\Billing\PlanPriceKind;
+use App\Models\Billing\Plan;
+use App\Models\Billing\Subscription;
+use App\Models\Organization;
+use App\Services\Billing\TicketLedgerService;
+use Illuminate\Database\Seeder;
+
+/**
+ * bug-hunt env 専用: 有料プラン組織に active subscription + 初期チケットを付与する。
+ *
+ * 目的: BillingAccess::hasActiveAccess (subscription default が active/trialing) を
+ * 有料プラン組織で true にし、業務ルート (/projects, /app) を bug-hunt で走行可能にする。
+ * チケット消費系ジャーニー (AI 解析 / レンダ) のため初期残高も付与する。
+ *
+ * ★ free 組織には何も付与しない: 「課金なし経路」(billing redirect / 残高ゼロ) を
+ *   bug-hunt 環境内に温存し、課金ゲート系バグの検出能力を落とさない (概念設計 施策 4)。
+ *
+ * 三重 fail-secure (BughuntOAuthSeeder と同一): (1) config('testing.fake_externals') === true、
+ * (2) app()->environment('bughunt.local')、(3) DB 名 ^bug_hunt(_[1-8])?$。
+ * いずれか欠ければ no-op (production/dev DB に課金状態をばら撒かない)。
+ *
+ * 冪等 = 「探索前提の active 状態を毎回回復する」。stripe_status が active 以外に変わって
+ * いても reseed で active を再保証する。チケットは grantMonthly の idempotency_key で二重付与しない。
+ *
+ * 依存は Seeder の method injection (run() 引数) で受ける (Laravel 公式作法。型安全)。
+ */
+class BughuntBillingSeeder extends Seeder
+{
+    use Concerns\DetectsBughuntDatabase;
+
+    /** 初期チケット付与枚数 (S3 の解析/レンダ探索に十分な決定論値)。 */
+    private const int INITIAL_TICKET_GRANT = 100;
+
+    public function run(TicketLedgerService $tickets): void
+    {
+        if (
+            config('testing.fake_externals') !== true
+            || ! app()->environment('bughunt.local')
+            || ! $this->isBughuntDatabase()
+        ) {
+            $this->command->warn('BughuntBillingSeeder: fake_externals / bughunt.local / bug_hunt DB のいずれか不成立のため skip (production/dev safety)。');
+
+            return;
+        }
+
+        $paidPlanCodes = $this->paidPlanCodes();
+        if ($paidPlanCodes === []) {
+            $this->command->warn('BughuntBillingSeeder: 有料プランが無いため skip。先に PlanSeeder を流すこと。');
+
+            return;
+        }
+
+        $organizations = Organization::query()->whereIn('plan_code', $paidPlanCodes)->orderBy('id')->get();
+        foreach ($organizations as $organization) {
+            $this->ensureActiveSubscription($organization);
+            // 冪等キーで二重付与を防ぐ (reseed は migrate:fresh 後だが、単独再実行にも安全)
+            $tickets->grantMonthly(
+                $organization,
+                self::INITIAL_TICKET_GRANT,
+                null,
+                "bughunt:initial-grant:{$organization->id}",
+                'bug-hunt 初期チケット (探索用)',
+            );
+        }
+
+        $this->command->info("BughuntBillingSeeder: {$organizations->count()} 組織に active subscription + チケット".self::INITIAL_TICKET_GRANT.' 枚を付与。');
+    }
+
+    /**
+     * base price を持つプラン (= 有料プラン) の code 一覧。
+     *
+     * @return list<string>
+     */
+    private function paidPlanCodes(): array
+    {
+        // pluck は list<mixed> になるため map で string を実型でも保証し、
+        // array_values で list 型を確定させる (PHPStan level 10)
+        return array_values(
+            Plan::query()->orderBy('sort_order')->get()
+                ->filter(fn (Plan $plan): bool => $plan->currentPrice(PlanPriceKind::Base) !== null)
+                ->map(fn (Plan $plan): string => $plan->code)
+                ->all(),
+        );
+    }
+
+    /**
+     * active な default subscription を保証する (payload はここに集約)。
+     * 作成は billable relation 経由 (organization_id は FK 自動設定 = guarded 不侵)。
+     * stripe_id は決定論 fixture `sub_bughunt_{org id}` = org 単位一意
+     * (subscriptions.stripe_id UNIQUE と両立。Stripe には到達しない)。
+     */
+    private function ensureActiveSubscription(Organization $organization): void
+    {
+        $existing = $organization->subscription('default');
+
+        if ($existing instanceof Subscription) {
+            if ($existing->stripe_status !== 'active') {
+                // 探索で past_due 等に変わっていても active を回復 (冪等 = active 再保証)
+                $existing->forceFill(['stripe_status' => 'active'])->save();
+            }
+
+            return;
+        }
+
+        $organization->subscriptions()->create([
+            'type' => 'default',
+            'stripe_id' => "sub_bughunt_{$organization->id}",
+            'stripe_status' => 'active',
+            'quantity' => 1,
+        ]);
+    }
+}
diff --git a/database/seeders/BughuntOAuthSeeder.php b/database/seeders/BughuntOAuthSeeder.php
index 33a7771..3c89dc8 100644
--- a/database/seeders/BughuntOAuthSeeder.php
+++ b/database/seeders/BughuntOAuthSeeder.php
@@ -35,8 +35,7 @@
  */
 class BughuntOAuthSeeder extends Seeder
 {
-    /** bug-hunt DB 名の許容 regex (bug-hunt 隔離規約と一致)。 */
-    private const string BUGHUNT_DB_REGEX = '/^bug_hunt(_[1-8])?$/';
+    use Concerns\DetectsBughuntDatabase;
 
     /** legacy MCP token の決定論 id (冪等キー)。char(80) PK に収まる固定値。 */
     private const string LEGACY_MCP_TOKEN_ID_PREFIX = 'bughunt-legacy-mcp-token';
@@ -50,11 +49,10 @@ class BughuntOAuthSeeder extends Seeder
     public function run(): void
     {
         // fail-secure 三軸: fake_externals かつ bughunt.local かつ DB 名 bug_hunt* の全成立時のみ。
-        $dbName = DB::connection()->getDatabaseName();
         if (
             config('testing.fake_externals') !== true
             || ! app()->environment('bughunt.local')
-            || preg_match(self::BUGHUNT_DB_REGEX, $dbName) !== 1
+            || ! $this->isBughuntDatabase()
         ) {
             $this->command->warn('BughuntOAuthSeeder: fake_externals / bughunt.local / bug_hunt DB のいずれか不成立のため skip (production/dev safety)。');
 
diff --git a/database/seeders/Concerns/DetectsBughuntDatabase.php b/database/seeders/Concerns/DetectsBughuntDatabase.php
new file mode 100644
index 0000000..36f4f6e
--- /dev/null
+++ b/database/seeders/Concerns/DetectsBughuntDatabase.php
@@ -0,0 +1,22 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Database\Seeders\Concerns;
+
+use Illuminate\Support\Facades\DB;
+
+/**
+ * bug-hunt DB 名判定の SSOT (bug-hunt 隔離規約 ^bug_hunt(_[1-8])?$ と一致)。
+ * bughunt 系 seeder の fail-secure guard から参照する。
+ */
+trait DetectsBughuntDatabase
+{
+    /** bug-hunt DB 名の許容 regex (scripts/bug-hunt-shard.sh の guard と一致させる)。 */
+    private const string BUGHUNT_DB_REGEX = '/^bug_hunt(_[1-8])?$/';
+
+    private function isBughuntDatabase(): bool
+    {
+        return preg_match(self::BUGHUNT_DB_REGEX, DB::connection()->getDatabaseName()) === 1;
+    }
+}
diff --git a/scripts/bug-hunt-shard.sh b/scripts/bug-hunt-shard.sh
index 42b010d..e619158 100755
--- a/scripts/bug-hunt-shard.sh
+++ b/scripts/bug-hunt-shard.sh
@@ -518,6 +518,51 @@ cmd_assets_check() {
     return 1
 }
 
+# --- filament assets guard (worktree は composer install --no-scripts のため -----
+#     post-autoload-dump の filament:upgrade が走らず public/*/filament が欠落する)
+
+FILAMENT_ASSET_MARKER=public/js/filament/.bughunt-filament-version
+FILAMENT_REQUIRED_ASSETS=(public/js/filament/filament/app.js public/css/filament/filament/app.css)
+
+filament_version_from_lock() {
+    php -r '
+        $lock = json_decode((string) file_get_contents("composer.lock"), true);
+        foreach (($lock["packages"] ?? []) as $p) {
+            if (($p["name"] ?? "") === "filament/filament") { echo $p["version"] ?? ""; return; }
+        }
+    ' 2>/dev/null
+}
+
+filament_assets_present() {
+    local f
+    for f in "${FILAMENT_REQUIRED_ASSETS[@]}"; do
+        [[ -s "${f}" ]] || return 1
+    done
+    return 0
+}
+
+# 冪等 publish: marker (composer.lock の filament version) 一致 ∧ 必須アセット実在なら skip。
+# marker は filament:assets 成功後にのみ書く (失敗時は残さず次回再実行)。
+# 並列 fan-out (provision-all) は shard を直列 provision するため race しない。
+# 将来 provision を並列化する場合は本 helper を worktree 単位の事前フェーズへ移すこと。
+ensure_filament_assets() {
+    local db=$1 url=$2
+    is_dryrun && return 0
+    local version; version="$(filament_version_from_lock)"
+    [[ -z "${version}" ]] \
+        && echo "warning: composer.lock から filament/filament version を解決できない (marker skip 不可 = 毎回 publish 判定)" >&2
+    if [[ -n "${version}" && -f "${FILAMENT_ASSET_MARKER}" \
+        && "$(cat "${FILAMENT_ASSET_MARKER}")" == "${version}" ]] && filament_assets_present; then
+        return 0
+    fi
+    echo ">>> filament assets missing/stale → filament:assets"
+    artisan_for_shard "${db}" "${url}" filament:assets
+    filament_assets_present \
+        || die 1 "filament:assets 実行後も必須アセットが無い (${FILAMENT_REQUIRED_ASSETS[*]})。filament の publish 先変更を疑い、artisan filament:assets の出力を確認すること"
+    [[ -n "${version}" ]] && printf '%s' "${version}" > "${FILAMENT_ASSET_MARKER}"
+    return 0
+}
+
 cmd_keepdb_check() {
     local shard=$1
     cmd_assets_check || die 1 "--keep-db reuse 中止: アセットが stale (上記理由)。provision をスキップせず再 provision してください。"
@@ -592,6 +637,9 @@ cmd_provision() {
     #     (ドメイン固有シーダーはアプリ側で本ブロックに追記する)。
     artisan_for_shard "${db}" "${url}" migrate:fresh --seed --force
     artisan_for_shard "${db}" "${url}" db:seed --class=ManualTestSeeder --force
+    # 有料プラン組織に active subscription + 初期チケットを付与 (三重ガード付き)。
+    # free 組織は未契約のまま = 課金なし経路の探索能力を温存する。
+    artisan_for_shard "${db}" "${url}" db:seed --class=BughuntBillingSeeder --force
     # 管理画面 (Filament admin) 探索用 admin user。AdminUserSeeder は local 限定 (DatabaseSeeder が
     # local でしか呼ばない) のため bughunt では明示 seed する。admin MFA は .env.bughunt.local の
     # ADMIN_MFA_REQUIRED=false で無効化済 (email+password ログイン可)。
@@ -600,6 +648,9 @@ cmd_provision() {
     # bug_hunt DB の三重ガード付き。config('testing.fake_externals') 未導入なら seeder 側で no-op)。
     artisan_for_shard "${db}" "${url}" db:seed --class=BughuntOAuthSeeder --force
 
+    # (b2) Filament 静的アセット publish (F-13 対策)。冪等 (marker + 実在確認で skip)。
+    ensure_filament_assets "${db}" "${url}"
+
     # (c) 実効 env 検証 (不一致 fail-fast)
     local effective
     effective="$(artisan_for_shard "${db}" "${url}" tinker --execute='
@@ -745,6 +796,9 @@ cmd_reseed() {
     db="$(shard_db "${shard}")"; url="$(shard_url "${shard}")"
     artisan_for_shard "${db}" "${url}" migrate:fresh --seed --force
     artisan_for_shard "${db}" "${url}" db:seed --class=ManualTestSeeder --force
+    # 有料プラン組織に active subscription + 初期チケットを付与 (三重ガード付き)。
+    # free 組織は未契約のまま = 課金なし経路の探索能力を温存する。
+    artisan_for_shard "${db}" "${url}" db:seed --class=BughuntBillingSeeder --force
     artisan_for_shard "${db}" "${url}" db:seed --class=AdminUserSeeder --force
     artisan_for_shard "${db}" "${url}" db:seed --class=BughuntOAuthSeeder --force
     echo "reseeded: ${db}"
diff --git a/tests/Feature/Admin/AdminUserSeederTest.php b/tests/Feature/Admin/AdminUserSeederTest.php
index a6aa152..809d941 100644
--- a/tests/Feature/Admin/AdminUserSeederTest.php
+++ b/tests/Feature/Admin/AdminUserSeederTest.php
@@ -4,6 +4,7 @@
 
 use App\Models\AdminUser;
 use Database\Seeders\AdminUserSeeder;
+use Illuminate\Support\Facades\DB;
 use Illuminate\Support\Facades\Hash;
 
 /*
@@ -32,6 +33,38 @@
     expect(Hash::check('password12345', $admin?->password ?? ''))->toBeTrue();
 });
 
+test('bughunt.local かつ bug_hunt DB 名なら AdminUser を作成する', function (): void {
+    $originalEnv = $this->app['env'];
+    $connection = DB::connection();
+    $originalDb = $connection->getDatabaseName();
+
+    try {
+        $this->app['env'] = 'bughunt.local';
+        // 接続は張り替えず DB 名のみ差し替える (実 DB は test DB のまま)
+        $connection->setDatabaseName('bug_hunt');
+        $this->seed(AdminUserSeeder::class);
+    } finally {
+        $this->app['env'] = $originalEnv;
+        $connection->setDatabaseName($originalDb);
+    }
+
+    expect(AdminUser::whereBlind('email', 'email_index', 'admin@example.com')->count())->toBe(1);
+});
+
+test('bughunt.local でも DB 名が bug_hunt 系でなければ作成しない (dev DB 防御)', function (): void {
+    $originalEnv = $this->app['env'];
+
+    try {
+        $this->app['env'] = 'bughunt.local';
+        // DB 名は test DB のまま = bughunt DB 名 guard が拒否する
+        $this->seed(AdminUserSeeder::class);
+    } finally {
+        $this->app['env'] = $originalEnv;
+    }
+
+    expect(AdminUser::query()->count())->toBe(0);
+});
+
 test('再実行時に既存 AdminUser のパスワードを上書きしない', function (): void {
     $this->app['env'] = 'local';
     $this->seed(AdminUserSeeder::class);
diff --git a/tests/Feature/Billing/BillingPageTest.php b/tests/Feature/Billing/BillingPageTest.php
index 9848634..9e199de 100644
--- a/tests/Feature/Billing/BillingPageTest.php
+++ b/tests/Feature/Billing/BillingPageTest.php
@@ -3,6 +3,8 @@
 declare(strict_types=1);
 
 use App\Models\User;
+use App\Services\Billing\Fakes\FakeSubscriptionCheckoutGateway;
+use App\Services\Billing\SubscriptionCheckoutGateway;
 use App\Services\Billing\TicketLedgerService;
 use Inertia\Testing\AssertableInertia as Assert;
 
@@ -87,3 +89,28 @@
 
     $this->actingAs($user)->get('/billing')->assertNotFound();
 });
+
+test('owner の checkout は fake gateway 経由で中立帰還 URL へ遷移する (happy path)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+    $this->app->bind(SubscriptionCheckoutGateway::class, FakeSubscriptionCheckoutGateway::class);
+
+    $response = $this->actingAs($owner)->post('/billing/checkout', ['plan_code' => 'standard']);
+
+    // 非 Inertia リクエストでは Inertia::location は 302 redirect を返す
+    $response->assertStatus(302);
+    $location = $response->headers->get('Location');
+    expect($location)->toContain('/billing')
+        ->and($location)->toContain('fake_external=stripe');
+});
+
+test('owner の portal は fake gateway 経由で中立帰還 URL へ遷移する (happy path)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+    $this->app->bind(SubscriptionCheckoutGateway::class, FakeSubscriptionCheckoutGateway::class);
+
+    $response = $this->actingAs($owner)->post('/billing/portal');
+
+    $response->assertStatus(302);
+    $location = $response->headers->get('Location');
+    expect($location)->toContain('/billing')
+        ->and($location)->toContain('fake_external=stripe');
+});
diff --git a/tests/Feature/Billing/TicketCheckoutTest.php b/tests/Feature/Billing/TicketCheckoutTest.php
index a0e74ba..9813d34 100644
--- a/tests/Feature/Billing/TicketCheckoutTest.php
+++ b/tests/Feature/Billing/TicketCheckoutTest.php
@@ -55,6 +55,16 @@ function checkoutPayload(int $count = 30, ?string $token = null): array
             ->has('page.attemptToken'));
 });
 
+test('fake_external marker query は purchased 表示に転用されない (アプリ非解釈)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    // runtime fake (FakeExternalsServiceProvider) の中立帰還 URL に付く観測用 marker。
+    // アプリはこの query を一切解釈しない = purchased 偽装にならないことを固定する
+    $this->actingAs($owner)->get('/purchase-tickets?fake_external=stripe')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page->where('page.purchased', false));
+});
+
 test('member は閲覧可能 (canManage=false) だが POST は 403', function (): void {
     [$organization] = createOrganizationWithOwner();
     $member = attachOrganizationMember($organization);
diff --git a/tests/Feature/Capture/TakeObjectStorageTest.php b/tests/Feature/Capture/TakeObjectStorageTest.php
index 877ae6d..b5157bd 100644
--- a/tests/Feature/Capture/TakeObjectStorageTest.php
+++ b/tests/Feature/Capture/TakeObjectStorageTest.php
@@ -72,7 +72,14 @@ protected function client(): S3Client
     expect($presigned->url)->toContain('s3.invalid.test');
     expect($presigned->url)->toContain('projects/1/manuals/2/cuts/3/takes/01TEST.mp4');
     expect($presigned->url)->toContain('X-Amz-Signature=');
-    expect($presigned->url)->toContain('X-Amz-Expires=1800');
+    // 失効時刻 = X-Amz-Date + X-Amz-Expires が渡した expiresAt と正確に一致する
+    // (SDK は Expires 秒数を内部 time() 基準で算出するため、テスト側 now() との間に
+    // クライアント初回ビルド等の遅延が入ると固定値 1800 の照合は秒境界で flake する。
+    // 署名日時基準で失効時刻そのものを検証する方が厳密かつ決定的)
+    expect(preg_match('/X-Amz-Date=(\d{8}T\d{6}Z)/', $presigned->url, $dateMatch))->toBe(1);
+    expect(preg_match('/X-Amz-Expires=(\d+)/', $presigned->url, $expiresMatch))->toBe(1);
+    $signedAt = CarbonImmutable::createFromFormat('Ymd\THis\Z', $dateMatch[1], 'UTC');
+    expect($signedAt->getTimestamp() + (int) $expiresMatch[1])->toBe($expiresAt->getTimestamp());
     // D2b: checksum が署名に固定される (query パラメータ + SignedHeaders の両方。
     // content-type/length は PHP SDK が presign 署名から除外するため、その照合は
     // HeadObject 三点照合が担う = checksum が内容とサイズを一意に固定する)
diff --git a/tests/Feature/Database/BughuntBillingSeederTest.php b/tests/Feature/Database/BughuntBillingSeederTest.php
new file mode 100644
index 0000000..35d90c9
--- /dev/null
+++ b/tests/Feature/Database/BughuntBillingSeederTest.php
@@ -0,0 +1,102 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Billing\Subscription;
+use App\Models\Organization;
+use App\Services\Billing\BillingAccess;
+use App\Services\Billing\TicketLedgerService;
+use Database\Seeders\BughuntBillingSeeder;
+use Illuminate\Support\Facades\DB;
+
+/*
+ * BughuntBillingSeeder: bug-hunt env 専用の課金 fixture (有料プラン組織のみ
+ * active subscription + 初期チケット 100。free 組織は未契約のまま温存)。
+ * 三重 fail-secure (fake_externals / bughunt.local / bug_hunt DB 名) を固定する。
+ *
+ * DB 名は接続を張り替えず setDatabaseName で名前のみ差し替える (実 DB は test DB のまま)。
+ * try/finally で必ず復元する。
+ */
+
+/**
+ * bughunt guard 3 軸を成立させた状態で callback を実行する (env / DB 名は必ず復元)。
+ */
+function runWithBughuntGuardSatisfied(Closure $callback): void
+{
+    config(['testing.fake_externals' => true]);
+
+    $app = app();
+    $originalEnv = $app['env'];
+    $connection = DB::connection();
+    $originalDb = $connection->getDatabaseName();
+
+    try {
+        $app['env'] = 'bughunt.local';
+        $connection->setDatabaseName('bug_hunt');
+        $callback();
+    } finally {
+        $app['env'] = $originalEnv;
+        $connection->setDatabaseName($originalDb);
+    }
+}
+
+test('guard 不成立 (既定の testing env / 非 bughunt DB 名) では no-op', function (): void {
+    [$organization] = createOrganizationWithOwner('標準組織', subscribed: false);
+    $organization->forceFill(['plan_code' => 'standard'])->save();
+
+    $this->seed(BughuntBillingSeeder::class);
+
+    expect(Subscription::query()->count())->toBe(0);
+    expect(app(TicketLedgerService::class)->balance($organization))->toBe(0);
+});
+
+test('fake_externals=true でも env=testing のままなら no-op (flag 単独では点火しない)', function (): void {
+    [$organization] = createOrganizationWithOwner('標準組織', subscribed: false);
+    $organization->forceFill(['plan_code' => 'standard'])->save();
+
+    config(['testing.fake_externals' => true]);
+    $this->seed(BughuntBillingSeeder::class);
+
+    expect(Subscription::query()->count())->toBe(0);
+    expect(app(TicketLedgerService::class)->balance($organization))->toBe(0);
+});
+
+test('guard 成立時: standard 組織のみ active sub + チケット 100 を付与し、再実行しても増えない (冪等)', function (): void {
+    [$standardOrg] = createOrganizationWithOwner('標準組織', subscribed: false);
+    $standardOrg->forceFill(['plan_code' => 'standard'])->save();
+    [$freeOrg] = createOrganizationWithOwner('無料組織', subscribed: false);
+    $freeOrg->forceFill(['plan_code' => 'free'])->save();
+
+    runWithBughuntGuardSatisfied(function (): void {
+        $this->seed(BughuntBillingSeeder::class);
+        // 冪等: 再実行しても subscription 1 行・残高 100 のまま増えない
+        $this->seed(BughuntBillingSeeder::class);
+    });
+
+    $standardOrg = Organization::query()->findOrFail($standardOrg->id);
+    $freeOrg = Organization::query()->findOrFail($freeOrg->id);
+    $tickets = app(TicketLedgerService::class);
+
+    // standard 組織: active subscription (課金ゲート通過) + チケット 100
+    expect(app(BillingAccess::class)->hasActiveAccess($standardOrg))->toBeTrue();
+    expect($standardOrg->subscriptions()->count())->toBe(1);
+    expect($tickets->balance($standardOrg))->toBe(100);
+
+    // free 組織: subscription もチケットも付与されない (課金なし経路の温存)
+    expect($freeOrg->subscriptions()->count())->toBe(0);
+    expect($tickets->balance($freeOrg))->toBe(0);
+});
+
+test('既存 subscription が past_due でも再実行で active に回復する (行は増えない)', function (): void {
+    [$organization] = createOrganizationWithOwner('標準組織', subscribed: false);
+    $organization->forceFill(['plan_code' => 'standard'])->save();
+    createFakeSubscription($organization, 'past_due');
+
+    runWithBughuntGuardSatisfied(function (): void {
+        $this->seed(BughuntBillingSeeder::class);
+    });
+
+    $organization = Organization::query()->findOrFail($organization->id);
+    expect($organization->subscriptions()->count())->toBe(1);
+    expect($organization->subscription('default')?->stripe_status)->toBe('active');
+});
diff --git a/tests/Feature/Database/BughuntOAuthSeederGuardTest.php b/tests/Feature/Database/BughuntOAuthSeederGuardTest.php
new file mode 100644
index 0000000..1b11514
--- /dev/null
+++ b/tests/Feature/Database/BughuntOAuthSeederGuardTest.php
@@ -0,0 +1,34 @@
+<?php
+
+declare(strict_types=1);
+
+use Database\Seeders\BughuntOAuthSeeder;
+use Illuminate\Support\Facades\DB;
+
+/*
+ * BughuntOAuthSeeder の fail-secure guard 回帰: config/testing.php の新設
+ * (fake_externals flag の点火) により第 1 ガードが bughunt 環境で成立し始めたため、
+ * 「bughunt 外では従来どおり no-op」の境界をテストで固定する。
+ */
+
+test('既定の testing env では no-op (oauth_clients / oauth_sessions が増えない)', function (): void {
+    $clientsBefore = DB::table('oauth_clients')->count();
+    $sessionsBefore = DB::table('oauth_sessions')->count();
+
+    $this->seed(BughuntOAuthSeeder::class);
+
+    expect(DB::table('oauth_clients')->count())->toBe($clientsBefore);
+    expect(DB::table('oauth_sessions')->count())->toBe($sessionsBefore);
+});
+
+test('fake_externals=true でも env=testing なら no-op (flag 単独では点火しない)', function (): void {
+    config(['testing.fake_externals' => true]);
+
+    $clientsBefore = DB::table('oauth_clients')->count();
+    $sessionsBefore = DB::table('oauth_sessions')->count();
+
+    $this->seed(BughuntOAuthSeeder::class);
+
+    expect(DB::table('oauth_clients')->count())->toBe($clientsBefore);
+    expect(DB::table('oauth_sessions')->count())->toBe($sessionsBefore);
+});
diff --git a/tests/Feature/Providers/FakeExternalsServiceProviderTest.php b/tests/Feature/Providers/FakeExternalsServiceProviderTest.php
new file mode 100644
index 0000000..20600a3
--- /dev/null
+++ b/tests/Feature/Providers/FakeExternalsServiceProviderTest.php
@@ -0,0 +1,49 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Providers\FakeExternalsServiceProvider;
+use App\Services\Billing\CashierSubscriptionCheckoutGateway;
+use App\Services\Billing\CashierTicketCheckoutGateway;
+use App\Services\Billing\Fakes\FakeSubscriptionCheckoutGateway;
+use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;
+use App\Services\Billing\SubscriptionCheckoutGateway;
+use App\Services\Billing\TicketCheckoutGateway;
+use Illuminate\Support\Facades\Log;
+
+/*
+ * FakeExternalsServiceProvider: config('testing.fake_externals') が capability flag。
+ * fail-secure 二軸 (flag 既定 false = 完全 no-op / 環境 allowlist) を固定する。
+ * Pest はテスト毎に app を再構築するため register() 再実行の container 汚染は漏れない。
+ */
+
+test('既定 (flag=false) では両 gateway とも Cashier 実装に解決される', function (): void {
+    expect(config('testing.fake_externals'))->toBeFalse();
+    expect(app(TicketCheckoutGateway::class))->toBeInstanceOf(CashierTicketCheckoutGateway::class);
+    expect(app(SubscriptionCheckoutGateway::class))->toBeInstanceOf(CashierSubscriptionCheckoutGateway::class);
+});
+
+test('flag=true かつ allowlist 環境 (testing) では両 gateway が fake に解決される', function (): void {
+    config(['testing.fake_externals' => true]);
+    (new FakeExternalsServiceProvider($this->app))->register();
+
+    expect(app(TicketCheckoutGateway::class))->toBeInstanceOf(FakeTicketCheckoutGateway::class);
+    expect(app(SubscriptionCheckoutGateway::class))->toBeInstanceOf(FakeSubscriptionCheckoutGateway::class);
+});
+
+test('flag=true でも allowlist 外の環境 (production) では fake に bind せず warning を出す', function (): void {
+    config(['testing.fake_externals' => true]);
+    Log::spy();
+
+    $originalEnv = $this->app['env'];
+    try {
+        $this->app['env'] = 'production';
+        (new FakeExternalsServiceProvider($this->app))->register();
+    } finally {
+        $this->app['env'] = $originalEnv;
+    }
+
+    expect(app(TicketCheckoutGateway::class))->toBeInstanceOf(CashierTicketCheckoutGateway::class);
+    expect(app(SubscriptionCheckoutGateway::class))->toBeInstanceOf(CashierSubscriptionCheckoutGateway::class);
+    Log::shouldHaveReceived('warning')->once();
+});
diff --git a/tests/Feature/Support/ProductionEnvGuardTest.php b/tests/Feature/Support/ProductionEnvGuardTest.php
index ef194cf..5624ae1 100644
--- a/tests/Feature/Support/ProductionEnvGuardTest.php
+++ b/tests/Feature/Support/ProductionEnvGuardTest.php
@@ -15,6 +15,7 @@
     config(['security.csp.enabled' => true]);
     config(['debug.login.user' => '']);
     config(['debug.login.password' => '']);
+    config(['testing.fake_externals' => false]);
     config(['trusted_hosts.exact_hosts' => ['app.example.com']]);
     config(['trusted_hosts.wildcard_suffixes' => []]);
     config(['trusted_hosts.raw_wildcard_suffixes' => []]);
@@ -93,6 +94,13 @@
     expect($errors[0])->toContain('DEBUG_LOGIN');
 });
 
+test('TESTING_FAKE_EXTERNALS が true なら violation', function (): void {
+    config(['testing.fake_externals' => true]);
+    $errors = (new ProductionEnvGuard)->violations();
+    expect($errors)->toHaveCount(1);
+    expect($errors[0])->toContain('TESTING_FAKE_EXTERNALS');
+});
+
 test('TrustHosts allowlist が空なら violation', function (): void {
     config(['trusted_hosts.exact_hosts' => []]);
     config(['trusted_hosts.wildcard_suffixes' => []]);
diff --git a/tests/Unit/Billing/FakeTicketCheckoutGatewayTest.php b/tests/Unit/Billing/FakeTicketCheckoutGatewayTest.php
new file mode 100644
index 0000000..bf0efee
--- /dev/null
+++ b/tests/Unit/Billing/FakeTicketCheckoutGatewayTest.php
@@ -0,0 +1,56 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Billing\CreatedCheckoutSession;
+use App\Models\Organization;
+use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;
+
+/*
+ * runtime fake (App\Services\Billing\Fakes\FakeTicketCheckoutGateway) の不変条件:
+ * - session id は idempotency key から決定的に導出 (同一 attempt の再送は同一 session に収束)
+ * - 戻り URL は cancel URL ベース + 観測用 marker `fake_external=stripe` (アプリは解釈しない)
+ */
+
+function fakeRuntimeTicketCheckout(
+    string $idempotencyKey,
+    string $cancelUrl = 'https://app.test/purchase-tickets',
+): CreatedCheckoutSession {
+    return (new FakeTicketCheckoutGateway)->createTicketCheckout(
+        Organization::factory()->make(),
+        'price_test',
+        10,
+        'https://app.test/purchase-tickets?purchased=1',
+        $cancelUrl,
+        $idempotencyKey,
+        [],
+    );
+}
+
+test('同一 idempotency key からは同一 sessionId が返る (決定論収束)', function (): void {
+    expect(fakeRuntimeTicketCheckout('attempt-1')->sessionId)
+        ->toBe(fakeRuntimeTicketCheckout('attempt-1')->sessionId);
+});
+
+test('異なる idempotency key からは異なる sessionId が返る', function (): void {
+    expect(fakeRuntimeTicketCheckout('attempt-1')->sessionId)
+        ->not->toBe(fakeRuntimeTicketCheckout('attempt-2')->sessionId);
+});
+
+test('sessionId は cs_bughuntfake_ + 32 桁 hex の固定長トークン (退行検出)', function (): void {
+    expect(fakeRuntimeTicketCheckout('attempt-1')->sessionId)->toMatch('/^cs_bughuntfake_[0-9a-f]{32}$/');
+});
+
+test('戻り URL は cancel URL ベースで fake_external=stripe marker が付与される', function (): void {
+    // query なし → `?` で連結
+    expect(fakeRuntimeTicketCheckout('a', 'https://app.test/purchase-tickets')->url)
+        ->toBe('https://app.test/purchase-tickets?fake_external=stripe');
+
+    // 既存 query あり → `&` で連結
+    expect(fakeRuntimeTicketCheckout('a', 'https://app.test/purchase-tickets?foo=1')->url)
+        ->toBe('https://app.test/purchase-tickets?foo=1&fake_external=stripe');
+});
+
+test('expireCheckoutSession は expired を返す (状態を持たない stub)', function (): void {
+    expect((new FakeTicketCheckoutGateway)->expireCheckoutSession('cs_any'))->toBe('expired');
+});
```
