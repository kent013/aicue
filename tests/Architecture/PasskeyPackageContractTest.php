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
use Laravel\Fortify\FortifyServiceProvider;
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

/*
 * ★本設計 (T166) で最も重要な検査★
 * FortifyServiceProvider::register() の configurePasskeys() が
 * `passkeys.*` を `fortify.passkeys.*` から**無条件に上書きする**。
 * この写しが切れると、アプリの宣言は**無言で無視され APP_URL / APP_KEY 由来の
 * 既定へ戻る** (= 設定したのに効かない事故。設計中に実際に踏みかけた経路)。
 * 実効値と宣言値の一致を固定する。
 */
test('Fortify が fortify.passkeys.* を passkeys.* へ写している (fallback と区別できる値で検査)', function (): void {
    // ★**素の一致比較では偽陰性になる**。通常環境では宣言値も fallback も同じ
    //   APP_URL / APP_KEY 由来なので、Fortify が fortify.passkeys.* を読まなくなっても
    //   両者は一致してしまう。fallback では絶対に生まれない sentinel を宣言してから写像を実行する。
    config([
        'fortify.passkeys.relying_party_id' => 'sentinel.example.com',
        'fortify.passkeys.allowed_origins' => ['https://sentinel.example.com'],
        'fortify.passkeys.user_handle_secret' => str_repeat('s', 32),
    ]);

    // configurePasskeys() は protected。**vendor の写像そのもの**が検査対象なので
    // Reflection で直接叩く (register() 全体を再実行すると Response contract の
    // アプリ実装への差し替えまで Fortify 既定へ戻ってしまうため、対象を最小に絞る)。
    // 名前が変わればこのテストが落ちる = 版を上げたときに写像を再確認する契機になる。
    $provider = new FortifyServiceProvider(app());
    $configure = new ReflectionMethod($provider, 'configurePasskeys');
    $configure->setAccessible(true);
    $configure->invoke($provider);

    expect(config('passkeys.relying_party_id'))->toBe('sentinel.example.com');
    expect(config('passkeys.allowed_origins'))->toBe(['https://sentinel.example.com']);
    expect(config('passkeys.user_handle_secret'))->toBe(str_repeat('s', 32));
});

test('config cache 往復後もアプリ側の passkeys 宣言が残る', function (): void {
    $subset = ['fortify' => config('fortify'), 'passkeys' => config('passkeys')];
    $exported = var_export($subset, true);
    /** @var array<string, mixed> $roundTripped */
    $roundTripped = eval('return '.$exported.';');

    expect(data_get($roundTripped, 'fortify.passkeys.relying_party_id'))->toBeString();
    expect(data_get($roundTripped, 'fortify.passkeys.allowed_origins'))->toBeArray();
    expect(data_get($roundTripped, 'fortify.passkeys.raw_allowed_origins'))->toBeArray();
    expect(data_get($roundTripped, 'fortify.passkeys.user_handle_secret'))->toBeString();
    expect(data_get($roundTripped, 'fortify.passkeys.user_handle_secret_declared'))->toBeBool();
    expect(data_get($roundTripped, 'passkeys.relying_party_id'))->toBeString();
});

/*
 * アプリは passkeys の一部キーしか宣言しない。残りは Fortify の configurePasskeys() が
 * アプリ設定から組み立てるか、laravel/passkeys の既定が供給する。
 * この結線が崩れると **management_middleware / throttle が消えて保護が外れる**ため、
 * アプリ宣言を足した後も**実効キーが揃っている**ことを明示的に固定する。
 * (management_middleware / throttle は vendor 既定値ではなく Fortify の組み立て結果である)
 */
test('アプリ宣言を足しても Fortify 結線後の実効キーが揃っている', function (): void {
    expect(config('passkeys.timeout'))->toBe(60000);
    expect(config('passkeys.guard'))->toBe('web');
    expect(config('passkeys.middleware'))->toBe(['web']);
    expect(config('passkeys.redirect'))->toBeString();
    // confirmPassword=false のため空配列になる (既存の「password.confirm 無効化」契約と対)。
    expect(config('passkeys.management_middleware'))->toBe([]);
    // limiters.passkeys から Fortify が組み立てる (既存の throttle 契約と対)。
    expect(config('passkeys.throttle'))->toBe('throttle:passkeys');
});

