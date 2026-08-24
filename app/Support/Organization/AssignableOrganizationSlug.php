<?php

declare(strict_types=1);

namespace App\Support\Organization;

use App\Exceptions\Organization\ReservedOrganizationSlugException;

/**
 * **保存してよい**組織識別名。不変条件は「構文的に妥当 かつ 非予約語」。
 *
 * ★生成と昇格は別操作である。構文型を作るのが「生成」、予約語判定器を通して
 *   この型にするのが「昇格」。
 * ★organizations.slug を書ける経路は**この型を受ける 1 本だけ**で、構文型を保存へ渡す道は
 *   型で消えている (OrganizationSlugWritePathTest が deny-by-default で固定する)。
 */
final readonly class AssignableOrganizationSlug
{
    private function __construct(public string $value) {}

    public static function promote(OrganizationSlug $slug, OrganizationSlugReservedWords $reserved): self
    {
        // ★1 回の取得で分岐する (contains → reasonFor の 2 回呼びは、理由の非 null 性を
        //   PHPStan が導けない)
        $reason = $reserved->reservationFor($slug);
        if ($reason !== null) {
            throw new ReservedOrganizationSlugException($slug, $reason);
        }

        return new self($slug->value);
    }
}
