<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Queue\TriesOnceProbeJob;
use Tests\Support\Queue\TriesThriceProbeJob;
use Webmozart\Assert\Assert;

/*
 * ワーカー制限時間 (--timeout) に到達したとき何が起きるかを behavioral に固定する。
 * 「規則 1 が守る窓」(= 予約が残ったまま処理が消えている時間帯) が実在することをコードで示す。
 *
 * 経路 A (`queue:work`): SIGALRM ハンドラ (Worker::registerTimeoutHandler) が
 *   `markJobAsFailedIfWillExceedMaxAttempts()` を呼ぶ。本テストはこの 1 メソッドを
 *   ReflectionMethod で直接叩く (実プロセス・実 SIGALRM・実時間経過を使わない)。
 *
 * 経路 B (`queue:listen`) は自動テストにしない (実プロセス起動と最短でも --timeout 秒の
 * 実時間経過が要り、グローバルテストロック配下のテストレーンを数分占有するため)。
 * 代わりに vendor 実読の結果をここに固定する:
 *
 *   Listener::createCommand() は子へ --timeout を渡さない
 *   → WorkCommand の --once は Worker::runNextJob() を呼び、runNextJob() は SIGALRM を張らない
 *   → queue:listen 配下では Job 側 $timeout が効かず、親 Symfony Process の timeout が唯一の上限
 *   → 到達時は markJobAsFailedIfWillExceedMaxAttempts を通らず、予約が残ったまま子が kill され、
 *     ProcessTimedOutException が Listener::listen() を抜けて listener 本体も終了する
 *
 * この前提が変わると規則 1 の重要度そのものが変わるため、**Laravel のメジャー更新時は
 * ここを再確認する** (docs/architecture.md §キューのリース期間とワーカー制限時間の規約)。
 */

/**
 * ジョブを database 接続へ push して 1 件 pop し、SIGALRM ハンドラと同じ経路を叩く。
 *
 * テスト env は QUEUE_CONNECTION=sync のため接続名を必ず明示する。
 *
 * 戻り値は「失敗として確定したか」= `JobFailed` イベントの発火有無。
 * ★ `failed_jobs` テーブルへの記録そのものは Worker ではなく **`queue:work` コマンド側**の
 *   `JobFailed` リスナ (`WorkCommand::logFailedJob()`) が行うため、Worker 層だけを叩く
 *   本テストでは観測できない。失敗確定の分岐点はこのイベントであり、ここを固定すれば
 *   「timeout 到達で failed になるか / 予約が残るか」の遷移は behavioral に固定できる。
 */
function workerTimeoutProbe(object $job, int $maxTries): bool
{
    $failed = false;
    Event::listen(JobFailed::class, function () use (&$failed): void {
        $failed = true;
    });

    Queue::connection('database')->push($job);

    $popped = Queue::connection('database')->pop();
    Assert::isInstanceOf($popped, QueueJobContract::class, 'database 接続から予約済みジョブを取得できませんでした');

    $worker = app('queue.worker');
    Assert::isInstanceOf($worker, Worker::class);

    // SIGALRM ハンドラ (Worker::registerTimeoutHandler) が呼ぶのと同じ protected メソッド。
    // $maxTries は「CLI --tries とジョブ $tries の合成後の値」を直接渡す
    // (合成ロジック自体は Laravel の責務なのでテストしない)。
    $method = new ReflectionMethod(Worker::class, 'markJobAsFailedIfWillExceedMaxAttempts');
    $method->invoke($worker, 'database', $popped, $maxTries, new RuntimeException('worker timeout'));

    return $failed;
}

test('tries=1 のジョブは worker timeout で即座に失敗として確定する', function (): void {
    $failed = workerTimeoutProbe(new TriesOnceProbeJob, 1);

    expect($failed)->toBeTrue('tries=1 のジョブは timeout 到達で JobFailed (= failed_jobs 記録の契機) になるべき');
    expect(DB::table('jobs')->count())->toBe(0, '失敗確定後は jobs から削除されるべき');
});

test('tries=3 のジョブは worker timeout では failed にならず予約が残る', function (): void {
    $failed = workerTimeoutProbe(new TriesThriceProbeJob, 3);

    expect($failed)->toBeFalse('tries=3 のジョブは timeout 到達だけでは失敗確定しない');

    // 予約されたまま残る = retry_after 経過まで再配布されない = 規則 1 が守る窓。
    // ワーカー --timeout が retry_after 以上だと、この窓の中で同じジョブが二重取得される。
    $reserved = DB::table('jobs')->whereNotNull('reserved_at')->count();
    expect($reserved)->toBe(1, 'timeout で kill されたジョブは予約 (reserved_at) を残したまま jobs に残る');
});
