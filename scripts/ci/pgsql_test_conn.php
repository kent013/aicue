<?php

declare(strict_types=1);

/*
 * scripts/ci/pgsql_test_conn.php
 *
 * ensure-test-db.php / drop-test-db.php 共有の接続 resolver。
 * 両スクリプトが本ファイルを require し、同一の接続値・同一の maintenance PDO を使うことで
 * 「ensure は作るが drop は別 PostgreSQL を見て回収しない」ズレ (stale DB) を構造的に排除する。
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
}

/**
 * ensure が実行すべき action 列を返す (純関数。PDO にも SQL にも触れない)。
 *
 * **両分岐とも StampProvenance を含む**のが契約: 既存 DB のときに省くと
 * 「ラベルの無い現役 DB」が生まれ、将来の孤児 sweep の分類材料が欠ける (= 冪等にする)。
 *
 * @return list<TestDatabaseEnsureAction>
 *                                        $exists=false → [Create, StampProvenance] / $exists=true → [StampProvenance]
 */
function testDatabaseEnsurePlan(bool $exists): array
{
    return $exists
        ? [TestDatabaseEnsureAction::StampProvenance]
        : [TestDatabaseEnsureAction::Create, TestDatabaseEnsureAction::StampProvenance];
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
