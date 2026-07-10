<?php

declare(strict_types=1);

namespace App\Services\Capture;

use App\DataTransferObjects\Capture\DownloadAckClaims;
use App\DataTransferObjects\Capture\UploadTicketClaims;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use JsonException;

/**
 * チケット / ACK トークンの封緘・開封 (doc/10 §10.8-7 / 概念設計 D6)。
 * Crypt::encryptString (AEAD = 改竄検出) を用い、復号失敗 (DecryptException)・
 * shape 不正・期限切れは null を返す (呼び出し側で 422 に変換)。
 * payload 種別キー (KIND) で upload チケットと download ACK の相互流用を防ぐ。
 */
class UploadTicketCodec
{
    public function seal(UploadTicketClaims $claims): string
    {
        return Crypt::encryptString(json_encode($claims->toArray(), JSON_THROW_ON_ERROR));
    }

    public function open(string $ticket): ?UploadTicketClaims
    {
        $payload = $this->decode($ticket);
        if ($payload === null) {
            return null;
        }

        try {
            $claims = UploadTicketClaims::fromArray($payload);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $claims->expiresAtTimestamp < now()->getTimestamp() ? null : $claims;
    }

    public function sealAck(DownloadAckClaims $claims): string
    {
        return Crypt::encryptString(json_encode($claims->toArray(), JSON_THROW_ON_ERROR));
    }

    public function openAck(string $token): ?DownloadAckClaims
    {
        $payload = $this->decode($token);
        if ($payload === null) {
            return null;
        }

        try {
            $claims = DownloadAckClaims::fromArray($payload);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $claims->expiresAtTimestamp < now()->getTimestamp() ? null : $claims;
    }

    /**
     * 復号 + JSON decode (失敗は null)。復号後 payload は未検証動的値として
     * 各 Claims::fromArray の Assert 検証にのみ渡す。
     *
     * @return array<array-key, mixed>|null
     */
    private function decode(string $sealed): ?array
    {
        try {
            $json = Crypt::decryptString($sealed);
        } catch (DecryptException) {
            return null;
        }

        try {
            $payload = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }
}
