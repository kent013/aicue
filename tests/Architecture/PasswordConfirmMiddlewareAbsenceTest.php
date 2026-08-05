<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * `password.confirm` middleware の **全 route での不在** を deny-by-default で固定する。
 *
 * 本アプリは Fortify 標準の password.confirm (3h 窓・パスワード限定) を撤去し、
 * generic recent-auth (15 分窓・パスワード or 再SSO or パスキー) へ統一している。
 * password.confirm が復活すると:
 *   1. SSO-only ユーザー (password 未設定) がその route で**詰む** (satisfier が無い)
 *   2. confirmPasswordView は recent-auth.confirm への redirect でしかなく
 *      `auth.password_confirmed_at` を満たせないため無限ループになる (bug-hunt F-11)
 *
 * 特に laravel/passkeys は config 既定が `management_middleware = ['password.confirm']` で、
 * `fortify-options.passkeys.confirmPassword` を落とすと即座に復活する。
 */
test('password.confirm middleware を持つ route が 1 本も無い', function (): void {
    $violations = [];
    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        $checked++;

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }
            if ($middleware === 'password.confirm' || str_starts_with($middleware, 'password.confirm:')) {
                $violations[] = $route->getName() ?? implode('|', $route->methods()).':'.$route->uri();
            }
        }
    }

    expect($violations)->toBe(
        [],
        'password.confirm は generic recent-auth へ置換済み。復活すると SSO-only ユーザーが詰む: '
        .implode(', ', $violations),
    );
    // route 走査自体が空振りしていないこと
    expect($checked)->toBeGreaterThan(0);
});
