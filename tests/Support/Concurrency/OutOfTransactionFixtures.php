<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webmozart\Assert\Assert;

/**
 * テストの transaction の外に検体を作る (正典 v1 の要素 (2))。
 *
 * `RefreshDatabase` が検体を**未コミットの transaction の中**に置くため、子プロセスからは
 * 見えない。既定接続の設定を**複製した別名接続**を作り、**閉じた区間だけ**既定接続を
 * そこへ差し替えて生成し、その接続の**明示トランザクションで commit** する。
 *
 * ★**片付けは呼び出し側の責任**である。ここで作った行は `RefreshDatabase` の
 *   rollback では消えない。放置すると同一 worker の後続テストへ漏れる。
 * ★既定接続の差し替えは**閉じた区間だけ**で、finally で必ず元へ戻す。
 *   **失敗時は別名接続を disconnect + purge** し、成功時だけ後続の読み取り・cleanup 用に維持する。
 *
 * **保証しないもの**: 掃除するのは下の 8 表だけである。検体の生成経路が別の表へ
 * 行を足すようになったら、この一覧を同じ変更で増やす必要がある
 * (増やし忘れは {@see self::residueCounts()} では映らない = 8 表の外は見ていない)。
 */
final class OutOfTransactionFixtures
{
    public const string CONNECTION_NAME = 'concurrency_out_of_transaction';

    /**
     * 削除と残留検査の対象 (FK 安全な順序。表名 => 絞り込む列)。
     *
     * 順序が load-bearing である理由 (FK を全数実読した結果):
     * - `organizations.laratrust_team_id` は **restrictOnDelete** なので
     *   「組織を消せば全部消える」は成り立たない。**組織 → teams の順**でなければ削除できない
     * - `role_user.user_id` には FK が無い (polymorphic) ので、利用者を消しても連鎖しない
     *   (`teams` 削除の cascade で消える経路に依存する)
     * - `organizations` は softDeletes を持つので Eloquent の `delete()` では物理削除されない
     *   (本クラスは query builder で物理削除する)
     *
     * @var array<string, string>
     */
    private const array CLEANUP_TABLES = [
        'idempotency_keys' => 'api_key_id',
        'api_keys' => 'organization_id',
        'organization_user' => 'organization_id',
        'custom_teams' => 'organization_id',
        'organizations' => 'id',
        'role_user' => 'team_id',
        'teams' => 'id',
        'users' => 'id',
    ];

    /**
     * 検体を transaction の外へ作る。
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function create(Closure $callback): mixed
    {
        $original = config('database.default');
        Assert::stringNotEmpty($original);
        Assert::same($original, 'pgsql', 'このハーネスは pgsql レーンを前提にする');

        self::register($original);

        $succeeded = false;
        try {
            config(['database.default' => self::CONNECTION_NAME]);
            $result = DB::connection(self::CONNECTION_NAME)->transaction($callback);
            $succeeded = true;

            return $result;
        } finally {
            config(['database.default' => $original]);

            // ★失敗したら別名接続を残さない (握ったまま抜けると接続が漏れる)
            if (! $succeeded) {
                DB::disconnect(self::CONNECTION_NAME);
                DB::purge(self::CONNECTION_NAME);
            }
        }
    }

    /** 別名接続で読む (親の裏取り用。既定接続の transaction の中を見に行かない) */
    public static function connection(): ConnectionInterface
    {
        self::ensureRegistered();

        return DB::connection(self::CONNECTION_NAME);
    }

    /**
     * 呼び出し側が finally で呼ぶ。冪等 (何度呼んでも安全)。
     *
     * ★**削除したあと、自分で残留ゼロを検査する**。呼び出し側のテストだけに任せると、
     *   見本テストの後始末の完全性が「別のテストが緑であること」に依存してしまう。
     *   1 行でも残っていれば例外にする (後続テストを汚した状態で静かに通らない)。
     */
    public static function cleanup(ConcurrencyFixtureKeys $keys): void
    {
        self::ensureRegistered();

        try {
            self::deleteInForeignKeySafeOrder($keys);
            self::assertNoResidue($keys);
        } finally {
            DB::disconnect(self::CONNECTION_NAME);
            DB::purge(self::CONNECTION_NAME);
        }
    }

    /**
     * 8 表それぞれの残留件数を返す (表名 => 件数)。
     *
     * ★`cleanup()` から切り出して**公開**しているのは、検査器そのものを検査できるようにするため。
     *   `cleanup()` の中に埋め込むと「削除してから数える」経路でしか叩けず、
     *   「残留があるのに 0 と数える」退行を検出できない。
     *
     * @return array<string, int>
     */
    public static function residueCounts(ConcurrencyFixtureKeys $keys): array
    {
        $connection = self::connection();

        $counts = [];
        foreach (self::CLEANUP_TABLES as $table => $column) {
            $counts[$table] = $connection->table($table)
                ->where($column, self::keyFor($keys, $table))
                ->count();
        }

        return $counts;
    }

    /** 別名接続を登録する (既定接続設定の**完全な複製**。座標は 1 文字も変えない) */
    private static function register(string $original): void
    {
        $base = config("database.connections.{$original}");
        Assert::isArray($base);

        config(['database.connections.'.self::CONNECTION_NAME => $base]);
        DB::purge(self::CONNECTION_NAME);
    }

    /**
     * 別名接続の設定が無ければ既定接続から複製する (cleanup / connection の入口で使う)。
     */
    private static function ensureRegistered(): void
    {
        if (is_array(config('database.connections.'.self::CONNECTION_NAME))) {
            return;
        }

        $original = config('database.default');
        Assert::stringNotEmpty($original);
        Assert::same($original, 'pgsql', 'このハーネスは pgsql レーンを前提にする');

        self::register($original);
    }

    private static function deleteInForeignKeySafeOrder(ConcurrencyFixtureKeys $keys): void
    {
        $connection = self::connection();

        foreach (self::CLEANUP_TABLES as $table => $column) {
            // ★`organizations` は softDeletes を持つが、ここは query builder なので物理削除になる。
            $connection->table($table)
                ->where($column, self::keyFor($keys, $table))
                ->delete();
        }
    }

    private static function assertNoResidue(ConcurrencyFixtureKeys $keys): void
    {
        $residue = array_filter(self::residueCounts($keys), static fn (int $count): bool => $count > 0);

        if ($residue === []) {
            return;
        }

        $described = [];
        foreach ($residue as $table => $count) {
            $described[] = "{$table}={$count}";
        }

        throw new RuntimeException(
            'transaction 外の検体を片付けきれなかった (後続テストを汚す): '.implode(',', $described)
        );
    }

    /** 表ごとの絞り込みに使う主キー / 外部キーの値 */
    private static function keyFor(ConcurrencyFixtureKeys $keys, string $table): int
    {
        return match ($table) {
            'idempotency_keys' => $keys->apiKeyId,
            'api_keys', 'organization_user', 'custom_teams', 'organizations' => $keys->organizationId,
            'role_user', 'teams' => $keys->laratrustTeamId,
            'users' => $keys->userId,
        };
    }
}
