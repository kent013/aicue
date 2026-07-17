Round 2 の Warning を対応した。**指摘は正しく、実害があった** — Round 1 での私の修正が新しい穴を作っていた。

## 事実確認（自分で検証した）

- `claimSignupGrantMarker()` は **素の条件付き UPDATE** で、自前の tx / ロックを持たない
  （`activate()` 側は `activateWithinTransaction()` が `DB::transaction` + `lockForUpdate` で包んでいたから安全だった）。
- webhook の `DB::transaction`（L120）は **冪等記録の獲得 `claim()` だけ**を包んでおり、
  `grantMonthlyTickets()` は **tx の外**（L178 の dispatch）で走る。
- つまりあなたの指摘どおり、**marker の UPDATE が単独 commit され、その後 `grantSignupGrant()` が失敗すると
  marker だけ残って付与が永久に失われる**（marker が真実源なので Stripe 再送でも二度と付与されない）。

## 対応

1. webhook の claim+grant を **org 行ロック下の単一 transaction** に閉じた
   （`DB::transaction` + `Organization::query()->lockForUpdate()->findOrFail()` = `activate()` と同一パターン）。
2. **回帰テストを追加**: 「paid webhook: 付与が失敗したら marker も rollback される
   （marker だけ残って付与が永久に失われない）」。
3. **負のコントロールで検証済み**: tx を外すと当該テストが **fail**（marker が Carbon のまま残る）/ 戻すと **pass**。

## テスト結果

- composer test: **1826 tests / 1824 passed / 0 failed / 2 skipped**（7717 assertions）
- composer phpstan: **[OK] No errors**（level 10）/ pint: passed
- `SignupGrantOncePerOrgTest`: 8/8 passed

## 追加差分

```diff
diff --git a/app/Services/Billing/StripeWebhookProcessor.php b/app/Services/Billing/StripeWebhookProcessor.php
index 65e4e13..1b27c73 100644
--- a/app/Services/Billing/StripeWebhookProcessor.php
+++ b/app/Services/Billing/StripeWebhookProcessor.php
@@ -70,6 +70,7 @@ class StripeWebhookProcessor
     public function __construct(
         private readonly TicketLedgerService $tickets,
         private readonly BillingNotificationDispatcher $notifications,
+        private readonly PersonalPlanService $personalPlan,
     ) {}
 
     public function handle(WebhookReceived $event): void
@@ -263,11 +264,29 @@ private function grantMonthlyTickets(array $payload): void
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
+            // 移行期規約 (CreateNewUser / PersonalPlanService::activate と同一): org 行ロック下の
+            // 単一 transaction で「marker の条件付き先取 → 先取できたときのみ付与」を原子的に行う。
+            // marker (organizations.signup_tickets_granted_at) が付与の唯一の真実源であるため:
+            //  - marker を立てないと、「登録経由でない org (追加組織) が初回契約で付与を受ける」経路で
+            //    付与済みなのに marker が NULL のまま残り、後続の activate() が claim に成功して
+            //    granted=true を返すのに ledger の org スコープ UNIQUE が実 insert を止める
+            //    (= 残高は動かないのに「付与した」と応答する) 不整合が生じる。
+            //  - 逆に marker だけ先に commit されて付与が失敗すると、marker が立っているため
+            //    再送でも二度と付与されない (= 付与の取りこぼしが恒久化する)。よって同一 tx に閉じる。
+            DB::transaction(function () use ($organizationId): void {
+                $locked = Organization::query()->lockForUpdate()->findOrFail($organizationId);
+
+                if ($this->personalPlan->claimSignupGrantMarker($locked)) {
+                    $this->tickets->grantSignupGrant($locked, "signup_grant:org:{$organizationId}");
+                }
+            });
         }
 
         $plan = $this->resolveInvoicePlan($payload, $organization);
diff --git a/tests/Feature/Billing/SignupGrantOncePerOrgTest.php b/tests/Feature/Billing/SignupGrantOncePerOrgTest.php
new file mode 100644
index 0000000..787a6ee
--- /dev/null
+++ b/tests/Feature/Billing/SignupGrantOncePerOrgTest.php
@@ -0,0 +1,211 @@
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
+test('paid webhook: 付与が失敗したら marker も rollback される (marker だけ残って付与が永久に失われない)', function (): void {
+    $organization = grantOnceCustomer();
+    expect($organization->signup_tickets_granted_at)->toBeNull();
+
+    // grantSignupGrant が失敗する状況を作る (付与のみ throw。marker の UPDATE は既に走っている)
+    $this->mock(TicketLedgerService::class, function ($mock): void {
+        $mock->shouldReceive('grantSignupGrant')
+            ->once()
+            ->andThrow(new RuntimeException('grant failed'));
+        // 月次付与など他経路は素通しさせない (本テストは signup grant の原子性のみを見る)
+        $mock->shouldIgnoreMissing();
+    });
+
+    // webhook 処理は例外を握って failed 記録する契約のため、ここでは例外の有無を問わない
+    try {
+        event(new WebhookReceived(grantOnceInvoicePaidPayload()));
+    } catch (Throwable) {
+        // 冪等マシンの failed 記録経路。marker の原子性が本テストの関心
+    }
+
+    // marker だけ commit されていたら、以後 claim できず二度と付与されない = 恒久的な取りこぼし
+    expect($organization->refresh()->signup_tickets_granted_at)->toBeNull();
+    expect(grantOnceSignupEntryCount($organization))->toBe(0);
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
