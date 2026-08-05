<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\OAuthTestHelpers;
use Tests\Support\ResponseSignature;

/*
 * テナント境界 404 が「binding より後のあらゆる短絡」より前に走ることの**振る舞い**固定
 * (audit-cycle-2 High-1 / T108 S2)。
 *
 * 不在 id は SubstituteBindings が 404 にする。したがって binding より後・テナント guard
 * より前に 404 以外で短絡する middleware があると、
 *   「他組織に実在 = その短絡の応答 (302/402/409) / 不在 = 404」
 * という 1 bit の存在オラクルになる。監査の横断確認で、課金ゲート 302・verified 302・
 * 2FA 強制 302・Inertia version mismatch 409 のすべてがテナント境界より先に走っていた。
 *
 * 本テストは「その状態のユーザーで cross-org 実在 project と不在 project id を叩き、
 * 応答が **status / ヘッダ / body すべて同一** であること」を固定する
 * (= 分岐しない = オラクル不成立)。同時に「自組織 project では従来どおりの着地」も
 * 固定し、課金ゲートの『行き先のない詰みを作らない』契約を壊していないことを示す。
 *
 * 順序そのものの静的固定は tests/Architecture/TenantBoundaryOrderingTest。
 */

/** 不在の {project} id (18 桁 pattern 内・実在しない)。 */
const TBP_MISSING_PROJECT_ID = '999999999';

/**
 * cross-org の実在 project と 不在 id で応答が完全一致することを表明する。
 *
 * @param  callable(string): TestResponse  $request
 */
function tbpAssertNoOracle(callable $request, Project $crossOrgProject, int $expectedStatus): void
{
    $crossOrg = $request((string) $crossOrgProject->id);
    $missing = $request(TBP_MISSING_PROJECT_ID);

    expect($crossOrg->getStatusCode())->toBe(
        $expectedStatus,
        'cross-org の実在 project が期待した status で閉じていない',
    );
    expect(ResponseSignature::of($crossOrg))->toBe(
        ResponseSignature::of($missing),
        'cross-org 実在 project と 不在 project id の応答が一致しない (存在オラクル)',
    );
}

/** 他組織に実在する project を作る。 */
function tbpForeignProject(): Project
{
    [$otherOrg] = createOrganizationWithOwner('他組織');

    return Project::factory()->forOrganization($otherOrg)->create();
}

test('メール未確認ユーザーでも cross-org 実在 project と不在 id は同一 404', function (): void {
    [, $owner] = createOrganizationWithOwner();
    $owner->forceFill(['email_verified_at' => null])->save();
    $foreign = tbpForeignProject();

    tbpAssertNoOracle(
        fn (string $id) => $this->actingAs($owner)->get("/projects/{$id}"),
        $foreign,
        404,
    );
});

test('未契約組織のユーザーでも cross-org 実在 project と不在 id は同一 404', function (): void {
    // grandfatherFreePlan: false = 真の未契約組織 (課金ゲートが onboarding へ 302 する)
    [, $owner] = createOrganizationWithOwner('未契約組織', grandfatherFreePlan: false);
    $foreign = tbpForeignProject();

    tbpAssertNoOracle(
        fn (string $id) => $this->actingAs($owner)->get("/projects/{$id}"),
        $foreign,
        404,
    );
});

test('2FA 強制の未準拠ユーザーでも cross-org 実在 project と不在 id は同一 404', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $organization->forceFill(['two_factor_required' => true])->save();
    // owner は 2FA 未設定 = 未準拠 (RequireTwoFactorForEnforcedOrganizations が 302 する状態)
    expect($owner->twoFactorStatus()->value)->toBe('disabled');
    $foreign = tbpForeignProject();

    tbpAssertNoOracle(
        fn (string $id) => $this->actingAs($owner)->get("/projects/{$id}"),
        $foreign,
        404,
    );
});

test('Inertia version mismatch (409 契機) でも cross-org 実在 project と不在 id は同一 404', function (): void {
    [, $owner] = createOrganizationWithOwner();
    $foreign = tbpForeignProject();

    tbpAssertNoOracle(
        fn (string $id) => $this->actingAs($owner)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => 'stale-version'])
            ->get("/projects/{$id}"),
        $foreign,
        404,
    );
});

test('未契約組織でも自組織 project は従来どおり課金ゲートの 302 で受ける (詰みを作らない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('未契約組織', grandfatherFreePlan: false);
    $ownProject = Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)
        ->get("/projects/{$ownProject->id}")
        ->assertRedirect();
});

test('メール未確認でも自組織 project は従来どおり verified の 302 で受ける', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $owner->forceFill(['email_verified_at' => null])->save();
    $ownProject = Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)
        ->get("/projects/{$ownProject->id}")
        ->assertRedirect(route('verification.notice'));
});

test('2FA 強制の未準拠ユーザーでも自組織 project は従来どおり 302 で受ける', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $organization->forceFill(['two_factor_required' => true])->save();
    $ownProject = Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)
        ->get("/projects/{$ownProject->id}")
        ->assertRedirect(route('settings.security'));
});

