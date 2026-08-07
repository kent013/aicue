<?php

declare(strict_types=1);

namespace Tests\Support\Billing;

use App\Enums\Security\GatewayFailureObservationExemption;
use Webmozart\Assert\Assert;

/** 「決済 gateway 例外を観測しないことが正しい」と裁定されたクラスの目録エントリ。 */
final readonly class GatewayObservationExemptionEntry
{
    /** @param non-empty-string $rationale 30 文字以上 */
    public function __construct(
        public GatewayFailureObservationExemption $exemption,
        public string $rationale,
    ) {
        Assert::greaterThanEq(mb_strlen($rationale), 30, '免除の根拠は 30 文字以上で書くこと');
    }
}
