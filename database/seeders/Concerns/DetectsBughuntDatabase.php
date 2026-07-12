<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * bug-hunt DB 名判定の SSOT (bug-hunt 隔離規約 ^bug_hunt(_[1-8])?$ と一致)。
 * bughunt 系 seeder の fail-secure guard から参照する。
 */
trait DetectsBughuntDatabase
{
    /** bug-hunt DB 名の許容 regex (scripts/bug-hunt-shard.sh の guard と一致させる)。 */
    private const string BUGHUNT_DB_REGEX = '/^bug_hunt(_[1-8])?$/';

    private function isBughuntDatabase(): bool
    {
        return preg_match(self::BUGHUNT_DB_REGEX, DB::connection()->getDatabaseName()) === 1;
    }
}