/*
 * --- pre-binding 短絡の性質固定 (S4 検査 4 の behavioral proof) ---
 *
 * SubstituteBindings **より前**に走る短絡 (未認証 302 / throttle 429 / CSRF 419 /
 * actor 解決失敗 401) は、route parameter を読まないため実在 id と不在 id で
 * 応答が分岐しない。静的検査 (TenantBoundaryOrderingTest 検査 4) は
 * 「呼び出し先クラス経由の間接参照」までは証明できないため、実応答でも固定する。
 */

test('未認証 (pre-binding 短絡) では実在 project と不在 id が同一応答', function (): void {
    [$organization] = createOrganizationWithOwner();
    $existing = Project::factory()->forOrganization($organization)->create();

    tbpAssertNoOracle(
        fn (string $id) => $this->get("/projects/{$id}"),
        $existing,
        302,
    );
});

test('API の actor 解決失敗 (pre-binding 短絡) では実在 project と不在 id が同一 401', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $existing = Project::factory()->forOrganization($organization)->create();
    [$apiKey, $plain] = issueApiKey($organization, $owner, ['read', 'write']);
    // 発行者削除 = actor (人間の帰属) を解決できない → 403 actor_not_resolvable
    $apiKey->forceFill(['created_by_user_id' => null])->save();

    $crossOrg = $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->getJson("/api/v1/projects/{$existing->id}/items");
    $missing = $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->getJson('/api/v1/projects/'.TBP_MISSING_PROJECT_ID.'/items');

    expect($crossOrg->getStatusCode())->toBe(403)
        ->and($crossOrg->json('error.code'))->toBe('actor_not_resolvable')
        ->and(ResponseSignature::of($crossOrg))->toBe(ResponseSignature::of($missing));
});

/*
 * --- S2 の副作用 (ResolveApiActor を binding より前へ移した結果) ---
 *
 * actor 解決失敗時の応答は、不在 project id に対しても **404 ではなく 401/403** になる。
 * これは「actor が解決できない = リソースの話に到達していない」という意味論として正しく、
 * かつ実在 id と不在 id で同一のため存在オラクルにならない。
 * 5 つの失効状態を個別に登録し、将来 binding より後ろへ戻されたら red になるようにする。
 */

test('API キー失効後は不在 project id でも 401 (404 にならない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    [$apiKey, $plain] = issueApiKey($organization, $owner, ['read']);
    $apiKey->forceFill(['revoked_at' => now()])->save();

    $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->getJson('/api/v1/projects/'.TBP_MISSING_PROJECT_ID.'/items')
        ->assertUnauthorized();
});

test('API キー発行者削除後は不在 project id でも 403 actor_not_resolvable (404 にならない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    [$apiKey, $plain] = issueApiKey($organization, $owner, ['read']);
    $apiKey->forceFill(['created_by_user_id' => null])->save();

    $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->getJson('/api/v1/projects/'.TBP_MISSING_PROJECT_ID.'/items')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'actor_not_resolvable');
});

test('OAuth トークン失効後は不在 project id でも 401 (404 にならない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $issued = OAuthTestHelpers::issueCliSessionTokens(
        test: $this,
        user: $owner,
        organization: $organization,
        client: OAuthTestHelpers::createMcpClient(name: 'Revoke CLI'),
    );
    DB::table('oauth_access_tokens')->update(['revoked' => true]);
    Auth::forgetGuards();

    $this->withHeaders(['Authorization' => 'Bearer '.$issued['access_token']])
        ->getJson('/api/v1/projects/'.TBP_MISSING_PROJECT_ID.'/items')
        ->assertUnauthorized();
});

test('CLI セッション失効後は不在 project id でも 401 (404 にならない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $issued = OAuthTestHelpers::issueCliSessionTokens(
        test: $this,
        user: $owner,
        organization: $organization,
        client: OAuthTestHelpers::createMcpClient(name: 'Session CLI'),
    );
    $issued['session']->revoke();
    Auth::forgetGuards();

    $this->withHeaders(['Authorization' => 'Bearer '.$issued['access_token']])
        ->getJson('/api/v1/projects/'.TBP_MISSING_PROJECT_ID.'/items')
        ->assertUnauthorized();
});

test('membership 剥奪後は不在 project id でも 401 (404 にならない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $issued = OAuthTestHelpers::issueCliSessionTokens(
        test: $this,
        user: $member,
        organization: $organization,
        client: OAuthTestHelpers::createMcpClient(name: 'Membership CLI'),
    );
    $organization->users()->detach($member->id);
    Auth::forgetGuards();
    expect($owner)->toBeInstanceOf(User::class);

    $this->withHeaders(['Authorization' => 'Bearer '.$issued['access_token']])
        ->getJson('/api/v1/projects/'.TBP_MISSING_PROJECT_ID.'/items')
        ->assertUnauthorized();
});
