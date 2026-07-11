<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Dashboard;

/**
 * 撮影対象 1 行 (採用待ち cut がある ready/published manual)。
 * TS 側 types/dashboard.ts の ShootingTarget と対で保守する。
 */
final readonly class ShootingTargetData
{
    public function __construct(
        public int $manualId,
        public string $title,
        public int $cutsCount,
        public int $pendingCutsCount, // 採用テイクなしの cut 数
    ) {}

    /**
     * @return array{manual_id: int, title: string, cuts_count: int, pending_cuts_count: int}
     */
    public function toArray(): array
    {
        return [
            'manual_id' => $this->manualId,
            'title' => $this->title,
            'cuts_count' => $this->cutsCount,
            'pending_cuts_count' => $this->pendingCutsCount,
        ];
    }
}
