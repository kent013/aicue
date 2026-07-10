<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\Enums\QuotaKey;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Billing\QuotaService;
use Illuminate\Support\Facades\DB;

/**
 * プロジェクト生成の唯一の窓口。
 * Default Team パターン (docs/default-team-pattern.md): teams_visible=false の既定では
 * Team 選択 UI を出さず、Service が組織の Default Team を自動割当する。
 * custom_team_id は所有権キーのためクライアントから受け取らない (ここで明示代入)。
 */
class ProjectService
{
    public function __construct(
        private readonly QuotaService $quota,
    ) {}

    /**
     * 組織配下にプロジェクトを生成する (Default Team 割当を内包)。
     * 作成前に Quota (max_projects) をチェックし、超過は QuotaExceededException
     * (web では back + error flash に変換される)。
     */
    public function createProject(Organization $organization, string $name, ?string $description): Project
    {
        return DB::transaction(function () use ($organization, $name, $description): Project {
            // Quota チェックは QuotaService 経由のみ (docs 07 ガイド §4)
            $this->quota->check($organization, QuotaKey::MaxProjects, $organization->projects()->count());

            $project = new Project([
                'name' => $name,
                'description' => $description,
            ]);
            // 所有権キーは relation 経由で明示代入 (mass assignment しない)
            $project->customTeam()->associate($organization->defaultTeam());
            $project->save();

            return $project;
        });
    }
}
