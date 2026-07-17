<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| free_plan_code 書き込み経路の invariant
|--------------------------------------------------------------------------
|
| `organizations.free_plan_code` は課金状態 (free entitlement) を確定させる状態キーのため、
| 書き込み (array key 代入 / プロパティ代入) は PersonalPlanService に閉じる。値域
| ('personal' のみ) を DB check constraint ではなくアプリ側定数
| (PersonalPlanService::FREE_PLAN_CODE) で守る前提の機械的補助。
| 読み取り (`->free_plan_code` の比較) は対象外。
*/

test('app/ 内の free_plan_code 書き込みは PersonalPlanService に閉じる', function (): void {
    $allowlist = [
        'app/Services/Billing/PersonalPlanService.php',
    ];

    // 書き込みパターン: array key 代入 ('free_plan_code' => / "free_plan_code" =>) と
    // プロパティ代入 (->free_plan_code = 値。=== / !== 比較は除外)。
    $finder = Finder::create()
        ->in(base_path('app'))
        ->files()
        ->name('*.php')
        ->contains('/([\'"])free_plan_code\1\s*=>|->free_plan_code\s*=[^=]/');

    $violations = [];
    foreach ($finder as $file) {
        $relative = str_replace(base_path().'/', '', (string) $file->getRealPath());
        if (! in_array($relative, $allowlist, true)) {
            $violations[] = $relative;
        }
    }

    expect($violations)->toBe([], 'free_plan_code の書き込みは PersonalPlanService 経由に限定してください: '.implode(', ', $violations));
});
