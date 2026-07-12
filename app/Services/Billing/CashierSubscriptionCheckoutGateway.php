<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\ExternalBillingRedirect;
use App\Models\Organization;
use Webmozart\Assert\Assert;

/**
 * SubscriptionCheckoutGateway の Cashier (Stripe SDK) 実装。
 * ロジックは BillingController から移動 (挙動不変)。
 * PortalConfigurationSpec は同一名前空間 (App\Services\Billing) のため use 不要。
 */
final class CashierSubscriptionCheckoutGateway implements SubscriptionCheckoutGateway
{
    public function createSubscriptionCheckout(
        Organization $organization,
        string $stripePriceId,
        string $successUrl,
        string $cancelUrl,
    ): ExternalBillingRedirect {
        $checkout = $organization
            ->newSubscription('default', $stripePriceId)
            ->checkout([
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
            ]);

        $url = $checkout->asStripeCheckoutSession()->url;
        Assert::string($url, 'Checkout Session に URL がありません (ui_mode: hosted のみ対応)');

        return new ExternalBillingRedirect($url);
    }

    public function portalRedirect(Organization $organization, string $returnUrl): ExternalBillingRedirect
    {
        // configuration id (billing:ensure-portal-configuration で生成) が設定されていれば
        // subscription_update 無効の spec 準拠 configuration で portal session を作る
        // (未設定なら Dashboard 既定 configuration。PortalConfigurationSpec 参照)
        return new ExternalBillingRedirect($organization->billingPortalUrl(
            $returnUrl,
            PortalConfigurationSpec::sessionOptions(config('cashier.portal_configuration_id')),
        ));
    }
}
