<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Services\Billing\CashierStripeGateway;

/*
 * P9: subscription Checkout Session payload の invariant。**payload 変更の唯一の入口**。
 *
 * - subscription_data.metadata.{name,type} = 'default'
 *   Cashier の WebhookController が `subscriptions` 行を作る際に読むラベル。落とすと
 *   **課金成立なのに subscription 行が作られず** BillingAccess::state() が NoSubscription に
 *   落ちて締め出しが起きる (P4 のゲート反転後は致命的)。
 * - subscription_data.payment_settings.save_default_payment_method = 'on_subscription'
 *   T1004 の PM 流用の第一候補 (subscription.default_payment_method) が埋まる前提。
 * - promo / automatic tax を含まない (金額照合の前提を壊さない = チケット側と同一方針)。
 */

test('payload は mode=subscription で customer / line_items / metadata を含む', function (): void {
    $organization = Organization::factory()->make();
    $organization->stripe_id = 'cus_payload_1';

    $payload = (new CashierStripeGateway)->buildSubscriptionSessionPayload(
        $organization,
        'price_standard',
        'https://app.test/billing?session_id={CHECKOUT_SESSION_ID}',
        'https://app.test/billing/plans',
        ['purpose' => 'subscription_start', 'org_ref' => '1', 'plan_code' => 'standard'],
    );

    expect($payload['mode'])->toBe('subscription');
    expect($payload['customer'])->toBe('cus_payload_1');
    expect($payload['line_items'])->toBe([['price' => 'price_standard', 'quantity' => 1]]);
    expect($payload['success_url'])->toContain('{CHECKOUT_SESSION_ID}');
    expect($payload['cancel_url'])->toBe('https://app.test/billing/plans');
    expect($payload['metadata']['purpose'])->toBe('subscription_start');
});

test('subscription_data は Cashier の name/type ラベルと save_default_payment_method を含む', function (): void {
    $organization = Organization::factory()->make();
    $organization->stripe_id = 'cus_payload_1';

    $payload = (new CashierStripeGateway)->buildSubscriptionSessionPayload(
        $organization, 'price_standard', 'https://a.test', 'https://b.test', [],
    );

    expect($payload['subscription_data']['metadata']['name'])->toBe('default');
    expect($payload['subscription_data']['metadata']['type'])->toBe('default');
    expect($payload['subscription_data']['payment_settings']['save_default_payment_method'])
        ->toBe('on_subscription');
});

test('payload に allow_promotion_codes / automatic_tax を含めない', function (): void {
    $organization = Organization::factory()->make();
    $organization->stripe_id = 'cus_payload_1';

    $payload = (new CashierStripeGateway)->buildSubscriptionSessionPayload(
        $organization, 'price_standard', 'https://a.test', 'https://b.test', [],
    );

    expect($payload)->not->toHaveKey('allow_promotion_codes');
    expect($payload)->not->toHaveKey('automatic_tax');
});

test('Stripe customer 未作成の組織では fail-fast する', function (): void {
    (new CashierStripeGateway)->buildSubscriptionSessionPayload(
        Organization::factory()->make(), 'price_standard', 'https://a.test', 'https://b.test', [],
    );
})->throws(InvalidArgumentException::class);
