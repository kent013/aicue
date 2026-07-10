<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Capture;

use InvalidArgumentException;

/**
 * SHA-256 チェックサム値オブジェクト (概念設計 D2b)。
 * base64 正当性 + デコード後 32 bytes を生成時保証する (presign 署名条件に入る値の型境界)。
 */
final readonly class Sha256Checksum
{
    private function __construct(public string $base64) {}

    public static function fromBase64(string $value): self
    {
        $decoded = base64_decode($value, strict: true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new InvalidArgumentException('SHA-256 チェックサム (base64) が不正です');
        }

        return new self($value);
    }
}
