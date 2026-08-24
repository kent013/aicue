<?php

declare(strict_types=1);

use App\Enums\Billing\TicketCheckoutSessionStatus;
use App\Models\Billing\TicketCheckoutSession;
use App\Models\Billing\TicketVolumePrice;
use App\Models\User;
use App\Services\Billing\TicketCheckoutGateway;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeTicketCheckoutGateway;

/*
 * チケットスポット購入 (/purchase-tickets)。
 * - 閲覧は組織メンバー全員 / Checkout 開始は manageBilling (owner / admin) のみ
 * - 冪等マシン: attempt_token 冪等 / live pending dedup / 期限切れ回収 / race 収束
 * - Stripe には到達しない (FakeTicketCheckoutGateway を bind)
 */

function fakeTicketGateway(): FakeTicketCheckoutGateway
{
    $fake = new FakeTicketCheckoutGateway;
    app()->instance(TicketCheckoutGateway::class, $fake);

    return $fake;
}

function checkoutPayload(int $count = 30, ?string $token = null): array
{
    return ['count' => $count, 'attempt_token' => $token ?? (string) Str::ulid()];
}

test('guest は login へ redirect される', function (): void {
    $this->get('/organizations/guest-org/billing/purchase-tickets')->assertRedirect('/login');
    $this->post('/organizations/guest-org/billing/purchase-tickets/checkout', checkoutPayload())->assertRedirect('/login');
});

test('owner は購入画面で tiers / per-bucket 残高 / canManage / ticketAttemptToken を受け取る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/billing/purchase-tickets")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/PurchaseTickets')
            ->has('page.tiers', 7)
            ->where('page.tiers.0.minCount', 1)
            ->where('page.tiers.0.unitAmount', 100)
            ->where('page.minCount', 1)
            ->where('page.maxCount', 1000)
            ->where('page.defaultCount', 10)
            ->where('page.balance.totalAvailable', 0)
            ->where('page.balance.monthlyRemaining', 0)
            ->where('page.balance.purchasedRemaining', 0)
            ->where('page.balance.nextExpireAt', null)
            ->where('page.canManage', true)
            ->where('page.purchased', false)
            ->where('page.formState', 'normal')
            ->has('page.ticketAttemptToken'));
});

test('fake_external marker query は purchased 表示に転用されない (アプリ非解釈)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    // runtime fake (BughuntFakesServiceProvider) の中立帰還 URL に付く観測用 marker。
    // アプリはこの query を一切解釈しない = purchased 偽装にならないことを固定する
    $this->actingAs($owner)->get("/organizations/{$organization->slug}/billing/purchase-tickets?fake_external=stripe")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('page.purchased', false));
});

test('member は閲覧可能 (canManage=false) だが POST は 403', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);

    $this->actingAs($member)->get("/organizations/{$organization->slug}/billing/purchase-tickets")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.canManage', false));

    $this->actingAs($member)
        ->post("/organizations/{$organization->slug}/billing/purchase-tickets/checkout", checkoutPayload())
        ->assertForbidden();
});

test('非所属の組織 URL は 404 (組織の有無を露出しない)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $user = User::factory()->create();

    $this->actingAs($user)->get("/organizations/{$organization->slug}/billing/purchase-tickets")->assertNotFound();
});

test('未契約 org (subscription なし) でも GET/POST に到達できる (課金ゲート対象外)', function (): void {
    $fake = fakeTicketGateway();
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/billing/purchase-tickets")->assertOk();

    $response = $this->actingAs($owner)
        ->post("/organizations/{$organization->slug}/billing/purchase-tickets/checkout", checkoutPayload());

    $response->assertStatus(302); // Inertia::location (非 Inertia リクエストは 302 redirect)
    expect($fake->created)->toHaveCount(1);
});

test('owner の checkout は gateway 1 回呼び出しで Stripe URL へ遷移し pending 行が pin される', function (): void {
    $fake = fakeTicketGateway();
    [$organization, $owner] = createOrganizationWithOwner();
    $token = (string) Str::ulid();

    // Inertia リクエストでは Inertia::location = 409 + X-Inertia-Location で full page redirect する
    $response = $this->actingAs($owner)
        ->post("/organizations/{$organization->slug}/billing/purchase-tickets/checkout", ['count' => 30, 'attempt_token' => $token], ['X-Inertia' => 'true']);

    $response->assertStatus(409);
    expect($response->headers->get('X-Inertia-Location'))->toBe("https://checkout.stripe.test/c/pay/cs_test_{$token}");

    expect($fake->created)->toHaveCount(1);
    expect($fake->created[0]['quantity'])->toBe(30);
    expect($fake->created[0]['idempotencyKey'])->toBe("purchase:{$token}");
    // success_url は purchased=1 + Stripe 置換テンプレート session_id (帰還時の自 org 照合用)
    expect($fake->created[0]['successUrl'])->toContain('purchased=1&session_id={CHECKOUT_SESSION_ID}');
    expect($fake->created[0]['metadata'])->toBe([
        'purpose' => 'ticket_purchase',
        'org_ref' => (string) $organization->id,
        'count' => '30',
    ]);

    $session = TicketCheckoutSession::query()->firstOrFail();
    expect($session->organization_id)->toBe($organization->id);
    expect($session->initiated_by_user_id)->toBe($owner->id);
    expect($session->ticket_count)->toBe(30);
    expect($session->unit_amount)->toBe(80); // 30 枚 → 20 枚〜段 (¥80) の pin
    expect($session->currency)->toBe('jpy');
    expect($session->status)->toBe(TicketCheckoutSessionStatus::Pending);
    expect($session->attempt_token)->toBe($token);
});

