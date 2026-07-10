<?php

declare(strict_types=1);

use App\Models\OauthSession;
use App\Models\Organization;
use App\Models\User;
use App\Services\OAuth\OauthSessionListService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Laravel\Passport\ClientRepository;

/*
 * OAuth セッション一覧 (WP24): OauthSessionListService の決定的順序 (created_at DESC,
 * id DESC) と legacy MCP token の併記、組織設定画面 props への出し分けを固定する。
 */

/**
 * legacy MCP token (session_id NULL、client_kind='mcp') を 1 件作り access token id を返す。
 */
function makeLegacyMcpToken(Organization $organization, User $user): string
{
    /** @var ClientRepository $repo */
    $repo = app(ClientRepository::class);
    $client = $repo->createAuthorizationCodeGrantClient(
        name: 'Legacy MCP Client',
        redirectUris: ['https://legacy.example/callback'],
        confidential: false,
    );
    DB::table('oauth_clients')->where('id', $client->getKey())->update(['client_kind' => 'mcp']);

    $tokenId = Str::random(80);
    DB::table('oauth_access_tokens')->insert([
        'id' => $tokenId,
        'user_id' => $user->id,
        'organization_id' => $organization->id,
        'session_id' => null,
        'client_id' => $client->getKey(),
        'scopes' => json_encode(['mcp:use']),
        'revoked' => false,
        'created_at' => now(),
        'updated_at' => now(),
        'expires_at' => now()->addDay(),
    ]);

    return $tokenId;
}

test('listForOrganization は created_at DESC の決定的順序で CLI セッションを返す', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('一覧組織');

    $old = OauthSession::factory()->cli()->create([
        'user_id' => $owner->id,
        'organization_id' => $organization->id,
        'created_at' => now()->subDays(2),
    ]);
    $new = OauthSession::factory()->cli()->create([
        'user_id' => $owner->id,
        'organization_id' => $organization->id,
        'created_at' => now()->subDay(),
    ]);
    // 他組織の session は混ざらない
    [$otherOrg, $otherOwner] = createOrganizationWithOwner('他組織');
    OauthSession::factory()->cli()->create([
        'user_id' => $otherOwner->id,
        'organization_id' => $otherOrg->id,
    ]);

    $items = app(OauthSessionListService::class)->listForOrganization($organization);

    expect(array_map(static fn ($dto): string => $dto->id, $items))->toBe([$new->id, $old->id]);
    expect($items[0]->isLegacy)->toBeFalse();
    expect($items[0]->userId)->toBe($owner->id);
});

test('failed/revoked セッションも revokedAt 付きで一覧に残る (棚卸し)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('一覧組織');
    OauthSession::factory()->cli()->revoked()->create([
        'user_id' => $owner->id,
        'organization_id' => $organization->id,
    ]);

    $items = app(OauthSessionListService::class)->listForOrganization($organization);

    expect($items)->toHaveCount(1);
    expect($items[0]->revokedAt)->not->toBeNull();
});

test('legacy MCP token (session 無し) は isLegacy=true で併記される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('一覧組織');
    OauthSession::factory()->cli()->create([
        'user_id' => $owner->id,
        'organization_id' => $organization->id,
        'created_at' => now()->subDay(),
    ]);
    $legacyId = makeLegacyMcpToken($organization, $owner);

    $items = app(OauthSessionListService::class)->listForOrganization($organization);

    expect($items)->toHaveCount(2);
    $legacy = array_values(array_filter($items, static fn ($dto): bool => $dto->isLegacy));
    expect($legacy)->toHaveCount(1);
    expect($legacy[0]->id)->toBe($legacyId);
    expect($legacy[0]->clientKind)->toBe('mcp');
    // legacy は今 (now) 発行なので先頭 (createdAt DESC の全体順序)
    expect($items[0]->id)->toBe($legacyId);
});

test('接続セッション画面は owner に sessions props を渡し、一般メンバーは 403', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('一覧組織');
    $member = attachOrganizationMember($organization);
    $session = OauthSession::factory()->cli()->create([
        'user_id' => $owner->id,
        'organization_id' => $organization->id,
    ]);

    $this->actingAs($owner)
        ->get("/organizations/{$organization->slug}/api-keys/sessions")
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Organizations/ApiKeys/Sessions')
            ->has('sessions', 1)
            ->where('sessions.0.id', $session->id)
            ->has('legacyTokens', 0));

    // 権限境界 (manageForOrganization = manageApiKeys) 外の一般メンバーは 403
    $this->actingAs($member)
        ->get("/organizations/{$organization->slug}/api-keys/sessions")
        ->assertForbidden();
});
