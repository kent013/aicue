<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Webmozart\Assert\Assert;

/**
 * current organization 解決 + URL 整合 guard の helper 集。
 * 「/projects/...」「/billing」等、URL に org セグメントを含めない current org スコープの
 * ルートで使う。ユーザーの current_organization_id を解決し、未設定なら 404
 * (存在しないリソースとして扱い、組織の有無を露出しない)。
 *
 * 組織管理系ルート (/organizations/{organization:slug}/...) は current に依存せず、
 * MembershipScopedOrganizationBinder の route binding で org を解決する (本 trait 不使用)。
 */
trait ResolvesCurrentOrganization
{
    private function resolveCurrentOrganization(Request $request): Organization
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $organization = $user->currentOrganization;
        abort_if($organization === null, 404);

        return $organization;
    }

    /**
     * URL 整合 guard (D2 不変条件): URL 上の {project} が current org に属さなければ
     * **認可より前に 404** (403 で存在を漏らさない / cross-org は 404)。
     * 所属確認は relation (Organization::projects = CustomTeam 経由) のみで行う (直 fetch 禁止)。
     */
    private function resolveOrganizationProject(Organization $organization, Project $project): Project
    {
        abort_unless(
            $organization->projects()->whereKey($project->getKey())->exists(),
            404,
        );

        return $project;
    }
}
