<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Dashboard;

use App\Enums\Manual\VideoManualStatus;

/**
 * 最近のマニュアル 1 行。TS 側 types/dashboard.ts の RecentManual と対で保守する。
 */
final readonly class RecentManualData
{
    public function __construct(
        public int $id,
        public string $title,
        public VideoManualStatus $status,
        public ?string $categoryName,
        public string $updatedAt,
    ) {}

    /**
     * @return array{id: int, title: string, status: string,
     *   category_name: string|null, updated_at: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status->value,
            'category_name' => $this->categoryName,
            'updated_at' => $this->updatedAt,
        ];
    }
}
