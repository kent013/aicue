<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Context\ApiActorContext;
use App\Enums\ApiErrorCode;
use App\Enums\Idempotency\IdempotencyClaimStatus;
use App\Enums\Idempotency\IdempotencyState;
use App\Exceptions\Idempotency\IdempotencyFinalizationFailure;
use App\Http\Resources\ApiErrorResource;
use App\Models\ApiKey;
use App\Models\IdempotencyKey;
use App\Support\Api\ApiError;
use App\Support\Idempotency\IdempotencyClaimOutcome;
use App\Support\Idempotency\IdempotencyHeaders;
use App\Support\Idempotency\IdempotencyRetention;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * Idempotency-Key middleware (REST API v1 の全 write エンドポイントに配線する)。
 *
 * **実行前 claim 方式**。本処理より先に `state = processing` の行を
 * `insertOrIgnore` で確保し、既存の unique 2 本 (api_key_id / user_id) を**唯一の調停者**に
 * する (cache ロック等の best-effort な二重機構は使わない)。決着は
 * `completed` / `indeterminate` の 2 つだけで、**release (再実行を許す) 経路は持たない**。
 *
 * | 状況 | 応答 |
 * |------|------|
 * | ヘッダ無し | 素通し (冪等行を作らない) |
 * | キーが 255 文字超 | 422 validation_failed (DB に触る前に弾く) |
 * | 初回 (claim 成功) | 本処理を実行。2xx JSON なら completed、それ以外は indeterminate |
 * | 同一キー + 同一 body + completed | 保存応答を再生 (`Idempotent-Replayed: true`) |
 * | 同一キー + 異なる body | 409 idempotency_conflict |
 * | 同一キー + processing | 409 idempotency_in_progress (本処理を実行しない) |
 * | 同一キー + indeterminate | 409 idempotency_indeterminate (本処理を実行しない) |
 *
 * ⚠ **契約変更 (破壊的)**: 4xx / 5xx で終わった要求の後、**同じキーは再利用できない**
 * (以前は再実行できた)。middleware は controller が副作用の前後どちらで失敗したかを
 * 知らないため、再実行せず新しいキーを要求する。契約の正本は `docs/api-idempotency.md`。
 *
 * スコープは actor 単位 × route: API キー actor は (api_key_id, route_name, key)、
 * OAuth user-token actor は (user_id, route_name, key)。同一 key でも別 route なら独立。
 * 保持期間は `config/idempotency.php` が唯一の正本 (クラス定数を復活させない)。
 *
 * 順序契約: auth → throttle → resolve.api-actor → api.project-in-org
 * → api-key.ability → idempotent → controller
 * (api_actor attribute が前提。配線ミスは fail-closed で 500 + report)。
 * **terminable にしない** (finalize は同一リクエストの応答確定前に完了させる)。
 */
class IdempotentRequest
{
    /** `idempotency_keys.key` は varchar(255)。DB に触る前にここで弾く */
    private const MAX_KEY_LENGTH = 255;

    /** claim の再試行回数 (期限切れ行の削除と再 claim の競合ぶん) */
    private const CLAIM_ATTEMPTS = 2;

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

        // キー長の検証。`key` 列は varchar(255) のため、255 超のヘッダをそのまま claim すると
        // INSERT が 22001 で落ち、本処理を実行しないまま 500 になる。
        // DB に触る前に 422 で弾き、副作用も冪等行も作らない。
        if (mb_strlen($key) > self::MAX_KEY_LENGTH) {
            return ApiErrorResource::make(ApiError::fromCode(
                ApiErrorCode::ValidationFailed,
                details: ['errors' => ['Idempotency-Key' => [
                    'The Idempotency-Key header must not be longer than '.self::MAX_KEY_LENGTH.' characters.',
                ]]],
            ))->response()->setStatusCode(422);
        }

        $actor = $request->attributes->get(ResolveApiActor::ATTRIBUTE_KEY);
        if (! $actor instanceof ApiActorContext) {
            // 配線ミス (resolve.api-actor middleware が前段に無い)。fail-closed で 500
            report(new LogicException(
                'IdempotentRequest middleware reached without ApiActorContext attribute. '
                .'Ensure resolve.api-actor middleware runs first.',
            ));

            return ApiErrorResource::make(ApiError::fromCode(ApiErrorCode::InternalServerError))
                ->response()->setStatusCode(500);
        }

        $routeName = $request->route()?->getName() ?? $request->path();
        $requestHash = $this->hashRequest($request);

        $outcome = $this->claim($actor, $routeName, $key, $requestHash);

