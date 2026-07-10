<?php

declare(strict_types=1);

namespace App\Enums\OAuth;

use App\Enums\ApiKeyAbility;

/**
 * CLI OAuth user token の scope 列挙。
 *
 * API キーの ability と異なり、OAuth user token は scope を持つ。`CliUse` は CLI 経由
 * アクセスの基礎 scope (これが無い token は dual-guard の actor 解決段で 401)。
 * `Read` / `Write` は REST の {@see ApiKeyAbility} と **同じ string 値** で
 * 対応させる (RequireApiKeyAbility が ability 値のまま scope 判定できる単純な契約)。
 * `SessionRevoke` は CLI 専用 (`DELETE /api/v1/me/session`) の scope。
 *
 * 値は公開 OAuth 契約の一部なので rename は version bump を伴う変更として扱う。
 * アプリ固有の ability を追加する場合は同じ string 値の scope をここにも追加する。
 */
enum CliOAuthScope: string
{
    /** CLI 経由アクセスの基礎 scope。無い token は actor 解決段で拒否 (401)。 */
    case CliUse = 'cli:use';

    /** 全 GET endpoint (REST `read` ability に対応)。 */
    case Read = 'read';

    /** 書き込み endpoint (REST `write` ability に対応)。 */
    case Write = 'write';

    /** CLI セッション失効 (`DELETE /api/v1/me/session`) 専用 scope。 */
    case SessionRevoke = 'session.revoke';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases(),
        );
    }
}
