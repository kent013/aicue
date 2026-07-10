<?php

declare(strict_types=1);

namespace App\Console\Commands\Billing;

use App\Services\Billing\PortalConfigurationSpec;
use Illuminate\Console\Command;
use Laravel\Cashier\Cashier;
use Throwable;

/**
 * Customer Portal Configuration を spec から生成 / 検証する。
 *
 * 既定 (生成): PortalConfigurationSpec から Stripe Billing Portal Configuration を作成し、
 * 設定すべき env (STRIPE_PORTAL_CONFIGURATION_ID) を出力する
 * (SyncStripePrices と同じ「生成と反映の分離」。.env へは自動書込しない)。
 * --verify: 設定済 configuration が spec の核心 (subscription_update 無効) に整合するか検証する。
 *
 * Stripe API を直接叩く provisioning ツールのため CI テスト対象外 (env 未設定の fail-fast のみ検証)。
 */
class EnsurePortalConfiguration extends Command
{
    protected $signature = 'billing:ensure-portal-configuration {--verify : 設定済 configuration が spec に整合するか検証する}';

    protected $description = 'Customer Portal Configuration を spec から生成 / 検証する (subscription_update 無効化)';

    public function handle(): int
    {
        $configId = config('cashier.portal_configuration_id');

        if ($this->option('verify') === true) {
            // Cashier::stripe() は STRIPE_SECRET 未設定だと "api_key cannot be the empty string" で
            // 失敗するため、env 未設定の fail-fast を Stripe クライアント生成より前に行う。
            if (! is_string($configId) || $configId === '') {
                $this->error('STRIPE_PORTAL_CONFIGURATION_ID が未設定です。先に (--verify なしで) 生成してください。');

                return self::FAILURE;
            }

            $stripe = Cashier::stripe();

            try {
                $config = $stripe->billingPortal->configurations->retrieve($configId, []);
            } catch (Throwable $e) {
                $this->error("configuration {$configId} の取得に失敗: {$e->getMessage()}");

                return self::FAILURE;
            }

            $subEnabled = $config->features->subscription_update->enabled ?? null;
            if ($subEnabled !== false) {
                $this->error('drift: subscription_update.enabled が false ではありません。再生成するか Dashboard で修正してください。');

                return self::FAILURE;
            }

            $this->info("OK: {$configId} は subscription_update 無効で spec に整合しています。");

            return self::SUCCESS;
        }

        $stripe = Cashier::stripe();

        try {
            // business_profile (規約・プライバシーポリシー URL) は派生アプリが法務ページ実装後に
            // 追加する (テンプレートは該当ページを持たないため未指定 = Stripe Dashboard 設定に従う)。
            $config = $stripe->billingPortal->configurations->create([
                'features' => PortalConfigurationSpec::features(),
            ]);
        } catch (Throwable $e) {
            $this->error("生成に失敗: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info('Portal Configuration を生成しました (subscription_update 無効)。');
        $this->line("次の env を設定してください: STRIPE_PORTAL_CONFIGURATION_ID={$config->id}");

        return self::SUCCESS;
    }
}
