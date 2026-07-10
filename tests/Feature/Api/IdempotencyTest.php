<?php

declare(strict_types=1);

use App\Models\IdempotencyKey;
use App\Models\Project;

/*
 * Idempotency-Key middleware (write エンドポイント) の契約:
 * - 同一 key + 同一 body の再送 → 保存レスポンスの再生 (副作用は 1 回)
 * - 同一 key + 異なる body → 409 idempotency_conflict
 * - スコープは (api_key, route_name, key)。TTL (24h) 超過の保存行は未使用扱い
 */

test('同一 Idempotency-Key の再送は保存レスポンスを再生する (副作用 1 回)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    [, $plain] = issueApiKey($organization, $owner);
    $headers = [
        'Authorization' => "Bearer {$plain}",
        'Idempotency-Key' => 'idem-key-001',
    ];
    $payload = ['name' => '一度だけ作る', 'note' => null];

    $first = $this->withHeaders($headers)
        ->postJson("/api/v1/projects/{$project->id}/items", $payload)
        ->assertCreated();

    $second = $this->withHeaders($headers)
        ->postJson("/api/v1/projects/{$project->id}/items", $payload)
        ->assertCreated();

    // 同一レスポンスが再生され、Item は 1 件のみ
    expect($second->json())->toBe($first->json());
    expect($project->items()->count())->toBe(1);
    expect(IdempotencyKey::query()->count())->toBe(1);
});

test('同一 Idempotency-Key + 異なる body は 409 idempotency_conflict', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    [, $plain] = issueApiKey($organization, $owner);
    $headers = [
        'Authorization' => "Bearer {$plain}",
        'Idempotency-Key' => 'idem-key-002',
    ];

    $this->withHeaders($headers)
        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => '最初の body'])
        ->assertCreated();

    $this->withHeaders($headers)
        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => '別の body'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'idempotency_conflict');

    expect($project->items()->count())->toBe(1);
});

test('Idempotency-Key なしの再送は通常どおり毎回実行される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    [, $plain] = issueApiKey($organization, $owner);
    $headers = ['Authorization' => "Bearer {$plain}"];
    $payload = ['name' => '毎回作る'];

    $this->withHeaders($headers)
        ->postJson("/api/v1/projects/{$project->id}/items", $payload)
        ->assertCreated();
    $this->withHeaders($headers)
        ->postJson("/api/v1/projects/{$project->id}/items", $payload)
        ->assertCreated();

    expect($project->items()->count())->toBe(2);
    expect(IdempotencyKey::query()->count())->toBe(0);
});

test('Idempotency-Key は API キー単位でスコープされる (別キーの同名 key は独立)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    [, $plainA] = issueApiKey($organization, $owner, name: 'キーA');
    [, $plainB] = issueApiKey($organization, $owner, name: 'キーB');
    $payload = ['name' => '同じ body'];

    $this->withHeaders([
        'Authorization' => "Bearer {$plainA}",
        'Idempotency-Key' => 'shared-key',
    ])->postJson("/api/v1/projects/{$project->id}/items", $payload)->assertCreated();

    $this->withHeaders([
        'Authorization' => "Bearer {$plainB}",
        'Idempotency-Key' => 'shared-key',
    ])->postJson("/api/v1/projects/{$project->id}/items", $payload)->assertCreated();

    // 別 API キーなので両方とも実行される
    expect($project->items()->count())->toBe(2);
    expect(IdempotencyKey::query()->count())->toBe(2);
});

test('TTL 超過の Idempotency-Key は未使用扱いで再実行される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    [, $plain] = issueApiKey($organization, $owner);
    $headers = [
        'Authorization' => "Bearer {$plain}",
        'Idempotency-Key' => 'idem-key-ttl',
    ];
    $payload = ['name' => 'TTL 検証'];

    $this->withHeaders($headers)
        ->postJson("/api/v1/projects/{$project->id}/items", $payload)
        ->assertCreated();
    expect(IdempotencyKey::query()->count())->toBe(1);

    // TTL (24h) 超過後の再送は保存行を削除して再実行する
    $this->travel(25)->hours();

    $this->withHeaders($headers)
        ->postJson("/api/v1/projects/{$project->id}/items", $payload)
        ->assertCreated();

    expect($project->items()->count())->toBe(2);
    expect(IdempotencyKey::query()->count())->toBe(1);
});

test('Idempotency-Key は route 単位でスコープされる (別 route の同名 key は独立)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    [, $plain] = issueApiKey($organization, $owner);
    $headers = [
        'Authorization' => "Bearer {$plain}",
        'Idempotency-Key' => 'shared-across-routes',
    ];

    $item = $this->withHeaders($headers)
        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => '作成'])
        ->assertCreated()
        ->json('data.id');

    // 別 route (update) での同名 key は conflict にならず独立に実行される
    $this->withHeaders($headers)
        ->patchJson("/api/v1/projects/{$project->id}/items/{$item}", ['name' => '更新後'])
        ->assertOk();

    expect(IdempotencyKey::query()->count())->toBe(2);
    expect($project->items()->firstOrFail()->name)->toBe('更新後');
});

test('IdempotencyKeyFactory: expired 状態は isExpired が真', function (): void {
    $expired = IdempotencyKey::factory()->expired()->create();
    $active = IdempotencyKey::factory()->create();

    expect($expired->isExpired())->toBeTrue();
    expect($active->isExpired())->toBeFalse();
});

test('バリデーション失敗 (非 2xx) は保存されず再送で再実行できる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    [, $plain] = issueApiKey($organization, $owner);
    $headers = [
        'Authorization' => "Bearer {$plain}",
        'Idempotency-Key' => 'idem-key-003',
    ];

    $this->withHeaders($headers)
        ->postJson("/api/v1/projects/{$project->id}/items", ['note' => 'name なし'])
        ->assertUnprocessable();

    expect(IdempotencyKey::query()->count())->toBe(0);

    // 正しい body での再送 (同一 key) は実行される
    $this->withHeaders($headers)
        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => '修正後'])
        ->assertCreated();

    expect($project->items()->count())->toBe(1);
});
