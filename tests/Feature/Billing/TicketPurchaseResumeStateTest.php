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
 * - 完了直後 (窓内) は completed。窓外は normal
 * - 非管理者には resume / completed を出さない (resumeUrl は Stripe 直リンクで gate を迂回する)
 * - 他 user の pending は resume しない (initiated_by_user_id スコープ)
 */

test('live pending がある owner は resume 状態で既存 token / count / resumeUrl を受け取る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $session = TicketCheckoutSession::factory()
        ->forOrganization($organization)
        ->initiatedBy($owner)
        ->create(['ticket_count' => 42]);

    $this->actingAs($owner)->get('/purchase-tickets')
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

    $this->actingAs($owner)->get('/purchase-tickets?fresh=1')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.formState', 'normal')
            ->where('page.boundCount', null)
            ->where('page.resumeUrl', null)
            ->where('page.ticketAttemptToken', fn (string $t): bool => $t !== $session->attempt_token));
});

test('窓内の完了 session は completed 状態 (resumeUrl は null)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    TicketCheckoutSession::factory()
        ->forOrganization($organization)
        ->initiatedBy($owner)
        ->completed()
        ->create(['ticket_count' => 7, 'completed_at' => CarbonImmutable::now()->subMinutes(5)]);

    $this->actingAs($owner)->get('/purchase-tickets')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.formState', 'completed')
            ->where('page.boundCount', 7)
            ->where('page.resumeUrl', null));
});

test('窓外の完了 session は normal へ縮退する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    TicketCheckoutSession::factory()
        ->forOrganization($organization)
        ->initiatedBy($owner)
        ->completed()
        ->create(['completed_at' => CarbonImmutable::now()->subMinutes(31)]);

    $this->actingAs($owner)->get('/purchase-tickets')
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

    $this->actingAs($owner)->get('/purchase-tickets')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.formState', 'normal')
            ->where('page.resumeUrl', null));
});

test('非管理者 (member) には live pending があっても resume を出さない', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();
    TicketCheckoutSession::factory()
        ->forOrganization($organization)
        ->initiatedBy($member)
        ->create();

    $this->actingAs($member)->get('/purchase-tickets')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.formState', 'normal')
            ->where('page.resumeUrl', null));
});

test('他 user が開始した pending は resume しない (initiated_by_user_id スコープ)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);
    $admin->forceFill(['current_organization_id' => $organization->id])->save();
    TicketCheckoutSession::factory()
        ->forOrganization($organization)
        ->initiatedBy($owner)
        ->create();

    $this->actingAs($admin)->get('/purchase-tickets')
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
    $response = $this->actingAs($owner)->post('/purchase-tickets/checkout', [
        'count' => 30,
        'attempt_token' => $session->attempt_token,
    ]);

    $response->assertStatus(302);
    expect($response->headers->get('Location'))->toBe($session->checkout_url)
        ->and($fake->created)->toBe([])
        ->and(TicketCheckoutSession::query()->where('organization_id', $organization->id)->count())->toBe(1);
});
