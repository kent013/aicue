<?php

declare(strict_types=1);

namespace Tests\Support\Security;

/**
 * 解決点を持つ入口 (家系裁定 AG-047)。解決点ごとに由来を持つ。
 */
final readonly class OrganizationScoped extends MachinePlaneEntryClassification
{
    /** @param non-empty-list<OrganizationResolutionPoint> $resolutions */
    public function __construct(public array $resolutions) {}
}
