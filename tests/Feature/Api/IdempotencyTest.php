<?php

declare(strict_types=1);

use App\Enums\Idempotency\IdempotencyState;
use App\Models\IdempotencyKey;
use App\Models\Project;

/*
 * Idempotency-Key middleware (write エンドポイント) の契約:
 * - 同一 key + 同一 body の再送 → 保存レスポンスの再生 (副作用は 1 回、Idempotent-Replayed: true)
 * - 同一 key + 異なる body → 409 idempotency_conflict
 * - 4xx/5xx で終わった要求は indeterminate として記録され、**同一キーは再利用できない**
 *   (T139 の破壊的契約変更。release 経路を持たない)
 * - スコープは (api_key, route_name, key)。保持期間 (config idempotency.retention_hours)
 *   超過の保存行は未使用扱い
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

    // 初回応答には Idempotent-Replayed を付けない (再生かどうかを識別できる)
    $first = $this->withHeaders($headers)
        ->postJson("/api/v1/projects/{$project->id}/items", $payload)
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed');

    $second = $this->withHeaders($headers)
        ->postJson("/api/v1/projects/{$project->id}/items", $payload)
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true');

    // 同一レスポンスが再生され、Item は 1 件のみ
    expect($second->json())->toBe($first->json());
    expect($project->items()->count())->toBe(1);
    expect(IdempotencyKey::query()->count())->toBe(1);
    expect(IdempotencyKey::query()->sole()->state)->toBe(IdempotencyState::Completed);
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
        ->assertJsonCount(1)
        ->assertJsonCount(3, 'error')
        ->assertJsonPath('error.code', 'idempotency_conflict')
        ->assertJsonPath('error.status', 409);

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
    expect(IdempotencyKey::query()->pluck('state')->all())
        ->toBe([IdempotencyState::Completed, IdempotencyState::Completed]);
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

    // 保持期間 (config idempotency.retention_hours = 24h) 超過後の再送は
    // 保存行を削除して再実行する
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

test('IdempotencyKeyFactory: 既定は completed / processing と indeterminate は応答列が null', function (): void {
    $completed = IdempotencyKey::factory()->create();
    $processing = IdempotencyKey::factory()->processing()->create();
    $indeterminate = IdempotencyKey::factory()->indeterminate()->create();

    expect($completed->state)->toBe(IdempotencyState::Completed);
    expect($completed->response_status)->toBe(201);

    expect($processing->state)->toBe(IdempotencyState::Processing);
    expect($processing->response_status)->toBeNull();
    expect($processing->response_body)->toBeNull();

    expect($indeterminate->state)->toBe(IdempotencyState::Indeterminate);
    expect($indeterminate->response_status)->toBeNull();
    expect($indeterminate->response_body)->toBeNull();
});

test('バリデーション失敗は indeterminate として記録され、同一キーの再送は 409 になる', function (): void {
    // ★契約変更 (T139): 決着は completed と indeterminate だけで、
    //   release (再実行を許す) 経路を持たない。4xx の後に同じキーは使えない。
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

    $row = IdempotencyKey::query()->sole();
    expect($row->state)->toBe(IdempotencyState::Indeterminate);
    expect($row->response_status)->toBeNull();

    // 同一 body の再送 → 409 indeterminate
    $this->withHeaders($headers)
        ->postJson("/api/v1/projects/{$project->id}/items", ['note' => 'name なし'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'idempotency_indeterminate');

    // 修正した body での再送 → hash 不一致なので 409 conflict (新しいキーが要る)
    $this->withHeaders($headers)
        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => '修正後'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'idempotency_conflict');

    // 新しいキーなら通る (詰まないことの確認)
    $this->withHeaders([...$headers, 'Idempotency-Key' => 'idem-key-004'])
        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => '修正後'])
        ->assertCreated();

    expect($project->items()->count())->toBe(1);
});
