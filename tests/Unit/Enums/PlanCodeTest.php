<?php

declare(strict_types=1);

use App\Enums\PlanCode;

/*
 * Stripe 決済対象プランの写像を全 case 網羅で固定する。
 *
 * SubscriptionService::assertStripeBillablePlan() は
 * PlanCode::requiresStripeCheckout() が false のプランを 422 (ValidationException) に倒す。
 * 「false → 422」という変換自体は SubscriptionPlanChangeTest の personal ケースが固定済みなので、
 * ここでは**写像側**を全 case で固定し、合成として enterprise / business の穴を埋める。
 *
 * Plan Factory は作らない: Plan / PlanPrice は参照データで、真実源は PlanSeeder +
 * config/quota.php + StripePriceLookupKeys の三点セットである。Factory を足すと
 * 「seeder と食い違うプラン定義」(quota 定義の無い plan_code、価格の無い有償プラン) を
 * テストが作れてしまう (docs/factories.md)。
 */

test('requiresStripeCheckout の写像が全 case で固定されている', function (): void {
    $expected = [
        'personal' => false,    // 無料 (PersonalPlanService::activate 経由)
        'starter' => true,
        'standard' => true,
        'business' => true,
        'enterprise' => false,  // 問い合わせ営業 (Checkout も in-app swap も通らない)
    ];

    // cases() 由来で網羅する = case 追加時に必ず落ちる
    expect(array_map(static fn (PlanCode $case): string => $case->value, PlanCode::cases()))
        ->toEqualCanonicalizing(array_keys($expected));

    foreach (PlanCode::cases() as $case) {
        expect($case->requiresStripeCheckout())
            ->toBe($expected[$case->value], "PlanCode::{$case->name} の決済対象判定");
    }
});
