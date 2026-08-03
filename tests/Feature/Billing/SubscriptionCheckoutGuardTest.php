<?php

declare(strict_types=1);

use App\Enums\Billing\PlanPriceKind;
use App\Exceptions\Billing\StripePriceNotSyncedException;
use App\Models\Billing\Plan;
use App\Models\Billing\PlanPrice;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use App\Services\Billing\Fakes\FakeStripeGateway;
use App\Services\Billing\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Webmozart\Assert\Assert;

/*
 * SubscriptionService::startCheckout の service 層ガード。
 *
 * - assertPriceSynced: production runtime でのみ「未 sync の test mode Price」を拒否する
 *   (deploy 手順の sync 漏れで test Price の本番課金が発生する事故を DB レベルで塞ぐ)。
 * - assertStripeBillablePlan: Personal (free) / Enterprise / 未知 code は fail-closed で 422。
 * - 有効なサブスク保持組織の再 checkout は fail-closed (プラン変更は Portal 経由)。
 *
 * P9: シグネチャは冪等マシン (org, user, plan, urls, attemptToken, funding) に変わった。
 * base Price は plan から service が解決する。
 */

function checkoutGuardService(): SubscriptionService
{
    return app(SubscriptionService::class);
}

function checkoutGuardPlan(string $planCode = 'standard'): Plan
{
    return Plan::query()->where('code', $planCode)->firstOrFail();
}

function checkoutGuardPrice(string $planCode = 'standard'): PlanPrice
{
    $price = checkoutGuardPlan($planCode)->currentPrice(PlanPriceKind::Base);
    Assert::isInstanceOf($price, PlanPrice::class, "{$planCode} の current base price が未 seed");

    return $price;
}

function startGuardCheckout(Organization $organization, User $user, ?Plan $plan = null): string
{
    $result = checkoutGuardService()->startCheckout(
        $organization,
        $user,
        $plan ?? checkoutGuardPlan(),
        'https://example.test/return',
        'https://example.test/return',
        (string) Str::ulid(),
        null,
    );

    return (string) $result->url;
}

beforeEach(function (): void {
    $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
});

test('非 production では未 sync の test mode Price でも checkout できる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $price = checkoutGuardPrice();
    $price->forceFill(['livemode' => false, 'synced_at' => null])->save();

    expect(startGuardCheckout($organization, $owner))->toContain('fake_external=stripe');
});

test('production では未 sync / test mode の Price を StripePriceNotSyncedException で拒否する', function (bool $livemode, ?string $syncedAt): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    [$organization, $owner] = createOrganizationWithOwner();
    $price = checkoutGuardPrice();
    $price->forceFill([
        'livemode' => $livemode,
        'synced_at' => $syncedAt === null ? null : CarbonImmutable::now(),
    ])->save();

    startGuardCheckout($organization, $owner);
})->with([
    'test mode Price (livemode=false)' => [false, 'now'],
    'sync 未実施 (synced_at=null)' => [true, null],
    'test mode かつ未 sync' => [false, null],
])->throws(StripePriceNotSyncedException::class);

test('production でも livemode + synced_at 済みの Price なら checkout できる', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    [$organization, $owner] = createOrganizationWithOwner();
    $price = checkoutGuardPrice();
    $price->forceFill(['livemode' => true, 'synced_at' => CarbonImmutable::now()])->save();

    expect(startGuardCheckout($organization, $owner))->toContain('fake_external=stripe');
});

test('Stripe 決済対象外プラン (personal) の checkout は 422 (validation)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    // personal は Price を持たない (activate 経路) ため、Price 側の plan を差し替えて
    // 「validation を迂回して非対象プランの Price が渡る」経路を service 層で塞ぐことを固定する
    $personal = Plan::query()->where('code', 'personal')->firstOrFail();
    checkoutGuardPrice()->forceFill(['plan_id' => $personal->id])->save();

    startGuardCheckout($organization, $owner, $personal->fresh() ?? $personal);
})->throws(ValidationException::class);

test('既に有効なサブスクリプションがある組織の checkout は fail-closed', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    createFakeSubscription($organization, status: 'active');

    startGuardCheckout($organization, $owner);
})->throws(InvalidArgumentException::class, '既に有効なサブスクリプションがあります。プラン変更をご利用ください。');

test('解約済み (猶予期間も終了) のサブスクだけを持つ組織は再 checkout できる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    // Cashier の valid() は ends_at で猶予期間を見るため、終了済みを明示する
    createFakeSubscription($organization, status: 'canceled')
        ->forceFill(['ends_at' => CarbonImmutable::now()->subDay()])->save();

    expect(startGuardCheckout($organization, $owner))->toContain('fake_external=stripe');
});

test('有効サブスク保持組織の /billing/checkout は 500 にせず error flash で差し戻す', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    createFakeSubscription($organization, status: 'active');

    $this->actingAs($owner)
        ->from('/billing')
        ->post('/billing/checkout', [
            'plan_code' => 'standard',
            'subscription_attempt_token' => (string) Str::ulid(),
        ])
        ->assertRedirect('/billing')
        ->assertSessionHas('error', '既に有効なサブスクリプションがあります。プラン変更をご利用ください。');
});
