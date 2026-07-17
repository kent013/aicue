<?php

declare(strict_types=1);

use App\Models\Billing\Plan;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * plans.is_active による公開制御。PricingService::listPublicPlans() の is_active
 * フィルタが「プランを料金表に出すか」の唯一の場所であることを固定する
 * (PlanSeeder は新規作成時のみ is_active=true を確定するため、運用者が管理画面で
 * 非公開にしたプランは seed 再実行後も非公開のまま留まる)。
 */

test('is_active=false の Plan は /pricing の props に出ない', function (): void {
    Plan::query()->where('code', 'standard')->update(['is_active' => false]);

    $this->get('/pricing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('page.plans', 3)
            ->where('page.plans.0.code', 'free')
            ->where('page.plans.1.code', 'personal')
            ->where('page.plans.2.code', 'starter'));
});

test('is_active=true に戻した Plan は /pricing の props に出る', function (): void {
    Plan::query()->where('code', 'standard')->update(['is_active' => false]);
    Plan::query()->where('code', 'standard')->update(['is_active' => true]);

    $this->get('/pricing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('page.plans', 4)
            ->where('page.plans.3.code', 'standard'));
});

test('seed 直後は全プランが is_active=true (公開方針)', function (): void {
    expect(Plan::query()->where('is_active', false)->count())->toBe(0);
    expect(Plan::query()->where('is_active', true)->pluck('code')->all())
        ->toEqualCanonicalizing(['free', 'personal', 'starter', 'standard']);
});
