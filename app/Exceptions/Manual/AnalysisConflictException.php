<?php

declare(strict_types=1);

namespace App\Exceptions\Manual;

use App\Enums\Manual\AnalysisConflictType;
use App\Http\Resources\Manual\AnalysisConflictResource;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AI 解析トリガーの競合 (409)。AnalysisJobService::trigger が投げ、render() が
 * JsonResource 応答を返す (`response()->json()` 直書き禁止の遵守。
 * ScenarioConflictException と同じ「code 厳格一致」構造)。
 */
final class AnalysisConflictException extends Exception
{
    public function __construct(
        public readonly AnalysisConflictType $type,
    ) {
        parent::__construct($type->message());
    }

    public function render(Request $request): JsonResponse
    {
        return AnalysisConflictResource::make($this)
            ->response($request)
            ->setStatusCode(409);
    }
}
