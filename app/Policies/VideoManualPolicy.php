<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Models\VideoManual;

/**
 * VideoManual (Project 配下の動画マニュアル) の認可。
 * 子リソースは親 Policy に委譲する (直 fetch 禁止)。
 *
 * 権限表 (doc/10 §10.5): 編集者 (project_admin / org 管理者) = write 全可、
 * 撮影者 (project_member) = show / 一覧のみ。write 判定は ProjectPolicy::update が担う。
 */
class VideoManualPolicy
{
    public function __construct(
        private readonly ProjectPolicy $projectPolicy,
    ) {}

    /** 閲覧: プロジェクトを閲覧できる人 (撮影者も可) */
    public function view(User $user, VideoManual $manual): bool
    {
        $project = $manual->project;

        return $project !== null && $this->projectPolicy->view($user, $project);
    }

    /** 作成: プロジェクトを操作できる人 (対象 VideoManual が無いため Project を追加引数に取る) */
    public function create(User $user, Project $project): bool
    {
        return $this->projectPolicy->update($user, $project);
    }

    /** 更新 (メタデータ): プロジェクトを操作できる人 */
    public function update(User $user, VideoManual $manual): bool
    {
        $project = $manual->project;

        return $project !== null && $this->projectPolicy->update($user, $project);
    }

    /** 削除: プロジェクトを操作できる人 */
    public function delete(User $user, VideoManual $manual): bool
    {
        $project = $manual->project;

        return $project !== null && $this->projectPolicy->update($user, $project);
    }

    /** 複製 (別名保存): 元を閲覧でき、かつ同一プロジェクトに作成できる人 = プロジェクト編集者のみ。撮影者は不可 */
    public function duplicate(User $user, VideoManual $manual): bool
    {
        $project = $manual->project;

        return $project !== null && $this->projectPolicy->update($user, $project);
    }

    /** AI 解析の実行: プロジェクトを操作できる人 (編集者)。撮影者は不可 */
    public function analyze(User $user, VideoManual $manual): bool
    {
        $project = $manual->project;

        return $project !== null && $this->projectPolicy->update($user, $project);
    }

    /** レンダ/プレビューの実行: プロジェクトを操作できる人 (編集者)。撮影者は不可 (§10.5) */
    public function render(User $user, VideoManual $manual): bool
    {
        $project = $manual->project;

        return $project !== null && $this->projectPolicy->update($user, $project);
    }

    /** 完成動画のダウンロード: 編集者のみ (§10.5。ポーリングは view = 撮影者も可) */
    public function download(User $user, VideoManual $manual): bool
    {
        $project = $manual->project;

        return $project !== null && $this->projectPolicy->update($user, $project);
    }
}
