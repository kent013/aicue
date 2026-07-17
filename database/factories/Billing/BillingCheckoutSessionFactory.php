<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use App\Enums\CheckoutIntent;
use App\Enums\CheckoutSessionStatus;
use App\Models\Billing\BillingCheckoutSession;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * 既定は live pending (契約待ち) のサブスク Checkout 追跡行。
 *
 * @extends Factory<BillingCheckoutSession>
 */
class BillingCheckoutSessionFactory extends Factory
{
    protected $model = BillingCheckoutSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            // 既定は null (= 旧行相当)。resume/replay の user スコープを検証するテストは
            // ->initiatedBy($userId) で明示する。
            'initiated_by_user_id' => null,
            'intent' => CheckoutIntent::SubscriptionStart->value,
            'plan_code' => 'starter',
            'stripe_session_id' => 'cs_'.Str::random(24),
            'idempotency_key' => 'checkout:'.Str::uuid()->toString(),
            'attempt_token' => null,
            'checkout_url' => null,
            'status' => CheckoutSessionStatus::Pending->value,
            'completed_at' => null,
        ];
    }

    /**
     * attempt_token (契約 attempt 単位の冪等キー) を固定する。
     * checkout_url が未指定なら Pending 再生用のダミー URL を併せて設定する。
     */
    public function withAttemptToken(string $token, ?string $checkoutUrl = 'https://checkout.stripe.com/dummy'): static
    {
        return $this->state(fn (): array => [
            'attempt_token' => $token,
            'checkout_url' => $checkoutUrl,
        ]);
    }

    /** 購入意図を起こした user を固定する (resume/replay の user スコープ検証用)。 */
    public function initiatedBy(int $userId): static
    {
        return $this->state(fn (): array => [
            'initiated_by_user_id' => $userId,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => CheckoutSessionStatus::Completed->value,
            'completed_at' => CarbonImmutable::now(),
        ]);
    }

    /** オートリチャージ用カード登録 (Checkout mode=setup) セッション。 */
    public function setupPaymentMethod(): static
    {
        return $this->state(fn (): array => [
            'intent' => CheckoutIntent::SetupPaymentMethod->value,
            'plan_code' => null,
        ]);
    }

    /** 明示 expire 済みの行。 */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => CheckoutSessionStatus::Expired->value,
        ]);
    }

    /** 決済失敗で終わった行。 */
    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => CheckoutSessionStatus::Failed->value,
        ]);
    }

    /** stale な pending (status は pending のまま created_at が stale 境界より過去) の行。 */
    public function stale(): static
    {
        return $this->state(fn (): array => [
            'status' => CheckoutSessionStatus::Pending->value,
            'created_at' => CarbonImmutable::now()->subDays(2),
        ]);
    }
}
