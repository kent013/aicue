<?php

declare(strict_types=1);

/*
 * scripts/ci/drop-test-db.php
 *
 * worktree の base テスト DB (`<slug>_test_<hash>`) と paratest worker DB (`_test_<token>`)
 * を回収する。ensure と接続 resolver を共有 (pgsql_test_conn.php) し、
 * 同一 PostgreSQL を見る (stale DB 排除)。
 *
 * dev-DB 保護 (NON-NEGOTIABLE):
 *   - base 名は TestDatabaseEnv::pgsqlBaseDatabase() (唯一のソース)。
 *   - pg_database を `datname = base OR datname LIKE base||'\_test\_%'` で列挙し、
 *     1 件ずつ isAllowedTestDatabase() で再検証。一致したものだけ DROP する。
 *   - isDevDatabase() true は無条件 skip + 警告 (理論上到達しないが防壁)。
 *   - best-effort: 接続失敗は skip + exit 0 (teardown を止めない)。失敗 DB 名は stderr に明示。
 */

use Tests\Support\Ci\TestDatabaseEnv;

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/pgsql_test_conn.php';

$projectRoot = dirname(__DIR__, 2);
$base = TestDatabaseEnv::pgsqlBaseDatabase($projectRoot);

try {
    $pdo = pgsqlTestMaintenancePdo($projectRoot);
} catch (Throwable $e) {
    fwrite(STDERR, "drop-test-db: maintenance DB connect failed; skipping (best-effort): {$e->getMessage()}\n");
    exit(0);
}

// base 完全一致 OR base_test_<token>。LIKE の _ / % を ESCAPE でリテラル化。
$pattern = str_replace(['_', '%'], ['\_', '\%'], $base).'\_test\_%';
$stmt = $pdo->prepare("SELECT datname FROM pg_database WHERE datname = :base OR datname LIKE :pat ESCAPE '\\'");
$stmt->execute(['base' => $base, 'pat' => $pattern]);

/** @var list<string> $names */
$names = array_values(array_filter(
    array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $stmt->fetchAll(PDO::FETCH_COLUMN)),
    static fn (string $v): bool => $v !== '',
));

$dropped = 0;
foreach ($names as $name) {
    if (TestDatabaseEnv::isDevDatabase($name)) {
        fwrite(STDERR, "drop-test-db: refusing to drop dev DB (skipped): {$name}\n");

        continue;
    }
    if (! TestDatabaseEnv::isAllowedTestDatabase($name)) {
        fwrite(STDERR, "drop-test-db: name not allowlisted (skipped): {$name}\n");

        continue;
    }
    try {
        $pdo->exec(pgsqlDropDatabaseSql($name));
        $dropped++;
    } catch (Throwable $e) {
        fwrite(STDERR, "drop-test-db: failed to drop {$name} (manual cleanup may be needed): {$e->getMessage()}\n");
    }
}

fwrite(STDERR, "drop-test-db: dropped {$dropped} test DB(s) for base {$base}\n");
exit(0);
