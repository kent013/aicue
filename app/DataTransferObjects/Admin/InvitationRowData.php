<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Admin;

use App\Enums\OrganizationRole;
use App\Models\OrganizationInvitation;
use Illuminate\Support\Carbon;
use Webmozart\Assert\Assert;

/**
 * ユーザー管理画面 (Admin/Users) の招待中 1 行分。TS 側 types/admin.ts の InvitationRow と対で保守。
 * 招待は org ロールだけを持つ (役割付き招待は裁定 AG-079 で撤去)。
 * MemberRoleState (受諾後の 5 値表示状態) は使わない — 招待中の行を「未割当」と表示するのは
 * 意味的に誤り (割当漏れではなく、まだ参加していないだけ)。
 */
final readonly class InvitationRowData
{
    public function __construct(
        public int $id,
        public string $email,
        public string $role,      // OrganizationRole value
        public string $roleLabel, // OrganizationRole label
        public string $expiresAt, // Y-m-d
    ) {}

    public static function fromInvitation(OrganizationInvitation $invitation): self
    {
        $role = OrganizationRole::from($invitation->role);

        $expiresAt = $invitation->getAttribute('expires_at');
        Assert::isInstanceOf($expiresAt, Carbon::class);

        return new self(
            id: $invitation->id,
            email: $invitation->email,
            role: $role->value,
            roleLabel: $role->label(),
            expiresAt: $expiresAt->toDateString(),
        );
    }
}
