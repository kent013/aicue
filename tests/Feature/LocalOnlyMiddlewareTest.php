<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

/*
 * local 専用デバッグログイン (/debug/login) の防御層テスト。
 *
 * route 登録は routes/web.php の isLocal || runningUnitTests ゲートにより
 * テスト実行中は常に有効。環境判定・資格情報検査は LocalOnly middleware
 * (config('app.env') を直接参照) が担うため、config 上書きで検証できる。
 */

beforeEach(function (): void {
    config(['app.env' => 'local']);
    // 平文 password (LocalOnly は hash_equals で timing-safe 比較する)
    config(['debug.login.user' => 'testuser']);
    config(['debug.login.password' => 'testpass123']);
});

test('production 環境では debug login 経路が 404', function (): void {
    config(['app.env' => 'production']);
    $response = $this->get('/debug/login');
    $response->assertNotFound();
});

test('production 環境では loginAs (POST) も 404', function (): void {
    [, $user] = createOrganizationWithOwner();
    config(['app.env' => 'production']);
    $response = $this->withHeaders([
        'PHP_AUTH_USER' => 'testuser',
        'PHP_AUTH_PW' => 'testpass123',
    ])->post("/debug/login/{$user->id}");
    $response->assertNotFound();
    $this->assertGuest();
});

test('local + DEBUG_LOGIN_* 未設定なら 404 (fail-secure)', function (): void {
    config(['debug.login.user' => '']);
    config(['debug.login.password' => '']);
    $response = $this->get('/debug/login');
    $response->assertNotFound();
});

test('local + DEBUG_LOGIN_* が null (env 未定義) でも 404 (fail-secure)', function (): void {
    config(['debug.login.user' => null]);
    config(['debug.login.password' => null]);
    $response = $this->get('/debug/login');
    $response->assertNotFound();
});

test('local + 認証情報なし → 401 (Basic 認証を要求)', function (): void {
    $response = $this->get('/debug/login');
    $response->assertStatus(401);
    expect((string) $response->headers->get('WWW-Authenticate'))->toContain('Basic');
});

test('local + 不正 user → 401', function (): void {
    $response = $this->withHeaders([
        'PHP_AUTH_USER' => 'wronguser',
        'PHP_AUTH_PW' => 'testpass123',
    ])->get('/debug/login');
    $response->assertStatus(401);
});

test('local + 不正 password → 401', function (): void {
    $response = $this->withHeaders([
        'PHP_AUTH_USER' => 'testuser',
        'PHP_AUTH_PW' => 'wrongpass',
    ])->get('/debug/login');
    $response->assertStatus(401);
});

test('local + 正しい credential → ユーザー一覧 (Debug/Login) が表示される', function (): void {
    [$organization, $user] = createOrganizationWithOwner('デバッグ組織');

    $response = $this->withHeaders([
        'PHP_AUTH_USER' => 'testuser',
        'PHP_AUTH_PW' => 'testpass123',
    ])->get('/debug/login');

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Debug/Login')
        ->has('users', 1)
        ->where('users.0.id', $user->id)
        ->where('users.0.organization', $organization->name));
});

test('loginAs で対象ユーザーとしてログインし dashboard へ redirect', function (): void {
    [$organization, $user] = createOrganizationWithOwner();

    $response = $this->withHeaders([
        'PHP_AUTH_USER' => 'testuser',
        'PHP_AUTH_PW' => 'testpass123',
    ])->post("/debug/login/{$user->id}");

    $response->assertRedirect("/organizations/{$organization->slug}/dashboard");
    $this->assertAuthenticatedAs($user);
});

test('loginAs で存在しないユーザー id は 404', function (): void {
    $response = $this->withHeaders([
        'PHP_AUTH_USER' => 'testuser',
        'PHP_AUTH_PW' => 'testpass123',
    ])->post('/debug/login/999999');

    $response->assertNotFound();
    $this->assertGuest();
});
