<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 退会をブロックされたユーザーが取るべき「次の一手」。
 *
 * **表示時点のヒントであり権威ではない** (削除時にサーバがロック下で再評価する)。
 * 値集合は resources/js/types/account.ts の TS union と同期する
 * (AccountDeletionBlockerActionTsSyncInvariantTest が固定)。
 */
enum AccountDeletionBlockerAction: string
{
    /** 別メンバーへオーナーを移譲する (/organizations/{slug}/settings) */
    case TransferOwnership = 'transfer_ownership';

    /** サブスクリプションを解約する (/billing。blocker が現在組織のとき) */
    case OpenBilling = 'open_billing';

    /** 組織を切り替えてから請求設定を開く (blocker が現在組織でないとき) */
    case SwitchOrganizationThenOpenBilling = 'switch_organization_then_open_billing';
}
