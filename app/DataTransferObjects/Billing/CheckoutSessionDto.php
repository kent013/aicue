<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

/**
 * P9: 冪等 checkout マシン (`SubscriptionService::startCheckout`) の戻り値。
 *
 * `url === null` は「新規 Checkout を作らなかった」を意味する:
 *  - Completed 行の replay (= 既に受付済み)
 *  - 同 plan の live pending dedup (= 進行中の Checkout がある)
 * どちらかは Controller が `stripe_session_id` の行 status で判別する。
 *
 * @phpstan-type CheckoutSessionShape array{
 *   stripeSessionId: string,
 *   url: string|null,
 *   intent: string,
 *   planCode: string|null
 * }
 */
final readonly class CheckoutSessionDto
{
    public function __construct(
        public string $stripeSessionId,
        public ?string $url,
        public string $intent,
        public ?string $planCode,
    ) {}

    /**
     * @return CheckoutSessionShape
     */
    public function toArray(): array
    {
        return [
            'stripeSessionId' => $this->stripeSessionId,
            'url' => $this->url,
            'intent' => $this->intent,
            'planCode' => $this->planCode,
        ];
    }
}
