<?php

declare(strict_types=1);

namespace Tests\Support\JobDedup;

use App\Enums\Security\JobDedupExemption;
use Webmozart\Assert\Assert;

/** 「重複実行の保証を持たないことが正しい」と裁定されたジョブの目録エントリ。 */
final readonly class ExemptionEntry
{
    /** @param non-empty-string $rationale 30 文字以上 */
    public function __construct(
        public JobDedupExemption $exemption,
        public string $rationale,
    ) {
        Assert::greaterThanEq(mb_strlen($rationale), 30, '免除の根拠は 30 文字以上で書くこと');
    }
}
