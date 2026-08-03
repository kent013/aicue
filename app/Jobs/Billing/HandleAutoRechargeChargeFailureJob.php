<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Enums\Billing\AutoRechargeAttemptStatus;
use App\Models\Billing\TicketAutoRechargeAttempt;
use App\Models\Organization;
use App\Services\Billing\AutoRechargeService;
use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Webmozart\Assert\Assert;

/**
 * P8a: invoice.payment_failed (webhook) からの失敗振り分け。
 *
 * SCA 判定は local failure_code に依存せず Stripe 側 PaymentIntent 状態 (gateway の
 * invoice state) を出典にする — webhook が同期 pay の記録より先に来ても SCA を誤終端しない。
 * **外向き Stripe API は webhook 同期処理から Job へ退避する**。
 *
 * $tries = 1: 取りこぼしはリコンサイル (iii)/(iv) が同じ判定で回収する。
 */
final class HandleAutoRechargeChargeFailureJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $attemptId) {}

    public function handle(AutoRechargeGatewayInterface $gateway, AutoRechargeService $autoRecharge): void
    {
        $attempt = TicketAutoRechargeAttempt::query()->find($this->attemptId);
        if (! $attempt instanceof TicketAutoRechargeAttempt) {
            return;
        }
        if ($attempt->status !== AutoRechargeAttemptStatus::Pending) {
            return;
        }

        $organization = $attempt->organization;
        Assert::isInstanceOf($organization, Organization::class);

        $invoiceId = $attempt->stripe_invoice_id;
        if ($invoiceId === null) {
            return; // invoice 未作成の failed イベントはあり得ない (防御的 no-op)
        }

        $state = $gateway->retrieveInvoiceState($invoiceId);

        if ($state->status === 'paid') {
            // 順序逆転 (failed → 実は復旧して paid): 冪等付与で収束。
            $amountPaid = $state->amountPaid;
            $amountDue = $state->amountDue;
            Assert::integer($amountPaid);
            Assert::integer($amountDue);
            $autoRecharge->recordSuccessfulCharge($organization, $attempt, $invoiceId, $amountPaid, $amountDue, $state->paymentIntentId);

            return;
        }

        $autoRecharge->handleChargeFailure(
            $organization,
            $attempt,
            $attempt->failure_code ?? 'payment_failed',
            $state->requiresAction,
        );
    }
}
