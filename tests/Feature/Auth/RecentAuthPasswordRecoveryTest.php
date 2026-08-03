<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/*
 * ConfirmRecentAuth (canSatisfy=false) が案内する回復手順が **端まで成立する** ことの固定。
 *
 * 「再認証手段が無い」ユーザーに提示できるのは「いったんログアウトし、guest として
 * パスワードを再設定する」経路だけである (アプリ内でパスワードを設定する経路は無い:
 * UpdateUserPassword は current_password 必須、/forgot-password は guest middleware 付き)。
 * 案内はあるが実際にはできない、という F-2-01 型の再発を防ぐため、
 * ログアウト着地 → リセットリンク → パスワード設定 → canSatisfy=true までを 1 本で通す。
 *
 * 画面上の導線 (Welcome の「ログイン」/ Login の「パスワードをお忘れの方」) は
 * tests/js/pages/Welcome.test.ts / Login.test.ts が保証しており、回復経路は
 * これらのテスト群**全体**で担保される (本テスト 1 本で完結するわけではない)。
 */
test('再認証手段が無いユーザーはログアウト後にパスワードを設定でき、再認証可能になる', function (): void {
    // PasswordPolicy の HIBP 照会を止める (外部依存をテストに持ち込まない)
    Http::fake(['https://api.pwnedpasswords.com/range/*' => Http::response('', 200)]);
    Notification::fake();

    $user = User::factory()->ssoOnly()->create();
    $email = $user->email;

    // 1. 出発点: 再認証手段が無い (= 本画面が回復手順を案内する状態)
    $this->actingAs($user)->get('/recent-auth/confirm')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canSatisfy', false));

    // 2. 案内どおりログアウトする。Fortify 既定 (Fortify::redirects('logout')) で `/` = Welcome へ
    //    着地し、そこには guest nav の「ログイン」リンクが常時ある (Welcome.test.ts が固定)。
    $logout = $this->post('/logout');
    $logout->assertRedirect('/');
    $this->assertGuest();

    // 3. guest としてリセットリンクを要求する (ログイン済みでは guest ゲートに阻まれる経路)
    $this->post('/forgot-password', ['email' => $email])->assertSessionHasNoErrors();

    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });
    expect($token)->toBeString();

    // 4. パスワードを設定する。ResetUserPassword は confirmed を使わない (確認入力フィールドは無い)
    $response = $this->post('/reset-password', [
        'token' => $token,
        'email' => $email,
        'password' => 'CorrectHorse9Battery',
    ]);
    $response->assertRedirect(route('login'));
    $response->assertSessionHasNoErrors();

    // 5. 到達点: password が設定され、再認証できる状態になっている
    $fresh = $user->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh?->hasPassword())->toBeTrue();

    $this->actingAs($fresh)->get('/recent-auth/confirm')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('passwordSet', true)
            ->where('canSatisfy', true));
});
