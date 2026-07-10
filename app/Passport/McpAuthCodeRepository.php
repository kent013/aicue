<?php

declare(strict_types=1);

namespace App\Passport;

use App\Enums\OAuth\OAuthClientKind;
use App\Models\OauthSession;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Bridge\AuthCodeRepository as BaseRepository;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * auth_code 発行時に、consent で選択された organization_id を同一トランザクションで書き込む。
 *
 * consent middleware (McpConsentOrganizationBinder) が request attribute
 * `mcp_selected_organization_id` に int を set している前提。
 * `null` の場合 (非 MCP フロー) は何もしない (base flow のみ)。
 *
 * client_kind='cli' の auth code に対しては、同一トランザクション内で
 * `oauth_sessions` を 1 行作成し `oauth_auth_codes.session_id` を書き込む。
 * 「auth code 永続化の 1 点」に寄せることで「session は作られたが code に id が
 * 無い／その逆」を構造排除する。MCP client は従来どおり session_id NULL。
 */
final class McpAuthCodeRepository extends BaseRepository
{
    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $orgIdRaw = request()->attributes->get('mcp_selected_organization_id');
        $orgId = is_int($orgIdRaw) ? $orgIdRaw : null;

        $clientId = (string) $authCodeEntity->getClient()->getIdentifier();
        $isCli = $this->isCliClient($clientId);

        DB::transaction(function () use ($authCodeEntity, $orgId, $clientId, $isCli): void {
            parent::persistNewAuthCode($authCodeEntity);

            if ($orgId !== null) {
                DB::table('oauth_auth_codes')
                    ->where('id', $authCodeEntity->getIdentifier())
                    ->update(['organization_id' => $orgId]);
            }

            if (! $isCli || $orgId === null) {
                return;
            }

            $userId = (int) $authCodeEntity->getUserIdentifier();
            $user = User::query()->find($userId);
            $organization = Organization::query()->find($orgId);
            if ($user === null || $organization === null) {
                // CLI auth code は session 必須。user/org が引けない = 整合性破綻なので
                // 「token は出るが resolve.api-actor で常時 401」の中途半端な状態を作らず、
                // invalid_grant で交換自体を失敗させる (fail-loudly)。
                throw OAuthServerException::invalidGrant(
                    'Unable to establish a CLI session for this authorization code.',
                );
            }

            $sessionId = (string) Str::uuid();
            OauthSession::createForClient(
                user: $user,
                organization: $organization,
                clientId: $clientId,
                kind: OAuthClientKind::Cli,
                sessionId: $sessionId,
            );

            DB::table('oauth_auth_codes')
                ->where('id', $authCodeEntity->getIdentifier())
                ->update(['session_id' => $sessionId]);
        });
    }

    private function isCliClient(string $clientId): bool
    {
        $kind = DB::table('oauth_clients')
            ->where('id', $clientId)
            ->value('client_kind');

        return $kind === OAuthClientKind::Cli->value;
    }
}
