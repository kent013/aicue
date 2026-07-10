<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Me;

use App\DataTransferObjects\Api\SessionRevokedDto;
use App\Enums\OAuth\CliOAuthScope;
use App\Http\Controllers\Api\V1\Concerns\ReadsApiActor;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SessionRevokedResource;
use App\Models\OauthSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webmozart\Assert\Assert;

/**
 * `DELETE /api/v1/me/session` — CLI が自分の OAuth セッションを失効させる (logout のサーバ側)。
 *
 * `auth:api-oauth` **単独** guard (API キーにセッション概念は無い。API キー Bearer は
 * guard 段 401) + resolve.api-actor を経て到達する。失効対象は actor 自身の session のみ
 * (oauthSessionId と一致する 1 件)。失効は冪等 (再実行 / 既に消えた session でも 200)。
 * 失効後の token は次回の dual guard / actor 解決で 401 になる。
 */
class RevokeSessionController extends Controller
{
    use ReadsApiActor;

    public function destroy(Request $request): JsonResponse
    {
        $actor = $this->apiActor($request);

        // session.revoke scope を持たない token では失効させない (fail-closed)。
        abort_unless($actor->hasScope(CliOAuthScope::SessionRevoke->value), 403);

        $sessionId = $actor->oauthSessionId;

        // resolve.api-actor は user-token actor の session を必須にしているため null は到達不能。
        // 到達した場合は配線異常なので fail-secure: 500 (200 で握り潰さない)。
        Assert::notNull(
            $sessionId,
            'RevokeSessionController reached without a resolved oauth session id (wiring bug).',
        );

        $session = OauthSession::query()->find($sessionId);
        if ($session !== null) {
            $session->revoke();
        }

        return SessionRevokedResource::make(
            new SessionRevokedDto(sessionId: $sessionId, revoked: true),
        )->response();
    }
}
