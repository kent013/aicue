<?php

declare(strict_types=1);

namespace App\Console\Commands\Capture;

use App\Enums\Capture\TakeUploadReservationStatus;
use App\Models\TakeUploadReservation;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Webmozart\Assert\Assert;

/**
 * 解放済み / 登録済みのアップロード予約のうち、保持期間
 * (capture.released_reservation_retention_days) を超えた行を物理削除する。
 *
 * **これは滞留回収ではなく保持期間の決着**なので、回収 (work:recover-stuck) とは別の入口に
 * 分ける (既存の inquiry:purge / idempotency:prune と同じ位置付け)。物理削除は肥大の防止で
 * 緊急性が無いため日次で足りる。
 */
class PurgeUploadReservationsCommand extends Command
{
    /** @var string */
    protected $signature = 'capture:purge-upload-reservations';

    /** @var string */
    protected $description = '保持期間を過ぎたアップロード予約 (released / completed) を物理削除する';

    public function handle(): int
    {
        $cutoff = CarbonImmutable::now()
            ->subDays(config()->integer('capture.released_reservation_retention_days'));

        $deleted = TakeUploadReservation::query()
            ->whereIn('status', [TakeUploadReservationStatus::Released, TakeUploadReservationStatus::Completed])
            ->where('updated_at', '<', $cutoff)
            ->delete();
        Assert::integer($deleted, 'delete() は削除件数を返す');

        $this->info("purged {$deleted} upload reservation(s)");

        return self::SUCCESS;
    }
}
