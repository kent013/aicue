<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\User;

/*
 * Filament 管理画面 (/admin) のアクセス制御。
 * admin guard (AdminUser) と web guard (User) のセッション分離を検証する。
 */

test('未認証で /admin にアクセスするとログイン画面へ redirect される', function (): void {
    $this->get('/admin')
        ->assertRedirect('/admin/login');
});

test('/admin/login は未認証で表示できる', function (): void {
    $this->get('/admin/login')
        ->assertOk();
});

test('AdminUser でログインすると /admin が 200 を返す', function (): void {
    // MFA は既定で必須 (config/admin.php) のため設定済み state を使う
    // (未設定 admin の redirect は tests/Feature/Filament/AdminMfaBypassPreventionTest が検証)
    $admin = AdminUser::factory()->withMfa()->create();

    $this->actingAs($admin, 'admin')
        ->get('/admin')
        ->assertOk();
});

test('一般 User の web guard セッションでは /admin にアクセスできない (guard 分離)', function (): void {
    $user = User::factory()->create();

    // web guard で認証済みでも admin guard は未認証のためログインへ redirect される
    $this->actingAs($user)
        ->get('/admin')
        ->assertRedirect('/admin/login');

    expect(auth()->guard('admin')->check())->toBeFalse();
});
