<?php

declare(strict_types=1);

use App\Enums\AdminConsoleRole;
use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;
use App\Enums\Security\OrgAccessRevocationReason;
use App\Enums\SecurityEventType;
use App\Models\ApiKey;
use App\Models\OauthSession;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SecurityAuditEvent;
use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;
use App\Services\Security\SecurityEventRecorder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Support\OAuthTestHelpers;

/*
 * 組織の役割変更に同期したトークン失効 (家系の正典 v2) の振る舞い。
 *
 * 失効の境界は「役割を変える操作が成功したこと」であり、役割の集合の差分は取らない。
 * その帰結として**昇格でも接続はやり直しになる**。ここではその仕様と、
 * 失効する 3 家族 / 失効させないもの (組織の API キー・プロジェクト単位の役割) の
 * 境界を固定する。
 */

/**
 * 資格情報の 1 揃い (セッション / セッション付きトークン / セッション無しトークン /
 * 更新トークン / 未交換の認可コード) を作る。
 *
 * `oauth_*` は Passport の vendor テーブルで Factory を持たない
 * (`OauthSession` だけが自前モデル) ため、素の insert で組む。
 *
 * @return array{session: OauthSession, bound: string, orphan: string, refresh: string, code: string}
 */
function revocationCredentials(Organization $organization, User $user): array
{
    /** @var OauthSession $session */
    $session = OauthSession::factory()->cli()->create([
        'user_id' => $user->id,
        'organization_id' => $organization->id,
    ]);

    $clientId = $session->client_id;

    $bound = revocationInsertAccessToken($organization, $user, $clientId, $session->id);
    $orphan = revocationInsertAccessToken($organization, $user, $clientId, null);
    $refresh = revocationInsertRefreshToken($bound);
    $code = revocationInsertAuthCode($organization, $user, $clientId);

    return [
        'session' => $session,
        'bound' => $bound,
        'orphan' => $orphan,
        'refresh' => $refresh,
        'code' => $code,
    ];
}

function revocationInsertAccessToken(Organization $organization, User $user, string $clientId, ?string $sessionId, bool $revoked = false): string
{
    $id = Str::random(80);
    DB::table('oauth_access_tokens')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'organization_id' => $organization->id,
        'session_id' => $sessionId,
        'client_id' => $clientId,
        'scopes' => json_encode(['cli:use', 'read']),
        'revoked' => $revoked,
        'created_at' => now(),
        'updated_at' => now(),
        'expires_at' => now()->addDay(),
    ]);

    return $id;
}

function revocationInsertRefreshToken(string $accessTokenId, bool $revoked = false): string
{
    $id = Str::random(80);
    DB::table('oauth_refresh_tokens')->insert([
        'id' => $id,
        'access_token_id' => $accessTokenId,
        'revoked' => $revoked,
        'expires_at' => now()->addDays(14),
    ]);

    return $id;
}

function revocationInsertAuthCode(Organization $organization, User $user, string $clientId, bool $revoked = false): string
{
    $id = Str::random(80);
    DB::table('oauth_auth_codes')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'organization_id' => $organization->id,
        'session_id' => null,
        'client_id' => $clientId,
        'scopes' => json_encode(['cli:use']),
        'revoked' => $revoked,
        'expires_at' => now()->addMinutes(10),
    ]);

    return $id;
}

/** アクセストークンが失効済みか。 */
function revocationTokenIsRevoked(string $id): bool
{
    return (bool) DB::table('oauth_access_tokens')->where('id', $id)->value('revoked');
}

/** 更新トークンが失効済みか。 */
function revocationRefreshIsRevoked(string $id): bool
{
    return (bool) DB::table('oauth_refresh_tokens')->where('id', $id)->value('revoked');
}

/** 認可コードが失効済みか。 */
function revocationCodeIsRevoked(string $id): bool
{
    return (bool) DB::table('oauth_auth_codes')->where('id', $id)->value('revoked');
}

/** 直近の失効監査 (metadata 付き)。 */
function revocationLatestAudit(): ?SecurityAuditEvent
{
    /** @var SecurityAuditEvent|null $event */
    $event = SecurityAuditEvent::query()
        ->where('event_type', SecurityEventType::OrganizationAccessRevoked->value)
        ->orderByDesc('id')
        ->first();

    return $event;
}

