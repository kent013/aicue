<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Billing\BillingAccess;
use App\Services\Billing\PersonalPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/*
 * F-07 回帰 (ゲート反転の山場)。
 *
 * ゲート反転 = BillingAccess::hasActiveAccess() から移行 OR (plan_code === null) を外し
 * state()->grantsAccess() 一本にすること。反転で締め出しが起きない / 新規登録者が詰まないことを
 * 固定する:
 *   (a) 既存の plan_code IS NULL 組織 (grandfathering backfill 済み = free_plan_code='personal'、
 *       declarer NULL) は業務 route に到達できる
 *   (b) 新規未契約組織は遮断されるが onboarding.checkout / activate-personal で閉路が閉じる
 *   (c) 遮断時に理由が画面に出る (H1「説明なしリダイレクト」の再発検知。middleware は error flash を
 *       積まず、着地ページが理由を持つ = aigenba 方式)
 *   (d) 遮断先が gate group 外 = 無限ループしない
 *   (e) P4 の結論変更は plan_code IS NULL に閉じる (plan_code 非 null 側は移行 OR の有無で不変)
 */

const GATE_NO_PLAN_MESSAGE = 'ご利用にはプランの選択が必要です。';
const GATE_BLOCKED_MESSAGE = 'サブスクリプションのお支払いが確認できないため、ご利用を一時停止しています。お支払い方法をご確認ください。';

/** 未契約組織 (free_plan_code NULL) に member を 1 人足す (manageBilling 非保持)。 */
function gateMemberOf(Organization $organization): User
{
    $member = User::factory()->create();
    $organization->users()->attach($member);
    $member->addRole(OrganizationRole::Member->value, $organization->laratrust_team_id);
    $member->forceFill(['current_organization_id' => $organization->id])->save();

    return $member;
}

// ── (a) 既存 (grandfathered) 組織は移行後も業務ルートに到達する ──

test('(a) grandfathered な既存 free 組織 (declarer NULL) は業務 route に到達できる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(); // 既定 = backfill 相当

    expect($organization->plan_code)->toBeNull()
        ->and($organization->free_plan_code)->toBe(PersonalPlanService::FREE_PLAN_CODE)
        ->and($organization->personal_declared_by_user_id)->toBeNull();

    $this->actingAs($owner)->get('/projects')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Projects/Index'));
});

test('(a) grandfathered な既存 free 組織はプロジェクトを作成できる', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->post('/projects', ['name' => 'Grandfathered プロジェクト'])
        ->assertRedirect();
    expect(Project::query()->where('name', 'Grandfathered プロジェクト')->exists())->toBeTrue();
});

test('(a) grandfathered な既存 free 組織は撮影 PWA (/app) に到達できる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->get('/app')
        ->assertRedirect(route('capture.manuals.index', ['project' => $project]));
});

// ── (b) 新規登録者は遮断されても詰まない ──

