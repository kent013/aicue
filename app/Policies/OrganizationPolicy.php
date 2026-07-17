<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\ApiKey\ApiKeyPermissionService;
use App\Services\Billing\BillingPermissionService;

/**
 * 組織の認可。判定は User::organizationRole() (laratrust_team_id 明示 =
 * strict_check=true 前提) に集約し、ここでは ability → ロールの対応だけを持つ。
 */
class OrganizationPolicy
{
    /** 閲覧: メンバーなら可 */
    public function view(User $user, Organization $organization): bool
    {
        return $user->organizationRole($organization) !== null;
    }

    /** 設定変更 (name 等): owner / admin */
    public function update(User $user, Organization $organization): bool
    {
        return $user->organizationRole($organization)?->canManage() ?? false;
    }

    /** メンバー管理 (招待 / ロール変更 / 削除): owner / admin */
    public function manageMembers(User $user, Organization $organization): bool
    {
        return $user->organizationRole($organization)?->canManage() ?? false;
    }

    /**
     * 課金管理 (プラン変更 / Customer Portal): owner / admin を既定境界とし、加えて
     * `manage-billing` を直接付与された一般メンバーにも許可する
     * ({@see BillingPermissionService})。非メンバー (role null) は直接付与が残存しても不可。
     */
    public function manageBilling(User $user, Organization $organization): bool
    {
        $role = $user->organizationRole($organization);

        if ($role === null) {
            return false;
        }

        if ($role->canManage()) {
            return true;
        }

        return app(BillingPermissionService::class)->hasDirectPermission($user, $organization);
    }

    /**
     * API キー管理 (発行 / 失効): owner / admin を既定境界とし、加えて
     * `manage-api-keys` を直接付与された一般メンバーにも許可する
     * ({@see ApiKeyPermissionService})。非メンバー (role null) は直接付与が残存しても不可。
     */
    public function manageApiKeys(User $user, Organization $organization): bool
    {
        $role = $user->organizationRole($organization);

        if ($role === null) {
            return false;
        }

        if ($role->canManage()) {
            return true;
        }

        return app(ApiKeyPermissionService::class)->hasDirectPermission($user, $organization);
    }

    /** オーナー移譲: owner のみ */
    public function transferOwnership(User $user, Organization $organization): bool
    {
        return $user->organizationRole($organization) === OrganizationRole::Owner;
    }

    /** 組織削除: owner のみ */
    public function delete(User $user, Organization $organization): bool
    {
        return $user->organizationRole($organization) === OrganizationRole::Owner;
    }
}
