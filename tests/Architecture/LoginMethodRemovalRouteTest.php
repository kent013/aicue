<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureLoginMethodRemains;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/*
 * 「ログイン手段を減らす route」の分類 invariant (deny-by-default)。
 *
 * ログイン手段を全部消して自分で締め出す事故は復旧コストが高く、現場を止める。
 * 候補となる route を構造的に列挙し、
 *   (a) guard 必須 (ensure-login-method middleware を持つ) か
 *   (b) 免除 (理由文字列つき)
 * のどちらかに **必ず分類させる**。分類漏れは fail = 将来 SSO 解除 / passkey 削除 /
 * パスワード削除 route を足したとき、guard の要否を必ず考えさせる。
 *
 * 本テストは分類漏れ・drift を落とす役割に限定する。実挙動 (投影評価・ロック・422) は
 * tests/Feature/Auth/LoginMethodRetentionTest.php が担保する。
 *
 * 候補の構造的定義: 認証系 URI 空間 ('user/passkeys', 'settings/social', 'user/password',
 * 'settings/account') に属する破壊的メソッド (DELETE / PUT / PATCH) の named route。
 */

/** @return list<string> guard 必須の route 名 */
function loginMethodRemovalGuardedRoutes(): array
{
    return [
        // passkey 削除 (credential 集合を減らす。最初の被保護 route)
        'passkey.destroy',
    ];
}

/** @return array<string, string> route 名 => 免除理由 (非空必須) */
function loginMethodRemovalExemptRoutes(): array
{
    return [
        // アカウント自体を消す操作。手段が 0 になるのは目的であって事故ではない。
        // 別途 recent-auth (step-up) で保護済み。
        'settings.account.destroy' => 'アカウント除去そのものであり、手段が残らないことが意図',
        // 母集団は URI 接頭辞 ('settings/account') × 破壊的メソッド (DELETE) で定義されるため、
        // 退会**予約の取消**もここに入る。実際にはログイン手段を 1 つも触らない
        // (users の予約列 2 本を null に戻すだけ) ので免除する。
        // ★設計は「予約は認証手段を減らさないので登録不要」と書いていたが、母集団は
        //   「認証手段を触るか」ではなく URI 接頭辞で決まるため実測では登録が必要だった。
        'settings.account.deletion-request.destroy' => '退会予約の取消であり認証手段を一切触らない '
            .'(users の予約列 2 本を null に戻すだけ。むしろアカウント消失を防ぐ救済経路)',
        // 第二要素の除去であってログイン手段の除去ではない
        // (TOTP を外してもパスワード / SSO / passkey は残る)。
        'two-factor.disable' => '第二要素の除去でありログイン手段ではない',
        // 変更であって除去ではない。current_password 必須で null 化できない。
        'user-password.update' => 'パスワードの変更であり除去経路ではない (current_password 必須)',
    ];
}

function routeHasLoginMethodGuard(RoutingRoute $route): bool
{
    $middleware = $route->gatherMiddleware();

    return in_array('ensure-login-method', $middleware, true)
        || in_array(EnsureLoginMethodRemains::class, $middleware, true);
}

test('ログイン手段を減らしうる route は guard 必須か免除のどちらかに分類されている', function (): void {
    $prefixes = ['user/passkeys', 'settings/social', 'user/password', 'settings/account'];
    $destructive = ['DELETE', 'PUT', 'PATCH'];

    $guarded = loginMethodRemovalGuardedRoutes();
    $exempt = loginMethodRemovalExemptRoutes();

    $checked = 0;
    $violations = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();
        $matchesPrefix = false;
        foreach ($prefixes as $prefix) {
            if (str_starts_with($uri, $prefix)) {
                $matchesPrefix = true;
                break;
            }
        }
        if (! $matchesPrefix || array_intersect($destructive, $route->methods()) === []) {
            continue;
        }

        $name = $route->getName();
        if ($name === null) {
            $violations[] = "route {$uri} に名前が無く分類できない";

            continue;
        }

        $checked++;

        if (array_key_exists($name, $exempt)) {
            expect(trim($exempt[$name]))->not->toBe('', "route '{$name}' の免除理由が空 (運用劣化)");

            continue;
        }

        if (! in_array($name, $guarded, true)) {
            $violations[] = "route '{$name}' が未分類 (guard 必須 or 免除のどちらかに登録すること)";

            continue;
        }

        if (! routeHasLoginMethodGuard($route)) {
            $violations[] = "route '{$name}' に ensure-login-method middleware が付与されていない";
        }
    }

    expect($violations)->toBe([]);
    // 1 本も検査されない = 候補判定が壊れた (空振り drift) ので fail させる
    expect($checked)->toBeGreaterThan(0);
});

test('guard 必須リストの route は全て実在する', function (): void {
    $routes = app('router')->getRoutes();
    $routes->refreshNameLookups();

    foreach (loginMethodRemovalGuardedRoutes() as $name) {
        expect($routes->getByName($name))->not->toBeNull("route '{$name}' が存在しない (リネーム/削除に追従していない)");
    }
});

test('免除リストの route は全て実在する', function (): void {
    $routes = app('router')->getRoutes();
    $routes->refreshNameLookups();

    foreach (array_keys(loginMethodRemovalExemptRoutes()) as $name) {
        expect($routes->getByName($name))->not->toBeNull("route '{$name}' が存在しない (陳腐化した免除登録)");
    }
});

/*
 * **allowlist 外への付与も禁じる (deny-by-default の逆方向)**。
 *
 * EnsureLoginMethodRemains は $next() を DB transaction 内で実行するため、
 * controller / 同期 listener / Responsable 変換 / flash まで transaction に入る。
 * 適用範囲が無自覚に広がると副作用範囲が急拡大する
 * (streamed response / 外部 I/O / afterCommit でない queue dispatch は特に危険)。
 * 付与してよい route を allowlist に固定し、増やすときは必ず判断させる。
 */
test('ensure-login-method middleware を持つ route は guard 必須リストのみ', function (): void {
    $guarded = loginMethodRemovalGuardedRoutes();
    $unexpected = [];

    foreach (Route::getRoutes() as $route) {
        if (! routeHasLoginMethodGuard($route)) {
            continue;
        }
        $name = $route->getName() ?? $route->uri();
        if (! in_array($name, $guarded, true)) {
            $unexpected[] = "route '{$name}' に ensure-login-method が付いているが allowlist に無い"
                .' (middleware は $next を transaction 内で実行する。適用条件を docblock で確認すること)';
        }
    }

    expect($unexpected)->toBe([]);
});
