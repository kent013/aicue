<?php

declare(strict_types=1);

namespace App\Rules;

use App\Exceptions\Organization\InvalidOrganizationSlugException;
use App\Exceptions\Organization\ReservedOrganizationSlugException;
use App\Support\Organization\AssignableOrganizationSlug;
use App\Support\Organization\OrganizationSlug;
use App\Support\Organization\OrganizationSlugReservedWords;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * 識別名の**入力の妥当性**を 422 として返す唯一の変換点 (家系裁定 AG-039)。
 *
 * ★domain 例外 (`InvalidOrganizationSlugException` / `ReservedOrganizationSlugException`) は
 *   HTTP を知らない。素のまま Controller まで届くと 500 になるので、
 *   **FormRequest のカスタムルールで 422 へ変換する**。
 * ★変換点は FormRequest (入力の妥当性) と Controller (ロック後に判明する競合) の
 *   **2 点だけ**である。それ以外に散らさない。
 */
final class AssignableOrganizationSlugRule implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('識別名は文字列で指定してください。');

            return;
        }

        try {
            AssignableOrganizationSlug::promote(
                OrganizationSlug::fromString($value),
                OrganizationSlugReservedWords::load(),
            );
        } catch (InvalidOrganizationSlugException|ReservedOrganizationSlugException $e) {
            $fail($e->getMessage());
        }
    }
}
