<?php

declare(strict_types=1);

namespace App\Enums\OAuth;

use App\Http\Middleware\ResolveApiActor;

/**
 * OAuth client の用途種別 (`oauth_clients.client_kind` / `oauth_sessions.client_kind`)。
 *
 * - `Mcp`: DCR (`/oauth/register`) で登録される MCP client (WP23 の `auth:mcp-oauth` 経路)。
 *   session を持たない既存 token は legacy として OauthSessionListService が併記する。
 * - `Cli`: first-party CLI の PKCE loopback で発行される public client。REST dual guard の
 *   user-token actor 解決 ({@see ResolveApiActor}) はこの kind の
 *   session のみ受理する。
 *
 * 値は永続化 + actor 解決判定で参照されるため rename には migration が要る。
 * `client_kind` 列自体は nullable (= 種別を持たない client は Passport 標準動作)。
 */
enum OAuthClientKind: string
{
    case Mcp = 'mcp';
    case Cli = 'cli';
}
