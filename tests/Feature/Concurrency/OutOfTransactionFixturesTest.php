<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\ConcurrencyFixtureKeys;
use Tests\Support\Concurrency\OutOfTransactionFixtures;

/*
 * transaction 外の検体置き場の契約 (正典 v1 の要素 (2))。
 *
 * `RefreshDatabase` は検体を**未コミットの transaction の中**に置くため、子プロセスからは
 * 見えない。本テストは「別名接続へ出して commit し、末尾で確実に片付ける」という契約が
 * 実際に効いていることを固定する。
 *
 * ★**片付けの完全性は cleanup() 自身の契約**である (8 表の残留ゼロ検査)。
 *   本テストはその検査器そのものが機能していること (削除前なら非ゼロを数えること) も見る —
 *   「残留があるのに 0 と数える」退行は、残留ゼロ検査だけでは緑のまま通ってしまう。
 *
 * **保証しないもの**: 見ているのは cleanup が受け持つ 8 表だけである。検体の生成経路が
 * 別の表へ行を足すようになったら、この検査は沈黙する (一覧を同じ変更で増やすこと)。
 */

/** 検体 (組織 + owner + API キー) を transaction の外に作る */
function createOutOfTransactionFixture(): ConcurrencyFixtureKeys
{
    return OutOfTransactionFixtures::create(function (): ConcurrencyFixtureKeys {
        [$organization, $owner] = createOrganizationWithOwner();
        [$apiKey] = issueApiKey($organization, $owner);

        return new ConcurrencyFixtureKeys(
            organizationId: $organization->id,
            laratrustTeamId: $organization->laratrust_team_id,
            userId: $owner->id,
            apiKeyId: $apiKey->id,
        );
    });
}

test('create() で作った行は別名接続から見える (テストの transaction の外に出ている)', function (): void {
    $keys = createOutOfTransactionFixture();

    try {
        $rows = OutOfTransactionFixtures::connection()
            ->table('organizations')
            ->where('id', $keys->organizationId)
            ->count();

        expect($rows)->toBe(1);

        // 既定接続 (テストの transaction の中) から見ても在る = 同じ DB を指している
        expect(DB::table('api_keys')->where('id', $keys->apiKeyId)->count())->toBe(1);
    } finally {
        OutOfTransactionFixtures::cleanup($keys);
    }
});

test('residueCounts() は削除前の検体を数え上げる (検査器そのものが機能している)', function (): void {
    $keys = createOutOfTransactionFixture();

    try {
        $counts = OutOfTransactionFixtures::residueCounts($keys);

        // 8 表すべてが対象で、検体を作った直後はどれも 1 件以上ある
        expect(array_keys($counts))->toBe([
            'idempotency_keys', 'api_keys', 'organization_user', 'custom_teams',
            'organizations', 'role_user', 'teams', 'users',
        ]);

        // idempotency_keys だけは検体の時点で 0 件 (要求を出していないため)
        expect($counts['idempotency_keys'])->toBe(0);

        foreach (['api_keys', 'organization_user', 'custom_teams', 'organizations', 'role_user', 'teams', 'users'] as $table) {
            expect($counts[$table])->toBeGreaterThan(0);
        }
    } finally {
        OutOfTransactionFixtures::cleanup($keys);
    }
});

test('cleanup() の後は 8 表すべてで残留が 0 (organizations は物理削除される)', function (): void {
    $keys = createOutOfTransactionFixture();

    OutOfTransactionFixtures::cleanup($keys);

    // ★softDeletes を持つ organizations も query builder 経由で**物理削除**されている
    //   (Eloquent の delete() だと deleted_at が入るだけで行は残る)。
    expect(OutOfTransactionFixtures::residueCounts($keys))->toBe([
        'idempotency_keys' => 0,
        'api_keys' => 0,
        'organization_user' => 0,
        'custom_teams' => 0,
        'organizations' => 0,
        'role_user' => 0,
        'teams' => 0,
        'users' => 0,
    ]);
});

test('cleanup() は冪等 (2 回呼んでも安全)', function (): void {
    $keys = createOutOfTransactionFixture();

    OutOfTransactionFixtures::cleanup($keys);
    OutOfTransactionFixtures::cleanup($keys);

    expect(OutOfTransactionFixtures::residueCounts($keys)['users'])->toBe(0);
});

test('別名接続の座標は既定接続と一致する (別の DB を向いていない)', function (): void {
    $expected = config('database.connections.pgsql');

    $keys = createOutOfTransactionFixture();

    try {
        expect(config('database.connections.'.OutOfTransactionFixtures::CONNECTION_NAME))->toBe($expected);
        expect(OutOfTransactionFixtures::connection()->getDatabaseName())
            ->toBe(DB::connection('pgsql')->getDatabaseName());
    } finally {
        OutOfTransactionFixtures::cleanup($keys);
    }
});

test('create() の中で例外が出たら作りかけの行は rollback され、接続の後始末も済んでいる', function (): void {
    $original = config('database.default');

    // ★**行を作ってから**例外を投げる。行を作る前に投げる形だと `DB::transaction()` を
    //   外しても緑のままで、rollback が効いていることを何も示さない
    //   (rollback は create() 失敗時の残留を防ぐ唯一の仕組みである)。
    $keys = null;

    // ★アロー関数で包まない。アロー関数は外側の変数を**値で**取り込むため、
    //   その内側の `use (&$keys)` は複製に束縛され、控えた主キーが外へ出てこない。
    expect(function () use (&$keys): void {
        OutOfTransactionFixtures::create(function () use (&$keys): never {
            [$organization, $owner] = createOrganizationWithOwner();
            [$apiKey] = issueApiKey($organization, $owner);

            $keys = new ConcurrencyFixtureKeys(
                organizationId: $organization->id,
                laratrustTeamId: $organization->laratrust_team_id,
                userId: $owner->id,
                apiKeyId: $apiKey->id,
            );

            throw new RuntimeException('検体の生成に失敗した');
        });
    })->toThrow(RuntimeException::class, '検体の生成に失敗した');

    expect($keys)->toBeInstanceOf(ConcurrencyFixtureKeys::class);

    // 既定接続名は元へ戻り、別名接続は disconnect + purge されている
    expect(config('database.default'))->toBe($original);
    expect(array_key_exists(
        OutOfTransactionFixtures::CONNECTION_NAME,
        DB::getConnections(),
    ))->toBeFalse();

    // 作りかけの行は 8 表すべてで残っていない (cleanup では拾えない = rollback が唯一の砦)
    expect(OutOfTransactionFixtures::residueCounts($keys))->toBe([
        'idempotency_keys' => 0,
        'api_keys' => 0,
        'organization_user' => 0,
        'custom_teams' => 0,
        'organizations' => 0,
        'role_user' => 0,
        'teams' => 0,
        'users' => 0,
    ]);
});
