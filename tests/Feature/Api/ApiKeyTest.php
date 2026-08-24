<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Project;
use App\Models\SecurityAuditEvent;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * API キーのライフサイクル (発行 / 平文 1 度きり / 失効 / ability gate / cross-org)。
 */

test('owner は API キーを発行できる (平文は flash 経由・DB には Argon2id ハッシュのみ)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->from("/organizations/{$organization->slug}/settings")
        ->post("/organizations/{$organization->slug}/api-keys", [
            'name' => 'CI 用キー',
            'abilities' => ['read', 'write'],
        ]);

    $response->assertRedirect("/organizations/{$organization->slug}/settings");
    $response->assertSessionHas('new_api_key');

    /** @var array{id: int, name: string, plain: string} $newApiKey */
    $newApiKey = session('new_api_key');
    $slug = config('template.slug');
    expect($newApiKey['plain'])->toStartWith($slug.'_');

    /** @var ApiKey $apiKey */
    $apiKey = ApiKey::query()->findOrFail($newApiKey['id']);
    // 平文 "{slug}_{prefix8}_{secret40}" は保存されず、secret の Argon2id ハッシュのみ
    $secret = Str::afterLast($newApiKey['plain'], '_');
    expect(strlen($secret))->toBe(ApiKey::SECRET_RANDOM_LENGTH);
    expect(password_verify($secret, $apiKey->key_hash))->toBeTrue();
    expect($apiKey->key_hash)->not->toBe($newApiKey['plain']);
    expect($newApiKey['plain'])->toStartWith($apiKey->key_prefix.'_');
    expect($apiKey->abilities)->toBe(['read', 'write']);
    expect($apiKey->created_by_user_id)->toBe($owner->id);

    // SecurityEvent (ApiKeyIssued) が記録される
    expect(SecurityAuditEvent::query()->where('event_type', 'api_key_issued')->exists())->toBeTrue();
});

test('平文キーは発行直後の 1 度きり表示 (API キー画面 再訪で消える)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->withSession(['recent_auth_at' => time()])->post("/organizations/{$organization->slug}/api-keys", [
        'name' => 'CI 用キー',
        'abilities' => ['read'],
    ]);

    // 発行直後 (flash が生きている) は newApiKey が渡る (専用 API キー画面)
    $this->actingAs($owner)->get("/organizations/{$organization->slug}/api-keys")
        ->assertInertia(fn (Assert $page) => $page
            ->component('Organizations/ApiKeys/Index')
            ->where('newApiKey.name', 'CI 用キー'));

    // 2 回目以降は消える
    $this->actingAs($owner)->get("/organizations/{$organization->slug}/api-keys")
        ->assertInertia(fn (Assert $page) => $page
            ->component('Organizations/ApiKeys/Index')
            ->where('newApiKey', null));
});

test('member は API キーを発行できない (403)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);

    $this->actingAs($member)->withSession(['recent_auth_at' => time()])->post("/organizations/{$organization->slug}/api-keys", [
        'name' => '不正キー',
        'abilities' => ['read'],
    ])->assertForbidden();

    expect(ApiKey::query()->count())->toBe(0);
});

test('owner は API キーを失効できる (revoked_at + SecurityEvent)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    [$apiKey] = issueApiKey($organization, $owner);

    $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->delete("/organizations/{$organization->slug}/api-keys/{$apiKey->id}")
        ->assertRedirect()
        ->assertSessionHas('success'); // 破壊的操作の flash 規約 (着地先で toast 化される)

    expect($apiKey->refresh()->isRevoked())->toBeTrue();
    expect(SecurityAuditEvent::query()->where('event_type', 'api_key_revoked')->exists())->toBeTrue();
});

test('他組織の API キーは失効できない (認可より前に 404)', function (): void {
    [$organizationA, $ownerA] = createOrganizationWithOwner('組織A');
    [$organizationB, $ownerB] = createOrganizationWithOwner('組織B');
    [$apiKeyB] = issueApiKey($organizationB, $ownerB);

    $this->actingAs($ownerA)
        ->withSession(['recent_auth_at' => time()])
        ->delete("/organizations/{$organizationA->slug}/api-keys/{$apiKeyB->id}")
        ->assertNotFound();

    expect($apiKeyB->refresh()->isRevoked())->toBeFalse();
});

test('失効済みキーでの API アクセスは 401 (unauthenticated envelope)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    [$apiKey, $plain] = issueApiKey($organization, $owner);
    $apiKey->forceFill(['revoked_at' => now()])->save();

    $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated')
        ->assertJsonPath('error.status', 401);
});

test('ability 不足は 403 (insufficient_ability envelope + required_ability)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    [, $plain] = issueApiKey($organization, $owner, ['read']);

    $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => 'アイテム'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'insufficient_ability')
        ->assertJsonPath('error.details.required_ability', 'write');

    expect($project->items()->count())->toBe(0);
});

test('write は read を含意しない (flat ability の strict semantics)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    [, $plain] = issueApiKey($organization, $owner, ['write']);

    $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->getJson('/api/v1/projects')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'insufficient_ability');
});

test('他組織のリソースは 404 (cross-org は存在を漏らさない)', function (): void {
    [$organizationA, $ownerA] = createOrganizationWithOwner('組織A');
    [$organizationB] = createOrganizationWithOwner('組織B');
    $projectB = Project::factory()->forOrganization($organizationB)->create();
    [, $plain] = issueApiKey($organizationA, $ownerA);

    $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->getJson("/api/v1/projects/{$projectB->id}")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

test('認証で last_used_at が更新される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    [$apiKey, $plain] = issueApiKey($organization, $owner);
    expect($apiKey->last_used_at)->toBeNull();

    $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->getJson('/api/v1/me')
        ->assertOk();

    expect($apiKey->refresh()->last_used_at)->not->toBeNull();
});

test('abilities 空での発行は 422 (バリデーション)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->withSession(['recent_auth_at' => time()])->post("/organizations/{$organization->slug}/api-keys", [
        'name' => 'キー',
        'abilities' => [],
    ])->assertSessionHasErrors('abilities');
});
