<?php

declare(strict_types=1);

use App\Enums\Billing\BillingFeedbackKind;
use App\Enums\Billing\OnboardingBillingState;
use App\Enums\CheckoutIntent;
use App\Enums\CheckoutSessionStatus;
use App\Models\Billing\BillingCheckoutSession;
use App\Services\Billing\BillingAccess;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Support\FakeStripeGateway;

/*
 * P9 (C-1): live 判定の単一出典。
 *
 * 「pending 行が live か」の判定は BillingCheckoutSession の述語だけが定義し、
 * state() / startCheckout() の段 2・3・4 / 日次 sweeper の 4 経路が共有する。
 * 判定の正しさを sweeper の実行タイミングに依存させない (= sweeper 未実行でも成立する)。
 */

beforeEach(function (): void {
    $this->app->singleton(StripeGatewayInterface::class, fn (): FakeStripeGateway => new FakeStripeGateway);
});

test('2 日前の stale pending があっても新 token の POST は新規 Checkout を作る (warning に落ちない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    BillingCheckoutSession::factory()
        ->for($organization)
        ->initiatedBy((int) $owner->id)
        ->withAttempt((string) Str::ulid(), 'standard')
        ->stale()
        ->create();

    $this->actingAs($owner)->post("/organizations/{$organization->slug}/billing/checkout", [
        'plan_code' => 'standard',
        'subscription_attempt_token' => (string) Str::ulid(),
    ])->assertRedirectContains('https://checkout.stripe.test/');

    expect(BillingCheckoutSession::query()->where('organization_id', $organization->id)->count())->toBe(2);

    /** @var FakeStripeGateway $fake */
    $fake = app(StripeGatewayInterface::class);
    expect($fake->created)->toHaveCount(1);
    // stale な行は Stripe 側で既に expire 済みのため照会しない (無駄な外部 API を撃たない)。
    expect($fake->expired)->toHaveCount(0);
});

test('同 token + stale pending の再送は checkout_retry_required、境界内 (23h59m) なら既存 URL へ replay する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    // (a) stale (25h 前) → retry
    $staleToken = (string) Str::ulid();
    BillingCheckoutSession::factory()
        ->for($organization)
        ->initiatedBy((int) $owner->id)
        ->withAttempt($staleToken, 'standard')
        ->create(['created_at' => CarbonImmutable::now()->subHours(25)]);

    $this->actingAs($owner)->post("/organizations/{$organization->slug}/billing/checkout", [
        'plan_code' => 'standard',
        'subscription_attempt_token' => $staleToken,
    ])
        ->assertRedirect("/organizations/{$organization->slug}/billing")
        ->assertSessionHas(BillingFeedbackKind::FLASH_KEY, BillingFeedbackKind::CheckoutRetryRequired->value);

    // (b) 境界内 (23h59m 前) → replay (既存 checkout_url)
    [$org2, $owner2] = createOrganizationWithOwner('境界内組織');
    $liveToken = (string) Str::ulid();
    BillingCheckoutSession::factory()
        ->for($org2)
        ->initiatedBy((int) $owner2->id)
        ->withAttempt($liveToken, 'standard')
        ->create(['created_at' => CarbonImmutable::now()->subMinutes(23 * 60 + 59)]);

    $this->actingAs($owner2)->post("/organizations/{$org2->slug}/billing/checkout", [
        'plan_code' => 'standard',
        'subscription_attempt_token' => $liveToken,
    ])->assertRedirect('https://checkout.stripe.com/dummy');
});

test('state() と startCheckout() は同一閾値を共有する (23h = PendingCheckout / 25h = ExpiredCheckout)', function (): void {
    $access = app(BillingAccess::class);

    // 23h 前 = live → PendingCheckout、新規作成しない
    [$org23, $owner23] = createOrganizationWithOwner('23h 組織', grandfatherFreePlan: false);
    BillingCheckoutSession::factory()
        ->for($org23)
        ->initiatedBy((int) $owner23->id)
        ->withAttempt((string) Str::ulid(), 'standard')
        ->create(['created_at' => CarbonImmutable::now()->subHours(23)]);

    expect($access->state($org23))->toBe(OnboardingBillingState::PendingCheckout);

    $this->actingAs($owner23)
        ->from("/organizations/{$org23->slug}/billing/plans")
        ->post("/organizations/{$org23->slug}/billing/checkout", [
            'plan_code' => 'standard',
            'subscription_attempt_token' => (string) Str::ulid(),
        ])
        ->assertSessionHas('warning');
    expect(BillingCheckoutSession::query()->where('organization_id', $org23->id)->count())->toBe(1);

    // 25h 前 = stale → ExpiredCheckout、新 token で新規作成できる
    [$org25, $owner25] = createOrganizationWithOwner('25h 組織', grandfatherFreePlan: false);
    BillingCheckoutSession::factory()
        ->for($org25)
        ->initiatedBy((int) $owner25->id)
        ->withAttempt((string) Str::ulid(), 'standard')
        ->create(['created_at' => CarbonImmutable::now()->subHours(25)]);

    expect($access->state($org25))->toBe(OnboardingBillingState::ExpiredCheckout);

    $this->actingAs($owner25)->post("/organizations/{$org25->slug}/billing/checkout", [
        'plan_code' => 'standard',
        'subscription_attempt_token' => (string) Str::ulid(),
    ])->assertRedirectContains('https://checkout.stripe.test/');
    expect(BillingCheckoutSession::query()->where('organization_id', $org25->id)->count())->toBe(2);
});

test('state() は read 経路で DB 行を書き換えない (stale pending は in-memory 判定のみ)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    $row = BillingCheckoutSession::factory()
        ->for($organization)
        ->initiatedBy((int) $owner->id)
        ->stale()
        ->create();

    expect(app(BillingAccess::class)->state($organization))->toBe(OnboardingBillingState::ExpiredCheckout);
    expect($row->refresh()->status)->toBe(CheckoutSessionStatus::Pending->value);
});

test('billing:reconcile-schedules は stale pending だけを Expired にする (intent で絞らない)', function (): void {
    [$organization] = createOrganizationWithOwner();

    $staleSub = BillingCheckoutSession::factory()->for($organization)->stale()->create();
    $staleSetup = BillingCheckoutSession::factory()->for($organization)->setupPaymentMethod()->stale()->create();
    $live = BillingCheckoutSession::factory()->for($organization)->create();

    $this->artisan('billing:reconcile-schedules')
        ->expectsOutputToContain('expired=2')
        ->assertSuccessful();

    expect($staleSub->refresh()->status)->toBe(CheckoutSessionStatus::Expired->value);
    // P8a の setup 行も収束する (sweeper だけは intent 非スコープ)
    expect($staleSetup->refresh()->status)->toBe(CheckoutSessionStatus::Expired->value);
    expect($staleSetup->intent)->toBe(CheckoutIntent::SetupPaymentMethod->value);
    expect($live->refresh()->status)->toBe(CheckoutSessionStatus::Pending->value);
});
