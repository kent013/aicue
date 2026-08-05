<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;
use App\Models\Item;
use App\Models\Organization;
use App\Models\Project;
use Tests\Support\OAuthTestHelpers;

/*
 * REST API v1 Item の認可境界 (web 側 Projects\ItemController と同一の ItemPolicy 境界)。
 *
 * ★不変条件: cross-org は認可より前に 404 (403 で存在を漏らさない)。
 *   認可を足したことで 404 が 403 に劣化していないことを本テストが固定する。
 * ★actor は ApiActorContext::$user (API キー = 発行者 / OAuth = トークン所有者)。
 *   Gate::authorize (default guard) を使うと API キー経路で ApiKey が渡り 500 になるため、
 *   API キー経路と OAuth 経路の両方で 403 を assert する。
 * ★存在オラクル封じ: cross-org の実在 project は「FormRequest のバリデーションより前」に
 *   404 でなければならない (api.project-in-org middleware)。不正 payload で 422、
 *   不在 id で 404 になると、その差分が project の実在を漏らす。
 */

/** viewer (組織 member かつ project ロールなし) を作り API キー平文を返す。 */
function itemAuthorizationViewerApiKey(Organization $organization): string
{
    $viewer = attachOrganizationMember($organization, OrganizationRole::Member);
    [, $plain] = issueApiKey($organization, $viewer, ['read', 'write']);

    return $plain;
}

/** Bearer ヘッダ。 */
function itemAuthorizationBearer(string $plain): array
{
    return ['Authorization' => "Bearer {$plain}"];
}

// --- API キー経路: 権限不足は 403 (ケース 1/2/3/11) ---

test('viewer の API キーでは Item を作成できない (403)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $this->withHeaders(itemAuthorizationBearer(itemAuthorizationViewerApiKey($organization)))
        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => '侵入'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');

    // ケース 11: 副作用が起きていないこと
    expect($project->items()->count())->toBe(0);
});

test('viewer の API キーでは Item を更新できない (403)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $item = Item::factory()->forProject($project)->create(['name' => '元の名前']);

    $this->withHeaders(itemAuthorizationBearer(itemAuthorizationViewerApiKey($organization)))
        ->patchJson("/api/v1/projects/{$project->id}/items/{$item->id}", ['name' => '書き換え'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');

    expect($item->fresh()?->name)->toBe('元の名前');
});

test('viewer の API キーでは Item を削除できない (403) かつ Item が残る', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $item = Item::factory()->forProject($project)->create();

    $this->withHeaders(itemAuthorizationBearer(itemAuthorizationViewerApiKey($organization)))
        ->deleteJson("/api/v1/projects/{$project->id}/items/{$item->id}")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');

    expect($item->fresh())->not->toBeNull();
});

// --- API キー経路: 権限のある actor は通る (ケース 4/5) ---

test('project_admin の API キーは store / update / destroy を通る', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $editor = attachOrganizationMember($organization, OrganizationRole::Member);
    attachProjectMember($project, $editor, ProjectRole::Admin);
    [, $plain] = issueApiKey($organization, $editor, ['read', 'write']);

    $created = $this->withHeaders(itemAuthorizationBearer($plain))
        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => '作成'])
        ->assertCreated();

    $itemId = $created->json('data.id');

    $this->withHeaders(itemAuthorizationBearer($plain))
        ->patchJson("/api/v1/projects/{$project->id}/items/{$itemId}", ['name' => '更新'])
        ->assertOk();

    $this->withHeaders(itemAuthorizationBearer($plain))
        ->deleteJson("/api/v1/projects/{$project->id}/items/{$itemId}")
        ->assertOk();

    expect($project->items()->count())->toBe(0);
});

test('組織 admin の API キーは project ロールが無くても store を通る (継承規則)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);
    [, $plain] = issueApiKey($organization, $admin, ['read', 'write']);

    $this->withHeaders(itemAuthorizationBearer($plain))
        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => '管理者作成'])
        ->assertCreated();

    expect($project->items()->count())->toBe(1);
});

// --- OAuth CLI トークン経路 (ケース 6/7) ---

test('viewer の OAuth CLI トークンでも Item を作成できない (403)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $viewer = attachOrganizationMember($organization, OrganizationRole::Member);

    $issued = OAuthTestHelpers::issueCliSessionTokens(
        test: $this,
        user: $viewer,
        organization: $organization,
        client: OAuthTestHelpers::createMcpClient(name: 'Test CLI Client'),
        scope: 'cli:use read write',
    );

    $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => '侵入'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');

    expect($project->items()->count())->toBe(0);
});

