<?php

declare(strict_types=1);

namespace App\Services\Help;

/** 1 回の生成 / 検査の観測結果一式。 */
final readonly class HelpBuildReport
{
    /** @param list<HelpArtifactObservation> $observations */
    public function __construct(public array $observations) {}

    public function isClean(): bool
    {
        return $this->problems() === [];
    }

    /** @return list<HelpArtifactObservation> */
    public function problems(): array
    {
        return array_values(array_filter(
            $this->observations,
            static fn (HelpArtifactObservation $o): bool => $o->state !== HelpArtifactState::UpToDate,
        ));
    }
}
