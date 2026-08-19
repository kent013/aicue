<?php

declare(strict_types=1);

/*
 * scripts/ci/pgsql_test_conn.php
 *
 * ensure-test-db.php / drop-test-db.php 共有の接続 resolver。
 * ensure-test-db.php は base DB を「存在させ、スキーマを最新にする」ところまで担う
 * (家系の裁定 AG-135)。本ファイルはその接続値解決・環境組み立て・到達確認の判定を
 * ensure/drop の双方へ共有し、「ensure は作るが drop は別 PostgreSQL を見て回収しない」
 * (stale DB) や「スクリプトと検査で判定がずれる」ことを構造的に排除する。
 *
 * 接続値の解決はテスト lane (APP_ENV=testing) と同一の優先順位:
 *   shell env (docker-compose の export が最優先) → .env.testing → 固定 default
 *   (127.0.0.1:5432 postgres/postgres = .env.testing の既定値と同一)。
 * これにより phpunit 本体と ensure/drop が必ず同じ PostgreSQL を見る。
 *
 * maintenance DB は固定で `postgres` (CREATE/DROP DATABASE は TX 内不可なので
 * autocommit 接続を maintenance DB に張る)。実テスト base 名は
 * TestDatabaseEnv::pgsqlBaseDatabase() が決める (本ファイルは名前を決めない)。
 */

/**
 * テスト lane と同一優先順位で DB 接続値を解決する。
 *
 * @return array{host: string, port: string, username: string, password: string}
 */
function pgsqlTestConnValues(string $projectRoot): array
{
    // shell env を尊重しつつ .env.testing で補完する (Laravel testing lane と同じ immutable 挙動)
    if (is_file($projectRoot.'/.env.testing') && class_exists(Dotenv\Dotenv::class)) {
        Dotenv\Dotenv::createImmutable($projectRoot, '.env.testing')->safeLoad();
    }

    $env = static function (string $key, string $default): string {
        $v = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        return is_string($v) && $v !== '' ? $v : $default;
    };

    return [
        'host' => $env('DB_HOST', '127.0.0.1'),
        'port' => $env('DB_PORT', '5432'),
        'username' => $env('DB_USERNAME', 'postgres'),
        'password' => $env('DB_PASSWORD', 'postgres'),
    ];
}

/**
 * maintenance DB (`postgres`) への autocommit PDO を返す。
 * CREATE/DROP DATABASE は TX 内で実行不可のため maintenance DB へ張る。
 */
function pgsqlTestMaintenancePdo(string $projectRoot): PDO
{
    $c = pgsqlTestConnValues($projectRoot);
    $dsn = "pgsql:host={$c['host']};port={$c['port']};dbname=postgres";

    return new PDO($dsn, $c['username'], $c['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 10,
    ]);
}

/**
 * 識別子 (DB 名) を PostgreSQL の二重引用符でクォートする。
 * DB 名は allowlist 正規表現で検証済みのものだけを渡す前提 (二重防御)。
 */
function pgsqlQuoteIdentifier(string $name): string
{
    return '"'.str_replace('"', '""', $name).'"';
}

/**
 * base DB 不在時のみ実行する CREATE DATABASE 文を生成する (冪等は呼び出し側で pg_database 確認)。
 * base 名は TestDatabaseEnv::pgsqlBaseDatabase() (allowlist 準拠) からのみ渡される前提。
 */
function pgsqlCreateDatabaseSql(string $name): string
{
    return 'CREATE DATABASE '.pgsqlQuoteIdentifier($name);
}

/**
 * allowlist 検証済み DB 名に対する DROP 文を生成する。WITH (FORCE) で接続中でも落とす。
 */
function pgsqlDropDatabaseSql(string $name): string
{
    return 'DROP DATABASE IF EXISTS '.pgsqlQuoteIdentifier($name).' WITH (FORCE)';
}

/**
 * allowlist 検証済み DB 名に、出自 (worktree の realpath) を記録する COMMENT 文を生成する。
 *
 * 孤児 sweep (`drop-test-db.php --orphans`) が「削除済み worktree の残骸」と
 * 「同一 PostgreSQL を共有する**別クローンの生存 DB**」を区別するための**分類材料**。
 * **信頼境界ではない** (誰でも書き換えられるため単独では guard にならない)。
 * 識別子は pgsqlQuoteIdentifier、リテラルは PDO::quote で組み立てる (独自連結はしない。
 * provenance path に `'` が含まれうる)。非破壊 DDL なので ensure 側から実行してよい。
 */
