<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use App\Enums\Billing\AutoRechargeDisabledReason;
use App\Models\Billing\TicketAutoRecharge;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * 既定は「行はあるが off」= opt-in 未有効の設定行。
 *
 * @extends Factory<TicketAutoRecharge>
 */
class TicketAutoRechargeFactory extends Factory
{
    protected $model = TicketAutoRecharge::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'enabled' => false,
            'threshold_count' => config()->integer('billing.auto_recharge.default_threshold'),
            'max_count' => config()->integer('billing.auto_recharge.default_max'),
            'stripe_payment_method_id' => null,
            'failure_count' => 0,
            'disabled_reason' => null,
            'consented_at' => null,
            'consent_version' => null,
            'consented_max_count' => null,
            'consented_max_amount' => null,
            'created_by_user_id' => null,
        ];
    }

    /** 有効化済み (PM 保存 + 同意記録済み) 状態。 */
    public function enabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'enabled' => true,
            'stripe_payment_method_id' => 'pm_'.Str::random(24),
            'consented_at' => now(),
            'consent_version' => config()->string('billing.auto_recharge.consent_version'),
            'consented_max_count' => $attributes['max_count'] ?? config()->integer('billing.auto_recharge.default_max'),
            // 上限額は「テストが価格改定で壊れない」よう十分大きく取る
            // (価格改定シナリオは consentedMaxAmount() で明示的に絞る)。
            'consented_max_amount' => PHP_INT_MAX >> 32,
        ]);
    }

    /** 事前同意のみ記録済み (enabled=false / PM 未登録) = pendingAutoEnable 状態。 */
    public function preConsented(): static
    {
        return $this->state(fn (array $attributes): array => [
            'enabled' => false,
            'stripe_payment_method_id' => null,
            'disabled_reason' => null,
            'consented_at' => now(),
            'consent_version' => config()->string('billing.auto_recharge.consent_version'),
            'consented_max_count' => $attributes['max_count'] ?? config()->integer('billing.auto_recharge.default_max'),
            'consented_max_amount' => PHP_INT_MAX >> 32,
        ]);
    }

    /** 同意時上限額を明示する (価格改定 → 再同意要求のシナリオ用)。 */
    public function consentedMaxAmount(int $amount): static
    {
        return $this->state(fn (): array => [
            'consented_max_amount' => $amount,
        ]);
    }

    /** 連続失敗で自動停止された状態。 */
    public function disabledByFailures(): static
    {
        return $this->enabled()->state(fn (): array => [
            'enabled' => false,
            'failure_count' => config()->integer('billing.auto_recharge.max_failures'),
            'disabled_reason' => AutoRechargeDisabledReason::PaymentFailures,
        ]);
    }
}
