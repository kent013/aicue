<?php

declare(strict_types=1);

namespace Tests\Support\Security;

/**
 * 1 つの解決点 (家系裁定 AG-047)。
 *
 * ★**入口の識別子は持たない**。台帳 (inventory) の**キーが入口の唯一の SoT** である
 *   (DTO 側にも持たせると、外側キーと内側の値が食い違う余地ができる)。
 * ★`resolutionId` は**入口内で安定した識別子** (メソッド名 + 引数名など)。
 */
final readonly class OrganizationResolutionPoint
{
    public function __construct(
        public string $resolutionId,
        public OrganizationReferenceProvenance $provenance,
        /** RelationScoped のときだけ非 null。**同じ入口内の**別の解決点を指す。 */
        public ?string $parentResolutionId = null,
    ) {}
}
