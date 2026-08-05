<?php

declare(strict_types=1);

/*
 * scripts/ci/ensure-test-db.php
 *
 * pgsql テストの base DB (`<slug>_test_<worktree-hash>`) を不在時のみ冪等 CREATE する。
 * Laravel の ParallelTesting が base に `_test_<token>` を付した per-worker DB を作るが、
 * base DB 自体は事前に存在している必要があるため、run-test.sh / CI が test 前に本スクリプトを呼ぶ。
 *
 * dev-DB 保護:
 *   - base 名は TestDatabaseEnv::pgsqlBaseDatabase() (= 唯一のソース)。CREATE 前に
 *     isAllowedTestDatabase() 再検証 + isDevDatabase() deny で二重防御。
 *   - 接続失敗は CI / local いずれも明示エラー + exit 1 (偽グリーンを許さない)。
 */

use Tests\Support\Ci\TestDatabaseEnv;
use Webmozart\Assert\Assert;

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/pgsql_test_conn.php';

$projectRoot = dirname(__DIR__, 2);
$base = TestDatabaseEnv::pgsqlBaseDatabase($projectRoot);

// dev-DB 二重防御 (pgsqlBaseDatabase 内でも検査済だが、CREATE 直前に再確認)。
Assert::false(TestDatabaseEnv::isDevDatabase($base), "refusing to ensure dev DB: {$base}");
Assert::true(TestDatabaseEnv::isAllowedTestDatabase($base), "computed base name not allowlisted: {$base}");

try {
    $pdo = pgsqlTestMaintenancePdo($projectRoot);
} catch (Throwable $e) {
    fwrite(STDERR, "ensure-test-db: failed to connect to maintenance DB (postgres): {$e->getMessage()}\n");
    exit(1);
}

$stmt = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = :name');
$stmt->execute(['name' => $base]);
$exists = $stmt->fetchColumn() !== false;

// 出自 (worktree の realpath) を記録/更新する (非破壊の COMMENT ON DATABASE)。
// 孤児 sweep (drop-test-db.php --orphans) の**分類材料**であって guard ではない。
// 既存 DB でも必ず通す = 冪等 (ここを通さないと「ラベルの無い現役 DB」が生まれる)。
$provenance = realpath($projectRoot);
Assert::string($provenance, "projectRoot must resolve to a real path: {$projectRoot}");

foreach (testDatabaseEnsurePlan($exists) as $action) {
    match ($action) {
        TestDatabaseEnsureAction::Create => $pdo->exec(pgsqlCreateDatabaseSql($base)),
        TestDatabaseEnsureAction::StampProvenance => pgsqlStampProvenance(
            static fn (string $sql): mixed => $pdo->exec($sql),
            pgsqlCommentDatabaseSql($pdo, $base, $provenance),
        ),
    };
}

fwrite(STDERR, $exists
    ? "ensure-test-db: base DB already exists: {$base}\n"
    : "ensure-test-db: created base DB: {$base}\n");
exit(0);
