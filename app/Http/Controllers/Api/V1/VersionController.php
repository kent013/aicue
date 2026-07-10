<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\VersionResource;
use App\Services\VersionInfoService;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/version — 未認証の公開エンドポイント。CLI / クライアントが
 * 認証前に API 互換性 (capability negotiation) を確認するために使う
 * (throttle: api-status バケット)。payload の組み立ては VersionInfoService に集約。
 */
class VersionController extends Controller
{
    public function show(VersionInfoService $versionInfo): JsonResponse
    {
        return VersionResource::make($versionInfo->getVersionInfo())->response();
    }
}
