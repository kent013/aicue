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
