<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\Enums\Manual\ScenarioRuleCode;

/**
 * 規約検査 1 件の指摘 (code ごとに 1 つ)。件数は全件、位置は先頭 N 件のみ載せる
 * (N = ScenarioRuleCheck::MAX_POSITIONS_PER_CODE)。
 */
final readonly class ScenarioRuleFindingData
{
    /** @param list<array{step: int, point: int|null}> $positions 1 始まり。point=null は手順カット */
    public function __construct(
        public ScenarioRuleCode $code,
        public int $count,
        public array $positions,
    ) {}

    /** @return array{code: string, count: int, positions: list<array{step: int, point: int|null}>} */
    public function toArray(): array
    {
        return ['code' => $this->code->value, 'count' => $this->count, 'positions' => $this->positions];
    }
}