test('(b) 新規未契約組織の owner は checkout へ遮断され activate-personal で業務 route に戻れる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    expect($organization->free_plan_code)->toBeNull();

    $this->actingAs($owner)->get('/projects')
        ->assertRedirect(route('onboarding.checkout'));

    // 着地できる (契約するための画面が契約してないと見られない詰みが無い)
    $this->actingAs($owner)->get(route('onboarding.checkout'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Onboarding/Checkout'));

    $this->actingAs($owner)->post(route('onboarding.activate-personal'), ['declaration' => true])
        ->assertRedirect(route('dashboard'));

    // 閉路が閉じている
    $this->actingAs($owner)->get('/projects')->assertOk();
});

test('(b) manageBilling 非保持 member は billing-required へ遮断され着地できる', function (): void {
    [$organization] = createOrganizationWithOwner(grandfatherFreePlan: false);
    $member = gateMemberOf($organization);

    $this->actingAs($member)->get('/projects')
        ->assertRedirect(route('onboarding.billing-required'));

    $this->actingAs($member)->get(route('onboarding.billing-required'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Onboarding/BillingRequired'));
});

// ── (c) 遮断時に理由が画面に出る (H1 再発検知) ──

test('(c) 遮断 redirect の着地は billing.index ではなく理由を持つ Onboarding/Checkout', function (): void {
    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);

    $redirect = $this->actingAs($owner)->get('/projects');
    $redirect->assertRedirect(route('onboarding.checkout'));
    expect($redirect->headers->get('Location'))->not->toBe(route('billing.index'));
    // 理由は着地ページが持つ = middleware は error flash を積まない (aigenba 方式)
    $redirect->assertSessionMissing('error');

    $this->actingAs($owner)->get(route('onboarding.checkout'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Onboarding/Checkout')
            ->where('pageData.plans', fn (Collection $plans): bool => $plans->isNotEmpty())
            ->whereNot('pageData.personalEligibility', null));
});

test('(c) billing-required の着地は理由提示の素材 (owner 連絡先 / 問い合わせ導線) を持つ', function (): void {
    [$organization] = createOrganizationWithOwner(grandfatherFreePlan: false);
    $member = gateMemberOf($organization);

    $this->actingAs($member)->get(route('onboarding.billing-required'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Onboarding/BillingRequired')
            ->whereNot('pageData.ownerEmail', null)
            ->whereNot('pageData.contactUrl', null));
});

test('(c) 未契約の JSON は 402 + プラン未選択の文言', function (): void {
    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);

    $this->actingAs($owner)->getJson('/projects')
        ->assertStatus(402)
        ->assertJsonPath('message', GATE_NO_PLAN_MESSAGE);
});

test('(c) 有償契約 + 支払い不健全の JSON は 402 + 支払い文言 (D15 の既存契約は不変)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    contractPaidPlan($organization, status: 'canceled');

    $this->actingAs($owner)->getJson('/projects')
        ->assertStatus(402)
        ->assertJsonPath('message', GATE_BLOCKED_MESSAGE);
});

// ── (d) 無限ループ不在 (gate group 外の構造的 allowlist) ──

test('(d) 遮断先および課金系 route は gate group 外で再遮断されない', function (string $routeName): void {
    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);

    $this->actingAs($owner)->get(route($routeName))->assertOk();
})->with([
    'onboarding.checkout' => 'onboarding.checkout',
    'billing.index' => 'billing.index',
    'billing.tickets.show' => 'billing.tickets.show',
    'notifications.index' => 'notifications.index',
]);

test('(d) billing-required は manageBilling 非保持 member でも再遮断されない', function (): void {
    [$organization] = createOrganizationWithOwner(grandfatherFreePlan: false);
    $member = gateMemberOf($organization);

    $this->actingAs($member)->get(route('onboarding.billing-required'))->assertOk();
});

// ── (e) 反転の結論変更は plan_code IS NULL に閉じている ──

test('(e) plan_code 非 null の組織は移行 OR の有無で結論が変わらない', function (): void {
    $access = app(BillingAccess::class);

    // 分類 1-6 の fixture: (stripe_status, trial 終了, PM) → 期待 (= 移行 OR 撤去前と同一)
    $cases = [
        '1: active + entitled' => ['active', false, true, true],
        '2: active + trial 終了 + PM 無' => ['active', true, false, false],
        '3: past_due + PM 有' => ['past_due', true, true, true],
        '4: past_due + trial 終了 + PM 無' => ['past_due', true, false, false],
        '5: canceled' => ['canceled', false, true, false],
        '5: paused' => ['paused', false, true, false],
    ];

    foreach ($cases as $label => [$status, $trialEnded, $hasPm, $expected]) {
        [$organization] = createOrganizationWithOwner(grandfatherFreePlan: false);
        contractPaidPlan($organization, status: $status)->forceFill([
            'trial_ends_at' => $trialEnded ? CarbonImmutable::now()->subDay() : null,
            'has_payment_method' => $hasPm,
        ])->save();

        // 移行 OR は plan_code IS NULL にしか効かない = plan_code 非 null の結論は反転前後で同一
        expect($organization->plan_code)->not->toBeNull()
            ->and($access->hasActiveAccess($organization->refresh()))->toBe($expected, $label);
    }

    // 6: plan_code 非 null + subscription 行なし (fail-closed。反転前後で不変)
    [$orphan] = createOrganizationWithOwner(grandfatherFreePlan: false);
    $orphan->forceFill(['plan_code' => 'standard'])->save();
    expect($access->hasActiveAccess($orphan))->toBeFalse();
});

test('(e) entitled な subscription を持つ plan_code null 組織は grandfather 不要で通る (分類 11)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    createFakeSubscription($organization, status: 'active');

    expect($organization->plan_code)->toBeNull()
        ->and(app(BillingAccess::class)->hasActiveAccess($organization))->toBeTrue();

    $this->actingAs($owner)->get('/projects')->assertOk();
});
