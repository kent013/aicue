<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Organizations;

use App\Models\Organization;
use App\Models\User;

/**
 * 画面へ渡す組織文脈。**URL の binding からのみ導出する** (家系裁定 AG-037)。
 *
 * ★組織 route 以外では必ず null になる (「所属している組織のどれか」を裏口から選ばない)。
 *   保持列 (`users.current_organization_id`) は撤去済みで、概念ごと存在しない。
 * ★配列化は Inertia へ渡す最終の 1 か所 (`toArray()`) だけで行う。
 *   `resources/js/lib/shared-props.ts` の `CurrentOrganization` 型との一致は
 *   `CurrentOrganizationSharedPropShapeTest` が**キーと各値の型**まで固定する。
 */
final readonly class CurrentOrganizationData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public ?string $role,
        public bool $canManageMembers,
        public bool $canManageApiKeys,
    ) {}

    /**
     * 権限は `$organization` を対象に評価し、OrganizationPolicy を唯一の真実源とする
     * (role 直見しない)。Policy は `organizationRole($organization)` =
     * laratrust_team_id を明示した strict_check 判定を経由するため、別組織で付与された
     * 権限は現在組織へ漏れない。
     */
    public static function forMember(User $user, Organization $organization): self
    {
        return new self(
            id: $organization->id,
            name: $organization->name,
            slug: $organization->slug,
            role: $user->organizationRole($organization)?->value,
            // ナビ表示用の最小権限 (settings/billing は view=メンバー全員のためフラグ不要)。
            // billing 画面内の操作出し分けは既存 canManageBilling prop が担う。
            canManageMembers: $user->can('manageMembers', $organization),
            canManageApiKeys: $user->can('manageApiKeys', $organization),
        );
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     slug: string,
     *     role: string|null,
     *     canManageMembers: bool,
     *     canManageApiKeys: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'role' => $this->role,
            'canManageMembers' => $this->canManageMembers,
            'canManageApiKeys' => $this->canManageApiKeys,
        ];
    }
}
