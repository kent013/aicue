<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\OrganizationOidcConnection;
use App\Models\User;

/**
 * 組織 OIDC 接続の認可。
 *
 * ★境界は `OrganizationPolicy::update` と**同じ** (owner / admin) である。
 *   接続の管理は**組織のログイン経路そのものを変える操作**なので、
 *   **閲覧も含めて** owner / admin に限る (一覧だけ緩めない — issuer と client_id が見える)。
 *
 * ★テナント境界 (層 2 = 404) は route の scoped binding が**認可より前**に閉じている。
 *   本 policy は層 3 (403) だけを担う。
 */
class OrganizationOidcConnectionPolicy
{
    /** 一覧の閲覧。 */
    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->canManage($user, $organization);
    }

    /** 新しい接続の登録。 */
    public function create(User $user, Organization $organization): bool
    {
        return $this->canManage($user, $organization);
    }

    /** 更新・確認・有効化・無効化 (状態と認証材料を変える操作)。 */
    public function update(User $user, OrganizationOidcConnection $connection): bool
    {
        $organization = $connection->organization;

        return $organization !== null && $this->canManage($user, $organization);
    }

    /** 物理削除 (身元が 0 件のときだけ D1 が実際に許す)。 */
    public function delete(User $user, OrganizationOidcConnection $connection): bool
    {
        $organization = $connection->organization;

        return $organization !== null && $this->canManage($user, $organization);
    }

    private function canManage(User $user, Organization $organization): bool
    {
        return $user->organizationRole($organization)?->canManage() ?? false;
    }
}
