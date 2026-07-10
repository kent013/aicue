<?php

declare(strict_types=1);

namespace App\Services\Mcp\Auth;

use App\Enums\Mcp\ToolName;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\AccessToken;
use Webmozart\Assert\Assert;

/**
 * MCP tool 実行時の認可コンテキスト。
 *
 * Passport access token から principal (User) と bound organization を解決し、
 * Laratrust permission を organization の laratrust_team_id scope で runtime
 * 再評価する。token 発行後に剥奪された permission / membership も即反映される。
 *
 * Memoize は Request attributes に詰める (request lifetime に bind)。
 * Octane / 長寿命プロセス / parallel test でもリークしない。
 *
 * 認可層由来の失敗は `AuthorizationException` に正規化する (laravel/mcp の
 * CallTool が `Response::error` にマップし、JSON-RPC envelope にクリーンに乗る。
 * Assert 系の 500 化を避ける)。
 */
final class McpAuthorizationContext
{
    private const ATTRIBUTE_KEY = 'mcp_auth_context';

    /** MCP 経路の基礎 scope。これを持たない token (CLI user token 等) は拒否する。 */
    private const REQUIRED_SCOPE = 'mcp:use';

    public function __construct(
        public readonly User $user,
        public readonly Organization $organization,
        public readonly string $accessTokenId,
        public readonly ?string $oauthClientId,
    ) {}

    public static function for(Request $request): self
    {
        $cached = $request->attributes->get(self::ATTRIBUTE_KEY);
        if ($cached instanceof self) {
            return $cached;
        }

        $user = self::resolveAuthenticatedUser($request);
        Assert::isInstanceOf($user, User::class, 'No authenticated User on request.');

        $accessToken = $user->token();
        Assert::isInstanceOf($accessToken, AccessToken::class, 'No Passport access token on request.');

        // MCP 経路は `mcp:use` scope 必須。CLI user token (`cli:use`) が MCP endpoint に
        // 流用されるのを scope 境界で拒否する (REST 側の cli:use 必須と対称)。
        if (! $accessToken->can(self::REQUIRED_SCOPE)) {
            throw new AuthorizationException('This token is not authorized for MCP access (mcp:use scope required).');
        }

        // Passport v13 の AccessToken は League の AccessTokenEntity ラッパで、
        // `oauth_access_token_id` attribute に DB の primary key が入る。
        $tokenId = (string) $accessToken->oauth_access_token_id;
        Assert::notEmpty($tokenId, 'Passport access token id must be non-empty.');

        /** @var mixed $orgIdRaw */
        $orgIdRaw = DB::table('oauth_access_tokens')
            ->where('id', $tokenId)
            ->value('organization_id');
        if ($orgIdRaw === null) {
            throw new AuthorizationException('Access token is not associated with an organization. Re-authorize and pick an organization on the consent screen.');
        }
        if (! is_numeric($orgIdRaw)) {
            throw new AuthorizationException('Access token has an invalid organization association. Re-authorize to obtain a fresh token.');
        }
        $orgId = (int) $orgIdRaw;

        $organization = Organization::query()->find($orgId);
        if ($organization === null) {
            throw new AuthorizationException('The organization associated with this access token no longer exists. Re-authorize and pick an active organization.');
        }

        // 最終防御: token 発行後にメンバーシップが剥奪されていたら即拒否。
        if (! $user->isMemberOf($organization)) {
            throw new AuthorizationException('You are no longer a member of the organization associated with this access token. Ask the organization owner to re-add you, or re-authorize for a different organization.');
        }

        $clientIdRaw = $accessToken->oauth_client_id ?? null;
        $oauthClientId = is_scalar($clientIdRaw) ? (string) $clientIdRaw : null;

        $ctx = new self(
            user: $user,
            organization: $organization,
            accessTokenId: $tokenId,
            oauthClientId: $oauthClientId,
        );

        $request->attributes->set(self::ATTRIBUTE_KEY, $ctx);

        return $ctx;
    }

    /**
     * Tool の required permission を runtime で再評価する。
     * null なら member であれば誰でも呼べる (whoami 等の base tool)。
     * permission は必ず organization の laratrust_team_id を明示して判定する
     * (strict_check=true 前提)。
     */
    public function authorizeTool(ToolName $tool): bool
    {
        $permission = $tool->requiredPermission();
        if ($permission === null) {
            return $this->isMember();
        }

        return $this->user->hasPermission($permission, $this->organization->laratrust_team_id);
    }

    public function isMember(): bool
    {
        return $this->user->isMemberOf($this->organization);
    }

    /**
     * MCP Request 上の認証済 User を一意に解決する。
     *
     * Authorization ヘッダがある場合は **必ず `mcp-oauth` (Passport) guard のみ** を参照する。
     * default guard (session user 等) には絶対にフォールバックしない。
     * これにより test で actingAs 後に Bearer 付きで MCP を叩いた際、
     * 「session user と Passport bearer user が異なる」ケースで bearer が確実に勝つ。
     *
     * Authorization ヘッダが無い場合は default guard を参照する (本番では auth:mcp-oauth
     * middleware に弾かれるため到達しないが、unit / contract テストの入口として保持)。
     */
    public static function resolveAuthenticatedUser(Request $request): ?User
    {
        if ($request->hasHeader('Authorization')) {
            $bearerUser = $request->user('mcp-oauth');

            return $bearerUser instanceof User ? $bearerUser : null;
        }

        $defaultUser = $request->user();

        return $defaultUser instanceof User ? $defaultUser : null;
    }
}
