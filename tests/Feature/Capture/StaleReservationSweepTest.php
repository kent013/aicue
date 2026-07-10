<?php

declare(strict_types=1);

use App\Enums\Capture\TakeUploadReservationStatus;
use App\Models\Cut;
use App\Models\TakeUploadReservation;
use App\Services\Capture\StaleUploadReservationSweeper;
use App\Services\Capture\TakeObjectStorage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Mockery\MockInterface;

/*
 * 孤児掃除 (施策9): 期限切れ pending / stale verifying の released 化 + S3 孤児削除。
 * fresh verifying (検証中) には触れない。released/completed の retention 超過行は物理削除。
 */

/** updated_at をモデルイベントなしで過去に倒す */
function backdateReservation(TakeUploadReservation $reservation, int $minutes): void
{
    DB::table('take_upload_reservations')
        ->where('id', $reservation->id)
        ->update(['updated_at' => now()->subMinutes($minutes)]);
}

function mockSweeperStorage(bool $exists = true): MockInterface
{
    $mock = Mockery::mock(TakeObjectStorage::class);
    $mock->shouldReceive('exists')->andReturn($exists)->byDefault();
    $mock->shouldReceive('delete')->byDefault();
    app()->instance(TakeObjectStorage::class, $mock);

    return $mock;
}

test('期限切れ pending は released 化され、PUT 済みオブジェクトは削除される', function (): void {
    $cut = Cut::factory()->create();
    $stale = TakeUploadReservation::factory()->forCut($cut)->expired()->create();
    $mock = Mockery::mock(TakeObjectStorage::class);
    $mock->shouldReceive('exists')->once()->with($stale->video_path)->andReturnTrue();
    $mock->shouldReceive('delete')->once()->with($stale->video_path);
    app()->instance(TakeObjectStorage::class, $mock);

    $released = app(StaleUploadReservationSweeper::class)->sweep();

    expect($released)->toBe(1);
    expect($stale->fresh()?->status)->toBe(TakeUploadReservationStatus::Released);
});

test('PUT 未完了 (exists=false) の期限切れ pending は released のみで delete は呼ばれない', function (): void {
    $cut = Cut::factory()->create();
    $stale = TakeUploadReservation::factory()->forCut($cut)->expired()->create();
    $mock = Mockery::mock(TakeObjectStorage::class);
    $mock->shouldReceive('exists')->once()->andReturnFalse();
    $mock->shouldNotReceive('delete');
    app()->instance(TakeObjectStorage::class, $mock);

    expect(app(StaleUploadReservationSweeper::class)->sweep())->toBe(1);
    expect($stale->fresh()?->status)->toBe(TakeUploadReservationStatus::Released);
});

test('未失効 pending / fresh verifying / completed は触られない', function (): void {
    $cut = Cut::factory()->create();
    $pending = TakeUploadReservation::factory()->forCut($cut)->create();
    $verifying = TakeUploadReservation::factory()->forCut($cut)->verifying()->create();
    $completed = TakeUploadReservation::factory()->forCut($cut)->completed()->create();
    $mock = mockSweeperStorage();
    $mock->shouldNotReceive('delete');

    expect(app(StaleUploadReservationSweeper::class)->sweep())->toBe(0);
    expect($pending->fresh()?->status)->toBe(TakeUploadReservationStatus::Pending);
    expect($verifying->fresh()?->status)->toBe(TakeUploadReservationStatus::Verifying);
    expect($completed->fresh()?->status)->toBe(TakeUploadReservationStatus::Completed);
});

test('stale verifying (updated_at が閾値超過) は released 化される', function (): void {
    $cut = Cut::factory()->create();
    $stale = TakeUploadReservation::factory()->forCut($cut)->verifying()->create();
    backdateReservation($stale, 20); // 閾値 15 分超過
    mockSweeperStorage();

    expect(app(StaleUploadReservationSweeper::class)->sweep())->toBe(1);
    expect($stale->fresh()?->status)->toBe(TakeUploadReservationStatus::Released);
});

test('retention 超過の released/completed 行は物理削除され、期限内の行は残る', function (): void {
    $cut = Cut::factory()->create();
    $oldReleased = TakeUploadReservation::factory()->forCut($cut)->released()->create();
    $oldCompleted = TakeUploadReservation::factory()->forCut($cut)->completed()->create();
    backdateReservation($oldReleased, 60 * 24 * 31); // 31 日前
    backdateReservation($oldCompleted, 60 * 24 * 31);
    $freshReleased = TakeUploadReservation::factory()->forCut($cut)->released()->create();
    mockSweeperStorage();

    app(StaleUploadReservationSweeper::class)->sweep();

    expect(TakeUploadReservation::query()->whereKey($oldReleased->id)->exists())->toBeFalse();
    expect(TakeUploadReservation::query()->whereKey($oldCompleted->id)->exists())->toBeFalse();
    expect(TakeUploadReservation::query()->whereKey($freshReleased->id)->exists())->toBeTrue();
});

test('冪等: 2 回目の sweep は何もしない', function (): void {
    $cut = Cut::factory()->create();
    TakeUploadReservation::factory()->forCut($cut)->expired()->create();
    mockSweeperStorage();

    expect(app(StaleUploadReservationSweeper::class)->sweep())->toBe(1);
    expect(app(StaleUploadReservationSweeper::class)->sweep())->toBe(0);
});

test('CAS 競合: 一覧取得後に completed 化された予約は released 上書き・削除されない', function (): void {
    $cut = Cut::factory()->create();
    $first = TakeUploadReservation::factory()->forCut($cut)->expired()->create();
    $second = TakeUploadReservation::factory()->forCut($cut)->expired()->create();

    $mock = Mockery::mock(TakeObjectStorage::class);
    // 1 件目の exists() 呼び出し中に 2 件目が登録処理に completed 化されるケースを再現
    $mock->shouldReceive('exists')->andReturnUsing(function () use ($second): bool {
        TakeUploadReservation::query()->whereKey($second->id)
            ->update(['status' => TakeUploadReservationStatus::Completed]);

        return true;
    });
    $deleted = [];
    $mock->shouldReceive('delete')->andReturnUsing(function (string $path) use (&$deleted): void {
        $deleted[] = $path;
    });
    app()->instance(TakeObjectStorage::class, $mock);

    $released = app(StaleUploadReservationSweeper::class)->sweep();

    expect($released)->toBe(1); // CAS 勝者は 1 件目のみ
    expect($first->fresh()?->status)->toBe(TakeUploadReservationStatus::Released);
    expect($second->fresh()?->status)->toBe(TakeUploadReservationStatus::Completed); // 上書きされない
    expect($deleted)->toBe([$first->video_path]); // completed のオブジェクトは削除されない
});

test('cron: capture:release-stale-upload-reservations コマンドが動き Schedule に登録されている', function (): void {
    mockSweeperStorage();

    Artisan::call('capture:release-stale-upload-reservations');
    expect(Artisan::output())->toContain('released 0 stale upload reservation(s)');

    $scheduled = collect(Schedule::events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'capture:release-stale-upload-reservations'));
    expect($scheduled)->toHaveCount(1);
});
