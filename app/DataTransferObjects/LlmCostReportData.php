<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Enums\LlmCostGroupBy;
use Carbon\CarbonImmutable;

/**
 * コストレポート全体 (集計軸 + クエリ条件 + 行 + TOTAL)。
 *
 * `toArray()` が機械可読出力の正本であり、public property の並びを外部契約にしない。
 */
final readonly class LlmCostReportData
{
    /**
     * @param  ?int  $afterId  「この実行分」を切り出した id 境界 (smoke 用)
     * @param  list<LlmCostRowData>  $rows
     * @param  LlmCostRowData  $total  key = 'TOTAL'
     */
    public function __construct(
        public LlmCostGroupBy $groupBy,
        public ?CarbonImmutable $since,
        public ?CarbonImmutable $until,
        public ?int $afterId,
        public array $rows,
        public LlmCostRowData $total,
    ) {}

    /**
     * @return array{
     *     group_by: string,
     *     since: string|null,
     *     until: string|null,
     *     after_id: int|null,
     *     rows: list<array<string, mixed>>,
     *     total: array<string, mixed>,
     * }
     */
    public function toArray(): array
    {
        return [
            'group_by' => $this->groupBy->value,
            'since' => $this->since?->toIso8601String(),
            'until' => $this->until?->toIso8601String(),
            'after_id' => $this->afterId,
            'rows' => array_map(static fn (LlmCostRowData $row): array => $row->toArray(), $this->rows),
            'total' => $this->total->toArray(),
        ];
    }
}
