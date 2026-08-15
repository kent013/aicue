<?php

declare(strict_types=1);

use App\Enums\Security\RescueRouteGateDisposition;
use App\Enums\Security\RescueRouteGateKind;
use Illuminate\Routing\Router;

/*
 * Architecture invariant: **救済 route (退会予約の取消) の経路上にあるゲートは、
 * その救済 route に対する扱いを目録に宣言してあること** (deny-by-default)。
 *
 * SoT = devnotes/20260811-0146-blocked-action-context/detailed-design.md の施策 3。
 * 背景 = bug-hunt run 20260811-003230 の F-4-01: 凍結 middleware は取消を通していたのに、
 * priority list で先に走る 2FA 強制ゲートが弾いていた (**ゲート同士で判断が食い違っていた**)。
 *
 * 記号: `U` = ( 救済 route の resolve 済み middleware ∩ App\Http\Middleware\ )
 *              ∪ { Authenticate, AuthenticateSession, EnsureEmailIsVerified }
 *       `D` = RescueRouteGateDisposition の値集合。**`U` と `D` は exact-fit**。
 *
 * ★この gate が保証するもの:
 *   - 検査 1: `U` と `D` の exact-fit (自前ゲートの新設・撤去に必ず分類判断を強制する)
 *   - 検査 2: `PassesRescueRoute` の宣言が**実装の allowlist と一致**する (機械照合)
 *   - 検査 3: 2FA ゲートと凍結 middleware は `PassesRescueRoute` から**動かせない** (名指し)
 *   - 検査 4: 全 case の `rationale()` が 30 文字以上
 *   - 検査 5: 名指しした vendor 認証ゲート 3 本が**実際に route に付き、母集団に入る**
 *   - 空振り検知: 母集団件数 exact / route 不在で明示 fail / 照合器の負のコントロール
 *
 * ★この gate が保証しないもの (誇張しない):
 *   - **「route 上の全 middleware を通過できる」ことは保証しない**。守るのは**分類の網羅**で
 *     あって通過の全称証明ではない (だから名前は …PassageTest ではなく …InventoryTest)。
 *     CSRF 419 / session 開始 / binding 404 / Inertia 履歴暗号化は母集団に入れていない。
 *   - **vendor は名指し 3 本のみ**。vendor 側に新しい短絡 middleware が増えても沈黙する。
 *   - **人手申告の分類 2 種** (ShortCircuitsButEscapable / NeverShortCircuitsRescueRoute) は
 *     middleware 本体の改変 (新しい短絡分岐の追加) に沈黙する。機械照合できるのは
 *     `PassesRescueRoute` の allowlist 一致だけである。
 *   - **母集団は救済 route 1 本だけ**。他の救済的 route のゲート通過性は見ない。
 *
 * DB 不使用 (Architecture lane は TestCase のみ)。
 */

/** 母集団 `U` の件数 (exact。middleware の増減を必ずレビューに出す)。 */
const RESCUE_GATE_POPULATION_COUNT = 11;

/**
 * 母集団に名指しで加える vendor 認証ゲート。
 *
 * この 3 本だけがこの route を実際に短絡させうる vendor middleware である。
 * cookie / session 開始 / CSRF / binding / Inertia 履歴暗号化は入れない
 * (Laravel 側のクラス名変更・構成変更で偽赤にしないため)。
 *
 * @var list<string>
 */
const RESCUE_GATE_NAMED_VENDOR = [
    'Illuminate\Auth\Middleware\Authenticate',
    'Illuminate\Session\Middleware\AuthenticateSession',
    'Illuminate\Auth\Middleware\EnsureEmailIsVerified',
];

/**
 * 救済 route の resolve 済み middleware (priority 適用後) を返す。
 *
 * route が存在しなければ**明示的に fail** させる (route リネームで gate が黙って緑になるのを防ぐ)。
 *
 * @return list<string>
 */
function rescueRouteResolvedMiddleware(): array
{
    /** @var Router $router */
    $router = app('router');
    $routes = $router->getRoutes();
    $routes->refreshNameLookups();

    $route = $routes->getByName(RescueRouteGateDisposition::RESCUE_ROUTE_NAME);
    expect($route)->not->toBeNull(
        '救済 route "'.RescueRouteGateDisposition::RESCUE_ROUTE_NAME.'" が存在しません。'
        .'リネーム / 削除で目録の母集団が空になると、この gate は何も守らなくなります。');

    $resolved = [];
    foreach ($router->gatherRouteMiddleware($route) as $middleware) {
        if (! is_string($middleware)) {
            continue;
        }
        // 'throttle:60,1' のようなパラメータ付きは ':' の前で正規化する
        $resolved[] = str_contains($middleware, ':') ? strstr($middleware, ':', true) : $middleware;
    }

    return array_values(array_unique(array_filter($resolved, static fn (string $m): bool => $m !== '')));
}

/**
 * 母集団 `U` = 自前 middleware 全件 + 名指し vendor 3 本。
 *
 * @return list<string>
 */
