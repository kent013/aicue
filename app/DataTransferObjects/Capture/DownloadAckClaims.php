<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Capture;

use Webmozart\Assert\Assert;

/**
 * DL 済み ACK トークンの封入 shape (概念設計 D6。検証専用)。
 * 詳細 GET が採用テイクの署名 DL URL と同時に発行し、POST .../downloaded が検証する。
 * UploadTicketClaims と同じ decoder 規約 (fromArray = Assert 全キー検証)。
 */
final readonly class DownloadAckClaims
{
    /** payload 種別キー (アップロードチケットとの相互流用を防ぐ) */
    public const string KIND = 'download-ack';

    public function __construct(
        public int $takeId,
        public int $userId,
        public int $expiresAtTimestamp,
    ) {}

    /**
     * @return array{k: 'download-ack', t: int, u: int, e: int}
     */
    public function toArray(): array
    {
        return [
            'k' => self::KIND,
            't' => $this->takeId,
            'u' => $this->userId,
            'e' => $this->expiresAtTimestamp,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        Assert::keyExists($payload, 'k');
        Assert::same($payload['k'], self::KIND, 'ACK トークン種別が不正です');
        foreach (['t', 'u', 'e'] as $key) {
            Assert::keyExists($payload, $key);
            Assert::integer($payload[$key]);
            Assert::greaterThan($payload[$key], 0);
        }

        return new self(
            takeId: $payload['t'],
            userId: $payload['u'],
            expiresAtTimestamp: $payload['e'],
        );
    }
}
