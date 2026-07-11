<?php

declare(strict_types=1);

namespace App\Enums\Dashboard;

/**
 * ダッシュボード表示ロール (概念設計「ロール差」)。判定はサーバ側で
 * ProjectPolicy へ委譲した結果の写像 (フロントは表示分岐のみ、権限判定を持たない)。
 * TS 側 types/dashboard.ts の DashboardRole literal union と対で保守する。
 */
enum DashboardRole: string
{
    case Editor = 'editor';   // ProjectPolicy::update 可 (org owner/admin または project_admin)
    case Shooter = 'shooter'; // update 不可 + ProjectPolicy::capture 可 (project_member)
    case Viewer = 'viewer';   // どちらも不可の組織メンバー
}
