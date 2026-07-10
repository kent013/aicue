<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ProjectRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;

/**
 * プロジェクトの認可。組織所属の確認は親 (Organization) 経由で行う (直 fetch 禁止)。
 *
 * - 閲覧: 組織メンバーなら可 (組織管理者は配下プロジェクトに暗黙アクセス = 継承規則)
 * - 作成: 組織の owner / admin
 * - 更新・削除: 組織の owner / admin、または当該プロジェクトの project_admin
 *
 * viewAny / create は対象 Project が無いため Organization を追加引数に取る
 * (Gate::authorize('create', [Project::class, $organization]))。
 */
class ProjectPolicy
{
    /** 一覧閲覧: 組織メンバーなら可 */
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->organizationRole($organization) !== null;
    }

    /** 閲覧: 所属組織のメンバーなら可 */
    public function view(User $user, Project $project): bool
    {
        $organization = $project->organization;

        return $organization !== null && $user->organizationRole($organization) !== null;
    }

    /** 作成: 組織の owner / admin */
    public function create(User $user, Organization $organization): bool
    {
        return $user->organizationRole($organization)?->canManage() ?? false;
    }

    /** 更新: 組織の owner / admin または project_admin */
    public function update(User $user, Project $project): bool
    {
        return $this->canManageProject($user, $project);
    }

    /** 削除: 組織の owner / admin または project_admin */
    public function delete(User $user, Project $project): bool
    {
        return $this->canManageProject($user, $project);
    }

    /**
     * 撮影 (take の capture/upload/adopt): 管理権限者または project メンバー
     * (doc/10 §10.5 撮影者)。TakePolicy が全 ability を本メソッドへ委譲する。
     */
    public function capture(User $user, Project $project): bool
    {
        if ($this->canManageProject($user, $project)) {
            return true;
        }

        $organization = $project->organization;
        if ($organization === null || $user->organizationRole($organization) === null) {
            return false; // cross-org 不変条件
        }

        return $project->memberRole($user) !== null; // Admin / Member どちらも撮影可
    }

    /**
     * プロジェクト管理権限の判定。
     * 組織ロールは laratrust_team_id 明示 (organizationRole)、
     * プロジェクトロールは project_members pivot (memberRole) で判定する。
     */
    private function canManageProject(User $user, Project $project): bool
    {
        $organization = $project->organization;
        if ($organization === null) {
            return false;
        }

        if ($user->organizationRole($organization)?->canManage() ?? false) {
            return true;
        }

        // 組織メンバーでなければ project ロールがあっても不可 (cross-org 不変条件)
        if ($user->organizationRole($organization) === null) {
            return false;
        }

        return $project->memberRole($user) === ProjectRole::Admin;
    }
}
