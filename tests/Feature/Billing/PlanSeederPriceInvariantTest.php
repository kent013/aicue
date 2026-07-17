<?php

declare(strict_types=1);

use App\Enums\Billing\PlanPriceKind;
use App\Models\Billing\Plan;

/*
 * seed fixture 不変条件: 有償プラン (Checkout 対象) は current な base Price を必ず持つ。
 * ManualTestSeeder / BillingAccess の「plan_code 非 null ⇔ 有償契約」判定は「有償プランは
 * currentPrice(Base) を持つ」という前提に立つ。この前提が崩れると seeded 有償組織が free 扱いに
 * silently 退行するため、判定式 (currentPrice) に依存しない独立検証でここを固定する。
 * (本番コードのプラン名分岐ではなく fixture 仕様の検証。docs 07 §4 の規約には抵触しない)
 */

test('有償プラン standard は current base Price を持つ (seed 不変条件)', function (): void {
    $standard = Plan::query()->where('code', 'standard')->firstOrFail();

    expect($standard->currentPrice(PlanPriceKind::Base))->not->toBeNull();
});

test('有償プラン starter は current base Price を持つ (seed 不変条件)', function (): void {
    $starter = Plan::query()->where('code', 'starter')->firstOrFail();

    expect($starter->currentPrice(PlanPriceKind::Base))->not->toBeNull();
});

test('personal プランは Stripe Price を持たない (activate 経由の無料プラン)', function (): void {
    $personal = Plan::query()->where('code', 'personal')->firstOrFail();

    expect($personal->currentPrice(PlanPriceKind::Base))->toBeNull();
    expect($personal->prices()->count())->toBe(0);
});

test('全プランの monthly_ticket_grant が 0 (D28: 月次付与は廃止)', function (): void {
    expect(Plan::query()->pluck('monthly_ticket_grant', 'code')->all())
        ->toEqual(['free' => 0, 'personal' => 0, 'starter' => 0, 'standard' => 0]);
});

test('free プランは Stripe Price を持たない (Checkout 対象外の未契約既定)', function (): void {
    $free = Plan::query()->where('code', 'free')->firstOrFail();

    expect($free->currentPrice(PlanPriceKind::Base))->toBeNull();
    expect($free->prices()->count())->toBe(0);
});
