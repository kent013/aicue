<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use App\Enums\Billing\PersonalPlanIneligibleReason;
use RuntimeException;
use Throwable;

/**
 * Personal (free) プランの有効化条件を満たさない。
 *
 * Controller 層で ValidationException (422) に変換する (500 にしない)。
 */
final class PersonalPlanNotEligibleException extends RuntimeException
{
    public function __construct(
        public readonly PersonalPlanIneligibleReason $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct('personal plan not eligible: '.$reason->value, 0, $previous);
    }

    public function userMessage(): string
    {
        return $this->reason->label();
    }
}
