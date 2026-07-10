<?php

declare(strict_types=1);

namespace App\Http\Resources\Manual;

use App\Exceptions\Manual\AnalysisConflictException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * AI 解析トリガー競合の 409 ボディ ({ code, conflict_type, message })。
 * code 厳格一致でクライアントが自分宛て応答のみ処理する (scenario_conflict と同方式)。
 * TS 側 types/manual.ts の AnalysisConflictBody と対で保守する。
 *
 * @property-read AnalysisConflictException $resource
 */
final class AnalysisConflictResource extends JsonResource
{
    /** 409 契約の判別子 (他の 409 契約との誤食防止) */
    public const string CODE = 'analysis_conflict';

    /** @var string|null */
    public static $wrap = null;

    /**
     * @return array{code: 'analysis_conflict', conflict_type: string, message: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => self::CODE,
            'conflict_type' => $this->resource->type->value,
            'message' => $this->resource->getMessage(),
        ];
    }
}
