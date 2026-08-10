<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * bug-hunt DB 名判定の SSOT (fail-secure な**検出**側)。
 *
 * ★ 並列 cap は 4 (`scripts/bug-hunt-shard.sh` の `BUGHUNT_SHARD_CAP`) だが、本 regex は
 *   cap と同期させない。狭めると残留 `bug_hunt_5` を bughunt DB と認識できず「dev DB 扱い」に
 *   なってしまう (= 検出漏れ)。同スクリプトの `SHARD_DB_RE` は「触れてよい DB の allowlist」で
 *   方向が逆である点に注意。
 * ★ 依存の向きは app ← seeders。seeder 側 trait (DetectsBughuntDatabase) は本クラスへ
 *   委譲するだけの薄い殻にする。
 */
final readonly class BughuntDatabaseGuard
{
    /** bug-hunt DB 名の許容 regex (残留も検出するため cap より広い。上記 docblock 参照)。 */
    private const string BUGHUNT_DB_REGEX = '/^bug_hunt(_[1-8])?$/';

    /** 現在の既定接続が bug-hunt DB を指しているか。 */
    public function isBughuntDatabase(): bool
    {
        return self::matches(DB::connection()->getDatabaseName());
    }

    /** 名前だけを見る純関数 (テストで DB 接続なしに判定表を固定できる)。 */
    public static function matches(string $databaseName): bool
    {
        return preg_match(self::BUGHUNT_DB_REGEX, $databaseName) === 1;
    }
}
