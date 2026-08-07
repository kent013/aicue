<?php

declare(strict_types=1);

use App\Enums\Auth\EmailVerificationGateContext;
use App\Enums\OrganizationRole;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

/*
 * EnsureEmailIsVerifiedOrBack (verified.or-back) の挙動。
 * 未認証時は /email/verify への沈黙 302 ではなく back + error flash で戻す。
 * gate 強度は標準 verified と同等 (null user / 未認証 遮断)、応答だけが変わる。
 */

// --- enum fallback route existence (route rename 耐性、app bootstrap 要) ---

it('points every gate context fallback at a real, registered route', function (): void {
    foreach (EmailVerificationGateContext::cases() as $case) {
        expect(Route::has($case->fallbackRouteName()))->toBeTrue();
    }
});

// --- organizations.store ---

it('blocks an unverified user on organizations.store with back + error flash (not /email/verify)', function (): void {
    $user = User::factory()->unverified()->create();

    // referer 無し → fallback (verification.notice) へ back + error flash。
    $response = $this->actingAs($user)
        ->post('/organizations', ['name' => 'New Org']);

    // 旧 verified の挙動 (署名付き /email/verify/{id}/{hash} への silent 302) ではないことを固定する。
    $response->assertRedirect(route('verification.notice'));
    expect($response->headers->get('Location'))->not->toContain('/email/verify/');
    $response->assertSessionHas('error', EmailVerificationGateContext::OrganizationStore->message());
});

it('lets a verified user pass the organizations.store gate', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post('/organizations', ['name' => 'Another Org']);

    // gate は通過し組織が作成される (認証ブロックではない)。
    $response->assertSessionMissing('error');
    $this->assertDatabaseHas('organizations', ['name' => 'Another Org']);
});

it('redirects a guest to login (fail-closed) on organizations.store', function (): void {
    $response = $this->post('/organizations', ['name' => 'Org']);

    $response->assertRedirect(route('login'));
});

// --- referer handling (open-redirect prevention) ---

it('returns to a same-origin referer when present', function (): void {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)
        ->withHeader('referer', url('/organizations/create'))
        ->post('/organizations', ['name' => 'Org']);

    $response->assertRedirect('/organizations/create');
    $response->assertSessionHas('error');
});

it('falls back to verification.notice for a cross-origin referer', function (): void {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)
        ->withHeader('referer', 'https://evil.test/phish')
        ->post('/organizations', ['name' => 'Org']);

    $response->assertRedirect(route('verification.notice'));
});

it('keeps the error flash visible on the final verification.notice screen', function (): void {
    $user = User::factory()->unverified()->create();
    $expected = EmailVerificationGateContext::OrganizationStore->message();

    // 1) gate response: fallback 配線 + flash 生存。
    $response = $this->actingAs($user)
        ->post('/organizations', ['name' => 'Org']);
    $response->assertRedirect(route('verification.notice'));
    $response->assertSessionHas('error', $expected);

    // 2) 同一クライアント (session 保持) で fallback 先へ GET し、gate が積んだ flash が
    //    最終画面の Inertia prop `flash.error` に乗ることを実フローで確認する。
    $this->get(route('verification.notice'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('flash.error', $expected)
        );
});

// --- organizations.invitations.store ---

it('blocks an unverified owner on invitations.store with the invite message and sends no mail', function (): void {
    Notification::fake();
    [$organization, $owner] = createOrganizationWithOwner();
    $owner->forceFill(['email_verified_at' => null])->save();

    $response = $this->actingAs($owner)
        ->from("/organizations/{$organization->slug}/settings")
        ->post("/organizations/{$organization->slug}/invitations", [
            'email' => 'invitee@example.com',
            'role' => OrganizationRole::Admin->value,
        ]);

    // referer (from) が同一オリジンなのでそこへ戻す + invite 文言の error flash。
    $response->assertRedirect("/organizations/{$organization->slug}/settings");
    $response->assertSessionHas('error', EmailVerificationGateContext::Invite->message());
    Notification::assertNothingSent();
});

it('lets a verified owner pass the invitations.store gate', function (): void {
    Notification::fake();
    [$organization, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)
        ->from("/organizations/{$organization->slug}/settings")
        ->post("/organizations/{$organization->slug}/invitations", [
            'email' => 'invitee@example.com',
            'role' => OrganizationRole::Admin->value,
        ]);

    // gate は通過し招待が送信される (back + success flash)。
    $response->assertSessionMissing('error');
    $response->assertSessionHas('success');
});
