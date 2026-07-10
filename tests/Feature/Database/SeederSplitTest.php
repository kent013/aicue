<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

/*
 * Seeder 分割 (WP50): RoleSeeder / PermissionSeeder / RolePermissionSeeder の
 * 冪等性と DatabaseSeeder の call 順 (Role → Permission → RolePermission → Plan →
 * AdminUser) を検証する。DatabaseSeeder は TestCase の $seed=true で毎テスト走る。
 */

test('DatabaseSeeder 実行後に全組織ロールが display_name 付きで存在する', function (): void {
    foreach (OrganizationRole::cases() as $role) {
        $record = Role::query()->where('name', $role->value)->first();

        expect($record)->not->toBeNull();
        expect($record?->display_name)->toBe($role->label());
    }
});

test('RoleSeeder は再実行安全で display_name のドリフトを修復する (updateOrCreate)', function (): void {
    // display_name が手動変更などでドリフトした状態を作る
    Role::query()
        ->where('name', OrganizationRole::Owner->value)
        ->update(['display_name' => '壊れた表示名']);

    $before = Role::query()->count();
    $this->seed(RoleSeeder::class);

    // 件数は増えず、display_name は enum の label() に修復される
    expect(Role::query()->count())->toBe($before);
    expect(
        Role::query()->where('name', OrganizationRole::Owner->value)->value('display_name'),
    )->toBe(OrganizationRole::Owner->label());
});

test('PermissionSeeder / RolePermissionSeeder は再実行しても重複しない (冪等)', function (): void {
    $permissionCount = Permission::query()->count();
    $pivotCount = DB::table('permission_role')->count();

    $this->seed(PermissionSeeder::class);
    $this->seed(RolePermissionSeeder::class);

    expect(Permission::query()->count())->toBe($permissionCount);
    expect(DB::table('permission_role')->count())->toBe($pivotCount);
});
