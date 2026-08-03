<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * P8a: オートリチャージが無効化された理由。
 * PaymentFailures = 連続課金失敗による自動停止 (再有効化は UI から)。
 * User = ユーザーによる明示的な停止。
 */
enum AutoRechargeDisabledReason: string
{
    case PaymentFailures = 'payment_failures';
    case User = 'user';
}
