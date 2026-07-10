<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual\Analysis;

/**
 * 作業分解表の 1 行 (1 動作 1 No + 急所)。doc/03 §3.3。
 */
final readonly class WorkDecompositionStepData
{
    /** @param list<string> $points */
    public function __construct(
        public int $no,
        public string $action,
        public array $points,
    ) {}

    /**
     * @return array{no: int, action: string, points: list<string>}
     */
    public function toArray(): array
    {
        return [
            'no' => $this->no,
            'action' => $this->action,
            'points' => $this->points,
        ];
    }
}
