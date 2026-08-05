<?php

declare(strict_types=1);

use Tests\Support\Ci\TestDatabaseEnv;
use Webmozart\Assert\Assert;

/*
 * テスト用 DB 名決定ロジック (pgsql 一本化) の Unit テスト。
 *
 * 不変条件:
 *   1. base 名は `<prefix>_test_<worktree-hash>` で worktree ごとに分離される
 *   2. 算出値は必ず allowlist に一致し、dev DB 名には決して解決しない
 *   3. assertPgsqlTestDatabaseSafe は dev / 非 allowlist 名で fail-closed する
 */

// ── base name / override ──

it('builds a {slug}_test_<hash> base database name', function (): void {
    $root = realpath(sys_get_temp_dir());
    Assert::string($root);

    $name = TestDatabaseEnv::pgsqlBaseDatabase($root);

    $hash = substr(sha1($root), 0, 8);
    expect($name)->toBe("app_test_{$hash}")
        ->and(TestDatabaseEnv::isAllowedTestDatabase($name))->toBeTrue()
        ->and(TestDatabaseEnv::isDevDatabase($name))->toBeFalse();
});

it('derives the base name hash from the resolved project root', function (): void {
    $rootA = realpath(sys_get_temp_dir());
    Assert::string($rootA);

    expect(TestDatabaseEnv::pgsqlBaseDatabase($rootA))->not->toBe(TestDatabaseEnv::pgsqlBaseDatabase('/'));
});

it('returns the base name for pgsql override and null otherwise', function (): void {
    $root = realpath(sys_get_temp_dir());
    Assert::string($root);

    expect(TestDatabaseEnv::pgsqlOverrideDatabase(['DB_CONNECTION' => 'pgsql'], $root))
        ->toBe(TestDatabaseEnv::pgsqlBaseDatabase($root));
    expect(TestDatabaseEnv::pgsqlOverrideDatabase(['DB_CONNECTION' => 'sqlite'], $root))->toBeNull();
    expect(TestDatabaseEnv::pgsqlOverrideDatabase([], $root))->toBeNull();
});

// ── 正の回帰: shell env から dev DB 名が leak しても dev に解決しない ──

it('resolves to a test DB (not dev) even when the dev DB name leaks into the env', function (): void {
    $root = realpath(sys_get_temp_dir());
    Assert::string($root);

    // shell / docker-compose から dev 名が leak した状況を模す。
    // pgsqlOverrideDatabase は DB_DATABASE を無視して base 名を算出する。
    $resolved = TestDatabaseEnv::pgsqlOverrideDatabase(
        ['DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'app'],
        $root,
    );

    Assert::string($resolved);
    expect($resolved)->toMatch('/^app_test_[0-9a-f]{8}$/')
        ->and(TestDatabaseEnv::isDevDatabase($resolved))->toBeFalse();
});

// ── 単一点 fail-closed ガード本体 ──

it('assertPgsqlTestDatabaseSafe throws on a dev DB name', function (): void {
    TestDatabaseEnv::assertPgsqlTestDatabaseSafe('app');
})->throws(InvalidArgumentException::class);

it('assertPgsqlTestDatabaseSafe throws on a non-allowlisted name', function (): void {
    TestDatabaseEnv::assertPgsqlTestDatabaseSafe('some_other_db');
})->throws(InvalidArgumentException::class);

it('assertPgsqlTestDatabaseSafe passes for canonical test DB names', function (): void {
    TestDatabaseEnv::assertPgsqlTestDatabaseSafe('app_test_0123abcd');
    TestDatabaseEnv::assertPgsqlTestDatabaseSafe('app_test_0123abcd_test_2');
    expect(true)->toBeTrue(); // 例外を投げなければ pass
});

// ── dev-deny / allowlist ──

it('detects dev databases including case/whitespace variants', function (string $variant): void {
    expect(TestDatabaseEnv::isDevDatabase($variant))->toBeTrue();
})->with([
    'app',
    'App',
    ' app ',
    'APP',
]);

// bug-hunt 環境の DB は allowlist regex でも構造的に除外されるが、
// 「絶対に触らない」意図を denylist にも明示する二重防御 (AGENTS.md §bug-hunt)。
it('hard-denies bug-hunt databases', function (string $variant): void {
    expect(TestDatabaseEnv::isDevDatabase($variant))->toBeTrue()
        ->and(TestDatabaseEnv::isAllowedTestDatabase($variant))->toBeFalse();
})->with([
    'bug_hunt',
    'bug_hunt_1',
    'bug_hunt_8',
    'BUG_HUNT_3',
    ' bug_hunt_5 ',
]);

it('covers every bug-hunt shard database in the denylist', function (): void {
    // shard は :8011..:8018 = bug_hunt_1..8 (scripts/bug-hunt-shard.sh)。取りこぼしを機械検出する。
    $expected = ['app', 'bug_hunt'];
    for ($i = 1; $i <= 8; $i++) {
        $expected[] = "bug_hunt_{$i}";
    }

    expect(TestDatabaseEnv::DEV_DB_DENYLIST)->toBe($expected);
});

it('does not deny unrelated names that merely start with bug_hunt', function (): void {
    expect(TestDatabaseEnv::isDevDatabase('bug_hunt_9'))->toBeFalse()
        ->and(TestDatabaseEnv::isDevDatabase('bug_hunts'))->toBeFalse()
        // allowlist に載らないので DROP 経路には到達しない (denylist は二重防御の側)
        ->and(TestDatabaseEnv::isAllowedTestDatabase('bug_hunt_9'))->toBeFalse();
});

it('assertPgsqlTestDatabaseSafe throws on bug-hunt databases', function (): void {
    TestDatabaseEnv::assertPgsqlTestDatabaseSafe('bug_hunt_3');
})->throws(InvalidArgumentException::class);

it('does not flag test databases as dev', function (): void {
    expect(TestDatabaseEnv::isDevDatabase('app_test_deadbeef'))->toBeFalse()
        ->and(TestDatabaseEnv::isDevDatabase('app_testx'))->toBeFalse();
});

it('allowlists base and paratest worker test databases only', function (): void {
    expect(TestDatabaseEnv::isAllowedTestDatabase('app_test_deadbeef'))->toBeTrue()
        ->and(TestDatabaseEnv::isAllowedTestDatabase('app_test_deadbeef_test_3'))->toBeTrue()
        ->and(TestDatabaseEnv::isAllowedTestDatabase('app'))->toBeFalse()
        ->and(TestDatabaseEnv::isAllowedTestDatabase('app_testx'))->toBeFalse()
        ->and(TestDatabaseEnv::isAllowedTestDatabase('app_test_DEADBEEF'))->toBeFalse()
        ->and(TestDatabaseEnv::isAllowedTestDatabase('app_test_dead'))->toBeFalse();
});
