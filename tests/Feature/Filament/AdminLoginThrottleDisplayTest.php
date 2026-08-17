<?php

declare(strict_types=1);

use App\Filament\Auth\Login;
use App\Models\AdminUser;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| 管理画面ログインの上限到達時の表示 (裁定 AG-017b)
|--------------------------------------------------------------------------
|
| vendor (Filament) の Login は上限に達すると通知を出して早期 return するだけで、
| Livewire は入力エラーを次の要求へ持ち越す。結果、上限に達した画面には
| 直前の試行の入力エラー (「認証に失敗しました。」) が残り、実態と食い違う理由を
| 表示し続ける。
|
| 台帳 (機能 filament-login-throttle-display) が求める保証は 2 つで、
| 本テストはその両方を、通常の上限 (authenticate 冒頭) と
| 多要素チャレンジ専用の上限の**両方の経路**で固定する:
|   (1) 前の要求で付いた入力エラーを画面に残さないこと
|   (2) 再開までの残り秒数を含む案内を入力欄の位置に示すこと
|
| 振る舞いの検査は、独自クラスを名指しせず panel に配線されたクラスを実行時に解決して行う
| (実装前は vendor の Login に対して不具合が再現し、実装後は同じ検査が独自クラスに対して
|  緑になる = 「意図した赤」を観測できる)。配線そのものは最後の検査が別途固定する
| (`Login::class` は名前を字句として解決するだけでクラスの読み込みを起こさないため、
|  実装前でも「配線されていない」という赤として観測できる)。
|
*/

beforeEach(function (): void {
    // panel 解決 (Filament::auth() 等) を admin panel に固定する
    Filament::setCurrentPanel('admin');
    // 上限到達の「あと何秒」を決定的にする (減衰は 60 秒)
    $this->freezeTime();
});

/** panel に配線されているログインページのクラス名を返す。 */
function adminLoginPageClass(): string
{
    $loginPage = Filament::getPanel('admin')->getLoginRouteAction();

    expect($loginPage)->toBeString();
    expect(class_exists((string) $loginPage))->toBeTrue();

    return (string) $loginPage;
}

/** 上限到達時に入力欄へ出す案内 (残り秒数入り)。 */
function adminLoginThrottleMessage(int $seconds = 60): string
{
    $message = __('auth.throttle', ['seconds' => $seconds]);

    expect($message)->toBeString();

    return (string) $message;
}

test('通常の上限に達すると、前の試行の入力エラーが残り秒数入りの案内へ差し替わる', function (): void {
    AdminUser::factory()->create(['email' => 'admin@example.com']);

    $component = Livewire::test(adminLoginPageClass())
        ->fillForm(['email' => 'admin@example.com', 'password' => 'wrong-password']);

    // vendor の上限は 5 回。5 回目までは従来どおり認証失敗の入力エラーが出る
    foreach (range(1, 5) as $ignored) {
        $component->call('authenticate')->assertHasErrors(['data.email']);
    }

    // 6 回目は上限到達 = 前の試行の入力エラーを捨て、残り秒数の案内へ差し替える
    $component->call('authenticate')->assertHasErrors(['data.email']);

    $errors = $component->errors();

    // メッセージは error bag の完全一致で固定する
    // (`assertHasErrors(['key' => 値])` は失敗した規則名とも照合する形なので、
    //  「残り秒数入りの案内そのもの」を固定したいときは使わない)。
    // これ 1 本で (1) 前の試行の理由 (認証に失敗しました。) が残っていないこと・
    // 案内が積み増しではなく差し替えであること・(2) 残り秒数を含むことを同時に固定する
    expect($errors->get('data.email'))->toBe([adminLoginThrottleMessage()]);
    expect(array_keys($errors->toArray()))->toBe(['data.email']);

    Notification::assertNotified(
        __('filament-panels::auth/pages/login.notifications.throttled.title', [
            'seconds' => 60,
            'minutes' => 1,
        ]),
    );
});

test('上限に達する前は従来どおり認証失敗の入力エラーが出る (消しすぎの検出)', function (): void {
    AdminUser::factory()->create(['email' => 'admin@example.com']);

    $component = Livewire::test(adminLoginPageClass())
        ->fillForm(['email' => 'admin@example.com', 'password' => 'wrong-password'])
        ->call('authenticate')
        ->assertHasErrors(['data.email']);

    expect($component->errors()->get('data.email'))
        ->toContain(__('filament-panels::auth/pages/login.messages.failed'));
});

test('多要素チャレンジ専用の上限でも、持ち越しエラーが案内へ差し替わりチャレンジ状態は保たれる', function (): void {
    $admin = AdminUser::factory()->withMfa()->create(['email' => 'admin@example.com']);

    // 1 回目: 資格情報は正しいので多要素チャレンジへ進む (通常の計上 1 回)
    $component = Livewire::test(adminLoginPageClass())
        ->fillForm(['email' => 'admin@example.com', 'password' => 'password'])
        ->call('authenticate');

    expect($component->get('userUndertakingMultiFactorAuthentication'))->not->toBeNull();

    // 2 回目: 確認コード未入力で送る = 確認コード欄に入力エラーが立つ (通常の計上 2 回)
    // (コードを詐称すると偶然正しい TOTP になりうるため、未入力で確実に落とす)
    $component->call('authenticate')->assertHasErrors(['data.multiFactor.app.code']);

    // 通常側は上限未満のまま、多要素チャレンジ専用の計数だけを上限まで積む
    // (鍵は vendor と同じく認証識別子で組み立てる。主キーとは限らない)
    $challengeKey = "filament-multi-factor-challenge:{$admin->getAuthIdentifier()}";
    while (! RateLimiter::tooManyAttempts($challengeKey, maxAttempts: 5)) {
        RateLimiter::hit($challengeKey);
    }

    // 3 回目: 多要素チャレンジ専用の上限に達する経路。
    // 持ち越しエラーは案内へ差し替わり、チャレンジ表示と入力値は保たれる
    $component->call('authenticate')->assertHasErrors(['data.multiFactor.app.code']);

    $errors = $component->errors();

    expect($errors->get('data.multiFactor.app.code'))->toBe([adminLoginThrottleMessage()]);
    expect(array_keys($errors->toArray()))->toBe(['data.multiFactor.app.code']);

    expect($component->get('userUndertakingMultiFactorAuthentication'))->not->toBeNull();
    expect($component->get('data.email'))->toBe('admin@example.com');

    Notification::assertNotified(
        __('filament-panels::auth/pages/login.notifications.throttled.title', [
            'seconds' => 60,
            'minutes' => 1,
        ]),
    );
});

test('panel のログインページは独自クラスで、ページとして自動発見されていない', function (): void {
    expect(Filament::getPanel('admin')->getLoginRouteAction())
        ->toBe(Login::class);

    // 独自ログインページを app/Filament/Pages/ 配下に置くと自動発見が
    // 通常ページとして登録し、ここに login を含む route が現れる
    // (置き場所の誤りを検出する。正当なページの追加では赤くしない)
    foreach (Route::getRoutes() as $route) {
        $name = $route->getName();
        if ($name === null || ! str_starts_with($name, 'filament.admin.pages.')) {
            continue;
        }

        expect($name)->not->toContain('login');
    }
});
