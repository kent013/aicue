<?php

declare(strict_types=1);

use App\Http\Middleware\SecurityHeaders;
use App\Mcp\Servers\AppMcpServer;
use Illuminate\Routing\Route;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP (Model Context Protocol) Routes
|--------------------------------------------------------------------------
|
| OAuth / MCP は web から分離した routes/ai.php に置く。
| このファイルは laravel/mcp の McpServiceProvider が prefix なしで読み込むため、
| MCP endpoint は `/api/v1/mcp` をフルパスで書く (api/* に置くことで統一エラー
| envelope の対象になる)。well-known / DCR は root 配下 (Passport の
| /oauth/{authorize,token,...} と prefix を揃えるため)。
|
| 認証構成 (WP23): MCP は OAuth 2.1 (Passport) で保護する。
| - Passport の /oauth/{authorize,token,...} は McpPassportServiceProvider が登録
|   (PKCE S256 強制 + consent の組織バインド + org/session の token chain 継承)
| - Mcp::oauthRoutes() が `.well-known/oauth-*` (RFC 8414 / RFC 9728) と
|   DCR `/oauth/register` (RFC 7591) を登録する
| - REST API v1 は dual guard (auth:api-key,api-oauth。WP24、routes/api.php)
|
| 書き込み tool を追加する場合は scope gate と idempotency
| (mcp_idempotency_keys) を必ず配線すること。
*/

Mcp::oauthRoutes();

/*
|--------------------------------------------------------------------------
| DCR (/oauth/register) の rate limit 後付け配線 + 起動 fail-fast
|--------------------------------------------------------------------------
|
| DCR は未認証で誰でも client 登録できる endpoint のため IP 単位の throttle を
| 必須にする。laravel/mcp の `oauthRoutes()` は Route を return しないので、
| router 走査で該当 route を見つけて throttle を追加する。
|
| 二段検出 (URI マッチ + action name に vendor controller 基準語を含むこと) で
| laravel/mcp の update による route 構造変更を早期検出。未達なら起動時
| fail-fast して DCR が無制限のまま公開される事故を防ぐ。
| limiter 本体 (oauth-register) は AppServiceProvider で定義する。
*/
$mcpOauthRegisterRoute = collect(app('router')->getRoutes()->getRoutes())
    ->first(function (Route $r): bool {
        if (! in_array('POST', $r->methods(), true)) {
            return false;
        }
        if ($r->uri() !== 'oauth/register') {
            return false;
        }

        // action name が OAuthRegisterController を含むことで誤マッチを防ぐ。
        return str_contains((string) $r->getActionName(), 'OAuthRegisterController');
    });

if (! $mcpOauthRegisterRoute instanceof Route) {
    throw new RuntimeException(
        'laravel/mcp の /oauth/register route が見つかりません。'
        .'laravel/mcp が update で route 構造を変えた可能性があります。'
        .'oauth-register bucket が middleware に配線されないまま起動すると DCR endpoint の'
        .'abuse 耐性が失われるため、fail-fast で起動を止めます。',
    );
}

$mcpOauthRegisterRoute->middleware('throttle:oauth-register');

/*
|--------------------------------------------------------------------------
| `.well-known/oauth-*` への SecurityHeaders 後付け配線 (WP15)
|--------------------------------------------------------------------------
|
| Mcp::oauthRoutes() が登録する discovery metadata route は middleware group に
| 属さないため、そのままではセキュリティヘッダが一切付かない。SecurityHeaders を
| 後付けし、middleware 側の `.well-known/oauth-*` 分岐で最小 subset
| (nosniff + no-referrer、config: security.metadata_headers) を適用する。
*/
collect(app('router')->getRoutes()->getRoutes())
    ->filter(fn (Route $r): bool => str_starts_with($r->uri(), '.well-known/oauth-'))
    ->each(fn (Route $r) => $r->middleware(SecurityHeaders::class));

/*
|--------------------------------------------------------------------------
| MCP server endpoint
|--------------------------------------------------------------------------
|
| POST /api/v1/mcp に AppMcpServer (laravel/mcp) を mount。middleware の順序契約:
|
|   mcp.origin (Origin allowlist) → mcp.transport (POST+JSON 強制)
|     → throttle:api-mcp → auth:mcp-oauth (Passport Bearer)
*/
Mcp::web('/api/v1/mcp', AppMcpServer::class)->middleware([
    'mcp.origin',
    'mcp.transport',
    'throttle:api-mcp',
    'auth:mcp-oauth',
]);
