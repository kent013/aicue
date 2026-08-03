<?php

declare(strict_types=1);

use App\Models\OauthSession;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * 組織管理経路の OAuth セッション失効 (WP24)。
 *
 * DELETE /organizations/{organization:slug}/api-keys/sessions/{oauthSession}
 * 認可 = OauthSessionPolicy::manageForOrganization (API キー管理と同一境界 = owner/admin)。
 * recent-auth (step-up) 必須。クロステナントは scopeBindings + binder で 404。
 */

/**
 * 組織に紐づく CLI セッション + 配下 access token を 1 件作る。
 */
function makeCliSessionWithToken(Organization $organization, User $user): OauthSession
{
    /** @var OauthSession $session */
    $session = OauthSession::factory()->cli()->create([
        'user_id' => $user->id,
        'organization_id' => $organization->id,
    ]);

    DB::table('oauth_access_tokens')->insert([
        'id' => Str::random(80),
        'user_id' => $user->id,
        'organization_id' => $organization->id,
        'session_id' => $session->id,
        'client_id' => $session->client_id,
        'scopes' => json_encode(['cli:use', 'read']),
        'revoked' => false,
        'created_at' => now(),
        'updated_at' => now(),
        'expires_at' => now()->addDay(),
    ]);

    return $session;
}

test('owner は recent-auth 済みならセッションを失効でき、配下 token も revoke される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('セッション組織');
    $session = makeCliSessionWithToken($organization, $owner);

    $this->actingAs($owner)->withSession(['recent_auth_at' => time()])
        ->delete("/organizations/{$organization->slug}/api-keys/sessions/{$session->id}")
        ->assertStatus(302)
        ->assertSessionHas('success'); // 破壊的操作の flash 規約 (着地先で toast 化される)

    expect(OauthSession::query()->findOrFail($session->id)->isRevoked())->toBeTrue();

    $revoked = DB::table('oauth_access_tokens')->where('session_id', $session->id)->value('revoked');
    expect((bool) $revoked)->toBeTrue();
});

test('recent-auth が stale なら confirm へ誘導し、失効しない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('セッション組織');
    $session = makeCliSessionWithToken($organization, $owner);

    $this->actingAs($owner)
        ->delete("/organizations/{$organization->slug}/api-keys/sessions/{$session->id}")
        ->assertRedirect(route('recent-auth.confirm'));

    expect(OauthSession::query()->findOrFail($session->id)->isRevoked())->toBeFalse();
});

test('一般メンバーは失効できない (403 = same-org の権限不足)', function (): void {
    [$organization] = createOrganizationWithOwner('セッション組織');
    $member = attachOrganizationMember($organization);
    $session = makeCliSessionWithToken($organization, $member);

    $this->actingAs($member)->withSession(['recent_auth_at' => time()])
        ->delete("/organizations/{$organization->slug}/api-keys/sessions/{$session->id}")
        ->assertForbidden();

    expect(OauthSession::query()->findOrFail($session->id)->isRevoked())->toBeFalse();
});

test('他組織の session id は 404 (scopeBindings による存在秘匿)', function (): void {
    [$organizationA, $ownerA] = createOrganizationWithOwner('組織A');
    [$organizationB, $ownerB] = createOrganizationWithOwner('組織B');
    $sessionB = makeCliSessionWithToken($organizationB, $ownerB);

    $this->actingAs($ownerA)->withSession(['recent_auth_at' => time()])
        ->delete("/organizations/{$organizationA->slug}/api-keys/sessions/{$sessionB->id}")
        ->assertNotFound();

    expect(OauthSession::query()->findOrFail($sessionB->id)->isRevoked())->toBeFalse();
});

test('非メンバーは組織 URL 自体が 404 (テナント存在秘匿)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('セッション組織');
    $session = makeCliSessionWithToken($organization, $owner);
    $outsider = User::factory()->create();

    $this->actingAs($outsider)->withSession(['recent_auth_at' => time()])
        ->delete("/organizations/{$organization->slug}/api-keys/sessions/{$session->id}")
        ->assertNotFound();

    expect(OauthSession::query()->findOrFail($session->id)->isRevoked())->toBeFalse();
});
