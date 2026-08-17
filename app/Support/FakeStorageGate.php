<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\ExternalFakes\ExternalFakeDeclaration;
use Illuminate\Contracts\Foundation\Application;

/**
 * 偽の保存先の有効化条件の単一正本 (fail-secure 二軸)。
 *
 * 経路登録 (BughuntFakesServiceProvider) と署名付き経路の action guard の双方が
 * 本メソッドを参照する (登録条件より実行時条件が弱いと経路キャッシュ残存で素通りするため
 * 完全一致させる)。
 *
 * 二軸:
 * 1. capability flag: ExternalFakeDeclaration::STORAGE_FLAG === true (既定 false = 完全 no-op)
 * 2. 許可環境: ExternalFakeDeclaration::STORAGE_ENVIRONMENTS (= bughunt.local / testing)。
 *    ただし testing は**自動テスト実行中に限る** (testing を HTTP 実行環境として素通ししない)。
 *    許可環境そのものは宣言側が正本で、testing への追加条件だけを本クラスが持つ。
 *
 * production は ProductionEnvGuard が flag=true を起動時 fail-fast で拒否する (二重防御)。
 */
final readonly class FakeStorageGate
{
    public function __construct(private Application $app) {}

    public function enabled(): bool
    {
        if (config(ExternalFakeDeclaration::STORAGE_FLAG) !== true) {
            return false;
        }

        $env = $this->app->environment();
        if (! in_array($env, ExternalFakeDeclaration::STORAGE_ENVIRONMENTS, true)) {
            return false;
        }

        return $env !== 'testing' || $this->app->runningUnitTests();
    }
}
