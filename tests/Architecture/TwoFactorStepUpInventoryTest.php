<?php

declare(strict_types=1);

use App\Enums\Security\TwoFactorStepUpExemption;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Tests\Support\Security\RecentAuthMiddleware;

/*
 * 2FA 面の step-up (recent-auth) 付与漏れ invariant (deny-by-default)。
 *
 * 「route 名に `two-factor` を含む route は recent-auth を持つか、
 *   TwoFactorStepUpExemption + 30 文字以上の根拠で免除登録されている」を機械強制する。
 *
 * ★保証範囲 (誇張しない): セレクタは**名前ベース**である。`mfa.*` / `security.*` のような
 *   別名で第二要素の状態に触る route を将来足した場合、本 gate は**沈黙する**。
 *   別名で第二要素へ触る route を足すときは、この inventory の母集団設計も同時に見直すこと。
 *   (命名規約そのものを強制する仕組みは意図的に作っていない = 過大)
 *
 * ★RecentAuthRouteTest (allowlist 型) との役割分担:
 *   あちらは「機微操作の名指し表に付いているか」、こちらは「2FA 名前空間に未分類が無いか」。
 *   判定述語は Tests\Support\Security\RecentAuthMiddleware に単一化してドリフトを防ぐ。
 */

/**
 * 母集団セレクタ: route 名に `two-factor` を含む全 route。
 *
 * @return array<string, RoutingRoute>
 */
function twoFactorStepUpPopulation(): array
{
    /** @var Router $router */
    $router = Route::getFacadeRoot();
    $routes = $router->getRoutes();
    $routes->refreshNameLookups();

    /** @var array<string, RoutingRoute> $matched */
    $matched = [];
    foreach ($routes as $route) {
        $name = $route->getName();
        if (is_string($name) && str_contains($name, 'two-factor')) {
            $matched[$name] = $route;   // route 名は一意
        }
    }
    ksort($matched);

    return $matched;
}

/**
 * 母集団件数の **exact fit**。
 * ★下限だけでは「セレクタが壊れて 0 件」は検出できても「Fortify が 1 本足した」を見逃す。
 *   exact なら増減のどちらも必ず差分として現れ、分類の再検討を強制できる。
 *   実測値 (php artisan route:list --json): Fortify 9 本 + アプリ 2 本 = 11 本。
 */
function twoFactorStepUpPopulationSize(): int
{
    return 11;
}

/** exemption 理由の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
function twoFactorStepUpReasonMinLength(): int
{
    return 30;
}

/** exemption 件数の上限。**現在値ちょうど** (exact fit)。上げる前に必ず再検討すること。 */
function twoFactorStepUpExemptionCap(): int
{
    return 3;
}

/**
 * case 別上限 (分類の偏り検出)。全体 cap とは役割が違うので array_sum で導出しない。
 *
 * @return array<string, int>
 */
function twoFactorStepUpExemptionCapByCase(): array
{
    return [
        TwoFactorStepUpExemption::PreAuthChallengeSurface->value => 2,
        // ★ここが膨らむ = 「秘密を開示する route を所持証明つきとして逃がした」疑い。
        TwoFactorStepUpExemption::ProofOfSecretPossessionRequired->value => 1,
    ];
}

/**
 * **免除にできない route** の名指し固定。ここに載る route が exemption 側へ移されたり
 * recent-auth を失ったら fail する (この gate の存在理由そのものを守る = 空振り防止の核)。
 *
 * 2 系統をまとめて持つ:
 *  (a) 秘密の**開示** — 読めば第二要素を複製できる
 *  (b) 第二要素の**除去・差し替え** — 書けば正規ユーザーを締め出せる
 *
 * ★組織管理側の 2 本 (organizations.members.two-factor.reset /
 *   organizations.two-factor-requirement.update) は**入れない**。
 *   脅威系統が違い (管理者操作であり Gate 認可が別途かかる)、
 *   RecentAuthRouteTest の allowlist が既に名指しで固定しているため二重管理になる。
 *
 * @return list<string>
 */
