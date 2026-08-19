<?php

declare(strict_types=1);

// pgsql_test_conn.php を個別に require_once しない。ensure-test-db.php をトップレベル
// スクリプトとして require_once すれば、その内部の require_once 経由で共有依存
// (pgsql_test_conn.php) も一緒に読み込まれるため、ここで重複した読み込み宣言を置かない
// (require_once 同士なので二重に require_once しても fatal にはならないが、
// 既存の DropTestDbScriptTest.php も drop-test-db.php 1 本だけを require_once する
// 同じスタイルに揃える)。
require_once __DIR__.'/../../../scripts/ci/ensure-test-db.php';

/*
 * ensure-test-db.php のスキーマ更新まわりを固定する Unit テスト。
 *
 * 固定する不変条件:
 *   1. pgsqlTestArtisanEnv() は環境を継承せず組み立てる (固定キーが常に勝つ / 許可した
 *      3 キーだけ継承する / DB_URL は空で固定する / 親環境の DB_DATABASE・DB_URL・
 *      APP_CONFIG_CACHE を上書きしても固定値が勝つ)
 *   2. pgsqlTestConfigCachePath() は projectRoot からの一意な固定パスを返し、
 *      Laravel の既定パス (bootstrap/cache/config.php) とは異なる
 *   3. pgsqlTestMigrationFileNames() はパスから拡張子・ディレクトリを取り除く
 *   4. pgsqlTestSchemaUnappliedMigrations() は「ファイル -> 表」の包含判定であり、
 *      表側だけ余分にあっても (vendor パッケージ由来) 合格になる一方、
 *      ファイル側にあって表に無いものは 1 件でも検出する
 *   5. ensureTestDatabaseSchemaUpdated() の 9 失敗経路 (Round 1 レビューの 7 条件を
 *      判定場所ごとに分解したもの) がそれぞれ独立して検出され、いずれも ok=false を返す
 *   6. 正常系では $runArtisan に渡る引数列が
 *      ['migrate', '--force', '--no-interaction'] → ['migrate:status', '--pending=1']
 *      の 2 回・この順序・この内容だけであり、それ以外の引数列は 1 度も渡らない
 *      (破壊的コマンドの主たる防御。ソース grep より強い — 文字列分割や動的組み立てで
 *      回避できない)
 *   7. 失敗経路のうち UnsafeDatabaseName は $runArtisan / $listMigrationFiles /
 *      $verifyAppliedMigrations のいずれも 1 度も呼ばない (短絡)
 *   8. ensure-test-db.php のソースが migrate:fresh / migrate:refresh / migrate:rollback /
 *      migrate:reset / db:wipe を使っていない (副次的な防御。負例。コメント中に同じ文字列を
 *      書いても検出するが、文字列を分割して動的に組み立てる呼び出しは検出できない —
 *      主たる防御は 6)
 *   9. pgsql_test_conn.php を複数の require_once エントリポイント
 *      (pgsql_test_conn.php 自身 / drop-test-db.php / ensure-test-db.php の 3 本) 経由で
 *      1 プロセス内で読み込んでも fatal error にならない (Round 2 レビューで発見された
 *      Critical の回帰防止。本テストが起動する**別プロセス**の中で 3 本を実際に
 *      require_once して検証する。fatal error が起きても本テストプロセス自体は
 *      巻き込まれない)
 *  10. realTestDatabaseSchemaUpdateCallables() の listMigrationFiles 結線が実際の
 *      database/migrations ディレクトリへ正しくつながっている (実 DB・実子プロセスを
 *      使わずに検証できる結線だけを対象にする。runArtisan・verifyAppliedMigrations の結線は
 *      実 DB・実子プロセスに触れるため対象外 — 施策2「保証しないこと」参照)
 *
 * 本テストは実 DB を作らず、artisan の実子プロセスも起動しない (純関数の入出力・
 * フェイク callable の呼び出し記録・ソース走査のみ)。ただし末尾の require 順検証だけは、
 * fatal error が起きても本テストプロセス自体を巻き込まないために PHP の別プロセスを
 * `proc_open()` で起動する (DB へは接続しない)。
 */

// ── pgsqlTestArtisanEnv(): 環境を継承しない子プロセス env ──