/** 失効監査の件数。 */
function revocationAuditCount(): int
{
    return SecurityAuditEvent::query()
        ->where('event_type', SecurityEventType::OrganizationAccessRevoked->value)
        ->count();
}

// ---------------------------------------------------------------------------
// A. 失効そのもの
// ---------------------------------------------------------------------------

test('降格すると 3 家族 (セッション / トークン / 認可コード) がまとめて失効する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('失効組織');
    $member = attachOrganizationMember($organization, OrganizationRole::Admin);
    $credentials = revocationCredentials($organization, $member);

    app(OrganizationMembershipService::class)
        ->changeRole($organization, $member, OrganizationRole::Member, $owner);

    expect($credentials['session']->fresh()?->revoked_at)->not->toBeNull();
    expect(revocationTokenIsRevoked($credentials['bound']))->toBeTrue();
    expect(revocationTokenIsRevoked($credentials['orphan']))->toBeTrue();
    expect(revocationRefreshIsRevoked($credentials['refresh']))->toBeTrue();
    expect(revocationCodeIsRevoked($credentials['code']))->toBeTrue();
});

test('昇格でも接続は切れる (役割の差分で判断しない仕様)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('昇格組織');
    $member = attachOrganizationMember($organization);
    $credentials = revocationCredentials($organization, $member);

    app(OrganizationMembershipService::class)
        ->changeRole($organization, $member, OrganizationRole::Admin, $owner);

    expect($credentials['session']->fresh()?->revoked_at)->not->toBeNull();
    expect(revocationTokenIsRevoked($credentials['bound']))->toBeTrue();
});

test('同じ役割への変更 (冪等の早期 return) では失効しない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('冪等組織');
    $member = attachOrganizationMember($organization);
    $credentials = revocationCredentials($organization, $member);

    app(OrganizationMembershipService::class)
        ->changeRole($organization, $member, OrganizationRole::Member, $owner);

    expect($credentials['session']->fresh()?->revoked_at)->toBeNull();
    expect(revocationTokenIsRevoked($credentials['bound']))->toBeFalse();
    expect(revocationAuditCount())->toBe(0);
});

test('除名すると失効する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('除名組織');
    $member = attachOrganizationMember($organization);
    $credentials = revocationCredentials($organization, $member);

    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);

    expect($credentials['session']->fresh()?->revoked_at)->not->toBeNull();
    expect(revocationTokenIsRevoked($credentials['bound']))->toBeTrue();
    expect(revocationCodeIsRevoked($credentials['code']))->toBeTrue();
});

test('オーナー移譲では譲り手と受け手の両方が切れる (受け手も切れる)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('移譲組織');
    $successor = attachOrganizationMember($organization, OrganizationRole::Admin);

    $ownerCredentials = revocationCredentials($organization, $owner);
    $successorCredentials = revocationCredentials($organization, $successor);

    app(OrganizationMembershipService::class)->transferOwnership($organization, $owner, $successor);

    expect($ownerCredentials['session']->fresh()?->revoked_at)->not->toBeNull();
    expect(revocationTokenIsRevoked($ownerCredentials['bound']))->toBeTrue();
    expect($successorCredentials['session']->fresh()?->revoked_at)->not->toBeNull();
    expect(revocationTokenIsRevoked($successorCredentials['bound']))->toBeTrue();
});

test('修復経路 (役割未付与の行への直接付与) でも失効する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('修復組織');
    Project::factory()->forOrganization($organization)->create();

    // 異常行を再現: attach のみでロール未付与 (表示状態は「未割当」)
    $broken = User::factory()->create();
    $organization->users()->attach($broken);
    $credentials = revocationCredentials($organization, $broken);

    app(OrganizationMembershipService::class)
        ->applyConsoleRole($organization, $broken, AdminConsoleRole::Shooter, $owner);

    expect($credentials['session']->fresh()?->revoked_at)->not->toBeNull();
    expect(revocationTokenIsRevoked($credentials['bound']))->toBeTrue();
});