function twoFactorNonExemptibleRoutes(): array
{
    return [
        // (a) 秘密の開示
        'two-factor.qr-code',        // otpauth:// URL (秘密を内包) と QR SVG
        'two-factor.secret-key',     // 平文 TOTP seed
        'two-factor.recovery-codes', // TOTP を伴わないログイン成立手段
        // (b) 第二要素の除去・差し替え
        'two-factor.enable',         // force=true が seed とリカバリコードを差し替える
        'two-factor.disable',        // 第二要素そのものの除去
        'two-factor.regenerate-recovery-codes', // bypass 手段の差し替え
    ];
}

/**
 * recent-auth を持たないことが正しいと裁定した route の inventory。
 *
 * @return array<string, array{TwoFactorStepUpExemption, string}>
 */
function twoFactorStepUpExemptions(): array
{
    $preAuth = TwoFactorStepUpExemption::PreAuthChallengeSurface;
    $possession = TwoFactorStepUpExemption::ProofOfSecretPossessionRequired;

    return [
        'two-factor.login' => [$preAuth,
            'guest:web guard 配下の未認証チャレンジ画面。session に認証主体が存在しないため '
            .'step-up の鮮度判定 (recent_auth_at) が定義不能であり、ここに recent-auth を課すと '
            .'ログインそのものが成立しなくなる。流量制限は fortify.limiters.two-factor が担当する。'],

        'two-factor.login.store' => [$preAuth,
            'two-factor.login と同一 URI の検証側。これ自体が第二要素の検証 = satisfier であり、'
            .'自分自身に step-up を要求すると構造的に詰む。guest:web + throttle:two-factor で '
            .'総当りは有界化されている。'],

        'two-factor.confirm' => [$possession,
            'enrollment の確認。成立には認証アプリが生成した TOTP コードの提示が必要で、'
            .'session 保持だけでは成立しない (秘密の所持証明が前提)。応答は秘密を開示せず、'
            .'既存の第二要素を除去も差し替えもしない (two_factor_confirmed_at を立てるだけ)。'
            .'秘密の入手経路である qr-code / secret-key / enable 側に step-up を課してある。'],
    ];
}

test('母集団が exact fit である (セレクタの空振り / vendor の route 追加を検出)', function (): void {
    $population = twoFactorStepUpPopulation();
    $expected = twoFactorStepUpPopulationSize();

    expect(count($population))->toBe($expected,
        '2FA route の母集団が '.count($population)."件 (期待 {$expected} 件) です。"
        .'セレクタの空振り、または Fortify / アプリ側の route 増減が起きています。'
        .'増えた route を分類してからこの数値を更新してください。'
        .PHP_EOL.implode(PHP_EOL, array_keys($population)));
});

test('母集団の各 route は recent-auth 系 middleware をちょうど 1 種類持つか exemption inventory に明示分類されている (未知は fail)', function (): void {
    $inventory = twoFactorStepUpExemptions();
    $violations = [];

    foreach (twoFactorStepUpPopulation() as $name => $route) {
        $count = RecentAuthMiddleware::countAttachedKinds($route);

        if ($count === 1) {
            continue;
        }
        if ($count === 0 && array_key_exists($name, $inventory)) {
            continue;
        }

        $violations[] = $count === 0
            ? "{$name}: recent-auth が無く exemption inventory にも未登録"
            : "{$name}: 別種の recent-auth middleware が {$count} 本同居している"
                .' (無条件 step-up と条件付き step-up の混在。契約は 1 種類ちょうど)';
    }

    expect($violations)->toBe([],
        '2FA 面の step-up 付与が不正です。recent-auth を貼るか、貼らないことが正しい理由を '
        .'twoFactorStepUpExemptions() に TwoFactorStepUpExemption + 具体的根拠付きで'
        .'登録してください。'.PHP_EOL.implode(PHP_EOL, $violations));
});

