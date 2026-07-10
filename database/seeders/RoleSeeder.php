<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\OrganizationRole;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * 組織ロールのシーダー (再実行安全)。
 * updateOrCreate のため、enum の label() 変更やドリフトした display_name は
 * 再実行で修復される。
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (OrganizationRole::cases() as $role) {
            Role::query()->updateOrCreate(
                ['name' => $role->value],
                ['display_name' => $role->label()],
            );
        }
    }
}
