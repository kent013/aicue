<?php

declare(strict_types=1);

use App\Enums\CheckoutIntent;
use App\Enums\CheckoutSessionStatus;
use App\Models\Billing\BillingCheckoutSession;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
 * BillingCheckoutSession (state() の PendingCheckout / ExpiredCheckout の真実源) の
 * 述語と DB 制約を固定する。
 */

test('factory 既定は subscription_start の live pending 行', function (): void {
    $session = BillingCheckoutSession::factory()->create();

    expect($session->intentEnum())->toBe(CheckoutIntent::SubscriptionStart)
        ->and($session->statusEnum())->toBe(CheckoutSessionStatus::Pending)
        ->and($session->plan_code)->toBe('starter')
        ->and($session->completed_at)->toBeNull();
});

test('setupPaymentMethod state は intent=setup_payment_method / plan_code なし', function (): void {
    $session = BillingCheckoutSession::factory()->setupPaymentMethod()->create();

    expect($session->intentEnum())->toBe(CheckoutIntent::SetupPaymentMethod)
        ->and($session->plan_code)->toBeNull();
});

test('completed / expired / failed state が statusEnum に反映される', function (string $state, CheckoutSessionStatus $expected): void {
    $session = BillingCheckoutSession::factory()->{$state}()->create();

    expect($session->statusEnum())->toBe($expected);
})->with([
    ['completed', CheckoutSessionStatus::Completed],
    ['expired', CheckoutSessionStatus::Expired],
    ['failed', CheckoutSessionStatus::Failed],
]);

test('isReplayablePending は pending かつ checkout_url が生存しているときだけ true', function (): void {
    $replayable = BillingCheckoutSession::factory()->withAttemptToken('token-live')->create();

    expect($replayable->isReplayablePending(CarbonImmutable::now()))->toBeTrue();
});

test('isReplayablePending は checkout_url が null / 空なら false', function (?string $url): void {
    $session = BillingCheckoutSession::factory()->create(['checkout_url' => $url]);

    expect($session->isReplayablePending(CarbonImmutable::now()))->toBeFalse();
})->with([null, '']);

test('isReplayablePending は pending 以外なら checkout_url があっても false', function (string $state): void {
    $session = BillingCheckoutSession::factory()
        ->withAttemptToken('token-'.$state)
        ->{$state}()
        ->create();

    expect($session->isReplayablePending(CarbonImmutable::now()))->toBeFalse();
})->with(['completed', 'expired', 'failed']);

test('initiatedBy / organization の関連が引ける', function (): void {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $session = BillingCheckoutSession::factory()
        ->initiatedBy($user->getKey())
        ->create(['organization_id' => $organization->getKey()]);

    expect($session->initiated_by_user_id)->toBe($user->getKey())
        ->and($session->organization->getKey())->toBe($organization->getKey());
});

test('stripe_session_id は unique', function (): void {
    BillingCheckoutSession::factory()->create(['stripe_session_id' => 'cs_dup']);

    expect(fn () => BillingCheckoutSession::factory()->create(['stripe_session_id' => 'cs_dup']))
        ->toThrow(QueryException::class);
});

test('idempotency_key は unique', function (): void {
    BillingCheckoutSession::factory()->create(['idempotency_key' => 'checkout:dup']);

    expect(fn () => BillingCheckoutSession::factory()->create(['idempotency_key' => 'checkout:dup']))
        ->toThrow(QueryException::class);
});

test('(organization_id, intent, attempt_token) は unique', function (): void {
    $organization = Organization::factory()->create();
    BillingCheckoutSession::factory()
        ->withAttemptToken('attempt-1')
        ->create(['organization_id' => $organization->getKey()]);

    expect(fn () => BillingCheckoutSession::factory()
        ->withAttemptToken('attempt-1')
        ->create(['organization_id' => $organization->getKey()]))
        ->toThrow(QueryException::class);
});

test('attempt_token が同値でも intent が違えば衝突しない', function (): void {
    $organization = Organization::factory()->create();
    BillingCheckoutSession::factory()
        ->withAttemptToken('attempt-1')
        ->create(['organization_id' => $organization->getKey()]);

    $other = BillingCheckoutSession::factory()
        ->setupPaymentMethod()
        ->withAttemptToken('attempt-1')
        ->create(['organization_id' => $organization->getKey()]);

    expect($other->intentEnum())->toBe(CheckoutIntent::SetupPaymentMethod);
});

test('attempt_token が NULL の行は複数あってもよい (複合 unique の NULL 重複許容)', function (): void {
    $organization = Organization::factory()->create();
    BillingCheckoutSession::factory()->count(2)->create([
        'organization_id' => $organization->getKey(),
    ]);

    expect(BillingCheckoutSession::query()->where('organization_id', $organization->getKey())->count())
        ->toBe(2);
});

test('tenant / actor キーは mass-assign できない (明示代入のみ)', function (): void {
    $session = new BillingCheckoutSession;

    expect($session->getFillable())
        ->not->toContain('organization_id')
        ->not->toContain('initiated_by_user_id');
});

test('isLivePending は created_at が stale 境界より新しいときだけ true (境界の両側を固定)', function (): void {
    // created_at の永続化は秒精度 (Eloquent の date format) のため基準時刻も秒に丸める。
    $now = CarbonImmutable::now()->startOfSecond();

    $live = BillingCheckoutSession::factory()->create(['created_at' => $now->subHours(23)]);
    $boundary = BillingCheckoutSession::factory()->create([
        'created_at' => BillingCheckoutSession::staleThresholdAt($now),
    ]);
    $stale = BillingCheckoutSession::factory()->create(['created_at' => $now->subHours(25)]);

    expect($live->isLivePending($now))->toBeTrue();
    // 境界時刻ちょうどは live 側 (live/stale は補集合であり両方に属する行は存在しない)
    expect($boundary->isLivePending($now))->toBeTrue();
    expect($stale->isLivePending($now))->toBeFalse();
});

test('created_at が null の行は live 扱い (state() の else 分岐と同一)', function (): void {
    $session = BillingCheckoutSession::factory()->create();
    $session->created_at = null;

    expect($session->isLivePending(CarbonImmutable::now()))->toBeTrue();
});

test('P9 の additive 2 列: funding_choice は fillable、pm_reuse_dispatched_at は datetime cast かつ fillable 外', function (): void {
    $session = BillingCheckoutSession::factory()
        ->fundingAutoRecharge()
        ->pmReuseDispatched()
        ->create();

    expect($session->funding_choice)->toBe('auto_recharge');
    expect($session->refresh()->pm_reuse_dispatched_at)->toBeInstanceOf(Carbon::class);
    expect($session->getFillable())->toContain('funding_choice');
    expect($session->getFillable())->not->toContain('pm_reuse_dispatched_at');
});
