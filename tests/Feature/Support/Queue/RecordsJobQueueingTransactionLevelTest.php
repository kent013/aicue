<?php

declare(strict_types=1);

use App\Jobs\Billing\AutoRechargeTriggerJob;
use App\Jobs\Billing\SyncBillingCustomerDetails;
use App\Models\Organization;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\Queue\RecordsJobQueueingTransactionLevel;

/*
|--------------------------------------------------------------------------
| capture ヘルパ自身の挙動固定 (M9 の観測装置が嘘をつかないこと)
|--------------------------------------------------------------------------
|
| ★ **実 database queue 経由で確認する**。`Event::dispatch()` を直接叩くだけでは
|   `QueueManager` 経由の発火経路を検証したことにならない。
| ★ `Queue::fake()` は使わない (QueueFake::push は enqueueUsing を通らない)。
*/

beforeEach(function (): void {
    // 実 jobs 表へ積む (ジョブ本体は実行されない = 行が積まれるだけ)
    config()->set('queue.default', 'database');
});

test('capture 中は JobQueueing を記録する', function (): void {
    $collector = RecordsJobQueueingTransactionLevel::capture(
        static fn () => AutoRechargeTriggerJob::dispatch(1),
    );

    $records = RecordsJobQueueingTransactionLevel::only($collector->all(), AutoRechargeTriggerJob::class);
    expect($records)->toHaveCount(1);
    expect($records[0]['level'])->toBe(DB::transactionLevel());
});

test('capture 前から存在する listener は capture 中も capture 後も動く', function (): void {
    $seen = 0;
    Event::listen(JobQueueing::class, function () use (&$seen): void {
        $seen++;
    });

    RecordsJobQueueingTransactionLevel::capture(static fn () => AutoRechargeTriggerJob::dispatch(1));
    expect($seen)->toBe(1);

    AutoRechargeTriggerJob::dispatch(2);
    expect($seen)->toBe(2);
});

test('capture 後に別ジョブを dispatch しても collector->all() の件数が増えない', function (): void {
    $collector = RecordsJobQueueingTransactionLevel::capture(
        static fn () => AutoRechargeTriggerJob::dispatch(1),
    );
    expect($collector->all())->toHaveCount(1);

    AutoRechargeTriggerJob::dispatch(2);

    // ★ 同一 collector オブジェクトを capture 前後で比較する
    //   (配列を返す設計だと copy-on-write でこの検査が空振りする)
    expect($collector->all())->toHaveCount(1);
});

test('action が例外を投げても capture は例外を伝播し、直前の collector を汚染しない', function (): void {
    // ★ 保証範囲を誇張しない: 例外経路で戻り値 (collector) は呼び出し側へ渡らないため、
    //   「その collector に記録が増えないこと」は**外から観測できない** (finally の不活性化は
    //   メモリ上の効果に留まる)。ここで観測できるのは (a) 例外がそのまま伝播すること、
    //   (b) 例外を投げた capture が**先行 capture の collector を汚染しない**ことの 2 点である。
    //   finally 削除の変異 (#18) を赤くするのは「capture 後に増えない」テストの方である。
    $first = RecordsJobQueueingTransactionLevel::capture(
        static fn () => AutoRechargeTriggerJob::dispatch(1),
    );

    expect(function (): void {
        RecordsJobQueueingTransactionLevel::capture(static function (): void {
            AutoRechargeTriggerJob::dispatch(2);

            throw new RuntimeException('意図的な失敗');
        });
    })->toThrow(RuntimeException::class, '意図的な失敗');

    expect($first->all())->toHaveCount(1);
});

test('only() は対象ジョブクラスの記録だけを返す', function (): void {
    $organization = Organization::factory()->create(['stripe_id' => 'cus_only_test']);

    $collector = RecordsJobQueueingTransactionLevel::capture(static function () use ($organization): void {
        AutoRechargeTriggerJob::dispatch(1);
        SyncBillingCustomerDetails::dispatch($organization);
    });

    expect($collector->all())->toHaveCount(2);
    expect(RecordsJobQueueingTransactionLevel::only($collector->all(), AutoRechargeTriggerJob::class))
        ->toHaveCount(1);
    expect(RecordsJobQueueingTransactionLevel::only($collector->all(), SyncBillingCustomerDetails::class))
        ->toHaveCount(1);
});
