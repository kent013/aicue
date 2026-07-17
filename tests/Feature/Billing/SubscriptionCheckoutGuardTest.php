<?php

declare(strict_types=1);

use App\Enums\Billing\PlanPriceKind;
use App\Exceptions\Billing\StripePriceNotSyncedException;
use App\Models\Billing\Plan;
use App\Models\Billing\PlanPrice;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use App\Services\Billing\Fakes\FakeStripeGateway;
use App\Services\Billing\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Webmozart\Assert\Assert;

/*
 * SubscriptionService::startCheckout の service 層ガード。
 *
 * - assertPriceSynced: production runtime でのみ「未 sync の test mode Price」を拒否する
 *   (deploy 手順の sync 漏れで test Price の本番課金が発生する事故を DB レベルで塞ぐ)。
 * - assertStripeBillablePlan: Personal (free) / Enterprise / 未知 code は fail-closed で 422。
 * - 有効なサブスク保持組織の再 checkout は fail-closed (プラン変更は Portal 経由)。
 */

function checkoutGuardService(): SubscriptionService
{
    return app(SubscriptionService::class);
}

function checkoutGuardPrice(string $planCode = 'standard'): PlanPrice
{
    $price = Plan::query()->where('code', $planCode)->firstOrFail()
        ->currentPrice(PlanPriceKind::Base);
    Assert::isInstanceOf($price, PlanPrice::class, "{$planCode} の current base price が未 seed");

    return $price;
}

beforeEach(function (): void {
    $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
});

test('非 production では未 sync の test mode Price でも checkout できる', function (): void {
    [$organization] = createOrganizationWithOwner();
    $price = checkoutGuardPrice();
    $price->forceFill(['livemode' => false, 'synced_at' => null])->save();

    $redirect = checkoutGuardService()->startCheckout(
        $organization,
        $price,
        'https://example.test/return',
        'https://example.test/return',
    );

    expect($redirect->url)->toContain('fake_external=stripe');
});

test('production では未 sync / test mode の Price を StripePriceNotSyncedException で拒否する', function (bool $livemode, ?string $syncedAt): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    [$organization] = createOrganizationWithOwner();
    $price = checkoutGuardPrice();
    $price->forceFill([
        'livemode' => $livemode,
        'synced_at' => $syncedAt === null ? null : CarbonImmutable::now(),
    ])->save();

    checkoutGuardService()->startCheckout(
        $organization,
        $price,
        'https://example.test/return',
        'https://example.test/return',
    );
})->with([
    'test mode Price (livemode=false)' => [false, 'now'],
    'sync 未実施 (synced_at=null)' => [true, null],
    'test mode かつ未 sync' => [false, null],
])->throws(StripePriceNotSyncedException::class);

test('production でも livemode + synced_at 済みの Price なら checkout できる', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    [$organization] = createOrganizationWithOwner();
    $price = checkoutGuardPrice();
    $price->forceFill(['livemode' => true, 'synced_at' => CarbonImmutable::now()])->save();

    $redirect = checkoutGuardService()->startCheckout(
        $organization,
        $price,
        'https://example.test/return',
        'https://example.test/return',
    );

    expect($redirect->url)->toContain('fake_external=stripe');
});

test('Stripe 決済対象外プラン (personal) の checkout は 422 (validation)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $price = checkoutGuardPrice();
    // personal は Price を持たない (activate 経路) ため、Price 側の plan を差し替えて
    // 「validation を迂回して非対象プランの Price が渡る」経路を service 層で塞ぐことを固定する
    $personal = Plan::query()->where('code', 'personal')->firstOrFail();
    $price->forceFill(['plan_id' => $personal->id])->save();

    checkoutGuardService()->startCheckout(
        $organization->fresh() ?? $organization,
        $price->fresh() ?? $price,
        'https://example.test/return',
        'https://example.test/return',
    );
})->throws(ValidationException::class);

test('既に有効なサブスクリプションがある組織の checkout は fail-closed', function (): void {
    [$organization] = createOrganizationWithOwner();
    createFakeSubscription($organization, status: 'active');

    checkoutGuardService()->startCheckout(
        $organization,
        checkoutGuardPrice(),
        'https://example.test/return',
        'https://example.test/return',
    );
})->throws(InvalidArgumentException::class, '既に有効なサブスクリプションがあります。プラン変更をご利用ください。');

test('解約済み (猶予期間も終了) のサブスクだけを持つ組織は再 checkout できる', function (): void {
    [$organization] = createOrganizationWithOwner();
    // Cashier の valid() は ends_at で猶予期間を見るため、終了済みを明示する
    createFakeSubscription($organization, status: 'canceled')
        ->forceFill(['ends_at' => CarbonImmutable::now()->subDay()])->save();

    $redirect = checkoutGuardService()->startCheckout(
        $organization,
        checkoutGuardPrice(),
        'https://example.test/return',
        'https://example.test/return',
    );

    expect($redirect->url)->toContain('fake_external=stripe');
});

test('有効サブスク保持組織の /billing/checkout は 500 にせず error flash で差し戻す', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    createFakeSubscription($organization, status: 'active');

    $this->actingAs($owner)
        ->from('/billing')
        ->post('/billing/checkout', ['plan_code' => 'standard'])
        ->assertRedirect('/billing')
        ->assertSessionHas('error', '既に有効なサブスクリプションがあります。プラン変更をご利用ください。');
});
