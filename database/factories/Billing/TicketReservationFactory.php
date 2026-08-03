<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use App\Enums\Billing\TicketReservationStatus;
use App\Enums\Billing\TicketSource;
use App\Models\Billing\TicketReservation;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 既定は purchased 消費の live な Reserved 予約 (TTL 未来)。
 * legacy() で P5 デプロイ前の in-flight 予約 (consume_* = null) を、
 * monthlyHold() / purchasedHold() で消費出所を、stale() で TTL 切れを作る。
 *
 * @extends Factory<TicketReservation>
 */
class TicketReservationFactory extends Factory
{
    protected $model = TicketReservation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'amount' => 1,
            'status' => TicketReservationStatus::Reserved,
            'expires_at' => CarbonImmutable::now()->addMinutes(30),
            'consume_source' => TicketSource::Purchased,
            'consume_expires_at' => null,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (): array => ['organization_id' => $organization->getKey()]);
    }

    /** P5 デプロイ前の in-flight 予約 (consume_source / consume_expires_at とも null)。 */
    public function legacy(): static
    {
        return $this->state(fn (): array => [
            'consume_source' => null,
            'consume_expires_at' => null,
        ]);
    }

    /** monthly バケットからの消費予約。$consumeExpiresAt = null は無期限 monthly。 */
    public function monthlyHold(?CarbonImmutable $consumeExpiresAt = null): static
    {
        return $this->state(fn (): array => [
            'consume_source' => TicketSource::Monthly,
            'consume_expires_at' => $consumeExpiresAt,
        ]);
    }

    /** purchased バケット (無期限) からの消費予約。 */
    public function purchasedHold(): static
    {
        return $this->state(fn (): array => [
            'consume_source' => TicketSource::Purchased,
            'consume_expires_at' => null,
        ]);
    }

    /** TTL 切れ (status は reserved のまま expires_at が過去) の予約。 */
    public function stale(): static
    {
        return $this->state(fn (): array => [
            'status' => TicketReservationStatus::Reserved,
            'expires_at' => CarbonImmutable::now()->subMinutes(31),
        ]);
    }
}
