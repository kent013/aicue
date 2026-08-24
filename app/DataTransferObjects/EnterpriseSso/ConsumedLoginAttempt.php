<?php

declare(strict_types=1);

namespace App\DataTransferObjects\EnterpriseSso;

use App\Models\EnterpriseSsoLoginAttempt;
use App\Models\OrganizationOidcConnection;
use SensitiveParameter;
use Webmozart\Assert\Assert;

/**
 * 使用権を得た試行の中身 (**試行の行そのものは外へ出さない**)。
 *
 * ★接続は **relation 起点で解決したモデル**を持つ。
 *   id だけを持ち回って呼び出し側でクラス起点の主キー同一性クエリを書かせない
 *   (AGENTS.md セキュリティ不変条件 3。`DirectFetchInventory` の母集団を増やさない)。
 * ★PKCE の検証子だけは token 交換でそのまま送るので平文で持つ。
 *   `#[SensitiveParameter]` を付け、他の秘密 (state / nonce / 結合) は**指紋のまま**扱う。
 */
final readonly class ConsumedLoginAttempt
{
    private function __construct(
        public OrganizationOidcConnection $connection,
        public string $nonceFingerprint,
        #[SensitiveParameter] public string $codeVerifier,
    ) {}

    public static function fromModel(EnterpriseSsoLoginAttempt $attempt): self
    {
        // ★relation 起点。FK が cascade で担保しているので不在は不変条件の破れ = fail-fast。
        $connection = $attempt->connection;
        Assert::isInstanceOf($connection, OrganizationOidcConnection::class);

        return new self(
            connection: $connection,
            nonceFingerprint: $attempt->nonce_fingerprint,
            codeVerifier: $attempt->pkce_verifier_encrypted,
        );
    }
}
