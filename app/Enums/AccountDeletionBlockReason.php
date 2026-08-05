<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 退会 (アカウント削除) がブロックされる理由 (**サーバ内部の語彙**)。
 *
 * 画面へは載せない (wire に載せるのは AccountDeletionBlockerAction = 次の一手)。
 * ValidationException の文言生成と action 導出にだけ使う。
 */
enum AccountDeletionBlockReason: string
{
    /** 唯一 Owner のまま退会すると、他のメンバーが Owner 不在の組織に取り残される */
    case OwnerlessMembers = 'ownerless_members';

    /** 唯一 Owner のまま退会すると、生きた課金責務が引受先不在で残る */
    case ActiveBilling = 'active_billing';
}