test('purchased バナーは session_id が自 org の checkout 行と一致した時のみ表示される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    TicketCheckoutSession::factory()
        ->forOrganization($organization)
        ->initiatedBy($owner)
        ->create(['stripe_session_id' => 'cs_test_return_1']);

    // 一致 → バナー表示
    $this->actingAs($owner)->get("/organizations/{$organization->slug}/billing/purchase-tickets?purchased=1&session_id=cs_test_return_1")
        ->assertInertia(fn (Assert $page) => $page->where('page.purchased', true));

    // session_id なし / 未知 session → 非表示 (query 偽装で成功バナーを出さない fail-closed)
    $this->actingAs($owner)->get("/organizations/{$organization->slug}/billing/purchase-tickets?purchased=1")
        ->assertInertia(fn (Assert $page) => $page->where('page.purchased', false));
    $this->actingAs($owner)->get("/organizations/{$organization->slug}/billing/purchase-tickets?purchased=1&session_id=cs_unknown")
        ->assertInertia(fn (Assert $page) => $page->where('page.purchased', false));
});

test('他 org の session_id では purchased バナーを表示しない (org 切替帰還の誤表示防止)', function (): void {
    [$organizationA, $ownerA] = createOrganizationWithOwner();
    TicketCheckoutSession::factory()
        ->forOrganization($organizationA)
        ->initiatedBy($ownerA)
        ->create(['stripe_session_id' => 'cs_test_org_a_1']);

    [$organization, $ownerB] = createOrganizationWithOwner();

    $this->actingAs($ownerB)->get("/organizations/{$organization->slug}/billing/purchase-tickets?purchased=1&session_id=cs_test_org_a_1")
        ->assertInertia(fn (Assert $page) => $page->where('page.purchased', false));
});

test('同一 attempt_token の再送は gateway を呼ばず同一 URL を replay する', function (): void {
    $fake = fakeTicketGateway();
    [$organization, $owner] = createOrganizationWithOwner();
    $token = (string) Str::ulid();

    $first = $this->actingAs($owner)
        ->post("/organizations/{$organization->slug}/billing/purchase-tickets/checkout", ['count' => 30, 'attempt_token' => $token]);
    $second = $this->actingAs($owner)
        ->post("/organizations/{$organization->slug}/billing/purchase-tickets/checkout", ['count' => 30, 'attempt_token' => $token]);

    expect($fake->created)->toHaveCount(1);
    expect($second->headers->get('Location'))->toBe($first->headers->get('Location'));
    expect(TicketCheckoutSession::query()->count())->toBe(1);
});

test('別 token・同 count (別タブ想定) は新規作成せず既存 live pending へ replay する', function (): void {
    $fake = fakeTicketGateway();
    [$organization, $owner] = createOrganizationWithOwner();

    $first = $this->actingAs($owner)
        ->post("/organizations/{$organization->slug}/billing/purchase-tickets/checkout", checkoutPayload(30));
    $second = $this->actingAs($owner)
        ->post("/organizations/{$organization->slug}/billing/purchase-tickets/checkout", checkoutPayload(30));

    expect($fake->created)->toHaveCount(1);
    expect($second->headers->get('Location'))->toBe($first->headers->get('Location'));
    expect(TicketCheckoutSession::query()->count())->toBe(1);
});

test('別 token・別 count は既存 pending を Stripe expire してから新規作成する', function (): void {
    $fake = fakeTicketGateway();
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->post("/organizations/{$organization->slug}/billing/purchase-tickets/checkout", checkoutPayload(30));
    $response = $this->actingAs($owner)->post("/organizations/{$organization->slug}/billing/purchase-tickets/checkout", checkoutPayload(50));

    $response->assertStatus(302);
    expect($fake->created)->toHaveCount(2);
    expect($fake->expired)->toHaveCount(1);

    $old = TicketCheckoutSession::query()->where('ticket_count', 30)->firstOrFail();
    expect($old->status)->toBe(TicketCheckoutSessionStatus::Expired);
    $new = TicketCheckoutSession::query()->where('ticket_count', 50)->firstOrFail();
    expect($new->status)->toBe(TicketCheckoutSessionStatus::Pending);
    expect($new->unit_amount)->toBe(70); // 50 枚 → 50 枚〜段 (¥70)
});

