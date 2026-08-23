<?php

declare(strict_types=1);

namespace App\DataTransferObjects\EnterpriseSso;

use App\Models\OrganizationOidcConnection;

/**
 * `verify` の第 1 段が読む**認証材料のスナップショット**。
 *
 * ★**client secret の平文も値型も持たない** — 持つのは**暗号文そのものの SHA-256 digest**
 *   だけである (復号せずに「書き換わったか」だけを見る)。
 *   verify は discovery を取るだけで秘密を必要としないので、
 *   **verify の経路は client secret を一度も復号しない**。
 * ★`$hidden` や `toArray()` の対象にもならない内部の値であり、**画面へ出さない**。
 */
final readonly class ConnectionCredentialsSnapshot
{
    private function __construct(
        public int $connectionId,
        public string $issuer,
        public string $clientId,
        public int $credentialsRevision,
        public string $clientSecretCiphertextDigest,
    ) {}

    public static function of(OrganizationOidcConnection $connection): self
    {
        return new self(
            connectionId: $connection->id,
            issuer: $connection->issuer,
            clientId: $connection->client_id,
            credentialsRevision: $connection->credentials_revision,
            clientSecretCiphertextDigest: $connection->clientSecretCiphertextDigest(),
        );
    }
}
