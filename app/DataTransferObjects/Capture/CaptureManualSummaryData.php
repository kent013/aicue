<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Capture;

use App\Models\VideoManual;
use Webmozart\Assert\Assert;

/**
 * 撮影一覧 (Capture/Index) の 1 行分。TS 側 types/capture.ts の CaptureManualSummary と対で保守。
 * 進捗カウント (cuts_total / cuts_adopted / cuts_with_takes) は withCount 済みモデルから読む。
 */
final readonly class CaptureManualSummaryData
{
    public function __construct(
        public int $id,
        public string $title,
        public string $status,
        public ?int $categoryId,
        public ?string $categoryName,
        public int $cutsTotal,
        public int $cutsAdopted,
        public int $cutsWithTakes,
        public ?string $updatedAt,
    ) {}

    /**
     * withCount('cuts', 'cuts as cuts_adopted_count', 'cuts as cuts_with_takes_count') +
     * with('category') 済みの manual から生成する (Capture/IndexController の一覧クエリと対)。
     */
    public static function fromManual(VideoManual $manual): self
    {
        $cutsTotal = $manual->getAttribute('cuts_count');
        $cutsAdopted = $manual->getAttribute('cuts_adopted_count');
        $cutsWithTakes = $manual->getAttribute('cuts_with_takes_count');
        Assert::integer($cutsTotal, 'withCount(cuts) 済みの manual を渡してください');
        Assert::integer($cutsAdopted, 'withCount(cuts as cuts_adopted_count) 済みの manual を渡してください');
        Assert::integer($cutsWithTakes, 'withCount(cuts as cuts_with_takes_count) 済みの manual を渡してください');

        return new self(
            id: $manual->id,
            title: $manual->title,
            status: $manual->status->value,
            categoryId: $manual->category?->id,
            categoryName: $manual->category?->name,
            cutsTotal: $cutsTotal,
            cutsAdopted: $cutsAdopted,
            cutsWithTakes: $cutsWithTakes,
            updatedAt: $manual->updated_at?->toIso8601String(),
        );
    }

    /**
     * @return array{id: int, title: string, status: string, category_id: int|null,
     *   category_name: string|null, cuts_total: int, cuts_adopted: int, cuts_with_takes: int,
     *   updated_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status,
            'category_id' => $this->categoryId,
            'category_name' => $this->categoryName,
            'cuts_total' => $this->cutsTotal,
            'cuts_adopted' => $this->cutsAdopted,
            'cuts_with_takes' => $this->cutsWithTakes,
            'updated_at' => $this->updatedAt,
        ];
    }
}
