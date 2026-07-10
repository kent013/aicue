<?php

declare(strict_types=1);

namespace App\Support\Security;

/**
 * mass-assignment 保護キーの single source of truth。
 *
 * 不変条件: 「サーバが Auth / route / 生成で導出する ownership / actor / tenant キーを
 * クライアント payload から信頼しない」(devnotes/20260611-template-extraction 04 Q3)。
 *
 * - 入口防御: ProhibitsProtectedKeys trait が FormRequest で `missing` rule を張る
 * - 出口防御: tests/Architecture/MassAssignmentSafetyTest が $fillable 不含を検証する
 *
 * 新しいテナント/所有権 FK (例: site_id 相当) を追加したら本リストに追記すること。
 */
final class MassAssignmentProtectedKeys
{
    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            // actor (操作主体は Auth から導出する)
            'user_id',
            'created_by_user_id',
            'invited_by_user_id',
            // tenant / ownership (route・コンテキストから導出する)
            'organization_id',
            'custom_team_id',
            'laratrust_team_id',
            'current_organization_id',
            'project_id',
            'api_key_id',
            // billing (Service / Seeder がサーバ側で導出する)
            'plan_id',
            'reservation_id',
            // secret (サーバ生成値)
            'token_hash',
            'key_hash',
        ];
    }
}
