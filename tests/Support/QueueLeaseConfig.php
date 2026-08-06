<?php

declare(strict_types=1);

namespace Tests\Support;

use Webmozart\Assert\Assert;

/**
 * キューのリース期間 (retry_after) の「リポジトリに書かれている値」を読む helper。
 *
 * ★ `config()` 経由にしない。テスト env は `QUEUE_CONNECTION=sync` であり、
 *   env 上書き (`DB_QUEUE_RETRY_AFTER` 等) も混ざるため、config() で読むと
 *   「gate は通るが本番の実値は別」を作れてしまう (gate が嘘をつく)。
 *   ここでは `config/queue.php` を **直接 require** して素の配列を読む。
 *
 * Pest のファイルスコープ関数はテストファイル間で衝突しうるため、
 * `Tests\Support\AnalysisBudget` と同じくクラスの static メソッドへ集約する
 * (QueueWorkerLeaseInvariantTest / QueuedJobLeaseInventoryTest の両方から使う)。
 */
final class QueueLeaseConfig
{
    /**
     * `driver` が `database` の接続 (接続名 => retry_after 秒)。
     *
     * @return array<string, int>
     */
    public static function databaseConnections(): array
    {
        $config = require self::configPath();
        Assert::isArray($config, 'config/queue.php が配列を返していません');
        Assert::keyExists($config, 'connections', 'config/queue.php に connections がありません');

        // 配列 offset 式のままだと narrowing が保たれないためローカル変数へ移す
        $connections = $config['connections'];
        Assert::isArray($connections, 'config/queue.php の connections が配列ではありません');

        $result = [];
        foreach ($connections as $name => $connection) {
            Assert::string($name, 'config/queue.php の接続名が文字列ではありません');
            Assert::isArray($connection, "config/queue.php の接続 {$name} が配列ではありません");

            $driver = $connection['driver'] ?? null;
            if ($driver !== 'database') {
                continue;
            }

            Assert::keyExists($connection, 'retry_after', "接続 {$name} に retry_after がありません");
            $retryAfter = $connection['retry_after'];
            Assert::integer($retryAfter, "接続 {$name} の retry_after が int ではありません");
            Assert::greaterThan($retryAfter, 0, "接続 {$name} の retry_after が正の整数ではありません");

            $result[$name] = $retryAfter;
        }

        Assert::notEmpty($result, 'driver=database の接続が 1 つもありません');

        return $result;
    }

    /** `config/queue.php` の絶対パス (テストは worktree のルートから走る)。 */
    public static function configPath(): string
    {
        return dirname(__DIR__, 2).'/config/queue.php';
    }
}
