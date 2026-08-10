<?php

declare(strict_types=1);

use App\Http\Middleware\RequireTwoFactorForEnforcedOrganizations;
use Illuminate\Routing\Router;

/**
 * 組織 2FA 強制ゲートの allowlist (ALLOWED_ROUTE_NAMES) の鮮度 invariant。
 *
 * allowlist は「準拠達成に必要な最小経路」の正であり、route リネーム・削除で名前が
 * 浮く (= 実は通していない) と未準拠ユーザーが 2FA を設定できなくなる。逆に理由の
 * 無いエントリは過剰許可の温床になる。実際の到達可能性 (ゲート中に 302 されない) は
 * tests/Feature/Organizations/TwoFactorEnforcementTest.php の dataset が担保する。
 */

/** ALLOWED_ROUTE_NAMES の件数 (exact-fit。増減させたらこの数値も同じ diff で書き換える)。 */
const TWO_FACTOR_ALLOWLIST_COUNT = 21;
test('allowlist の route name は全て実在する named route である', function (): void {
    /** @var Router $router */
    $router = app('router');
    $routes = $router->getRoutes();
    $routes->refreshNameLookups();

    foreach (array_keys(RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES) as $name) {
        expect($routes->getByName($name))
            ->not->toBeNull("allowlist の route '{$name}' が存在しない (リネーム/削除に追従していない)");
    }
});

test('allowlist の各エントリは必要理由 (非空) を持つ', function (): void {
    foreach (RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES as $name => $reason) {
        expect(trim($reason))->not->toBe('', "route '{$name}' の必要理由が空 (運用劣化)");
    }
});

test('allowlist の件数を exact-fit で pin する (根拠なく通す余裕枠を作らない)', function (): void {
    expect(RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES)
        ->toHaveCount(TWO_FACTOR_ALLOWLIST_COUNT,
            'ALLOWED_ROUTE_NAMES の件数が変わりました。増減は「未準拠ユーザーに何を許すか」の'
            .'契約変更なので、この数値を同じ diff で書き換えてレビューに出してください。');
});

test('救済 route は allowlist にあり、予約・即時削除は無い (名指し pin)', function (): void {
    $names = array_keys(RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES);

    // 救済 (誤操作した退会予約の取消) は通す。凍結側 allowlist と判断を揃える (F-4-01)
    expect($names)->toContain('settings.account.deletion-request.destroy');

    // ★以下は「救済ではない」ため通さない。個別に expect して失敗時に対象が特定できるようにする
    expect($names)->not->toContain('settings.account.deletion-request.store'); // 予約は意思表示であり救済ではない
    expect($names)->not->toContain('settings.account.destroy');                // 即時削除は 30 日猶予の迂回口
    expect($names)->not->toContain('two-factor.disable');                      // 既存判断の維持 (pending 巻き戻しの濫用面)
});
