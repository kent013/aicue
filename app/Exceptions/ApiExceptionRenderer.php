<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ApiErrorCode;
use App\Http\Resources\ApiErrorResource;
use App\Support\Api\ApiError;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * framework 例外 → 統一 REST API v1 エラー envelope ({error: {code, message, status, details?}})。
 *
 * `api/*` パスのリクエストのみ書き換える。Inertia / web リクエストは既存の
 * レンダリング経路 (Error ページ / redirect) を保つため対象外。
 * bootstrap/app.php の withExceptions で render に配線する。
 */
final class ApiExceptionRenderer
{
    public static function shouldHandle(Request $request): bool
    {
        return $request->is('api/*');
    }

    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! self::shouldHandle($request)) {
            return null;
        }

        // HttpResponseException は自前の response を持つ (FormRequest 由来等) ため尊重する
        if ($e instanceof HttpResponseException) {
            return null;
        }

        $error = self::toApiError($e);

        return ApiErrorResource::make($error)
            ->response()
            ->setStatusCode($error->status)
            ->withHeaders(self::extraHeaders($e));
    }

    private static function toApiError(Throwable $e): ApiError
    {
        if ($e instanceof AuthenticationException) {
            return ApiError::fromCode(
                ApiErrorCode::Unauthenticated,
                message: $e->getMessage() !== '' ? $e->getMessage() : null,
            );
        }

        if ($e instanceof AuthorizationException || $e instanceof AccessDeniedHttpException) {
            return ApiError::fromCode(
                ApiErrorCode::Forbidden,
                message: $e->getMessage() !== '' ? $e->getMessage() : null,
            );
        }

        // cross-org は認可より前に 404 (存在を漏らさない) — メッセージも固定文言に collapse する
        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return ApiError::fromCode(ApiErrorCode::NotFound);
        }

        if ($e instanceof ValidationException) {
            /** @var array<string, mixed> $errors */
            $errors = $e->errors();

            return ApiError::fromCode(
                ApiErrorCode::ValidationFailed,
                message: $e->getMessage(),
                status: $e->status,
                details: ['errors' => $errors],
            );
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            // throttle (429) は Retry-After を details.retry_after として表面化する
            if ($status === 429) {
                return ApiError::fromCode(
                    ApiErrorCode::RateLimited,
                    message: $e->getMessage() !== '' ? $e->getMessage() : null,
                    details: self::rateLimitDetails($e),
                );
            }

            $code = ApiErrorCode::fromHttpStatus($status);

            // 未対応 status (405/415/...) は internal_server_error の code に collapse しつつ
            // HTTP status は保持する。production では framework のメッセージを隠す (情報漏えい防止)
            $message = $e->getMessage() !== '' && $code !== ApiErrorCode::InternalServerError
                ? $e->getMessage()
                : (config('app.debug') === true && $e->getMessage() !== ''
                    ? $e->getMessage()
                    : $code->defaultMessage());

            return new ApiError(
                code: $code->value,
                message: $message,
                status: $status,
            );
        }

        // 非 HTTP 例外: 500。production では内部情報 (メッセージ・クラス名) を漏らさない
        $message = config('app.debug') === true && $e->getMessage() !== ''
            ? $e->getMessage()
            : ApiErrorCode::InternalServerError->defaultMessage();

        return ApiError::fromCode(ApiErrorCode::InternalServerError, message: $message);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function rateLimitDetails(HttpExceptionInterface $e): ?array
    {
        $headers = $e->getHeaders();
        if (! isset($headers['Retry-After'])) {
            return null;
        }
        $retryAfter = $headers['Retry-After'];
        if (is_string($retryAfter) && ctype_digit($retryAfter)) {
            return ['retry_after' => (int) $retryAfter];
        }
        if (is_int($retryAfter) || is_string($retryAfter)) {
            return ['retry_after' => $retryAfter];
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private static function extraHeaders(Throwable $e): array
    {
        if (! $e instanceof HttpExceptionInterface) {
            return [];
        }

        $headers = [];
        foreach ($e->getHeaders() as $name => $value) {
            if (is_string($name) && (is_string($value) || is_int($value))) {
                $headers[$name] = (string) $value;
            }
        }

        return $headers;
    }
}
