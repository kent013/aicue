<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 組織アクセス失効の理由 (監査 metadata の固定語彙)。
 *
 * **理由は制御フローを変えない**。窓口は理由に関わらず 3 家族を同じように失効させる。
 * 分けているのは「なぜ接続が切れたのか」をサポート時に 1 行で答えるためだけである
 * (とくに OwnershipTransferredTo は「昇格したのに切れた」という驚きの説明に要る)。
 */
enum OrgAccessRevocationReason: string
{
    /** 組織ロールの変更 (昇格・降格の区別はしない) */
    case RoleChanged = 'role_changed';

    /** 組織からの除名 */
    case MemberRemoved = 'member_removed';

    /** オーナー移譲の譲り手 (Owner → Admin) */
    case OwnershipTransferredFrom = 'ownership_transferred_from';

    /** オーナー移譲の受け手 (→ Owner)。**昇格でも切る**という設計判断の可視化 */
    case OwnershipTransferredTo = 'ownership_transferred_to';

    public function label(): string
    {
        return match ($this) {
            self::RoleChanged => '組織ロールの変更',
            self::MemberRemoved => '組織からの除名',
            self::OwnershipTransferredFrom => 'オーナー移譲 (譲り手)',
            self::OwnershipTransferredTo => 'オーナー移譲 (受け手)',
        };
    }
}
