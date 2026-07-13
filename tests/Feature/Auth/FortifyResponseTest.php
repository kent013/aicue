<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/*
 * Fortify Response contract bind (app/Http/Responses/Fortify/) の応答契約の正本。
 * Login / Register の redirect 契約は AuthenticationTest / RegistrationTest が担う。
 *
 * flash キー統一ポリシー: web 向け操作成功 flash は success に統一する。
 * status は flash-to-toast (resources/js/lib/stores/flash-to-toast.ts) が意図的に
 * gating しており toast にならないため使わない。bind 済みの TwoFactorDisabledResponse /
 * RecoveryCodesGeneratedResponse も success キーで実装済み (2FA 系 Feature テストが担保)。
 */

test('forgot-password は user 在/不在で同一応答 (enumeration 抑止)', function (): void {
    Notification::fake();
    User::factory()->create(['email' => 'exists@example.com']);

    $existing = $this->from('/forgot-password')->post('/forgot-password', [
        'email' => 'exists@example.com',
    ]);
    $missing = $this->from('/forgot-password')->post('/forgot-password', [
        'email' => 'missing@example.com',
    ]);

    // どちらも同一の success flash + redirect back (成功/失敗を区別させない)。
    // 同一メッセージだけでなく同一キーであることも固定する (片側だけ status が
    // 残る誤実装も enumeration 差分になるため検出する)
    $existing->assertRedirect('/forgot-password');
    $missing->assertRedirect('/forgot-password');
    $existing->assertSessionHas('success', 'パスワードリセット用のリンクをメールで送信しました。');
    $missing->assertSessionHas('success', 'パスワードリセット用のリンクをメールで送信しました。');
    $existing->assertSessionMissing('status');
    $missing->assertSessionMissing('status');
    $missing->assertSessionDoesntHaveErrors();
});

test('認証メール再送は success flash を返す (web)', function (): void {
    // verification.send は auth:web + throttle:6,1 (config fortify.limiters.verification)。
    // 本テストは 1 リクエストのみ発行し throttle 上限に構造的に触れない
    // (middleware の抑制はしない。並列実行でもユーザー毎にレートキーは独立)。
    // JSON 分岐は Fortify 元実装互換のため wantsJson/202 を維持している
    // (既存 3 クラスの expectsJson とあえて揃えない)。
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)
        ->from('/email/verify')
        ->post('/email/verification-notification');

    $response->assertRedirect('/email/verify');
    $response->assertSessionHas('success', '認証メールを再送信しました。');
    $response->assertSessionMissing('status');
    Notification::assertSentTo($user, VerifyEmail::class);
});

test('認証メール再送は JSON リクエストに 202 を返す (Fortify 既定互換)', function (): void {
    // VerificationNotificationSentResponse の wantsJson 分岐は Fortify 既定
    // (wantsJson / 202) の挙動互換を維持する設計意図の固定。誤って expectsJson 化・
    // ステータス変更されると XHR クライアントの契約が壊れるため契約テストで固定する。
    // 別ユーザーで 1 リクエストのみ発行するため throttle:6,1 には触れない。
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)
        ->postJson('/email/verification-notification');

    $response->assertStatus(202);
    Notification::assertSentTo($user, VerifyEmail::class);
});

test('プロフィール更新は success flash を返す (web)', function (): void {
    $user = User::factory()->create();

    // email は現状維持 (同一 email 分岐で通知/再検証を発火させず flash 契約に集中)
    $response = $this->actingAs($user)
        ->from('/settings')
        ->put('/user/profile-information', [
            'name' => '更新後の名前',
            'email' => $user->email,
        ]);

    $response->assertRedirect('/settings');
    $response->assertSessionHas('success', 'プロフィールを更新しました。');
    $response->assertSessionMissing('status');
});

test('プロフィール更新は JSON リクエストに 200 を返す', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->putJson('/user/profile-information', [
            'name' => '更新後の名前',
            'email' => $user->email,
        ]);

    $response->assertStatus(200);
});

test('パスワード変更は success flash を返す (web)', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->from('/settings')
        ->put('/user/password', [
            'current_password' => 'password',
            'password' => 'NewPassword123',
        ]);

    $response->assertRedirect('/settings');
    $response->assertSessionHas('success', 'パスワードを変更しました。');
    $response->assertSessionMissing('status');
});

test('パスワード変更は JSON リクエストに 200 を返す', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->putJson('/user/password', [
            'current_password' => 'password',
            'password' => 'NewPassword123',
        ]);

    $response->assertStatus(200);
});

test('パスワードリセットは success flash + login redirect を返す (web)', function (): void {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $response = $this->from('/reset-password')->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'NewPassword123',
        'password_confirmation' => 'NewPassword123',
    ]);

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('success', 'パスワードを変更しました。ログインしてください。');
    $response->assertSessionMissing('status');
});

test('パスワードリセットは JSON リクエストに 200 + message を返す', function (): void {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $response = $this->postJson('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'NewPassword123',
        'password_confirmation' => 'NewPassword123',
    ]);

    $response->assertOk();
    $response->assertJsonPath('message', __('passwords.reset'));
});

test('パスワードリセットは不正 token では success flash を出さない (非回帰)', function (): void {
    $user = User::factory()->create();

    $response = $this->from('/reset-password')->post('/reset-password', [
        'token' => 'invalid-token-string',
        'email' => $user->email,
        'password' => 'NewPassword123',
        'password_confirmation' => 'NewPassword123',
    ]);

    $response->assertSessionHasErrors();
    $response->assertSessionMissing('success');
});

test('パスワードリセットは期限切れ token では success flash を出さない (非回帰)', function (): void {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    // token 有効期限 (config auth.passwords.users.expire=60分) を超過させる
    $this->travel(61)->minutes();

    $response = $this->from('/reset-password')->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'NewPassword123',
        'password_confirmation' => 'NewPassword123',
    ]);

    $this->travelBack();

    $response->assertSessionHasErrors();
    $response->assertSessionMissing('success');
});
