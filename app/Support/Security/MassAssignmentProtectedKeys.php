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
            'created_by', // AI-CUE ドメイン (video_manuals) の actor キー (doc/10 §10.1 準拠の命名)
            'triggered_by', // AI-CUE: analysis_jobs / render_jobs のジョブ実行者 (通知宛先導出。Auth 導出のみ)
            'invited_by_user_id',
            'personal_declared_by_user_id', // organizations の free plan 自己申告 actor (PersonalPlanService が Auth から導出)
            // tenant / ownership (route・コンテキストから導出する)
            'organization_id',
            'custom_team_id',
            'laratrust_team_id',
            'current_organization_id',
            'project_id',
            'api_key_id',
            // AI-CUE ドメイン (Project ─< Category / VideoManual ─< SourceDocument / Cut ─< Take)
            'category_id',
            'video_manual_id',
            'source_document_id',
            'cut_id',
            'parent_cut_id',
            'adopted_take_id',
            // billing (Service / Seeder がサーバ側で導出する)
            'plan_id',
            'initiated_by_user_id', // ticket_checkout_sessions の actor キー (T007)
            'reservation_id',
            'ticket_reservation_id', // AI-CUE: analysis_jobs の予約冪等キー (doc/10 §10.1)
            // secret (サーバ生成値)
            'token_hash',
            'key_hash',
        ];
    }
}
