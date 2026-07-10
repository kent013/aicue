<?php

declare(strict_types=1);

use App\Enums\SecurityEventType;
use App\Models\AdminUser;
use App\Models\SecurityAuditEvent;

/*
 * admin:reset-mfa — MFA 紛失時の break-glass 復旧経路。
 * --reason (trim 後 mb 10 文字以上) を必須とし、security_audit_events に記録する。
 */

test('reason 付きで MFA 2 列のみ null 化し監査ログを記録する', function (): void {
    $admin = AdminUser::factory()->withMfa()->create();
    $passwordHashBefore = $admin->password;

    $this->artisan('admin:reset-mfa', [
        'id' => (string) $admin->id,
        '--reason' => '認証装置を紛失したため再セットアップを許可する',
    ])->assertExitCode(0);

    $admin->refresh();
    expect($admin->app_authentication_secret)->toBeNull();
    expect($admin->app_authentication_recovery_codes)->toBeNull();
    // MFA 2 列以外 (password 等) には触れないこと
    expect($admin->password)->toBe($passwordHashBefore);

    // 監査ログ (security_audit_events) の記録
    $event = SecurityAuditEvent::query()
        ->where('event_type', SecurityEventType::AdminMfaReset->value)
        ->first();
    expect($event)->not->toBeNull();
    expect($event?->metadata)->toMatchArray([
        'admin_user_id' => $admin->id,
        'reason' => '認証装置を紛失したため再セットアップを許可する',
        'executed_by' => 'cli:admin:reset-mfa',
    ]);
});

test('reason は前後空白を trim してから 10 文字判定する', function (): void {
    $admin = AdminUser::factory()->withMfa()->create();

    // 空白込みで 10 文字を超えても trim 後 9 文字なら fail
    $this->artisan('admin:reset-mfa', [
        'id' => (string) $admin->id,
        '--reason' => '  123456789  ',
    ])->assertExitCode(1);

    expect($admin->refresh()->app_authentication_secret)->not->toBeNull();
});

test('reason が 10 文字未満 (マルチバイト文字数基準) は fail する', function (): void {
    $admin = AdminUser::factory()->withMfa()->create();

    // 日本語 9 文字 (バイト数では 10 バイト超だが mb_strlen で判定)
    $this->artisan('admin:reset-mfa', [
        'id' => (string) $admin->id,
        '--reason' => 'デバイスを紛失した',
    ])->assertExitCode(1);

    expect($admin->refresh()->app_authentication_secret)->not->toBeNull();
});

test('reason 未指定は fail する', function (): void {
    $admin = AdminUser::factory()->withMfa()->create();

    $this->artisan('admin:reset-mfa', [
        'id' => (string) $admin->id,
    ])->assertExitCode(1);

    expect($admin->refresh()->app_authentication_secret)->not->toBeNull();
});

test('存在しない id は fail する', function (): void {
    $this->artisan('admin:reset-mfa', [
        'id' => '99999',
        '--reason' => '認証装置を紛失したため再セットアップを許可する',
    ])->assertExitCode(1);

    expect(SecurityAuditEvent::query()->count())->toBe(0);
});

test('id が 0 以下は fail する', function (): void {
    $this->artisan('admin:reset-mfa', [
        'id' => '0',
        '--reason' => '認証装置を紛失したため再セットアップを許可する',
    ])->assertExitCode(1);
});
