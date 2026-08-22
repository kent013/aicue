# T010 実装レビュー依頼 (impl-review Round 1)

## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

## あなたの役割 (system)

あなたはシニア Laravel/Svelte エンジニアとして、TODO T010「Free課金ゲート矛盾の解消 (F-07: サイレントリダイレクト)」の実装 diff を最終レビューする。

観点:

1. **正しさ**: entitlement 判定 (`BillingAccess`) の書き換えが Free プランを正しく通し、期限切れ/未課金を正しく遮断するか。HTML(flash + redirect) と JSON(402) の遮断文言・挙動が一貫しているか
2. **セキュリティ不変条件**: 課金の冪等性 / tenant キー不信 / cross-org 不可 などを壊していないか
3. **禁止事項違反**: 上記禁止事項 1〜8 への抵触
4. **テスト**: 再現テストファーストが守られ、不変条件が Feature/Architecture テストに登録されているか。テストの削除・widen がないか
5. **設計整合**: 詳細設計 `devnotes/20260712-0927-bugfix-billing-free-access/detailed-design.md`(APPROVED、design-review Round 3 まで実施済み)との乖離

必要なら worktree `/workspace/.claude/worktrees/tasks/T010` 配下のファイルを読み込んで文脈を確認してよい(読み込みのみ)。

出力形式:

```
## 総合判定: APPROVED / NEEDS_CHANGES

## Critical
(なければ「なし」)

## Warning
(なければ「なし」)

## Suggestion
(なければ「なし」)
```

Critical は「本番で誤動作・セキュリティ欠陥・禁止事項違反を引き起こすもの」に限定すること。

---

## レビュー対象 diff (user)

ブランチ `todo/T010` の `main` に対する全 diff。検証結果: composer test (1514 passed / 2 skipped) / composer phpstan (0 errors, level 10) / pint / pnpm lint / typecheck / test (428 passed) / build すべて green。

