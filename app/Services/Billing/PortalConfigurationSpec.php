<?php

declare(strict_types=1);

namespace App\Services\Billing;

/**
 * Customer Portal Configuration の許可機能ポリシー (コード上の固定真実源)。
 *
 * 核心: subscription_update を無効化し、Portal からの out-of-band プラン変更を構造的に封じる。
 * プラン変更はアプリ側 (Checkout / Subscription Schedule) が所有しており、Portal で直接変更
 * されると plan_code / schedule 整合が壊れるため。env はこの spec から生成された
 * configuration id を保持するのみで、ポリシー切替先ではない。
 *
 * 公式 API ref: POST /v1/billing_portal/configurations の features 集合に対応。
 *
 * @phpstan-type PortalConfigurationFeatures array{
 *     subscription_update: array{enabled: bool},
 *     subscription_cancel: array{enabled: bool, mode?: string},
 *     payment_method_update: array{enabled: bool},
 *     invoice_history: array{enabled: bool},
 *     customer_update: array{enabled: bool, allowed_updates: list<string>},
 * }
 */
final class PortalConfigurationSpec
{
    /** @var list<string> */
    public const ALLOWED_CUSTOMER_UPDATES = ['address', 'email', 'name', 'phone'];

    /**
     * Stripe billing_portal/configurations create / update に渡す features 配列を返す。
     *
     * @return PortalConfigurationFeatures
     */
    public static function features(): array
    {
        return [
            'subscription_update' => ['enabled' => false],
            'subscription_cancel' => ['enabled' => true, 'mode' => 'at_period_end'],
            'payment_method_update' => ['enabled' => true],
            'invoice_history' => ['enabled' => true],
            'customer_update' => [
                'enabled' => true,
                'allowed_updates' => self::ALLOWED_CUSTOMER_UPDATES,
            ],
        ];
    }

    /**
     * portal セッション生成に渡す Stripe オプション。configuration id が非空文字列なら指定し、
     * 未設定なら空配列 (Dashboard 既定 configuration) を返す。
     *
     * @return array<string, mixed>
     */
    public static function sessionOptions(mixed $configurationId): array
    {
        return is_string($configurationId) && $configurationId !== ''
            ? ['configuration' => $configurationId]
            : [];
    }
}
