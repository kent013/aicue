<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DataTransferObjects\Api\MeDto;
use App\Http\Controllers\Api\V1\Concerns\ReadsApiActor;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/me (whoami) — 認証済み actor の組織・権限メタ情報を返す。
 * トークン検証だけしたいヘルスチェック / CLI whoami 用。
 *
 * dual guard 対応: API キー actor は api_key セクション、OAuth user-token actor は
 * session セクションを出し分ける (MeDto::fromActor)。
 */
class MeController extends Controller
{
    use ReadsApiActor;

    public function show(Request $request): JsonResponse
    {
        return MeResource::make(MeDto::fromActor($this->apiActor($request)))->response();
    }
}
