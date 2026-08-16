<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Capture;

use App\Enums\Manual\TakeStatus;
use App\Models\Take;

/**
 * 撮影 PWA へ返すテイクの shape。TS 側 types/capture.ts の CaptureTake と対で保守する
 * (キー集合の契約は CaptureManualBrowsingTest が固定する)。
 *
 * playback_url / download_ack_token は**採用テイクの詳細 GET のみ**値を設定する
 * (CaptureManualDetailData が唯一の設定経路。store/update/adopt 応答では常に null)。
 */
final readonly class CaptureTakeData
{
    public function __construct(
        public Take $take,
        public ?string $playbackUrl = null,
        public ?string $downloadAckToken = null,
    ) {}

    public static function fromTake(Take $take, ?string $playbackUrl = null, ?string $downloadAckToken = null): self
    {
        return new self($take, $playbackUrl, $downloadAckToken);
    }

    /**
     * @return array{id: int, client_take_id: string, status: string, size_bytes: int,
     *   duration_ms: int|null, comment: string|null, captured_at: string|null, sort_order: int,
     *   downloaded: bool, has_thumbnail: bool, playback_url: string|null,
     *   download_ack_token: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->take->id,
            'client_take_id' => $this->take->client_take_id,
            'status' => $this->take->status->value,
            'size_bytes' => $this->take->size_bytes,
            'duration_ms' => $this->take->duration_ms,
            'comment' => $this->take->comment,
            'captured_at' => $this->take->captured_at?->toIso8601String(),
            'sort_order' => $this->take->sort_order,
            'downloaded' => $this->take->downloaded_at !== null,
            // サムネイルの**有無だけ**を出す (パスも署名 URL も出さない)。UI はこの 1 つで
            // <img> とプレースホルダを出し分け、未生成テイクへ 404 のリクエストを出さない。
            // ★ 述語は **GET .../thumbnail が 302 を返す条件と 1 対 1** にする
            //   (ready でないテイクで true を返すと、必ず 404 になる <img> を描画してしまう)。
            //   T154 の「秘匿境界は props 側」「props と endpoint は 1 対 1」と同じ作法
            'has_thumbnail' => $this->take->status === TakeStatus::Ready
                && $this->take->thumbnail_path !== null,
            'playback_url' => $this->playbackUrl,
            'download_ack_token' => $this->downloadAckToken,
        ];
    }
}
