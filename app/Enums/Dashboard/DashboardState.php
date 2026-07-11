<?php

declare(strict_types=1);

namespace App\Enums\Dashboard;

/**
 * ダッシュボードの表示状態 (TS 側 DashboardState literal union と対)。
 * ログイン直後の着地点はどの状態でも 404 / redirect ループにしない (概念設計 状態分岐)。
 */
enum DashboardState: string
{
    case NoOrganization = 'no_organization';
    case NoProject = 'no_project';
    case Ready = 'ready';
}
