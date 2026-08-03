<?php

declare(strict_types=1);

use App\Services\Onboarding\OnboardingReturnResolver;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * P7: 課金ゲートで失われる「意図先 destination」の往復。
 *
 * - 遮断時に return_to を積むのは manageBilling 保持者の安全メソッド (GET/HEAD) かつ
 *   非 XHR のときだけ (POST / JSON は元 path 復元に意味がない)。
 * - 復帰は Personal 有効化 (activate-personal) の成功着地 / 有料経路は billing.index の
 *   continueUrl prop。どちらも 1 回限りで消費する (リロードで CTA が残らない)。
 * - 保存値は OnboardingReturnResolver::normalizePath を通った same-origin 内部 path のみ。
 */

test('gate 遮断 (manageBilling 保持 + GET) で元 path が return_to に積まれる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);

    $this->actingAs($owner)->get('/projects')
        ->assertRedirect(route('onboarding.checkout'));

    expect(session(OnboardingReturnResolver::orgKey($organization)))->toBe('/projects');
});

test('gate 遮断が XHR (expectsJson) の場合は 402 で return_to を積まない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);

    $this->actingAs($owner)
        ->getJson('/projects')
        ->assertStatus(402);

    expect(session(OnboardingReturnResolver::orgKey($organization)))->toBeNull();
});

test('gate 遮断が POST の場合は return_to を積まない (意図遷移ではない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);

    $this->actingAs($owner)
        ->post('/projects', ['name' => 'ダミー'])
        ->assertRedirect(route('onboarding.checkout'));

    expect(session(OnboardingReturnResolver::orgKey($organization)))->toBeNull();
});

test('manageBilling を持たない member の遮断では return_to を積まない', function (): void {
    [$organization] = createOrganizationWithOwner(grandfatherFreePlan: false);
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($member)->get('/projects')
        ->assertRedirect(route('onboarding.billing-required'));

    expect(session(OnboardingReturnResolver::orgKey($organization)))->toBeNull();
});

test('activate-personal 成功で元 path へ復帰し return_to は消費される (2 回目は dashboard)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);

    // 遮断 → return_to が積まれる
    $this->actingAs($owner)->get('/projects');
    expect(session(OnboardingReturnResolver::orgKey($organization)))->toBe('/projects');

    $this->actingAs($owner)
        ->post('/onboarding/activate-personal', ['declaration' => true])
        ->assertRedirect('/projects');

    expect(session(OnboardingReturnResolver::orgKey($organization)))->toBeNull();
});

test('return_to なしの activate-personal 成功は dashboard へ (既定の非退行)', function (): void {
    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);

    $this->actingAs($owner)
        ->post('/onboarding/activate-personal', ['declaration' => true])
        ->assertRedirect(route('dashboard'));
});

test('billing.index は契約成立時に continueUrl を 1 回だけ出す', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    contractPaidPlan($organization);
    session([OnboardingReturnResolver::orgKey($organization) => '/projects']);

    $this->actingAs($owner)->get('/billing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Index')
            ->where('page.continueUrl', '/projects'));

    // 1 回限り: リロードでは CTA が残らない
    $this->actingAs($owner)->get('/billing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('page.continueUrl', null));
});

test('billing.index は未契約 (grantsAccess 不成立) では continueUrl を出さず return_to も消さない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    session([OnboardingReturnResolver::orgKey($organization) => '/projects']);

    $this->actingAs($owner)->get('/billing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('page.continueUrl', null));

    expect(session(OnboardingReturnResolver::orgKey($organization)))->toBe('/projects');
});

test('改ざんされた return_to (外部 URL) は continueUrl に出ない (open-redirect 防御)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    contractPaidPlan($organization);
    session([OnboardingReturnResolver::orgKey($organization) => 'https://evil.example/x']);

    $this->actingAs($owner)->get('/billing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('page.continueUrl', null));
});
