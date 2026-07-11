<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use App\Enums\Billing\TicketCheckoutSessionStatus;
use App\Models\Billing\TicketCheckoutSession;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * 既定は live pending (決済待ち・expires_at 未来) の追跡行。
 * completed() / expired() / stale() state で状態遷移後の行を作る。
 *
 * @extends Factory<TicketCheckoutSession>
 */
class TicketCheckoutSessionFactory extends Factory
{
    protected $model = TicketCheckoutSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sessionId = 'cs_test_'.Str::random(24);

        return [
            'organization_id' => Organization::factory(),
            'initiated_by_user_id' => User::factory(),
            'ticket_count' => 10,
            'unit_amount' => 100,
            'currency' => 'jpy',
            'stripe_session_id' => $sessionId,
            'attempt_token' => (string) Str::ulid(),
            'checkout_url' => "https://checkout.stripe.test/c/pay/{$sessionId}",
            'status' => TicketCheckoutSessionStatus::Pending,
            'expires_at' => CarbonImmutable::now()->addDay(),
            'completed_at' => null,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (): array => ['organization_id' => $organization->getKey()]);
    }

    public function initiatedBy(User $user): static
    {
        return $this->state(fn (): array => ['initiated_by_user_id' => $user->getKey()]);
    }

    /** 付与済み (completed) の行。 */
    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => TicketCheckoutSessionStatus::Completed,
            'completed_at' => CarbonImmutable::now(),
        ]);
    }

    /** 明示 expire 済みの行。 */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => TicketCheckoutSessionStatus::Expired,
        ]);
    }

    /** 期限切れ pending (status は pending のまま expires_at が過去) の行。 */
    public function stale(): static
    {
        return $this->state(fn (): array => [
            'status' => TicketCheckoutSessionStatus::Pending,
            'expires_at' => CarbonImmutable::now()->subHour(),
        ]);
    }
}
