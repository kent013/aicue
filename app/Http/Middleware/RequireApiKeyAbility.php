<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Context\ApiActorContext;
use App\Enums\ApiErrorCode;
use App\Enums\ApiKeyAbility;
use App\Enums\OAuth\CliOAuthScope;
use App\Http\Resources\ApiErrorResource;
use App\Models\ApiKey;
use App\Support\Api\ApiError;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * actor の ability/scope gate (Q8 決定: flat ability)。
 *
 * routes/api.php での使い方 (順序契約: auth → throttle → resolve.api-actor → ability):
 *   Route::middleware(['auth:api-key,api-oauth', 'throttle:api-read',
 *       'resolve.api-actor', 'api-key.ability:read'])
 *
 * - resolve.api-actor の後段で動く前提 (request attributes に api_actor が必要)。
 *   actor 解決 (membership 再検証等) は前段が済ませており、ここでは再判定しない
 * - throttle の後段に置くことで、ability 不足のリクエストも throttle カウントに入る
 * - API キー actor は ApiKey::can() (従来と同値)。OAuth user-token actor は
 *   同じ string 値の scope ({@see CliOAuthScope}) の保有で判定する
 *
 * fail-secure:
 * - api_actor attribute が無い (順序ミス / 配線漏れ) → 401 + report
 * - route 宣言の ability が enum に無い (route 定義側のバグ) → 403 + report
 * - ability/scope 不足 → 403 insufficient_ability + details.required_ability
 */
class RequireApiKeyAbility
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $requiredAbility): Response
    {
        $routeName = $request->route()?->getName() ?? $request->path();

        $resolved = ApiKeyAbility::tryFrom($requiredAbility);
        if ($resolved === null) {
            // route 定義側のバグ。500 にせず fail-secure に 403 + report
            report(new \LogicException(
                "RequireApiKeyAbility: unknown ability '{$requiredAbility}' declared on route {$routeName}",
            ));

            return $this->makeForbidden($requiredAbility);
        }

        $actor = $request->attributes->get(ResolveApiActor::ATTRIBUTE_KEY);
        if (! $actor instanceof ApiActorContext) {
            // resolve.api-actor が前段に無い、または順序ミス
            report(new \LogicException(
                "RequireApiKeyAbility: api_actor attribute missing on route {$routeName}. "
                .'Ensure resolve.api-actor middleware runs first.',
            ));

            return $this->makeUnauthenticated();
        }

        if (! $this->actorAllows($actor, $resolved)) {
            return $this->makeForbidden($resolved->value);
        }

        return $next($request);
    }

    /**
     * actor がこの ability を許されるか (fail-closed)。
     *
     * API キー経路は ApiKey::can() (従来と完全同値)。OAuth 経路は同じ string 値の
     * scope 保有で判定する (membership は actor 解決段で毎リクエスト確定済み)。
     */
    private function actorAllows(ApiActorContext $actor, ApiKeyAbility $ability): bool
    {
        if ($actor->apiKey instanceof ApiKey) {
            return $actor->apiKey->can($ability->value);
        }

        return $actor->hasScope($ability->value);
    }

    private function makeForbidden(string $required): JsonResponse
    {
        return ApiErrorResource::make(ApiError::fromCode(
            ApiErrorCode::InsufficientAbility,
            message: "This API key does not have the '{$required}' ability.",
            details: ['required_ability' => $required],
        ))->response()->setStatusCode(403);
    }

    private function makeUnauthenticated(): JsonResponse
    {
        return ApiErrorResource::make(ApiError::fromCode(ApiErrorCode::Unauthenticated))
            ->response()->setStatusCode(401);
    }
}
