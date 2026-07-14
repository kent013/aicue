<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Foundation\Application;

/**
 * storage fake の有効化 predicate の SSOT (fail-secure 二軸)。
 *
 * route 登録 (FakeExternalsServiceProvider) と signed route action guard の双方が
 * 本メソッドを参照する (登録条件より実行時条件が弱いと route cache 残存で素通りするため
 * 完全一致させる)。
 *
 * 二軸:
 * 1. capability flag: config('testing.fake_storage') === true (既定 false = 完全 no-op)
 * 2. env allowlist: bughunt.local ∨ (testing ∧ runningUnitTests)
 *    - bughunt.local: 実 bug-hunt runtime
 *    - testing ∧ runningUnitTests: 自動テストのみ (testing を HTTP 実行環境として素通ししない)
 *
 * production は ProductionEnvGuard が flag=true を deploy 時 fail-fast で拒否する (二重防御)。
 */
final readonly class FakeStorageGate
{
    public function __construct(private Application $app) {}

    public function enabled(): bool
    {
        if (config('testing.fake_storage') !== true) {
            return false;
        }

        $env = $this->app->environment();
        if ($env === 'bughunt.local') {
            return true;
        }

        return $env === 'testing' && $this->app->runningUnitTests();
    }
}
