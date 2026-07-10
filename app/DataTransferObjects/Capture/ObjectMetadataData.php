<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Capture;

/**
 * HeadObject の照合対象メタデータ (概念設計 D2b の三点照合の入力)。
 */
final readonly class ObjectMetadataData
{
    public function __construct(
        public int $contentLength,
        public ?string $contentType,
        public ?string $checksumSha256, // HeadObject (ChecksumMode=ENABLED)。互換実装で欠落時 null
    ) {}
}
