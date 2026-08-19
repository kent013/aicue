<?php

declare(strict_types=1);

/*
 * scripts/ci/ensure-test-db.php
 *
 * pgsql テストの base DB (`<slug>_test_<worktree-hash>`) を「存在させ、
 * スキーマを最新にする」ところまで担う (家系の裁定 AG-135 への追従)。
 * Laravel の ParallelTesting は base に `_test_<token>` を付した worker DB を作るが、
 * DB 系 trait を使わない Architecture のレーンは base DB をそのまま読むため、
 * base DB のスキーマが古いままだと「新しい worktree でだけ落ちる」
 * 「実行順で結果が変わる」失敗になる。
 * run-test.sh / run-browser-test.sh / setup-worktree.sh が test 前に本スクリプトを呼ぶ
 * (CI は run-test.sh / run-browser-test.sh 経由でのみ呼び、ワークフローから直接
 * 本スクリプトを叩く経路は運用していない)。
 *
 * dev-DB 保護 (4 重。AGENTS.md 禁止事項 3):
 *   1. 名前の出所 — 基点名は TestDatabaseEnv::pgsqlBaseDatabase() の 1 か所だけが決める
 *   2. 名前の検査 — allowlist 一致 + dev 名 deny を、CREATE の直前と
 *      スキーマ更新 (ensureTestDatabaseSchemaUpdated()) の先頭の 2 箇所で再確認する
 *   3. 子プロセスの環境 — 継承せず許可リストで組み立て、DB_DATABASE を算出した基点名で固定し、
 *      設定キャッシュも ensure 専用の非既定パスへ固定する (この devcontainer の shell には
 *      dev DB 名が export されており、素直に継承するとスキーマ更新が dev DB に当たる)
 *   4. 到達確認 — 更新後に基点 DB へ直接つなぎ、database/migrations の全ファイルが
 *      適用済みであることまで確かめる (正典より 1 段強い基準。下記参照)
 *
 * 到達確認は正典より強い: 正典 (laravel-claude-template) は「migrations 表があり
 * 行が 1 件以上ある」で止まるが、それでは古い基点 DB に古い migrations 表が残っている
 * 状態を通してしまう。本スクリプトは pgsqlTestSchemaUnappliedMigrations() で
 * 「migrations 表が存在し、database/migrations の全ファイル名がその表に含まれる」を
 * 成功条件にする。tests/Architecture/BaseTestDatabaseSchemaTest.php の B-2 と
 * 同じ関数を共有しており、スクリプトと検査で判定がずれない。
 * **保証しないこと**: この到達確認は「基点 DB の最終状態がスキーマ最新である」ことの
 * 確認であって、直前の migrate/migrate:status 子プロセスがその更新を行ったことの監査では
 * ない (基点 DB が既に最新なら、子プロセスの環境変数解決が壊れていて別の DB を
 * 更新していても、この確認だけでは検出できない)。dev DB 保護は、この到達確認では
 * なく、上記 1〜3 (名前の出所の一本化・起動直前の再検証・非継承の環境固定) で成立させる。
 *
 * 出自の記録 (COMMENT ON DATABASE) は best-effort、スキーマ更新は fail-closed — この
 * 非対称は意図である。出自は孤児 sweep の分類材料にすぎず権限差で偽赤を増やしたくないが、
 * スキーマ更新の失敗を見逃すと基点 DB が古いまま「たまたま」テストが通ってしまう。
 *
 * 接続失敗は CI / local いずれも明示エラー + exit 1 (偽グリーンを許さない)。
 *
 * 保証しないこと: スキーマ更新に実行時間の見張りを持たない (子プロセスが DB のロック待ちで
 * 止まれば本スクリプトも止まる。既存のテスト入口も同じで、待ちの仕掛けは持ち込まない)。
 * 接続の待ちだけは PDO の ATTR_TIMEOUT 10 秒が効く。
 *
 * 実行時間の実測 (aicue、2026-08-19、devcontainer 内): 何もしないとき (migrate が
 * "Nothing to migrate" になる場合) 約 0.66 秒 / 空の DB から全 75 migration 適用のとき
 * 約 0.99 秒 (`performTestDatabaseSchemaUpdate()` の呼び出しのみを計測。正典の実測
 * 「何もしないとき約 0.53 秒 / 空の DB から全適用で約 0.66 秒」と同水準)。
 */