it('does not leak arbitrary environment variables into the child process env', function (): void {
    $original = getenv('SOME_SECRET');
    putenv('SOME_SECRET=leaked');

    try {
        $env = pgsqlTestArtisanEnv(__DIR__, 'app_test_8af22c44');
        expect($env)->not->toHaveKey('SOME_SECRET');
    } finally {
        putenv($original === false ? 'SOME_SECRET' : "SOME_SECRET={$original}");
    }
});

it('carries over only PATH / HOME / TMPDIR from the parent environment', function (): void {
    $env = pgsqlTestArtisanEnv(__DIR__, 'app_test_8af22c44');

    foreach (array_keys($env) as $key) {
        expect(in_array($key, ['PATH', 'HOME', 'TMPDIR'], true) || array_key_exists($key, [
            'APP_ENV' => true, 'APP_CONFIG_CACHE' => true, 'DB_CONNECTION' => true, 'DB_URL' => true,
            'DB_HOST' => true, 'DB_PORT' => true, 'DB_USERNAME' => true, 'DB_PASSWORD' => true,
            'DB_DATABASE' => true, 'CACHE_STORE' => true,
        ]))->toBeTrue("unexpected key leaked into artisan env: {$key}");
    }
});

it('forces DB_URL empty so that a URL-form connection string cannot override DB_DATABASE', function (): void {
    $env = pgsqlTestArtisanEnv(__DIR__, 'app_test_8af22c44');

    expect($env['DB_URL'])->toBe('');
});

it('pins the computed base name as DB_DATABASE and APP_ENV as testing', function (): void {
    $env = pgsqlTestArtisanEnv(__DIR__, 'app_test_8af22c44');

    expect($env['DB_DATABASE'])->toBe('app_test_8af22c44')
        ->and($env['APP_ENV'])->toBe('testing')
        ->and($env['DB_CONNECTION'])->toBe('pgsql');
});

