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
 * F-C3 回帰: ManualTestSeeder が生成する Free (Stripe Price 無し) プラン組織の全ロールが、
 * 課金ゲート (require-active-subscription) を素通りして中核業務 route に到達できることを固定する。
 * 根本原因は seeder が Free にも plan_code='free' を載せ、BillingAccess が active subscription を
 * 要求して締め出していたこと (devnotes/20260713-1633-seeder-free-plan-billing)。
 */

/**
 * Free プラン (current base Price を持たない) を取得する。
 *
 * personal も Price を持たないため「Price 無しの最初の Plan」では対象が非決定になる。
 * 本テストの関心は Free プラン組織のゲート素通りなので code で固定する。
 */
function seededFreePlan(): Plan
{
    $plan = Plan::query()->where('code', 'free')->firstOrFail();
    if ($plan->currentPrice(PlanPriceKind::Base) !== null) {
        throw new RuntimeException('Free プランに Price が付いている (seed 不変条件の破れ)');
    }

    return $plan;
}

test('seeded Free 組織の全ロールが /projects に到達できる (F-C3 回帰)', function (OrganizationRole $role): void {
    $this->seed(ManualTestSeeder::class);

    $plan = seededFreePlan();
    $organization = Organization::query()->where('name', "{$plan->name}プラン組織")->firstOrFail();
    expect($organization->plan_code)->toBeNull(); // seeder が不変条件を守っている

    $email = Str::afterLast($role->value, '_')."-{$plan->code}@example.com";
    $user = User::whereBlind('email', 'email_index', $email)->firstOrFail();

    // assertOk() で 302→billing の redirect を検出。加えて Inertia コンポーネント名で
    // 「200 だが別画面」ケースも塞ぐ (ProjectController@index の Inertia::render 先)。
    $this->actingAs($user)->get('/projects')
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
    $this->actingAs($owner)->get('/projects')->assertOk();
});
