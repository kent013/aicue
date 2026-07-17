<?php

declare(strict_types=1);

namespace App\Enums;

enum PlanCode: string
{
    case Personal = 'personal';
    case Starter = 'starter';
    case Standard = 'standard';
    case Business = 'business';
    case Enterprise = 'enterprise';

    /**
     * Stripe Checkout (サブスク契約) の対象プランか。
     * Personal は free (サブスクなし・PersonalPlanService::activate で有効化)、
     * Enterprise はお問い合わせ営業のため、どちらも Stripe checkout を通らない。
     */
    public function requiresStripeCheckout(): bool
    {
        return match ($this) {
            self::Starter, self::Standard, self::Business => true,
            self::Personal, self::Enterprise => false,
        };
    }
}