it('overrides a parent environment that already sets DB_DATABASE / DB_URL / APP_CONFIG_CACHE to a dev DB', function (): void {
    $keys = ['DB_DATABASE', 'DB_URL', 'APP_CONFIG_CACHE'];
    $originals = array_combine($keys, array_map(getenv(...), $keys));

    putenv('DB_DATABASE=app');
    putenv('DB_URL=pgsql://postgres:postgres@127.0.0.1:5432/app');
    putenv('APP_CONFIG_CACHE=/tmp/attacker-controlled-config.php');

    try {
        $env = pgsqlTestArtisanEnv(__DIR__, 'app_test_8af22c44');

        expect($env['DB_DATABASE'])->toBe('app_test_8af22c44')
            ->and($env['DB_URL'])->toBe('')
            ->and($env['APP_CONFIG_CACHE'])->toBe(pgsqlTestConfigCachePath(__DIR__));
    } finally {
        foreach ($originals as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
    }
});

// ── pgsqlTestConfigCachePath(): ensure 専用の非既定パス ──

it('returns a fixed config cache path derived from the project root', function (): void {
    expect(pgsqlTestConfigCachePath('/workspace'))->toBe('/workspace/bootstrap/cache/ensure-test-db-schema-update.config-cache.php');
});

it('does not point at the Laravel default config cache path', function (): void {
    expect(pgsqlTestConfigCachePath('/workspace'))->not->toBe('/workspace/bootstrap/cache/config.php');
});

// ── pgsqlTestMigrationFileNames(): パス -> ファイル名 ──

it('strips directory and extension from migration file paths', function (): void {
    expect(pgsqlTestMigrationFileNames([
        '/workspace/database/migrations/2024_01_01_000000_create_users_table.php',
        '/workspace/database/migrations/2024_01_02_000000_create_teams_table.php',
    ]))->toBe([
        '2024_01_01_000000_create_users_table',
        '2024_01_02_000000_create_teams_table',
    ]);
});

it('returns an empty list for an empty input (does not throw)', function (): void {
    expect(pgsqlTestMigrationFileNames([]))->toBe([]);
});

// ── pgsqlTestSchemaUnappliedMigrations(): ファイル -> 表の包含判定 (正典より強い基準) ──

it('reports no unapplied migrations when every file is present in the applied set', function (): void {
    expect(pgsqlTestSchemaUnappliedMigrations(
        ['2024_01_01_000000_create_users_table', '2024_01_02_000000_create_teams_table'],
        ['2024_01_01_000000_create_users_table', '2024_01_02_000000_create_teams_table'],
    ))->toBe([]);
});

it('tolerates extra applied rows that do not correspond to a repository migration file (vendor packages)', function (): void {
    expect(pgsqlTestSchemaUnappliedMigrations(
        ['2024_01_01_000000_create_users_table', '2099_01_01_000000_vendor_package_table'],
        ['2024_01_01_000000_create_users_table'],
    ))->toBe([]);
});

it('detects a single missing migration file even when most files are applied', function (): void {
    expect(pgsqlTestSchemaUnappliedMigrations(
        ['2024_01_01_000000_create_users_table'],
        ['2024_01_01_000000_create_users_table', '2024_01_02_000000_create_teams_table'],
    ))->toBe(['2024_01_02_000000_create_teams_table']);
});

it('reports every file as unapplied when the applied set is empty (stale migrations table)', function (): void {
    expect(pgsqlTestSchemaUnappliedMigrations(
        [],
        ['2024_01_01_000000_create_users_table'],
    ))->toBe(['2024_01_01_000000_create_users_table']);
});

// ── ensureTestDatabaseSchemaUpdated(): テスト用フェイク callable ──

function fakeMigrationFiles(): callable
{
    return static fn (string $root): array => ['/x/database/migrations/2024_01_01_000000_create_users_table.php'];
}

function fakeVerification(array $applied): callable
{
    return static fn (string $root, string $base): array => ['tableExists' => true, 'applied' => $applied];
}

// ── 9 失敗経路 ──

it('rejects the dev database name before touching any injected boundary', function (): void {
    $runnerCalls = 0;
    $listCalls = 0;
    $verifyCalls = 0;

    $result = ensureTestDatabaseSchemaUpdated(
        '/workspace',
        'app', // dev DB
        function () use (&$runnerCalls): array {
            $runnerCalls++;

            return ['status' => 0, 'output' => ''];
        },
        function () use (&$listCalls): array {
            $listCalls++;

            return [];
        },
        function () use (&$verifyCalls): array {
            $verifyCalls++;

            return ['tableExists' => true, 'applied' => []];
        },
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::UnsafeDatabaseName)
        ->and($runnerCalls)->toBe(0)
        ->and($listCalls)->toBe(0)
        ->and($verifyCalls)->toBe(0);
});

it('rejects a name that is not on the allowlist', function (): void {
    $result = ensureTestDatabaseSchemaUpdated(
        '/workspace',
        'app_test_XYZ', // allowlist 不一致
        static fn (): array => ['status' => 0, 'output' => ''],
        fakeMigrationFiles(),
        fakeVerification([]),
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::UnsafeDatabaseName);
});

/**
 * 一時 projectRoot フィクスチャ (bootstrap/cache/... の 3 階層) を内側から後始末する。
 * 「削除するのはキャッシュディレクトリだけで bootstrap とフィクスチャルートが
 * /tmp に残る」を避けるための対応。
 */
function cleanupEnsureTestDbFixtureRoot(string $projectRoot): void
{
    $cachePath = pgsqlTestConfigCachePath($projectRoot);
    @unlink($cachePath);
    @rmdir(dirname($cachePath)); // .../bootstrap/cache
    @rmdir(dirname($cachePath, 2)); // .../bootstrap
    @rmdir($projectRoot);
}

it('refuses to start migrate when the dedicated config cache path already exists', function (): void {
    $projectRoot = sys_get_temp_dir().'/ensure-test-db-fixture-'.bin2hex(random_bytes(4));
    $cachePath = pgsqlTestConfigCachePath($projectRoot);
    mkdir(dirname($cachePath), recursive: true);
    file_put_contents($cachePath, '<?php return [];');

    try {
        $runnerCalls = 0;
        $result = ensureTestDatabaseSchemaUpdated(
            $projectRoot,
            'app_test_8af22c44',
            function () use (&$runnerCalls): array {
                $runnerCalls++;

                return ['status' => 0, 'output' => ''];
            },
            fakeMigrationFiles(),
            fakeVerification([]),
        );

        expect($result['ok'])->toBeFalse()
            ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::ConfigCacheStale)
            ->and($runnerCalls)->toBe(0);
    } finally {
        cleanupEnsureTestDbFixtureRoot($projectRoot);
    }
});