test('プロジェクト側の割当だけが変わり組織ロールが同値なら失効しない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('割当組織');
    $project = Project::factory()->forOrganization($organization)->create();
    $member = attachOrganizationMember($organization);
    attachProjectMember($project, $member, ProjectRole::Admin);
    $credentials = revocationCredentials($organization, $member);

    // editor → shooter は組織ロールが Member のまま (プロジェクト側の pivot だけ変わる)
    app(OrganizationMembershipService::class)
        ->applyConsoleRole($organization, $member, AdminConsoleRole::Shooter, $owner);

    expect($project->memberRole($member))->toBe(ProjectRole::Member);
    expect($credentials['session']->fresh()?->revoked_at)->toBeNull();
    expect(revocationAuditCount())->toBe(0);
});

test('招待受諾 (組織に入れる操作) では失効しない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('招待組織');
    $invitee = User::factory()->create();

    $invitation = app(OrganizationMembershipService::class)
        ->inviteMember($organization, $owner, 'invitee@example.test', OrganizationRole::Member);
    // 平文 token は保存されないため、既知の平文に対応する hash へ差し替える
    $invitation->forceFill(['token_hash' => hash('sha256', 'join-token')])->save();

    $this->actingAs($invitee)->post('/invitations/accept', ['token' => 'join-token'])
        ->assertRedirect('/dashboard');

    expect($organization->users()->whereKey($invitee->getKey())->exists())->toBeTrue();
    // 免除の前提: 入れる操作では失効の窓口を呼ばない (監査が 1 行も増えない)
    expect(revocationAuditCount())->toBe(0);
});

// ---------------------------------------------------------------------------
// B. 家族ごとの独立性と網羅性
// ---------------------------------------------------------------------------

test('セッション行が 1 件も無くても、トークンと認可コードは失効する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('セッション無し組織');
    $member = attachOrganizationMember($organization);

    $client = OAuthTestHelpers::createMcpClient(name: 'セッション無し');
    $clientId = (string) $client->getKey();
    $token = revocationInsertAccessToken($organization, $member, $clientId, null);
    $code = revocationInsertAuthCode($organization, $member, $clientId);

    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);

    expect(revocationTokenIsRevoked($token))->toBeTrue();
    expect(revocationCodeIsRevoked($code))->toBeTrue();

    $audit = revocationLatestAudit();
    expect($audit?->metadata['revoked']['sessions'] ?? null)->toBe(0);
    expect($audit?->metadata['revoked']['access_tokens'] ?? null)->toBe(1);
});

test('親のトークンが既に失効済みで更新トークンだけ未失効の不整合行も失効する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('不整合組織');
    $member = attachOrganizationMember($organization);

    $client = OAuthTestHelpers::createMcpClient(name: '不整合');
    $clientId = (string) $client->getKey();
    // 親は失効済み・子は未失効 (母集団を「未失効の親」に絞ると取り逃す形)
    $parent = revocationInsertAccessToken($organization, $member, $clientId, null, revoked: true);
    $refresh = revocationInsertRefreshToken($parent, revoked: false);

    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);

    expect(revocationRefreshIsRevoked($refresh))->toBeTrue();
});

test('他組織 / 他利用者の資格情報は 1 件も巻き添えにならない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('対象組織');
    [$otherOrganization] = createOrganizationWithOwner('別組織');
    $member = attachOrganizationMember($organization);
    $bystander = attachOrganizationMember($organization);

    // 同じ人の別組織ぶん
    $otherOrganization->users()->attach($member);
    $member->addRole(OrganizationRole::Member->value, $otherOrganization->laratrust_team_id);

    $target = revocationCredentials($organization, $member);
    $crossOrg = revocationCredentials($otherOrganization, $member);
    $otherUser = revocationCredentials($organization, $bystander);

    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);

    expect(revocationTokenIsRevoked($target['bound']))->toBeTrue();
    expect(revocationTokenIsRevoked($crossOrg['bound']))->toBeFalse();
    expect($crossOrg['session']->fresh()?->revoked_at)->toBeNull();
    expect(revocationTokenIsRevoked($otherUser['bound']))->toBeFalse();
    expect($otherUser['session']->fresh()?->revoked_at)->toBeNull();
});

