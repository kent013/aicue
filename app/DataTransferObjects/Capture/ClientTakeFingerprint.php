<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Capture;

/**
 * 端末が保持するテイクの照合キー (cut id + client_take_id)。照合専用 (代入に使わない)。
 */
final readonly class ClientTakeFingerprint
{
    public function __construct(
        public int $cutId,
        public string $clientTakeId,
    ) {}

    /**
     * @return array{cut: int, client_take_id: string}
     */
    public function toArray(): array
    {
        return [
            'cut' => $this->cutId,
            'client_take_id' => $this->clientTakeId,
        ];
    }
}
