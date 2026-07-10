<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Capture;

/**
 * upload-url リクエストの検証済み入力 (Service は連想配列を受けない。概念設計 D12)。
 */
final readonly class TakeUploadInput
{
    public function __construct(
        public string $clientTakeId,     // 端末生成 ULID (26 桁)
        public int $sizeBytes,
        public string $contentType,
        public Sha256Checksum $checksum, // D2b: presign 署名で内容を固定
    ) {}
}
