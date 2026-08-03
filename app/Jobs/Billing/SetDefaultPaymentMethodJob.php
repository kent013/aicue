<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Models\Organization;
use App\Services\Billing\AutoRechargeService;
use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * P8a: mode=setup Checkout 完了 (webhook) からの PM default 設定。
 *
 * **外向き Stripe API は webhook 同期処理から Job に退避する**。
 * setup_intent → payment_method 解決 → attach + default 設定
 * (gateway::setDefaultPaymentMethod に一元化) → ticket_auto_recharges の snapshot 更新
 * (+ 有効な事前同意があれば同一 TX で自動有効化)。
 */
final class SetDefaultPaymentMethodJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly int $organizationId,
        public readonly string $setupIntentId,
    ) {}

    public function handle(AutoRechargeGatewayInterface $gateway, AutoRechargeService $autoRecharge): void
    {
        $organization = Organization::query()->find($this->organizationId);
        if (! $organization instanceof Organization) {
            return;
        }

        // Stripe API 接触は全て gateway 境界に集約 (fake 差し替え可能・Job は API を直接触らない)。
        $paymentMethodId = $gateway->resolveSetupIntentPaymentMethod($this->setupIntentId);

        $gateway->setDefaultPaymentMethod($organization, $paymentMethodId);
        $autoRecharge->applySetupCompletion($organization, $paymentMethodId);
    }
}
