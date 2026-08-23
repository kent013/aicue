<?php

declare(strict_types=1);

namespace Tests\Support\LegacyUrl;

/** 走査対象に確定した 1 ファイル (内容つき)。 */
final readonly class LegacyUrlScannedFile
{
    public function __construct(
        public string $relative,
        public string $contents,
        public LegacyUrlExtractionMode $mode,
        public string $ruleId,
    ) {}
}
