<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

/**
 * シナリオ document 一括保存の型付き入力 (expected_version + steps ツリー)。
 * validated() 配列の shape を UpdateScenarioRequest 内の 1 箇所で固定する。
 */
final readonly class ScenarioSaveInput
{
    /** @param list<ScenarioStepInput> $steps */
    public function __construct(
        public int $expectedVersion,
        public array $steps,
    ) {}
}
