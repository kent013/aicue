<?php

declare(strict_types=1);

namespace App\Services\OAuth;

use App\DataTransferObjects\OAuth\OAuthSessionListItemDto;
use App\Enums\OAuth\OAuthClientKind;
use App\Models\OauthSession;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * OAuth セッション一覧 (Web UI) を DTO list として返す。
 *
 * 2 種類を 1 リストに集約する:
 *  - CLI セッション (`oauth_sessions`、client_kind='cli'): scopes は各セッション配下の
 *    「最新の非失効 access token」(created_at DESC, id DESC の決定的順序) の granted scope。
 *    失効済みも `revokedAt` 付きで残す (棚卸し目的)。
 *  - legacy MCP token (`oauth_access_tokens` で session_id NULL かつ client の
 *    client_kind='mcp'): oauth_sessions 行を持たない MCP token (WP23 経路)。
 *    `id` は access token id、`isLegacy=true`。
 */
class OauthSessionListService
{
    /**
     * 組織配下の全 CLI セッション + legacy MCP token (発行ユーザー名込み)。
     *
     * @return list<OAuthSessionListItemDto>
     */
    public function listForOrganization(Organization $organization): array
    {
        $sessions = $organization->oauthSessions()
            ->where('client_kind', OAuthClientKind::Cli->value)
            ->with('user')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $cli = $this->mapSessions(array_values($sessions->all()));
        $legacy = $this->legacyMcpTokens($organization->id);

        return $this->sortByCreatedAtDesc([...$cli, ...$legacy]);
    }

    /**
     * @param  list<OAuthSessionListItemDto>  $items
     * @return list<OAuthSessionListItemDto>
     */
    private function sortByCreatedAtDesc(array $items): array
    {
        usort(
            $items,
            static fn (OAuthSessionListItemDto $a, OAuthSessionListItemDto $b): int => strcmp($b->createdAt, $a->createdAt),
        );

        return $items;
    }

    /**
     * @param  list<OauthSession>  $sessions
     * @return list<OAuthSessionListItemDto>
     */
    private function mapSessions(array $sessions): array
    {
        $scopesBySession = $this->latestScopesForSessions(
            array_map(static fn (OauthSession $s): string => $s->id, $sessions),
        );

        return array_map(
            fn (OauthSession $session): OAuthSessionListItemDto => new OAuthSessionListItemDto(
                id: $session->id,
                userId: $session->user_id,
                userName: $session->user->name ?? null,
                clientKind: $session->client_kind,
                scopes: $scopesBySession[$session->id] ?? [],
                lastUsedAt: $this->iso($session->last_used_at),
                revokedAt: $this->iso($session->revoked_at),
                createdAt: $this->iso($session->created_at) ?? '',
                isLegacy: false,
            ),
            $sessions,
        );
    }

    /**
     * legacy MCP token (session_id NULL, client の client_kind='mcp') を
     * pseudo-session として列挙する。
     *
     * @return list<OAuthSessionListItemDto>
     */
    private function legacyMcpTokens(int $organizationId): array
    {
        /** @var list<object{id: mixed, user_id: mixed, scopes: mixed, created_at: mixed}> $rows */
        $rows = DB::table('oauth_access_tokens as t')
            ->join('oauth_clients as c', 't.client_id', '=', 'c.id')
            ->whereNull('t.session_id')
            ->where('c.client_kind', OAuthClientKind::Mcp->value)
            ->where('t.revoked', false)
            ->where('t.organization_id', $organizationId)
            ->orderByDesc('t.created_at')
            ->orderByDesc('t.id')
            ->get(['t.id', 't.user_id', 't.scopes', 't.created_at'])
            ->all();

        // users.name は暗号化列のため raw join では復号できない。発行ユーザー名は
        // Eloquent User モデル経由で復号して解決する (組織一覧の監査性 = 誰の token か)。
        // user_id は driver により int / 数値文字列いずれでも返り得るため normalizeUserId で int 化する。
        $userIds = array_values(array_unique(array_filter(
            array_map(fn (object $row): int => $this->normalizeUserId($row->user_id), $rows),
            static fn (int $id): bool => $id > 0,
        )));
        $namesById = $userIds === []
            ? []
            : User::query()->whereIn('id', $userIds)->get()
                ->mapWithKeys(static fn (User $u): array => [$u->id => $u->name])->all();

        return array_map(
            function (object $row) use ($namesById): OAuthSessionListItemDto {
                $resolvedUserId = $this->normalizeUserId($row->user_id);
                $name = $namesById[$resolvedUserId] ?? null;

                return new OAuthSessionListItemDto(
                    id: is_scalar($row->id) ? (string) $row->id : '',
                    userId: $resolvedUserId,
                    userName: is_string($name) ? $name : null,
                    clientKind: OAuthClientKind::Mcp->value,
                    scopes: $this->decodeScopes($row->scopes),
                    lastUsedAt: null,
                    revokedAt: null,
                    createdAt: $this->normalizeTimestamp($row->created_at),
                    isLegacy: true,
                );
            },
            $rows,
        );
    }

    /**
     * 各セッションの「最新の非失効 access token」の scopes を引く (created_at DESC, id DESC)。
     *
     * @param  list<string>  $sessionIds
     * @return array<string, list<string>>
     */
    private function latestScopesForSessions(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }

        /** @var list<object{session_id: mixed, scopes: mixed}> $rows */
        $rows = DB::table('oauth_access_tokens')
            ->whereIn('session_id', $sessionIds)
            ->where('revoked', false)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['session_id', 'scopes'])
            ->all();

        $result = [];
        foreach ($rows as $row) {
            if (! is_string($row->session_id)) {
                continue;
            }
            // orderByDesc により最初に見たものが最新。以降は無視 (per-session 1 件)。
            if (array_key_exists($row->session_id, $result)) {
                continue;
            }
            $result[$row->session_id] = $this->decodeScopes($row->scopes);
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function decodeScopes(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            $decoded,
            static fn (mixed $s): bool => is_string($s),
        ));
    }

    /**
     * DB driver 差 (int / 数値文字列) を吸収して user_id を int 化する。非数値は 0。
     */
    private function normalizeUserId(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return 0;
    }

    private function normalizeTimestamp(mixed $value): string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }
        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value)->toIso8601String();
        }

        return '';
    }

    private function iso(?CarbonInterface $value): ?string
    {
        return $value?->toIso8601String();
    }
}
