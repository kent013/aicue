<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Capture;

/**
 * sync 応答 (未登録 fingerprint + サーバ状態スナップショット。概念設計 D8)。
 */
final readonly class CaptureSyncResultData
{
    /**
     * @param  list<ClientTakeFingerprint>  $pendingUpload
     */
    public function __construct(
        public array $pendingUpload,
        public CaptureManualDetailData $manual,
    ) {}

    /**
     * @return array{pending_upload: list<array{cut: int, client_take_id: string}>, manual: array{id: int, title: string, status: string, cuts: list<array<string, mixed>>}}
     */
    public function toArray(): array
    {
        return [
            'pending_upload' => array_map(
                static fn (ClientTakeFingerprint $fingerprint): array => $fingerprint->toArray(),
                $this->pendingUpload,
            ),
            'manual' => $this->manual->toArray(),
        ];
    }
}
