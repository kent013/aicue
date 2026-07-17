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
     * current org 解決 + 在籍 guard。current org が未設定なら 404、解決できても
     * **ユーザーがその org に非所属なら 404** (`current_organization_id` が退会後も
     * 残存する不整合を、**認可より前に** 存在しないリソースとして落とす = 不変条件 #2。
     * 403 で org の存在を漏らさない)。
     *
     * 組織 route (`/organizations/{organization:slug}/...`) では
     * MembershipScopedOrganizationBinder の route binding がこの層を担う。本メソッドは
     * その責務を current-org スコープ (URL に org セグメントを持たない route) へ写した受け皿。
     */
    private function resolveMemberCurrentOrganization(Request $request): Organization
    {
        $organization = $this->resolveCurrentOrganization($request);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        abort_unless(
            $organization->users()->whereKey($user->getKey())->exists(),
            404,
        );

        return $organization;
    }

    /**
     * URL 整合 guard (D2 不変条件): URL 上の {project} が current org に属さなければ
     * **認可より前に 404** (403 で存在を漏らさない / cross-org は 404)。
     * 所属確認は relation (Organization::projects = CustomTeam 経由) のみで行う (直 fetch 禁止)。
     *
     * web の {project} route では EnsureProjectBelongsToCurrentOrganization middleware
     * (project.in-current-org) が本 guard を FormRequest の DB ルールより**前**にも実行する
     * (422/404 差分の存在オラクル防止)。controller 内の呼び出しは二重防御として維持する。
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
