<?php

declare(strict_types=1);

namespace App\Services\Billing;

use Carbon\CarbonImmutable;

/**
 * Stripe サブスクリプションの値オブジェクト。Webhook ハンドラから SubscriptionService に渡す。
 *
 * T666 (C2): schedule ライフサイクル状態 (`stripe_schedule_id` / `schedule_setup_status`) は
 * ここに含めない。これらは Stripe subscription object に存在しない / 順序逆転 webhook で
 * 破壊的なドメインローカル状態であり、書込権威は SubscriptionService の schedule lifecycle
 * メソッド + ReconcileSubscriptionSchedules に限定する。汎用 webhook 同期
 * (`applySubscriptionSnapshot`) はこれらを触らない。
 */
final readonly class SubscriptionSnapshot
{
    public function __construct(
        public string $stripeId,
        public string $status,
        public ?string $basePriceId,
        public ?int $baseQuantity,
        public ?CarbonImmutable $currentPeriodEnd,
        public ?CarbonImmutable $trialEndsAt,
        public ?CarbonImmutable $endsAt,
    ) {}
}
