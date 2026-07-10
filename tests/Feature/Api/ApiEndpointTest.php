<?php

declare(strict_types=1);

use App\Models\Item;
use App\Models\Project;

/*
 * REST API v1 のエンドポイント契約 (統一エラー envelope / org スコープ / nested CRUD)。
 */

test('GET /api/v1/version は未認証で取得できる', function (): void {
    $this->getJson('/api/v1/version')
        ->assertOk()
        ->assertJsonPath('data.api_version', 'v1')
        ->assertJsonStructure(['data' => ['name', 'api_version', 'template_version']]);
});

test('GET /api/v1/me は組織とキーのメタ情報を返す (whoami)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('whoami組織');
    [$apiKey, $plain] = issueApiKey($organization, $owner, ['read']);

    $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.organization.id', $organization->id)
        ->assertJsonPath('data.organization.name', 'whoami組織')
        ->assertJsonPath('data.api_key.id', $apiKey->id)
        ->assertJsonPath('data.api_key.abilities', ['read']);
});

test('未認証 (Bearer なし) は 401 の統一 envelope', function (): void {
    $this->getJson('/api/v1/projects')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated')
        ->assertJsonPath('error.status', 401);
});

test('不正な形式のキーも 401 (prefix 不一致)', function (): void {
    $this->withHeaders(['Authorization' => 'Bearer invalid-token'])
        ->getJson('/api/v1/projects')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});

test('GET /api/v1/projects は自組織のプロジェクトのみ返す', function (): void {
    [$organizationA, $ownerA] = createOrganizationWithOwner('組織A');
    [$organizationB] = createOrganizationWithOwner('組織B');
    $projectA = Project::factory()->forOrganization($organizationA)->create();
    Project::factory()->forOrganization($organizationB)->create();
    [, $plain] = issueApiKey($organizationA, $ownerA);

    $response = $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->getJson('/api/v1/projects')
        ->assertOk();

    /** @var list<array{id: int}> $data */
    $data = $response->json('data');
    expect(array_column($data, 'id'))->toBe([$projectA->id]);
});

test('GET /api/v1/projects/{project} は自組織なら 200', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create(['name' => '対象プロジェクト']);
    [, $plain] = issueApiKey($organization, $owner);

    $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->getJson("/api/v1/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $project->id)
        ->assertJsonPath('data.name', '対象プロジェクト');
});

test('nested items の CRUD (一覧 / 作成 / 更新 / 削除)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    [, $plain] = issueApiKey($organization, $owner);
    $headers = ['Authorization' => "Bearer {$plain}"];

    // 作成 (201。project_id は URL から導出)
    $created = $this->withHeaders($headers)
        ->postJson("/api/v1/projects/{$project->id}/items", [
            'name' => '新しいアイテム',
            'note' => 'メモ',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', '新しいアイテム')
        ->assertJsonPath('data.project_id', $project->id);

    $itemId = $created->json('data.id');
    expect($project->items()->count())->toBe(1);

    // 一覧
    $this->withHeaders($headers)
        ->getJson("/api/v1/projects/{$project->id}/items")
        ->assertOk()
        ->assertJsonPath('data.0.id', $itemId);

    // 更新
    $this->withHeaders($headers)
        ->patchJson("/api/v1/projects/{$project->id}/items/{$itemId}", [
            'name' => '更新後',
            'note' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', '更新後');

    // 削除
    $this->withHeaders($headers)
        ->deleteJson("/api/v1/projects/{$project->id}/items/{$itemId}")
        ->assertOk()
        ->assertJsonPath('data.deleted', true);
    expect($project->items()->count())->toBe(0);
});

test('バリデーション失敗は 422 の統一 envelope (details.errors)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    [, $plain] = issueApiKey($organization, $owner);

    $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->postJson("/api/v1/projects/{$project->id}/items", ['note' => 'name なし'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.status', 422)
        ->assertJsonStructure(['error' => ['details' => ['errors' => ['name']]]]);
});

test('project_id を payload に含めると 422 (ProhibitsProtectedKeys の missing rule)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    [, $plain] = issueApiKey($organization, $owner);

    $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->postJson("/api/v1/projects/{$project->id}/items", [
            'name' => 'アイテム',
            'project_id' => $project->id,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');

    expect($project->items()->count())->toBe(0);
});

test('他組織プロジェクトの items 操作は 404 (URL 整合 guard)', function (): void {
    [$organizationA, $ownerA] = createOrganizationWithOwner('組織A');
    [$organizationB] = createOrganizationWithOwner('組織B');
    $projectB = Project::factory()->forOrganization($organizationB)->create();
    $itemB = Item::factory()->forProject($projectB)->create();
    [, $plain] = issueApiKey($organizationA, $ownerA);
    $headers = ['Authorization' => "Bearer {$plain}"];

    $this->withHeaders($headers)
        ->postJson("/api/v1/projects/{$projectB->id}/items", ['name' => '侵入'])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    $this->withHeaders($headers)
        ->deleteJson("/api/v1/projects/{$projectB->id}/items/{$itemB->id}")
        ->assertNotFound();

    expect($projectB->items()->count())->toBe(1);
});

test('item が別プロジェクト所属なら 404 (2 段目の URL 整合 guard)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $projectA = Project::factory()->forOrganization($organization)->create();
    $projectB = Project::factory()->forOrganization($organization)->create();
    $itemB = Item::factory()->forProject($projectB)->create();
    [, $plain] = issueApiKey($organization, $owner);

    $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->patchJson("/api/v1/projects/{$projectA->id}/items/{$itemB->id}", ['name' => '更新'])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});
