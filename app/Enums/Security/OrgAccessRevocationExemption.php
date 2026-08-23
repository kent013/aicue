<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 役割を書き込むのに組織アクセスの失効を呼ばない経路の免除目録 (既定拒否)。
 *
 * 免除できるのは「その操作の時点で、その人のその組織における資格情報が
 * まだ存在し得ない」場合だけである。降格・除名・移譲は免除できない。
 */
enum OrgAccessRevocationExemption: string
{
    case JoinOrganization = 'OrganizationMembershipService::joinOrganization';

    case AttachJustInTimeMember = 'OrganizationMembershipService::attachJustInTimeMember';

    public function rationale(): string
    {
        return match ($this) {
            self::JoinOrganization => '招待受諾は組織に入れる操作であり、その時点でその人が'
                .'その組織で持つ資格情報は 1 件も存在し得ない (発行には所属が前提のため)。'
                .'したがって失効の対象が構造的に空である。',
            self::AttachJustInTimeMember => '企業 SSO の初回ログインで**直前に作られた利用者**を'
                .'組織へ入れる操作である。利用者そのものが同じトランザクションで生まれたばかりなので、'
                .'その人がその組織で持つ資格情報は 1 件も存在し得ない。'
                .'したがって失効の対象が構造的に空である (joinOrganization と同じ理由)。',
        };
    }
}