use Tests\Support\Ci\TestDatabaseEnv;
use Webmozart\Assert\Assert;

require_once __DIR__.'/../../vendor/autoload.php';
// 同一プロセスで先に (Architecture/Unit テストなどから) pgsql_test_conn.php が
// require_once 済みの状態で本ファイルが require_once されたとき、通常の require は
// 同じファイルをもう一度パース・実行し、関数と TestDatabaseEnsureAction enum の再宣言で
// fatal error になる。require_once へ統一する (drop-test-db.php も同じ行を持つため、
// scripts/ci 配下の共有ファイルは全て require_once で読み込む規約にする)。
require_once __DIR__.'/pgsql_test_conn.php';

/** ensureTestDatabaseSchemaUpdated() が返す失敗理由。main 境界がメッセージ選定に使う。 */
enum TestDatabaseSchemaUpdateFailure
{
    case UnsafeDatabaseName;
    case ConfigCacheStale;
    case MigrateFailed;
    case MigrateStatusFailed;
    case MigrationFileEnumerationFailed;
    case NoMigrationFiles;
    case VerificationConnectionFailed;
    case MigrationsTableMissing;
    case UnappliedMigrationsRemain;
}

/**
 * 環境変数を継承しない artisan の起動 (laravel-claude-template@ccf465a7 と同名・同挙動)。
 *
 * shell を通さない配列形の proc_open を使う (引用の取り違えを構造的に無くす)。
 * 出力を捨てずに取りたい場合は一時ファイルへ落とす — pipe を使うと、片方を読み切るまで
 * もう片方が詰まる形になり、出力が増えたときに固まりうるためである (ここで必要なのは
 * 失敗時に見せる文言だけなので、非同期に読む仕掛けは持たない)。
 *
 * @param  list<string>  $args
 * @param  array<string, string>  $env
 * @return array{status: int, output: string}
 */
function runTestDatabaseArtisan(string $projectRoot, array $args, array $env, bool $capture): array
{
    if (! $capture) {
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => STDERR, 2 => STDERR];
        $process = proc_open([PHP_BINARY, 'artisan', ...$args], $descriptors, $pipes, $projectRoot, $env);
        if (! is_resource($process)) {
            return ['status' => 1, 'output' => "failed to start: artisan {$args[0]}\n"];
        }

        return ['status' => proc_close($process), 'output' => ''];
    }

    // stdout と stderr は別々の一時ファイルへ落とす。同じファイルを 2 つの descriptor で
    // 開くと書き込み位置が独立するため、片方がもう片方の内容を踏みつぶしうる。
    $outPath = tempnam(sys_get_temp_dir(), 'ensure-test-db-out-');
    $errPath = tempnam(sys_get_temp_dir(), 'ensure-test-db-err-');
    if ($outPath === false || $errPath === false) {
        if ($outPath !== false) {
            @unlink($outPath);
        }
        if ($errPath !== false) {
            @unlink($errPath);
        }

        return ['status' => 1, 'output' => "failed to create temporary files for output\n"];
    }

    try {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $outPath, 'w'],
            2 => ['file', $errPath, 'w'],
        ];

        $process = proc_open([PHP_BINARY, 'artisan', ...$args], $descriptors, $pipes, $projectRoot, $env);
        if (! is_resource($process)) {
            return ['status' => 1, 'output' => "failed to start: artisan {$args[0]}\n"];
        }

        $status = proc_close($process);

        return [
            'status' => $status,
            'output' => (string) file_get_contents($outPath).(string) file_get_contents($errPath),
        ];
    } finally {
        @unlink($outPath);
        @unlink($errPath);
    }
}

/**
 * @return array{ok: false, failure: TestDatabaseSchemaUpdateFailure, message: string}
 */
