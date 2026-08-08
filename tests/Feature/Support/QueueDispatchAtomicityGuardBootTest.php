<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Support\QueueDispatchAtomicityGuard;

/*
|--------------------------------------------------------------------------
| guard の boot 配線 (AG-114 確定 2)
|--------------------------------------------------------------------------
|
| guard 単体の判定は QueueDispatchAtomicityGuardTest が固定する。ここで固定するのは
| **AppServiceProvider::boot() が実際に guard を通ること** と、config:cache 相当
| (config/queue.php の値をそのまま配列で読む状態) でも判定が変わらないことである。
|
| ★ guard の呼び出しは boot() の冒頭にあるため、違反構成では boot() の残りは実行されない
|   (= 本テストが provider の他の副作用を二重に走らせることはない)。
*/

test('AppServiceProvider::boot() から guard が呼ばれている', function (): void {
    // container 解決であることの固定 (差し替えた double が呼ばれる = boot が make している)
    app()->instance(QueueDispatchAtomicityGuard::class, new class extends QueueDispatchAtomicityGuard
    {
        public function enforce(bool $isProduction): void
        {
            throw new RuntimeException('guard-called:'.var_export($isProduction, true));
        }
    });

    expect(fn () => (new AppServiceProvider(app()))->boot())
        ->toThrow(RuntimeException::class, 'guard-called:false');
});

test('違反構成では boot() が fail-closed で止まる', function (): void {
    config()->set('queue.connections.sync', ['driver' => 'sync']); // after_commit 欠落 = R4 違反

    expect(fn () => (new AppServiceProvider(app()))->boot())
        ->toThrow(RuntimeException::class, 'Queue dispatch atomicity violations');
});

test('config:cache 相当 (config を配列から直接読む状態) でも判定が変わらない', function (): void {
    // config:cache は config/*.php の評価結果 (env() 解決済み) を配列として凍結する。
    // 凍結された値そのものを guard へ食わせ、実行時 config と同じく違反 0 件になることを固定する。
    $queue = require config_path('queue.php');
    expect($queue)->toBeArray();

    config()->set('queue.default', $queue['default']);
    config()->set('queue.connections', $queue['connections']);

    expect((new QueueDispatchAtomicityGuard)->violations(false))->toBe([]);
});
