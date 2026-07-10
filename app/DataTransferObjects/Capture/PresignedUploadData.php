<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Capture;

use Carbon\CarbonImmutable;

/**
 * presigned PUT の発行結果 (概念設計 D11: 戻り値 DTO 固定)。
 */
final readonly class PresignedUploadData
{
    /**
     * @param  array<string, string>  $headers  クライアントが PUT に付ける署名対象ヘッダ
     */
    public function __construct(
        public string $url,
        public array $headers,
        public CarbonImmutable $expiresAt,
    ) {}
}
