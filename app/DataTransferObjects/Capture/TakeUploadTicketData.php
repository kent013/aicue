<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Capture;

/**
 * upload-url 発行応答 (presigned PUT + 検証専用チケット)。TakeUploadTicketResource が直列化する。
 */
final readonly class TakeUploadTicketData
{
    public function __construct(
        public PresignedUploadData $presigned,
        public string $ticket,
        public string $clientTakeId,
    ) {}
}
