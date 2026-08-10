<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use App\Enums\Billing\TicketLedgerKind;
use App\Enums\Billing\TicketSource;
use App\Models\Billing\TicketLedgerEntry;
use App\Models\Organization;
use App\Services\Billing\TicketLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 台帳エントリ (残高の真実源) の fixture。
 *
 * 既定は purchased バケットの無期限付与 (+1)。保持期間の畳み込み (PR-C2) の検証で
 * 「7 年より古い取引行」を任意の出所・失効時刻で並べるために使う。
 *
 * ★台帳は append-only (update / delete が Model イベントで例外化されている)。
 *   factory は insert しか行わないため不変条件に触れない。
 *
 * @extends Factory<TicketLedgerEntry>
 */
class TicketLedgerEntryFactory extends Factory
{
    protected $model = TicketLedgerEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'delta' => 1,
            'kind' => TicketLedgerKind::Grant,
            'source' => TicketSource::Purchased,
            'description' => 'テスト付与',
            'granted_at' => CarbonImmutable::now(),
            'expires_at' => null,
            'created_at' => CarbonImmutable::now(),
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (): array => ['organization_id' => $organization->getKey()]);
    }

    /** 取引成立日時 (保持期間の起算点)。 */
    public function createdAt(CarbonImmutable $createdAt): static
    {
        return $this->state(fn (): array => ['created_at' => $createdAt]);
    }

    /** monthly バケットの期限付き付与。 */
    public function monthly(?CarbonImmutable $expiresAt): static
    {
        return $this->state(fn (): array => [
            'source' => TicketSource::Monthly,
            'expires_at' => $expiresAt,
        ]);
    }

    /** purchased バケット (無期限)。 */
    public function purchased(): static
    {
        return $this->state(fn (): array => [
            'source' => TicketSource::Purchased,
            'expires_at' => null,
        ]);
    }

    /**
     * P5 以前の出所を持たない行 (`source = null`)。
     *
     * **表示残高の集計では purchased バケットに含まれる**が
     * ({@see TicketLedgerService::sumBalance()})、
     * **保持期間の畳み込みでは purchased へ寄せず独立した group として扱う**
     * (寄せると `sumActiveHolds` の legacy 除外規則と意味がズレる)。
     */
    public function legacy(): static
    {
        return $this->state(fn (): array => ['source' => null]);
    }

    /** 消費行 (負 delta)。消費した grant と同じ失効時刻を載せる。 */
    public function consumed(int $amount, ?CarbonImmutable $expiresAt = null): static
    {
        return $this->state(fn (): array => [
            'delta' => -$amount,
            'kind' => TicketLedgerKind::ReserveCommit,
            'granted_at' => null,
            'expires_at' => $expiresAt,
        ]);
    }

    /** 枚数 (正: 付与 / 負: 消費)。 */
    public function delta(int $delta): static
    {
        return $this->state(fn (): array => ['delta' => $delta]);
    }

    /** 冪等キー (二重付与防止キー) を持つ行。 */
    public function idempotencyKey(string $key): static
    {
        return $this->state(fn (): array => ['idempotency_key' => $key]);
    }
}
