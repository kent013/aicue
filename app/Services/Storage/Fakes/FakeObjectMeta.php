<?php

declare(strict_types=1);

namespace App\Services\Storage\Fakes;

/**
 * fake object の sidecar メタ (実 S3 が object metadata として持つ ContentType/Checksum の emulation)。
 * schema_version で将来の互換切りを可能にする。encode/decode は FakeObjectStore が担う。
 */
final readonly class FakeObjectMeta
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public string $contentType,
        public string $checksumSha256, // base64 sha256 (x-amz-checksum-sha256 と同形式)
    ) {}
}
