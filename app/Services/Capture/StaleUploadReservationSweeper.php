<?php

declare(strict_types=1);

namespace App\Services\Capture;

use App\Enums\Capture\TakeUploadReservationStatus;
use App\Models\TakeUploadReservation;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * 孤児掃除 (doc/10 §10.8-4 / 概念設計 D7): 回収対象は
 * (a) expires_at 超過の pending 予約
 * (b) stale な verifying 予約 (updated_at が閾値超過 = 登録リクエストの異常終了)
 * を released 化し (bytes_pending 解放)、S3 に PUT 済みだが未登録のオブジェクトを削除する。
 * **fresh な verifying には触れない** (登録処理の claim 契約と競合しない)。
 * 加えて released/completed の古い行 (retention 超過) を物理削除する。冪等。
 */
class StaleUploadReservationSweeper
{
    /** 1 回の sweep が対象にする予約数の上限 (exists/delete の I/O 回数を抑える) */
    private const int BATCH_LIMIT = 500;

    public function __construct(
        private readonly TakeObjectStorage $storage,
    ) {}

    /** @return int released 化した予約数 */
    public function sweep(): int
    {
        // 時刻境界の一貫性: $now / $cutoff は冒頭で一度だけ生成し、一覧抽出と CAS 条件で共有する
        $now = now()->toImmutable();
        $cutoff = $now->subMinutes(config()->integer('capture.stale_verifying_minutes'));

        /** @var list<TakeUploadReservation> $stale */
        $stale = TakeUploadReservation::query()
            ->where(function (Builder $query) use ($now, $cutoff): void {
                $query->where(fn (Builder $q) => $q
                    ->where('status', TakeUploadReservationStatus::Pending)
                    ->where('expires_at', '<=', $now))
                    ->orWhere(fn (Builder $q) => $q
                        ->where('status', TakeUploadReservationStatus::Verifying)
                        ->where('updated_at', '<', $cutoff));
            })
            ->limit(self::BATCH_LIMIT)
            ->get()
            ->all();

        $released = 0;
        foreach ($stale as $reservation) {
            // CAS: 一覧取得後に登録処理が completed 化していたら 0 行更新 → 削除しない
            // (登録確定側の verifying→completed CAS と対。勝者だけが後続処理を行う)
            $won = TakeUploadReservation::query()
                ->whereKey($reservation->id)
                ->where(function (Builder $query) use ($reservation, $now, $cutoff): void {
                    $reservation->status === TakeUploadReservationStatus::Pending
                        ? $query->where('status', TakeUploadReservationStatus::Pending)
                            ->where('expires_at', '<=', $now)
                        : $query->where('status', TakeUploadReservationStatus::Verifying)
                            ->where('updated_at', '<', $cutoff);
                })
                ->update(['status' => TakeUploadReservationStatus::Released]);
            if ($won === 0) {
                continue; // 登録処理が勝った (completed) → オブジェクトは正当な Take の実体
            }
            $released++;
            if ($this->storage->exists($reservation->video_path)) {
                $this->storage->delete($reservation->video_path); // 未登録オブジェクトの孤児削除
            }
        }

        // released/completed の古い行の物理削除 (肥大防止。retention は config)
        TakeUploadReservation::query()
            ->whereIn('status', [TakeUploadReservationStatus::Released, TakeUploadReservationStatus::Completed])
            ->where('updated_at', '<', $now->subDays(config()->integer('capture.released_reservation_retention_days')))
            ->delete();

        return $released;
    }
}