test('除名の前に発行された認可コードは失効し、その後の交換が成立しない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('認可コード組織');
    $member = attachOrganizationMember($organization);

    $pkce = OAuthTestHelpers::generatePkcePair();
    $client = OAuthTestHelpers::createMcpClient(name: '認可コード確認');
    $redirectUri = 'https://test.example/callback';

    $this->actingAs($member);
    $this->get(OAuthTestHelpers::buildAuthorizeUrl(
        clientId: (string) $client->getKey(),
        redirectUri: $redirectUri,
        codeChallenge: $pkce['code_challenge'],
    ));
    $approve = $this->post('/oauth/authorize', [
        'auth_token' => session('authToken'),
        'organization_id' => $organization->id,
    ]);
    $code = OAuthTestHelpers::parseCallbackParams($approve)['code'] ?? '';
    expect($code)->not->toBe('');

    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);
    Auth::forgetGuards();

    $response = OAuthTestHelpers::exchangeTokenForm($this, [
        'grant_type' => 'authorization_code',
        'client_id' => (string) $client->getKey(),
        'redirect_uri' => $redirectUri,
        'code_verifier' => $pkce['code_verifier'],
        'code' => $code,
    ]);

    expect($response->getStatusCode())->toBeGreaterThanOrEqual(400);
    expect($response->json('access_token'))->toBeNull();
});

// ---------------------------------------------------------------------------
// C. ひとまとまりであること
// ---------------------------------------------------------------------------

/*
 * 「ひとまとまりの外から窓口を呼ぶと例外になる」ことは**このレーンでは確認できない**。
 * Feature / Unit レーンは RefreshDatabase が全体をトランザクションで包むため、
 * トランザクションの深さが 0 の状態を作れないからである。
 * その検査は tests/Architecture/OrganizationAccessRevocationChokePointTest.php に置く
 * (Architecture レーンは RefreshDatabase を使わない)。
 */

test('役割変更が例外で失敗したら失効も巻き戻る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('巻き戻し組織');
    $credentials = revocationCredentials($organization, $owner);

    // 最後の Owner は降格できない (ロック下の検証で例外)
    expect(fn () => app(OrganizationMembershipService::class)
        ->changeRole($organization, $owner, OrganizationRole::Member, $owner))
        ->toThrow(ValidationException::class);

    expect($credentials['session']->fresh()?->revoked_at)->toBeNull();
    expect(revocationTokenIsRevoked($credentials['bound']))->toBeFalse();
    expect(revocationAuditCount())->toBe(0);
});

test('監査が書けないときは役割の変更ごと巻き戻る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('監査失敗組織');
    $member = attachOrganizationMember($organization);
    $credentials = revocationCredentials($organization, $member);

    $this->partialMock(SecurityEventRecorder::class, function ($mock): void {
        $mock->shouldReceive('recordOrFail')->andThrow(new RuntimeException('監査が書けない'));
    });

    expect(fn () => app(OrganizationMembershipService::class)
        ->changeRole($organization, $member, OrganizationRole::Admin, $owner))
        ->toThrow(RuntimeException::class);

    expect($member->fresh()?->organizationRole($organization))->toBe(OrganizationRole::Member);
    expect($credentials['session']->fresh()?->revoked_at)->toBeNull();
    expect(revocationTokenIsRevoked($credentials['bound']))->toBeFalse();
});

// ---------------------------------------------------------------------------
// D. 監査
// ---------------------------------------------------------------------------

test('失効が 0 件でも監査が 1 行残る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('0件組織');
    $member = attachOrganizationMember($organization);

    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);

    expect(revocationAuditCount())->toBe(1);
    $audit = revocationLatestAudit();
    expect($audit?->metadata['revoked'] ?? null)->toBe([
        'sessions' => 0,
        'access_tokens' => 0,
        'refresh_tokens' => 0,
        'auth_codes' => 0,
    ]);
});

