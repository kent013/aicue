<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * 請求通知の種別。冪等は DB の複合 UNIQUE で担保する (連結キー生成ロジックは持たない):
 *  - 支払い通知: (type, invoice_id)
 *  - 予告 (reminder): (type, dedup_key)
 *
 * テンプレートは支払い失敗 + 更新予告のみ。派生アプリは case を追加して拡張する
 * (payment_succeeded / trial_ending_reminder / charge_disputed 等)。
 */
enum BillingNotificationType: string
{
    case PaymentFailed = 'payment_failed';
    case RenewalReminder = 'renewal_reminder';
}