test('exemption inventory の key は現存する母集団 route (stale 検出)', function (): void {
    $population = twoFactorStepUpPopulation();

    $stale = [];
    foreach (array_keys(twoFactorStepUpExemptions()) as $name) {
        if (! array_key_exists($name, $population)) {
            $stale[] = $name;
        }
    }

    expect($stale)->toBe([],
        'exemption inventory に現存しない route 名があります (削除/rename 済み): '.implode(', ', $stale));
});

test('exemption 登録された route は recent-auth を 1 本も持たない (死んだ exemption の検出)', function (): void {
    $population = twoFactorStepUpPopulation();
    $dead = [];

    foreach (array_keys(twoFactorStepUpExemptions()) as $name) {
        $route = $population[$name] ?? null;
        if ($route instanceof RoutingRoute && RecentAuthMiddleware::isAttached($route)) {
            $dead[] = $name;
        }
    }

    expect($dead)->toBe([],
        'recent-auth が付いているのに exemption が残っています (免除が形骸化しています)。'
        .'inventory から削除してください: '.implode(', ', $dead));
});

test('exemption inventory の値は enum + 実質的な理由文字列', function (): void {
    $minLength = twoFactorStepUpReasonMinLength();
    $violations = [];

    foreach (twoFactorStepUpExemptions() as $name => [$exemption, $reason]) {
        if (! $exemption instanceof TwoFactorStepUpExemption) {
            $violations[] = "{$name}: 第 1 要素が TwoFactorStepUpExemption ではありません";
        }
        if (mb_strlen($reason) < $minLength) {
            $violations[] = "{$name}: 理由が {$minLength} 文字未満です";
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

test('exemption 件数が上限ちょうどを超えない (形骸化ガード)', function (): void {
    $count = count(twoFactorStepUpExemptions());

    expect($count)->toBeLessThanOrEqual(twoFactorStepUpExemptionCap(),
        "exemption が {$count} 件あります。免除を増やす前に、その route に step-up を"
        .'課せない構造的理由が本当にあるかを再検討してください。');
});

test('exemption の case 別件数が上限を超えない (分類の偏り検出)', function (): void {
    $caps = twoFactorStepUpExemptionCapByCase();
    $counts = [];

    foreach (twoFactorStepUpExemptions() as [$exemption]) {
        $counts[$exemption->value] = ($counts[$exemption->value] ?? 0) + 1;
    }

    $violations = [];

    // 全 case が cap 表に載っていること (case 追加時の登録漏れ検出)。
    // ★`expect()->toHaveKey($key, $value)` の第 2 引数は**期待値**であってメッセージではない
    //   ため使わない (使うと cap 値と文言を比較して常に fail する)。
    foreach (TwoFactorStepUpExemption::cases() as $case) {
        if (! array_key_exists($case->value, $caps)) {
            $violations[] = "case {$case->value} が cap 表に未登録です";
        }
    }

    foreach ($counts as $value => $count) {
        $cap = $caps[$value] ?? 0;
        if ($count > $cap) {
            $violations[] = "{$value}: {$count} 件 (上限 {$cap})";
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

test('免除にできない route は必ず recent-auth 系 middleware をちょうど 1 種類持つ (免除側へ移されたら fail)', function (): void {
    $population = twoFactorStepUpPopulation();
    $inventory = twoFactorStepUpExemptions();
    $violations = [];

    foreach (twoFactorNonExemptibleRoutes() as $name) {
        $route = $population[$name] ?? null;
        if (! $route instanceof RoutingRoute) {
            $violations[] = "{$name}: 母集団に存在しません (rename / 削除?)";

            continue;
        }
        if (RecentAuthMiddleware::countAttachedKinds($route) !== 1) {
            $violations[] = "{$name}: 秘密の開示 / 第二要素の差し替え経路なのに recent-auth 系 middleware が 1 種類ではありません";
        }
        if (array_key_exists($name, $inventory)) {
            $violations[] = "{$name}: この route は exemption にできません";
        }
    }

    expect($violations)->toBe([],
        '秘密開示 / 第二要素差し替え route の step-up は免除できません (T124 の存在理由そのものです)。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});
