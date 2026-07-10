<?php

declare(strict_types=1);

namespace App\Exceptions\Manual;

use App\Enums\Manual\ScenarioConflictType;
use App\Http\Resources\Manual\ScenarioConflictResource;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * シナリオ保存の競合 (409)。ScenarioService が投げ、render() が JsonResource 応答を返す
 * (`response()->json()` 直書き禁止の遵守。RequireRecentAuth の 409 契約と同じ
 * 「code 厳格一致」構造)。
 */
final class ScenarioConflictException extends Exception
{
    public function __construct(
        public readonly ScenarioConflictType $type,
        public readonly int $currentVersion,
    ) {
        parent::__construct($type->message());
    }

    public function render(Request $request): JsonResponse
    {
        return ScenarioConflictResource::make($this)
            ->response($request)
            ->setStatusCode(409);
    }
}
