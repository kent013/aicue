<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\Models\Take;

/**
 * PC テイク選択画面が受け取るテイク 1 件の shape。
 * TS 側 types/manual.ts の SelectableTake と対で保守する。
 *
 * **署名 URL / 保存パスのスロットを構造として持たない**。
 * 撮影 PWA 用の CaptureTakeData は採用テイクへ署名 URL を載せる口を持つため、
 * 似ていても合流させない (概念設計 D2。「今は null だから安全」を作らない)。
 * 再生は capture.takes.playback (302 + no-store)、サムネイル取得は
 * capture.takes.thumbnail 経由のみである。
 */
final readonly class SelectableTakeData
{
    public function __construct(
        public Take $take,
    ) {}

    public static function fromTake(Take $take): self
    {
        return new self($take);
    }

    /**
     * @return array{id: int, status: string, size_bytes: int, duration_ms: int|null,
     *   comment: string|null, captured_at: string|null, sort_order: int, downloaded: bool,
     *   has_thumbnail: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->take->id,
            'status' => $this->take->status->value,
            'size_bytes' => $this->take->size_bytes,
            'duration_ms' => $this->take->duration_ms,
            'comment' => $this->take->comment,
            'captured_at' => $this->take->captured_at?->toIso8601String(),
            'sort_order' => $this->take->sort_order,
            // DL 済みテイクは削除できない (422)。理由を押下前に説明するために出す
            'downloaded' => $this->take->downloaded_at !== null,
            // サムネイル生成は非同期。true のときだけ画像 URL を張る (撮影 PWA と同じ判断)
            'has_thumbnail' => $this->take->thumbnail_path !== null,
        ];
    }
}
