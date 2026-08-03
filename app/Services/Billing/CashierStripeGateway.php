<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\CreatedCheckoutSession;
use App\DataTransferObjects\Billing\ExternalBillingRedirect;
use App\Models\Organization;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use Carbon\CarbonImmutable;
use Laravel\Cashier\Cashier;
use Webmozart\Assert\Assert;

/**
 * StripeGatewayInterface の Cashier (Stripe SDK) 実装。
 * PortalConfigurationSpec は同一名前空間 (App\Services\Billing) のため use 不要。
 */
final class CashierStripeGateway implements StripeGatewayInterface
{
    public function createSubscriptionCheckout(
        Organization $organization,
        string $stripePriceId,
        string $successUrl,
        string $cancelUrl,
        array $metadata,
        string $idempotencyKey,
    ): CreatedCheckoutSession {
        // Cashier の `newSubscription()->checkout()` は最終的に request options 無しで
        // `checkout->sessions->create()` を呼ぶため per-request idempotency key を伝播できない。
        // 冪等キーを Stripe Checkout 作成 API へ確実に渡すため SDK を直叩きする
        // (CashierTicketCheckoutGateway と同型)。
        $organization->createOrGetStripeCustomer();

        $session = $organization->stripe()->checkout->sessions->create(
            $this->buildSubscriptionSessionPayload($organization, $stripePriceId, $successUrl, $cancelUrl, $metadata),
            ['idempotency_key' => $idempotencyKey],
        );

        // hosted mode では url / expires_at が常に返る (欠落は SDK/設定異常として fail-fast)
        Assert::string($session->url, 'Checkout Session に URL がありません (ui_mode: hosted のみ対応)');
        Assert::integer($session->expires_at, 'Checkout Session に expires_at がありません');

        return new CreatedCheckoutSession(
            sessionId: $session->id,
            url: $session->url,
            expiresAt: CarbonImmutable::createFromTimestamp($session->expires_at),
        );
    }

    public function expireCheckoutSession(string $stripeSessionId): string
    {
        // 決済主体は organization だが expire は session id 単独で完結する
        // (呼び出し側が自 org 行の session id のみ渡す契約)
        $session = Cashier::stripe()->checkout->sessions->expire($stripeSessionId);

        return is_string($session->status) ? $session->status : 'expired';
    }

    /**
     * subscription Checkout Session payload (pure)。
     *
     * invariant (gateway ユニットテストで固定):
     * - `subscription_data.metadata.{name,type} = 'default'` — Cashier の WebhookController が
     *   `subscriptions` 行を作る際に読むラベル。**落とすと課金成立なのに subscription 行が
     *   作られず** `BillingAccess::state()` が NoSubscription に落ちて締め出しが起きる。
     * - `subscription_data.payment_settings.save_default_payment_method = 'on_subscription'` —
     *   T1004 の PM 流用の第一候補 (`subscription.default_payment_method`) が埋まる前提。
     *
     * @param  array<string, string>  $metadata
     * @return array{
     *   mode: 'subscription',
     *   customer: string,
     *   line_items: array{array{price: string, quantity: int}},
     *   success_url: string,
     *   cancel_url: string,
     *   metadata: array<string, string>,
     *   subscription_data: array{
     *     metadata: array<string, string>,
     *     payment_settings: array{save_default_payment_method: 'on_subscription'}
     *   }
     * }
     */
    public function buildSubscriptionSessionPayload(
        Organization $organization,
        string $stripePriceId,
        string $successUrl,
        string $cancelUrl,
        array $metadata,
    ): array {
        // createOrGetStripeCustomer() 後は必ず存在する (欠落は設定異常として fail-fast)
        $customerId = $organization->stripe_id;
        Assert::stringNotEmpty($customerId, 'Stripe customer 未作成の組織では Checkout を作れません');

        return [
            'mode' => 'subscription',
            'customer' => $customerId,
            'line_items' => [
                [
                    'price' => $stripePriceId,
                    'quantity' => 1,
                ],
            ],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => $metadata,
            'subscription_data' => [
                'metadata' => [
                    'name' => 'default',
                    'type' => 'default',
                ],
                'payment_settings' => [
                    'save_default_payment_method' => 'on_subscription',
                ],
            ],
        ];
    }

    public function createPortalSession(Organization $organization, string $returnUrl): ExternalBillingRedirect
    {
        // configuration id (billing:ensure-portal-configuration で生成) が設定されていれば
        // subscription_update 無効の spec 準拠 configuration で portal session を作る
        // (未設定なら Dashboard 既定 configuration。PortalConfigurationSpec 参照)
        return new ExternalBillingRedirect($organization->billingPortalUrl(
            $returnUrl,
            PortalConfigurationSpec::sessionOptions(config('cashier.portal_configuration_id')),
        ));
    }

    public function syncCustomerDetails(Organization $organization): void
    {
        // 実 Stripe では Cashier の Billable 同期をそのまま使う。stripe_id 未設定は no-op
        // (Cashier 側も customer 不在では更新しないが、呼び出し前提を実装側でも明示)。
        if ($organization->stripe_id === null) {
            return;
        }

        $organization->syncStripeCustomerDetails();
    }
}
