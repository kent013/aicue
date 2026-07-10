<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * OAuth セッション (CLI ログイン) の認可。
 *
 * 責務分界:
 *  - クロステナント存在秘匿 (非メンバー / 他組織 = 404) は routing 層
 *    (MembershipScopedOrganizationBinder + scopeBindings) の責務で、Policy には来ない。
 *  - same-org の権限不足 (一般メンバー = 403) が本 Policy の責務。
 *
 * 組織管理経路は `manageForOrganization` 単一 ability。境界は API キー管理
 * ({@see OrganizationPolicy::manageApiKeys}) と同一 (owner / admin) に揃える
 * (「組織のマシン credential を管理できる人」を 1 つの権限概念に保つ)。
 */
class OauthSessionPolicy
{
    /**
     * 組織のセッション一覧 / 失効 (組織管理経路)。API キー管理と同一の権限境界を保つため
     * ({@see OrganizationPolicy::manageApiKeys}) に委譲する (owner / admin または直接付与メンバー)。
     */
    public function manageForOrganization(User $user, Organization $organization): bool
    {
        return Gate::forUser($user)->allows('manageApiKeys', $organization);
    }
}
