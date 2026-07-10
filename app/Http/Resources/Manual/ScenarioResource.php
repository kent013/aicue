<?php

declare(strict_types=1);

namespace App\Http\Resources\Manual;

use App\DataTransferObjects\Manual\ScenarioDocumentData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * シナリオ保存成功応答 ({ scenario_version, steps })。
 * edit 画面 props と同じ ScenarioDocumentData から生成し shape を一元化する
 * (クライアントは応答の確定 id を取り込み再編集を継続できる)。
 *
 * @property-read ScenarioDocumentData $resource
 */
final class ScenarioResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /**
     * @return array{scenario_version: int, steps: list<array{id: int, scene: string,
     *   shot_type: string, shooting_point: string|null, narration: string,
     *   subtitle_primary: string|null, subtitle_secondary: string, material_type: string|null,
     *   static_display_seconds: int|null, points: list<array{id: int, scene: string,
     *     shot_type: string, shooting_point: string|null, narration: string,
     *     subtitle_primary: string|null, subtitle_secondary: string, material_type: string|null,
     *     static_display_seconds: int|null}>}>}
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