function rescueRouteGatePopulation(): array
{
    $own = array_values(array_filter(
        rescueRouteResolvedMiddleware(),
        static fn (string $m): bool => str_starts_with($m, 'App\Http\Middleware\\'),
    ));

    return array_values(array_unique([...$own, ...RESCUE_GATE_NAMED_VENDOR]));
}

test('検査 1: 救済 route のゲート母集団と目録が exact-fit である', function (): void {
    $population = rescueRouteGatePopulation();
    $declared = RescueRouteGateDisposition::values();

    $undeclared = array_values(array_diff($population, $declared));
    $stale = array_values(array_diff($declared, $population));

    expect($undeclared)->toBe([],
        '救済 route の経路上に、目録へ分類が宣言されていない middleware があります。'
        .'新しいゲートを web group に足したら「この救済 route をどう扱うか」を必ず宣言してください '
        .'(F-4-01 はこの宣言が無かったために起きた): '.implode(', ', $undeclared));
    expect($stale)->toBe([],
        '目録に、救済 route の経路上に存在しない middleware が残っています (死に登録): '
        .implode(', ', $stale));
});

test('検査 2: PassesRescueRoute の宣言は実装の allowlist と一致する', function (): void {
    $notPermitting = [];
    foreach (RescueRouteGateDisposition::cases() as $case) {
        if ($case->disposition() !== RescueRouteGateKind::PassesRescueRoute) {
            continue;
        }
        if (! $case->permitsRouteName(RescueRouteGateDisposition::RESCUE_ROUTE_NAME)) {
            $notPermitting[] = $case->value;
        }
    }

    expect($notPermitting)->toBe([],
        'PassesRescueRoute と宣言しているのに、実装の allowlist が救済 route を通していません。'
        .'allowlist から救済 route を消すと「取り消したつもりで取り消せていない」詰みが再発します: '
        .implode(', ', $notPermitting));
});

test('検査 3: 2FA ゲートと凍結 middleware は PassesRescueRoute から動かせない', function (): void {
    // ★「通さない」に格下げして緑にする逃げ道を塞ぐ (名指し)。
    $nonExemptible = [
        RescueRouteGateDisposition::RequireTwoFactor,
        RescueRouteGateDisposition::NotPendingDeletion,
    ];

    foreach ($nonExemptible as $case) {
        expect($case->disposition())->toBe(RescueRouteGateKind::PassesRescueRoute,
            "{$case->value} は救済 route を必ず allowlist で通す側でなければなりません "
            .'(分類を格下げすると F-4-01 が再発します)。');
    }
});

test('検査 4: 各 case の rationale が 30 文字以上ある', function (): void {
    $short = [];
    foreach (RescueRouteGateDisposition::cases() as $case) {
        $length = mb_strlen($case->rationale());
        if ($length < 30) {
            $short[] = "{$case->value}: {$length} 文字";
        }
    }

    expect($short)->toBe([], '分類の根拠は 30 文字以上で書くこと: '.implode(' / ', $short));
});

test('検査 5: 名指しした vendor 認証ゲート 3 本が実際に route に付き、母集団に入る', function (): void {
    $resolved = rescueRouteResolvedMiddleware();
    $population = rescueRouteGatePopulation();

    $detached = array_values(array_diff(RESCUE_GATE_NAMED_VENDOR, $resolved));
    expect($detached)->toBe([],
        '名指しした vendor 認証ゲートが救済 route から外れました。分類 '
        .'(ShortCircuitsButEscapable) の前提が変わるので、目録側も同じ diff で見直してください: '
        .implode(', ', $detached));

    $missing = array_values(array_diff(RESCUE_GATE_NAMED_VENDOR, $population));
    expect($missing)->toBe([],
        '名指し vendor が母集団の導出から抜け落ちています (母集団を絞ると gate が空振りします): '
        .implode(', ', $missing));
});

test('空振り検知: 母集団件数の exact pin と、照合器の負のコントロール', function (): void {
    expect(rescueRouteGatePopulation())->toHaveCount(RESCUE_GATE_POPULATION_COUNT,
        '救済 route のゲート母集団の件数が変わりました。middleware の増減は救済の到達性に'
        .'直結するため、この数値を同じ diff で書き換えてレビューに出してください。');

    // ★照合器が「常に true」を返す実装に潰されていないことの実測。
    //   即時削除 (settings.account.destroy) は救済ではないのでどちらのゲートも通さない。
    foreach ([RescueRouteGateDisposition::RequireTwoFactor, RescueRouteGateDisposition::NotPendingDeletion] as $case) {
        expect($case->permitsRouteName('settings.account.destroy'))->toBeFalse(
            "{$case->value} の照合器が救済でない route まで true を返しています "
            .'(allowlist を実際に引いていない疑い)。');
    }

    // ★PassesRescueRoute 以外で permitsRouteName を使うと LogicException になる
    //   (宣言だけで緑にならないための構造)。
    expect(fn (): bool => RescueRouteGateDisposition::SecurityHeaders
        ->permitsRouteName(RescueRouteGateDisposition::RESCUE_ROUTE_NAME))
        ->toThrow(LogicException::class);
});
