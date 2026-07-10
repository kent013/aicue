<?php

declare(strict_types=1);

use App\Models\AdminUser;
use Illuminate\Support\Facades\Hash;

/*
 * admin:create — 本番初期 admin の正式発行経路 (env / seeder 投入は廃止済み)。
 * パスワードは PasswordPolicy (min12 + mixedCase + numbers) を明示検証する。
 */

test('オプション指定で AdminUser を作成できる (password は hash 保存)', function (): void {
    $this->artisan('admin:create', [
        '--email' => 'ops@example.com',
        '--name' => '運用担当',
        '--password' => 'ValidPassword123',
    ])->assertExitCode(0);

    $admin = AdminUser::whereBlind('email', 'email_index', 'ops@example.com')->first();
    expect($admin)->not->toBeNull();
    expect($admin?->name)->toBe('運用担当');
    expect(Hash::check('ValidPassword123', $admin?->password ?? ''))->toBeTrue();
});

test('--password 指定時は shell history 露出の警告を表示する', function (): void {
    $this->artisan('admin:create', [
        '--email' => 'ops@example.com',
        '--name' => '運用担当',
        '--password' => 'ValidPassword123',
    ])
        ->expectsOutputToContain('WARNING')
        ->assertExitCode(0);
});

test('対話入力でも作成できる (password は secret 入力)', function (): void {
    $this->artisan('admin:create')
        ->expectsQuestion('Email', 'interactive@example.com')
        ->expectsQuestion('Name', '対話管理者')
        ->expectsQuestion('Password (12文字以上 / 大文字・小文字を含む / 数字を含む)', 'ValidPassword123')
        ->assertExitCode(0);

    expect(AdminUser::whereBlind('email', 'email_index', 'interactive@example.com')->exists())->toBeTrue();
});

test('PasswordPolicy 未達 (12 文字未満) は fail し作成しない', function (): void {
    $this->artisan('admin:create', [
        '--email' => 'ops@example.com',
        '--name' => '運用担当',
        '--password' => 'Short12',
    ])->assertExitCode(1);

    expect(AdminUser::whereBlind('email', 'email_index', 'ops@example.com')->exists())->toBeFalse();
});

test('PasswordPolicy 未達 (大文字小文字混在なし / 数字なし) は fail する', function (string $password): void {
    $this->artisan('admin:create', [
        '--email' => 'ops@example.com',
        '--name' => '運用担当',
        '--password' => $password,
    ])->assertExitCode(1);

    expect(AdminUser::whereBlind('email', 'email_index', 'ops@example.com')->exists())->toBeFalse();
})->with([
    'mixedCase なし' => ['lowercaseonly123'],
    'numbers なし' => ['NoNumbersHerePassword'],
]);

test('不正な email 形式は fail する', function (): void {
    $this->artisan('admin:create', [
        '--email' => 'not-an-email',
        '--name' => '運用担当',
        '--password' => 'ValidPassword123',
    ])->assertExitCode(1);

    expect(AdminUser::query()->count())->toBe(0);
});

test('name 空は fail する', function (): void {
    $this->artisan('admin:create', [
        '--email' => 'ops@example.com',
        '--name' => '',
        '--password' => 'ValidPassword123',
    ])->assertExitCode(1);

    expect(AdminUser::query()->count())->toBe(0);
});

test('既存 email との重複は fail し既存 AdminUser を変更しない', function (): void {
    $existing = AdminUser::factory()->create(['email' => 'ops@example.com']);

    $this->artisan('admin:create', [
        '--email' => 'ops@example.com',
        '--name' => '別の管理者',
        '--password' => 'ValidPassword123',
    ])->assertExitCode(1);

    expect(AdminUser::query()->count())->toBe(1);
    expect($existing->refresh()->name)->not->toBe('別の管理者');
});
