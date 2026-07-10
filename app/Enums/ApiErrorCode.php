<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * REST API v1 の正規エラーコード (統一 envelope {error: {code, message, status, details?}})。
 *
 * string 値は公開 API 契約の一部。リネームは version bump を伴う変更として扱うこと。
 * defaultStatus / defaultMessage は handler とドキュメントの drift を防ぐための単一の真実。
 */
enum ApiErrorCode: string
{
    case Unauthenticated = 'unauthenticated';
    case Forbidden = 'forbidden';
    /**
     * API キーに必要な ability が付与されていない場合の 403 専用コード。
     * Forbidden と分けるのは、クライアントが details.required_ability を読んで
     * 「ability を追加して再発行」と案内できるようにするため。
     */
    case InsufficientAbility = 'insufficient_ability';
    /**
     * 認証は成立したが actor (人間の帰属) を解決できない場合の 403 専用コード
     * (例: API キーの発行者が削除済み)。resolve.api-actor middleware が返す。
     */
    case ActorNotResolvable = 'actor_not_resolvable';
    case NotFound = 'not_found';
    case ValidationFailed = 'validation_failed';
    case RateLimited = 'rate_limited';
    /** 同一 Idempotency-Key + 異なる request body の再送 (409) */
    case IdempotencyConflict = 'idempotency_conflict';
    case InternalServerError = 'internal_server_error';

    public function defaultStatus(): int
    {
        return match ($this) {
            self::Unauthenticated => 401,
            self::Forbidden, self::InsufficientAbility, self::ActorNotResolvable => 403,
            self::NotFound => 404,
            self::ValidationFailed => 422,
            self::RateLimited => 429,
            self::IdempotencyConflict => 409,
            self::InternalServerError => 500,
        };
    }

    public function defaultMessage(): string
    {
        return match ($this) {
            self::Unauthenticated => 'Invalid or expired API key.',
            self::Forbidden => 'You do not have permission to perform this action.',
            self::InsufficientAbility => 'This API key does not have the required ability.',
            self::ActorNotResolvable => 'The authenticated actor could not be resolved.',
            self::NotFound => 'Resource not found.',
            self::ValidationFailed => 'The given data was invalid.',
            self::RateLimited => 'Too many requests.',
            self::IdempotencyConflict => 'Idempotency key conflict.',
            self::InternalServerError => 'Internal server error.',
        };
    }

    /**
     * HTTP status → 正規コード。未対応 status (405 / 415 等) は internal_server_error に
     * collapse する (無関係なコードへの暗黙の別名化を防ぐ。HTTP status 自体は保持される)。
     */
    public static function fromHttpStatus(int $status): self
    {
        return match ($status) {
            401 => self::Unauthenticated,
            403 => self::Forbidden,
            404 => self::NotFound,
            409 => self::IdempotencyConflict,
            422 => self::ValidationFailed,
            429 => self::RateLimited,
            default => self::InternalServerError,
        };
    }
}