function testDatabaseSchemaUpdateFailure(TestDatabaseSchemaUpdateFailure $failure, string $message): array
{
    return ['ok' => false, 'failure' => $failure, 'message' => $message];
}

/**
 * base DB のスキーマ更新の**意思決定関数** (UpdateSchema action の本体)。
 *
 * `exit()` も `fwrite()` も行わない。実 artisan 起動・ファイル列挙・PDO 接続はすべて
 * callable として受け取り、実行順・分岐・メッセージ選定だけをこの関数が担う。
 * これにより `tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` は実 DB・実子プロセスなしで
 * 9 つの失敗経路と正常系、artisan へ渡る引数列そのものを固定できる。
 *
 * **「純粋な意思決定関数」ではない**: `TestDatabaseEnv::isDevDatabase()` /
 * `isAllowedTestDatabase()` の静的判定、`pgsqlTestArtisanEnv()` が読む `.env.testing` 経由の
 * 環境変数、`is_file()` による設定キャッシュパスの確認は、この関数が直接読む外部状態である。
 * 「主要な実行境界 (子プロセス起動・ファイル列挙・DB 接続) だけを callable 注入で切り離した」
 * という範囲に限定して主張する。
 *
 * 実行順: (1) dev DB 名の再検証 → (2) 設定キャッシュの残存確認 → (3) migrate →
 * (4) 設定キャッシュの再確認 → (5) migrate:status → (6) migration ファイル列挙 →
 * (7) 到達確認の PDO 検証 → (8) migrations 表の存在確認 → (9) 未適用差分の判定。
 *
 * @param  callable(list<string>, array<string, string>, bool): array{status: int, output: string}  $runArtisan
 * @param  callable(string): (list<string>|false)  $listMigrationFiles  glob() 相当。false = 列挙失敗、[] = ファイル0件 (型で区別する)
 * @param  callable(string, string): array{tableExists: bool, applied: list<string>}  $verifyAppliedMigrations  接続/クエリ失敗時は例外を投げる契約
 * @return array{ok: bool, failure: TestDatabaseSchemaUpdateFailure|null, message: string}
 */
