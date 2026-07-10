<?php

declare(strict_types=1);

namespace App\Auth\Context;

/**
 * REST API v1 の認証主体 (actor) 種別。
 *
 * - `ApiKey`: 組織スコープの API キー (Bearer "{slug}_..."、CI / 自動化用)。
 * - `UserToken`: OAuth user token (人間の CLI。Passport `api-oauth` guard 由来)。
 *
 * backed enum の string 値は `GET /api/v1/me` の `actor_kind` として公開 API 契約に
 * 露出するため rename は version bump を伴う変更として扱う。
 */
enum ApiActorKind: string
{
    case ApiKey = 'api_key';
    case UserToken = 'user_token';
}
