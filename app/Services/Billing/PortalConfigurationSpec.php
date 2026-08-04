<?php

declare(strict_types=1);

namespace App\Services\Billing;

/**
 * Customer Portal Configuration の許可機能ポリシー (コード上の固定真実源)。
 *
 * 核心: subscription_update を無効化し、Portal からの out-of-band プラン変更を構造的に封じる。
 * プラン変更はアプリ側 (新規契約 = Checkout / 契約中の変更 = `SubscriptionService::changePlan`)
 * が所有しており、Portal で直接変更されると plan_code / schedule 整合が壊れるため。
 * env はこの spec から生成された
 * configuration id を保持するのみで、ポリシー切替先ではない。
 *
 * **この方針は確定済み** (T090 で `SubscriptionService::changePlan` が実装され、
 * 「プラン変更はアプリが所有する」という本 spec の宣言が実装で満たされた)。
 * `subscription_update` を再開放するには boolean の反転では足りず、以下が必要:
 *  1. Stripe の `subscription_update` 有効化は `products: [{product, prices: [...]}]` の
 *     列挙が必須だが、AI-CUE は **Stripe product id をどこにも保持していない**
 *     (`plan_prices` の列は stripe_price_id / lookup_key / amount / currency / is_current)
 *  2. 列挙の鮮度を保つ機構が無い。価格改定 (`PlanPriceService::replaceCurrent()`) と
 *     `plans.is_active=false` (販売停止) は Portal 列挙に効かないため、
 *     **旧価格・販売停止プランへ Portal から移行できてしまう**
 *  3. 変更可否の理由 (past_due / schedule 管理下 / downgrade の上限低下) を
 *     Stripe hosted 画面に載せられない (禁止事項 #8「押下時に理由を出す」と噛み合わない)
 * 反転する場合はこの 3 点を満たす設計を先に立てること。
 * **検証責務**: 現在の drift 検知は `billing:ensure-portal-configuration --verify` が
 * `subscription_update.enabled === false` を確認するのみで、products/prices 列挙の検証は無い。
 * 再開放するなら **verify 側に列挙の検証を足すところまでが同一作業**である。
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
