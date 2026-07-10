<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Category;
use App\Models\Project;
use App\Models\User;

/**
 * Category (Project 配下の動画マニュアル分類) の認可。
 * 子リソースは親 Policy に委譲する (直 fetch 禁止)。
 *
 * 権限表 (doc/10 §10.5): 編集者 (project_admin / org 管理者) = write 全可、
 * 撮影者 (project_member) = 閲覧のみ。write 判定は ProjectPolicy::update が担う。
 */
class CategoryPolicy
{
    public function __construct(
        private readonly ProjectPolicy $projectPolicy,
    ) {}

    /** 閲覧: プロジェクトを閲覧できる人 */
    public function view(User $user, Category $category): bool
    {
        $project = $category->project;

        return $project !== null && $this->projectPolicy->view($user, $project);
    }

    /** 作成: プロジェクトを操作できる人 (対象 Category が無いため Project を追加引数に取る) */
    public function create(User $user, Project $project): bool
    {
        return $this->projectPolicy->update($user, $project);
    }

    /** 更新: プロジェクトを操作できる人 */
    public function update(User $user, Category $category): bool
    {
        $project = $category->project;

        return $project !== null && $this->projectPolicy->update($user, $project);
    }

    /** 削除: プロジェクトを操作できる人 */
    public function delete(User $user, Category $category): bool
    {
        $project = $category->project;

        return $project !== null && $this->projectPolicy->update($user, $project);
    }

    /**
     * 並べ替え: プロジェクトを操作できる人。
     * 対象 Category が単一でないため Project を追加引数に取る専用シグネチャ
     * (Gate::authorize('reorder', [Category::class, $project]))。
     */
    public function reorder(User $user, Project $project): bool
    {
        return $this->projectPolicy->update($user, $project);
    }
}
