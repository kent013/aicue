<?php

declare(strict_types=1);

namespace App\Services\Billing\Retention;

use App\DataTransferObjects\Billing\BillingRetentionPurgeResultDto;
use App\Services\Billing\Contracts\BillingRetentionPurger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 物理削除で決着する purger の共通実装。
 *
 * 起算点 (`clockStartColumn`) と補助時計 (`anomalyClockColumn`) は目録 (enum) が正本で、
 * ここでは**自テーブルの列**であることを前提に機械的にクエリへ落とす。親テーブルの列を
 * 起算点にする子 target は {@see expiredQuery()} を override する。
 *
 * ★削除は**行単位**で行う (`$model->delete()`)。1 本の DELETE で消すと、1 行の失敗で
 *   バッチ全体が落ちて「1 件も消えない日」が続く。行単位なら失敗を件数として報告でき、
 *   残りは進む。行の取り出しは `chunkById` (削除しながらでも取りこぼさない)。
 *
 * @template TModel of Model
 */
abstract class AbstractBillingRetentionPurger implements BillingRetentionPurger
{
    /** 1 回の取り出し件数。 */
    private const int CHUNK_SIZE = 500;

    /** @return Builder<TModel> */
    abstract protected function baseQuery(): Builder;

    public function countExpired(CarbonImmutable $threshold): int
    {
        return $this->expiredQuery($threshold)->count();
    }

    public function countFailClosed(CarbonImmutable $threshold): int
    {
        $anomaly = $this->anomalyQuery($threshold);
        $blocked = $this->blockedQuery($threshold);

        return ($anomaly === null ? 0 : $anomaly->count())
            + ($blocked === null ? 0 : $blocked->count());
    }

    public function purgeExpired(CarbonImmutable $threshold): BillingRetentionPurgeResultDto
    {
        $candidates = $this->countExpired($threshold);
        $processed = 0;
        $unexpectedFailures = 0;

        $this->deletableQuery($threshold)->chunkById(
            self::CHUNK_SIZE,
            function (Collection $rows) use (&$processed, &$unexpectedFailures): void {
                foreach ($rows as $row) {
                    try {
                        $row->delete();
                        $processed++;
                    } catch (Throwable $e) {
                        $unexpectedFailures++;
                        // 例外 message は載せない (外部生成の可変文字列)。target と例外クラスだけ。
                        Log::warning('billing retention purge failed', [
                            'target' => $this->target()->value,
                            'error_class' => $e::class,
                        ]);
                    }
                }
            },
        );

        return new BillingRetentionPurgeResultDto(
            target: $this->target(),
            candidates: $candidates,
            processed: $processed,
            failClosed: $this->countFailClosed($threshold),
            unexpectedFailures: $unexpectedFailures,
            expiredRemaining: $this->countExpired($threshold),
        );
    }

    /**
     * 起算済み (起算列が非 null) かつ期限超過の行。
     *
     * @return Builder<TModel>
     */
    protected function expiredQuery(CarbonImmutable $threshold): Builder
    {
        $column = $this->target()->clockStartColumn();

        return $this->baseQuery()
            ->whereNotNull($column)
            ->where($column, '<=', $threshold);
    }

    /**
     * 起算列が null のまま補助時計が閾値より古い**異常**の行 (消さずに計上する)。
     *
     * 補助時計を持たない target は null を返す (= 異常検出をしない)。
     *
     * @return Builder<TModel>|null
     */
    protected function anomalyQuery(CarbonImmutable $threshold): ?Builder
    {
        $anomalyClock = $this->target()->anomalyClockColumn();
        if ($anomalyClock === null) {
            return null;
        }

        return $this->baseQuery()
            ->whereNull($this->target()->clockStartColumn())
            ->where($anomalyClock, '<=', $threshold);
    }

    /**
     * 期限超過だが他から参照されていて消せない行 (消さずに計上する)。
     *
     * 既定は「参照制約なし」。参照を持つ target は override する。
     *
     * @return Builder<TModel>|null
     */
    protected function blockedQuery(CarbonImmutable $threshold): ?Builder
    {
        return null;
    }

    /**
     * 実際に削除する行 (期限超過のうち参照中でないもの)。
     *
     * @return Builder<TModel>
     */
    protected function deletableQuery(CarbonImmutable $threshold): Builder
    {
        return $this->expiredQuery($threshold);
    }
}
