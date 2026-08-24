<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Jobs\Billing\SyncBillingCustomerDetails;
use App\Models\Organization;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

/*
 * P9: 請求先連絡先の更新経路 (PATCH /billing/contact)。
 * current-org スコープ (route parameter なし) + manageBilling。
 * Stripe 同期は **email 変更時のみ** BillingCustomerSynchronizer 経由で発火する。
 */

test('email 変更時のみ Stripe 同期 job が dispatch される (name のみの変更では発火しない)', function (): void {
    Queue::fake();
    [$organization, $owner] = createOrganizationWithOwner();
    $organization->forceFill(['stripe_id' => 'cus_contact_1'])->save();

    $this->actingAs($owner)->patch("/organizations/{$organization->slug}/billing/contact", [
        'billing_contact_email' => 'billing@example.test',
        'billing_contact_name' => '経理部',
    ])->assertRedirect();
    Queue::assertPushed(SyncBillingCustomerDetails::class, 1);

    // name だけ変更 (email は同値) → 同期は増えない
    $this->actingAs($owner)->patch("/organizations/{$organization->slug}/billing/contact", [
        'billing_contact_email' => 'billing@example.test',
        'billing_contact_name' => '総務部',
    ])->assertRedirect();
    Queue::assertPushed(SyncBillingCustomerDetails::class, 1);

    expect($organization->fresh()?->billing_contact_name)->toBe('総務部');
});

test('stripe_id 未設定の org では同期 job が dispatch されない', function (): void {
    Queue::fake();
    [$organization, $owner] = createOrganizationWithOwner();
    expect($organization->stripe_id)->toBeNull();

    $this->actingAs($owner)->patch("/organizations/{$organization->slug}/billing/contact", [
        'billing_contact_email' => 'billing@example.test',
    ])->assertRedirect();

    Queue::assertNotPushed(SyncBillingCustomerDetails::class);
});

test('宛名は空文字を null に畳む', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->patch("/organizations/{$organization->slug}/billing/contact", [
        'billing_contact_email' => 'billing@example.test',
        'billing_contact_name' => '   ',
    ])->assertRedirect()->assertSessionHas('info', '請求先情報を更新しました。');

    expect($organization->fresh()?->billing_contact_name)->toBeNull();
});

test('認可: member は 403', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);

    $this->actingAs($member)->patch("/organizations/{$organization->slug}/billing/contact", [
        'billing_contact_email' => 'billing@example.test',
    ])->assertForbidden();
});

test('認可: 未ログインは login へ redirect', function (): void {
    createOrganizationWithOwner();

    $this->patch('/organizations/guest-org/billing/contact', [
        'billing_contact_email' => 'billing@example.test',
    ])->assertRedirect('/login');
});

test('URL スコープ: 別組織の URL への PATCH はその組織だけを更新する', function (): void {
    [$orgA, $owner] = createOrganizationWithOwner('組織 A');
    [$orgB] = createOrganizationWithOwner('組織 B');
    $orgB->users()->attach($owner);
    $owner->addRole(OrganizationRole::Owner->value, $orgB->laratrust_team_id);

    $this->actingAs($owner)->patch("/organizations/{$orgB->slug}/billing/contact", [
        'billing_contact_email' => 'b@example.test',
    ])->assertRedirect();

    expect($orgB->fresh()?->billing_contact_email)->toBe('b@example.test');
    expect($orgA->fresh()?->billing_contact_email)->toBeNull();
});

test('payload 契約: 保護キー混入は 422 / email 欠落も 422', function (array $payload, string $field): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->from("/organizations/{$organization->slug}/billing")
        ->patch("/organizations/{$organization->slug}/billing/contact", $payload)
        ->assertInvalid([$field]);
})->with([
    'organization_id 混入' => [['billing_contact_email' => 'a@example.test', 'organization_id' => 1], 'organization_id'],
    'plan_id 混入' => [['billing_contact_email' => 'a@example.test', 'plan_id' => 1], 'plan_id'],
    'email 欠落' => [[], 'billing_contact_email'],
    'email 形式不正' => [['billing_contact_email' => 'not-an-email'], 'billing_contact_email'],
]);

test('routeNotificationForMail は billing_contact_email 正本 → owner email fallback', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    // 未設定なら owner email
    expect($organization->billingContactEmail())->toBe($owner->email);

    $this->actingAs($owner)->patch("/organizations/{$organization->slug}/billing/contact", [
        'billing_contact_email' => 'billing@example.test',
    ])->assertRedirect();

    /** @var Organization $fresh */
    $fresh = $organization->fresh();
    expect($fresh->billingContactEmail())->toBe('billing@example.test');
});

test('Billing/Index の props に billingContact が載る (未設定なら fallbackEmail が owner)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/billing")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('page.billingContact.email', null)
            ->where('page.billingContact.name', null)
            ->where('page.billingContact.fallbackEmail', $owner->email),
    );
});

test('stripeEmail は請求先メール正本 → owner email fallback (宛名は Stripe へ送らない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    expect($organization->stripeEmail())->toBe($owner->email);

    $this->actingAs($owner)->patch("/organizations/{$organization->slug}/billing/contact", [
        'billing_contact_email' => 'billing@example.test',
        'billing_contact_name' => '経理部',
    ])->assertRedirect();

    /** @var Organization $fresh */
    $fresh = $organization->fresh();
    expect($fresh->stripeEmail())->toBe('billing@example.test');
    // 宛名は送信内容に含めない (stripeName は組織名のまま)
    expect($fresh->stripeName())->toBe($organization->name);
});
