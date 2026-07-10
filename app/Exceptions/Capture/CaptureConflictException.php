<?php

declare(strict_types=1);

namespace App\Exceptions\Capture;

use App\Enums\Capture\CaptureConflictType;
use App\Http\Resources\Capture\CaptureConflictResource;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * テイク登録の競合 (409)。TakeRegistrationService が投げ、render() が JsonResource 応答を返す
 * (`response()->json()` 直書き禁止の遵守。ScenarioConflictException と同じ
 * 「code 厳格一致」構造)。
 */
final class CaptureConflictException extends Exception
{
    public function __construct(
        public readonly CaptureConflictType $type,
    ) {
        parent::__construct($type->message());
    }

    public function render(Request $request): JsonResponse
    {
        return CaptureConflictResource::make($this)
            ->response($request)
            ->setStatusCode(409);
    }
}
