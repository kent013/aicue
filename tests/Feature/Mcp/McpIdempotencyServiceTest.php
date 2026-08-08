<?php

declare(strict_types=1);

use App\Exceptions\Mcp\IdempotencyConflictException;
use App\Exceptions\Mcp\InvalidParamsException;
use App\Models\McpIdempotencyKey;
use App\Services\Mcp\McpIdempotencyService;
use App\Values\Mcp\IdempotencyKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 * MCP 書き込み系 tool 用冪等性ストア (McpIdempotencyService) の契約:
 * 同一 user の純粋 retry は replay、payload 改変は conflict、TTL 超過は再実行、
 * caller 境界は (organization_id, user_id, tool_name, key)。
 */

beforeEach(function (): void {
    [$this->org, $this->user] = createOrganizationWithOwner();
    $this->service = app(McpIdempotencyService::class);
    $this->key = new IdempotencyKey((string) Str::uuid());
});

test('未登録の key は replay されない (null)', function (): void {
    $result = $this->service->replay(
        $this->org->id, $this->user->id, 'test-tool', $this->key, ['a' => 1],
    );

    expect($result)->toBeNull();
});

test('store 済みの同一 payload は replay される', function (): void {
    $payload = ['a' => 1, 'nested' => ['x' => 'y']];
    $this->service->store(
        $this->org->id, $this->user->id, 'test-tool', $this->key, $payload, ['created_id' => 42],
    );

    $result = $this->service->replay(
        $this->org->id, $this->user->id, 'test-tool', $this->key, $payload,
    );

    expect($result)->toBe(['created_id' => 42]);
});

test('key 順序が違っても canonical hash で同一 payload とみなす', function (): void {
    $this->service->store(
        $this->org->id, $this->user->id, 'test-tool', $this->key, ['a' => 1, 'b' => 2], ['ok' => true],
    );

    $result = $this->service->replay(
        $this->org->id, $this->user->id, 'test-tool', $this->key, ['b' => 2, 'a' => 1],
    );

    expect($result)->toBe(['ok' => true]);
});

test('同一 key で payload が異なる場合は conflict', function (): void {
    $this->service->store(
        $this->org->id, $this->user->id, 'test-tool', $this->key, ['a' => 1], ['ok' => true],
    );

    $this->service->replay(
        $this->org->id, $this->user->id, 'test-tool', $this->key, ['a' => 2],
    );
})->throws(IdempotencyConflictException::class);

test('TTL 超過レコードは削除され再実行を許す', function (): void {
    McpIdempotencyKey::factory()
        ->forOrganizationAndUser($this->org, $this->user)
        ->expired()
        ->create([
            'tool_name' => 'test-tool',
            'idempotency_key' => $this->key->value,
            'payload_hash' => McpIdempotencyService::hashPayload(['a' => 1]),
        ]);

    $result = $this->service->replay(
        $this->org->id, $this->user->id, 'test-tool', $this->key, ['a' => 1],
    );

    expect($result)->toBeNull();
    expect(McpIdempotencyKey::query()->count())->toBe(0);
});

test('同一 org の別 user は同じ key でも衝突しない (caller 境界は user 単位)', function (): void {
    $other = attachOrganizationMember($this->org);

    $this->service->store(
        $this->org->id, $this->user->id, 'test-tool', $this->key, ['a' => 1], ['ok' => true],
    );

    // 別 user は replay されず (null)、store も unique 違反にならない
    $result = $this->service->replay(
        $this->org->id, $other->id, 'test-tool', $this->key, ['a' => 999],
    );
    expect($result)->toBeNull();

    $this->service->store(
        $this->org->id, $other->id, 'test-tool', $this->key, ['a' => 999], ['ok' => 'other'],
    );
    expect(McpIdempotencyKey::query()->count())->toBe(2);
});

test('int と float は同一 payload とみなさない (JSON 表現差を維持)', function (): void {
    $this->service->store(
        $this->org->id, $this->user->id, 'test-tool', $this->key, ['a' => 1], ['ok' => true],
    );

    $this->service->replay(
        $this->org->id, $this->user->id, 'test-tool', $this->key, ['a' => 1.0],
    );
})->throws(IdempotencyConflictException::class);

test('idempotency_key は UUID v4 のみ受理する', function (string $invalid): void {
    new IdempotencyKey($invalid);
})->with([
    '空文字' => [''],
    '非UUID' => ['not-a-uuid'],
    'UUID v1 相当' => ['550e8400-e29b-11d4-a716-446655440000'],
])->throws(InvalidParamsException::class);

test('store の expires_at は config の保持期間から決まる (クラス定数ではない)', function (): void {
    // 保持期間の SoT は config/idempotency.php (REST 側と共有)。
    // ここを変えて expires_at が追随しなければ二重管理へ逆戻りしている。
    config(['idempotency.retention_hours' => 1]);
    $this->freezeTime();
    $now = CarbonImmutable::now();

    $this->service->store(
        $this->org->id, $this->user->id, 'test-tool', $this->key, ['a' => 1], ['ok' => true],
    );

    $row = McpIdempotencyKey::query()->sole();
    expect($row->expires_at->toDateTimeString())->toBe($now->addHour()->toDateTimeString());
});
