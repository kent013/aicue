<?php

declare(strict_types=1);

use App\Services\Billing\PortalConfigurationSpec;

/*
 * Customer Portal Configuration の spec (コード上の固定真実源)。
 * 核心は subscription_update 無効化 (Portal からの out-of-band プラン変更を構造的に封じる)。
 * Stripe API を叩く生成/検証は billing:ensure-portal-configuration (CI 対象外) で行い、
 * ここでは spec と fail-fast のみ検証する。
 */

test('features は subscription_update 無効 + 自己管理系 (解約/支払い方法/請求履歴) 許可', function (): void {
    expect(PortalConfigurationSpec::features())->toBe([
        'subscription_update' => ['enabled' => false],
        'subscription_cancel' => ['enabled' => true, 'mode' => 'at_period_end'],
        'payment_method_update' => ['enabled' => true],
        'invoice_history' => ['enabled' => true],
        'customer_update' => [
            'enabled' => true,
            'allowed_updates' => ['address', 'email', 'name', 'phone'],
        ],
    ]);
});

test('sessionOptions は configuration id が非空文字列のときのみ指定する', function (): void {
    expect(PortalConfigurationSpec::sessionOptions('bpc_test_1'))->toBe(['configuration' => 'bpc_test_1'])
        ->and(PortalConfigurationSpec::sessionOptions(''))->toBe([])
        ->and(PortalConfigurationSpec::sessionOptions(null))->toBe([]);
});

test('ensure-portal-configuration --verify は configuration id 未設定なら fail-fast する', function (): void {
    config(['cashier.portal_configuration_id' => null]);

    $this->artisan('billing:ensure-portal-configuration', ['--verify' => true])
        ->expectsOutputToContain('STRIPE_PORTAL_CONFIGURATION_ID が未設定です')
        ->assertFailed();
});
