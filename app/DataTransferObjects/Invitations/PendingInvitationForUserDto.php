<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Invitations;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use Illuminate\Support\Carbon;
use Webmozart\Assert\Assert;

/**
 * **受信者視点**の保留中招待 1 件 (アプリ内受諾 UI 用)。
 *
 * 管理者視点の InvitationRowData とは契約を分離したままにする (裁定 AG-113):
 * 管理者は「誰を招待したか (email)」を見る面、受信者は「どこへ参加できるか」を見る面であり、
 * 開示すべき項目が違う。似ているからと統合しない (思考原則 4)。
 *
 * **開示するのはこの 4 つだけ**。email (自分の値だが載せる必要がない) / token_hash /
 * accepted_at・revoked_at・expires_at の生値 / invited_by_user_id / organization_id は出さない。
 * 受諾 URL も持たせない (署名も token も無い経路のため、サーバが URL を配る意味が無く
 * 開示面だけ増える。front が route から組む)。
 *
 * TS 側 resources/js/types/invitation.ts の PendingInvitation と対で保守する。
 */
final readonly class PendingInvitationForUserDto
{
    public function __construct(
        public int $id,
        public string $organizationName,
        public string $roleLabel,
        public string $expiresAt, // Y-m-d
    ) {}

    /**
     * scopeActivePendingForEmail で解決済みの招待から組み立てる。
     *
     * 呼び出し側で `->format()` を書かない (日時の文字列化責務をここへ集約する)。
     * organization は scope の whereHas で存在が保証されているが、
     * relation の遅延解決が null を返す可能性を型で潰すため Assert で narrow する。
     */
    public static function fromInvitation(OrganizationInvitation $invitation): self
    {
        $organization = $invitation->organization;
        Assert::isInstanceOf($organization, Organization::class);

        $expiresAt = $invitation->getAttribute('expires_at');
        Assert::isInstanceOf($expiresAt, Carbon::class);

        return new self(
            id: $invitation->id,
            organizationName: $organization->name,
            roleLabel: OrganizationRole::from($invitation->role)->label(),
            expiresAt: $expiresAt->toDateString(),
        );
    }

    /**
     * @return array{id: int, organizationName: string, roleLabel: string, expiresAt: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organizationName' => $this->organizationName,
            'roleLabel' => $this->roleLabel,
            'expiresAt' => $this->expiresAt,
        ];
    }
}
