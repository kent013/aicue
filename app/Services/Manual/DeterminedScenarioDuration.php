<?php

declare(strict_types=1);

namespace App\Services\Manual;

use Webmozart\Assert\Assert;

/**
 * 「このシナリオで**いま尺が確定している分**は合わせて何 ms か、確定していないカットは何本か」。
 *
 * **完成動画の見込み尺ではない**。未撮影の動画カットの尺は v1 では原理的に出せないので
 * (ナレーション尺の推定を持たない = `DeterminedCutDuration` の docblock)、
 * ここが表すのは確定分の合計だけである。**未確定を 0 ms として足さない**。
 * 1 本も確定していなければ合計は `null` で、表示側は「—」を出す
 * (`resources/js/lib/manual/format-duration.ts` の `DURATION_UNKNOWN` と同じ思想。
 * 未確定を `0:00` と書くと「長さゼロの動画がある」という別の嘘になる)。
 *
 * **入力はカット 1 本ずつの確定尺の配列だけ**である (`Cut` も `Take` も受け取らない)。
 * したがって `adoptedTake` relation を読みようがなく、
 * `AdoptedTakeReferenceInventory` の登録は増えない。
 * 採用済みかつ ready のテイクの解決は呼び出し側 (`AdoptedReadyTakeCoverage` 経由) の責務である。
 */
final readonly class DeterminedScenarioDuration
{
    /**
     * @param  int|null  $totalDurationMs  確定分の合計 (ms)。1 本も確定していなければ null
     * @param  int  $undeterminedCutCount  尺が確定していないカット数
     */
    public function __construct(
        public ?int $totalDurationMs,
        public int $undeterminedCutCount,
    ) {}

    /**
     * @param  list<int|null>  $perCutDurationsMs  カットの表示順に並べた確定尺
     *                                             (`DeterminedCutDuration::milliseconds()` の戻り値)
     */
    public static function fromCutDurations(array $perCutDurationsMs): self
    {
        // array_sum() は使わない。整数加算が PHP_INT_MAX を超えると array_sum() は
        // float を返し得るため、readonly コンストラクタの int 契約と静的に矛盾しうる。
        // 1 パスの明示ループで加算前に範囲を検査し、クランプせず例外にする
        // (異常値を黙って変えない)。
        $total = 0;
        $undeterminedCount = 0;
        foreach ($perCutDurationsMs as $ms) {
            if ($ms === null) {
                $undeterminedCount++;

                continue;
            }

            Assert::greaterThanEq($ms, 0, 'カットの確定尺は負値になり得ない');
            Assert::lessThanEq($ms, PHP_INT_MAX - $total, 'カット尺の合計が PHP_INT_MAX を超える');
            $total += $ms;
        }

        $determinedCount = count($perCutDurationsMs) - $undeterminedCount;

        return new self(
            totalDurationMs: $determinedCount === 0 ? null : $total,
            undeterminedCutCount: $undeterminedCount,
        );
    }
}