/*
 * 版 pin。laravel/passkeys は **0.x** であり semver の後方互換保証が無い
 * (0.3.0 で設定キー名・contract・route 名が予告なく変わりうる)。
 * 本ファイルの他の契約検査と config/fortify.php の passkeys ブロックのキー名は
 * **0.2 系に対して検証する契約**であり、その前提が黙って動かないように 2 つの側面を固定する:
 *
 *   - composer.json の直接要求 = 「直接 import しているので直接要求する」設計意思と許容範囲。
 *     これが無いと laravel/fortify の推移要求が緩んだ瞬間に 0.3 系が無言で入る。
 *   - composer.lock の解決値 = **いま実際に動いている版**。
 *     制約だけ見ても、lock が手で書き換えられた / platform 設定で別版が入った場合を捕まえられない。
 *
 * 0.2.x を外れるときは、本ファイルの契約検査 (route 名 7 本 / confirmPassword /
 * limiter / モデル差し替え / Response contract 4 本 / binder / 写像 sentinel) と
 * Fortify の configurePasskeys() が読むキー名 (fortify.passkeys.*) を
 * 再確認してから、この pin を更新すること。
 */

/** @return array<string, mixed> composer.json の require ブロック */
function composerRequireBlock(): array
{
    $raw = file_get_contents(base_path('composer.json'));
    expect($raw)->toBeString();
    /** @var string $raw */
    $decoded = json_decode($raw, true);
    expect($decoded)->toBeArray();
    /** @var array<string, mixed> $decoded */
    $require = $decoded['require'] ?? null;
    expect($require)->toBeArray();

    /** @var array<string, mixed> $require */
    return $require;
}

/** composer.lock の解決版 (例 "v0.2.1") を返す */
function lockedPackageVersion(string $name): ?string
{
    $raw = file_get_contents(base_path('composer.lock'));
    expect($raw)->toBeString();
    /** @var string $raw */
    $decoded = json_decode($raw, true);
    expect($decoded)->toBeArray();

    /** @var array<string, mixed> $decoded */
    $packages = $decoded['packages'] ?? [];
    expect($packages)->toBeArray();

    /** @var array<int, array<string, mixed>> $packages */
    foreach ($packages as $package) {
        if (($package['name'] ?? null) === $name && is_string($package['version'] ?? null)) {
            return $package['version'];
        }
    }

    return null;
}

test('composer.json が laravel/passkeys を直接要求する (直接 import しているため)', function (): void {
    $require = composerRequireBlock();

    // ★`toHaveKey($key, $value)` の第 2 引数は**期待値**であってメッセージではない。
    //   ここで見たいのは「キーが在ること」なので array_key_exists を明示的に検査する。
    expect(array_key_exists('laravel/passkeys', $require))->toBeTrue(
        'laravel/passkeys を直接 import しているのに直接要求が無い。'
        .'laravel/fortify の推移要求が緩むと 0.3 系が無言で入る'
    );

    $constraint = $require['laravel/passkeys'];
    expect($constraint)->toBeString();
    /** @var string $constraint */
    // 書き方は caret 1 種類に絞る (composer.json の他 20 件超がすべて caret のため)。
    // **前方一致では不十分**: `^0.20` / `^0.2 || ^1.0` / `^0.2.1 || ^0.3` / `^0.2@dev` が通り、
    // 特に `|| ^0.3` は「0.3 系を入れない」というこの検査の目的を破る。
    expect(preg_match('/^\^0\.2(?:\.\d+)?$/', $constraint))->toBe(
        1,
        "laravel/passkeys の制約は '^0.2' か '^0.2.<patch>' の形だけを許す: {$constraint}"
    );
});

test('composer.lock の laravel/passkeys が 0.2 系 (契約検査の検証済み範囲)', function (): void {
    $version = lockedPackageVersion('laravel/passkeys');

    expect($version)->toBeString('composer.lock に laravel/passkeys が無い');
    /** @var string $version */
    expect(str_starts_with(ltrim($version, 'v'), '0.2.'))->toBeTrue(
        "laravel/passkeys の解決版が 0.2 系を外れている: {$version}。"
        .'本ファイルの契約検査と fortify.passkeys.* のキー名を再確認してから pin を更新すること'
    );
});
