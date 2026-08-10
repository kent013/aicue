<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

/**
 * 採用テイクの充足状況 (「採用済みかつ ready のテイクを持つカットが揃っているか」)。
 * render の 422 と、詳細画面の事前告知が**同じ値**を見るための唯一の shape。
 *
 * ★ props 用の toProps() はラベルを PROP_LABEL_LIMIT 件で打ち切るが、
 *   missingCount は**常に全件数**である (件数を打ち切ると嘘になる)。
 */
final readonly class TakeCoverageData
{
    /** props に載せるラベルの上限 (カット数が多い manual の全ラベルを毎描画で送らない) */
    public const int PROP_LABEL_LIMIT = 10;

    /**
     * @param  list<string>  $missingLabels  未充足カットの表示ラベル (CutSequencer の表示順)
     */
    public function __construct(
        public int $totalCuts,
        public array $missingLabels,
    ) {}

    public function missingCount(): int
    {
        return count($this->missingLabels);
    }

    /**
     * @return array{total_cuts: int, missing_count: int, missing_labels: list<string>}
     */
    public function toProps(): array
    {
        return [
            'total_cuts' => $this->totalCuts,
            'missing_count' => $this->missingCount(),
            'missing_labels' => array_slice($this->missingLabels, 0, self::PROP_LABEL_LIMIT),
        ];
    }
}
