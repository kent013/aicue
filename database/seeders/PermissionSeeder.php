<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Services\ApiKey\ApiKeyPermissionService;
use Illuminate\Database\Seeder;

/**
 * permission 定義のシーダー (再実行安全)。
 *
 * テンプレート標準の認可はロールベース (Policy + OrganizationRole) のため
 * 初期 permission は空。機能フェーズで permission が必要になったら
 * ここに追加し、RolePermissionSeeder でロールへ紐付ける。
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->permissions() as $permission) {
            Permission::query()->updateOrCreate(
                ['name' => $permission['name']],
                ['display_name' => $permission['display_name']],
            );
        }
    }

    /**
     * <!-- TEMPLATE-MARKER: アプリ固有の permission をここに追加する。
     *      例: ['name' => 'billing-manage', 'display_name' => '請求・プラン管理'] -->
     *
     * `manage-api-keys` は Owner/Admin の既定境界の外にいる一般メンバーへ
     * 個別付与するための permission ({@see ApiKeyPermissionService})。専用 Role には
     * 紐付けない (flat 付与モデル) ため RolePermissionSeeder には登録しない。
     *
     * @return list<array{name: string, display_name: string}>
     */
    protected function permissions(): array
    {
        return [
            ['name' => ApiKeyPermissionService::PERMISSION_MANAGE_API_KEYS, 'display_name' => 'API キー管理'],
        ];
    }
}
