<?php

declare(strict_types=1);

use App\Http\Middleware\RequireRecentAuth;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

/*
 * 撤去した Fortify 標準 step-up 機構 (`password.confirm` middleware) の
 * **実行時の不在**を deny-by-default で固定する (家系正典 surface-removal-absence-gate v1 の実行時層)。
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
 *
 * ★**撤去物 × 実行時観測軸** (正典 I1。該当しない軸は理由つきで宣言する):
 *   - route 名の不在      … **該当なし**。撤去したのは*機構*であり、同名 route 3 本
 *     (`password.confirm` / `password.confirm.store` / `password.confirmation`) は
 *     Fortify が救済 redirect / 状態プローブとして意図的に残す現役資産である
 *   - メソッド×URI の不在 … **該当なし** (`user/confirm-password` は現役)
 *   - クラス・表の不在    … **該当なし** (機構は vendor 側クラス。aicue が撤去したのは*適用*)
 *   - 実 HTTP 404・無副作用 … **該当なし** (同上)
 *   - 機構に対応する等価の実行時層 … **本ファイルの 3 層**が担う
 *
 * ★**静的層との分担**: `PasswordConfirmSurfaceAbsenceGateTest` が、列挙した middleware 位置
 *   (M1〜M3) への**参照の再流入**を字句で止める。**列挙外は静的層の保証外**であり、
 *   本テスト (解決済み middleware の全数走査) が**テスト起動時に実体化した全 route について
 *   補完する**が、**環境依存で実体化しない経路 (production 限定の条件分岐・未実行コード) までは
 *   保証しない**。
 * ★`PasskeyPackageContractTest` は `fortify-options.passkeys.confirmPassword` を**名指しで**
 *   pin する。本ファイルの層 2 は**設定木全体から生成した母集団**を見る (新しい設定ファイルに
 *   `confirmPassword` が生えたことを捕まえる) ので二重化ではない。
 */

/** route の診断ラベル (本ファイル固有の名前にする。Pest のファイルスコープ関数は衝突しうる)。 */
function routeLabelForPasswordConfirmGate(RoutingRoute $route): string
{
    return $route->getName() ?? implode('|', $route->methods()).':'.$route->uri();
}

/**
 * 設定木から `confirmPassword` キーを**生成**して集める (再帰。キー名の完全一致のみ)。
 *
 * 診断パスは文字列キーを `.` で、整数キーを `[0]` の角括弧で連結する。
 *
 * @param  array<array-key, mixed>  $tree
 * @param  array<string, mixed>  $found
 */
function collectConfirmPasswordKeysForPasswordConfirmGate(array $tree, string $prefix, array &$found): void
{
    foreach ($tree as $key => $value) {
        $path = is_int($key)
            ? $prefix.'['.$key.']'
            : ($prefix === '' ? $key : $prefix.'.'.$key);

        if ($key === 'confirmPassword') {
            $found[$path] = $value;
        }

        if (is_array($value)) {
            collectConfirmPasswordKeysForPasswordConfirmGate($value, $path, $found);
        }
    }
}

test('password.confirm middleware を持つ route が 1 本も無い', function (): void {
    /** @var Router $router */
    $router = app('router');

    $violations = [];
    $checked = 0;
    $routesWithResolvedMiddleware = 0;

    foreach (Route::getRoutes() as $route) {
        $checked++;

        // (a) alias 文字列そのものの再流入 (alias 登録側の復活を見る)
        foreach ($route->gatherMiddleware() as $declared) {
            if (! is_string($declared)) {
                continue;
            }
            if ($declared === 'password.confirm' || str_starts_with($declared, 'password.confirm:')) {
                $violations[] = 'alias: '.routeLabelForPasswordConfirmGate($route);
            }
        }

        // (b) group 展開・alias 解決・クラス直指定をすべて含む**解決済み**集合
        $resolved = $router->gatherRouteMiddleware($route);
        if ($resolved !== []) {
            $routesWithResolvedMiddleware++;
        }
        foreach ($resolved as $entry) {
            if (! is_string($entry)) {
                continue; // Closure middleware は名前を持たない
            }
            if (strtolower(explode(':', $entry, 2)[0]) === strtolower(RequirePassword::class)) {
                $violations[] = 'class: '.routeLabelForPasswordConfirmGate($route);
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
    // ★ middleware 解決自体が壊れて全 route が空を返す形で緑になるのを防ぐ
    expect($routesWithResolvedMiddleware)->toBeGreaterThan(0);
});

test('confirmPassword の設定キーは生成した母集団のうえで全件 false', function (): void {
    // ★ config()->all() は Config Repository の契約上すでに配列。
    //   is_array() を置くと PHPStan が「常に true」の不要条件として報告するため置かない。
    /** @var array<string, mixed> $all */
    $all = config()->all();

    /** @var array<string, mixed> $found */
    $found = [];
    collectConfirmPasswordKeysForPasswordConfirmGate($all, '', $found);

    // ★母集団が空なのに緑になる形を作らない (実測 2 件を下限に pin)
    expect(count($found))->toBeGreaterThanOrEqual(2);

    // ★既知の 2 パスが含まれること (パッケージ設定の未ロードを検出する代表値 pin)
    expect(array_keys($found))->toContain('fortify-options.two-factor-authentication.confirmPassword');
    expect(array_keys($found))->toContain('fortify-options.passkeys.confirmPassword');

    $enabled = array_keys(array_filter($found, static fn (mixed $value): bool => $value !== false));
    expect($enabled)->toBe([], 'confirmPassword が false 以外: '.implode(', ', $enabled));
});

test('置換先の generic recent-auth が生きている', function (): void {
    /** @var Router $router */
    $router = app('router');

    expect(Route::has('recent-auth.confirm'))->toBeTrue();
    expect(Route::has('recent-auth.password'))->toBeTrue();

    $guarded = 0;
    foreach (Route::getRoutes() as $route) {
        foreach ($router->gatherRouteMiddleware($route) as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            // ★alias 名 ('recent-auth') をハードコードしない。解決済み集合で見る
            if (strtolower(explode(':', $entry, 2)[0]) === strtolower(RequireRecentAuth::class)) {
                $guarded++;
                break;
            }
        }
    }

    expect($guarded)->toBeGreaterThan(0, 'recent-auth を実際に適用している route が 1 本も無い');
});
