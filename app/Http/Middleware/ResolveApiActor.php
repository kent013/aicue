<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Context\ApiActorContext;
use App\Auth\Context\ApiActorKind;
use App\Auth\Context\ApiScopeSet;
use App\Enums\ApiErrorCode;
use App\Enums\OAuth\CliOAuthScope;
use App\Enums\OAuth\OAuthClientKind;
use App\Http\Resources\ApiErrorResource;
use App\Models\ApiKey;
use App\Models\OauthSession;
use App\Models\Organization;
use App\Models\User;
use App\Support\Api\ApiError;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\AccessToken;
use Symfony\Component\HttpFoundation\Response;
use Webmozart\Assert\Assert;

/**
 * REST API v1 の actor 解決 middleware (dual guard の後段)。
 *
 * 認証成立後 (`auth:api-key,api-oauth` / `auth:api-oauth` guard を通過) に、API キー経路 /
 * OAuth user-token 経路の双方を {@see ApiActorContext} に正規化し request attribute
 * `api_actor` へ staple する。actor 解決段の失敗 (cli:use 欠落 / session 不整合 /
 * 非メンバー / issuedBy null) は **すべて本 middleware が 401/403 で弾く**。
 * controller / ability middleware は解決済み context を読むだけで再判定しない。
 *
 * user-token 経路では下流互換のため request attribute `organization` も注入する
 * (API キー経路は ApiKeyGuard が注入済み。ResolvesApiOrganization / rate limiter が参照)。
 *
 * 順序契約: auth → throttle → resolve.api-actor → api-key.ability:X → (idempotent) → controller
 * (ApiGuardAllowlistInvariantTest が dual/oauth 分類ごと固定)。
 */
class ResolveApiActor
{
    public const ATTRIBUTE_KEY = 'api_actor';

    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->attributes->get('api_key');
        if ($apiKey instanceof ApiKey) {
            $context = $this->contextFromApiKey($apiKey);
            if ($context instanceof JsonResponse) {
                return $context;
            }
            $request->attributes->set(self::ATTRIBUTE_KEY, $context);

            return $this->forward($next, $request);
        }

        $user = $request->user('api-oauth');
        if ($user instanceof User) {
            $context = $this->contextFromUserToken($user);
            if ($context instanceof JsonResponse) {
                return $context;
            }
            $request->attributes->set(self::ATTRIBUTE_KEY, $context);
            // 下流互換 (ResolvesApiOrganization 等): API キー経路は guard が注入するのと同じ attribute
            $request->attributes->set('organization', $context->organization);

            return $this->forward($next, $request);
        }

        // defense-in-depth: guard を経た正常系では到達しない。
        return $this->unauthenticated();
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    private function forward(Closure $next, Request $request): Response
    {
        $response = $next($request);
        Assert::isInstanceOf($response, Response::class);

        return $response;
    }

    private function contextFromApiKey(ApiKey $apiKey): ApiActorContext|JsonResponse
    {
        $issuedBy = $apiKey->createdBy;
        if (! $issuedBy instanceof User) {
            // issuedBy 削除済 = actor (人間の帰属) を解決できない → 403。
            return $this->actorNotResolvable('The API key issuer is no longer available.');
        }

        $organization = $apiKey->organization;
        Assert::isInstanceOf($organization, Organization::class, 'ApiKey must belong to an Organization.');

        $abilities = array_values(array_filter(
            is_array($apiKey->abilities) ? $apiKey->abilities : [],
            static fn (mixed $a): bool => is_string($a),
        ));

        return new ApiActorContext(
            kind: ApiActorKind::ApiKey,
            user: $issuedBy,
            organization: $organization,
            scopes: new ApiScopeSet($abilities),
            oauthSessionId: null,
            apiKey: $apiKey,
        );
    }

    private function contextFromUserToken(User $user): ApiActorContext|JsonResponse
    {
        $accessToken = $user->token();
        if (! $accessToken instanceof AccessToken) {
            return $this->unauthenticated();
        }

        $scopes = $this->scopesFromToken($accessToken);
        if (! in_array(CliOAuthScope::CliUse->value, $scopes, true)) {
            return $this->unauthenticated('This token is not authorized for CLI access.');
        }

        $tokenId = (string) $accessToken->oauth_access_token_id;
        if ($tokenId === '') {
            return $this->unauthenticated();
        }

        /** @var object{organization_id: mixed, session_id: mixed}|null $row */
        $row = DB::table('oauth_access_tokens')
            ->where('id', $tokenId)
            ->first(['organization_id', 'session_id']);
        $organizationId = $this->normalizeId($row?->organization_id);
        if ($row === null || $organizationId === null) {
            return $this->unauthenticated('This token is not associated with an organization.');
        }
        if (! is_string($row->session_id) || $row->session_id === '') {
            return $this->unauthenticated('This token is not associated with a CLI session.');
        }

        $session = OauthSession::query()->find($row->session_id);
        if (
            $session === null
            || $session->isRevoked()
            || $session->client_kind !== OAuthClientKind::Cli->value
            || $session->user_id !== $user->id
            || $session->organization_id !== $organizationId
        ) {
            return $this->unauthenticated('The CLI session is no longer active.');
        }

        // membership は毎リクエスト再検証する (組織から外れた token を即時失効同等に扱う)。
        $organization = Organization::query()->find($organizationId);
        if ($organization === null || ! $user->isMemberOf($organization)) {
            return $this->unauthenticated('You are no longer a member of this organization.');
        }

        $session->touchLastUsedAt();

        return new ApiActorContext(
            kind: ApiActorKind::UserToken,
            user: $user,
            organization: $organization,
            scopes: new ApiScopeSet($scopes),
            oauthSessionId: $session->id,
            apiKey: null,
        );
    }

    /**
     * @param  AccessToken<mixed>  $accessToken
     * @return list<string>
     */
    private function scopesFromToken(AccessToken $accessToken): array
    {
        /** @var mixed $raw */
        $raw = $accessToken->oauth_scopes;
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(
            $raw,
            static fn (mixed $s): bool => is_string($s),
        ));
    }

    /**
     * DB driver 差 (int / 数値文字列) を吸収して id を int 化する。非数値は null。
     */
    private function normalizeId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    private function unauthenticated(?string $message = null): JsonResponse
    {
        return ApiErrorResource::make(ApiError::fromCode(
            ApiErrorCode::Unauthenticated,
            message: $message,
        ))->response()->setStatusCode(401);
    }

    private function actorNotResolvable(string $message): JsonResponse
    {
        return ApiErrorResource::make(ApiError::fromCode(
            ApiErrorCode::ActorNotResolvable,
            message: $message,
        ))->response()->setStatusCode(403);
    }
}
