<?php

declare(strict_types=1);

namespace App\Services\Help;

/** 生成物 1 件の観測結果 (相対パスと状態の対)。 */
final readonly class HelpArtifactObservation
{
    /** @param non-empty-string $relativePath */
    public function __construct(
        public string $relativePath,
        public HelpArtifactState $state,
    ) {}
}
