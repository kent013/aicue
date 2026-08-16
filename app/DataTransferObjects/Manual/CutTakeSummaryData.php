<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\Enums\Manual\MaterialType;
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
 *
 * **素材登録状況 (doc/02 §2.4 の 3 値) の材料をここで出す**。
 * 判定に使うのは「採用テイクが在るか」と「その material_type」の 2 つだけで、
 * **ready 判定 (AdoptedReadyTakeCoverage の述語) は再実装しない** (ドメイン固有規約 12)。
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
        /** 採用テイクの**実体**種別 (NOT NULL)。採用テイクが無いときは null */
        public ?MaterialType $adoptedMaterialType,
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
            adoptedMaterialType: $adopted?->material_type,
        );
    }

    /**
     * @return array{cut_id: int, takes_count: int,
     *   adopted: array{id: int, status: string, has_thumbnail: bool, material_type: string}|null}
     */
    public function toArray(): array
    {
        // id / status / material_type は同時に決まる (すべて null か、すべて非 null)
        if ($this->adoptedId === null || $this->adoptedStatus === null || $this->adoptedMaterialType === null) {
            return ['cut_id' => $this->cutId, 'takes_count' => $this->takesCount, 'adopted' => null];
        }

        return [
            'cut_id' => $this->cutId,
            'takes_count' => $this->takesCount,
            'adopted' => [
                'id' => $this->adoptedId,
                'status' => $this->adoptedStatus,
                'has_thumbnail' => $this->adoptedHasThumbnail,
                'material_type' => $this->adoptedMaterialType->value,
            ],
        ];
    }
}
