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
use Illuminate\Support\Facades\Log;

/**
 * P9 (T1004): mode=subscription Checkout 完了 (funding=auto_recharge) からの
 * サブスク決済カード流用。
 *
 * webhook 同期処理から**外向き Stripe API を撃たない** invariant のため Job へ退避する:
 * PM 解決 (gateway) → `AutoRechargeService::applyReusedPaymentMethod`
 * (適格性先行 fail-closed — 同意なし・失効・停止状態では customer default PM にも
 * ローカル snapshot にも一切触れない)。
 *
 * Model 参照は保持しない (id のみ) = 遅延実行中の stale snapshot を作らない。
 */
final class ReuseSubscriptionPaymentMethodJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly int $organizationId,
        public readonly string $stripeSubscriptionId,
    ) {}

    public function handle(AutoRechargeGatewayInterface $gateway, AutoRechargeService $autoRecharge): void
    {
        $org = Organization::query()->find($this->organizationId);
        if (! $org instanceof Organization) {
            return;
        }

        // 軽量 guard: webhook 再送等で明らかに no-op のとき (enabled 済み・同意なし・失効・停止)、
        // Stripe retrieve より前に return する (不要な外部 API 呼び出しの排除)。
        if (! $autoRecharge->isAutoEnablePending($org)) {
            return;
        }

        $paymentMethodId = $gateway->resolveSubscriptionPaymentMethod($this->stripeSubscriptionId);
        if ($paymentMethodId === null) {
            // PM 解決不能でも詰まない (請求ページのカード登録 CTA で回復できる)。
            // ログには org id / subscription id のみ出す (PM・customer 情報は出さない)。
            Log::warning('auto-recharge: subscription PM unresolved, skipping reuse', [
                'organization_id' => $this->organizationId,
                'stripe_subscription_id' => $this->stripeSubscriptionId,
            ]);

            return;
        }

        $autoRecharge->applyReusedPaymentMethod($org, $paymentMethodId);
    }
}
