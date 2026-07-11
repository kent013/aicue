<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Admin;

use App\Enums\MemberRoleState;
use App\Enums\OrganizationRole;
use App\Models\OrganizationInvitation;
use Illuminate\Support\Carbon;
use Webmozart\Assert\Assert;

/**
 * ユーザー管理画面 (Admin/Users) の招待中 1 行分。TS 側 types/admin.ts の InvitationRow と対で保守。
 * 表示状態は招待の org role + project_role から導出する (受諾後のメンバー行と同じ 5 値語彙)。
 */
final readonly class InvitationRowData
{
    public function __construct(
        public int $id,
        public string $email,
        public string $roleState, // MemberRoleState value
        public string $roleLabel,
        public string $expiresAt, // Y-m-d
    ) {}

    public static function fromInvitation(OrganizationInvitation $invitation): self
    {
        $state = MemberRoleState::derive(
            OrganizationRole::from($invitation->role),
            $invitation->project_role,
        );

        $expiresAt = $invitation->getAttribute('expires_at');
        Assert::isInstanceOf($expiresAt, Carbon::class);

        return new self(
            id: $invitation->id,
            email: $invitation->email,
            roleState: $state->value,
            roleLabel: $state->label(),
            expiresAt: $expiresAt->toDateString(),
        );
    }
}
