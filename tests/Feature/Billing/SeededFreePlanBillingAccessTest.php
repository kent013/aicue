<?php

declare(strict_types=1);

use App\Enums\Billing\PlanPriceKind;
use App\Enums\OrganizationRole;
use App\Models\Billing\Plan;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\ManualTestSeeder;
use Illuminate\Support\Str;

/*
 * F-C3 回帰: ManualTestSeeder が生成する無料プラン組織の全ロールが、課金ゲート
 * (require-active-subscription) を通過して中核業務 route に到達できることを固定する。
 * 根本原因は seeder が無料プランにも plan_code を載せ、BillingAccess が active subscription を
 * 要求して締め出していたこと (devnotes/20260713-1633-seeder-free-plan-billing)。
 *
 * **P4 (T075) のゲート反転後**: 無料枠は「plan_code が null であること」ではなく
 * `organizations.free_plan_code = 'personal'` の明示申告 (ActiveFreePlan) で表現する。
 * `plans` の `free` 行は D11 で撤去され、後継は `personal`。
 * ManualTestSeeder は当該組織を `PersonalPlanService::activate()` で有効化するため、
 * 本テストが固定する「無料プラン組織は業務 route に到達できる」不変条件は反転後も生きている
 * (通過の根拠が『素通り』から『ActiveFreePlan として許可』へ変わっただけ)。
 */

/**
 * 無料プラン (current base Price を持たない) を取得する。
 *
 * P4 で free 行が撤去されたため後継の personal を code で固定する
 * (starter 以降は Price を持つので「Price 無しの最初の Plan」でも一意だが、
 *  seed 構成の変化で非決定にならないよう code 指定を維持する)。
 */
function seededFreePlan(): Plan
{
    $plan = Plan::query()->where('code', 'personal')->firstOrFail();
    if ($plan->currentPrice(PlanPriceKind::Base) !== null) {
        throw new RuntimeException('無料プランに Price が付いている (seed 不変条件の破れ)');
    }

    return $plan;
}

test('seeded 無料プラン組織の全ロールが /organizations/{slug}/projects に到達できる (F-C3 回帰)', function (OrganizationRole $role): void {
    $this->seed(ManualTestSeeder::class);

    $plan = seededFreePlan();
    $organization = Organization::query()->where('name', "{$plan->name}プラン組織")->firstOrFail();
    // seeder が不変条件を守っている: plan_code は載せず、無料枠は free_plan_code の明示申告で表現する
    expect($organization->plan_code)->toBeNull()
        ->and($organization->free_plan_code)->toBe($plan->code);

    $email = Str::afterLast($role->value, '_')."-{$plan->code}@example.com";
    $user = User::whereBlind('email', 'email_index', $email)->firstOrFail();

    // assertOk() で 302→billing の redirect を検出。加えて Inertia コンポーネント名で
    // 「200 だが別画面」ケースも塞ぐ (ProjectController@index の Inertia::render 先)。
    $this->actingAs($user)->get("/organizations/{$organization->slug}/projects")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Projects/Index'));
})->with([
    'owner' => OrganizationRole::Owner,
    'admin' => OrganizationRole::Admin,
    'member' => OrganizationRole::Member,
]);

test('seeded 有償組織は plan_code と active subscription を持ち課金ゲートを通過する', function (): void {
    $this->seed(ManualTestSeeder::class);

    $paid = Plan::query()->get()
        ->first(fn (Plan $p): bool => $p->currentPrice(PlanPriceKind::Base) !== null);
    expect($paid)->not->toBeNull();

    $organization = Organization::query()->where('name', "{$paid?->name}プラン組織")->firstOrFail();
    expect($organization->plan_code)->toBe($paid?->code);
    expect($organization->subscription('default')?->stripe_status)->toBe('active');

    $owner = User::whereBlind('email', 'email_index', "owner-{$paid?->code}@example.com")->firstOrFail();
    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects")->assertOk();
});
