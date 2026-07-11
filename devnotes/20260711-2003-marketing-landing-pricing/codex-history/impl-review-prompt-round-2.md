# impl-review Round 2 (確認ラウンド)

あなたは Round 1 で T007 (LP + 料金表 + チケットリチャージ) の実装レビューを行った同じシニア Laravel/Svelte エンジニアの立場で、Round 1 指摘への対応を確認する。前回同様、コマンド実行・ファイル書き込みは禁止 (ファイル読み込みは許可)。

Round 1 の指摘: Critical 1 件 (success_url の org 依存)、Warning 4 件、Suggestion 3 件。

## Claude 側の対応マトリクス

### [Critical] success_url の org 依存 → 対応 (帰還表示の照合を実装) / 404 部分は反論
- 課金の正しさは webhook (ticket_checkout_sessions 行が真実源) が担保し金銭事故は起きない。org 非依存の専用完了ページ新設は「今必要なものだけ作る」原則と current-org スコープの routing 方針に反するスコープ拡大。current org 未設定時の 404 は全 current-org ルート共通の設計。
- 実装: success_url に `&session_id={CHECKOUT_SESSION_ID}` を追加。`TicketCheckoutService::confirmsPurchaseReturn()` を新設し、show() は session_id が current org の checkout 行と一致した時のみ purchased=true (fail-closed)。org 切替中の誤バナーと query 偽装を排除。テスト 3 系統追加。

### [Warning] dedup (org, user) 粒度 → 反論 (意図した設計)
- docblock は当初から「同 (org, user)」と明記。org 単位 dedup は管理者 B が管理者 A の決済途中 session を expire できるユーザー間干渉を生む。別管理者の同時購入は 2 つの意図した独立購入で、付与冪等性は session 単位で webhook が担保。

### [Warning] 金額照合 failure_reason → 対応
- 照合不一致 RuntimeException に expected (count×pin単価, pin通貨) / actual (amount_subtotal, currency) を含めた。

### [Warning] attemptToken stale → 反論 (既に成立)
- submit は `page.attemptToken` を送信時に読む ($props reactive)。validation エラー / 業務エラーは back() redirect → Inertia が show() を再訪し新 token で再レンダー。stale が残るのはクライアント側事前チェックで弾かれた未消費ケースのみ。

### [Warning] alreadyCompleted 分岐の UX 不統一 → 見送り
- 2 経路は意味が異なる (決済完了帰還 vs 受付済み replay)。purchased バナーが session 照合制になったため、replay に purchased=1 を付ける統一はむしろ不正確。

### [Suggestion] PricingService N+1 → 見送り (プラン 2〜3 件 + リクエスト内メモ化、既存共有 API)
### [Suggestion] aria-label → 対応 (付与済み)
### [Suggestion] expired 行への遅延 webhook テスト → 対応 (「付与し completed 化する」方針を pin するテスト追加)

## 検証状況
composer test 1427 passed / phpstan 0 errors / pint / eslint / tsc / vitest 399 passed / build すべて green。

## 依頼
以下の修正 diff (Round 1 レビュー済みコードとの差分) を確認し、(1) 対応が Round 1 の指摘趣旨を満たすか、(2) 反論・見送りの判断が妥当か、(3) 新たな Critical を作り込んでいないか、を判定せよ。

出力形式: `## Critical` / `## Warning` / `## Suggestion` (無いセクションは「なし」) + `## 判定` (対応承認可否を 3 行以内)。

