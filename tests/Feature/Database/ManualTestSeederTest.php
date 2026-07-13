<?php

declare(strict_types=1);

use App\Enums\Billing\PlanPriceKind;
use App\Enums\OrganizationRole;
use App\Models\Billing\Plan;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\BillingAccess;
use Database\Seeders\ManualTestSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * ManualTestSeeder (WP50): ロール × プラン総当たりの手動テスト用ユーザー/組織を
 * 固定パスワードで冪等作成する (ドメイン非依存の汎用版)。
 */

test('ロール × プラン総当たりのユーザーと組織を作成する', function (): void {
    $this->seed(ManualTestSeeder::class);

    foreach (Plan::query()->orderBy('sort_order')->get() as $plan) {
        $organization = Organization::query()->where('name', "{$plan->name}プラン組織")->firstOrFail();

        // 有償判定はループ先頭で 1 回だけ算出 (currentPrice の多重クエリを避け分岐を一元化)
        $isPaid = $plan->currentPrice(PlanPriceKind::Base) !== null;

        // plan_code の不変条件: Stripe Price を持つ有償プランのみ plan_code + active subscription を持つ。
        // Free (Price 無し) は未契約 = plan_code null (BillingAccess の free-tier 許可の前提)。
        if ($isPaid) {
            expect($organization->plan_code)->toBe($plan->code);
            expect($organization->subscription('default')?->stripe_status)->toBe('active');
        } else {
            expect($organization->plan_code)->toBeNull();
            expect($organization->subscription('default'))->toBeNull(); // free には契約行が無い (根本原因への回帰耐性)
        }
        // どちらの tier も業務 route を利用してよい (free tier / 有償 active はともに許可)
        expect(app(BillingAccess::class)->hasActiveAccess($organization))->toBeTrue();

        foreach (OrganizationRole::cases() as $role) {
            $email = Str::afterLast($role->value, '_')."-{$plan->code}@example.com";
            $user = User::whereBlind('email', 'email_index', $email)->first();

            expect($user)->not->toBeNull();
            expect($user?->organizationRole($organization))->toBe($role);
            expect($user?->current_organization_id)->toBe($organization?->id);
            expect(Hash::check('password123', $user?->getAttribute('password') ?? ''))->toBeTrue();
        }
    }
});

test('複数組織所属ユーザーとメール未認証ユーザーも作成する', function (): void {
    $this->seed(ManualTestSeeder::class);

    $multi = User::whereBlind('email', 'email_index', 'multi-org@example.com')->first();
    expect($multi)->not->toBeNull();
    // 全プラン組織に所属している
    expect($multi?->organizations()->count())->toBe(Plan::query()->count());

    $unverified = User::whereBlind('email', 'email_index', 'unverified@example.com')->first();
    expect($unverified)->not->toBeNull();
    expect($unverified?->email_verified_at)->toBeNull();
});

test('再実行してもユーザー・組織が増えない (冪等)', function (): void {
    $this->seed(ManualTestSeeder::class);

    $userCount = User::query()->count();
    $organizationCount = Organization::query()->count();

    $this->seed(ManualTestSeeder::class);

    expect(User::query()->count())->toBe($userCount);
    expect(Organization::query()->count())->toBe($organizationCount);
});
