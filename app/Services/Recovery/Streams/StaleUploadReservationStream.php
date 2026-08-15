<?php

declare(strict_types=1);

namespace App\Services\Recovery\Streams;

use App\Contracts\Recovery\StuckWorkStream;
use App\Enums\Capture\TakeUploadReservationStatus;
use App\Enums\Recovery\RecoveryOutcome;
use App\Enums\Recovery\RecoveryStream;
use App\Models\TakeUploadReservation;
use App\Services\Capture\TakeObjectStorage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 撮影アップロードの滞留予約 (doc/10 §10.8-4)。回収対象は
 * (a) expires_at 超過の pending 予約、(b) stale な verifying 予約
 * (updated_at が閾値超過 = 登録リクエストの異常終了) で、released 化して bytes_pending を
 * 解放し、S3 に PUT 済みだが未登録のオブジェクトを削除する。
 * **fresh な verifying には触れない** (登録処理の claim 契約と競合しない)。
 *
 * 保持期間 (released/completed の古い行の物理削除) は**この回収には含まない**。
 * あれは滞留の前進ではなく期限の決着なので capture:purge-upload-reservations が持つ。
 */
final readonly class StaleUploadReservationStream implements StuckWorkStream
{
    /** S3 の存在確認・削除の入出力を有界にするための既存の上限。公平性は保証しない */
    private const int SWEEP_ITEM_LIMIT = 500;

    public function __construct(private TakeObjectStorage $storage) {}

    public function stream(): RecoveryStream
    {
        return RecoveryStream::UploadReservation;
    }

    public function candidateIds(CarbonImmutable $sweptAt, ?int $afterId, int $pageSize): array
    {
        /** @var list<positive-int> $ids */
        $ids = self::applyStalePredicate(TakeUploadReservation::query(), $sweptAt)
            ->when($afterId !== null, fn (Builder $query) => $query->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit($pageSize)
            ->pluck('id')
            ->all();

        return $ids;
    }

    public function recover(int $id, CarbonImmutable $sweptAt): RecoveryOutcome
    {
        $videoPath = $this->releaseIfStillStale($id, $sweptAt);
        if ($videoPath === null) {
            return RecoveryOutcome::Skipped; // 登録処理が勝った (completed) = 正当な Take の実体
        }

        try {
            if ($this->storage->exists($videoPath)) {
                $this->storage->delete($videoPath); // 未登録オブジェクトの孤児削除
            }
        } catch (Throwable $exception) {
            // 枠の解放は巻き戻さない (利用者の枠を人質にしない)。件数として観測できる形で残す
            report($exception);

            return RecoveryOutcome::RecoveredWithCleanupFailure;
        }

        return RecoveryOutcome::Recovered;
    }

    /** @return positive-int */
    public function sweepItemLimit(): int
    {
        return self::SWEEP_ITEM_LIMIT;
    }

    /**
     * 行ロック下で滞留の述語を再評価して解放し、削除対象のパスを返す (解放できなければ null)。
     *
     * **条件付き UPDATE ではなく行ロックにする** — 条件付き UPDATE だと「更新」と
     * 「パスの読み取り」で主キーのクエリが 2 本になる。行ロック + 述語の再評価なら 1 本で済み、
     * 他の 4 stream と同じ形にも揃う。直列化の効き方は条件付き UPDATE と同じ —
     * 登録処理側の verifying→completed の更新はこのロックが解けるまで待ち、解けた時点で
     * 述語が再評価されて 0 行になる (正当な Take を消さない)。
     *
     * **S3 の削除はコミット後**に行う (行ロックを保持したまま外部の入出力を待たない)。
     * id は候補列挙が返した主キーで HTTP 入力を経由しない (DirectFetchInventory に登録)。
     *
     * @param  positive-int  $id
     */
    private function releaseIfStillStale(int $id, CarbonImmutable $sweptAt): ?string
    {
        return DB::transaction(function () use ($id, $sweptAt): ?string {
            $locked = self::applyStalePredicate(TakeUploadReservation::query()->whereKey($id), $sweptAt)
                ->lockForUpdate()
                ->first();
            if ($locked === null) {
                return null; // 登録処理が勝った (completed) / 条件を満たさなくなった
            }

            $locked->forceFill(['status' => TakeUploadReservationStatus::Released])->save();

            return $locked->video_path;
        });
    }

    /**
     * 滞留の述語 (**この 1 か所だけが正本**)。候補列挙と行ロック下の再評価が同じ式を使う。
     *
     * @param  Builder<TakeUploadReservation>  $query
     * @return Builder<TakeUploadReservation>
     */
    private static function applyStalePredicate(Builder $query, CarbonImmutable $sweptAt): Builder
    {
        $cutoff = $sweptAt->subMinutes(config()->integer('capture.stale_verifying_minutes'));

        return $query->where(fn (Builder $outer) => $outer
            ->where(fn (Builder $pending) => $pending
                ->where('status', TakeUploadReservationStatus::Pending)
                ->where('expires_at', '<=', $sweptAt))
            ->orWhere(fn (Builder $verifying) => $verifying
                ->where('status', TakeUploadReservationStatus::Verifying)
                ->where('updated_at', '<', $cutoff)));
    }
}