function pgsqlCommentDatabaseSql(PDO $pdo, string $name, string $provenance): string
{
    return 'COMMENT ON DATABASE '.pgsqlQuoteIdentifier($name).' IS '.$pdo->quote($provenance);
}

/** ensure が行う操作。SQL 生成はしない (クォート責務は既存の SQL ビルダに残す)。 */
enum TestDatabaseEnsureAction
{
    case Create;
    case StampProvenance;
    case UpdateSchema;
}

/**
 * ensure が実行すべき action 列を返す (純関数。PDO にも SQL にも触れない)。
 *
 * 実行順は **CREATE → 出自の記録 → スキーマ更新** で固定する。出自の記録をスキーマ更新より
 * 先に置くのは、スキーマ更新が失敗したときに「ラベルの無い現役 DB」を残さないためである
 * (aicue:D30 が揃え続けると宣言した不変条件の 1 つ)。
 *
 * **両分岐とも StampProvenance と UpdateSchema を含む**のが契約: 既存 DB のときに省くと、
 * 前者は「ラベルの無い現役 DB」、後者は「基点 DB のスキーマが古いまま放置される」につながる
 * (= どちらも冪等にする)。
 *
 * @return list<TestDatabaseEnsureAction>
 *                                        $exists=false → [Create, StampProvenance, UpdateSchema] /
 *                                        $exists=true  → [StampProvenance, UpdateSchema]
 */
function testDatabaseEnsurePlan(bool $exists): array
{
    return $exists
        ? [TestDatabaseEnsureAction::StampProvenance, TestDatabaseEnsureAction::UpdateSchema]
        : [
            TestDatabaseEnsureAction::Create,
            TestDatabaseEnsureAction::StampProvenance,
            TestDatabaseEnsureAction::UpdateSchema,
        ];
}

/**
 * provenance ラベルを **best-effort** で実行する。`$exec` を注入するので PDO 無しでテストできる。
 *
 * fail-closed にしない理由: comment は分類材料であって必須ではない。ここで落とすと
 * 権限設定の差でテスト実行そのものが止まり、**偽赤を増やす**。
 * 「ラベルの無い DB がフラグ 1 つで一括 DROP される」危険の方は
 * `--include-hash` の明示指定制 (一括フラグを用意しない) で構造的に潰してある。
 *
 * 例外だけでなく **`$exec` の戻り値 `false`** も失敗として扱う
 * (`PDO::exec()` は ERRMODE 次第で例外ではなく false を返す)。
 *
 * @param  callable(string): mixed  $exec
 * @return bool 成功したか (失敗時は false + stderr へ warning。例外は伝播させない)
 */
function pgsqlStampProvenance(callable $exec, string $sql): bool
{
    try {
        if ($exec($sql) === false) {
            fwrite(STDERR, "ensure-test-db: provenance コメントの記録に失敗 (best-effort / 続行)\n");

            return false;
        }

        return true;
    } catch (Throwable $e) {
        fwrite(STDERR, "ensure-test-db: provenance コメントの記録に失敗 (best-effort / 続行): {$e->getMessage()}\n");

        return false;
    }
}

/**
 * 指定した DB への PDO (スキーマ更新後の到達確認用。maintenance DB ではない)。
 */
function pgsqlTestDatabasePdo(string $projectRoot, string $database): PDO
{
    $c = pgsqlTestConnValues($projectRoot);
    $dsn = "pgsql:host={$c['host']};port={$c['port']};dbname={$database}";

    return new PDO($dsn, $c['username'], $c['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 10,
    ]);
}

/**
 * スキーマ更新の子プロセス**専用**の設定キャッシュパス。
 *
 * Laravel の既定パス (`bootstrap/cache/config.php`) を返す形は採らず、通常の
 * `php artisan config:cache` が**絶対に書かない**専用パスを返す。`pgsqlTestArtisanEnv()` は
 * この値をそのまま `APP_CONFIG_CACHE` として子プロセスへ渡すため、子プロセスは既定パスの
 * 残存状態に一切左右されない (既定パスが別プロセスの `config:cache` で再生成されても、
 * 子プロセスはこの専用パスしか見ない)。
 *
 * この専用パスの存在チェック (`ensureTestDatabaseSchemaUpdated()` が各 artisan 起動の直前に行う)
 * は、したがって「よくある race の検出」ではなく「通常経路では誰も生成しないはずの専用パスが、
 * なぜか既に存在している」という**通常は起こらない異常**の検出になる (通常の `migrate` 子プロセスも
 * 設定キャッシュファイル自体は書き出さない。書くとすれば `config:cache` コマンドだけであり、
 * それはこの専用パスへ向けて実行されることがない)。
 * **多重起動の排除までは主張しない**: `scripts/setup-worktree.sh` はグローバルテストロックの
 * **外**でこのスクリプトを呼ぶため (worktree 作成そのものを壊さないための意図的な設計)、
 * 同一 worktree 内で本スクリプトが多重に起動される余地は理論上ゼロではない。
 * この専用パスの存在チェックが fail-closed で拾うのは、その多重起動を含む「原因を問わず
 * 専用パスが既に存在する」という異常そのものである。
 */
