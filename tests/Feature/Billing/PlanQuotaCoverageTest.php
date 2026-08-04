<?php

declare(strict_types=1);

use App\Models\Billing\Plan;
use Database\Seeders\PlanSeeder;

/*
 * quota 定義欠落の機械的追跡。
 *
 * limits の無い plan_code が organizations.plan_code に入ると QuotaService::limits() の
 * `?? []` により **無制限扱い**になる (静かな課金事故)。現状 config/quota.php には
 * business / enterprise の entry が無いため、その 2 つを PlanSeeder に足した瞬間に
 * 本テストが red になり、quota 定義の追加を強制する。
 *
 * PlanCode enum 全 case との一致は要求しない: enterprise は問い合わせ営業で
 * Plan 行も plan_prices も持たず、organizations.plan_code が付く経路が無い。
 * 追跡すべきは「seed されて実際に plan_code になりうるプラン」だけである。
 */

test('PlanSeeder が投入する plan code は必ず config/quota.php に limits を持つ', function (): void {
    // テスト全体で $seed = true のため PlanSeeder は既に走っているが、
    // 本テストが seeder に依存していることを明示するために明示的に走らせる
    // (再実行安全な upsert seeder であることは PlanSeeder の docblock が保証する)。
    $this->seed(PlanSeeder::class);

    $seededCodes = Plan::query()->pluck('code')->all();
    $configured = array_keys(config()->array('quota.plans'));

    expect($seededCodes)->not->toBeEmpty();
    foreach ($seededCodes as $code) {
        expect(in_array($code, $configured, true))->toBeTrue(
            "PlanSeeder が投入する plan '{$code}' に config/quota.php の limits がありません (無制限扱いになる)",
        );
    }
});
