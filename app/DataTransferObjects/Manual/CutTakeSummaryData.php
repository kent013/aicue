<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\Models\Cut;
use Webmozart\Assert\Assert;

/**
 * シナリオ編集画面「動画」列の 1 カット分。
 * TS 側 types/manual.ts の CutTakeSummary と対で保守する。
 *
 * 採用テイクは `adopted` キーで返す — 採用テイク外部キーの識別子は
 * ScenarioWritePathInventoryTest 検出 4 の deny-by-default 走査対象であり、
 * 表示のために security gate の allowlist を広げないための命名である。
 * 読み取りは adoptedTake relation 経由で行う。
 */
final readonly class CutTakeSummaryData
{
    public function __construct(
        public int $cutId,
        public int $takesCount,
        public ?int $adoptedId,
        public ?string $adoptedStatus,
        /**
         * 採用テイクのサムネイルが生成済みか。採用テイクが無いときは false。
         * 生成は非同期なので、録画直後・生成失敗・過去分は false になる。
         * true のときだけ画像 URL (capture.takes.thumbnail) を張る = 404 を踏まない。
         */
        public bool $adoptedHasThumbnail,
    ) {}

    /** withCount('takes') + with('adoptedTake') 済みの cut から生成する */
    public static function fromCut(Cut $cut): self
    {
        $takesCount = $cut->getAttribute('takes_count');
        Assert::integer($takesCount, 'withCount(takes) 済みの cut を渡してください');
        $adopted = $cut->adoptedTake;

        return new self(
            cutId: $cut->id,
            takesCount: $takesCount,
            adoptedId: $adopted?->id,
            adoptedStatus: $adopted?->status->value,
            // thumbnail_path は takes 表の列なので追加クエリは発生しない。
            // 採用テイクが無いときは null !== null = false へ落ちる (意味が一致する)
            adoptedHasThumbnail: $adopted?->thumbnail_path !== null,
        );
    }

    /**
     * @return array{cut_id: int, takes_count: int,
     *   adopted: array{id: int, status: string, has_thumbnail: bool}|null}
     */
    public function toArray(): array
    {
        return [
            'cut_id' => $this->cutId,
            'takes_count' => $this->takesCount,
            // id と status は同時に決まる (両方 null か両方非 null)
            'adopted' => $this->adoptedId === null || $this->adoptedStatus === null
                ? null
                : [
                    'id' => $this->adoptedId,
                    'status' => $this->adoptedStatus,
                    'has_thumbnail' => $this->adoptedHasThumbnail,
                ],
        ];
    }
}