it('refuses to start migrate:status when the dedicated config cache path appears during migrate (second re-check point)', function (): void {
    // 未検証だった分岐: ConfigCacheStale の判定箇所は 2 か所あるが、
    // migrate 実行中に専用パスが出現するケースを別に固定する。
    $projectRoot = sys_get_temp_dir().'/ensure-test-db-fixture-'.bin2hex(random_bytes(4));
    $cachePath = pgsqlTestConfigCachePath($projectRoot);

    try {
        $calls = [];
        $result = ensureTestDatabaseSchemaUpdated(
            $projectRoot,
            'app_test_8af22c44',
            function (array $args) use (&$calls, $cachePath): array {
                $calls[] = $args;
                if ($args[0] === 'migrate') {
                    // migrate の実行中に専用パスが (異常として) 出現したことを模す。
                    mkdir(dirname($cachePath), recursive: true);
                    file_put_contents($cachePath, '<?php return [];');
                }

                return ['status' => 0, 'output' => ''];
            },
            fakeMigrationFiles(),
            fakeVerification([]),
        );

        expect($result['ok'])->toBeFalse()
            ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::ConfigCacheStale)
            ->and($calls)->toBe([['migrate', '--force', '--no-interaction']]); // migrate:status へは進んでいない
    } finally {
        cleanupEnsureTestDbFixtureRoot($projectRoot);
    }
});

it('fails when migrate exits non-zero', function (): void {
    $result = ensureTestDatabaseSchemaUpdated(
        '/workspace',
        'app_test_8af22c44',
        static fn (array $args): array => $args[0] === 'migrate'
            ? ['status' => 1, 'output' => 'boom']
            : ['status' => 0, 'output' => ''],
        fakeMigrationFiles(),
        fakeVerification([]),
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::MigrateFailed);
});

it('fails when migrate:status exits non-zero (either connection failure or unapplied migrations)', function (): void {
    $result = ensureTestDatabaseSchemaUpdated(
        '/workspace',
        'app_test_8af22c44',
        static fn (array $args): array => $args[0] === 'migrate:status'
            ? ['status' => 1, 'output' => 'pending: 2024_01_02_000000_create_teams_table']
            : ['status' => 0, 'output' => ''],
        fakeMigrationFiles(),
        fakeVerification([]),
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::MigrateStatusFailed)
        ->and($result['message'])->toContain('pending: 2024_01_02_000000_create_teams_table');
});

it('fails when migration file enumeration itself fails (glob returned false)', function (): void {
    $result = ensureTestDatabaseSchemaUpdated(
        '/workspace',
        'app_test_8af22c44',
        static fn (): array => ['status' => 0, 'output' => ''],
        static fn (string $root): bool => false,
        fakeVerification([]),
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::MigrationFileEnumerationFailed);
});

it('fails when there are zero migration files (distinct from glob failure)', function (): void {
    $result = ensureTestDatabaseSchemaUpdated(
        '/workspace',
        'app_test_8af22c44',
        static fn (): array => ['status' => 0, 'output' => ''],
        static fn (string $root): array => [],
        fakeVerification([]),
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::NoMigrationFiles);
});

it('fails when the verification connection throws', function (): void {
    $result = ensureTestDatabaseSchemaUpdated(
        '/workspace',
        'app_test_8af22c44',
        static fn (): array => ['status' => 0, 'output' => ''],
        fakeMigrationFiles(),
        static function (): array {
            throw new RuntimeException('connection refused');
        },
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::VerificationConnectionFailed)
        ->and($result['message'])->toContain('connection refused');
});

it('fails when the migrations table is missing after update', function (): void {
    $result = ensureTestDatabaseSchemaUpdated(
        '/workspace',
        'app_test_8af22c44',
        static fn (): array => ['status' => 0, 'output' => ''],
        fakeMigrationFiles(),
        static fn (): array => ['tableExists' => false, 'applied' => []],
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::MigrationsTableMissing);
});

it('fails when an unapplied migration remains after update', function (): void {
    $result = ensureTestDatabaseSchemaUpdated(
        '/workspace',
        'app_test_8af22c44',
        static fn (): array => ['status' => 0, 'output' => ''],
        fakeMigrationFiles(), // 期待 = ['2024_01_01_000000_create_users_table']
        static fn (): array => ['tableExists' => true, 'applied' => []], // 未適用のまま
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::UnappliedMigrationsRemain)
        ->and($result['message'])->toContain('2024_01_01_000000_create_users_table');
});

// ── 正常系 + 引数列そのものの検証 (破壊的コマンドの主たる防御) ──

