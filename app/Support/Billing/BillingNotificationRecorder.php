<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Enums\Billing\BillingNotificationStatus;
use App\Enums\Billing\BillingNotificationType;
use App\Models\Billing\BillingNotification;
use Illuminate\Support\Str;
use Throwable;

/**
 * billing_notifications delivery record の状態遷移を 1 か所に集約する。
 *
 * 自然キーは 2 経路: 支払い通知 = `invoice_id`、予告 = `dedup_key`。
 * 内部は private markSentBy / markFailedReasonBy に集約し、両経路の public メソッドが委譲する。
 *
 * 状態遷移ルール (sent 優先):
 *  - markSent*: sent に上書きし過去の failed 痕跡 (failed_at / error) を clear。
 *  - markFailed* / markFailedReason*: status !== Sent のときのみ failed (sent を後退させない)。
 *  - 該当行が無ければ no-op。
 */
final class BillingNotificationRecorder
{
    // --- 支払い通知経路 (invoice_id) ---

    public static function markSent(BillingNotificationType $type, string $invoiceId): void
    {
        self::markSentBy($type, 'invoice_id', $invoiceId);
    }

    public static function markFailed(BillingNotificationType $type, string $invoiceId, Throwable $e): void
    {
        self::markFailedReason($type, $invoiceId, self::formatError($e));
    }

    public static function markFailedReason(BillingNotificationType $type, string $invoiceId, string $reason): void
    {
        self::markFailedReasonBy($type, 'invoice_id', $invoiceId, $reason);
    }

    // --- 予告経路 (dedup_key) ---

    public static function markSentByDedupKey(BillingNotificationType $type, string $dedupKey): void
    {
        self::markSentBy($type, 'dedup_key', $dedupKey);
    }

    public static function markFailedByDedupKey(BillingNotificationType $type, string $dedupKey, Throwable $e): void
    {
        self::markFailedReasonByDedupKey($type, $dedupKey, self::formatError($e));
    }

    public static function markFailedReasonByDedupKey(BillingNotificationType $type, string $dedupKey, string $reason): void
    {
        self::markFailedReasonBy($type, 'dedup_key', $dedupKey, $reason);
    }

    // --- 内部共通 ---

    private static function markSentBy(BillingNotificationType $type, string $column, string $value): void
    {
        BillingNotification::query()
            ->where('type', $type->value)
            ->where($column, $value)
            ->update([
                'status' => BillingNotificationStatus::Sent->value,
                'sent_at' => now(),
                'failed_at' => null,
                'error' => null,
            ]);
    }

    private static function markFailedReasonBy(BillingNotificationType $type, string $column, string $value, string $reason): void
    {
        BillingNotification::query()
            ->where('type', $type->value)
            ->where($column, $value)
            ->where('status', '!=', BillingNotificationStatus::Sent->value)
            ->update([
                'status' => BillingNotificationStatus::Failed->value,
                'failed_at' => now(),
                'error' => $reason,
            ]);
    }

    /** 例外クラス名 + 短縮メッセージ (error 列 512 文字に収める。PII 抑制)。 */
    private static function formatError(Throwable $e): string
    {
        return Str::limit($e::class.': '.$e->getMessage(), 480);
    }
}
