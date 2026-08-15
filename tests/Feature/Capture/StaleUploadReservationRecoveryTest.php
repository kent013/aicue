<?php

declare(strict_types=1);

use App\Enums\Capture\TakeUploadReservationStatus;
use App\Enums\Recovery\RecoveryOutcome;
use App\Enums\Recovery\RecoveryStream;
use App\Models\Cut;
use App\Models\TakeUploadReservation;
use App\Services\Capture\TakeObjectStorage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Mockery\MockInterface;

/*
 * 孤児掃除の回収側 (work:recover-stuck --stream=upload_reservation --apply):
 * 期限切れ pending / stale verifying の released 化 + S3 孤児削除。
 * fresh verifying (検証中) には触れない。保持期間の物理削除は
 * capture:purge-upload-reservations の担当で本ファイルの範囲外
 * (PurgeUploadReservationsTest が持つ)。
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

/**
 * 滞留したアップロード予約を回収し、結果の種類ごとの件数を返す。
 *
 * @return array<value-of<RecoveryOutcome>, int>
 */
function recoverStaleUploadReservations(): array
{
    return sweepStuckWorkStream(RecoveryStream::UploadReservation)->outcomes;
}

test('期限切れ pending は released 化され、PUT 済みオブジェクトは削除される', function (): void {
    $cut = Cut::factory()->create();
    $stale = TakeUploadReservation::factory()->forCut($cut)->expired()->create();
    $mock = Mockery::mock(TakeObjectStorage::class);
    $mock->shouldReceive('exists')->once()->with($stale->video_path)->andReturnTrue();
    $mock->shouldReceive('delete')->once()->with($stale->video_path);
    app()->instance(TakeObjectStorage::class, $mock);

    expect(recoverStaleUploadReservations())->toBe([RecoveryOutcome::Recovered->value => 1]);
    expect($stale->fresh()?->status)->toBe(TakeUploadReservationStatus::Released);
});

test('PUT 未完了 (exists=false) の期限切れ pending は released のみで delete は呼ばれない', function (): void {
    $cut = Cut::factory()->create();
    $stale = TakeUploadReservation::factory()->forCut($cut)->expired()->create();
    $mock = Mockery::mock(TakeObjectStorage::class);
    $mock->shouldReceive('exists')->once()->andReturnFalse();
    $mock->shouldNotReceive('delete');
    app()->instance(TakeObjectStorage::class, $mock);

    expect(recoverStaleUploadReservations())->toBe([RecoveryOutcome::Recovered->value => 1]);
    expect($stale->fresh()?->status)->toBe(TakeUploadReservationStatus::Released);
});

test('未失効 pending / fresh verifying / completed は触られない', function (): void {
    $cut = Cut::factory()->create();
    $pending = TakeUploadReservation::factory()->forCut($cut)->create();
    $verifying = TakeUploadReservation::factory()->forCut($cut)->verifying()->create();
    $completed = TakeUploadReservation::factory()->forCut($cut)->completed()->create();
    $mock = mockSweeperStorage();
    $mock->shouldNotReceive('delete');

    expect(recoverStaleUploadReservations())->toBe([]);
    expect($pending->fresh()?->status)->toBe(TakeUploadReservationStatus::Pending);
    expect($verifying->fresh()?->status)->toBe(TakeUploadReservationStatus::Verifying);
    expect($completed->fresh()?->status)->toBe(TakeUploadReservationStatus::Completed);
});

test('stale verifying (updated_at が閾値超過) は released 化される', function (): void {
    $cut = Cut::factory()->create();
    $stale = TakeUploadReservation::factory()->forCut($cut)->verifying()->create();
    backdateReservation($stale, 20); // 閾値 15 分超過
    mockSweeperStorage();

    expect(recoverStaleUploadReservations())->toBe([RecoveryOutcome::Recovered->value => 1]);
    expect($stale->fresh()?->status)->toBe(TakeUploadReservationStatus::Released);
});

test('冪等: 2 回目の回収は何もしない', function (): void {
    $cut = Cut::factory()->create();
    TakeUploadReservation::factory()->forCut($cut)->expired()->create();
    mockSweeperStorage();

    expect(recoverStaleUploadReservations())->toBe([RecoveryOutcome::Recovered->value => 1]);
    expect(recoverStaleUploadReservations())->toBe([]);
});

