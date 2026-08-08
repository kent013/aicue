<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * キュー投入時点の DB トランザクション深さを記録するテストヘルパ。
 *
 * `Illuminate\Queue\Events\JobQueueing` は `Queue::enqueueUsing()` の内部から発火するため、
 * 「実際に push が起きた瞬間」の tx level を観測できる。
 *
 * ★ **`Queue::fake()` と併用してはならない**。`QueueFake::push()` は `enqueueUsing` を通らず
 *   即時記録するため、この観測点も after_commit の解決も素通りする
 *   (BillingCustomerSynchronizerTest の docblock が既に警告している落とし穴)。
 *
 * ★ 判定は **action 直前の `DB::transactionLevel()` (baseline) + 1 以上**である。
 *   固定値 (`>= 2`) では判定しない — ネストの深さはテストの書き方で変わるため。
 *
 * ★ 観測の前提: 対象ジョブが使う接続が driver=database かつ **after_commit=false** であること。
 *   `after_commit=true` の接続では `JobQueueing` が commit 後の callback 内で発火し、
 *   観測される level が baseline に落ちる。テスト側で前提そのものを assert すること。
 */
final class RecordsJobQueueingTransactionLevel
{
    /**
     * `$action` の実行中に発火した `JobQueueing` の tx level を記録する。
     *
     * ★ **1 テスト 1 capture**。同一テスト内で複数回呼ぶと listener が重複し記録が混線する。
     *
     * ★ listener の隔離は **元 dispatcher に listener を足し、capture 終了後にその closure を
     *   不活性化する**方式で行う。採らなかった 2 案とその理由:
     *   - `Event::forget(JobQueueing::class)`: capture 以前から存在した同イベントの listener まで
     *     削除する。「現時点で grep 0 件」は恒久的な安全性にならない
     *   - **dispatcher の clone へ swap**: `QueueManager` は解決済みの queue connection を
     *     キャッシュし、connection は自分が持つ container 経由で event dispatcher を引く。
     *     swap 前に connection が生成済みなら clone 側の listener が `JobQueueing` を捕捉できず、
     *     swap 中に生成された connection は capture 後も clone dispatcher を握り続けうる
     *   不活性化方式なら dispatcher の差し替えも既存 listener の削除も起きない。
     *   グローバルな application 再生成によりテスト終了時に dispatcher ごと破棄されるため、
     *   「1 テスト 1 capture」の規約下では不活性 listener はそのテスト中に高々 1 個残るだけである。
     *
     * ★ 戻り値は **配列ではなく可変 collector オブジェクト**である (理由は
     *   `JobQueueingTransactionRecords` の docblock)。
     */
    public static function capture(callable $action): JobQueueingTransactionRecords
    {
        $collector = new JobQueueingTransactionRecords;

        Event::listen(JobQueueing::class, function (JobQueueing $event) use ($collector): void {
            $job = $event->job;
            $collector->record(is_object($job) ? $job::class : (string) $job, DB::transactionLevel());
        });

        try {
            $action();
        } finally {
            $collector->active = false; // action が例外を投げても必ず不活性化する
        }

        return $collector;
    }

    /**
     * 対象ジョブクラスの記録だけを抜き出す。
     * action 中に付随ジョブが増えても無関係な理由で壊れないようにするため、
     * assert は必ずこの filter を通した結果に対して行う。
     *
     * @param  list<array{job: string, level: int}>  $records  `$collector->all()` を渡す
     * @return list<array{job: string, level: int}>
     */
    public static function only(array $records, string $jobClass): array
    {
        return array_values(array_filter(
            $records,
            static fn (array $record): bool => $record['job'] === $jobClass,
        ));
    }
}
