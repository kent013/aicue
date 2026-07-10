<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Capture;

use App\Models\Cut;
use App\Models\Take;

/**
 * 撮影 PWA へ返すカットの shape (takes 込み)。TS 側 types/capture.ts の CaptureCut と対で保守。
 * adopted_take_id の参照は読み取り直列化のみ (書き込み経路は CaptureTakeService に限定。
 * ScenarioWritePathInventoryTest 検出 4 が deny-by-default で固定する)。
 */
final readonly class CaptureCutData
{
    /**
     * @param  list<CaptureTakeData>  $takes
     */
    public function __construct(
        public Cut $cut,
        public array $takes,
    ) {}

    /**
     * takes は sort_order 順。採用テイクには playback URL / DL ACK トークンを付与できる
     * (詳細 GET のみ。null なら全テイク null = store/adopt 応答)。
     */
    public static function fromCut(Cut $cut, ?string $adoptedPlaybackUrl = null, ?string $adoptedAckToken = null): self
    {
        $adoptedTakeId = $cut->adopted_take_id;
        $takes = $cut->takes()->orderBy('sort_order')->orderBy('id')->get()
            ->map(static function (Take $take) use ($adoptedTakeId, $adoptedPlaybackUrl, $adoptedAckToken): CaptureTakeData {
                $isAdopted = $adoptedTakeId !== null && $take->id === $adoptedTakeId;

                return CaptureTakeData::fromTake(
                    $take,
                    playbackUrl: $isAdopted ? $adoptedPlaybackUrl : null,
                    downloadAckToken: $isAdopted ? $adoptedAckToken : null,
                );
            })
            ->all();

        return new self($cut, array_values($takes));
    }

    /**
     * @return array{id: int, type: string, parent_cut_id: int|null, scene: string,
     *   shot_type: string, shooting_point: string|null, narration: string,
     *   subtitle_primary: string|null, subtitle_secondary: string, adopted_take_id: int|null,
     *   takes: list<array{id: int, client_take_id: string, status: string, size_bytes: int,
     *     duration_ms: int|null, comment: string|null, captured_at: string|null, sort_order: int,
     *     downloaded: bool, playback_url: string|null, download_ack_token: string|null}>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->cut->id,
            'type' => $this->cut->type->value,
            'parent_cut_id' => $this->cut->parent_cut_id,
            'scene' => $this->cut->scene,
            'shot_type' => $this->cut->shot_type->value,
            'shooting_point' => $this->cut->shooting_point,
            'narration' => $this->cut->narration,
            'subtitle_primary' => $this->cut->subtitle_primary,
            'subtitle_secondary' => $this->cut->subtitle_secondary,
            'adopted_take_id' => $this->cut->adopted_take_id,
            'takes' => array_map(
                static fn (CaptureTakeData $take): array => $take->toArray(),
                $this->takes,
            ),
        ];
    }
}
