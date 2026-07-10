<?php

declare(strict_types=1);

use App\Enums\QuotaKey;

/*
 * config/quota.php の limits キー ⇔ App\Enums\QuotaKey の整合を CI で固定する。
 * キー追加時に enum への case 追加を忘れると型安全な参照 (QuotaService::check) が
 * できないため、config 側のキーは必ず enum の case であることを強制する。
 */

test('config/quota.php の全 limits キーが QuotaKey の case である', function (): void {
    $plans = config()->array('quota.plans');
    $enumValues = QuotaKey::values();

    foreach ($plans as $planCode => $limits) {
        expect($limits)->toBeArray();
        /** @var array<string, int> $limits */
        foreach (array_keys($limits) as $key) {
            expect(in_array($key, $enumValues, true))->toBeTrue(
                "config/quota.php の limits キー '{$key}' (plan: {$planCode}) に対応する QuotaKey の case がありません",
            );
        }
    }
});
