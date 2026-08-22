<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use App\Models\Organization;
use App\Models\Project;

/**
 * URL 由来の組織に対する整合 guard の helper (家系裁定 AG-037)。
 *
 * ★組織そのものは **route binding が確定する** (`{organization:slug}` /
 *   `MembershipScopedOrganizationBinder`)。したがって「組織を解決する」メソッドは
 *   本 trait に**存在しない** — 保持列 (`users.current_organization_id`) と切替 endpoint は
 *   撤去済みで、2 方式の併存は認めない。
 * ★残っているのは「URL 上の {project} が URL 上の組織に属するか」を確かめる 1 本だけである。
 */
trait ResolvesRouteOrganization
{
    /**
     * URL 整合 guard (D2 不変条件): URL 上の {project} が URL 上の組織に属さなければ
     * **認可より前に 404** (403 で存在を漏らさない / cross-org は 404)。
     * 所属確認は relation (Organization::projects = CustomTeam 経由) のみで行う (直 fetch 禁止)。
     *
     * web の {project} route では EnsureProjectBelongsToRouteOrganization middleware
     * (project.in-route-org) が本 guard を FormRequest の DB ルールより**前**にも実行する
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
