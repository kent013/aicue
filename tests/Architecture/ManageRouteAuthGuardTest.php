<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * 管理メニュー (/manage/*) の guard invariant (deny-by-default)。
 *
 * /manage/ 配下の全 named route は auth + verified middleware を持たなければならない
 * (管理メニューは PII (メンバー email) を含む管理者専用画面群。将来 /manage/ 配下へ
 * route を足したときの guard 漏れを構造的に落とす)。
 * 認可 (manageMembers 等) は各 Controller の Gate::authorize の責務 (Feature テストで固定)。
 */
test('/manage/ 配下の全 route は auth + verified middleware を持つ', function (): void {
    $checked = 0;
    $violations = [];

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'manage/')) {
            continue;
        }

        $name = $route->getName() ?? $route->uri();
        $middleware = $route->gatherMiddleware();

        foreach (['auth', 'verified'] as $required) {
            if (! in_array($required, $middleware, true)) {
                $violations[] = "route {$name} に {$required} middleware が無い";
            }
        }
        $checked++;
    }

    expect($violations)->toBe([]);
    // route が 1 本も検査されない (= /manage/ route が消えた/リネームされた) 場合も fail させ、
    // テスト自体の空振り drift を検知する
    expect($checked)->toBeGreaterThan(0);
});
