<?php

declare(strict_types=1);

namespace App\Exceptions\Manual;

use App\Enums\Manual\RenderConflictType;
use App\Http\Resources\Manual\RenderConflictResource;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * レンダ/プレビュートリガーの競合 (409)。RenderJobService::trigger / triggerPreview が投げ、
 * render() が JsonResource 応答を返す (`response()->json()` 直書き禁止の遵守。
 * AnalysisConflictException と同じ「code 厳格一致」構造)。
 */
final class RenderConflictException extends Exception
{
    public function __construct(
        public readonly RenderConflictType $type,
    ) {
        parent::__construct($type->message());
    }

    public function render(Request $request): JsonResponse
    {
        return RenderConflictResource::make($this)
            ->response($request)
            ->setStatusCode(409);
    }
}
