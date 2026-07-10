<?php

declare(strict_types=1);

namespace Tests\Support\Ci;

use InvalidArgumentException;
use Webmozart\Assert\Assert;

/**
 * テスト用 DB 名の決定ロジック (pgsql 一本化)。
 *
 * 安全軸: tests/bootstrap.php が pgsqlOverrideDatabase() の算出値
 *   (`<slug>_test_<worktree-hash>`) で DB_DATABASE を後勝ち上書きし、直後に
 *   assertPgsqlTestDatabaseSafe() で「最終 DB 名が test DB (allowlist 一致 + 非 dev)」を
 *   Laravel boot 前に fail-closed 検証する一点に集約する。shell / docker-compose から
 *   DB_DATABASE=<dev DB> が leak しても override + 単一点ガードで dev DB には到達しない。
 *
 * paratest 実行時は Laravel の ParallelTesting が base 名に更に `_test_<token>` を
 * 付与してプロセスごとに分離する (2 段分離)。
 *
 * NOTE: prefix / denylist の 'app' は config('template.slug') の既定値に対応する
 *   (本クラスは Laravel boot 前に走るため config() は使えない)。アプリ初期化時の
 *   init.sh 置換対象。テンプレート派生アプリ名をここへ直書きしないこと
 *   (AppNameHardcodeTest)。
 */
final class TestDatabaseEnv
{
    /** テスト DB 名 prefix。config('template.slug') 既定値 'app' + '_test_' (init.sh 置換対象)。 */
    public const TEST_DB_PREFIX = 'app_test_';

    /** 実テスト DB 名の許可パターン (base または paratest worker)。不可逆 DROP / bootstrap ガードの正の allow。 */
    public const TEST_DB_ALLOWLIST_PATTERN = '/^app_test_[0-9a-f]{8}(_test_[0-9]+)?$/';

    /** dev DB 名の hard-deny 対象 (docker-compose の POSTGRES_DB / slug 既定値)。trim+lowercase 比較。 */
    public const DEV_DB_DENYLIST = ['app'];

    /** worktree root realpath の決定論的 8 桁 hash。別 worktree との DB 衝突を防ぐキー。 */
    public static function workrootHash(string $projectRoot): string
    {
        $real = realpath($projectRoot);
        Assert::string($real, 'projectRoot must resolve to a real path');

        return substr(sha1($real), 0, 8);
    }

    /**
     * pgsql base テスト DB 名 `<slug>_test_<hash>`。
     * 生成名が dev DB でない・allowlist 準拠であることを Assert する (理論破綻で fail-closed)。
     */
    public static function pgsqlBaseDatabase(string $projectRoot): string
    {
        $name = self::TEST_DB_PREFIX.self::workrootHash($projectRoot);

        if (self::isDevDatabase($name)) {
            throw new InvalidArgumentException("computed test DB name collided with a dev DB: {$name}");
        }
        Assert::true(self::isAllowedTestDatabase($name), "computed test DB name is not allowlisted: {$name}");

        return $name;
    }

    /**
     * pgsql のとき DB_DATABASE に強制すべき base 名。pgsql 以外 / 未設定は null。
     *
     * @param  array<string, mixed>  $server  $_SERVER 相当 (DB_CONNECTION を見て分岐)
     */
    public static function pgsqlOverrideDatabase(array $server, string $projectRoot): ?string
    {
        if (($server['DB_CONNECTION'] ?? null) !== 'pgsql') {
            return null;
        }

        return self::pgsqlBaseDatabase($projectRoot);
    }

    /**
     * 単一点 fail-closed ガード本体。pgsql lane で最終 DB_DATABASE が test DB
     * (allowlist 一致 + 非 dev) でなければ例外。tests/bootstrap.php から Laravel boot 前に呼ぶ。
     *
     * @throws InvalidArgumentException dev DB / 非 allowlist の場合
     */
    public static function assertPgsqlTestDatabaseSafe(string $effectiveDb): void
    {
        if (self::isDevDatabase($effectiveDb)) {
            throw new InvalidArgumentException("refusing to run pgsql tests against a dev DB: {$effectiveDb}");
        }
        Assert::true(self::isAllowedTestDatabase($effectiveDb), "effective pgsql test DB is not allowlisted: {$effectiveDb}");
    }

    /** DB 名が dev DB (variant 含む) か。前後空白・大小バリアントも塞ぐ。 */
    public static function isDevDatabase(string $name): bool
    {
        return in_array(strtolower(trim($name)), self::DEV_DB_DENYLIST, true);
    }

    /** DB 名が test allowlist に一致するか (不可逆 DROP・bootstrap ガードの正の allow)。 */
    public static function isAllowedTestDatabase(string $name): bool
    {
        return preg_match(self::TEST_DB_ALLOWLIST_PATTERN, $name) === 1;
    }
}
