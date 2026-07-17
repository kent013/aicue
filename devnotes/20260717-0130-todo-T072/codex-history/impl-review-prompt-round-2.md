Round 1 の Critical 1 / Warning 1 を対応した。**指摘は正しく、実装ミスではなく「設計の内部矛盾」が実装時に露見したもの**だった。

## [Critical] webhook が marker を claim せず grantSignupGrant() を直呼び

事実確認した結果、あなたの指摘どおりだった:
- P1 設計は `CreateNewUser` に移行期規約（claim できた時のみ grant）を**適用**する一方、webhook は
  「**引数適合のみ**。claim 追加は P6」としていた（= 実装者は設計どおりに書いていた）。
- しかし **登録経由でない org（`Organizations/Create` の追加組織）は登録時 grant を受けない**ため、
  その org の初回契約で `invoice.paid` が**初回付与**になり、**marker が NULL のまま**残る。
- P3 で activate-personal が配線された後、その org が解約 → personal 有効化すると
  `claimSignupGrantMarker` が成功して **`granted=true` を返すのに ledger の org スコープ UNIQUE が実 insert を止める**
  → **残高は動かないのに「付与した」と応答する**（ユーザーに見える嘘）。

対応: **P1 で webhook にも移行期規約を適用**（`PersonalPlanService` を DI し claim できたときのみ grant）。
**金銭の結果は不変**（ledger UNIQUE で元から冪等）で marker が整合するだけ。
設計側も訂正した（P1 の webhook 行 / P1 のリスク行「P1〜P5 は marker を立てない」を解消 /
P6 の「(b) paid webhook に claim+grant 追加」→「P1 で適用済み」）。
**なお P6 で付与契機が `customer.subscription.created` へ移る（D29）ため、この `invoice.paid` 側 claim は P6 で退役する**。

## [Warning] テストが不整合を「正」として固定していた

対応: 逆順テストの期待を **`granted=false` + webhook 時点で `signup_tickets_granted_at` が非 null** へ更新。
併せて **新規テスト**「登録経由でない組織の初回契約（paid webhook）でもマーカーが立つ（付与実績と真実源が一致する）」
を追加（= 今回の Critical の直接の回帰テスト）。

## [Suggestion] `laratrust_team_id` 書換の検査追加

**見送った**。v2 原則「設計に無いものを足さない」に従い、arch guard の拡張は本 PR（P1 プラン基盤）のスコープ外と判断。
必要なら test テーマの別 TODO で扱う。

## テスト結果（修正後）

- composer test: **1825 tests / 1823 passed / 0 failed / 2 skipped**（7713 assertions）
- composer phpstan: **[OK] No errors**（level 10）/ pint --test: passed
- `SignupGrantOncePerOrgTest`: 7/7 passed

## 追加差分（今回の修正のみ）

