<?php

declare(strict_types=1);

use Tests\Support\Ci\TestDatabaseEnv;

/*
|--------------------------------------------------------------------------
| PHPUnit Bootstrap (pgsql 一本化)
|--------------------------------------------------------------------------
|
| 1) vendor autoloader を読み込む。
| 2) test 用 DB 名を `<slug>_test_<worktree-hash>` に決定し、DB_DATABASE を
|    $_SERVER / $_ENV / putenv の 3 経路へ注入する。Laravel の env() は
|    $_SERVER → $_ENV → getenv の順で見るため 3 経路全て埋める。
| 3) 最終 DB 名が test DB (allowlist 一致 + 非 dev) であることを Laravel boot 前に
|    fail-closed 検証する (単一点ガード)。shell / docker-compose から
|    DB_DATABASE=<dev DB> が leak しても dev DB を wipe しない安全装置。
|
| <hash> は project root の realpath から sha1 先頭 8 文字。別 worktree からの
| `composer test` 並走でも DB 名が衝突しない。同一 worktree 内の paratest worker
| 間は Laravel が `<base>_test_<TEST_TOKEN>` へ自動展開するためここでは扱わない。
|
| phpunit.xml の <server force> ではなく bootstrap で env を埋めるのは、phpunit.xml
| が静的記述で worktree hash を持てないため。適用順 (load-bearing): phpunit の
| TextUI\Application は PhpHandler::handle() (<server> を $_SERVER に代入) を
| BootstrapLoader より先に実行するため、ここで $_SERVER['DB_CONNECTION'] は
| phpunit.xml の force 済み値 (pgsql) を反映している。Laravel の phpdotenv は
| immutable adapter で既存 env を尊重する (.env.testing が後から上書きしない)。
|
| base DB の作成は scripts/ci/ensure-test-db.php (run-test.sh / CI が test 前に実行)
| が担う。アプリ名は DB 名に含めない (TestDatabaseEnv の prefix は template slug 由来)。
*/

require __DIR__.'/../vendor/autoload.php';

$projectRoot = realpath(__DIR__.'/..') ?: dirname(__DIR__);

$override = TestDatabaseEnv::pgsqlOverrideDatabase($_SERVER, $projectRoot);

if ($override !== null) {
    $_SERVER['DB_DATABASE'] = $override;
    $_ENV['DB_DATABASE'] = $override;
    putenv("DB_DATABASE={$override}");
}

// 単一点 fail-closed ガード: pgsql lane の最終 DB_DATABASE が test DB でなければ
// Laravel boot 前に停止する (override 不発・env leak が生存した場合もここで捕捉)。
if (($_SERVER['DB_CONNECTION'] ?? null) === 'pgsql') {
    $effective = (string) ($_SERVER['DB_DATABASE'] ?? '');
    try {
        TestDatabaseEnv::assertPgsqlTestDatabaseSafe($effective);
    } catch (Throwable $e) {
        fwrite(STDERR, "[db-safety] FATAL: {$e->getMessage()}\n");
        exit(1);
    }
    unset($effective);
}

unset($projectRoot, $override);
