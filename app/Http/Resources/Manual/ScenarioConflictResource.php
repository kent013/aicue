<?php

declare(strict_types=1);

namespace App\Http\Resources\Manual;

use App\Exceptions\Manual\ScenarioConflictException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * シナリオ保存競合の 409 ボディ ({ code, conflict_type, message, current_version })。
 * code 厳格一致でクライアントが自分宛て応答のみ処理する (recent_auth_required と同方式)。
 * TS 側 types/manual.ts の ScenarioConflictBody と対で保守する。
 *
 * @property-read ScenarioConflictException $resource
 */
final class ScenarioConflictResource extends JsonResource
{
    /** 409 契約の判別子 (他の 409 契約との誤食防止) */
    public const string CODE = 'scenario_conflict';

    /** @var string|null */
    public static $wrap = null;

    /**
     * @return array{code: 'scenario_conflict', conflict_type: string, message: string, current_version: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => self::CODE,
            'conflict_type' => $this->resource->type->value,
            'message' => $this->resource->getMessage(),
            'current_version' => $this->resource->currentVersion,
        ];
    }
}
