<?php

declare(strict_types=1);

use App\Enums\Billing\OnboardingBillingState;
use App\Models\Billing\BillingCheckoutSession;
use App\Models\Billing\Subscription;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\BillingAccess;
use Carbon\CarbonImmutable;

/*
 * P2 判定モデル (aigenba verbatim 移植) の cohort 表 A〜I を固定する。
 *
 * state() は plan_code を一切見ない。hasActiveAccess() は state()->grantsAccess() に
 * 移行 OR (plan_code === null。P4 で削除) を足したもの。
 *
 * 現行からの反転 (P2 の成果物・挙動不変を主張しない):
 * - cohort C (active/trialing + trial 終了 + PM 無): 許可 → **遮断**
 * - cohort D (past_due + PM 有):                    遮断 → **許可**
 */

/**
 * cohort 固定用の subscription 行 (Stripe には到達しない)。
 * `has_payment_method` / `trial_ends_at` は列既定を上書きして事実値を明示する。
 */
function cohortSubscription(
    Organization $organization,
    string $status = 'active',
    bool $hasPaymentMethod = true,
    ?CarbonImmutable $trialEndsAt = null,
): Subscription {
    $subscription = createFakeSubscription($organization, status: $status);
    $subscription->forceFill([
        'has_payment_method' => $hasPaymentMethod,
        'trial_ends_at' => $trialEndsAt,
    ])->save();

    return $subscription;
}

/** 有償プラン契約中 (plan_code 非 null) の組織。 */
function cohortPaidOrganization(): Organization
{
    $organization = Organization::factory()->create();
    $organization->forceFill(['plan_code' => 'standard'])->save();

    return $organization;
}

function cohortBillingAccess(): BillingAccess
{
    return app(BillingAccess::class);
}

test('cohort A: active/trialing で trial 未設定なら Subscribed + 許可', function (string $status): void {
    $organization = cohortPaidOrganization();
    cohortSubscription($organization, status: $status, hasPaymentMethod: false, trialEndsAt: null);

    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::Subscribed)
        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeTrue();
})->with(['active', 'trialing']);

test('cohort A: trial_ends_at が未来なら PM 無しでも Subscribed + 許可', function (): void {
    $organization = cohortPaidOrganization();
    cohortSubscription(
        $organization,
        status: 'trialing',
        hasPaymentMethod: false,
        trialEndsAt: CarbonImmutable::now()->addDay(),
    );

    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::Subscribed)
        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeTrue();
});

test('cohort B: trial 終了 + PM 有りは Subscribed + 許可', function (string $status): void {
    $organization = cohortPaidOrganization();
    cohortSubscription(
        $organization,
        status: $status,
        hasPaymentMethod: true,
        trialEndsAt: CarbonImmutable::now()->subDay(),
    );

    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::Subscribed)
        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeTrue();
})->with(['active', 'trialing']);

test('cohort C: trial 終了 + PM 無しは ExpiredCheckout + 遮断 (P2 で反転する側)', function (string $status): void {
    $organization = cohortPaidOrganization();
    cohortSubscription(
        $organization,
        status: $status,
        hasPaymentMethod: false,
        trialEndsAt: CarbonImmutable::now()->subDay(),
    );

    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::ExpiredCheckout)
        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeFalse();
})->with(['active', 'trialing']);

test('cohort C 境界: trial_ends_at ちょうど now + PM 無しは遮断 (<= 判定)', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-17 00:00:00'));
    $organization = cohortPaidOrganization();
    cohortSubscription(
        $organization,
        status: 'active',
        hasPaymentMethod: false,
        trialEndsAt: CarbonImmutable::now(),
    );

    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::ExpiredCheckout)
        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeFalse();
});

test('cohort D: past_due + PM 有りは Subscribed + 許可 (P2 で反転する側)', function (): void {
    $organization = cohortPaidOrganization();
    cohortSubscription(
        $organization,
        status: 'past_due',
        hasPaymentMethod: true,
        trialEndsAt: CarbonImmutable::now()->subDay(),
    );

    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::Subscribed)
        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeTrue();
});

test('cohort D: past_due + trial 未終了は PM 無しでも Subscribed + 許可', function (): void {
    $organization = cohortPaidOrganization();
    cohortSubscription($organization, status: 'past_due', hasPaymentMethod: false, trialEndsAt: null);

    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::Subscribed)
        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeTrue();
});

test('cohort E: past_due + trial 終了 + PM 無しは ExpiredCheckout + 遮断', function (): void {
    $organization = cohortPaidOrganization();
    cohortSubscription(
        $organization,
        status: 'past_due',
        hasPaymentMethod: false,
        trialEndsAt: CarbonImmutable::now()->subDay(),
    );

    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::ExpiredCheckout)
        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeFalse();
});

test('cohort F: paused は PM 有りでも ExpiredCheckout + 遮断', function (): void {
    $organization = cohortPaidOrganization();
    cohortSubscription($organization, status: 'paused', hasPaymentMethod: true, trialEndsAt: null);

    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::ExpiredCheckout)
        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeFalse();
});

