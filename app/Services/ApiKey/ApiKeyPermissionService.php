<?php

declare(strict_types=1);

namespace App\Services\ApiKey;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\User;
use App\Policies\OrganizationPolicy;
use DomainException;
use Illuminate\Support\Facades\DB;
use Webmozart\Assert\Assert;

/**
 * 組織スコープ `manage-api-keys` permission の付与 / 剥奪 / 参照を担う domain service。
 *
 * 認可の既定境界 (Owner / Admin) は {@see OrganizationPolicy::manageApiKeys}
 * がロールで判定する。本 service は **その既定境界の外にいる一般メンバーへ個別付与** する
 * ための flat 付与モデル (専用 Role を作らない) を提供し、Policy 側が
 * {@see self::hasDirectPermission()} を OR で参照する。
 *
 * 一覧描画 (メンバー管理画面等) では {@see self::getDirectMap()} で N+1 を避けて
 * 直接付与状態を一括取得する。
 */
final class ApiKeyPermissionService
{
    /** 組織 API キー管理 permission の name (`config/permission` 相当のアプリ規約: kebab)。 */
    public const PERMISSION_MANAGE_API_KEYS = 'manage-api-keys';

    /**
     * 対象ユーザーに `manage-api-keys` permission を付与する (組織メンバー限定)。
     */
    public function grant(User $target, Organization $organization): void
    {
        $teamId = $this->ensureTeamId($organization);
        $this->ensureMembership($target, $organization);

        $target->givePermission(self::PERMISSION_MANAGE_API_KEYS, $teamId);
        $target->flushCache();
    }

    /**
     * 対象ユーザーから `manage-api-keys` permission を剥奪する (組織メンバー限定)。
     */
    public function revoke(User $target, Organization $organization): void
    {
        $teamId = $this->ensureTeamId($organization);
        $this->ensureMembership($target, $organization);

        $target->removePermission(self::PERMISSION_MANAGE_API_KEYS, $teamId);
        $target->flushCache();
    }

    /**
     * 指定組織での `manage-api-keys` 直接付与の有無。
     *
     * 暗黙許可 (Owner / Admin) は含めず **直接付与のみ** を見る。
     * 組織メンバーでない場合は false (退会後に残存し得る permission の安全側挙動)。
     */
    public function hasDirectPermission(User $user, Organization $organization): bool
    {
        $teamId = $this->ensureTeamId($organization);

        if (! $organization->users()->where('users.id', $user->id)->exists()) {
            return false;
        }

        return $user->isAbleTo(self::PERMISSION_MANAGE_API_KEYS, $teamId);
    }

    /**
     * 指定組織・指定ユーザー群の直接付与状態を 1 クエリで取得する (一覧描画の eager load 用)。
     *
     * 境界条件: `permission_user` を直接引き **membership は検査しない**。退会済ユーザーでも
     * 行が残っていれば true を返すため、呼び出し側は **その組織のメンバー user_id だけを渡す**
     * こと (一覧表示用途に最適化)。非メンバーを渡した場合の挙動は未定義。
     *
     * @param  list<int>  $userIds
     * @return array<int, bool>
     */
    public function getDirectMap(Organization $organization, array $userIds): array
    {
        $teamId = $this->ensureTeamId($organization);

        if ($userIds === []) {
            return [];
        }

        $permissionId = Permission::query()
            ->where('name', self::PERMISSION_MANAGE_API_KEYS)
            ->value('id');

        if ($permissionId === null) {
            return array_fill_keys($userIds, false);
        }

        $grantedRaw = DB::table('permission_user')
            ->where('permission_id', $permissionId)
            ->where('team_id', $teamId)
            ->whereIn('user_id', $userIds)
            ->pluck('user_id')
            ->all();

        $map = array_fill_keys($userIds, false);
        foreach ($grantedRaw as $id) {
            Assert::integerish($id);
            $map[(int) $id] = true;
        }

        return $map;
    }

    private function ensureTeamId(Organization $organization): int
    {
        $teamId = $organization->laratrust_team_id;
        Assert::integer(
            $teamId,
            'Organization must have a laratrust_team_id to manage API key permission.',
        );

        return $teamId;
    }

    private function ensureMembership(User $target, Organization $organization): void
    {
        $isMember = $organization->users()->where('users.id', $target->id)->exists();
        if (! $isMember) {
            throw new DomainException(sprintf(
                'User %d is not a member of organization %d.',
                $target->id,
                $organization->id,
            ));
        }
    }
}
