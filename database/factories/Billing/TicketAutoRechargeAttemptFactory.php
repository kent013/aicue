<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use App\Enums\Billing\AutoRechargeAttemptStatus;
use App\Models\Billing\TicketAutoRechargeAttempt;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * 既定は pending (invoice 未作成) の試行行。
 *
 * @extends Factory<TicketAutoRechargeAttempt>
 */
class TicketAutoRechargeAttemptFactory extends Factory
{
    protected $model = TicketAutoRechargeAttempt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attempt_ulid' => strtolower((string) Str::ulid()),
            'organization_id' => Organization::factory(),
            'status' => AutoRechargeAttemptStatus::Pending,
            'quantity' => 45,
            'unit_amount' => 80,
            'stripe_price_id' => 'price_'.Str::random(24),
            'stripe_invoice_id' => null,
            'stripe_payment_intent_id' => null,
            'failure_code' => null,
            'resolved_at' => null,
        ];
    }

    /** invoice 作成済み (pay 前 / webhook 待ち) の pending。 */
    public function withInvoice(?string $invoiceId = null): static
    {
        return $this->state(fn (): array => [
            'stripe_invoice_id' => $invoiceId ?? 'in_'.Str::random(24),
        ]);
    }

    public function paid(): static
    {
        return $this->withInvoice()->state(fn (): array => [
            'status' => AutoRechargeAttemptStatus::Paid,
            'stripe_payment_intent_id' => 'pi_'.Str::random(24),
            'resolved_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->withInvoice()->state(fn (): array => [
            'status' => AutoRechargeAttemptStatus::Failed,
            'failure_code' => 'card_declined',
            'resolved_at' => now(),
        ]);
    }

    public function canceled(): static
    {
        return $this->withInvoice()->state(fn (): array => [
            'status' => AutoRechargeAttemptStatus::Canceled,
            'resolved_at' => now(),
        ]);
    }
}
