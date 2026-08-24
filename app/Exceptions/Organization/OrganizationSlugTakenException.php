<?php

declare(strict_types=1);

namespace App\Exceptions\Organization;

use App\Support\Organization\AssignableOrganizationSlug;
use DomainException;

/**
 * 利用者が明示した識別名が既に使われている (家系裁定 AG-039)。
 *
 * ★**黙って代替を作らない**。利用者が明示した値は矯正も代替もせず、
 *   Controller が 422 へ変換して返す。導出値・フォールバック由来の衝突は
 *   Service 内で次の候補へ遷移するので、この例外は Requested 由来のときだけ出る。
 */
final class OrganizationSlugTakenException extends DomainException
{
    public function __construct(public readonly AssignableOrganizationSlug $slug)
    {
        parent::__construct("この識別名は既に使われています: {$slug->value}");
    }
}
