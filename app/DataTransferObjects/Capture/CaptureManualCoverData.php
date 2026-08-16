<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Capture;

/**
 * 撮影一覧カードの代表サムネイル 1 枚を指す座標 (doc/05 §5.2 のサムネイル要件)。
 *
 * **2 つの別の行から合成する** (cut と take) ため、両方揃ったときだけ存在する形にしてある。
 * 片方だけ非 null という不正状態を型で表現できないようにするのが本 DTO の役目で、
 * 「代表が無い」は `CaptureManualSummaryData::$cover` が null であることで表す。
 *
 * URL は載せない。組み立て規則はフロント側の `lib/capture/take-endpoints.ts#takeUrl()` に
 * 1 本化されており (撮影 PWA と PC 編集面が共用する)、props に URL 文字列を持つと
 * 規則の置き場所が 2 つになる。署名 URL も載せない (取得は endpoint の 302 に限る)。
 */
final readonly class CaptureManualCoverData
{
    public function __construct(
        public int $cutId,
        public int $takeId,
    ) {}

    /**
     * @return array{cut_id: int, take_id: int}
     */
    public function toArray(): array
    {
        return [
            'cut_id' => $this->cutId,
            'take_id' => $this->takeId,
        ];
    }
}
