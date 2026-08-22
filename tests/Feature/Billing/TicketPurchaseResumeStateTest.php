<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Billing\TicketCheckoutSession;
use App\Services\Billing\TicketCheckoutGateway;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeTicketCheckoutGateway;

/*
 * P8b (tc-5): 購入画面の状態機械 + ticketAttemptToken のサーバ側安定化。
 *
 * - live pending (自分が開始した決済待ち) があれば resume へ写像し、token を再利用する
 *   (ブラウザバック / bfcache 復帰で既存 replay 冪等が効く = 二重課金しない)
 * - 完了 session は窓の内外を問わず normal (T088: aigenba F-5-02 追随で完了窓ロックを撤去)
 * - 非管理者には resume を出さない (resumeUrl は Stripe 直リンクで gate を迂回する)
 * - 他 user の pending は resume しない (initiated_by_user_id スコープ)
 */

test('live pending がある owner は resume 状態で既存 token / count / resumeUrl を受け取る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $session = TicketCheckoutSession::factory()
        ->forOrganization($organization)
        ->initiatedBy($owner)
        ->create(['ticket_count' => 42]);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/billing/purchase-tickets")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/PurchaseTickets')
            ->where('page.formState', 'resume')
            ->where('page.ticketAttemptToken', $session->attempt_token)
            ->where('page.boundCount', 42)
            ->where('page.resumeUrl', $session->checkout_url)
            ->where('page.newPurchaseUrl', fn (string $url): bool => str_contains($url, 'fresh=1')));
});

test('?fresh=1 は resume を捨てて normal + 別 token に倒す', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $session = TicketCheckoutSession::factory()
        ->forOrganization($organization)
        ->initiatedBy($owner)
        ->create();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/billing/purchase-tickets?fresh=1")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.formState', 'normal')
            ->where('page.boundCount', null)
            ->where('page.resumeUrl', null)
            ->where('page.ticketAttemptToken', fn (string $t): bool => $t !== $session->attempt_token));
});

test('完了 session は窓内でも normal (T088: aigenba 追随で完了窓ロックを撤去)', function (): void {
    // 旧実装は「直近 30 分の完了」を completed 状態としてフォームをロックしていた。
    // aigenba は 2026-07-30 の bug-hunt (F-5-02) でこれを撤去済み — 完了通知は決済戻り着地の
    // one-shot が担い、二重課金は POST の冪等が担保するため、フォームを塞ぐ必要がない。
    // 塞ぐと「決済成功で戻った直後に、完了案内と『決済を続ける』が同時に出る」誤誘導も生む。
    [$organization, $owner] = createOrganizationWithOwner();
    TicketCheckoutSession::factory()
        ->forOrganization($organization)
        ->initiatedBy($owner)
        ->completed()
        ->create(['ticket_count' => 7, 'completed_at' => CarbonImmutable::now()->subMinutes(5)]);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/billing/purchase-tickets")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.formState', 'normal')
            ->where('page.boundCount', null)
            ->where('page.resumeUrl', null));
});

test('窓外の完了 session も normal のまま', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    TicketCheckoutSession::factory()
        ->forOrganization($organization)
        ->initiatedBy($owner)
        ->completed()
        ->create(['completed_at' => CarbonImmutable::now()->subMinutes(31)]);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/billing/purchase-tickets")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.formState', 'normal')
            ->where('page.boundCount', null));
});

test('期限切れ pending は resume しない (normal)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    TicketCheckoutSession::factory()
        ->forOrganization($organization)
        ->initiatedBy($owner)
        ->stale()
        ->create();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/billing/purchase-tickets")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.formState', 'normal')
            ->where('page.resumeUrl', null));
});

test('決済成功着地 (?purchased=1 + 自 org の session_id) では resume を出さない', function (): void {
    // webhook 未達の一瞬は当該 session がまだ live pending。成功バナーと「決済を続ける」
    // (支払い済み Checkout への直リンク) の同時表示は誤誘導になるため成功着地を優先する。
    [$organization, $owner] = createOrganizationWithOwner();
    $session = TicketCheckoutSession::factory()
        ->forOrganization($organization)
        ->initiatedBy($owner)
        ->create(['ticket_count' => 12]);

    $this->actingAs($owner)
        ->get("/organizations/{$organization->slug}/billing/purchase-tickets?purchased=1&session_id=".$session->stripe_session_id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.purchased', true)
            ->where('page.formState', 'normal')
            ->where('page.boundCount', null)
            ->where('page.resumeUrl', null));
});

test('非管理者 (member) には live pending があっても resume を出さない', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    TicketCheckoutSession::factory()
        ->forOrganization($organization)
        ->initiatedBy($member)
        ->create();

    $this->actingAs($member)->get("/organizations/{$organization->slug}/billing/purchase-tickets")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.formState', 'normal')
            ->where('page.resumeUrl', null));
});

test('他 user が開始した pending は resume しない (initiated_by_user_id スコープ)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);
    TicketCheckoutSession::factory()
        ->forOrganization($organization)
        ->initiatedBy($owner)
        ->create();

    $this->actingAs($admin)->get("/organizations/{$organization->slug}/billing/purchase-tickets")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.formState', 'normal')
            ->where('page.resumeUrl', null));
});

test('resume 表示の token を再送しても Stripe session は増えず同一 URL へ収束する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $fake = new FakeTicketCheckoutGateway;
    app()->instance(TicketCheckoutGateway::class, $fake);

    $session = TicketCheckoutSession::factory()
        ->forOrganization($organization)
        ->initiatedBy($owner)
        ->create(['ticket_count' => 30]);

    // 画面 render 由来の安定 token をそのまま再送する (ブラウザバック相当)
    $response = $this->actingAs($owner)->post("/organizations/{$organization->slug}/billing/purchase-tickets/checkout", [
        'count' => 30,
        'attempt_token' => $session->attempt_token,
    ]);

    $response->assertStatus(302);
    expect($response->headers->get('Location'))->toBe($session->checkout_url)
        ->and($fake->created)->toBe([])
        ->and(TicketCheckoutSession::query()->where('organization_id', $organization->id)->count())->toBe(1);
});