```diff
diff --git a/app/Http/Controllers/Billing/TicketPurchaseController.php b/app/Http/Controllers/Billing/TicketPurchaseController.php
index 9794ff0..aef423d 100644
--- a/app/Http/Controllers/Billing/TicketPurchaseController.php
+++ b/app/Http/Controllers/Billing/TicketPurchaseController.php
@@ -40,14 +40,24 @@ class TicketPurchaseController extends Controller
     private const int DEFAULT_COUNT = 10;
 
     /** 購入画面 (attempt_token は render ごとに ULID 発行) */
-    public function show(Request $request, TicketPricingService $pricing, TicketLedgerService $tickets): Response
-    {
+    public function show(
+        Request $request,
+        TicketPricingService $pricing,
+        TicketLedgerService $tickets,
+        TicketCheckoutService $checkout,
+    ): Response {
         $organization = $this->resolveCurrentOrganization($request);
         Gate::authorize('view', $organization);
 
         $user = $request->user();
         Assert::isInstanceOf($user, User::class);
 
+        // Stripe success_url からの帰還 (表示専用)。session_id を current org の自 DB 行と
+        // 照合できた時のみバナー表示 (org 切替中の誤表示・query 偽装を fail-closed で防ぐ)
+        $sessionId = $request->query('session_id');
+        $purchased = $request->boolean('purchased')
+            && $checkout->confirmsPurchaseReturn($organization, is_string($sessionId) ? $sessionId : null);
+
         $dto = new PurchaseTicketsPageDto(
             tiers: $pricing->volumeTiersForDisplay(),
             minCount: TicketVolumePrice::PURCHASE_MIN_COUNT,
@@ -56,7 +66,7 @@ public function show(Request $request, TicketPricingService $pricing, TicketLedg
             balance: $tickets->balance($organization),
             canManage: $user->can('manageBilling', $organization),
             attemptToken: (string) Str::ulid(),
-            purchased: $request->boolean('purchased'), // Stripe success_url からの帰還 (表示専用)
+            purchased: $purchased,
         );
 
         return Inertia::render('Billing/PurchaseTickets', ['page' => $dto->toArray()]);
diff --git a/app/Services/Billing/StripeWebhookProcessor.php b/app/Services/Billing/StripeWebhookProcessor.php
index acdb374..adc8e8d 100644
--- a/app/Services/Billing/StripeWebhookProcessor.php
+++ b/app/Services/Billing/StripeWebhookProcessor.php
@@ -423,7 +423,15 @@ private function grantPurchasedTickets(array $payload): void
         if (! is_int($amountSubtotal)
             || $amountSubtotal !== $session->ticket_count * $session->unit_amount
             || $currency !== $session->currency) {
-            throw new RuntimeException("ticket purchase webhook: 金額/通貨照合不一致 (session {$sessionId})");
+            // expected/actual を記録する (failed 連鎖時の運用復旧を高速化)
+            throw new RuntimeException(sprintf(
+                'ticket purchase webhook: 金額/通貨照合不一致 (session %s, expected %d %s, actual %s %s)',
+                $sessionId,
+                $session->ticket_count * $session->unit_amount,
+                $session->currency,
+                is_int($amountSubtotal) ? (string) $amountSubtotal : 'missing',
+                $currency ?? 'missing',
+            ));
         }
 
         // (6) 冪等付与 (idempotency_key purchase:{sessionId} UNIQUE) + 行 completed 化 (同一 TX)
diff --git a/app/Services/Billing/TicketCheckoutService.php b/app/Services/Billing/TicketCheckoutService.php
index 558421c..4e679a8 100644
--- a/app/Services/Billing/TicketCheckoutService.php
+++ b/app/Services/Billing/TicketCheckoutService.php
@@ -69,6 +69,25 @@ public function startCheckout(Organization $organization, User $user, int $count
         }
     }
 
+    /**
+     * Stripe success_url 帰還の purchased 表示を検証する (表示専用・fail-closed)。
+     *
+     * session_id が current org の checkout 行と一致する時のみ true。org 切替中の帰還や
+     * 任意 query (?purchased=1) では購入完了バナーを出さない (課金付与は webhook が真実源
+     * のため、ここは表示の正確性のみを守る)。
+     */
+    public function confirmsPurchaseReturn(Organization $organization, ?string $sessionId): bool
+    {
+        if ($sessionId === null || $sessionId === '') {
+            return false;
+        }
+
+        return TicketCheckoutSession::query()
+            ->where('organization_id', $organization->id)
+            ->where('stripe_session_id', $sessionId)
+            ->exists();
+    }
+
     private function startCheckoutLocked(
         Organization $organization,
         User $user,
@@ -127,11 +146,14 @@ private function startCheckoutLocked(
         // (3) Stripe 作成 (idempotency key = purchase:{attemptToken}) → DB 記録。
         //     metadata は照合専用 (認可・org 解決の判断には一切使わない。真実源は ticket_checkout_sessions 行)。
         //     tenant キー不信の誤読を防ぐため organization_id ではなく非権限キー名 org_ref を使う。
+        //     success_url の {CHECKOUT_SESSION_ID} は Stripe 側で実 session id に置換される
+        //     (帰還時に confirmsPurchaseReturn() が current org の自 DB 行と照合し、
+        //     org 切替中の誤バナー・任意 query による purchased 偽装を防ぐ)。
         $created = $this->gateway->createTicketCheckout(
             $organization,
             $tier->stripePriceId,
             $count,
-            route('billing.tickets.show', ['purchased' => 1]),
+            route('billing.tickets.show', ['purchased' => 1]).'&session_id={CHECKOUT_SESSION_ID}',
             route('billing.tickets.show'),
             'purchase:'.$attemptToken,
             [
diff --git a/resources/js/pages/Pricing.svelte b/resources/js/pages/Pricing.svelte
index 81cd496..d8444bd 100644
--- a/resources/js/pages/Pricing.svelte
+++ b/resources/js/pages/Pricing.svelte
@@ -101,6 +101,7 @@
                 表示は各プランの基本料金 (月額) です。AI 解析・動画レンダにはどのプランでも共通のチケットを使います
                 (AI 解析 {page.analysisTicketCost} 枚・動画レンダ {page.renderTicketCost} 枚。<a
                     href="#ticket-pricing"
+                    aria-label="チケット料金セクションへ移動"
                     class="text-primary underline">チケット料金</a
                 >をご覧ください)。
             </p>
diff --git a/tests/Feature/Billing/TicketCheckoutTest.php b/tests/Feature/Billing/TicketCheckoutTest.php
index de578fd..a0e74ba 100644
--- a/tests/Feature/Billing/TicketCheckoutTest.php
+++ b/tests/Feature/Billing/TicketCheckoutTest.php
@@ -104,6 +104,8 @@ function checkoutPayload(int $count = 30, ?string $token = null): array
     expect($fake->created)->toHaveCount(1);
     expect($fake->created[0]['quantity'])->toBe(30);
     expect($fake->created[0]['idempotencyKey'])->toBe("purchase:{$token}");
+    // success_url は purchased=1 + Stripe 置換テンプレート session_id (帰還時の自 org 照合用)
+    expect($fake->created[0]['successUrl'])->toContain('purchased=1&session_id={CHECKOUT_SESSION_ID}');
     expect($fake->created[0]['metadata'])->toBe([
         'purpose' => 'ticket_purchase',
         'org_ref' => (string) $organization->id,
@@ -120,6 +122,37 @@ function checkoutPayload(int $count = 30, ?string $token = null): array
     expect($session->attempt_token)->toBe($token);
 });
 
+test('purchased バナーは session_id が自 org の checkout 行と一致した時のみ表示される', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    TicketCheckoutSession::factory()
+        ->forOrganization($organization)
+        ->initiatedBy($owner)
+        ->create(['stripe_session_id' => 'cs_test_return_1']);
+
+    // 一致 → バナー表示
+    $this->actingAs($owner)->get('/purchase-tickets?purchased=1&session_id=cs_test_return_1')
+        ->assertInertia(fn (Assert $page) => $page->where('page.purchased', true));
+
+    // session_id なし / 未知 session → 非表示 (query 偽装で成功バナーを出さない fail-closed)
+    $this->actingAs($owner)->get('/purchase-tickets?purchased=1')
+        ->assertInertia(fn (Assert $page) => $page->where('page.purchased', false));
+    $this->actingAs($owner)->get('/purchase-tickets?purchased=1&session_id=cs_unknown')
+        ->assertInertia(fn (Assert $page) => $page->where('page.purchased', false));
+});
+
+test('他 org の session_id では purchased バナーを表示しない (org 切替帰還の誤表示防止)', function (): void {
+    [$organizationA, $ownerA] = createOrganizationWithOwner();
+    TicketCheckoutSession::factory()
+        ->forOrganization($organizationA)
+        ->initiatedBy($ownerA)
+        ->create(['stripe_session_id' => 'cs_test_org_a_1']);
+
+    [, $ownerB] = createOrganizationWithOwner();
+
+    $this->actingAs($ownerB)->get('/purchase-tickets?purchased=1&session_id=cs_test_org_a_1')
+        ->assertInertia(fn (Assert $page) => $page->where('page.purchased', false));
+});
+
 test('同一 attempt_token の再送は gateway を呼ばず同一 URL を replay する', function (): void {
     $fake = fakeTicketGateway();
     [, $owner] = createOrganizationWithOwner();
diff --git a/tests/Feature/Billing/TicketPurchaseWebhookTest.php b/tests/Feature/Billing/TicketPurchaseWebhookTest.php
index f510bf5..d670b59 100644
--- a/tests/Feature/Billing/TicketPurchaseWebhookTest.php
+++ b/tests/Feature/Billing/TicketPurchaseWebhookTest.php
@@ -190,6 +190,20 @@ function paidTicketPayload(Organization $organization, string $eventId = 'evt_ti
     'mode=subscription' => [['mode' => 'subscription']],
 ]);
 
+test('expired 行への遅延 completed webhook でも付与し completed 化する (決済成立が真実源)', function (): void {
+    // DB 上 expired 扱いでも Stripe 側で決済が成立していれば completed event が届きうる
+    // (expire API との競合・event 遅延到着)。支払い済みの付与を落とさない方針を pin する
+    [$organization, , $session] = ticketPurchaseFixture();
+    $session->status = TicketCheckoutSessionStatus::Expired;
+    $session->save();
+
+    event(new WebhookReceived(paidTicketPayload($organization)));
+
+    expect(app(TicketLedgerService::class)->balance($organization))->toBe(30);
+    expect($session->refresh()->status)->toBe(TicketCheckoutSessionStatus::Completed);
+    expect($session->completed_at)->not->toBeNull();
+});
+
 test('attempts 上限到達の checkout.session.completed は terminal-ack + report される', function (): void {
     [$organization] = ticketPurchaseFixture();
 
diff --git a/tests/Support/FakeTicketCheckoutGateway.php b/tests/Support/FakeTicketCheckoutGateway.php
index 70cb8ba..0840548 100644
--- a/tests/Support/FakeTicketCheckoutGateway.php
+++ b/tests/Support/FakeTicketCheckoutGateway.php
@@ -19,7 +19,7 @@
  */
 final class FakeTicketCheckoutGateway implements TicketCheckoutGateway
 {
-    /** @var list<array{organizationId: int, stripePriceId: string, quantity: int, idempotencyKey: string, metadata: array<string, string>}> */
+    /** @var list<array{organizationId: int, stripePriceId: string, quantity: int, successUrl: string, cancelUrl: string, idempotencyKey: string, metadata: array<string, string>}> */
     public array $created = [];
 
     /** @var list<string> expire を要求された session id */
@@ -48,6 +48,8 @@ public function createTicketCheckout(
             'organizationId' => $organization->id,
             'stripePriceId' => $stripePriceId,
             'quantity' => $quantity,
+            'successUrl' => $successUrl,
+            'cancelUrl' => $cancelUrl,
             'idempotencyKey' => $idempotencyKey,
             'metadata' => $metadata,
         ];
```
