<?php

declare(strict_types=1);

namespace App\Services\Recovery;

use App\Contracts\Recovery\StuckWorkStream;
use App\DataTransferObjects\Recovery\StreamSweepResultDto;
use App\Enums\Recovery\RecoveryOutcome;
use Carbon\CarbonImmutable;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * 滞留回収の掃引。**走査 (候補の列挙とページ送り) だけを持ち、作用は stream 側に置く**。
 *
 * 設計上の要点:
 * 1. **ページ送りであって総件数の上限ではない**。先頭に居座って毎回例外になる行があっても、
 *    カーソルが跨いで前進するので後続に手が届く (総件数の上限にすると新しい滞留を作る)
 * 2. **上限を適用するのは effectiveLimit の 1 箇所だけ**。stream 実装側は上限を知らない
 * 3. **limitReached は「未処理の候補が実在する」ときだけ true**。ちょうど上限件数で
 *    候補が尽きたケースを打ち切りと報告しない (運用の誤読を作らない)
 * 4. 実行しない指定 (dry-run) は recover() を 1 度も呼ばない。**回収されるはずの件数は出せない**
 *    (webhook の回収は受理そのものが書き込みのため)。出力には候補件数だけを出す
 * 5. 掃引側はトランザクションを張らない (行ロックと述語の再評価は各 stream の責務)
 */
final class StuckWorkRecoverySweeper
{
    /** 1 度に取り出す候補の件数 (メモリの上界であって、掃引全体の上限ではない) */
    private const int PAGE_SIZE = 200;

    /**
     * stream 1 本を掃引する。
     *
     * @param  bool  $apply  true で実際に回収する (false は候補を数えるだけ)
     * @param  positive-int|null  $limitOverride  手動実行の --limit
     */
    public function sweep(StuckWorkStream $stream, bool $apply, ?int $limitOverride = null): StreamSweepResultDto
    {
        $sweptAt = CarbonImmutable::now();
        $limit = $this->effectiveLimit($stream->sweepItemLimit(), $limitOverride);

        /** @var array<value-of<RecoveryOutcome>, int<0, max>> $outcomes */
        $outcomes = [];
        $candidates = 0;
        $failures = 0;
        $afterId = null;

        while ($limit === null || $candidates < $limit) {
            // 残り件数を型で positive-int に閉じる (level 10 は min() の結果を正に絞れない)
            $pageSize = $limit === null ? self::PAGE_SIZE : min(self::PAGE_SIZE, $limit - $candidates);
            Assert::positiveInteger($pageSize);

            // 契約違反 (要求より多く返す実装) があっても実効上限を超えないよう防御的に切る
            $ids = array_slice($stream->candidateIds($sweptAt, $afterId, $pageSize), 0, $pageSize);
            if ($ids === []) {
                // 候補が尽きた = 打ち切りではない
                return $this->result($stream, $apply, $candidates, $outcomes, $failures, limitReached: false);
            }

            foreach ($ids as $id) {
                $candidates++;
                $afterId = $id;
                if (! $apply) {
                    continue; // 実行しない指定は数えるだけ (recover を 1 度も呼ばない)
                }
                try {
                    $outcome = $stream->recover($id, $sweptAt);
                    $outcomes[$outcome->value] = ($outcomes[$outcome->value] ?? 0) + 1;
                } catch (Throwable $exception) {
                    // 1 件の失敗で掃引を止めない。ただし終了コードでは隠さない (呼び出し側が判定)
                    $failures++;
                    report($exception);
                }
            }
        }

        // 上限に達した。**未処理の候補が実在するときだけ**打ち切りとして記録する
        $hasMore = $stream->candidateIds($sweptAt, $afterId, 1) !== [];

        return $this->result($stream, $apply, $candidates, $outcomes, $failures, limitReached: $hasMore);
    }

    /** 実効上限 = min(--limit, stream の申告)。どちらも無指定なら無制限 */
    private function effectiveLimit(?int $streamLimit, ?int $override): ?int
    {
        return match (true) {
            $streamLimit === null => $override,
            $override === null => $streamLimit,
            default => min($streamLimit, $override),
        };
    }

    /** @param  array<value-of<RecoveryOutcome>, int<0, max>>  $outcomes */
    private function result(
        StuckWorkStream $stream,
        bool $apply,
        int $candidates,
        array $outcomes,
        int $failures,
        bool $limitReached,
    ): StreamSweepResultDto {
        return new StreamSweepResultDto(
            stream: $stream->stream(),
            applied: $apply,
            candidates: $candidates,
            outcomes: $outcomes,
            failures: $failures,
            limitReached: $limitReached,
        );
    }
}
