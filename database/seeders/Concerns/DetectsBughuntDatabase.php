<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use App\Support\BughuntDatabaseGuard;

/**
 * bughunt 系 seeder の fail-secure guard から参照する薄い殻。
 *
 * 判定の SSOT は `App\Support\BughuntDatabaseGuard`
 * (同じ判定を smoke コマンドの fail-secure 条件でも使うため app 側へ昇格した)。
 * ここには regex を持たない (二重管理をしない)。
 */
trait DetectsBughuntDatabase
{
    private function isBughuntDatabase(): bool
    {
        return app(BughuntDatabaseGuard::class)->isBughuntDatabase();
    }
}
