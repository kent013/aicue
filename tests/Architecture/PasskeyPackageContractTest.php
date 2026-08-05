<?php

declare(strict_types=1);

use App\Http\Responses\Passkey\PasskeyConfirmationResponse;
use App\Http\Responses\Passkey\PasskeyDeletedResponse;
use App\Http\Responses\Passkey\PasskeyLoginResponse;
use App\Http\Responses\Passkey\PasskeyRegistrationResponse;
use App\Models\Passkey;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Contracts\PasskeyConfirmationResponse as PasskeyConfirmationResponseContract;
use Laravel\Passkeys\Contracts\PasskeyDeletedResponse as PasskeyDeletedResponseContract;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
use Laravel\Passkeys\Contracts\PasskeyRegistrationResponse as PasskeyRegistrationResponseContract;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Http\Controllers\PasskeyConfirmationController;
use Laravel\Passkeys\Http\Controllers\PasskeyLoginController;
use Laravel\Passkeys\Http\Controllers\PasskeyRegistrationController;
use Laravel\Passkeys\Passkeys;

/*
 * laravel/passkeys (Fortify 1.37 の推移依存) とアプリの結線契約を固定する。
 *
 * 守る事故:
 *   - パッケージ側 routes の二重登録 (Fortify が feature flag でゲートした route と衝突する)
 *   - Fortify 標準の password.confirm が復活し SSO-only ユーザーが詰む
 *   - config:cache 下で fortify-options.passkeys が落ちる
 *   - binder が vendor 実装のまま残り、他人の passkey の存在が 403 で漏れる
 *
 * DB を伴う実挙動 (他人の passkey が 404 になること) は
 * tests/Feature/Auth/PasskeyRouteAccessTest.php が担保する
 * (Architecture レーンは RefreshDatabase を持たないため DB に触れない)。
 */

/** @return list<string> Fortify が登録する passkey route の名前 */
function passkeyRouteNames(): array
{
    return [
        'passkey.login-options',
        'passkey.login',
        'passkey.confirm-options',
        'passkey.confirm',
        'passkey.registration-options',
        'passkey.store',
        'passkey.destroy',
    ];
}

test('パッケージ側の passkey routes は登録されない (Fortify 側が唯一の登録点)', function (): void {
    expect(Passkeys::shouldRegisterRoutes())->toBeFalse();
});

test('passkeys feature が有効 (キルスイッチが on)', function (): void {
    expect(Features::enabled(Features::passkeys()))->toBeTrue();
});

test('passkey route 7 本が実在し vendor controller に紐づく', function (): void {
    $routes = app('router')->getRoutes();
    $routes->refreshNameLookups();

    $expectedControllers = [
        PasskeyLoginController::class,
        PasskeyConfirmationController::class,
        PasskeyRegistrationController::class,
    ];

    foreach (passkeyRouteNames() as $name) {
        $route = $routes->getByName($name);
        expect($route)->not->toBeNull("route '{$name}' が存在しない");

        $controller = $route->getAction('controller');
        expect($controller)->toBeString();

        $matched = false;
        foreach ($expectedControllers as $expected) {
            if (str_starts_with((string) $controller, $expected.'@')) {
                $matched = true;
                break;
            }
        }
        expect($matched)->toBeTrue("route '{$name}' の action が vendor controller ではない: {$controller}");
    }
});

test('passkeys の confirmPassword は false (generic recent-auth へ統一)', function (): void {
    expect(config('fortify-options.passkeys.confirmPassword'))->toBeFalse();
});

test('passkeys の throttle limiter が設定されている (未認証 challenge 無制限の防止)', function (): void {
    expect(config('fortify.limiters.passkeys'))->toBe('passkeys');
});

/*
 * config:cache 下でも値が残ることを検査する。
 * ConfigCacheCommand は `'<?php return '.var_export($config, true).';'` を書き出すため、
 * その **serialize 機構そのものを再現**して往復させる
 * (Pest から config:cache を実行すると bootstrap/cache/config.php を書き換え、
 *  --parallel 実行を壊すため実行しない)。
 */
test('config cache 往復後も fortify-options.passkeys と features が残る', function (): void {
    $subset = [
        'fortify' => config('fortify'),
        'fortify-options' => config('fortify-options'),
    ];

    $exported = var_export($subset, true);
    /** @var array<string, mixed> $roundTripped */
    $roundTripped = eval('return '.$exported.';');

    expect(data_get($roundTripped, 'fortify-options.passkeys.confirmPassword'))->toBeFalse();
    expect(data_get($roundTripped, 'fortify.features'))->toContain('passkeys');
    expect(data_get($roundTripped, 'fortify.limiters.passkeys'))->toBe('passkeys');
});

test('モデル差し替えが app 実装になっている', function (): void {
    expect(Passkeys::passkeyModel())->toBe(Passkey::class);
    expect(Passkeys::userModel())->toBe(User::class);
    expect(is_a(User::class, PasskeyUser::class, true))->toBeTrue();
});

test('Response contract 4 本が app 実装に差し替えられている (response()->json 直書きの回避)', function (): void {
    expect(app(PasskeyLoginResponseContract::class))->toBeInstanceOf(PasskeyLoginResponse::class);
    expect(app(PasskeyConfirmationResponseContract::class))->toBeInstanceOf(PasskeyConfirmationResponse::class);
    expect(app(PasskeyRegistrationResponseContract::class))->toBeInstanceOf(PasskeyRegistrationResponse::class);
    expect(app(PasskeyDeletedResponseContract::class))->toBeInstanceOf(PasskeyDeletedResponse::class);
});

/*
 * binder の **最終解決系**がアプリ実装であることを固定する。
 *
 * vendor の binder は `app($model)->resolveRouteBinding($value)` でグローバル解決するため、
 * guest 文脈でも解決に成功しうる (= その後 controller の 403 に到達して存在が漏れる)。
 * アプリ実装 (SelfScopedPasskeyBinder) は guest を DB へ行かずに 404 相当へ倒すので、
 * **DB に触れずに** 差し替えの成否を判定できる。
 */
test('passkey binder の最終解決系がアプリ実装 (guest は DB を引かずに 404 相当)', function (): void {
    $callback = app('router')->getBindingCallback('passkey');

    expect($callback)->not->toBeNull('{passkey} の explicit binder が登録されていない');

    // class binding は Router::createClassBinding により ($value, $route) の 2 引数 closure になる
    expect(fn () => $callback('1', null))->toThrow(ModelNotFoundException::class);
});