test('project_admin の OAuth CLI トークンは Item を作成できる', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $editor = attachOrganizationMember($organization, OrganizationRole::Member);
    attachProjectMember($project, $editor, ProjectRole::Admin);

    $issued = OAuthTestHelpers::issueCliSessionTokens(
        test: $this,
        user: $editor,
        organization: $organization,
        client: OAuthTestHelpers::createMcpClient(name: 'Test CLI Client'),
        scope: 'cli:use read write',
    );

    $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => 'CLI作成'])
        ->assertCreated();

    expect($project->items()->count())->toBe(1);
});

// --- cross-org は認可より前に 404 (ケース 8/9) ---

test('cross-org の store / update / destroy は 404 (403 ではない)', function (): void {
    [$organizationA] = createOrganizationWithOwner('組織A');
    [$organizationB, $ownerB] = createOrganizationWithOwner('組織B');
    $projectA = Project::factory()->forOrganization($organizationA)->create();
    $itemA = Item::factory()->forProject($projectA)->create();
    // 組織 B の owner (= 組織 B では最強権限) でも、組織 A のリソースには到達できない
    [, $plain] = issueApiKey($organizationB, $ownerB, ['read', 'write']);

    $this->withHeaders(itemAuthorizationBearer($plain))
        ->postJson("/api/v1/projects/{$projectA->id}/items", ['name' => '越境'])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    $this->withHeaders(itemAuthorizationBearer($plain))
        ->patchJson("/api/v1/projects/{$projectA->id}/items/{$itemA->id}", ['name' => '越境'])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    $this->withHeaders(itemAuthorizationBearer($plain))
        ->deleteJson("/api/v1/projects/{$projectA->id}/items/{$itemA->id}")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    expect($itemA->fresh())->not->toBeNull();
});

