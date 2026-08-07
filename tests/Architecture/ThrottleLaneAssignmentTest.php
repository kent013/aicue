<?php

declare(strict_types=1);

use App\Support\Http\RouteThrottleBinder;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * T125 で新設したレーンへの **route 割当** invariant (deny-by-default)。
 *
 * inline から named へ移しただけでは「次に誰かが `throttle:password-verify` を
 * 別 route へ貼る」ことを止められない。描画のたびに飛ぶ GET を照合レーンへ足せば、
 * 分けたばかりのレーンがまた潰れる。**どの route がどのレーンに属するか**を目録で固定する。
 *
 * ★責務境界: 「throttle が 1 本あるか」は ThrottleCoverageInventoryTest、
 *   「キーの形式と衝突」は RateLimiterKeyConventionTest、
 *   「inline の残置」は InlineThrottleInventoryTest。本テストは**割当だけ**を見る。
 */

/**
 * 本設計が所有するレーンと、そこに属してよい route の目録。
 *
 * @return array<string, list<string>> limiter 名 => route 名 (ソート済みで比較する)
 */
function throttleLaneAssignments(): array
{
    return [
        // パスワード**照合**の試行予算 (1 つの秘密を 3 面で合算 6/min)
        'password-verify' => [
            'password.confirm.store',
            'recent-auth.password',
            'user-password.update',
        ],
        // パスワードの初回設定 (照合を伴わない credential mutation)
        'password-set' => [
            'settings.password.store',
        ],
        // メール検証フロー (Fortify の 1 knob が 2 route に貼る)
        'email-verification' => [
            'verification.send',
            'verification.verify',
        ],
        // 2FA 設定フローの操作予算
        'two-factor-manage' => [
            'two-factor.confirm',
            'two-factor.disable',
            'two-factor.enable',
            'two-factor.regenerate-recovery-codes',
        ],
        // 招待受諾の確定 (未認証 GET の invitation-accept とは別レーン)
        'invitation-accept-submit' => [
            'invitations.accept.store',
        ],
        // パーソナルプランの有効化
        'plan-activate' => [
            'onboarding.activate-personal',
        ],
    ];
}

/** route の目録キー。 */
function throttleLaneRouteLabel(RoutingRoute $route): string
{
    $name = $route->getName();
    if ($name !== null && $name !== '') {
        return $name;
    }

    return implode('|', array_values(array_diff($route->methods(), ['HEAD']))).' /'.$route->uri();
}

/**
 * 実際の route 群から「本設計が所有するレーン」への割当を収集する。
 *
 * @return array<string, list<string>>
 */
function throttleLaneActualAssignments(): array
{
    /** @var Router $router */
    $router = Route::getFacadeRoot();
    $owned = array_keys(throttleLaneAssignments());
    $actual = [];

    foreach (Route::getRoutes() as $route) {
        foreach (RouteThrottleBinder::throttleEntries($router, $route) as $entry) {
            $params = Str::contains($entry, ':') ? Str::after($entry, ':') : '';
            if (! in_array($params, $owned, true)) {
                continue;
            }
            $actual[$params][] = throttleLaneRouteLabel($route);
        }
    }

    foreach ($actual as $lane => $labels) {
        $unique = array_values(array_unique($labels));
        sort($unique);
        $actual[$lane] = $unique;
    }

    return $actual;
}

test('新レーンの route 割当が目録と完全一致する (未宣言の相乗りも stale も fail)', function (): void {
    $expected = throttleLaneAssignments();
    foreach ($expected as $lane => $labels) {
        sort($labels);
        $expected[$lane] = $labels;
    }
    ksort($expected);

    $actual = throttleLaneActualAssignments();
    ksort($actual);

    expect($actual)->toBe($expected,
        'レーンへの route 割当が宣言と食い違っています。'
        .'レーンは「何を数えるか」の単位です。新しい route を既存レーンへ相乗りさせる前に、'
        .'そのレーンの予算をその route と分け合ってよいかを必ず再検討してください'
        .'(描画のたびに飛ぶ GET を照合レーンへ足すと再認証が壊れます)。');
});

test('目録のレーンはすべて 1 本以上の route を持つ (空振り検出)', function (): void {
    $actual = throttleLaneActualAssignments();
    $empty = [];

    foreach (array_keys(throttleLaneAssignments()) as $lane) {
        if (($actual[$lane] ?? []) === []) {
            $empty[] = $lane;
        }
    }

    expect($empty)->toBe([],
        'route が 1 本も割り当てられていないレーンがあります'
        .'(limiter だけ残った / 割当が外れた / 走査が壊れた): '.implode(', ', $empty));
});

test('route に貼られた named limiter はすべて実在する (typo 検出。母集団は全 route)', function (): void {
    // ★対象は本設計のレーンだけではなく **route に貼られた全 named throttle**。
    //   目録側の lane だけを見ると、route に `throttle:password-sett` と書かれた
    //   「目録に存在しない未知の名前」を列挙できない
    //   (割当の完全一致テストは「route が消えた」としか言わず、原因が typo だと分からない)。
    //   未登録 limiter はリクエスト時に MissingRateLimiterException になるため、
    //   ここで build 時に落とす。
    /** @var Router $router */
    $router = Route::getFacadeRoot();
    $limiters = app(CacheRateLimiter::class);
    $missing = [];

    foreach (Route::getRoutes() as $route) {
        foreach (RouteThrottleBinder::throttleEntries($router, $route) as $entry) {
            $params = Str::contains($entry, ':') ? Str::after($entry, ':') : '';
            // inline (`{max},{decay}` / パラメータなし) は named ではないので対象外
            if ($params === '' || preg_match('/^\d+,\d+$/', $params) === 1) {
                continue;
            }
            if ($limiters->limiter($params) === null) {
                $missing[] = throttleLaneRouteLabel($route).' → '.$params;
            }
        }
    }

    expect($missing)->toBe([],
        '登録されていない named limiter が route に貼られています'
        .'(リクエスト時に MissingRateLimiterException になります)。'
        .PHP_EOL.implode(PHP_EOL, array_unique($missing)));
});

test('named limiter を貼った route が 1 本以上ある (走査の空振り検出)', function (): void {
    // ★上のテストは「未登録が無いこと」を見るため、母集団が 0 件でも green になる。
    //   走査自体が生きていることを別に固定する (実測 33 本)。
    /** @var Router $router */
    $router = Route::getFacadeRoot();
    $named = 0;

    foreach (Route::getRoutes() as $route) {
        foreach (RouteThrottleBinder::throttleEntries($router, $route) as $entry) {
            $params = Str::contains($entry, ':') ? Str::after($entry, ':') : '';
            if ($params !== '' && preg_match('/^\d+,\d+$/', $params) !== 1) {
                $named++;
            }
        }
    }

    expect($named)->toBeGreaterThanOrEqual(25,
        "named throttle を貼った route が {$named} 件しか検出されませんでした (走査が壊れています)。");
});
