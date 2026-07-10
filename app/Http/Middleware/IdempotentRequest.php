<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Context\ApiActorContext;
use App\Enums\ApiErrorCode;
use App\Http\Resources\ApiErrorResource;
use App\Models\ApiKey;
use App\Models\IdempotencyKey;
use App\Support\Api\ApiError;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Webmozart\Assert\Assert;

/**
 * Idempotency-Key middleware (REST API v1 の全 write エンドポイントに配線する)。
 *
 * - ヘッダ無し → 素通し (idempotency なし)
 * - 同一 key + 同一 request hash → 保存済みレスポンスを再生 (副作用は 1 回だけ)
 * - 同一 key + 異なる request hash → 409 idempotency_conflict
 * - 初回は controller 実行後、成功 (2xx JSON) レスポンスを保存する
 * - 保存行は TTL_HOURS で失効する (期限切れは未使用扱いで削除 → 再実行できる)
 *
 * スコープは actor 単位 × route: API キー actor は (api_key_id, route_name, key)、
 * OAuth user-token actor は (user_id, route_name, key)。同一 key でも別 route なら独立。
 *
 * 順序契約: auth → throttle → resolve.api-actor → api-key.ability → idempotent → controller
 * (api_actor attribute が前提。配線ミスは fail-closed で 500 + report)。
 */
class IdempotentRequest
{
    /** 保存レスポンスの TTL (時間)。超過エントリは再送時に削除して作り直す */
    public const TTL_HOURS = 24;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || trim($key) === '') {
            $response = $next($request);
            Assert::isInstanceOf($response, Response::class);

            return $response;
        }
        $key = trim($key);

        $actor = $request->attributes->get(ResolveApiActor::ATTRIBUTE_KEY);
        if (! $actor instanceof ApiActorContext) {
            // 配線ミス (resolve.api-actor middleware が前段に無い)。fail-closed で 500
            report(new \LogicException(
                'IdempotentRequest middleware reached without ApiActorContext attribute. '
                .'Ensure resolve.api-actor middleware runs first.',
            ));

            return ApiErrorResource::make(ApiError::fromCode(ApiErrorCode::InternalServerError))
                ->response()->setStatusCode(500);
        }

        $routeName = $request->route()?->getName() ?? $request->path();
        $requestHash = $this->hashRequest($request);

        $existing = $this->scopedQuery($actor)
            ->where('route_name', $routeName)
            ->where('key', $key)
            ->first();

        // TTL 超過エントリは未使用扱いで削除する (unique 制約を空けて再実行を許す)
        if ($existing !== null && $existing->isExpired()) {
            $existing->delete();
            $existing = null;
        }

        if ($existing !== null) {
            if ($existing->request_hash !== $requestHash) {
                return ApiErrorResource::make(ApiError::fromCode(ApiErrorCode::IdempotencyConflict))
                    ->response()->setStatusCode(409);
            }

            return $this->replayResponse($existing);
        }

        $response = $next($request);
        Assert::isInstanceOf($response, Response::class);

        // 成功 (2xx) の JSON レスポンスのみ保存する (失敗は保存しない = 再送で再実行できる)
        if ($response instanceof JsonResponse && $response->isSuccessful()) {
            $this->storeResponse($actor, $routeName, $key, $requestHash, $response);
        }

        return $response;
    }

    /**
     * actor 単位の保存行 lookup query (API キー actor = api_key_id、user-token actor = user_id)。
     *
     * @return Builder<IdempotencyKey>
     */
    private function scopedQuery(ApiActorContext $actor): Builder
    {
        if ($actor->apiKey instanceof ApiKey) {
            return IdempotencyKey::query()->where('api_key_id', $actor->apiKey->id);
        }

        return IdempotencyKey::query()
            ->whereNull('api_key_id')
            ->where('user_id', $actor->user->id);
    }

    /** メソッド + パス + body で同一リクエストかを判定する */
    private function hashRequest(Request $request): string
    {
        return hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());
    }

    private function replayResponse(IdempotencyKey $existing): JsonResponse
    {
        return (new JsonResponse($existing->response_body, $existing->response_status))
            ->header('Content-Type', 'application/json');
    }

    private function storeResponse(
        ApiActorContext $actor,
        string $routeName,
        string $key,
        string $requestHash,
        JsonResponse $response,
    ): void {
        $bodyJson = $response->getContent();
        $body = is_string($bodyJson) && $bodyJson !== '' && $bodyJson !== 'null'
            ? json_decode($bodyJson, true)
            : null;

        $row = new IdempotencyKey([
            'route_name' => $routeName,
            'key' => $key,
            'request_hash' => $requestHash,
            'response_status' => $response->getStatusCode(),
            'response_body' => is_array($body) ? $body : null,
            'expires_at' => now()->addHours(self::TTL_HOURS),
        ]);
        // 所有権キーは $fillable 外のため明示代入 (mass assignment 二層防御)
        $row->forceFill(
            $actor->apiKey instanceof ApiKey
                ? ['api_key_id' => $actor->apiKey->id]
                : ['user_id' => $actor->user->id],
        );

        try {
            $row->save();
        } catch (QueryException $e) {
            // 同時リクエストの unique 衝突は best-effort で無視する
            // (勝者の保存行が再送時の再生に使われる)
            report($e);
        }
    }
}
