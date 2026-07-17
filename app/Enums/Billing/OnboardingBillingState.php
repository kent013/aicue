<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * Organization の課金状態 (流入制御目線)。
 *
 * SubscriptionState (= subscription model 派生) とは別レイヤー。
 * middleware は本 enum で gate 判定する。
 *
 * ActiveFreePlan = Stripe サブスクなしの free entitlement (Personal free)。
 * Subscribed は「Stripe subscription が entitled」の意味を維持する
 * (= 「Subscribed ⇒ サブスク行が存在する」という既存コードの仮定を壊さない)。
 */
enum OnboardingBillingState: string
{
    case NoSubscription = 'no_subscription';
    case PendingCheckout = 'pending_checkout';
    case ExpiredCheckout = 'expired_checkout';
    case Subscribed = 'subscribed';
    case ActiveFreePlan = 'active_free_plan';

    public function grantsAccess(): bool
    {
        return $this === self::Subscribed || $this === self::ActiveFreePlan;
    }
}
