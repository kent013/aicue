<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * ロール → permission の紐付け専用シーダー (再実行安全)。
 * ロール定義は RoleSeeder、permission 定義は PermissionSeeder が先に流れている前提
 * (DatabaseSeeder の call 順)。未紐付けのものだけ追加する (additive。既存の紐付けは
 * 剥がさない)。
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->rolePermissions() as $roleName => $permissionNames) {
            $role = Role::query()->where('name', $roleName)->firstOrFail();

            foreach ($permissionNames as $permissionName) {
                if ($role->permissions()->where('name', $permissionName)->exists()) {
                    continue;
                }

                $permission = Permission::query()->where('name', $permissionName)->firstOrFail();
                $role->givePermission($permission);
            }
        }
    }

    /**
     * <!-- TEMPLATE-MARKER: ロール名 → permission 名リストをここに追加する。
     *      例: OrganizationRole::Owner->value => ['billing-manage'] -->
     *
     * @return array<string, list<string>>
     */
    protected function rolePermissions(): array
    {
        return [];
    }
}