test('expire が complete を返したら新規作成せずエラー着地する (直前の決済が処理中)', function (): void {
    $fake = fakeTicketGateway();
    $fake->expireResult = 'complete';
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->post("/organizations/{$organization->slug}/billing/purchase-tickets/checkout", checkoutPayload(30));
    $response = $this->actingAs($owner)->post("/organizations/{$organization->slug}/billing/purchase-tickets/checkout", checkoutPayload(50));

    $response->assertRedirect();
    $response->assertSessionHas('error');
    expect($fake->created)->toHaveCount(1); // 新規作成なし
    // 既存 pending は expired 化されない (complete = 決済側で完結する)
    $session = TicketCheckoutSession::query()->firstOrFail();
    expect($session->status)->toBe(TicketCheckoutSessionStatus::Pending);
});

test('期限切れ pending は replay されず expired 化して新 session を作成する', function (): void {
    $fake = fakeTicketGateway();
    [$organization, $owner] = createOrganizationWithOwner();

    $staleToken = (string) Str::ulid();
    $stale = TicketCheckoutSession::factory()
        ->forOrganization($organization)
        ->initiatedBy($owner)
        ->stale()
        ->create(['ticket_count' => 30, 'attempt_token' => $staleToken]);

    $response = $this->actingAs($owner)->post("/organizations/{$organization->slug}/billing/purchase-tickets/checkout", checkoutPayload(30));

    $response->assertStatus(302);
    expect($fake->created)->toHaveCount(1);
    expect($fake->expired)->toHaveCount(0); // 期限切れは Stripe expire 不要 (pin 値で決定的)
    expect($stale->refresh()->status)->toBe(TicketCheckoutSessionStatus::Expired);
    expect(TicketCheckoutSession::query()->count())->toBe(2);
});

test('completed 済み attempt_token の再送は gateway を呼ばず受付済み着地する', function (): void {
    $fake = fakeTicketGateway();
    [$organization, $owner] = createOrganizationWithOwner();

    $token = (string) Str::ulid();
    TicketCheckoutSession::factory()
        ->forOrganization($organization)
        ->initiatedBy($owner)
        ->completed()
        ->create(['ticket_count' => 30, 'attempt_token' => $token]);

    $response = $this->actingAs($owner)
        ->post("/organizations/{$organization->slug}/billing/purchase-tickets/checkout", ['count' => 30, 'attempt_token' => $token]);

    $response->assertRedirect(route('billing.tickets.show', ['organization' => $organization->slug]));
    $response->assertSessionHas('info');
    expect($fake->created)->toHaveCount(0);
});

test('count 境界外・非整数・attempt_token 不正は validation error になる', function (array $payload, string $errorKey): void {
    $fake = fakeTicketGateway();
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->post("/organizations/{$organization->slug}/billing/purchase-tickets/checkout", $payload)
        ->assertSessionHasErrors($errorKey);
    expect($fake->created)->toHaveCount(0);
})->with([
    'count=0' => [['count' => 0, 'attempt_token' => '01J00000000000000000000000'], 'count'],
    'count=1001' => [['count' => 1001, 'attempt_token' => '01J00000000000000000000000'], 'count'],
    'count 非整数' => [['count' => 'ten', 'attempt_token' => '01J00000000000000000000000'], 'count'],
    'attempt_token 欠落' => [['count' => 10], 'attempt_token'],
    'attempt_token 非 ULID' => [['count' => 10, 'attempt_token' => 'not-a-ulid'], 'attempt_token'],
]);

test('payload に organization_id (保護キー) が混入すると 422', function (): void {
    fakeTicketGateway();
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->post("/organizations/{$organization->slug}/billing/purchase-tickets/checkout", checkoutPayload() + ['organization_id' => $organization->id])
        ->assertSessionHasErrors('organization_id');
});

test('tier が空のとき fail-closed で error flash に着地する (spot へ落ちない)', function (): void {
    $fake = fakeTicketGateway();
    [$organization, $owner] = createOrganizationWithOwner();
    TicketVolumePrice::query()->delete();

    $response = $this->actingAs($owner)->post("/organizations/{$organization->slug}/billing/purchase-tickets/checkout", checkoutPayload(30));

    $response->assertRedirect();
    $response->assertSessionHas('error');
    expect($fake->created)->toHaveCount(0);
});
