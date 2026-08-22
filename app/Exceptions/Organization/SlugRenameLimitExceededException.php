<?php

declare(strict_types=1);

namespace App\Exceptions\Organization;

use Carbon\CarbonImmutable;
use DomainException;

/**
 * 識別名の改名回数が窓 (30 日) の上限に達した (家系裁定 AG-046 / 不変条件 I12)。
 *
 * ★`$nextAvailableAt` は「窓内で最も古い履歴 + 30 日」。窓の境界は**含まない**
 *   (`renamed_at > now - 30 日`) ので、この時刻**ちょうど**で実際に改名できる
 *   (案内と挙動が一致する)。
 */
final class SlugRenameLimitExceededException extends DomainException
{
    public function __construct(public readonly CarbonImmutable $nextAvailableAt)
    {
        parent::__construct(
            '識別名の変更は 30 日あたり 5 回までです。次に変更できるのは '
            .$nextAvailableAt->toDateTimeString().' 以降です。',
        );
    }
}
