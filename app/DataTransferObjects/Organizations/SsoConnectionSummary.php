<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Organizations;

use App\Enums\EnterpriseSso\OidcConnectionStatus;
use App\Models\OrganizationOidcConnection;
use Carbon\CarbonImmutable;

/**
 * 画面へ返す接続の要約。
 *
 * ★**接続の秘密を持たない。伏字すら持たない** —
 *   伏字の項目を持つと「一覧の生成時に復号する」実装へ誘導される。
 *   在る・無いだけを bool で返せば、**一覧の経路は秘密に一度も触らない**。
 * ★`credentials_revision` も載せない — これは D1 の内部の比較子であって、
 *   画面が使う値ではない。外へ出すと「画面から見える版番号」として別の意味を持ち始める。
 */
final readonly class SsoConnectionSummary
{
    public function __construct(
        public int $id,
        public string $loginSlug,
        public string $displayName,
        public string $issuer,
        public string $clientId,
        public OidcConnectionStatus $status,
        public bool $hasClientSecret,          // ★復号しない
        public ?CarbonImmutable $verifiedAt,
        public bool $hasIdentities,
    ) {}

    /**
     * ★`$hasIdentities` は呼び出し側が**まとめて数えた結果**を渡す
     *   (一覧のたびに 1 件ずつ数えない = N+1 を作らない)。
     */
    public static function fromModel(OrganizationOidcConnection $connection, bool $hasIdentities): self
    {
        return new self(
            id: $connection->id,
            loginSlug: $connection->login_slug,
            displayName: $connection->display_name,
            issuer: $connection->issuer,
            clientId: $connection->client_id,
            status: $connection->status,
            // ★暗号文の有無だけを見る (復号しない)。
            hasClientSecret: $connection->hasClientSecret(),
            verifiedAt: $connection->verified_at,
            hasIdentities: $hasIdentities,
        );
    }

    /**
     * Inertia へ渡す形。enum は value、時刻は ISO 8601 文字列、キーは camelCase。
     * TypeScript の Props と一致することをテストが固定する。
     *
     * @return array{id: int, loginSlug: string, displayName: string, issuer: string,
     *               clientId: string, status: string, hasClientSecret: bool,
     *               verifiedAt: string|null, hasIdentities: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'loginSlug' => $this->loginSlug,
            'displayName' => $this->displayName,
            'issuer' => $this->issuer,
            'clientId' => $this->clientId,
            'status' => $this->status->value,
            'hasClientSecret' => $this->hasClientSecret,
            // オフセット付きで出す (端末側 Intl が UTC を現地時刻と誤解釈しないため)。
            'verifiedAt' => $this->verifiedAt?->toIso8601String(),
            'hasIdentities' => $this->hasIdentities,
        ];
    }
}
