<?php

declare(strict_types=1);

namespace App\Exceptions\Organization;

use App\Enums\Organization\SlugReservationReason;
use App\Support\Organization\OrganizationSlug;
use DomainException;

/**
 * 予約語を識別名として保存しようとした (家系裁定 AG-039 / 不変条件 I11)。
 *
 * ★理由 (3 分類) を必ず載せる。利用者へは「使えない語である」ことだけを返し、
 *   分類そのものは運用・レビューのための情報として例外に残す。
 */
final class ReservedOrganizationSlugException extends DomainException
{
    public function __construct(
        public readonly OrganizationSlug $slug,
        public readonly SlugReservationReason $reason,
    ) {
        parent::__construct("この識別名は使用できません ({$reason->label()}): {$slug->value}");
    }
}
