<?php

declare(strict_types=1);

namespace App\Rules;

use App\ValueObjects\EnterpriseSso\OidcIssuerUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * issuer の入力規則 (https のみ / userinfo なし / query なし / fragment なし / 絶対 URL)。
 *
 * ★規則の**正本は {@see OidcIssuerUrl}** である。ここはその述語を validation へ橋渡しするだけで、
 *   条件を写さない (2 か所に書くと必ず食い違う)。
 */
class OidcIssuerUrlRule implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! OidcIssuerUrl::isValid($value)) {
            $fail('発行者 URL は https で始まり、ユーザー情報・クエリ・フラグメントを含まない絶対 URL である必要があります。');
        }
    }
}