function pgsqlTestConfigCachePath(string $projectRoot): string
{
    return $projectRoot.'/bootstrap/cache/ensure-test-db-schema-update.config-cache.php';
}

/**
 * スキーマ更新の子プロセスへ渡す環境変数を **継承せず** 組み立てる。
 *
 * 継承しないのが要点である: この devcontainer では shell に dev DB 名が export されており、
 * 素直に継承すると更新が dev DB へ当たる (AGENTS.md 禁止事項 3)。
 * DB 接続先は pgsqlTestConnValues() で解決した値をそのまま渡し、phpunit 本体と
 * 同じ PostgreSQL を見ることを保つ。
 *
 * URL 形の接続指定は DB_URL 1 つだけを空で固定する — config/database.php が読む URL 形の
 * キーは env('DB_URL') だけであり、読み手のいないキーを足すと「効いているつもりの設定」が
 * 増えるだけだからである。
 *
 * **この関数単独は安全な実行境界にならない**。渡された `$database` をそのまま
 * `DB_DATABASE` に固定するだけであり、`$database` が dev DB かどうかの判定は行わない。
 * 呼び出し側 (`ensureTestDatabaseSchemaUpdated()`) が、この関数を呼ぶ**直前**に
 * `TestDatabaseEnv::isDevDatabase()` / `isAllowedTestDatabase()` を再検証する契約になっている。
 *
 * @return array<string, string>
 */
function pgsqlTestArtisanEnv(string $projectRoot, string $database): array
{
    $conn = pgsqlTestConnValues($projectRoot);

    $inherited = [];
    foreach (['PATH', 'HOME', 'TMPDIR'] as $key) {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
        if (is_string($value) && $value !== '') {
            $inherited[$key] = $value;
        }
    }

    // 固定値が常に勝つ順で合成する。
    return array_merge($inherited, [
        'APP_ENV' => 'testing',
        'APP_CONFIG_CACHE' => pgsqlTestConfigCachePath($projectRoot),
        'DB_CONNECTION' => 'pgsql',
        'DB_URL' => '',
        'DB_HOST' => $conn['host'],
        'DB_PORT' => $conn['port'],
        'DB_USERNAME' => $conn['username'],
        'DB_PASSWORD' => $conn['password'],
        'DB_DATABASE' => $database,
        'CACHE_STORE' => 'array',
    ]);
}

/**
 * database/migrations のファイル名一覧 (拡張子・ディレクトリ抜き) を返す。
 *
 * ensure-test-db.php の到達確認と tests/Architecture/BaseTestDatabaseSchemaTest.php の
 * B-2 が **同じ関数** を呼ぶことで、判定基準がスクリプトと検査でずれないようにする。
 *
 * @param  list<string>  $migrationPaths  glob() が返すファイルパスの列
 * @return list<string>
 */
function pgsqlTestMigrationFileNames(array $migrationPaths): array
{
    return array_values(array_map(
        static fn (string $path): string => basename($path, '.php'),
        $migrationPaths,
    ));
}

/**
 * 到達確認の判定 (正典より強い基準)。
 *
 * 正典の到達確認は「migrations 表があり行が 1 件以上ある」で止まる。これでは
 * **古い基点 DB に古い migrations 表が残っている**状態を通してしまう。
 * 本関数は比較の向きをファイル→表の包含にする (集合の一致は求めない。vendor パッケージ由来の
 * migration が表に増えうるため)。
 *
 * @param  list<string>  $appliedMigrations  migrations 表の migration 列
 * @param  list<string>  $migrationFileNames  database/migrations の全ファイル名 (拡張子抜き)
 * @return list<string> 未適用のファイル名 (空 = 到達確認 OK)
 */
function pgsqlTestSchemaUnappliedMigrations(array $appliedMigrations, array $migrationFileNames): array
{
    return array_values(array_diff($migrationFileNames, $appliedMigrations));
}