```diff
diff --git a/app/DataTransferObjects/Dashboard/BillingSummaryData.php b/app/DataTransferObjects/Dashboard/BillingSummaryData.php
index 6c085e3..045f1cc 100644
--- a/app/DataTransferObjects/Dashboard/BillingSummaryData.php
+++ b/app/DataTransferObjects/Dashboard/BillingSummaryData.php
@@ -16,13 +16,13 @@ public function __construct(
         public int $storageUsedBytes,       // StorageUsageService::occupiedBytes
         public ?int $storageLimitBytes,     // QuotaService::limits[max_storage_bytes] (無制限は null)
         public ?int $storageUsagePercent,   // 0-100 に clamp (limit null なら null)
-        public bool $hasActiveSubscription, // BillingAccess::hasActiveAccess
+        public bool $hasBillingAccess,      // BillingAccess::hasActiveAccess (billing entitlement。free 組織は true)
     ) {}
 
     /**
      * @return array{ticket_balance: int, is_low_balance: bool, storage_used_bytes: int,
      *   storage_limit_bytes: int|null, storage_usage_percent: int|null,
-     *   has_active_subscription: bool}
+     *   has_billing_access: bool}
      */
     public function toArray(): array
     {
@@ -32,7 +32,7 @@ public function toArray(): array
             'storage_used_bytes' => $this->storageUsedBytes,
             'storage_limit_bytes' => $this->storageLimitBytes,
             'storage_usage_percent' => $this->storageUsagePercent,
-            'has_active_subscription' => $this->hasActiveSubscription,
+            'has_billing_access' => $this->hasBillingAccess,
         ];
     }
 }
diff --git a/app/DataTransferObjects/Dashboard/DashboardPageData.php b/app/DataTransferObjects/Dashboard/DashboardPageData.php
index 50f43ee..03107bd 100644
--- a/app/DataTransferObjects/Dashboard/DashboardPageData.php
+++ b/app/DataTransferObjects/Dashboard/DashboardPageData.php
@@ -47,7 +47,7 @@ public function __construct(
      *     pending_cuts_count: int}>,
      *   billing: array{ticket_balance: int, is_low_balance: bool, storage_used_bytes: int,
      *     storage_limit_bytes: int|null, storage_usage_percent: int|null,
-     *     has_active_subscription: bool}|null}
+     *     has_billing_access: bool}|null}
      */
     public function toArray(): array
     {
diff --git a/app/Http/Middleware/RequireActiveSubscription.php b/app/Http/Middleware/RequireActiveSubscription.php
index d60fb9a..a897023 100644
--- a/app/Http/Middleware/RequireActiveSubscription.php
+++ b/app/Http/Middleware/RequireActiveSubscription.php
@@ -14,16 +14,18 @@
 use Symfony\Component\HttpFoundation\Response;
 
 /**
- * 課金ゲート: 有効な subscription (BillingAccess 判定) を持たない組織の
- * 業務 route アクセスを遮断する middleware。alias: `require-active-subscription`。
+ * 課金ゲート: BillingAccess の entitlement 判定で不許可
+ * (= 有償プラン契約中の支払い不健全) の組織の業務 route アクセスを遮断し、
+ * 理由 flash とともに billing へ誘導する middleware。alias: `require-active-subscription`。
  *
  * - 判定は BillingAccess::hasActiveAccess のみ (subscription 直参照禁止。
- *   アプリは BillingAccess の差し替えで gate 方針を変更する)
- * - 未契約: ブラウザは billing へ redirect して Checkout 導線に誘導、
- *   JSON/XHR は 402 Payment Required
+ *   アプリは BillingAccess の差し替えで gate 方針を変更する)。
+ *   plan_code null (未契約 = free tier) は許可されるため本 middleware を素通りする
+ * - 遮断時: ブラウザは billing へ redirect + 理由 flash (error)、
+ *   JSON/XHR は 402 Payment Required (同一文言)
  * - allowlist: billing (index/checkout/portal)・Stripe webhook・組織管理系 route には
  *   本 middleware を適用しない (route 側で group に含めない構造的 allowlist。
- *   未契約でも checkout に到達できることを保証する)
+ *   遮断中でも checkout / Customer Portal に到達できることを保証する)
  *
  * 対象 organization の解決:
  *   1. route に `{organization}` binding があればそれを使う。その際、非メンバー /
@@ -36,6 +38,13 @@
  */
 final class RequireActiveSubscription
 {
+    /**
+     * 遮断理由 (ブラウザ flash / JSON 402 で同一文言。H1: 説明なしリダイレクト対策)。
+     * 判定変更後に遮断されるのは「有償プラン契約中の支払い不健全」のみのため、
+     * free 組織を誤解させる旧文言 (「有効なサブスクリプションがありません」) は廃止。
+     */
+    private const string BLOCKED_MESSAGE = 'サブスクリプションのお支払いが確認できないため、ご利用を一時停止しています。お支払い方法をご確認ください。';
+
     public function __construct(
         private readonly BillingAccess $access,
     ) {}
@@ -60,16 +69,18 @@ public function handle(Request $request, Closure $next): Response
             return $next($request);
         }
 
-        // JSON/XHR は 402、ブラウザは billing へ誘導 (Checkout 導線)
+        // JSON/XHR は 402、ブラウザは billing へ誘導 (理由 flash 付き。文言は両経路で統一)
         if ($request->expectsJson()) {
-            abort(Response::HTTP_PAYMENT_REQUIRED, '有効なサブスクリプションがありません。お支払いを完了してください。');
+            abort(Response::HTTP_PAYMENT_REQUIRED, self::BLOCKED_MESSAGE);
         }
 
         // 直前 hop で積まれた flash (例: 招待受諾の success) が、この gate-redirect の
-        // 1 hop で消費され失われないよう延命する
+        // 1 hop で消費され失われないよう延命する。with('error', ...) は新規 flash の
+        // 積み込みで両立する (key 衝突時は本 middleware の error が優先される —
+        // 遮断理由の提示が最優先の情報のため許容)
         $request->session()->reflash();
 
-        return redirect()->route('billing.index');
+        return redirect()->route('billing.index')->with('error', self::BLOCKED_MESSAGE);
     }
 
     /**
diff --git a/app/Models/Organization.php b/app/Models/Organization.php
index d470dd6..b9e4f01 100644
--- a/app/Models/Organization.php
+++ b/app/Models/Organization.php
@@ -32,6 +32,9 @@
  * (OrganizationProvisioningService が明示代入する)。
  * plan_code は現在プランの状態キーのため $fillable 外 (StripeWebhookProcessor が
  * webhook から同期する。クライアント入力では変更できない)。
+ * plan_code は Stripe Price を持つ有償プランの契約 (active/trialing) 時のみ set され、
+ * subscription.deleted で null に戻る。**null = 未契約 = 支払い不要の free tier**
+ * (config/quota.php の fallback_plan が適用され、BillingAccess は業務 route を許可する)。
  */
 class Organization extends Model
 {
@@ -103,7 +106,8 @@ public function oauthSessions(): HasMany
     }
 
     /**
-     * 現在の契約プラン (plan_code → plans.code。null = 未契約)。
+     * 現在の契約プラン (plan_code → plans.code。null = 未契約 = 支払い不要の free tier。
+     * quota は config/quota.php の fallback_plan、業務 route は BillingAccess が許可する)。
      *
      * @return BelongsTo<Plan, $this>
      */
diff --git a/app/Services/Billing/BillingAccess.php b/app/Services/Billing/BillingAccess.php
index 75f35f7..1642750 100644
--- a/app/Services/Billing/BillingAccess.php
+++ b/app/Services/Billing/BillingAccess.php
@@ -7,28 +7,45 @@
 use App\Models\Organization;
 
 /**
- * 組織が業務機能を利用してよいか (課金ゲート) の判定。
+ * 組織が業務機能を利用してよいか (billing entitlement) の判定。
  *
  * **課金による利用可否の判定は必ず本クラスを経由する** (middleware / controller /
  * service での subscription 直参照は禁止)。判定基準を 1 クラスに閉じ込めることで、
- * アプリ側は本クラスの書き換え (または container での差し替え bind) だけで
- * gate 方針を変更できる (例: 専用の billing 状態カラムでの判定や、
- * entitlement 導出への差し替え)。
+ * アプリ側は本クラスの書き換えだけで gate 方針を変更できる。
  *
- * テンプレート既定は最小実装: Cashier の `subscription('default')` が
- * active / trialing なら許可。past_due / canceled / incomplete 等は不許可
- * (未契約と同じ扱いで billing へ誘導する)。
+ * AI-CUE の entitlement 方針 (テンプレート既定の「active subscription 必須」からの
+ * 意図的な書き換え。devnotes/20260712-0927-bugfix-billing-free-access):
+ *
+ * - plan_code null (未契約) = fallback free プラン。**支払い不要 tier としてアクセス許可**。
+ *   有償価値は別レイヤで gate 済み (チケット残高 = analyze/render、Quota = max_projects 等)
+ * - plan_code 非 null = 有償プラン契約状態。subscription('default') が active / trialing の
+ *   ときのみ許可 (past_due / canceled / incomplete / 行不在は fail-closed で不許可 =
+ *   支払い健全性の担保のみが本ゲートの責務)
+ *
+ * 不変条件 (依存するデータモデル契約): `organizations.plan_code` は Stripe Price を持つ
+ * 有償プランの契約時のみ StripeWebhookProcessor が set し、subscription.deleted で null に
+ * 戻す。支払い不要のプランを plan_code に載せる場合は本判定とセットで見直すこと
+ * (挙動は RequireActiveSubscriptionMiddlewareTest が固定する)。
+ *
+ * 注: 本メソッドは「subscription を持つか」ではなく「業務ルートを利用してよいか
+ * (billing entitlement)」を返す。free 組織は subscription 無しで true になる。
  */
 class BillingAccess
 {
-    /** アクセスを許可する Stripe subscription status */
+    /** アクセスを許可する Stripe subscription status (有償プラン契約時のみ参照) */
     private const array GRANTING_STATUSES = ['active', 'trialing'];
 
     public function hasActiveAccess(Organization $organization): bool
     {
+        // 未契約 (plan_code null) = fallback free プラン。支払い不要 tier として許可
+        if ($organization->plan_code === null) {
+            return true;
+        }
+
+        // 有償プラン契約状態: 支払い健全性 (active/trialing) を要求。
+        // 行不在 (webhook 順序逆転等) も fail-closed で不許可
         $subscription = $organization->subscription('default');
 
-        // subscription 不在 (未契約) は fail-closed で不許可
         return $subscription !== null
             && in_array($subscription->stripe_status, self::GRANTING_STATUSES, true);
     }
diff --git a/app/Services/Billing/StripeWebhookProcessor.php b/app/Services/Billing/StripeWebhookProcessor.php
index adc8e8d..87546cc 100644
--- a/app/Services/Billing/StripeWebhookProcessor.php
+++ b/app/Services/Billing/StripeWebhookProcessor.php
@@ -42,6 +42,13 @@
  *
  * subscriptions テーブル自体の同期 (updateOrCreate) は Cashier の WebhookController
  * が行うため、ここではアプリ状態 (plan_code / チケット) だけを扱う。
+ *
+ * plan_code 不変条件: `organizations.plan_code` は Stripe Price を持つ有償プランの
+ * 契約 (active/trialing) 時のみ本クラスが set し、`customer.subscription.deleted` で
+ * null に戻す状態キー。**null = 未契約 = 支払い不要の free tier**
+ * (config/quota.php の fallback_plan が適用される)。BillingAccess はこの契約を
+ * entitlement 判定の根拠にするため、支払い不要のプランを plan_code に載せる場合は
+ * BillingAccess とセットで見直すこと (RequireActiveSubscriptionMiddlewareTest が固定)。
  */
 class StripeWebhookProcessor
 {
diff --git a/app/Services/Dashboard/DashboardService.php b/app/Services/Dashboard/DashboardService.php
index e55bb8a..23e5a93 100644
--- a/app/Services/Dashboard/DashboardService.php
+++ b/app/Services/Dashboard/DashboardService.php
@@ -231,7 +231,7 @@ private function billingSummary(Organization $organization): BillingSummaryData
             storageUsedBytes: $used,
             storageLimitBytes: $limit,
             storageUsagePercent: $percent,
-            hasActiveSubscription: $this->billingAccess->hasActiveAccess($organization),
+            hasBillingAccess: $this->billingAccess->hasActiveAccess($organization),
         );
     }
 }
diff --git a/database/seeders/PlanSeeder.php b/database/seeders/PlanSeeder.php
index 06ef321..4be24d5 100644
--- a/database/seeders/PlanSeeder.php
+++ b/database/seeders/PlanSeeder.php
@@ -18,7 +18,12 @@
  * - 価格の真実源は plan_prices (DB snapshot)。ここでは bootstrap 行
  *   (stripe_price_id=price_test_* / livemode=false / synced_at=null) を投入し、
  *   実運用では `billing:sync-stripe-prices` が Stripe Catalog の実 Price ID へ上書きする
- * - free プランは Stripe Price を持たない (Checkout 対象外。未契約の既定)
+ * - free プランは Stripe Price を持たない (Checkout 対象外。未契約の既定)。
+ *   これは BillingAccess の entitlement 判定の前提でもある: plan_code は Stripe Price →
+ *   Plan 解決 (StripeWebhookProcessor) でのみ set されるため、Price を持たない free が
+ *   plan_code に載る経路はない (null = 未契約 = 支払い不要の free tier)。free に Price を
+ *   持たせる場合は BillingAccess とセットで見直すこと
+ *   (RequireActiveSubscriptionMiddlewareTest が固定)
  */
 class PlanSeeder extends Seeder
 {
diff --git a/docs/app-integration-guide.md b/docs/app-integration-guide.md
index 03fac64..7e23481 100644
--- a/docs/app-integration-guide.md
+++ b/docs/app-integration-guide.md
@@ -132,6 +132,12 @@ ## 4. 課金・上限のマッピング
   group に含めない構造的 allowlist)。判定方針を変えたいアプリは `BillingAccess` の
   書き換え(または container での差し替え bind)だけで済ませる(spirux は
   billing_access_state カラム判定、aigenba は entitlement 導出に差し替えた実績)。
+  本アプリ (AI-CUE) は entitlement 判定へ書き換え済み: `organizations.plan_code` null
+  (未契約) = 支払い不要の free tier として許可し、plan_code 非 null (有償プラン契約中)
+  のみ支払い健全性 (active/trialing) を要求する。plan_code は Stripe Price を持つ
+  有償プランの契約時のみ webhook が set し subscription.deleted で null に戻す状態キー —
+  支払い不要のプランを plan_code に載せる場合は `BillingAccess` とセットで見直すこと
+  (`RequireActiveSubscriptionMiddlewareTest` が固定)。
 
 ## 5. API・外部公開面のマッピング
 
@@ -181,7 +187,9 @@ ## 7. 守るべき不変条件(チェックリスト)
 6. **任意 class の逆シリアライズを許さない**(cache serializable_classes は既定 false。
    object cache が必要になったときだけ最小 allowlist)
 7. **課金系の冪等性**: webhook は冪等マシン経由、消費は 2 フェーズ、通知は dedup_key。
-   課金による利用可否の判定は `BillingAccess` 経由のみ(subscription 直参照の gate 分岐禁止)
+   課金による利用可否の判定は `BillingAccess` 経由のみ(subscription 直参照の gate 分岐禁止。
+   AI-CUE の判定は billing entitlement: plan_code null = free tier 許可 / 有償契約中のみ
+   支払い健全性を要求)
 8. **テストなしの実装完了はない**(不変条件 1-7 はそれぞれ対応する Architecture/Feature
    テストに新リソースを登録して初めて「実装済み」)
 
diff --git a/docs/architecture.md b/docs/architecture.md
index 0eaac2d..f46af44 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -82,7 +82,7 @@ ## 主要 Service (テンプレート同梱)
 | `Render/AssSubtitleWriter` | AI-CUE: ASS 字幕生成の安全境界 (唯一の字幕テキスト出力点。リテラル \N/override tag/制御文字/zero-width の正規化 + mb 安全な長さ上限) |
 | `Render/RenderObjectStorage` | AI-CUE: レンダ出力 S3 操作の集約点 (download/upload/署名 URL/削除/prefix。DL 用 Content-Disposition は RFC 5987 + ASCII fallback + ヘッダ注入不能) |
 | `Auth/SocialAccountService` | ソーシャルログイン連携 |
-| `Billing/BillingAccess` | 課金ゲート判定 (`subscription('default')` が active/trialing なら許可)。**課金による利用可否の判定は本クラス経由のみ** (アプリは本クラスの差し替えで gate 方針を変更する)。適用は `require-active-subscription` middleware (業務 route group。billing / webhook は構造的 allowlist) |
+| `Billing/BillingAccess` | billing entitlement 判定 (plan_code null = 未契約 = 支払い不要 free tier は許可 / plan_code 非 null = 有償契約は `subscription('default')` が active/trialing のときのみ許可 = 支払い健全性 gate)。**課金による利用可否の判定は本クラス経由のみ** (アプリは本クラスの差し替えで gate 方針を変更する)。適用は `require-active-subscription` middleware (業務 route group。billing / webhook は構造的 allowlist)。plan_code は Stripe Price を持つ有償プラン契約時のみ webhook が set する状態キー — 支払い不要プランを plan_code に載せる場合は本判定とセットで見直す (`RequireActiveSubscriptionMiddlewareTest` が固定) |
 | `Billing/QuotaService` | quota の消費・検証 |
 | `Billing/StripeWebhookProcessor` | webhook の冪等処理 |
 | `Billing/BillingNotificationDispatcher` | 請求通知の冪等 dispatch 窓口 (通知台帳へ insertOrIgnore → 新規行のみ queue。**請求系通知の送信は本クラス経由のみ**) |
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 72ef8a4..8920701 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -258,3 +258,41 @@ ### 関連
   `app/Services/Project/DefaultProjectResolver.php` /
   `app/Http/Controllers/Admin/UserManagementController.php`
 - 設計: `devnotes/20260711-1009-admin-console/` (概念設計 D1/D2/D6・詳細設計 施策 1〜7)
+
+## D9 ✅ BillingAccess の entitlement 判定への書き換え (free tier は課金ゲートを通す)
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| BillingAccess::hasActiveAccess | `subscription('default')` が active/trialing のときのみ許可 (未契約 = fail-closed) | plan_code null (未契約 = 支払い不要 free tier) は許可 / plan_code 非 null (有償プラン契約状態) のみ active/trialing を要求 |
+| 遮断時の UX | billing へ redirect (理由提示なし) / JSON 402 「有効なサブスクリプションがありません」 | billing へ redirect + 理由 flash / JSON 402 (両経路とも「サブスクリプションのお支払いが確認できないため…」で統一) |
+| ダッシュボード callout | `has_active_subscription` (subscription 有無) | `has_billing_access` (billing entitlement) + 支払い方法確認 CTA |
+
+### なぜ正当な差分か (logic-driven)
+
+AI-CUE は「Free プランで今すぐ試せます」を掲げる freemium 設計 (pricing / home)。テンプレート
+既定の「active subscription 必須」では、未契約の新規組織が business route (/projects, /app) に
+一切到達できず、North Star フロー (SOP→シナリオ→撮影→動画) が入口で詰む
+(bug-hunt F-07: devnotes/20260712-075854-bug-hunt)。有償価値は別レイヤで gate 済み
+(チケット残高 = analyze/render、Quota = max_projects / max_storage_bytes) のため、
+本ゲートの責務は「有償プラン契約中の支払い健全性の担保」のみで足りる。
+なお BillingAccess docblock 自身が「アプリは本クラスの書き換えで gate 方針を変更する」と
+宣言する公式拡張ポイントのため、これは構造逸脱ではなくサンクション済み拡張の記録。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「課金による利用可否の判定は BillingAccess 経由のみ / 有償契約の支払い不健全
+> (past_due / canceled / incomplete / 行不在) は fail-closed で遮断 / billing・checkout は
+> 構造的 allowlist で遮断中も到達可能 / plan_code は Stripe Price を持つ有償プラン契約時のみ
+> webhook が set する状態キー (null = 未契約 = free tier)」
+
+- 挙動固定: `RequireActiveSubscriptionMiddlewareTest` (F-07 再現 3 本 + 有償契約マトリクス +
+  free プランが Stripe Price を持たない前提の固定 + BillingAccess 単体マトリクス)
+- 遮断 UX: 同テストが flash / 402 message の文言を両経路で固定。
+  ダッシュボード callout は `DashboardTest` + `Dashboard.test.ts` が固定
+
+### 関連
+
+- 実装: `app/Services/Billing/BillingAccess.php` /
+  `app/Http/Middleware/RequireActiveSubscription.php` /
+  `app/DataTransferObjects/Dashboard/BillingSummaryData.php`
+- 設計: `devnotes/20260712-0927-bugfix-billing-free-access/` (概念設計 + 詳細設計 施策 1〜5)
diff --git a/resources/js/pages/Dashboard.svelte b/resources/js/pages/Dashboard.svelte
index f46024c..f33edb2 100644
--- a/resources/js/pages/Dashboard.svelte
+++ b/resources/js/pages/Dashboard.svelte
@@ -217,13 +217,13 @@
                 />
             </div>
 
-            {#if !billing.has_active_subscription}
+            {#if !billing.has_billing_access}
                 <Card class="mt-6" testId="billing-callout">
                     <p class="text-body text-text">
-                        有効なサブスクリプションがありません。プランを契約すると、マニュアルの作成・撮影を再開できます。
+                        サブスクリプションのお支払いが確認できないため、一部機能を一時停止しています。お支払い方法をご確認ください。
                     </p>
                     <div class="mt-4">
-                        <Button href="/billing" inertia>プランを見る</Button>
+                        <Button href="/billing" inertia>お支払い方法を確認</Button>
                     </div>
                 </Card>
             {/if}
diff --git a/resources/js/types/dashboard.ts b/resources/js/types/dashboard.ts
index 95d7f92..a4c71a5 100644
--- a/resources/js/types/dashboard.ts
+++ b/resources/js/types/dashboard.ts
@@ -38,7 +38,7 @@ export interface BillingSummary {
     storage_used_bytes: number;
     storage_limit_bytes: number | null;
     storage_usage_percent: number | null;
-    has_active_subscription: boolean;
+    has_billing_access: boolean;
 }
 
 export interface DashboardData {
diff --git a/routes/web.php b/routes/web.php
index c640e61..b5f0f56 100644
--- a/routes/web.php
+++ b/routes/web.php
@@ -302,7 +302,8 @@
     | Stripe webhook ルート (POST /stripe/webhook) は Cashier が自動登録する
     | (CSRF 除外は bootstrap/app.php の validateCsrfTokens except 'stripe/*')。
     | billing / webhook / 組織管理系は課金ゲート (require-active-subscription) の
-    | allowlist (gate group に含めない)。未契約でも checkout に到達できることを保証する。
+    | allowlist (gate group に含めない)。遮断対象 (有償プラン契約中の支払い不健全) でも
+    | checkout / Customer Portal に到達できることを保証する。
     */
     Route::get('/billing', [BillingController::class, 'index'])
         ->name('billing.index');
@@ -313,7 +314,8 @@
 
     /*
     | チケットスポット購入 (current org スコープ)。billing.* と同じく課金ゲート
-    | (require-active-subscription) の対象外 = 未契約 / free プラン組織でも購入できる。
+    | (require-active-subscription) の対象外 = 支払い不健全で遮断中の組織でも購入できる
+    | (free 組織はそもそも遮断されない = BillingAccess の entitlement 判定)。
     | 閲覧は組織メンバー全員、Checkout 開始は manageBilling (owner / admin) のみ。
     */
     Route::get('/purchase-tickets', [TicketPurchaseController::class, 'show'])
@@ -341,8 +343,9 @@
         ->name('notifications.read');
 
     /*
-    | 組織配下の業務 route (課金ゲート対象)。有効な subscription (BillingAccess 判定)
-    | を持たない組織は billing へ redirect される (JSON は 402)。
+    | 組織配下の業務 route (課金ゲート対象)。BillingAccess の entitlement 判定で
+    | 不許可 = 有償プラン契約中の支払い不健全のみ billing へ redirect + 理由 flash
+    | (JSON は 402)。free (未契約 = plan_code null) 組織は遮断されない。
     | 新しい業務ドメインの route はこの group 内に追加すること。
     */
     Route::middleware(['require-active-subscription', 'project.in-route-org'])->group(function (): void {
diff --git a/tests/Feature/Billing/ReconcileSubscriptionSchedulesTest.php b/tests/Feature/Billing/ReconcileSubscriptionSchedulesTest.php
index 9909c85..40ca911 100644
--- a/tests/Feature/Billing/ReconcileSubscriptionSchedulesTest.php
+++ b/tests/Feature/Billing/ReconcileSubscriptionSchedulesTest.php
@@ -33,7 +33,7 @@ function agedSubscription(): Subscription
 {
     [$organization] = createOrganizationWithOwner();
     /** @var Subscription $subscription */
-    $subscription = $organization->subscriptions()->sole();
+    $subscription = createFakeSubscription($organization);
     $subscription->forceFill(['created_at' => now()->subHours(2)])->save();
 
     return $subscription;
@@ -41,6 +41,7 @@ function agedSubscription(): Subscription
 
 test('subscriptions() はテンプレート拡張 Subscription モデルを返す (useSubscriptionModel)', function (): void {
     [$organization] = createOrganizationWithOwner();
+    createFakeSubscription($organization);
 
     expect($organization->subscriptions()->sole())->toBeInstanceOf(Subscription::class);
 });
@@ -75,6 +76,7 @@ function agedSubscription(): Subscription
 
 test('retryMissing: 作成 1h 未満の subscription は in-flight とみなし照会しない', function (): void {
     [$organization] = createOrganizationWithOwner();
+    createFakeSubscription($organization);
     $this->mock(StripeScheduleGateway::class)
         ->shouldReceive('retrieve')->never();
 
@@ -88,7 +90,7 @@ function agedSubscription(): Subscription
 test('retryPartial: Created で remote phases 設定済みなら Configured へ昇格する', function (): void {
     [$organization] = createOrganizationWithOwner();
     /** @var Subscription $subscription */
-    $subscription = $organization->subscriptions()->sole();
+    $subscription = createFakeSubscription($organization);
     $subscription->markScheduleCreated('sub_sched_partial_1');
 
     $this->mock(StripeScheduleGateway::class)
@@ -105,7 +107,7 @@ function agedSubscription(): Subscription
 test('retryPartial: remote から schedule が消えていれば None へ reset する', function (): void {
     [$organization] = createOrganizationWithOwner();
     /** @var Subscription $subscription */
-    $subscription = $organization->subscriptions()->sole();
+    $subscription = createFakeSubscription($organization);
     $subscription->markScheduleCreated('sub_sched_gone_1');
 
     $this->mock(StripeScheduleGateway::class)
@@ -125,10 +127,10 @@ function agedSubscription(): Subscription
     [$org1] = createOrganizationWithOwner();
     [$org2] = createOrganizationWithOwner();
     /** @var Subscription $failing */
-    $failing = $org1->subscriptions()->sole();
+    $failing = createFakeSubscription($org1);
     $failing->markScheduleCreated('sub_sched_err_1');
     /** @var Subscription $healthy */
-    $healthy = $org2->subscriptions()->sole();
+    $healthy = createFakeSubscription($org2);
     $healthy->markScheduleCreated('sub_sched_ok_1');
 
     $mock = $this->mock(StripeScheduleGateway::class);
diff --git a/tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php b/tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php
index baa0a7a..4fac137 100644
--- a/tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php
+++ b/tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php
@@ -4,7 +4,9 @@
 
 use App\Enums\OrganizationRole;
 use App\Http\Middleware\RequireActiveSubscription;
+use App\Models\Billing\Plan;
 use App\Models\Organization;
+use App\Models\Project;
 use App\Models\User;
 use App\Services\Billing\BillingAccess;
 use Illuminate\Http\Request;
@@ -14,63 +16,119 @@
 
 /*
  * 課金ゲート (require-active-subscription)。
- * 判定は BillingAccess::hasActiveAccess のみ (subscription('default') が active/trialing)。
- * 未契約はブラウザなら billing へ redirect、JSON なら 402。billing 系 route は
- * gate group 外 (構造的 allowlist) で未契約でも checkout に到達できる。
+ * 判定は BillingAccess::hasActiveAccess のみ (billing entitlement):
+ * - plan_code null (未契約) = fallback free プラン。支払い不要 tier として許可
+ * - plan_code 非 null = 有償プラン契約状態。subscription('default') が active/trialing の
+ *   ときのみ許可 (支払い不健全はブラウザなら billing へ redirect + 理由 flash、JSON なら 402)
+ * billing 系 route は gate group 外 (構造的 allowlist) で遮断中でも checkout に到達できる。
  */
 
-test('未契約の組織は業務 route から billing へ redirect される', function (): void {
-    [, $owner] = createOrganizationWithOwner(subscribed: false);
+const BILLING_BLOCKED_MESSAGE = 'サブスクリプションのお支払いが確認できないため、ご利用を一時停止しています。お支払い方法をご確認ください。';
 
-    $this->actingAs($owner)->get('/projects')
-        ->assertRedirect(route('billing.index'));
-});
+// ── 再現テスト (F-07。実装前に fail を確認する) ──
 
-test('active subscription の組織は業務 route に到達できる', function (): void {
+test('Free (未契約) 組織は業務 route に到達できる (F-07 再現)', function (): void {
     [, $owner] = createOrganizationWithOwner();
 
     $this->actingAs($owner)->get('/projects')->assertOk();
+    $this->actingAs($owner)->get('/projects/create')->assertOk();
 });
 
-test('trialing subscription の組織は業務 route に到達できる', function (): void {
-    [$organization, $owner] = createOrganizationWithOwner(subscribed: false);
-    createFakeSubscription($organization, status: 'trialing');
+test('Free (未契約) 組織はプロジェクトを作成できる (F-07 再現)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
 
-    $this->actingAs($owner)->get('/projects')->assertOk();
+    $this->actingAs($owner)->post('/projects', ['name' => 'Free プロジェクト'])
+        ->assertRedirect(); // projects.show へ (billing.index でないこと)
+    expect(Project::query()->where('name', 'Free プロジェクト')->exists())->toBeTrue();
 });
 
-test('past_due / canceled の subscription では業務 route から billing へ redirect される', function (string $status): void {
-    [$organization, $owner] = createOrganizationWithOwner(subscribed: false);
-    createFakeSubscription($organization, status: $status);
+test('Free (未契約) 組織は撮影 PWA (/app) に到達できる (F-07 再現)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    $this->actingAs($owner)->get('/app')
+        ->assertRedirect(route('capture.manuals.index', ['project' => $project]));
+});
+
+// ── 有償プラン契約状態の支払い健全性 gate (fail-closed は plan_code 非 null に限定) ──
+
+test('有償契約 + active/trialing は業務 route に到達できる', function (string $status): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    contractPaidPlan($organization, status: $status);
+
+    $this->actingAs($owner)->get('/projects')->assertOk();
+})->with(['active', 'trialing']);
+
+test('有償契約 + 支払い不健全は billing へ redirect + 理由 flash', function (string $status): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    contractPaidPlan($organization, status: $status);
 
     $this->actingAs($owner)->get('/projects')
-        ->assertRedirect(route('billing.index'));
+        ->assertRedirect(route('billing.index'))
+        ->assertSessionHas('error', BILLING_BLOCKED_MESSAGE);
 })->with(['past_due', 'canceled', 'incomplete', 'unpaid']);
 
-test('未契約の JSON リクエストは 402 Payment Required', function (): void {
-    [, $owner] = createOrganizationWithOwner(subscribed: false);
+test('有償契約 + subscription 行なしは fail-closed (webhook 順序逆転の防御)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $organization->forceFill(['plan_code' => 'standard'])->save(); // 行はあえて作らない
 
-    $this->actingAs($owner)->getJson('/projects')->assertStatus(402);
+    $this->actingAs($owner)->get('/projects')
+        ->assertRedirect(route('billing.index'));
+});
+
+test('有償契約 + 支払い不健全の JSON は 402 + message 固定 (flash と同一文言。非 XHR の Accept: json も含む)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    contractPaidPlan($organization, status: 'past_due');
+
+    // getJson は Accept: application/json のみ付与 (X-Requested-With なし) =
+    // 「JSON を要求する非 XHR クライアント」のケースを踏む (wantsJson 経由で 402 になること)
+    $this->actingAs($owner)->getJson('/projects')
+        ->assertStatus(402)
+        ->assertJsonPath('message', BILLING_BLOCKED_MESSAGE);
 });
 
-test('billing ページは未契約でも到達できる (構造的 allowlist)', function (): void {
-    [, $owner] = createOrganizationWithOwner(subscribed: false);
+test('billing ページは遮断対象の組織でも到達できる (構造的 allowlist)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    contractPaidPlan($organization, status: 'past_due');
 
     $this->actingAs($owner)->get('/billing')->assertOk();
 });
 
-test('BillingAccess は active/trialing のみ許可する (それ以外と未契約は fail-closed)', function (): void {
+// ── 依存するデータモデル契約の固定 (plan_code 不変条件の前提) ──
+
+test('free プランは Stripe Price を持たない (plan_code に free が入る経路がない前提の固定)', function (): void {
+    $free = Plan::query()->where('code', config()->string('quota.fallback_plan'))->firstOrFail();
+
+    // StripeWebhookProcessor::syncPlanCode は price.id → Plan 解決でのみ plan_code を set する。
+    // fallback プランが Price を持たない限り、plan_code に「支払い不要プラン」が載ることはない
+    expect($free->prices()->exists())->toBeFalse();
+});
+
+// ── BillingAccess 単体マトリクス ──
+
+test('BillingAccess: plan_code null は常に許可、非 null は active/trialing のみ許可', function (): void {
     $access = app(BillingAccess::class);
 
-    // 未契約
-    [$noSub] = createOrganizationWithOwner(subscribed: false);
-    expect($access->hasActiveAccess($noSub))->toBeFalse();
+    // 未契約 (free tier)
+    [$freeOrg] = createOrganizationWithOwner();
+    expect($access->hasActiveAccess($freeOrg))->toBeTrue();
+
+    // 未契約 + subscription 行だけある (webhook の plan_code 同期前) も許可 (fail-open は free 相当のみ)
+    [$syncLagOrg] = createOrganizationWithOwner();
+    createFakeSubscription($syncLagOrg, status: 'active');
+    expect($access->hasActiveAccess($syncLagOrg))->toBeTrue();
 
+    // 有償契約状態: status マトリクス
     foreach (['active' => true, 'trialing' => true, 'past_due' => false, 'canceled' => false, 'incomplete' => false] as $status => $expected) {
-        [$organization] = createOrganizationWithOwner(subscribed: false);
-        createFakeSubscription($organization, status: $status);
+        [$organization] = createOrganizationWithOwner();
+        contractPaidPlan($organization, status: $status);
         expect($access->hasActiveAccess($organization))->toBe($expected, "stripe_status={$status}");
     }
+
+    // 有償契約状態 + 行なし: fail-closed
+    [$orphan] = createOrganizationWithOwner();
+    $orphan->forceFill(['plan_code' => 'standard'])->save();
+    expect($access->hasActiveAccess($orphan))->toBeFalse();
 });
 
 /*
@@ -78,12 +136,13 @@
  * (current org の暗黙参照より route が優先。org セグメント route に適用するアプリ向け)。
  */
 
-test('route bound organization が未契約なら redirect される (current org より route 優先)', function (): void {
-    // current org は契約済み、route の org は未契約 (両方 owner が同一メンバー)
+test('route bound organization が有償不健全なら redirect される (current org より route 優先)', function (): void {
+    // current org は Free (許可)、route の org は有償不健全 (両方 owner が同一メンバー)
     [, $owner] = createOrganizationWithOwner();
     $gated = Organization::factory()->create(['slug' => 'gated-org']);
     $gated->users()->attach($owner);
     $owner->addRole(OrganizationRole::Member->value, $gated->laratrust_team_id);
+    contractPaidPlan($gated, status: 'past_due');
 
     Route::middleware(['web', 'auth', 'require-active-subscription'])
         ->get('/__gate-test/{organization:slug}', fn (Organization $organization) => response('ok'));
@@ -95,7 +154,7 @@
 test('非メンバーが binder を通過しても middleware が 404 に倒す (binder 回帰の defense-in-depth)', function (): void {
     // MembershipScopedOrganizationBinder を経由しない直接呼び出しで、binder 回帰
     // (非メンバー org が route param に載る) を再現する。存在秘匿のため 403 ではなく 404。
-    [$organization] = createOrganizationWithOwner(subscribed: false);
+    [$organization] = createOrganizationWithOwner();
     $outsider = User::factory()->create();
 
     $request = Request::create('/__direct', 'GET');
diff --git a/tests/Feature/Billing/SendBillingRemindersTest.php b/tests/Feature/Billing/SendBillingRemindersTest.php
index 41f4ef3..5e931c0 100644
--- a/tests/Feature/Billing/SendBillingRemindersTest.php
+++ b/tests/Feature/Billing/SendBillingRemindersTest.php
@@ -26,11 +26,10 @@ function reminderOrgWithRenewal(int $daysUntilRenewal, bool $withOwner = true):
 {
     if ($withOwner) {
         [$organization] = createOrganizationWithOwner();
-        $subscription = $organization->subscriptions()->sole();
     } else {
         $organization = Organization::factory()->create();
-        $subscription = createFakeSubscription($organization);
     }
+    $subscription = createFakeSubscription($organization);
     /** @var Subscription $subscription */
     $subscription->forceFill(['current_period_end' => now()->addDays($daysUntilRenewal)->subHour()])->save();
 
@@ -111,7 +110,7 @@ function reminderOrgWithRenewal(int $daysUntilRenewal, bool $withOwner = true):
     $organization->stripe_id = 'cus_period_sync_1';
     $organization->save();
     /** @var Subscription $subscription */
-    $subscription = $organization->subscriptions()->sole();
+    $subscription = createFakeSubscription($organization);
     expect($subscription->current_period_end)->toBeNull();
 
     $periodEnd = now()->addMonth()->startOfSecond();
diff --git a/tests/Feature/Billing/TicketCheckoutTest.php b/tests/Feature/Billing/TicketCheckoutTest.php
index a0e74ba..534ff14 100644
--- a/tests/Feature/Billing/TicketCheckoutTest.php
+++ b/tests/Feature/Billing/TicketCheckoutTest.php
@@ -78,7 +78,7 @@ function checkoutPayload(int $count = 30, ?string $token = null): array
 
 test('未契約 org (subscription なし) でも GET/POST に到達できる (課金ゲート対象外)', function (): void {
     $fake = fakeTicketGateway();
-    [, $owner] = createOrganizationWithOwner(subscribed: false);
+    [, $owner] = createOrganizationWithOwner();
 
     $this->actingAs($owner)->get('/purchase-tickets')->assertOk();
 
diff --git a/tests/Feature/DashboardTest.php b/tests/Feature/DashboardTest.php
index c14aa98..ecd6c74 100644
--- a/tests/Feature/DashboardTest.php
+++ b/tests/Feature/DashboardTest.php
@@ -205,7 +205,7 @@ function adoptTakeFor(Cut $cut): Take
             ->where('dashboard.billing.ticket_balance', 10)
             ->where('dashboard.billing.is_low_balance', false)
             ->where('dashboard.billing.storage_used_bytes', 0)
-            ->where('dashboard.billing.has_active_subscription', true));
+            ->where('dashboard.billing.has_billing_access', true));
 });
 
 test('残高/容量: threshold 未満で is_low_balance=true', function (): void {
@@ -409,14 +409,27 @@ function adoptTakeFor(Cut $cut): Take
     expect($member->fresh()->current_organization_id)->toBe($organization->id);
 });
 
-test('未契約 org: dashboard 200 + has_active_subscription=false + CTA 遷移先も 200', function (): void {
-    [$organization, $owner] = createOrganizationWithOwner(subscribed: false);
+test('Free (未契約) org: dashboard 200 + has_billing_access=true + 業務 route 開通', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    Project::factory()->forOrganization($organization)->create();
+
+    $this->actingAs($owner)->get('/dashboard')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('dashboard.billing.has_billing_access', true));
+
+    $this->actingAs($owner)->get('/projects')->assertOk();
+});
+
+test('有償契約 + 支払い不健全 org: has_billing_access=false + CTA 遷移先 200 (redirect loop なし)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    contractPaidPlan($organization, status: 'past_due');
     Project::factory()->forOrganization($organization)->create();
 
     $this->actingAs($owner)->get('/dashboard')
         ->assertOk()
         ->assertInertia(fn (Assert $page) => $page
-            ->where('dashboard.billing.has_active_subscription', false));
+            ->where('dashboard.billing.has_billing_access', false));
 
     // CTA 遷移先は課金ゲート外 (redirect loop なし不変条件)
     $this->actingAs($owner)->get('/purchase-tickets')->assertOk();
diff --git a/tests/Pest.php b/tests/Pest.php
index 981ca36..41b1019 100644
--- a/tests/Pest.php
+++ b/tests/Pest.php
@@ -114,27 +114,39 @@
  * Owner 付きの組織を provisioning 経由で生成する (Default Team 込み)。
  * owner の current_organization_id はこの組織になる。
  *
- * 業務 route (/projects 等) は require-active-subscription で gate されるため、
- * 既定で active な default subscription を持たせる。未契約状態を検証するテストは
- * `subscribed: false` で生成する (RequireActiveSubscriptionMiddlewareTest 参照)。
+ * 生成される組織は Free (未契約 = plan_code null) — 業務 route は free でも通る
+ * (BillingAccess の entitlement 判定)。有償プラン契約状態を検証するテストは
+ * contractPaidPlan() を併用する (RequireActiveSubscriptionMiddlewareTest 参照)。
  *
  * @return array{Organization, User} [organization, owner]
  */
-function createOrganizationWithOwner(string $name = 'テスト組織', bool $subscribed = true): array
+function createOrganizationWithOwner(string $name = 'テスト組織'): array
 {
     $owner = User::factory()->create();
     $organization = app(OrganizationProvisioningService::class)->provision($owner, $name);
 
-    if ($subscribed) {
-        createFakeSubscription($organization);
-    }
-
     return [$organization, $owner];
 }
 
+/**
+ * 組織を有償プラン契約状態にする (plan_code + Cashier subscription 行)。
+ * plan_code は $fillable 外の状態キー (webhook 同期のみ) のため forceFill で明示代入。
+ * BillingAccess は plan_code 非 null の組織にのみ active/trialing subscription を要求する。
+ *
+ * plan_code は PlanSeeder が投入する有償プラン code ('standard') を使う
+ * (プラン名分岐ではなく seeded fixture の参照。アプリコードには入らない)。
+ */
+function contractPaidPlan(Organization $organization, string $status = 'active'): Subscription
+{
+    $organization->forceFill(['plan_code' => 'standard'])->save();
+
+    return createFakeSubscription($organization, status: $status);
+}
+
 /**
  * テスト用の Cashier subscription 行を直接作成する (Stripe には到達しない)。
- * BillingAccess (課金ゲート) は stripe_status が active / trialing のとき許可する。
+ * BillingAccess (課金ゲート) は plan_code 非 null の組織に対して stripe_status が
+ * active / trialing のとき許可する (plan_code null = free tier は行の有無に依らず許可)。
  */
 function createFakeSubscription(
     Organization $organization,
diff --git a/tests/js/pages/Dashboard.test.ts b/tests/js/pages/Dashboard.test.ts
index f1328c9..30b5e6e 100644
--- a/tests/js/pages/Dashboard.test.ts
+++ b/tests/js/pages/Dashboard.test.ts
@@ -15,7 +15,7 @@ function billingData(overrides: Partial<BillingSummary> = {}): BillingSummary {
         storage_used_bytes: 250 * 1024 * 1024,
         storage_limit_bytes: 1024 * 1024 * 1024,
         storage_usage_percent: 25,
-        has_active_subscription: true,
+        has_billing_access: true,
         ...overrides,
     };
 }
@@ -245,18 +245,37 @@ describe("Dashboard", () => {
         expect(screen.getByTestId("stat-tickets")).toHaveTextContent("残高が少なくなっています");
     });
 
-    it("has_active_subscription=false で billing callout が出る", () => {
+    it("has_billing_access=false で billing callout が出る (支払い確認文言 + /billing CTA)", () => {
         render(Dashboard, {
             props: {
                 dashboard: dashboardData({
-                    billing: billingData({ has_active_subscription: false }),
+                    billing: billingData({ has_billing_access: false }),
                 }),
             },
         });
 
         const callout = screen.getByTestId("billing-callout");
         expect(callout).toBeInTheDocument();
-        expect(screen.getByText("プランを見る").getAttribute("href")).toMatch(/\/billing$/);
+        // 表示対象は「有償プラン契約中の支払い不健全」— 新規契約を誘導する文言・CTA への
+        // 後退 (二重契約誘導) を検出するため、文言と遷移先を固定する
+        expect(callout).toHaveTextContent(
+            "サブスクリプションのお支払いが確認できないため、一部機能を一時停止しています。お支払い方法をご確認ください。",
+        );
+        expect(screen.getByText("お支払い方法を確認").getAttribute("href")).toMatch(
+            /\/billing$/,
+        );
+    });
+
+    it("has_billing_access=true で billing callout は出ない", () => {
+        render(Dashboard, {
+            props: {
+                dashboard: dashboardData({
+                    billing: billingData({ has_billing_access: true }),
+                }),
+            },
+        });
+
+        expect(screen.queryByTestId("billing-callout")).toBeNull();
     });
 
     it("disabled 属性を持つ要素が 1 つも存在しない", () => {

```