        return match ($outcome->status) {
            IdempotencyClaimStatus::Claimed => $this->runAndFinalize($request, $next, $actor, $routeName, $key),
            IdempotencyClaimStatus::Replay => $this->replayResponse($outcome->rowOrFail()),
            IdempotencyClaimStatus::Conflict => $this->errorResponse(ApiErrorCode::IdempotencyConflict),
            IdempotencyClaimStatus::InProgress => $this->errorResponse(ApiErrorCode::IdempotencyInProgress),
            IdempotencyClaimStatus::Indeterminate => $this->errorResponse(ApiErrorCode::IdempotencyIndeterminate),
        };
    }

    /**
     * 実行**前**の claim。unique 制約が唯一の調停者で、cache ロック等の補助機構は使わない。
     *
     * 期限切れ行との競合があるため最大 2 回試行する。2 回とも決着しない場合は
     * **fail-closed** (本処理を実行せず 409 in_progress) にする。
     */
    private function claim(
        ApiActorContext $actor,
        string $routeName,
        string $key,
        string $requestHash,
    ): IdempotencyClaimOutcome {
        for ($attempt = 0; $attempt < self::CLAIM_ATTEMPTS; $attempt++) {
            $now = CarbonImmutable::now();

            // insertOrIgnore: pgsql では `insert ... on conflict do nothing`。
            // 例外を投げないため、外側のトランザクションを巻き込まない。
            $inserted = IdempotencyKey::query()->insertOrIgnore([
                ...$this->ownershipColumns($actor),
                'route_name' => $routeName,
                'key' => $key,
                'request_hash' => $requestHash,
                'state' => IdempotencyState::Processing->value,
                'response_status' => null,
                'response_body' => null,
                'expires_at' => IdempotencyRetention::expiresAt($now),
                // query builder insert は timestamps を自動付与しないので明示する
                'created_at' => $now,
            ]);

            if ($inserted === 1) {
                return IdempotencyClaimOutcome::claimed();
            }

            $existing = $this->rowQuery($actor, $routeName, $key)->first();
            if ($existing === null) {
                continue; // 別リクエストが期限切れ行を消した直後。もう 1 回だけ試す
            }

            if ($existing->isExpired($now)) {
                // 期限切れ行の削除は **同一スコープ + expires_at 条件付き**で行う
                // (主キー同一性クエリを書かない = ModelDirectFetchInvariantTest の母集団に入らない。
                //  同時に、削除と削除の間に作られた新しい行を巻き込まない)
                $this->rowQuery($actor, $routeName, $key)
                    ->where('expires_at', '<=', $now)
                    ->delete();

                continue;
            }

            if ($existing->request_hash !== $requestHash) {
                return IdempotencyClaimOutcome::conflict($existing);
            }

            return match ($existing->state) {
                IdempotencyState::Processing => IdempotencyClaimOutcome::inProgress($existing),
                IdempotencyState::Completed => IdempotencyClaimOutcome::replay($existing),
                IdempotencyState::Indeterminate => IdempotencyClaimOutcome::indeterminate($existing),
            };
        }

        // 2 回とも決着しなかった = 期限切れ削除と再 claim が競り続けている。
        // ここで本処理を走らせると二重実行になりうるので実行しない (fail-closed)。
        return IdempotencyClaimOutcome::inProgress(new IdempotencyKey);
    }

    /**
     * 本処理を実行し、結果を確定する。
     *
     * - 2xx JsonResponse → completed (応答を保存)
     * - それ以外 / 例外 → indeterminate (release 経路は持たない)
     *
     * @param  Closure(Request): Response  $next
     */
    private function runAndFinalize(
        Request $request,
        Closure $next,
        ApiActorContext $actor,
        string $routeName,
        string $key,
    ): Response {
        $logRouteName = $this->loggableRouteName($request);

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            // 例外が middleware まで抜けた = 決着不明。indeterminate に倒してから再送出する
            $this->finalize(
                $actor,
                $routeName,
                $logRouteName,
                $key,
                IdempotencyState::Indeterminate,
                causeClass: $e::class,
            );

            throw $e;
        }

        Assert::isInstanceOf($response, Response::class);

        if ($response instanceof JsonResponse && $response->isSuccessful()) {
            $this->finalize($actor, $routeName, $logRouteName, $key, IdempotencyState::Completed, $response);
        } else {
            $this->finalize($actor, $routeName, $logRouteName, $key, IdempotencyState::Indeterminate);
        }

        return $response;
    }

    /**
     * claim 行の確定 (state = processing の条件付き UPDATE)。
     *
     * **失敗しても応答は壊さない**。副作用は既に確定しており、ここで 500 に化けさせると
     * クライアントに「失敗した」と誤認させ、より悪い再送を誘発する。
     * 代わりに観測専用例外を report() する (載せる情報は 5 項目のみ)。
     *
     * @param  string  $routeName  行のスコープに使う (path fallback を含む)
     * @param  string  $logRouteName  ログに載せる識別子 (route parameter の実値を含まない)
     */
    private function finalize(
        ApiActorContext $actor,
        string $routeName,
        string $logRouteName,
        string $key,
        IdempotencyState $state,
        ?JsonResponse $response = null,
        ?string $causeClass = null,
    ): void {
        /** @var array<string, mixed> $payload */
        $payload = ['state' => $state->value];
        if ($response instanceof JsonResponse) {
            $body = $this->decodeBody($response);
            $payload['response_status'] = $response->getStatusCode();
            // `Builder::update()` は **model の cast を通さない** (toBase()->update() へ素通し)
            // ため、json 列へ入れる文字列をここで明示的に組み立てる
            // (`response_body` は null が正当な保存値)。
            //
            // ⚠ 誇張しない: pgsql では `PostgresGrammar::prepareBindingsForUpdate()` が
            //   配列を自動で json_encode するため、**この行を外しても pgsql では壊れない**
            //   (T139 の mutation 24 で実測。赤くならなかった)。明示エンコードを残すのは
            //   driver 非依存にすることと `JSON_THROW_ON_ERROR` で失敗を握り潰さないためで、
            //   「これが無いと落ちる」という主張ではない。
            $payload['response_body'] = $body === null
                ? null
                : json_encode($body, JSON_THROW_ON_ERROR);
        }

        try {
            $affected = $this->rowQuery($actor, $routeName, $key)
                ->where('state', IdempotencyState::Processing->value)
                ->update($payload);
        } catch (Throwable $e) {
            report(IdempotencyFinalizationFailure::make(
                routeName: $logRouteName,
                actorKind: $this->actorKind($actor),
                expectedState: $state->value,
                affectedRows: -1,
                causeClass: $e::class,
            ));

            return;
        }

        if ($affected !== 1) {
            report(IdempotencyFinalizationFailure::make(
                routeName: $logRouteName,
                actorKind: $this->actorKind($actor),
                expectedState: $state->value,
                affectedRows: $affected,
                causeClass: $causeClass,
            ));
        }
    }

    /**
     * 保存する応答 body。JSON が配列にならない場合は null。
     *
     * @return array<array-key, mixed>|null
     */
    private function decodeBody(JsonResponse $response): ?array
    {
        $bodyJson = $response->getContent();
        if (! is_string($bodyJson) || $bodyJson === '' || $bodyJson === 'null') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($bodyJson, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** 保存応答の再生 (Idempotent-Replayed は **ここでだけ** 付ける) */
    private function replayResponse(IdempotencyKey $existing): JsonResponse
    {
        $status = $existing->response_status;
        Assert::notNull($status, 'A completed idempotency row must carry a response status.');
        // response_body は null が正当な保存値 (2xx だが JSON 本体が配列でなかった場合)。
        $body = $existing->response_body;

        return (new JsonResponse($body, $status))
            ->header('Content-Type', 'application/json')
            ->header(IdempotencyHeaders::REPLAYED, IdempotencyHeaders::REPLAYED_VALUE);
    }

    private function errorResponse(ApiErrorCode $code): JsonResponse
    {
        return ApiErrorResource::make(ApiError::fromCode($code))
            ->response()->setStatusCode($code->defaultStatus());
    }

    /**
     * 所有権列 (api_key_id / user_id) を **1 箇所だけ**で組み立てる。
     * どちらか一方だけが非 NULL になることは、この method と Feature テストが担保する
     * (DB の CHECK 制約は持たない = 保証主体を誇張しない)。
     *
     * @return array{api_key_id: int|null, user_id: int|null}
     */
    private function ownershipColumns(ApiActorContext $actor): array
    {
        return $actor->apiKey instanceof ApiKey
            ? ['api_key_id' => $actor->apiKey->id, 'user_id' => null]
            : ['api_key_id' => null, 'user_id' => $actor->user->id];
    }

    /**
     * actor スコープ + route + key の行 query (主キー同一性クエリは使わない)。
     *
     * @return Builder<IdempotencyKey>
     */
    private function rowQuery(ApiActorContext $actor, string $routeName, string $key): Builder
    {
        return $this->scopedQuery($actor)->where('route_name', $routeName)->where('key', $key);
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

    private function actorKind(ApiActorContext $actor): string
    {
        return $actor->apiKey instanceof ApiKey ? 'api_key' : 'user';
    }

    /**
     * ログに載せる route 識別子。
     *
     * ★行のスコープに使う `$routeName` は名前が無ければ `$request->path()` に落ちるが、
     *   path には route parameter の**実値** (project id / item id) が入る。
     *   ログには実値を出さないため、名前が無いときは固定文字列にする
     *   (「載せるのは 5 項目だけ」という契約を守る)。
     */
    private function loggableRouteName(Request $request): string
    {
        $name = $request->route()?->getName();

        return is_string($name) && $name !== '' ? $name : '(unnamed-api-route)';
    }

    /** メソッド + パス + body で同一リクエストかを判定する */
    private function hashRequest(Request $request): string
    {
        return hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());
    }
}
