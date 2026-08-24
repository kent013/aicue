<?php

declare(strict_types=1);

namespace Tests\Support\EnterpriseSso;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * 行ロックの排他を**実際に競合させて**確かめるための土台。
 *
 * グローバル `RefreshDatabase` はテスト全体を未コミットのトランザクションで包むので、
 * 既定の接続で作った検体は**別の接続から見えない**。本ハーネスは
 * `Tests\Support\Concurrency\OutOfTransactionFixtures` と同じ手口で
 * **別名接続の明示トランザクションで commit** し、2 本の接続から同じ行を触れるようにする。
 *
 * ## 何を証明できるか (誇張しない)
 *
 * - **証明する**: 1 本目が `SELECT … FOR UPDATE` で行を掴んでいる間、
 *   2 本目の同じ行のロック取得は**進めない** (`lock_timeout` で観測する)。
 *   1 本目がコミットして行を消したあと、2 本目は**行が無い**ものとして扱う
 *   = 使用権を得るのはちょうど 1 つである
 * - **証明しない**: 実 OS プロセスを 2 本立てた場合の挙動。本ハーネスは 1 プロセス内の
 *   **2 本の DB 接続**であり、排他の主体である pgsql の行ロックは同じだが、
 *   PHP 側の同時実行 (worker の競合) までは再現しない
 *
 * ## 後片付け
 *
 * 作った行は `RefreshDatabase` の巻き戻しでは消えない。呼び出し側は必ず
 * {@see self::cleanup()} を finally で呼ぶこと。
 */
final class CommittedConnectionHarness
{
    /** 検体の生成と「1 本目」に使う接続。 */
    public const string PRIMARY = 'enterprise_sso_committed_a';

    /** 「2 本目」に使う接続。 */
    public const string SECONDARY = 'enterprise_sso_committed_b';

    /**
     * 接続 id で絞って片付ける表 (FK 安全な順序。表名 => 絞り込む列)。
     *
     * ★検体の生成経路が別の表へ行を足すようになったら、この一覧を**同じ変更で増やす**。
     */
    private const array CONNECTION_SCOPED_TABLES = [
        'enterprise_sso_login_attempts' => 'organization_oidc_connection_id',
        'enterprise_identities' => 'organization_oidc_connection_id',
        'organization_oidc_connections' => 'id',
    ];

    /**
     * 組織 id で絞って片付ける表 (FK 安全な順序)。
     *
     * ★`organizations.laratrust_team_id` は **restrictOnDelete** なので
     *   「組織を消せば全部消える」は成り立たない。**組織 → teams の順**でなければ削除できない
     *   (`OutOfTransactionFixtures` と同じ理由)。
     */
    private const array ORGANIZATION_SCOPED_TABLES = [
        'organization_user' => 'organization_id',
        'custom_teams' => 'organization_id',
        'organizations' => 'id',
    ];

    /** インスタンス化しない。 */
    private function __construct() {}

    /**
     * 検体を**コミット済み**で作る (別接続から見える)。
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function create(Closure $callback): mixed
    {
        $original = Config::string('database.default');
        Assert::same($original, 'pgsql', 'このハーネスは pgsql レーンを前提にする');

        self::register($original, self::PRIMARY);

        $succeeded = false;
        try {
            config(['database.default' => self::PRIMARY]);
            $result = DB::connection(self::PRIMARY)->transaction($callback);
            $succeeded = true;

            return $result;
        } finally {
            config(['database.default' => $original]);

            if (! $succeeded) {
                self::forget(self::PRIMARY);
            }
        }
    }

    /**
     * 既定の接続を別名接続へ差し替えて実行する (アプリのコードをそのまま走らせるため)。
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function onConnection(string $name, Closure $callback): mixed
    {
        $original = Config::string('database.default');
        self::register($original, $name);

        try {
            config(['database.default' => $name]);

            return $callback();
        } finally {
            config(['database.default' => $original]);
        }
    }

    public static function connection(string $name): ConnectionInterface
    {
        $original = Config::string('database.default');
        self::register($original, $name);

        return DB::connection($name);
    }

    /**
     * ロックの待ち時間に上限を置く (待ち続けて 1 プロセスが固まらないようにする)。
     *
     * ★上限を超えたら pgsql は `55P03 lock_not_available` を投げる。
     *   「待たされたこと」を**例外として観測できる**のが要点である。
     */
    public static function limitLockWait(string $name, int $milliseconds): void
    {
        self::connection($name)->statement("SET lock_timeout = '{$milliseconds}ms'");
    }

    /**
     * 作った行を消す (呼び出し側が finally で呼ぶ。冪等)。
     *
     * ★**削除したあと、自分で残留ゼロを検査する**。呼び出し側だけに任せると、
     *   後片付けの完全性が「別のテストが緑であること」に依存してしまう。
     *
     * @param  list<int>  $userIds  JIT で作られた利用者 (組織の所属と役割を先に消してから消す)
     */
    public static function cleanup(int $connectionId, ?int $organizationId = null, array $userIds = []): void
    {
        $original = Config::string('database.default');
        self::register($original, self::PRIMARY);
        $connection = DB::connection(self::PRIMARY);

        try {
            foreach (self::CONNECTION_SCOPED_TABLES as $table => $column) {
                $connection->table($table)->where($column, $connectionId)->delete();
            }

            if ($organizationId !== null) {
                /** @var object{laratrust_team_id: int}|null $organization */
                $organization = $connection->table('organizations')->where('id', $organizationId)->first();

                foreach (self::ORGANIZATION_SCOPED_TABLES as $table => $column) {
                    $connection->table($table)->where($column, $organizationId)->delete();
                }

                if ($organization !== null) {
                    $connection->table('role_user')->where('team_id', $organization->laratrust_team_id)->delete();
                    $connection->table('teams')->where('id', $organization->laratrust_team_id)->delete();
                }
            }

            if ($userIds !== []) {
                $connection->table('users')->whereIn('id', $userIds)->delete();
            }

            foreach (self::CONNECTION_SCOPED_TABLES as $table => $column) {
                Assert::same(
                    $connection->table($table)->where($column, $connectionId)->count(),
                    0,
                    "検体の残留がある: {$table}",
                );
            }

            if ($organizationId !== null) {
                foreach (self::ORGANIZATION_SCOPED_TABLES as $table => $column) {
                    Assert::same(
                        $connection->table($table)->where($column, $organizationId)->count(),
                        0,
                        "検体の残留がある: {$table}",
                    );
                }
            }
        } finally {
            self::forget(self::PRIMARY);
            self::forget(self::SECONDARY);
        }
    }

    /** 開きっぱなしのトランザクションを best-effort で閉じる。 */
    public static function rollbackQuietly(string $name): void
    {
        try {
            $connection = self::connection($name);
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        } catch (Throwable) {
            // 片付けの失敗で本体の失敗を隠さない
        }
    }

    private static function register(string $original, string $name): void
    {
        if (Config::array("database.connections.{$name}", []) !== []) {
            return;
        }

        /** @var array<string, mixed> $base */
        $base = Config::array("database.connections.{$original}");
        config(["database.connections.{$name}" => $base]);
    }

    private static function forget(string $name): void
    {
        DB::disconnect($name);
        DB::purge($name);
    }
}
