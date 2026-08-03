<?php

declare(strict_types=1);

use App\Enums\Billing\OnboardingBillingState;
use App\Services\Billing\BillingAccess;
use App\Services\Billing\PersonalPlanService;

/*
|--------------------------------------------------------------------------
| createOrganizationWithOwner() の契約固定 (impl-review R1 Warning への対応)
|--------------------------------------------------------------------------
|
| P4 で `tests/Pest.php` の helper 既定を「backfill 相当 (grandfathered)」へ変えた。
| これは大多数のテスト (業務 route を叩くもの) にとって正しい既定だが、
| **「未契約であること」を検証したいテストが暗黙に grandfather されて通ってしまう**
| という穴を生みやすい (Codex impl-review R1 Warning)。
|
| 本テストは helper の 2 モードが返す組織の state / 利用可否を**直接 pin** する。
| 既定を変える・grandfather の書き込み内容を変えるといった helper 側の変更は、
| 個別テストが静かに意味を失う前に、まずここが落ちて可視化される。
*/

test('既定 (grandfatherFreePlan: true) は backfill 相当 = ActiveFreePlan で許可される', function (): void {
    [$organization] = createOrganizationWithOwner();

    expect($organization->plan_code)->toBeNull()
        ->and($organization->free_plan_code)->toBe(PersonalPlanService::FREE_PLAN_CODE)
        ->and($organization->free_plan_activated_at)->not->toBeNull()
        // declarer NULL = 「移行由来 (本人申告ではない)」であることが grandfathered の定義
        ->and($organization->personal_declared_by_user_id)->toBeNull()
        ->and($organization->personal_declared_at)->toBeNull();

    $access = app(BillingAccess::class);

    expect($access->state($organization))->toBe(OnboardingBillingState::ActiveFreePlan)
        ->and($access->hasActiveAccess($organization))->toBeTrue();
});

test('grandfatherFreePlan: false は真の未契約 = NoSubscription で遮断される', function (): void {
    [$organization] = createOrganizationWithOwner(grandfatherFreePlan: false);

    expect($organization->plan_code)->toBeNull()
        ->and($organization->free_plan_code)->toBeNull()
        ->and($organization->free_plan_activated_at)->toBeNull();

    $access = app(BillingAccess::class);

    // ここが true に戻ったら P4 のゲート反転が効いていない (= 移行 OR の復活)
    expect($access->state($organization))->toBe(OnboardingBillingState::NoSubscription)
        ->and($access->hasActiveAccess($organization))->toBeFalse();
});

test('helper は signup grant を発火しない (残高期待を壊さない / declarer index に触れない)', function (): void {
    // grandfathered は「移行で救済しただけ」であり activate() ではない。
    // activate() を呼ぶと初回無償チケットが付与され、残高を検証するテストが壊れる。
    [$organization] = createOrganizationWithOwner();

    expect($organization->signup_tickets_granted_at)->toBeNull();
});
