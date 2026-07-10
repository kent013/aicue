<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\Take;
use App\Models\User;

/**
 * Take (撮影素材) の認可。全 ability を親 (ProjectPolicy::capture) へ委譲する (直 fetch 禁止)。
 * 撮影者 (project_member) は upload/登録/並べ替え/コメント/削除/adopt/DL ACK が可能
 * (adopt を撮影者に含めるのは doc/10 §10.5 の確定仕様。概念設計 D10)。
 */
class TakePolicy
{
    public function __construct(
        private readonly ProjectPolicy $projectPolicy,
    ) {}

    /** 作成 (upload-url / POST takes): 対象 Take が無いため Project を追加引数に取る */
    public function create(User $user, Project $project): bool
    {
        return $this->projectPolicy->capture($user, $project);
    }

    public function update(User $user, Take $take): bool
    {
        return $this->captureVia($user, $take);
    }

    public function delete(User $user, Take $take): bool
    {
        return $this->captureVia($user, $take);
    }

    public function adopt(User $user, Take $take): bool
    {
        return $this->captureVia($user, $take);
    }

    public function markDownloaded(User $user, Take $take): bool
    {
        return $this->captureVia($user, $take);
    }

    private function captureVia(User $user, Take $take): bool
    {
        $project = $take->cut?->videoManual?->project;

        return $project !== null && $this->projectPolicy->capture($user, $project);
    }
}
