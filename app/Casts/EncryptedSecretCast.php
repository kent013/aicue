<?php

declare(strict_types=1);

namespace App\Casts;

use App\ValueObjects\EnterpriseSso\ConnectionSecret;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

/**
 * 暗号化して保存する秘密を {@see ConnectionSecret} として出し入れする cast。
 *
 * ★受け取るのも返すのも**値型だけ**である。素の文字列を set できる道を作らない
 *   (作ると「うっかり平文を代入する」経路が復活する)。
 * ★復号できない暗号文は **null を返さず例外**にする。null に畳むと
 *   「秘密が無い接続」と「壊れた暗号文」が区別できなくなり、
 *   D2 の `hasClientSecret` が黙って false になる。
 *
 * @implements CastsAttributes<ConnectionSecret, ConnectionSecret>
 */
final class EncryptedSecretCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?ConnectionSecret
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf('%s は文字列の暗号文である必要があります。', $key));
        }

        $plaintext = Crypt::decryptString($value);

        return ConnectionSecret::fromPlaintext($plaintext);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (! $value instanceof ConnectionSecret) {
            throw new InvalidArgumentException(sprintf('%s には ConnectionSecret だけを代入できます。', $key));
        }

        return [$key => Crypt::encryptString($value->revealForEncryptionAtRest())];
    }
}