function ensureTestDatabaseSchemaUpdated(
    string $projectRoot,
    string $base,
    callable $runArtisan,
    callable $listMigrationFiles,
    callable $verifyAppliedMigrations,
): array {
    // (1) dev DB 二重防御: pgsqlBaseDatabase() 内でも検査済みだが、
    //     スキーマ更新という実行境界の直前にもう一度確認する (env 構築より前)。
    if (TestDatabaseEnv::isDevDatabase($base) || ! TestDatabaseEnv::isAllowedTestDatabase($base)) {
        return testDatabaseSchemaUpdateFailure(
            TestDatabaseSchemaUpdateFailure::UnsafeDatabaseName,
            "safety check failed for computed base database name: {$base}",
        );
    }

    $env = pgsqlTestArtisanEnv($projectRoot, $base);
    $configCachePath = pgsqlTestConfigCachePath($projectRoot);
    $where = "db={$base} host={$env['DB_HOST']}:{$env['DB_PORT']}";

    // (2) migrate 起動直前の設定キャッシュ確認。
    if (is_file($configCachePath)) {
        return testDatabaseSchemaUpdateFailure(
            TestDatabaseSchemaUpdateFailure::ConfigCacheStale,
            "ensure 専用の設定キャッシュが既に存在するため migrate を起動せず中止します: {$configCachePath}",
        );
    }

    // 更新自体が「未適用のものだけ当てる」条件分岐なので、有無を見て分岐すると
    // 同じ判定を二重に持つことになる (毎回無条件で実行する)。
    $migrate = $runArtisan(['migrate', '--force', '--no-interaction'], $env, false);
    if ($migrate['status'] !== 0) {
        return testDatabaseSchemaUpdateFailure(
            TestDatabaseSchemaUpdateFailure::MigrateFailed,
            "ensure-test-db: スキーマ更新に失敗しました ({$where}, exit={$migrate['status']})",
        );
    }

    // (4) migrate:status 起動直前にも同じ設定キャッシュを再確認する
    //     (migrate の実行中に生成される異常も見逃さない)。
    if (is_file($configCachePath)) {
        return testDatabaseSchemaUpdateFailure(
            TestDatabaseSchemaUpdateFailure::ConfigCacheStale,
            "ensure 専用の設定キャッシュが migrate 実行後に出現したため migrate:status を起動せず中止します: {$configCachePath}",
        );
    }

    // 未適用が残っていないことを artisan 自身の判定で確かめる。
    // 値を渡したときだけその値が終了コードになる (値を渡さない形は未適用があっても 0 を返す)。
    $pending = $runArtisan(['migrate:status', '--pending=1'], $env, true);
    if ($pending['status'] !== 0) {
        return testDatabaseSchemaUpdateFailure(
            TestDatabaseSchemaUpdateFailure::MigrateStatusFailed,
            "ensure-test-db: migration の状態確認に失敗、または未適用が残っています ({$where})\n{$pending['output']}",
        );
    }

    // (6) 別経路の到達確認の準備: 基点 DB へ直接つないで
    //     database/migrations の全ファイルが適用済みであることを確かめる。
    $files = $listMigrationFiles($projectRoot);
    if ($files === false) {
        return testDatabaseSchemaUpdateFailure(
            TestDatabaseSchemaUpdateFailure::MigrationFileEnumerationFailed,
            'ensure-test-db: database/migrations の列挙に失敗しました (glob failure)',
        );
    }
    if ($files === []) {
        return testDatabaseSchemaUpdateFailure(
            TestDatabaseSchemaUpdateFailure::NoMigrationFiles,
            'ensure-test-db: database/migrations にファイルがありません (到達確認が空振りするため中止)',
        );
    }
    $expected = pgsqlTestMigrationFileNames($files);

    try {
        $verification = $verifyAppliedMigrations($projectRoot, $base);
    } catch (Throwable $e) {
        return testDatabaseSchemaUpdateFailure(
            TestDatabaseSchemaUpdateFailure::VerificationConnectionFailed,
            "ensure-test-db: 更新後の確認接続に失敗しました ({$where}): {$e->getMessage()}",
        );
    }

    if (! $verification['tableExists']) {
        return testDatabaseSchemaUpdateFailure(
            TestDatabaseSchemaUpdateFailure::MigrationsTableMissing,
            "ensure-test-db: 更新後も migrations 表がありません ({$where})",
        );
    }

    $unapplied = pgsqlTestSchemaUnappliedMigrations($verification['applied'], $expected);
    if ($unapplied !== []) {
        return testDatabaseSchemaUpdateFailure(
            TestDatabaseSchemaUpdateFailure::UnappliedMigrationsRemain,
            "ensure-test-db: 更新後も未適用の migration ファイルが残っています ({$where}): ".implode(', ', $unapplied),
        );
    }

    return [
        'ok' => true,
        'failure' => null,
        'message' => 'ensure-test-db: schema up to date: '.$base.' ('.count($verification['applied']).' migrations)',
    ];
}

/**
 * `performTestDatabaseSchemaUpdate()` が使う実物 callable の組み立て。
 *
 * 組み立てを本 factory へ切り出すことで、実 DB・実子プロセスに触れない範囲
 * (`listMigrationFiles` の結線) だけは単体テストできるようにする。
 *
 * **保証しないこと**: `runArtisan` と `verifyAppliedMigrations` の結線自体は、実子プロセス起動・
 * 実 PDO 接続を伴うため単体テストの対象にしない (呼び出す関数本体 `runTestDatabaseArtisan()` /
 * `pgsqlTestDatabasePdo()` は正典からそのまま移植した部分である)。この 2 つの結線が
 * 壊れていないことは、`tests/Architecture/BaseTestDatabaseSchemaTest.php` の B-1/B-2 が
 * (監査ではなく最終状態の観測として) 間接的にしか裏取りしない。
 *
 * @return array{
 *     runArtisan: callable(list<string>, array<string, string>, bool): array{status: int, output: string},
 *     listMigrationFiles: callable(string): (list<string>|false),
 *     verifyAppliedMigrations: callable(string, string): array{tableExists: bool, applied: list<string>},
 * }
 */