it('succeeds and invokes the artisan runner with exactly two allowed argument lists, in order, and nothing else', function (): void {
    $calls = [];
    $result = ensureTestDatabaseSchemaUpdated(
        '/workspace',
        'app_test_8af22c44',
        function (array $args, array $env, bool $capture) use (&$calls): array {
            $calls[] = $args;

            return ['status' => 0, 'output' => ''];
        },
        fakeMigrationFiles(),
        fakeVerification(['2024_01_01_000000_create_users_table']),
    );

    expect($result['ok'])->toBeTrue()
        ->and($result['failure'])->toBeNull()
        ->and($calls)->toBe([
            ['migrate', '--force', '--no-interaction'],
            ['migrate:status', '--pending=1'],
        ]);
});

it('never calls the artisan runner with an argument list other than the two allowed forms, across every branch that reaches the runner', function (): void {
    // これまで「正常系・全ての失敗系を通しで走らせる」と書きながら実際には
    // 一部の分岐しか走らせないと乖離が生まれるため、データセット化して
    // runner へ実際に到達する主要分岐 (成功 / migrate 失敗 / migrate:status 失敗 /
    // 到達確認の 3 失敗いずれか) を明示的に列挙して回す。
    //
    // 対象外にした分岐とその理由:
    //   - UnsafeDatabaseName / migrate 前の ConfigCacheStale: $runArtisan を 1 度も呼ばない
    //     (専用のテストで呼び出し回数 0 を固定済み)
    //   - 移行後 ConfigCacheStale (migrate 中出現): 呼び出しが ['migrate', ...] の 1 回だけに
    //     短縮される特殊形であり、専用のテストで固定済み (this dataset の対象は
    //     「2 回とも呼ばれる」形に絞る)
    //   - MigrationFileEnumerationFailed / NoMigrationFiles: migrate + migrate:status が
    //     成功した後で失敗するため、runner への呼び出し列は 'success' と構造的に同一
    //     (どちらも重複してデータセットへ加える意味が無い)
    $allowed = [
        ['migrate', '--force', '--no-interaction'],
        ['migrate:status', '--pending=1'],
    ];

    $scenarios = [
        'success' => [
            'artisan' => static fn (array $args): array => ['status' => 0, 'output' => ''],
            'verify' => fakeVerification(['2024_01_01_000000_create_users_table']),
        ],
        'migrate failed' => [
            'artisan' => static fn (array $args): array => $args[0] === 'migrate' ? ['status' => 1, 'output' => ''] : ['status' => 0, 'output' => ''],
            'verify' => fakeVerification([]),
        ],
        'migrate:status failed' => [
            'artisan' => static fn (array $args): array => $args[0] === 'migrate:status' ? ['status' => 1, 'output' => ''] : ['status' => 0, 'output' => ''],
            'verify' => fakeVerification([]),
        ],
        'verification connection failed' => [
            'artisan' => static fn (array $args): array => ['status' => 0, 'output' => ''],
            'verify' => static function (): array {
                throw new RuntimeException('connection refused');
            },
        ],
        'migrations table missing' => [
            'artisan' => static fn (array $args): array => ['status' => 0, 'output' => ''],
            'verify' => static fn (): array => ['tableExists' => false, 'applied' => []],
        ],
        'unapplied migrations remain' => [
            'artisan' => static fn (array $args): array => ['status' => 0, 'output' => ''],
            'verify' => fakeVerification([]),
        ],
    ];

    foreach ($scenarios as $label => $scenario) {
        $seen = [];
        $spy = function (array $args, array $env, bool $capture) use (&$seen, $scenario): array {
            $seen[] = $args;

            return ($scenario['artisan'])($args);
        };

        ensureTestDatabaseSchemaUpdated('/workspace', 'app_test_8af22c44', $spy, fakeMigrationFiles(), $scenario['verify']);

        expect($seen)->not->toBe([], "scenario '{$label}' never called the runner (dataset entry would be vacuous)");
        foreach ($seen as $args) {
            // toContain() は可変長引数を「全て候補として含むこと」の判定に使うため、
            // 第2引数をカスタム失敗メッセージとしては使えない (Pest の仕様)。
            // 真偽判定 + toBeTrue() のメッセージ引数を使う。
            expect(in_array($args, $allowed, true))
                ->toBeTrue("unexpected artisan argument list in scenario '{$label}': ".implode(' ', $args));
        }
    }
});

// ── T-負例: 破壊的コマンドを使っていないこと (副次的な防御。主たる防御は上の引数列検証) ──

