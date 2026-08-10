<?php

declare(strict_types=1);

use App\Enums\Billing\OnboardingBillingState;
use Tests\Support\TsUnionValues;

/*
 * OnboardingBillingState (PHP enum) ⇔ resources/js/types/billing.ts の BillingStateValue
 * (TS literal union) の値集合同期 invariant。
 *
 * この union は /billing と /dashboard の**両方**で分岐に使われる (dashboard は
 * bug-hunt 20260811-003230 F-2-01 の是正で state 分岐になった)。case 追加が
 * TS 側の更新なしに通ると、新状態が画面で「どの分岐にも当たらない」= 無言の描画漏れになる。
 */

test('OnboardingBillingState の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    $enumValues = TsUnionValues::enumStringValues(OnboardingBillingState::cases());

    // 母集団 0 件での degenerate PASS を防ぐ (空 vs 空は一致してしまう)
    expect($enumValues)->not->toBeEmpty();

    expect(TsUnionValues::extract('resources/js/types/billing.ts', 'BillingStateValue'))
        ->toBe($enumValues);
});

test('billing.ts の抽出不能な union 名は fail する (degenerate PASS 防止の自己検証)', function (): void {
    expect(fn (): array => TsUnionValues::extract('resources/js/types/billing.ts', 'NoSuchUnionName'))
        ->toThrow(RuntimeException::class, 'degenerate PASS');
});
