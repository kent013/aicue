<?php

declare(strict_types=1);

use App\Enums\Idempotency\IdempotencyState;
use App\Models\ApiKey;
use App\Models\IdempotencyKey;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * idempotency_keys への state 列追加 migration (T139) のスキーマ契約。
 *
 * ★DB default を持たないことが本質: default があると「state を書き忘れた INSERT」が
 *   黙って completed になり、claim の意味が消える。
 * ★既存行は completed へ backfill する (旧実装は 2xx しか保存しないため決着は既知)。
 *   indeterminate に倒すとデプロイ直後の正当な再送が最大 24h ぶん 409 に化ける。
 */

test('state 列は NOT NULL で DB default を持たない', function (): void {
    $columns = collect(Schema::getColumns('idempotency_keys'))
        ->keyBy(fn (array $column): string => (string) $column['name']);

    expect($columns)->toHaveKey('state');
    expect($columns['state']['nullable'])->toBeFalse();
    expect($columns['state']['default'])->toBeNull();
});

test('response_status は nullable (claim 時点では応答が無い)', function (): void {
    $columns = collect(Schema::getColumns('idempotency_keys'))
        ->keyBy(fn (array $column): string => (string) $column['name']);

    expect($columns['response_status']['nullable'])->toBeTrue();
});

test('既存の unique 2 本が残っている (claim の調停者)', function (): void {
    $uniques = collect(Schema::getIndexes('idempotency_keys'))
        ->filter(fn (array $index): bool => (bool) $index['unique'])
        ->map(function (array $index): array {
            /** @var list<string> $columns */
            $columns = $index['columns'];
            sort($columns);

            return $columns;
        })
        ->values()
        ->all();

    expect($uniques)->toContain(['api_key_id', 'key', 'route_name']);
    expect($uniques)->toContain(['key', 'route_name', 'user_id']);
});

test('既存行は completed へ backfill される', function (): void {
    // 1. 旧スキーマ相当へ戻す (state 列と index を落とす)
    Schema::table('idempotency_keys', function (Blueprint $table): void {
        $table->dropIndex('idempotency_keys_state_expires_at_index');
        $table->dropColumn('state');
    });

    // 2. 旧実装が書いていた形の行を 1 件用意する (2xx の保存応答)。
    //    属性値は Factory から生成する (手組み禁止の規約)。旧スキーマへ挿入するため
    //    insert 自体は query builder で行い、落とした `state` だけを外す。
    $apiKey = ApiKey::factory()->create();
    /** @var array<string, mixed> $attributes */
    $attributes = IdempotencyKey::factory()
        ->forApiKey($apiKey)
        ->raw([
            'key' => 'legacy-key-1',
            'response_status' => 201,
            'response_body' => ['data' => ['id' => 7]],
        ]);
    unset($attributes['state']);
    $attributes['response_body'] = json_encode($attributes['response_body'], JSON_THROW_ON_ERROR);

    DB::table('idempotency_keys')->insert($attributes);

    // 3. 対象 migration の up() を直接実行する
    $migration = require database_path(
        'migrations/2026_08_09_000100_add_state_to_idempotency_keys_table.php',
    );
    $migration->up();

    // 4. 既存行は completed で、保存応答は無傷
    $row = IdempotencyKey::query()->where('key', 'legacy-key-1')->sole();
    expect($row->state)->toBe(IdempotencyState::Completed);
    expect($row->response_status)->toBe(201);
    expect($row->response_body)->toBe(['data' => ['id' => 7]]);
});
