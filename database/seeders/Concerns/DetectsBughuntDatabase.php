<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * bug-hunt DB 名判定の SSOT (fail-secure な**検出**側)。
 * bughunt 系 seeder の fail-secure guard から参照する。
 *
 * ★ 並列 cap は 4 (`scripts/bug-hunt-shard.sh` の `BUGHUNT_SHARD_CAP`) だが、本 regex は
 *   **cap と同期させない**。狭めると残留 `bug_hunt_5` を bughunt DB と認識できず
 *   「dev DB 扱い」になってしまう (= 検出漏れ)。同スクリプトの `SHARD_DB_RE` は
 *   「触れてよい DB の allowlist」で方向が逆である点に注意。
 */
trait DetectsBughuntDatabase
{
    /** bug-hunt DB 名の許容 regex (残留も検出するため cap より広い。上記 docblock 参照)。 */
    private const string BUGHUNT_DB_REGEX = '/^bug_hunt(_[1-8])?$/';

    private function isBughuntDatabase(): bool
    {
        return preg_match(self::BUGHUNT_DB_REGEX, DB::connection()->getDatabaseName()) === 1;
    }
}
