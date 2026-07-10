<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Webmozart\Assert\Assert;

/**
 * email が CipherSweet 暗号化カラムのため、credentials からの検索を
 * blind index (whereBlind) 経由に差し替えた UserProvider。
 * config/auth.php の providers.users.driver = 'encrypted-eloquent' で有効化する。
 */
class EncryptedUserProvider extends EloquentUserProvider
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        if (
            $credentials === [] ||
            (count($credentials) === 1 && str_contains(array_key_first($credentials), 'password'))
        ) {
            return null;
        }

        $query = $this->createModel()->newQuery();

        foreach ($credentials as $key => $value) {
            if (str_contains($key, 'password')) {
                continue;
            }
            if ($key === 'email') {
                Assert::string($value);
                $query->whereBlind('email', 'email_index', $value);
            } else {
                $query->where($key, $value);
            }
        }

        $user = $query->first();

        if ($user === null) {
            return null;
        }

        Assert::isInstanceOf($user, Authenticatable::class);

        return $user;
    }
}
