<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Capture;

use App\Models\TakeUploadReservation;
use Webmozart\Assert\Assert;

/**
 * 署名チケットの封入 shape (doc/10 §10.8-7)。**検証専用** — cut/org の解決には決して使わない。
 * fromArray/toArray で Crypt 封入の直列化を型に閉じる (復号直後の未検証動的値の唯一の型境界)。
 */
final readonly class UploadTicketClaims
{
    /** payload 種別キー (ACK トークン等との相互流用を防ぐ) */
    public const string KIND = 'upload';

    public function __construct(
        public int $reservationId,
        public int $cutId,          // 一致検証のみ (route cut と比較)
        public string $clientTakeId,
        public int $sizeBytes,
        public string $contentType,
        public string $checksumSha256, // D2b (base64)
        public string $videoPath,
        public int $expiresAtTimestamp,
    ) {}

    public static function fromReservation(TakeUploadReservation $reservation): self
    {
        return new self(
            reservationId: $reservation->id,
            cutId: $reservation->cut_id,
            clientTakeId: $reservation->client_take_id,
            sizeBytes: $reservation->size_bytes,
            contentType: $reservation->content_type,
            checksumSha256: $reservation->checksum_sha256,
            videoPath: $reservation->video_path,
            expiresAtTimestamp: $reservation->expires_at->getTimestamp(),
        );
    }

    /**
     * @return array{k: 'upload', r: int, c: int, t: string, s: int, m: string, h: string, p: string, e: int}
     */
    public function toArray(): array
    {
        return [
            'k' => self::KIND,
            'r' => $this->reservationId,
            'c' => $this->cutId,
            't' => $this->clientTakeId,
            's' => $this->sizeBytes,
            'm' => $this->contentType,
            'h' => $this->checksumSha256,
            'p' => $this->videoPath,
            'e' => $this->expiresAtTimestamp,
        ];
    }

    /**
     * 復号直後の未検証動的値の唯一の型境界 (概念設計 D2): 各キーの存在・型・範囲を Assert 検証。
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        Assert::keyExists($payload, 'k');
        Assert::same($payload['k'], self::KIND, 'チケット種別が不正です');
        foreach (['r', 'c', 's', 'e'] as $key) {
            Assert::keyExists($payload, $key);
            Assert::integer($payload[$key]);
            Assert::greaterThan($payload[$key], 0);
        }
        foreach (['t', 'm', 'h', 'p'] as $key) {
            Assert::keyExists($payload, $key);
            Assert::stringNotEmpty($payload[$key]);
        }

        return new self(
            reservationId: $payload['r'],
            cutId: $payload['c'],
            clientTakeId: $payload['t'],
            sizeBytes: $payload['s'],
            contentType: $payload['m'],
            checksumSha256: $payload['h'],
            videoPath: $payload['p'],
            expiresAtTimestamp: $payload['e'],
        );
    }
}