function realTestDatabaseSchemaUpdateCallables(string $projectRoot): array
{
    return [
        'runArtisan' => static fn (array $args, array $env, bool $capture): array => runTestDatabaseArtisan($projectRoot, $args, $env, $capture),
        'listMigrationFiles' => static fn (string $root): array|false => glob($root.'/database/migrations/*.php'),
        'verifyAppliedMigrations' => static function (string $root, string $db): array {
            $pdo = pgsqlTestDatabasePdo($root, $db);
            $table = $pdo->query("SELECT to_regclass('public.migrations')")->fetchColumn();
            if ($table === null || $table === false) {
                return ['tableExists' => false, 'applied' => []];
            }
            /** @var list<string> $applied */
            $applied = $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);

            return ['tableExists' => true, 'applied' => $applied];
        },
    ];
}

/**
 * main 境界のラッパ。`realTestDatabaseSchemaUpdateCallables()` が組み立てた実物 callable を
 * 注入して `ensureTestDatabaseSchemaUpdated()` を呼び、結果を stderr へ書いて非成功時のみ
 * `exit(1)` する。
 *
 * ラッパ自身 (fwrite・exit の配線) は実 DB / 実子プロセスに触れるため単体テストの対象にしない
 * (意思決定本体である `ensureTestDatabaseSchemaUpdated()` の側を単体テストする)。
 */
function performTestDatabaseSchemaUpdate(string $projectRoot, string $base): void
{
    $callables = realTestDatabaseSchemaUpdateCallables($projectRoot);
    $result = ensureTestDatabaseSchemaUpdated(
        $projectRoot,
        $base,
        $callables['runArtisan'],
        $callables['listMigrationFiles'],
        $callables['verifyAppliedMigrations'],
    );

    fwrite(STDERR, $result['message']."\n");
    if (! $result['ok']) {
        exit(1);
    }
}

// ───────────────────────── entrypoint ─────────────────────────

/*
 * 直接実行されたときだけ main を走らせる (scripts/ci/drop-test-db.php と同じ既存パターン)。
 *
 * 施策4 の Unit テストは、注入可能な意思決定関数 (`ensureTestDatabaseSchemaUpdated()`) を
 * 直接呼ぶために本ファイルを `require_once` する。このガードが無いと `require_once` だけで
 * 実 DB へ接続する main 処理が走ってしまう。
 */
if (! isset($argv[0]) || realpath($argv[0]) !== realpath(__FILE__)) {
    return;
}

$projectRoot = dirname(__DIR__, 2);
$base = TestDatabaseEnv::pgsqlBaseDatabase($projectRoot);

// dev-DB 二重防御 (pgsqlBaseDatabase 内でも検査済だが、CREATE の直前に再確認)。
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
// 孤児 sweep (drop-test-db.php --orphans) の分類材料であって guard ではない。
// 既存 DB でも必ず通す = 冪等 (ここを通さないと「ラベルの無い現役 DB」が生まれる)。
$provenance = realpath($projectRoot);
Assert::string($provenance, "projectRoot must resolve to a real path: {$projectRoot}");

// 実行順は CREATE → 出自の記録 → スキーマ更新 (aicue:D30 の不変条件)。
foreach (testDatabaseEnsurePlan($exists) as $action) {
    match ($action) {
        TestDatabaseEnsureAction::Create => $pdo->exec(pgsqlCreateDatabaseSql($base)),
        TestDatabaseEnsureAction::StampProvenance => pgsqlStampProvenance(
            static fn (string $sql): mixed => $pdo->exec($sql),
            pgsqlCommentDatabaseSql($pdo, $base, $provenance),
        ),
        TestDatabaseEnsureAction::UpdateSchema => performTestDatabaseSchemaUpdate($projectRoot, $base),
    };
}

fwrite(STDERR, $exists
    ? "ensure-test-db: base DB already exists: {$base}\n"
    : "ensure-test-db: created base DB: {$base}\n");
exit(0);
