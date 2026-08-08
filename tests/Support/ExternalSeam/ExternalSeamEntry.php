<?php

declare(strict_types=1);

namespace Tests\Support\ExternalSeam;

use App\Enums\Security\ExternalSeamClassification;
use App\Enums\Security\ExternalSeamKind;

/** 目録の 1 entry (値の器。判定ロジックを持たない)。 */
final readonly class ExternalSeamEntry
{
    /**
     * @param  class-string  $class
     * @param  string  $rationale  なぜこの到達が正当か (30 文字以上。gate が検査する)
     */
    public function __construct(
        public string $class,
        public ExternalSeamKind $kind,
        public ExternalSeamClassification $classification,
        public string $rationale,
    ) {}
}
