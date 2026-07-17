<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

use Webmozart\Assert\Assert;

/**
 * 課金系外部ページ (Stripe Checkout / Customer Portal) への遷移先。
 *
 * gateway (Contracts\StripeGatewayInterface) の戻り値契約。Response 化
 * (Inertia::location) は Controller の責務で、gateway は URL のみ返す。
 */
final readonly class ExternalBillingRedirect
{
    public function __construct(
        public string $url,
    ) {
        Assert::stringNotEmpty($url, '外部遷移先 URL が空です');
    }
}
