<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 退会をブロックされたユーザーが取るべき「次の一手」。
 *
 * **表示時点のヒントであり権威ではない** (削除時にサーバがロック下で再評価する)。
 * 値集合は resources/js/types/account.ts の TS union と同期する
 * (tests/js/architecture/enum-ts-sync.test.ts の目録が固定)。
 */
enum AccountDeletionBlockerAction: string
{
    /** 別メンバーへオーナーを移譲する (/organizations/{slug}/settings) */
    case TransferOwnership = 'transfer_ownership';

    /**
     * サブスクリプションを解約する (/organizations/{slug}/billing)。
     *
     * ★組織文脈は URL だけで決まる (家系裁定 AG-037) ので、blocker がどの組織であっても
     *   その組織の課金画面へ**直接**行ける。切替を挟む一手 (旧 SwitchOrganizationThenOpenBilling) は
     *   保持列と切替 endpoint の撤去に伴い**概念ごと消えた**。
     */
    case OpenBilling = 'open_billing';
}
