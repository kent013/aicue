<?php

declare(strict_types=1);

use App\Models\AdminUser;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| AdminUser の CipherSweet 暗号化 (WP45)
|--------------------------------------------------------------------------
|
| AdminUser も User と同様に name / email を CipherSweet で暗号化する。
| - 検索は whereBlind('email', 'email_index', ...) のみ (平文 where は hit しない)
| - email 一意性は blind_indexes の partial unique index が DB 層で担保する
| - 認証は encrypted-eloquent provider (config/auth.php providers.admin_users)
|
*/

test('name / email は DB 上で暗号化されて保存される (平文が残らない)', function (): void {
    $admin = AdminUser::factory()->create([
        'name' => '暗号化 太郎',
        'email' => 'encrypted-admin@example.com',
    ]);

    $raw = DB::table('admin_users')->where('id', $admin->id)->first();

    expect($raw->email)->not->toBe('encrypted-admin@example.com');
    expect($raw->name)->not->toBe('暗号化 太郎');

    // モデル経由の読み出しは復号済みの平文になる
    $admin->refresh();
    expect($admin->email)->toBe('encrypted-admin@example.com');
    expect($admin->name)->toBe('暗号化 太郎');
});

test('平文 where は hit せず whereBlind (email_index) で検索できる', function (): void {
    $admin = AdminUser::factory()->create(['email' => 'blind-admin@example.com']);

    expect(AdminUser::query()->where('email', 'blind-admin@example.com')->exists())->toBeFalse();

    $found = AdminUser::whereBlind('email', 'email_index', 'blind-admin@example.com')->first();
    expect($found?->id)->toBe($admin->id);
});

test('AdminUser の email blind index は DB 層で一意 (INSERT race の最終防衛線)', function (): void {
    AdminUser::factory()->create(['email' => 'unique-admin@example.com']);

    expect(fn () => AdminUser::factory()->create(['email' => 'unique-admin@example.com']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('admin guard は encrypted-eloquent provider 経由で email + password 認証できる', function (): void {
    AdminUser::factory()->create(['email' => 'login-admin@example.com']);

    // factory の共通パスワードは 'password'
    expect(Auth::guard('admin')->validate([
        'email' => 'login-admin@example.com',
        'password' => 'password',
    ]))->toBeTrue();

    expect(Auth::guard('admin')->validate([
        'email' => 'login-admin@example.com',
        'password' => 'wrong-password',
    ]))->toBeFalse();
});
