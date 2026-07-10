<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // call 順は依存関係のとおり固定: ロール定義 → permission 定義 → 紐付け →
        // プラン → local 開発用 AdminUser (AdminUserSeeder 自身が local 以外で skip)
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
            PlanSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