test('監査に理由・操作した人・家族ごとの件数が入る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('監査組織');
    $member = attachOrganizationMember($organization);
    revocationCredentials($organization, $member);

    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);

    $audit = revocationLatestAudit();
    expect($audit)->not->toBeNull();
    expect($audit?->user_id)->toBe($member->id);
    expect($audit?->metadata['reason'] ?? null)->toBe(OrgAccessRevocationReason::MemberRemoved->value);
    expect($audit?->metadata['actor_user_id'] ?? null)->toBe($owner->id);
    expect($audit?->metadata['organization_id'] ?? null)->toBe($organization->id);
    expect($audit?->metadata['revoked']['sessions'] ?? null)->toBe(1);
    expect($audit?->metadata['revoked']['access_tokens'] ?? null)->toBe(2);
    expect($audit?->metadata['revoked']['refresh_tokens'] ?? null)->toBe(1);
    expect($audit?->metadata['revoked']['auth_codes'] ?? null)->toBe(1);
});

test('オーナー移譲の監査は譲り手と受け手で理由が分かれる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('移譲監査組織');
    $successor = attachOrganizationMember($organization, OrganizationRole::Admin);

    app(OrganizationMembershipService::class)->transferOwnership($organization, $owner, $successor);

    $reasons = SecurityAuditEvent::query()
        ->where('event_type', SecurityEventType::OrganizationAccessRevoked->value)
        ->orderBy('id')
        ->get()
        ->map(fn (SecurityAuditEvent $event): mixed => $event->metadata['reason'] ?? null)
        ->all();

    expect($reasons)->toBe([
        OrgAccessRevocationReason::OwnershipTransferredFrom->value,
        OrgAccessRevocationReason::OwnershipTransferredTo->value,
    ]);
});

// ---------------------------------------------------------------------------
// E. 実際に使えなくなること (端から端まで)
// ---------------------------------------------------------------------------

test('除名の後はその人のトークンで外部 API の読み取りも書き込みも叩けない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('API組織');
    $project = Project::factory()->forOrganization($organization)->create();
    $member = attachOrganizationMember($organization, OrganizationRole::Admin);
    $client = OAuthTestHelpers::createMcpClient(name: 'CLI クライアント');

    $issued = OAuthTestHelpers::issueCliSessionTokens(
        test: $this,
        user: $member,
        organization: $organization,
        client: $client,
    );

    // 除名の前は読み取りも書き込みも通る (403/401 が除名以外の理由でないことの対照)
    $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
        ->getJson('/api/v1/me')
        ->assertOk();
    $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => '除名前の作成'])
        ->assertCreated();

    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);
    Auth::forgetGuards();

    $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
        ->getJson('/api/v1/me')
        ->assertUnauthorized();
    Auth::forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => '失効後の作成'])
        ->assertUnauthorized();

    expect($project->items()->count())->toBe(1);
});

test('除名の後は更新トークンでの再発行が拒否される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('再発行組織');
    $member = attachOrganizationMember($organization);
    $client = OAuthTestHelpers::createMcpClient(name: '再発行クライアント');

    $issued = OAuthTestHelpers::issueCliSessionTokens(
        test: $this,
        user: $member,
        organization: $organization,
        client: $client,
    );

    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);
    Auth::forgetGuards();

    $response = OAuthTestHelpers::exchangeTokenForm($this, [
        'grant_type' => 'refresh_token',
        'client_id' => (string) $client->getKey(),
        'refresh_token' => $issued['refresh_token'],
    ]);

    expect($response->getStatusCode())->toBeGreaterThanOrEqual(400);
    expect($response->json('access_token'))->toBeNull();
});

test('除名の後はその人のトークンで MCP を叩けない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('MCP失効組織');
    $member = attachOrganizationMember($organization);

    config()->set('mcp.allowed_origins', ['https://claude.ai']);
    config()->set('mcp.strict_transport', true);

    $client = OAuthTestHelpers::createMcpClient(name: 'MCP クライアント');
    $tokens = OAuthTestHelpers::exchangeForTokensUsing(
        test: $this,
        user: $member,
        organization: $organization,
        client: $client,
        pkce: OAuthTestHelpers::generatePkcePair(),
        redirectUri: 'https://test.example/callback',
    );
    Auth::forgetGuards();

    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);
    Auth::forgetGuards();

    $this->withHeaders([
        'Origin' => 'https://claude.ai',
        'Authorization' => 'Bearer '.$tokens['access_token'],
    ])->postJson('/api/v1/mcp', [
        'jsonrpc' => '2.0',
        'method' => 'tools/call',
        'params' => ['name' => 'whoami', 'arguments' => []],
        'id' => 1,
    ])->assertUnauthorized();
});

