<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Admin;

use App\Enums\MemberRoleState;
use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * ユーザー管理画面 (Admin/Users) のメンバー 1 行分。TS 側 types/admin.ts の MemberRow と対で保守。
 * 表示状態 (roleState) は org ロール × Default Project pivot から毎回導出する (概念設計 D2(a))。
 * email は CipherSweet 復号値。本画面は manageMembers 権限者しか到達できない (403) ため
 * 行レベルの可視性分岐は持たない (PII 可視性は画面到達境界で担保)。
 *
 * lastLoginAt は「最後にいつ入ったか」であり、users の列ではなく security_audit_events の
 * login 行から導出する (App\Services\Security\LastLoginLookup)。**履歴は持たない**。
 * 記録が無い利用者は null で、UI は「記録なし」と表示する — 「一度も入っていない」と
 * 断定しないのは、導出元の保持期間が未確定で将来 purge されうるためである。
 */
final readonly class MemberRowData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $roleState,       // MemberRoleState value
        public string $roleLabel,
        public string $twoFactorStatus, // disabled|pending|enabled
        public bool $isSelf,
        public ?string $lastLoginAt,    // ISO8601 (オフセット付き) / 記録が無ければ null
    ) {}

    /**
     * $lastLoginAt は**既定値を持たない必須引数**である。
     * 既定 null を与えると、将来 fromUser の呼び出し元が増えたときに
     * 「渡し忘れて全員 記録なし」が静かに起きるためである。
     */
    public static function fromUser(
        User $user,
        ?OrganizationRole $orgRole,
        ?ProjectRole $projectRole,
        int $currentUserId,
        ?CarbonImmutable $lastLoginAt,
    ): self {
        $state = MemberRoleState::derive($orgRole, $projectRole);

        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            roleState: $state->value,
            roleLabel: $state->label(),
            twoFactorStatus: $user->twoFactorStatus()->value,
            isSelf: $user->id === $currentUserId,
            // オフセット付きで出す。toDateTimeString() は使わない —
            // 端末側 Intl が UTC を現地時刻として解釈し 9 時間ずれる
            lastLoginAt: $lastLoginAt?->toIso8601String(),
        );
    }
}
