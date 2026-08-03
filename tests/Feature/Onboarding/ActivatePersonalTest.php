<?php

declare(strict_types=1);

use App\Enums\Billing\OnboardingBillingState;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\BillingAccess;
use App\Services\Billing\PersonalPlanService;
use App\Services\Billing\TicketPricingService;
use Illuminate\Support\Facades\DB;

/*
 * Personal (free) プランの有効化 (POST /onboarding/activate-personal。current org スコープ)。
 *
 * Controller は PersonalPlanService::activate() を呼ぶだけ (付与ロジックを再実装しない =
 * 二重付与源を作らない)。条件不成立は 500 ではなく 422 (errors.plan_code) へ落とす。
 */

function activatePersonalPayload(): array
{
    return ['declaration' => true];
}

test('current org 不在なら 404', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/onboarding/activate-personal', activatePersonalPayload())
        ->assertNotFound();
});

test('current org に非所属なら 404 (認可より前 = 403 で存在を漏らさない)', function (): void {
    [$organization] = createOrganizationWithOwner(grandfatherFreePlan: false);
    $outsider = User::factory()->create();
    $outsider->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($outsider)
        ->post('/onboarding/activate-personal', activatePersonalPayload())
        ->assertNotFound();
});

test('manageBilling なし member は 403', function (): void {
    [$organization] = createOrganizationWithOwner(grandfatherFreePlan: false);
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($member)
        ->post('/onboarding/activate-personal', activatePersonalPayload())
        ->assertForbidden();
});

test('declaration 未チェックは redirect-back + errors.declaration (有効化されない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);

    $this->actingAs($owner)
        ->post('/onboarding/activate-personal', ['declaration' => false])
        ->assertSessionHasErrors(['declaration' => '個人利用であることの確認が必要です。']);

    expect($organization->fresh()?->free_plan_code)->toBeNull();
});

test('declaration 欠落の XHR は 422', function (): void {
    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);

    $this->actingAs($owner)
        ->postJson('/onboarding/activate-personal', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['declaration' => '個人利用であることの確認が必要です。']);
});

test('保護キーを payload に混ぜると 422 (mass-assignment 入口防御)', function (): void {
    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);

    $this->actingAs($owner)
        ->postJson('/onboarding/activate-personal', [
            'declaration' => true,
            'personal_declared_by_user_id' => 999,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['personal_declared_by_user_id']);
});

test('成功すると free entitlement が確定し dashboard へ redirect + 枚数入り flash', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    $tickets = app(TicketPricingService::class)->signupGrantTickets();

    $this->actingAs($owner)
        ->post('/onboarding/activate-personal', activatePersonalPayload())
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('success', sprintf(
            'パーソナルプラン（無料）を開始しました。無料チケット %d 枚をお付けしました。',
            $tickets,
        ));

    $fresh = $organization->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh->free_plan_code)->toBe(PersonalPlanService::FREE_PLAN_CODE)
        ->and($fresh->personal_declared_by_user_id)->toBe($owner->getKey())
        ->and($fresh->personal_declared_at)->not->toBeNull()
        ->and($fresh->free_plan_activated_at)->not->toBeNull()
        ->and($fresh->signup_tickets_granted_at)->not->toBeNull()
        // 有効化直後は ActiveFreePlan (= 以後 checkout は billing.index へ逃がす)
        ->and(app(BillingAccess::class)->state($fresh))->toBe(OnboardingBillingState::ActiveFreePlan);

    expect(DB::table('ticket_ledger_entries')
        ->where('organization_id', $organization->getKey())
        ->where('idempotency_key', 'like', 'signup_grant:%')
        ->count())->toBe(1);
});

test('二重 POST は冪等 (2 回目は付与なしの文言 + signup_grant は 1 行のまま)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);

    $this->actingAs($owner)->post('/onboarding/activate-personal', activatePersonalPayload());
    $this->actingAs($owner)
        ->post('/onboarding/activate-personal', activatePersonalPayload())
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('success', 'パーソナルプラン（無料）を開始しました。');

    expect(DB::table('ticket_ledger_entries')
        ->where('organization_id', $organization->getKey())
        ->where('idempotency_key', 'like', 'signup_grant:%')
        ->count())->toBe(1);
});

test('付与マーカー済みの org は granted=false の文言で有効化される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    $organization->forceFill(['signup_tickets_granted_at' => now()])->save();

    $this->actingAs($owner)
        ->post('/onboarding/activate-personal', activatePersonalPayload())
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('success', 'パーソナルプラン（無料）を開始しました。');

    expect(DB::table('ticket_ledger_entries')
        ->where('organization_id', $organization->getKey())
        ->where('idempotency_key', 'like', 'signup_grant:%')
        ->count())->toBe(0);
});

test('既に free personal org を持つ user は errors.plan_code (500 にしない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    Organization::factory()->freePersonal($owner)->create();

    $this->actingAs($owner)
        ->post('/onboarding/activate-personal', activatePersonalPayload())
        ->assertSessionHasErrors([
            'plan_code' => '既にパーソナルプラン（無料）の組織をお持ちのため、この組織では選択できません。',
        ]);

    expect($organization->fresh()?->free_plan_code)->toBeNull();
});

test('メンバー超過の org は errors.plan_code (XHR は 422)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    // MAX_MEMBERS = 3 を超える (owner + 3 名 = 4 名)
    for ($i = 0; $i < 3; $i++) {
        attachOrganizationMember($organization);
    }

    $this->actingAs($owner)
        ->postJson('/onboarding/activate-personal', activatePersonalPayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'plan_code' => sprintf(
                'メンバーが %d 名を超えているためパーソナルプランは選択できません。',
                PersonalPlanService::MAX_MEMBERS,
            ),
        ]);

    expect($organization->fresh()?->free_plan_code)->toBeNull();
});

test('有効な有償契約がある org は errors.plan_code', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    contractPaidPlan($organization, status: 'active');

    $this->actingAs($owner)
        ->post('/onboarding/activate-personal', activatePersonalPayload())
        ->assertSessionHasErrors([
            'plan_code' => '有効な有償契約があるためパーソナルプランは選択できません。',
        ]);

    expect($organization->fresh()?->free_plan_code)->toBeNull();
});

test('throttle:10,1 が効く (11 回目は 429)', function (): void {
    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);

    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($owner)
            ->post('/onboarding/activate-personal', activatePersonalPayload())
            ->assertStatus(302);
    }

    $this->actingAs($owner)
        ->post('/onboarding/activate-personal', activatePersonalPayload())
        ->assertStatus(429);
});

test('未認証は login へ', function (): void {
    $this->post('/onboarding/activate-personal', activatePersonalPayload())
        ->assertRedirect('/login');
});