test('cohort G: 非 active 系 status は ExpiredCheckout + 遮断', function (string $status): void {
    $organization = cohortPaidOrganization();
    cohortSubscription($organization, status: $status, hasPaymentMethod: true, trialEndsAt: null);

    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::ExpiredCheckout)
        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeFalse();
})->with(['canceled', 'unpaid', 'incomplete', 'incomplete_expired']);

test('cohort F/G: state() は plan_code を見ない (null でも同じ state。許可は移行 OR 由来)', function (string $status): void {
    $organization = Organization::factory()->create(); // plan_code null
    cohortSubscription($organization, status: $status, hasPaymentMethod: true, trialEndsAt: null);

    expect($organization->plan_code)->toBeNull()
        ->and(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::ExpiredCheckout)
        // 移行 OR (P4 で削除) が cohort I として許可する
        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeTrue();
})->with(['paused', 'canceled', 'unpaid', 'incomplete', 'incomplete_expired']);

test('cohort H: subscription 行なし + checkout session なしは NoSubscription + 遮断', function (): void {
    $organization = cohortPaidOrganization();

    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::NoSubscription)
        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeFalse();
});

test('cohort I: plan_code null は state が遮断側でも移行 OR で許可される', function (): void {
    $organization = Organization::factory()->create();

    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::NoSubscription)
        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeTrue();
});

test('free_plan_code=personal は ActiveFreePlan + 許可 (declarer 有り)', function (): void {
    $declarer = User::factory()->create();
    $organization = Organization::factory()->freePersonal($declarer)->create();

    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::ActiveFreePlan)
        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeTrue();
});

test('free_plan_code=personal は ActiveFreePlan + 許可 (declarer 無し)', function (): void {
    $organization = Organization::factory()->grandfathered()->create();

    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::ActiveFreePlan)
        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeTrue();
});

test('entitled な subscription は free_plan_code より優先される (Subscribed)', function (): void {
    $organization = Organization::factory()->grandfathered()->create();
    cohortSubscription($organization, status: 'active');

    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::Subscribed);
});

test('entitled でない subscription があると free_plan_code が ActiveFreePlan を成立させる (paid→free)', function (): void {
    $organization = Organization::factory()->grandfathered()->create();
    cohortSubscription($organization, status: 'canceled');

    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::ActiveFreePlan)
        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeTrue();
});

test('live pending checkout (created_at が stale 境界ちょうど) は PendingCheckout', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-17 00:00:00'));
    $organization = cohortPaidOrganization();
    BillingCheckoutSession::factory()->create([
        'organization_id' => $organization->getKey(),
        'created_at' => BillingAccess::staleThresholdAt(CarbonImmutable::now()),
    ]);

    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::PendingCheckout)
        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeFalse();
});

test('stale pending checkout (境界の 1 秒前) は ExpiredCheckout', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-17 00:00:00'));
    $organization = cohortPaidOrganization();
    BillingCheckoutSession::factory()->create([
        'organization_id' => $organization->getKey(),
        'created_at' => BillingAccess::staleThresholdAt(CarbonImmutable::now())->subSecond(),
    ]);

    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::ExpiredCheckout);
});

test('live pending が 1 件でもあれば stale pending があっても PendingCheckout', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-17 00:00:00'));
    $organization = cohortPaidOrganization();
    BillingCheckoutSession::factory()->stale()->create(['organization_id' => $organization->getKey()]);
    BillingCheckoutSession::factory()->create(['organization_id' => $organization->getKey()]);

    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::PendingCheckout);
});

test('expired / failed の checkout session は ExpiredCheckout', function (string $state): void {
    $organization = cohortPaidOrganization();
    BillingCheckoutSession::factory()->{$state}()->create(['organization_id' => $organization->getKey()]);

    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::ExpiredCheckout);
})->with(['expired', 'failed']);

test('completed のみの checkout session は NoSubscription (expired 扱いにしない)', function (): void {
    $organization = cohortPaidOrganization();
    BillingCheckoutSession::factory()->completed()->create(['organization_id' => $organization->getKey()]);

    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::NoSubscription);
});

test('checkout session は他組織の行を読まない', function (): void {
    $organization = cohortPaidOrganization();
    BillingCheckoutSession::factory()->create(); // 別組織の live pending

    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::NoSubscription);
});

test('state() は読み取り経路で DB を書き換えない (stale pending の expire は sweeper 責務)', function (): void {
    $organization = cohortPaidOrganization();
    $session = BillingCheckoutSession::factory()->stale()->create([
        'organization_id' => $organization->getKey(),
    ]);
    $before = $session->fresh();
    expect($before)->not->toBeNull();

    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::ExpiredCheckout);

    $after = $session->fresh();
    expect($after)->not->toBeNull()
        ->and($after->status)->toBe($before->status)
        ->and($after->updated_at?->toIso8601String())->toBe($before->updated_at?->toIso8601String());
});
