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

/*
 * 本テストは D28 (月次付与の廃止) を pin するだけでなく、**P5 の会計上の既知窓が
 * 到達不能であることの根拠**でもある。
 *
 * TicketLedgerService::nearestMonthlyExpiry() は「生きた有限期限 monthly grant が
 * 2 本以上あり期限が異なる」場合に消費行の expires_at を実際の供給元と一致させられない。
 * 現行はこの前提が構造的に成立しない: monthly source の書き手は grantMonthly() のみで、
 * 定期付与経路 (StripeWebhookProcessor::grantMonthlyTickets) は monthly_ticket_grant <= 0 で
 * early return するため、残る経路は org 生涯 1 回の signup grant だけになる。
 *
 * **ここを 1 以上に変えると窓が開く**。変更する場合は nearestMonthlyExpiry() の契約と
 * TicketBalanceAccountingTest の「[既知窓]」2 本を必ず見直すこと。
 */
test('全プランの monthly_ticket_grant が 0 (D28: 月次付与は廃止。P5 の既知窓の到達不能性もここが担保する)', function (): void {
    expect(Plan::query()->pluck('monthly_ticket_grant', 'code')->all())
        ->toEqual(['personal' => 0, 'starter' => 0, 'standard' => 0]);
});

test('free プラン行は撤去済み (D11: 後継は personal。未契約の既定は free_plan_code で表現する)', function (): void {
    expect(Plan::query()->where('code', 'free')->exists())->toBeFalse();
});