test('接続セッション一覧に失効済みとして並ぶ', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('一覧確認組織');
    $member = attachOrganizationMember($organization);
    $credentials = revocationCredentials($organization, $member);

    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);

    $this->actingAs($owner)
        ->get("/organizations/{$organization->slug}/api-keys/sessions")
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Organizations/ApiKeys/Sessions')
            // ★件数を先に固定してから添字で見る (1 件しか無いので添字が一意に定まる。
            //   件数を固定しないと、並び順が変わったときに別の行を見て緑になりうる)
            ->has('sessions', 1)
            ->where('sessions.0.id', $credentials['session']->id)
            ->whereNot('sessions.0.revokedAt', null));
});

// ---------------------------------------------------------------------------
// F. 失効させないものの境界 (誇張しないことの固定)
// ---------------------------------------------------------------------------

test('除名された発行者の API キーでも読み取りは通る (組織の資産として振る舞う)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('鍵読み取り組織');
    $issuer = attachOrganizationMember($organization, OrganizationRole::Admin);
    [, $plain] = issueApiKey($organization, $issuer, ['read', 'write']);

    app(OrganizationMembershipService::class)->removeMember($organization, $issuer, $owner);

    $this->withHeader('Authorization', "Bearer {$plain}")
        ->getJson('/api/v1/me')
        ->assertOk();
});

test('除名された発行者の API キーでの書き込みは認可で拒否される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('鍵書き込み組織');
    $issuer = attachOrganizationMember($organization, OrganizationRole::Admin);
    $project = Project::factory()->forOrganization($organization)->create();
    // ★write ability を必ず持たせる。持たせないと資格不足の 403 で緑になり、
    //   認可の再評価を通っていない実装でも通ってしまう。
    [, $plain] = issueApiKey($organization, $issuer, ['read', 'write']);

    // ★除名の**前**に同じ要求が通ることを先に確かめる。これが無いと、403 が
    //   除名以外の理由 (資格不足・冪等キー不足・テナント境界) で返っていても緑になる。
    $this->withHeader('Authorization', "Bearer {$plain}")
        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => '除名前の作成'])
        ->assertCreated();

    app(OrganizationMembershipService::class)->removeMember($organization, $issuer, $owner);

    $this->withHeader('Authorization', "Bearer {$plain}")
        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => '失効後の作成'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');

    // 副作用が起きていないこと (1 件目だけが残る)
    expect($project->items()->count())->toBe(1);
});

test('組織の API キーは失効の対象外 (窓口を呼んでも 1 行も変わらない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('鍵不変組織');
    $member = attachOrganizationMember($organization);
    [$apiKey] = issueApiKey($organization, $member, ['read']);

    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);

    /** @var ApiKey|null $fresh */
    $fresh = ApiKey::query()->find($apiKey->getKey());
    expect($fresh)->not->toBeNull();
    expect($fresh?->revoked_at)->toBeNull();
});

test('プロジェクト単位の役割変更では失効しない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('プロジェクト役割組織');
    $project = Project::factory()->forOrganization($organization)->create();
    $member = attachOrganizationMember($organization);
    attachProjectMember($project, $member, ProjectRole::Member);
    $credentials = revocationCredentials($organization, $member);

    // プロジェクト側のロール更新は store の再実行 (syncWithoutDetaching)
    $this->actingAs($owner)
        ->post("/projects/{$project->id}/members", [
            'user_id' => $member->id,
            'role' => ProjectRole::Admin->value,
        ])
        ->assertSessionHas('success');

    expect($project->memberRole($member))->toBe(ProjectRole::Admin);
    expect($credentials['session']->fresh()?->revoked_at)->toBeNull();
    expect(revocationAuditCount())->toBe(0);
});