```diff
diff --git a/app/Services/Billing/StripeWebhookProcessor.php b/app/Services/Billing/StripeWebhookProcessor.php
index 65e4e13..c0df5b8 100644
--- a/app/Services/Billing/StripeWebhookProcessor.php
+++ b/app/Services/Billing/StripeWebhookProcessor.php
@@ -70,6 +70,7 @@ class StripeWebhookProcessor
     public function __construct(
         private readonly TicketLedgerService $tickets,
         private readonly BillingNotificationDispatcher $notifications,
+        private readonly PersonalPlanService $personalPlan,
     ) {}
 
     public function handle(WebhookReceived $event): void
@@ -263,11 +264,22 @@ private function grantMonthlyTickets(array $payload): void
             return; // サブスク以外の請求 (one-time 等) では付与しない
         }
 
-        // 初回 signup grant (「まず触れる」導線)。冪等キーは org スコープ (grantSignupGrant 内部で生成) のため
-        // subscription id は不要。1 組織 1 回の不変条件は idempotency_key + 部分 UNIQUE index が保証する。
+        // 初回 signup grant (「まず触れる」導線)。冪等キーは org スコープのため subscription id は不要。
+        // 1 組織 1 回の不変条件は idempotency_key + 部分 UNIQUE index が保証する。
         // (通常は登録時に付与済のため、ここは非個人組織のサブスク等に対する no-op ないし 1 回付与の安全網)
         if ($billingReason === 'subscription_create') {
-            $this->tickets->grantSignupGrant($organization);
+            $organizationId = $organization->getKey();
+            Assert::integer($organizationId, 'Organization の主キーは整数を想定しています');
+
+            // 移行期規約 (CreateNewUser と同一): marker を条件付きで先取できたときのみ付与する。
+            // marker (organizations.signup_tickets_granted_at) が付与の唯一の真実源であり、
+            // 「登録経由でない org (追加組織) が初回契約で付与を受ける」経路でも marker を立てないと、
+            // 付与済みなのに marker が NULL のまま残り、後続の PersonalPlanService::activate() が
+            // claim に成功して granted=true を返すのに ledger の org スコープ UNIQUE が実 insert を
+            // 止める (= 残高は動かないのに「付与した」と応答する) 不整合が生じる。
+            if ($this->personalPlan->claimSignupGrantMarker($organization)) {
+                $this->tickets->grantSignupGrant($organization, "signup_grant:org:{$organizationId}");
+            }
         }
 
         $plan = $this->resolveInvoicePlan($payload, $organization);
diff --git a/tests/Feature/Billing/SignupGrantOncePerOrgTest.php b/tests/Feature/Billing/SignupGrantOncePerOrgTest.php
new file mode 100644
index 0000000..ee6a2a9
--- /dev/null
+++ b/tests/Feature/Billing/SignupGrantOncePerOrgTest.php
@@ -0,0 +1,186 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Organization;
+use App\Models\User;
+use App\Services\Billing\PersonalPlanService;
+use App\Services\Billing\TicketLedgerService;
+use App\Services\Organization\OrganizationProvisioningService;
+use Carbon\CarbonImmutable;
+use Laravel\Cashier\Events\WebhookReceived;
+
+/*
+|--------------------------------------------------------------------------
+| 初回無償チケット付与の「org 単位で生涯 1 回」
+|--------------------------------------------------------------------------
+|
+| 真実源は organizations.signup_tickets_granted_at マーカー (条件付き UPDATE の先取)。
+| 二重防御として ticket_ledger_entries の部分 UNIQUE index
+| (organization_id WHERE idempotency_key LIKE 'signup_grant:%') が経路・キー種別を跨いで
+| org 生涯 1 行に閉じる。
+|
+| **移行期規約 (P6 まで)**: 付与契機は登録時 (CreateNewUser) のまま維持し、同一 tx で
+| マーカーを先取する。free 有効化 (PersonalPlanService::activate) は先取できたときのみ付与する。
+*/
+
+function grantOnceCustomer(string $stripeId = 'cus_grant_once'): Organization
+{
+    [$organization] = createOrganizationWithOwner();
+    // stripe_id は Cashier customer column (状態キー)。テストでは明示代入する
+    $organization->stripe_id = $stripeId;
+    $organization->save();
+
+    return $organization;
+}
+
+/**
+ * 初回契約の invoice.paid (billing_reason=subscription_create)。
+ * signup grant は plan 解決より前に走るため lines は不要 (月次付与は plan なしで no-op)。
+ *
+ * @return array<string, mixed>
+ */
+function grantOnceInvoicePaidPayload(string $eventId = 'evt_grant_once', string $stripeId = 'cus_grant_once'): array
+{
+    return [
+        'id' => $eventId,
+        'type' => 'invoice.paid',
+        'data' => [
+            'object' => [
+                'id' => 'in_grant_once',
+                'customer' => $stripeId,
+                'billing_reason' => 'subscription_create',
+            ],
+        ],
+    ];
+}
+
+function grantOnceSignupEntryCount(Organization $organization): int
+{
+    return $organization->ticketLedgerEntries()
+        ->where('idempotency_key', 'like', 'signup_grant:%')
+        ->count();
+}
+
+test('移行期: 登録時に付与され、同一 tx でマーカーも立つ', function (): void {
+    $this->post('/register', [
+        'name' => '山田 太郎',
+        'email' => 'grant-once@example.com',
+        'password' => 'SecurePass1234',
+        'terms_accepted' => '1',
+    ])->assertRedirect(route('verification.notice'));
+
+    $user = User::whereBlind('email', 'email_index', 'grant-once@example.com')->firstOrFail();
+    $organization = $user->organizations()->where('is_personal', true)->firstOrFail();
+
+    // 付与契機・枚数は不変 (現行挙動)
+    expect(app(TicketLedgerService::class)->balance($organization))
+        ->toBe(config()->integer('billing.signup_grant_tickets'));
+    expect($organization->ticketLedgerEntries()->firstOrFail()->idempotency_key)
+        ->toBe("signup_grant:org:{$organization->id}");
+
+    // 移行期に追加される唯一の効果: マーカーが同時に立つ
+    expect($organization->signup_tickets_granted_at)->not->toBeNull();
+});
+
+test('移行期: 登録済み (マーカー済み) の組織を activate しても再付与されない', function (): void {
+    $this->post('/register', [
+        'name' => '鈴木 花子',
+        'email' => 'grant-once-2@example.com',
+        'password' => 'SecurePass1234',
+        'terms_accepted' => '1',
+    ])->assertRedirect(route('verification.notice'));
+
+    $user = User::whereBlind('email', 'email_index', 'grant-once-2@example.com')->firstOrFail();
+    $organization = $user->organizations()->where('is_personal', true)->firstOrFail();
+    $balanceBefore = app(TicketLedgerService::class)->balance($organization);
+
+    $result = app(PersonalPlanService::class)->activate($organization, $user);
+
+    expect($result->granted)->toBeFalse();
+    expect(app(TicketLedgerService::class)->balance($organization))->toBe($balanceBefore);
+    expect(grantOnceSignupEntryCount($organization))->toBe(1);
+});
+
+test('マーカー済み組織への直接 claim は先取できない (条件付き UPDATE の 0 件)', function (): void {
+    $owner = User::factory()->create();
+    $organization = app(OrganizationProvisioningService::class)->provision($owner, 'マーカー済み組織');
+
+    expect(app(PersonalPlanService::class)->claimSignupGrantMarker($organization))->toBeTrue();
+    // 2 回目は既にマーカーが立っているため先取できない (= 付与しない)
+    expect(app(PersonalPlanService::class)->claimSignupGrantMarker($organization))->toBeFalse();
+});
+
+test('free 有効化済みの組織に paid webhook (subscription_create) が来ても二重付与しない', function (): void {
+    $organization = grantOnceCustomer();
+    $owner = $organization->users()->firstOrFail();
+
+    app(PersonalPlanService::class)->activate($organization, $owner);
+    expect(grantOnceSignupEntryCount($organization))->toBe(1);
+    $balanceBefore = app(TicketLedgerService::class)->balance($organization);
+
+    event(new WebhookReceived(grantOnceInvoicePaidPayload()));
+
+    // 部分 UNIQUE index が経路 (signup_grant:personal:% ↔ signup_grant:org:%) を跨いで弾く
+    expect(grantOnceSignupEntryCount($organization))->toBe(1);
+    expect(app(TicketLedgerService::class)->balance($organization))->toBe($balanceBefore);
+});
+
+test('paid webhook で付与済みの組織を free 有効化しても二重付与しない (逆順)', function (): void {
+    $organization = grantOnceCustomer();
+    $owner = $organization->users()->firstOrFail();
+
+    event(new WebhookReceived(grantOnceInvoicePaidPayload()));
+    expect(grantOnceSignupEntryCount($organization))->toBe(1);
+    $balanceBefore = app(TicketLedgerService::class)->balance($organization);
+
+    // paid webhook 経路も移行期規約 (marker 先取できたときのみ付与) に従うため、webhook 時点で
+    // マーカーが立つ。よって後続の activate はマーカーを先取できず granted=false になる
+    // (= 真実源であるマーカーと付与実績が一致する)。
+    expect($organization->refresh()->signup_tickets_granted_at)->not->toBeNull();
+
+    $result = app(PersonalPlanService::class)->activate($organization->refresh(), $owner);
+
+    expect($result->granted)->toBeFalse();
+    expect(grantOnceSignupEntryCount($organization))->toBe(1);
+    expect(app(TicketLedgerService::class)->balance($organization))->toBe($balanceBefore);
+});
+
+test('登録経由でない組織の初回契約 (paid webhook) でもマーカーが立つ (付与実績と真実源が一致する)', function (): void {
+    // 登録時 grant を受けていない組織 (= Organizations/Create で作った追加組織相当) を用意する
+    $organization = grantOnceCustomer();
+    expect($organization->signup_tickets_granted_at)->toBeNull();
+    expect(grantOnceSignupEntryCount($organization))->toBe(0);
+
+    event(new WebhookReceived(grantOnceInvoicePaidPayload()));
+
+    // 付与が起きたなら、その事実がマーカーにも反映されていること (marker = 付与の唯一の真実源)
+    expect(grantOnceSignupEntryCount($organization))->toBe(1);
+    expect($organization->refresh()->signup_tickets_granted_at)->not->toBeNull();
+});
+
+test('backfill migration: 付与履歴のある組織はマーカーが立ち、無い組織は null のまま (冪等)', function (): void {
+    $granted = grantOnceCustomer('cus_backfill_granted');
+    $notGranted = Organization::factory()->create();
+
+    // 既存の付与履歴を作る (サービス経由。台帳は append-only)
+    $grantedAt = CarbonImmutable::parse('2026-05-01 09:00:00');
+    $this->travelTo($grantedAt);
+    app(TicketLedgerService::class)->grantSignupGrant($granted, "signup_grant:org:{$granted->id}");
+    $this->travelBack();
+
+    // migration 適用前の既存データ相当へ戻す (マーカー未設定 + 付与済み)
+    $granted->forceFill(['signup_tickets_granted_at' => null])->save();
+
+    $migration = require database_path('migrations/2026_07_17_000110_backfill_signup_tickets_granted_at.php');
+    $migration->up();
+
+    expect($granted->refresh()->signup_tickets_granted_at?->toDateTimeString())
+        ->toBe('2026-05-01 09:00:00');
+    expect($notGranted->refresh()->signup_tickets_granted_at)->toBeNull();
+
+    // 冪等: 再実行しても値は動かない
+    $migration->up();
+    expect($granted->refresh()->signup_tickets_granted_at?->toDateTimeString())
+        ->toBe('2026-05-01 09:00:00');
+});
```

残る穴があれば指摘し、無ければ APPROVED を出してほしい。
