<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Item;
use App\Models\Project;
use App\Models\User;

/**
 * Item (Project 配下のサンプルリソース) の認可。
 * 子リソースは親 Policy に委譲する (07 ガイド §2: 親の Policy を経由して org 所属を確認)。
 *
 * - 閲覧: プロジェクトを閲覧できる人
 * - 作成・更新・削除: プロジェクトを操作 (update) できる人
 */
class ItemPolicy
{
    public function __construct(
        private readonly ProjectPolicy $projectPolicy,
    ) {}

    /** 閲覧: プロジェクトを閲覧できる人 */
    public function view(User $user, Item $item): bool
    {
        $project = $item->project;

        return $project !== null && $this->projectPolicy->view($user, $project);
    }

    /** 作成: プロジェクトを操作できる人 (対象 Item が無いため Project を追加引数に取る) */
    public function create(User $user, Project $project): bool
    {
        return $this->projectPolicy->update($user, $project);
    }

    /** 更新: プロジェクトを操作できる人 */
    public function update(User $user, Item $item): bool
    {
        $project = $item->project;

        return $project !== null && $this->projectPolicy->update($user, $project);
    }

    /** 削除: プロジェクトを操作できる人 */
    public function delete(User $user, Item $item): bool
    {
        $project = $item->project;

        return $project !== null && $this->projectPolicy->update($user, $project);
    }
}
