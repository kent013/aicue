<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Models\Organization;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Organization の name を Stripe customer へ同期する queued job。
 *
 * Cashier 標準 `Laravel\Cashier\Jobs\SyncCustomerDetails` の代替。標準 job は billable を
 * `Billable` (trait) として扱うため Organization 直渡しが PHPStan level 10 で trait-as-type
 * 不一致になる。Organization を明示的に受ける自前 job にすることで型安全に保つ。
 *
 * 本 job の dispatch は `BillingCustomerSynchronizer::dispatchFor()` 1 経路に限定する
 * (IV-2、tests/Architecture/BillingSyncDispatchInvariantTest で機械的に強制)。
 */
final class SyncBillingCustomerDetails implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Organization $organization,
    ) {}

    public function handle(StripeGatewayInterface $gateway): void
    {
        // 同期を StripeGatewayInterface 経由にし、fake 環境 (bug-hunt / Browser) では実 Stripe を
        // 叩かず no-op になるようにする (Cashier 同期を job から直接呼ぶと fake を素通りする)。
        $gateway->syncCustomerDetails($this->organization);
    }
}