test('競合: 候補列挙後に completed 化された予約は released 上書き・削除されない', function (): void {
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

    $counts = recoverStaleUploadReservations();

    expect($counts)->toBe([
        RecoveryOutcome::Recovered->value => 1,
        RecoveryOutcome::Skipped->value => 1,
    ]);
    expect($first->fresh()?->status)->toBe(TakeUploadReservationStatus::Released);
    expect($second->fresh()?->status)->toBe(TakeUploadReservationStatus::Completed); // 上書きされない
    expect($deleted)->toBe([$first->video_path]); // completed のオブジェクトは削除されない
});

test('S3 削除の失敗は掃引を止めず、行は released のまま cleanup 失敗として数えられる', function (): void {
    $cut = Cut::factory()->create();
    $stale = TakeUploadReservation::factory()->forCut($cut)->expired()->create();
    $mock = Mockery::mock(TakeObjectStorage::class);
    $mock->shouldReceive('exists')->andReturnTrue();
    $mock->shouldReceive('delete')->andThrow(new RuntimeException('S3 削除に失敗'));
    app()->instance(TakeObjectStorage::class, $mock);

    expect(recoverStaleUploadReservations())
        ->toBe([RecoveryOutcome::RecoveredWithCleanupFailure->value => 1]);
    expect($stale->fresh()?->status)->toBe(TakeUploadReservationStatus::Released); // 枠は解放したまま
});

test('解放とパスの取得は同じ行ロックの中で行われ、S3 削除はコミット後に走る', function (): void {
    $cut = Cut::factory()->create();
    TakeUploadReservation::factory()->forCut($cut)->expired()->create();

    // RefreshDatabase がテスト全体を 1 段のトランザクションで包むため、基準値を先に取る
    $baseline = DB::transactionLevel();

    $levelDuringExists = null;
    $mock = Mockery::mock(TakeObjectStorage::class);
    $mock->shouldReceive('exists')->andReturnUsing(function () use (&$levelDuringExists): bool {
        $levelDuringExists = DB::transactionLevel();

        return false;
    });
    $mock->shouldNotReceive('delete');
    app()->instance(TakeObjectStorage::class, $mock);

    recoverStaleUploadReservations();

    // 外部の入出力は解放のトランザクションの外 (行ロックを保持したまま待たない)
    expect($levelDuringExists)->toBe($baseline);
});

test('定期実行: 回収と保持期間の決着が別コマンドで 1 本ずつ Schedule に登録されている', function (): void {
    mockSweeperStorage();

    $this->artisan('work:recover-stuck --stream=upload_reservation --apply')
        ->expectsOutputToContain('upload_reservation: mode=apply candidates=0')
        ->assertSuccessful();

    $recovery = collect(Schedule::events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'work:recover-stuck --stream=upload_reservation'));
    expect($recovery)->toHaveCount(1);

    $purge = collect(Schedule::events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'capture:purge-upload-reservations'));
    expect($purge)->toHaveCount(1);
});

test('上限に達し後続候補が実在するとき limit-reached=yes が出力される', function (): void {
    $cut = Cut::factory()->create();
    TakeUploadReservation::factory()->count(2)->forCut($cut)->expired()->create();
    mockSweeperStorage(exists: false);

    // 系列の申告 (500) は実効上限の min() 側で --limit に置き換わる。
    // 上限に達したうえで**未処理の候補が実在する**ときだけ打ち切りとして出る
    Artisan::call('work:recover-stuck', [
        '--stream' => 'upload_reservation',
        '--apply' => true,
        '--limit' => '1',
    ]);

    $output = Artisan::output();
    expect($output)->toContain('candidates=1 recovered=1');
    expect($output)->toContain('limit-reached=yes');
});

test('候補がちょうど上限件数で尽きたときは limit-reached=no (打ち切りではない)', function (): void {
    $cut = Cut::factory()->create();
    TakeUploadReservation::factory()->forCut($cut)->expired()->create();
    mockSweeperStorage(exists: false);

    Artisan::call('work:recover-stuck', [
        '--stream' => 'upload_reservation',
        '--apply' => true,
        '--limit' => '1',
    ]);

    expect(Artisan::output())->toContain('limit-reached=no');
});
