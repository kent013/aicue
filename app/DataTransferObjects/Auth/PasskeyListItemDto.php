<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Auth;

/**
 * Settings/Security の passkey 一覧 1 件分。
 *
 * `resources/js/lib/passkeys.ts` の PasskeyListItem と 1:1 対応させる
 * (項目を増やすときは両方を同時に変更すること)。
 * credential 本体 (公開鍵 / signature counter) は**露出しない**。
 */
final readonly class PasskeyListItemDto
{
    public function __construct(
        public int $id,
        public string $name,
        /** 認証器名 (AAGUID から解決。不明なら null) */
        public ?string $authenticator,
        public ?string $lastUsedAt,
        public ?string $createdAt,
    ) {}
}
