<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * P8a: オートリチャージ attempt の状態機械。
 *
 * pending → paid   : invoice.paid webhook / リコンサイルの冪等付与後
 * pending → failed : invoice の終端 (void/delete) 成功後のみ (課金不能の確定。failure_count +1)
 * pending → canceled: invoice の終端成功後のみ (決済手段の問題ではない破棄。failure_count 増分なし)
 *
 * failed / canceled は「invoice が課金され得ない」ことが保証された終端。open invoice を
 * 残したまま終端しない (遅延支払いによる二重課金・二重付与の構造的排除)。
 */
enum AutoRechargeAttemptStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Canceled = 'canceled';
}
