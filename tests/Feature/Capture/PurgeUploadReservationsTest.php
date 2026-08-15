<?php

declare(strict_types=1);

use App\Models\Cut;
use App\Models\TakeUploadReservation;
use Illuminate\Support\Facades\DB;

/*
 * アップロード予約の保持期間の決着 (capture:purge-upload-reservations)。
 * 滞留の回収 (work:recover-stuck --stream=upload_reservation) とは責務が違うため入口が別。
 */

/** updated_at をモデルイベントなしで過去に倒す */
function backdatePurgeCandidate(TakeUploadReservation $reservation, int $minutes): void
{
    DB::table('take_upload_reservations')
        ->where('id', $reservation->id)
        ->update(['updated_at' => now()->subMinutes($minutes)]);
}

test('retention 超過の released/completed 行は物理削除され、期限内の行は残る', function (): void {
    $cut = Cut::factory()->create();
    $oldReleased = TakeUploadReservation::factory()->forCut($cut)->released()->create();
    $oldCompleted = TakeUploadReservation::factory()->forCut($cut)->completed()->create();
    backdatePurgeCandidate($oldReleased, 60 * 24 * 31); // 31 日前
    backdatePurgeCandidate($oldCompleted, 60 * 24 * 31);
    $freshReleased = TakeUploadReservation::factory()->forCut($cut)->released()->create();

    $this->artisan('capture:purge-upload-reservations')
        ->expectsOutputToContain('purged 2 upload reservation(s)')
        ->assertSuccessful();

    expect(TakeUploadReservation::query()->whereKey($oldReleased->id)->exists())->toBeFalse();
    expect(TakeUploadReservation::query()->whereKey($oldCompleted->id)->exists())->toBeFalse();
    expect(TakeUploadReservation::query()->whereKey($freshReleased->id)->exists())->toBeTrue();
});

test('pending / verifying は保持期間を過ぎていても削除されない (回収の対象であって決着済みではない)', function (): void {
    $cut = Cut::factory()->create();
    $pending = TakeUploadReservation::factory()->forCut($cut)->create();
    $verifying = TakeUploadReservation::factory()->forCut($cut)->verifying()->create();
    backdatePurgeCandidate($pending, 60 * 24 * 31);
    backdatePurgeCandidate($verifying, 60 * 24 * 31);

    $this->artisan('capture:purge-upload-reservations')->assertSuccessful();

    expect(TakeUploadReservation::query()->whereKey($pending->id)->exists())->toBeTrue();
    expect(TakeUploadReservation::query()->whereKey($verifying->id)->exists())->toBeTrue();
});
