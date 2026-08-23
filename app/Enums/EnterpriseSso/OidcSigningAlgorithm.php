<?php

declare(strict_types=1);

namespace App\Enums\EnterpriseSso;

/**
 * ID トークンの署名方式の**許可集合**。
 *
 * ★`none` と対称鍵 (HMAC) は **case に持たない**。
 *   許可集合を型で表せば「拒否の書き忘れ」という失敗様式そのものが消える
 *   (文字列比較で弾く形は、比較を 1 つ忘れれば通る)。
 */
enum OidcSigningAlgorithm: string
{
    case Rs256 = 'RS256';
    case Rs384 = 'RS384';
    case Rs512 = 'RS512';
    case Es256 = 'ES256';
    case Es384 = 'ES384';

    /** JWK の `kty` として妥当な値 (署名検証前の整合検査に使う)。 */
    public function keyType(): string
    {
        return match ($this) {
            self::Rs256, self::Rs384, self::Rs512 => 'RSA',
            self::Es256, self::Es384 => 'EC',
        };
    }

    /** EC のときに要求する `crv`。RSA では null。 */
    public function curve(): ?string
    {
        return match ($this) {
            self::Es256 => 'P-256',
            self::Es384 => 'P-384',
            self::Rs256, self::Rs384, self::Rs512 => null,
        };
    }
}
