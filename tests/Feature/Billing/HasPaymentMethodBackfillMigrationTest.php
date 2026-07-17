<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Services\Billing\BillingAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * cohort C の移行安全性: 列既定 false のまま既存行を残すと「trial 終了 + PM 無し」で
 * 締め出される。列追加と分離した data migration が既存行を true へ倒すことで、
 * P2 デプロイ時点の cohort C を空にする。
 */

/** 列追加直後 (backfill 前) 相当の行 = has_payment_method が列既定 false のまま。 */
function subscriptionWithColumnDefault(Organization $organization, ?CarbonImmutable $trialEndsAt = null): int
{
    $subscription = createFakeSubscription($organization);
    DB::table('subscriptions')->where('id', $subscription->getKey())->update([
        'has_payment_method' => false,
        'trial_ends_at' => $trialEndsAt,
    ]);

    return $subscription->getKey();
}

function runHasPaymentMethodBackfill(): void
{
    $migration = require database_path(
        'migrations/2026_07_17_000220_backfill_has_payment_method_on_subscriptions.php'
    );
    expect($migration)->toBeInstanceOf(Migration::class);
    $migration->up();
}

test('has_payment_method の列既定は false (移植元と同値)', function (): void {
    $organization = Organization::factory()->create();
    $subscription = createFakeSubscription($organization);

    expect($subscription->fresh()?->has_payment_method)->toBeFalse();
});

test('backfill が既存の全 subscription 行を true にする', function (): void {
    $organization = Organization::factory()->create();
    $id = subscriptionWithColumnDefault($organization, CarbonImmutable::now()->subDay());

    runHasPaymentMethodBackfill();

    expect(DB::table('subscriptions')->where('id', $id)->value('has_payment_method'))->toBeTrue();
});

test('backfill 後は trial 終了済みの既存有償組織が締め出されない (cohort C が空になる)', function (): void {
    $organization = Organization::factory()->create();
    $organization->forceFill(['plan_code' => 'standard'])->save();
    subscriptionWithColumnDefault($organization, CarbonImmutable::now()->subDay());

    // backfill 前は cohort C (trial 終了 + PM 無し) として遮断される
    expect(app(BillingAccess::class)->hasActiveAccess($organization->fresh() ?? $organization))->toBeFalse();

    runHasPaymentMethodBackfill();

    expect(app(BillingAccess::class)->hasActiveAccess($organization->fresh() ?? $organization))->toBeTrue();
});

test('backfill は冪等 (2 回流しても結果が変わらない)', function (): void {
    $organization = Organization::factory()->create();
    $id = subscriptionWithColumnDefault($organization);

    runHasPaymentMethodBackfill();
    $afterFirst = DB::table('subscriptions')->where('id', $id)->value('updated_at');

    runHasPaymentMethodBackfill();

    expect(DB::table('subscriptions')->where('id', $id)->value('has_payment_method'))->toBeTrue()
        ->and(DB::table('subscriptions')->where('id', $id)->value('updated_at'))->toBe($afterFirst);
});
