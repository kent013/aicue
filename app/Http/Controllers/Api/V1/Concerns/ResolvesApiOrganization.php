<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Item;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\Request;
use Webmozart\Assert\Assert;

/**
 * REST API v1 の組織コンテキスト解決 + URL 整合 guard の helper 集。
 *
 * `organization` attribute は API キー経路では ApiKeyGuard が、OAuth user-token 経路では
 * ResolveApiActor middleware が注入する。attribute が無いのは配線ミス (route に
 * auth guard / resolve.api-actor が無い) であり、Assert で fail-fast させる。
 * actor 自体が必要な場合は ReadsApiActor (api_actor attribute) を使う。
 */
trait ResolvesApiOrganization
{
    private function resolveOrganization(Request $request): Organization
    {
        $organization = $request->attributes->get('organization');
        Assert::isInstanceOf(
            $organization,
            Organization::class,
            'Organization attribute missing. Ensure the auth guard / resolve.api-actor middleware runs first.',
        );

        return $organization;
    }

    /**
     * URL 整合 guard (D2 不変条件): URL 上の {project} が API キーの組織に属さなければ
     * **認可より前に 404** (cross-org は 404。403 で存在を漏らさない)。
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

    /**
     * URL 整合 guard (D2 不変条件): URL 上の {item} が {project} に属さなければ 404。
     */
    private function resolveProjectItem(Project $project, Item $item): Item
    {
        abort_unless(
            $project->items()->whereKey($item->getKey())->exists(),
            404,
        );

        return $item;
    }
}
