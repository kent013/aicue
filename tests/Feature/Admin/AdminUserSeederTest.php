<?php

declare(strict_types=1);

use App\Models\AdminUser;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Support\Facades\Hash;

/*
 * AdminUserSeeder: local 開発専用の固定 AdminUser を作成する
 * (email は暗号化カラムのため whereBlind で冪等化)。
 * local 以外の環境では skip する (本番の初期 admin は admin:create コマンド)。
 */

test('local 以外の環境 (testing) では AdminUser を作成しない (skip)', function (): void {
    $this->seed(AdminUserSeeder::class);

    expect(AdminUser::query()->count())->toBe(0);
});

test('local 環境では固定 AdminUser を作成し、再実行しても増えない (冪等)', function (): void {
    $this->app['env'] = 'local';

    $this->seed(AdminUserSeeder::class);
    $this->seed(AdminUserSeeder::class);

    $admins = AdminUser::whereBlind('email', 'email_index', 'admin@example.com')->get();
    expect($admins)->toHaveCount(1);

    $admin = $admins->first();
    expect($admin?->name)->toBe('Local Admin');
    expect(Hash::check('password12345', $admin?->password ?? ''))->toBeTrue();
});

test('再実行時に既存 AdminUser のパスワードを上書きしない', function (): void {
    $this->app['env'] = 'local';
    $this->seed(AdminUserSeeder::class);

    // 運用 (開発) で変更されたパスワードを seeder の再実行が巻き戻さないこと
    $admin = AdminUser::whereBlind('email', 'email_index', 'admin@example.com')->firstOrFail();
    $admin->password = 'ChangedPassword456';
    $admin->save();

    $this->seed(AdminUserSeeder::class);

    $admin->refresh();
    expect(Hash::check('ChangedPassword456', $admin->password))->toBeTrue();
});