it('never mentions migrate:fresh, migrate:refresh, migrate:rollback, migrate:reset, or db:wipe in the source (secondary defense)', function (): void {
    $source = file_get_contents(__DIR__.'/../../../scripts/ci/ensure-test-db.php');
    expect($source)->toBeString();

    foreach (['migrate:fresh', 'migrate:refresh', 'migrate:rollback', 'migrate:reset', 'db:wipe'] as $forbidden) {
        // toContain() は可変長引数を全て候補として扱うため、第2引数をメッセージには使えない
        // (Pest の仕様。toBeFalse() のメッセージ引数を使う)。
        expect(str_contains($source, $forbidden))->toBeFalse("ensure-test-db.php が破壊的コマンド {$forbidden} を含んでいる");
    }
    expect($source)->toContain("'migrate', '--force'");
});

// ── 負のコントロール: 判定関数自身が空振りしていないことの確認 ──

it('negative control: the unapplied-migrations judgement actually flags a real gap', function (): void {
    // 前提: 何も適用されていない状態でファイルが 1 件でもあれば、必ず非空を返す
    // (この判定が定数 [] を返すだけの空振りになっていないことの確認)。
    expect(pgsqlTestSchemaUnappliedMigrations([], ['anything']))->not->toBe([]);
});

// ── realTestDatabaseSchemaUpdateCallables(): 結線の単体テスト (実 DB・実子プロセスを使わない範囲) ──

it('wires listMigrationFiles to the real database/migrations directory (no DB, no child process)', function (): void {
    // performTestDatabaseSchemaUpdate() の結線自体を Architecture テストは検証できない
    // (基点 DB が既に最新なら結線が壊れていても通ってしまう)。実 DB・実子プロセスを
    // 使わずに検証できる listMigrationFiles の結線だけを、ここで直接固定する
    // (runArtisan / verifyAppliedMigrations の結線は実 DB・実子プロセスに触れるため対象外。
    // 施策2「保証しないこと」参照)。
    $projectRoot = sys_get_temp_dir().'/ensure-test-db-wiring-'.bin2hex(random_bytes(4));
    mkdir($projectRoot.'/database/migrations', recursive: true);
    file_put_contents($projectRoot.'/database/migrations/2024_01_01_000000_create_users_table.php', '<?php');

    try {
        $callables = realTestDatabaseSchemaUpdateCallables($projectRoot);
        $files = ($callables['listMigrationFiles'])($projectRoot);

        expect($files)->toBe([$projectRoot.'/database/migrations/2024_01_01_000000_create_users_table.php']);
    } finally {
        // 後始末は内側から (作成した 3 階層を全て削除する)。
        @unlink($projectRoot.'/database/migrations/2024_01_01_000000_create_users_table.php');
        @rmdir($projectRoot.'/database/migrations');
        @rmdir($projectRoot.'/database');
        @rmdir($projectRoot);
    }
});

// ── 回帰テスト (共有ファイルの二重ロードで fatal error にならないことの裏取り) ──

it('requiring pgsql_test_conn.php via multiple require_once entrypoints in one process does not fatal', function (): void {
    // ensure-test-db.php / drop-test-db.php はどちらも内部で pgsql_test_conn.php を
    // require_once する。本テストは、それらを 1 つの別プロセスで実際に多重 require_once させ、
    // fatal にならないことを直接確認する (別プロセスにするのは、fatal error が起きた場合に
    // 本テストプロセス自体を巻き込まないため)。別プロセスが実際に require_once するのは
    // pgsql_test_conn.php 自身 / drop-test-db.php / ensure-test-db.php の 3 本である。
    $root = dirname(__DIR__, 3);
    $script = <<<'PHP'
    <?php
    require_once $argv[1].'/scripts/ci/pgsql_test_conn.php';
    require_once $argv[1].'/scripts/ci/drop-test-db.php';
    require_once $argv[1].'/scripts/ci/ensure-test-db.php';
    fwrite(STDOUT, 'OK');
    PHP;

    $scriptPath = tempnam(sys_get_temp_dir(), 'require-order-check-');
    file_put_contents($scriptPath, $script);

    try {
        $process = proc_open(
            [PHP_BINARY, $scriptPath, $root],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        expect(is_resource($process))->toBeTrue();
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        expect($status)->toBe(0, "require の多重ロードが fatal error になった: {$stderr}")
            ->and($stdout)->toContain('OK');
    } finally {
        @unlink($scriptPath);
    }
});
