<?php

declare(strict_types=1);

use App\DataTransferObjects\Billing\PlanDto;
use App\Enums\Billing\PlanPriceKind;
use App\Models\Billing\Plan;

/*
 * PlanDto::fromModel()。Plan の真実源は PlanSeeder (テストでも $seed=true で毎回走る)。
 *
 * currentBaseAmount の契約: 現行 (is_current=true) の base price の金額。base price を
 * 持たないプランは null = 無料表示契約 (PricingPlanDto::baseAmountJpy と同一意味論)。
 */

function seededPlan(string $code): Plan
{
    return Plan::query()->where('code', $code)->firstOrFail();
}

test('fromModel は code / name / is_active と現行 base price をマップする', function (): void {
    $plan = seededPlan('starter');

    $dto = PlanDto::fromModel($plan);

    expect($dto->code)->toBe('starter')
        ->and($dto->name)->toBe('Starter')
        ->and($dto->currentBaseAmount)->toBe(980)
        ->and($dto->isActive)->toBeTrue();
});

test('base price を持たないプランは currentBaseAmount が null (無料表示契約)', function (): void {
    $plan = seededPlan('personal');

    expect($plan->currentPrice(PlanPriceKind::Base))->toBeNull()
        ->and(PlanDto::fromModel($plan)->currentBaseAmount)->toBeNull();
});

test('is_active=false は isActive=false としてマップされる', function (): void {
    $plan = seededPlan('standard');
    $plan->forceFill(['is_active' => false])->save();

    expect(PlanDto::fromModel($plan->fresh())->isActive)->toBeFalse();
});

test('is_current でない base price は currentBaseAmount に載らない', function (): void {
    $plan = seededPlan('starter');
    // 現行 price を退役させる (is_current=true ⇔ active_to IS NULL の invariant を守る)
    $plan->prices()->where('kind', PlanPriceKind::Base->value)->where('is_current', true)
        ->update(['is_current' => false, 'active_to' => now()]);

    expect(PlanDto::fromModel($plan->fresh())->currentBaseAmount)->toBeNull();
});

test('toArray は Shape どおりのキーのみを返す (席 / limit 系は非移植)', function (): void {
    $dto = PlanDto::fromModel(seededPlan('standard'));

    expect($dto->toArray())->toBe([
        'code' => 'standard',
        'name' => 'Standard',
        'currentBaseAmount' => 4980,
        'isActive' => true,
    ]);
});