test('cross-org かつ viewer は認可より前に 404 (403 が返るなら順序が壊れている)', function (): void {
    [$organizationA] = createOrganizationWithOwner('組織A');
    [$organizationB] = createOrganizationWithOwner('組織B');
    $projectA = Project::factory()->forOrganization($organizationA)->create();
    $itemA = Item::factory()->forProject($projectA)->create();

    $this->withHeaders(itemAuthorizationBearer(itemAuthorizationViewerApiKey($organizationB)))
        ->patchJson("/api/v1/projects/{$projectA->id}/items/{$itemA->id}", ['name' => '更新'])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

// --- 判定は URL 上の {project} の組織で行う (ケース 10) ---

test('actor の current_organization_id が別組織でも URL の {project} の組織で判定される', function (): void {
    [$organizationA] = createOrganizationWithOwner('組織A');
    [$organizationB] = createOrganizationWithOwner('組織B');
    $projectA = Project::factory()->forOrganization($organizationA)->create();

    $editor = attachOrganizationMember($organizationA, OrganizationRole::Member);
    attachProjectMember($projectA, $editor, ProjectRole::Admin);
    // Laratrust team 文脈が current org に汚染されないことの固定
    $editor->forceFill(['current_organization_id' => $organizationB->id])->save();

    [, $plain] = issueApiKey($organizationA, $editor, ['read', 'write']);

    $this->withHeaders(itemAuthorizationBearer($plain))
        ->postJson("/api/v1/projects/{$projectA->id}/items", ['name' => '別orgカレント'])
        ->assertCreated();

    expect($projectA->items()->count())->toBe(1);
});

// --- 存在オラクル封じ (ケース 12-15) ---

test('cross-org + 不正 payload の store は 422 ではなく 404', function (): void {
    [$organizationA] = createOrganizationWithOwner('組織A');
    [$organizationB] = createOrganizationWithOwner('組織B');
    $projectA = Project::factory()->forOrganization($organizationA)->create();

    $this->withHeaders(itemAuthorizationBearer(itemAuthorizationViewerApiKey($organizationB)))
        ->postJson("/api/v1/projects/{$projectA->id}/items", ['note' => 'name 欠落'])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

test('cross-org + protected key payload の store は 422 ではなく 404', function (): void {
    [$organizationA] = createOrganizationWithOwner('組織A');
    [$organizationB] = createOrganizationWithOwner('組織B');
    $projectA = Project::factory()->forOrganization($organizationA)->create();

    $this->withHeaders(itemAuthorizationBearer(itemAuthorizationViewerApiKey($organizationB)))
        ->postJson("/api/v1/projects/{$projectA->id}/items", [
            'name' => '越境',
            'project_id' => $projectA->id,
        ])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

test('cross-org + 空 payload の update は 422 ではなく 404', function (): void {
    [$organizationA] = createOrganizationWithOwner('組織A');
    [$organizationB] = createOrganizationWithOwner('組織B');
    $projectA = Project::factory()->forOrganization($organizationA)->create();
    $itemA = Item::factory()->forProject($projectA)->create();

    $this->withHeaders(itemAuthorizationBearer(itemAuthorizationViewerApiKey($organizationB)))
        ->patchJson("/api/v1/projects/{$projectA->id}/items/{$itemA->id}", [])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

test('cross-org の実在 project と 存在しない project id は完全に同一応答', function (): void {
    [$organizationA] = createOrganizationWithOwner('組織A');
    [$organizationB] = createOrganizationWithOwner('組織B');
    $projectA = Project::factory()->forOrganization($organizationA)->create();
    $headers = itemAuthorizationBearer(itemAuthorizationViewerApiKey($organizationB));
    $payload = ['note' => 'name 欠落'];

    $crossOrg = $this->withHeaders($headers)
        ->postJson("/api/v1/projects/{$projectA->id}/items", $payload);
    $missing = $this->withHeaders($headers)
        ->postJson('/api/v1/projects/999999999/items', $payload);

    // status も body も一致 = 実在/不在の識別子が 1 bit も漏れていない
    expect($crossOrg->getStatusCode())->toBe(404)
        ->and($missing->getStatusCode())->toBe(404)
        ->and($crossOrg->json())->toBe($missing->json());
});

test('{item} も cross-project / cross-org / 不在 で完全に同一の 404 応答', function (): void {
    // {item} は scopeBindings ($project->items()) で routing 層に解決される。
    // その結果生まれうる新しい識別差分 (「この project には無いが item 自体は存在する」等) が
    // 漏れていないことを body 一致で証明する
    [$organizationA] = createOrganizationWithOwner('組織A');
    [$organizationB, $ownerB] = createOrganizationWithOwner('組織B');
    $projectB = Project::factory()->forOrganization($organizationB)->create();
    $otherProjectB = Project::factory()->forOrganization($organizationB)->create();
    $itemOfOtherProject = Item::factory()->forProject($otherProjectB)->create();
    $projectA = Project::factory()->forOrganization($organizationA)->create();
    $itemA = Item::factory()->forProject($projectA)->create();

    [, $plain] = issueApiKey($organizationB, $ownerB, ['read', 'write']);
    $headers = itemAuthorizationBearer($plain);
    $payload = ['name' => '更新'];

    // 同じ組織の別 project の item (実在するが {project} には属さない)
    $crossProject = $this->withHeaders($headers)
        ->patchJson("/api/v1/projects/{$projectB->id}/items/{$itemOfOtherProject->id}", $payload);
    // 存在しない item id
    $missingItem = $this->withHeaders($headers)
        ->patchJson("/api/v1/projects/{$projectB->id}/items/999999999", $payload);
    // 他組織の project + その item (project 段で落ちる)
    $crossOrg = $this->withHeaders($headers)
        ->patchJson("/api/v1/projects/{$projectA->id}/items/{$itemA->id}", $payload);

    expect($crossProject->getStatusCode())->toBe(404)
        ->and($missingItem->getStatusCode())->toBe(404)
        ->and($crossOrg->getStatusCode())->toBe(404)
        ->and($crossProject->json())->toBe($missingItem->json())
        ->and($crossProject->json())->toBe($crossOrg->json());

    expect($itemOfOtherProject->fresh()?->name)->not->toBe('更新');
});

// --- idempotency 層との相互作用 (ケース 16) ---

test('403 は Idempotency-Key で再生されない (権限付与後の再送は成功する)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $viewer = attachOrganizationMember($organization, OrganizationRole::Member);
    [, $plain] = issueApiKey($organization, $viewer, ['read', 'write']);
    $headers = ['Authorization' => "Bearer {$plain}", 'Idempotency-Key' => 'fixed-key-001'];
    $payload = ['name' => 'アイテム'];

    $this->withHeaders($headers)
        ->postJson("/api/v1/projects/{$project->id}/items", $payload)
        ->assertForbidden();

    attachProjectMember($project, $viewer, ProjectRole::Admin);
    // relation キャッシュ由来の偽陰性でテスト失敗の原因が切り分けられなくなるのを防ぐ
    $viewer->refresh();
    $project->unsetRelations();

    // 保存済み 403 が再生されるなら 403 のまま = 権限回復後も詰む
    $this->withHeaders($headers)
        ->postJson("/api/v1/projects/{$project->id}/items", $payload)
        ->assertCreated();

    expect($project->items()->count())->toBe(1);
});
