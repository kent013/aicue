<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Services\Billing\Fakes\FakeStripeGateway;

/*
 * runtime fake (App\Services\Billing\Fakes\FakeStripeGateway) の不変条件:
 * - checkout / portal とも「中立帰還」(遷移先ベース URL + 観測用 marker `fake_external=stripe`)
 * - syncCustomerDetails は no-op (fake 環境が実 Stripe API を叩かない規約)
 */

test('checkout は cancel URL ベースの中立帰還 URL を返す', function (): void {
    $redirect = (new FakeStripeGateway)->createSubscriptionCheckout(
        Organization::factory()->make(),
        'price_test',
        'https://app.test/billing?success=1',
        'https://app.test/billing',
    );

    expect($redirect->url)->toContain('https://app.test/billing')
        ->and($redirect->url)->toContain('fake_external=stripe');
});

test('portal は return URL ベースの中立帰還 URL を返す', function (): void {
    $redirect = (new FakeStripeGateway)->createPortalSession(
        Organization::factory()->make(),
        'https://app.test/billing',
    );

    expect($redirect->url)->toContain('https://app.test/billing')
        ->and($redirect->url)->toContain('fake_external=stripe');
});

test('syncCustomerDetails は no-op (実 Stripe を叩かない)', function (): void {
    // stripe_id を持つ組織でも Stripe API 呼び出しが起きず完走する
    $organization = Organization::factory()->make(['name' => 'テスト組織']);
    $organization->stripe_id = 'cus_fake_1';

    (new FakeStripeGateway)->syncCustomerDetails($organization);
})->throwsNoExceptions();
