<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AdminUser;
use App\Models\Item;
use App\Models\ModelAudit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ModelAudit の Factory。
 *
 * auditable はサンプルリソースの Item を既定にする (派生アプリは自ドメインの
 * Auditable モデルに合わせて state で上書きする)。
 *
 * @extends Factory<ModelAudit>
 */
class ModelAuditFactory extends Factory
{
    /** @var class-string<ModelAudit> */
    protected $model = ModelAudit::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_type' => AdminUser::class,
            'user_id' => AdminUser::factory(),
            'event' => 'updated',
            'auditable_type' => Item::class,
            'auditable_id' => Item::factory(),
            'old_values' => ['name' => '変更前の名前'],
            'new_values' => [
                'name' => '変更後の名前',
                '__reason' => 'テスト用の変更理由',
                '__admin_action_id' => '019e2e3a-6d0f-7831-87d8-5f54dc5af800',
            ],
            'url' => 'http://localhost/admin',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'tags' => 'source:admin_panel,action:item.update,admin_action_id:019e2e3a-6d0f-7831-87d8-5f54dc5af800',
        ];
    }
}
