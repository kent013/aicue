<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Capture;

use App\Models\VideoManual;
use Webmozart\Assert\Assert;

/**
 * 撮影一覧 (Capture/Index) の 1 行分。TS 側 types/capture.ts の CaptureManualSummary と対で保守。
 * 進捗カウント (cuts_total / cuts_adopted / cuts_with_takes) は withCount 済みモデルから読む。
 * creator は表示目的のみ (検索対象外)。User.name は CipherSweet PII のため whereBlind 検索の
 * 対象にはしない (自作フィルタは created_by の id 一致で行う)。
 *
 * **制作状態 (video_manuals.status) は載せない** (T197)。撮影 PWA が出す進捗バッジは
 * カットの採用状況から導出する別の量 (types/capture.ts の captureProgressOf) であり、
 * 制作状態は表示にも分岐にも使われていなかったため。撮影対象の母集団を
 * ready / published に絞るのは CaptureManualController の責務で、こちらは変えていない。
 */
final readonly class CaptureManualSummaryData
{
    public function __construct(
        public int $id,
        public string $title,
        public ?int $categoryId,
        public ?string $categoryName,
        public int $cutsTotal,
        public int $cutsAdopted,
        public int $cutsWithTakes,
        public ?string $updatedAt,
        public ?string $creatorName,
    ) {}

    /**
     * withCount('cuts', 'cuts as cuts_adopted_count', 'cuts as cuts_with_takes_count') +
     * with('category', 'creator') 済みの manual から生成する (Capture/IndexController の一覧クエリと対)。
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
            categoryId: $manual->category?->id,
            categoryName: $manual->category?->name,
            cutsTotal: $cutsTotal,
            cutsAdopted: $cutsAdopted,
            cutsWithTakes: $cutsWithTakes,
            updatedAt: $manual->updated_at?->toIso8601String(),
            creatorName: $manual->creator?->name, // 退会/削除で null (実運用では FK RESTRICT)
        );
    }

    /**
     * @return array{id: int, title: string, category_id: int|null,
     *   category_name: string|null, cuts_total: int, cuts_adopted: int, cuts_with_takes: int,
     *   updated_at: string|null, creator_name: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'category_id' => $this->categoryId,
            'category_name' => $this->categoryName,
            'cuts_total' => $this->cutsTotal,
            'cuts_adopted' => $this->cutsAdopted,
            'cuts_with_takes' => $this->cutsWithTakes,
            'updated_at' => $this->updatedAt,
            'creator_name' => $this->creatorName,
        ];
    }
}
