<?php

declare(strict_types=1);

namespace App\Support\Organization;

/**
 * 保存を試みる識別名 1 件と、その**由来**。
 * 由来は一意衝突したときの遷移を決めるためだけに存在する (保存する値は slug だけ)。
 */
final readonly class SlugCandidate
{
    public function __construct(
        public AssignableOrganizationSlug $slug,
        public SlugCandidateOrigin $origin,
    ) {}
}
