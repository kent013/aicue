<?php

declare(strict_types=1);

use App\DataTransferObjects\Auth\LoginMethodRemoval;
use App\Models\Passkey;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Auth\LoginMethodInventory;
use App\Services\Auth\PasskeyLoginPolicy;
use Illuminate\Http\Request;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Passkeys;

/*
 * LoginMethodInventory (投影後のログイン手段集合) と PasskeyLoginPolicy の契約。
 *
 * 基準は「データが存在する」ではなく「**使える**」。feature を落とした後も使えない手段を
 * 数えると EnsureLoginMethodRemains が形骸化する。
 */

function inventory(): LoginMethodInventory
{
    return app(LoginMethodInventory::class);
}

function linkSocialAccount(User $user, string $provider = 'google'): void
{
    $account = new SocialAccount([
        'provider' => $provider,
        'provider_user_id' => 'ext-'.$user->getKey().'-'.$provider,
    ]);
    $account->user()->associate($user);
    $account->save();
}

/** config/fortify.php の features から passkeys を外す (キルスイッチの再現) */
function disablePasskeyFeature(): void
{
    config()->set(
        'fortify.features',
        array_values(array_filter(
            config()->array('fortify.features'),
            static fn (mixed $feature): bool => $feature !== Features::passkeys(),
        )),
    );
}

test('password ユーザーは password を手段に持つ', function (): void {
    $user = User::factory()->create();

    expect(inventory()->remainingAfter($user, LoginMethodRemoval::none())->methods)
        ->toContain('password');
});

test('SSO 登録ユーザー (ssoOnly) は password を手段に持たない', function (): void {
    $user = User::factory()->ssoOnly()->create();

    expect(inventory()->remainingAfter($user, LoginMethodRemoval::none())->methods)
        ->not->toContain('password');
});

test('連携済み provider は social: 付きで数えられる', function (): void {
    $user = User::factory()->ssoOnly()->create();
    linkSocialAccount($user);

    expect(inventory()->remainingAfter($user, LoginMethodRemoval::none())->methods)
        ->toContain('social:google');
});

test('config から外された provider は連携行があっても数えない (fail-closed)', function (): void {
    $user = User::factory()->ssoOnly()->create();
    linkSocialAccount($user);

    config()->set('template.social_providers', []);

    expect(inventory()->remainingAfter($user, LoginMethodRemoval::none())->isEmpty())->toBeTrue();
});

test('social 除去の投影で当該 provider が集合から消える', function (): void {
    $user = User::factory()->ssoOnly()->create();
    linkSocialAccount($user);

    expect(inventory()->remainingAfter($user, LoginMethodRemoval::social('google'))->isEmpty())
        ->toBeTrue();
});

test('password 除去の投影で password が集合から消える', function (): void {
    $user = User::factory()->create();

    expect(inventory()->remainingAfter($user, LoginMethodRemoval::password())->isEmpty())
        ->toBeTrue();
});

test('passkey は登録済みなら手段に数えられる', function (): void {
    $user = User::factory()->ssoOnly()->create();
    Passkey::factory()->for($user)->create();

    expect(inventory()->remainingAfter($user, LoginMethodRemoval::none())->methods)
        ->toContain('passkey');
});

test('削除対象の passkey は残存手段として数えない (投影)', function (): void {
    $user = User::factory()->ssoOnly()->create();
    $passkey = Passkey::factory()->for($user)->create();

    expect(inventory()->remainingAfter($user, LoginMethodRemoval::passkey($passkey, $user))->isEmpty())
        ->toBeTrue();
});

test('passkey が 2 件あれば 1 件削除しても手段が残る', function (): void {
    $user = User::factory()->ssoOnly()->create();
    $first = Passkey::factory()->for($user)->create();
    Passkey::factory()->for($user)->create();

    expect(inventory()->remainingAfter($user, LoginMethodRemoval::passkey($first, $user))->methods)
        ->toContain('passkey');
});

test('allPasskeys 投影では passkey が全て消える', function (): void {
    $user = User::factory()->ssoOnly()->create();
    Passkey::factory()->count(2)->for($user)->create();

    expect(inventory()->remainingAfter($user, LoginMethodRemoval::allPasskeys())->isEmpty())
        ->toBeTrue();
});

test('feature off では passkey を手段に数えない (キルスイッチが inventory に連動する)', function (): void {
    $user = User::factory()->ssoOnly()->create();
    Passkey::factory()->for($user)->create();

    disablePasskeyFeature();

    expect(inventory()->remainingAfter($user, LoginMethodRemoval::none())->isEmpty())->toBeTrue();
});

test('TOTP confirmed ユーザーは passkey を手段に数えない (passkey login が拒否されるため)', function (): void {
    $user = User::factory()->ssoOnly()->withTwoFactor()->create();
    Passkey::factory()->for($user)->create();

    expect(inventory()->remainingAfter($user, LoginMethodRemoval::none())->isEmpty())->toBeTrue();
});

/* ---------------------------------------------------------------- 不正状態の排除 */

test('他人の passkey を LoginMethodRemoval::passkey に渡すと例外', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $passkey = Passkey::factory()->for($other)->create();

    expect(fn () => LoginMethodRemoval::passkey($passkey, $user))
        ->toThrow(InvalidArgumentException::class);
});

test('空 provider を LoginMethodRemoval::social に渡すと例外', function (): void {
    expect(fn () => LoginMethodRemoval::social(''))
        ->toThrow(InvalidArgumentException::class);
});

/* -------------------------------------------- inventory と login authorization の一致 */

/*
 * 構造 gate では「同じ判定を 2 箇所に書いていない」ことしか固定できないため、
 * 意味レベル (両者の結論が常に一致すること) をここで固定する。
 */
test('inventory の passkey 判定と Passkeys::allowsLogin が一致する (TOTP × feature の 4 組合せ)', function (
    bool $twoFactor,
    bool $featureEnabled,
): void {
    $factory = User::factory()->ssoOnly();
    $user = ($twoFactor ? $factory->withTwoFactor() : $factory)->create();
    $passkey = Passkey::factory()->for($user)->create();

    if (! $featureEnabled) {
        disablePasskeyFeature();
    }

    $inventoryHasPasskey = in_array(
        'passkey',
        inventory()->remainingAfter($user->fresh() ?? $user, LoginMethodRemoval::none())->methods,
        true,
    );

    $vendorAllows = Passkeys::allowsLogin(Request::create('/passkeys/login', 'POST'), $passkey);

    expect($inventoryHasPasskey)->toBe($vendorAllows);
    expect($vendorAllows)->toBe(app(PasskeyLoginPolicy::class)->allowsPasskeyLogin($user->fresh() ?? $user));
})->with([
    'TOTP なし / feature on' => [false, true],
    'TOTP あり / feature on' => [true, true],
    'TOTP なし / feature off' => [false, false],
    'TOTP あり / feature off' => [true, false],
]);
