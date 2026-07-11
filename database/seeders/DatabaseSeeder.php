<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // call 順は依存関係のとおり固定: ロール定義 → permission 定義 → 紐付け →
        // プラン → チケット傾斜単価 → local 開発用 AdminUser (AdminUserSeeder 自身が local 以外で skip)。
        // TicketVolumePriceSeeder はスポット購入 (tier 解決) と料金表表示の bootstrap に必要
        // (seeder docblock が定める「傾斜単価を使う派生アプリが DatabaseSeeder へ追加する」正規オプトイン)
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
            PlanSeeder::class,
            TicketVolumePriceSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
