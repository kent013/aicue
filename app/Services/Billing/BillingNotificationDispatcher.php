<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\BillingNotificationStatus;
use App\Enums\Billing\BillingNotificationType;
use App\Enums\Billing\BillingReminderDispatchResult;
use App\Models\Billing\BillingNotification;
use App\Models\Organization;
use App\Notifications\Billing\Concerns\TracksBillingDelivery;
use App\Notifications\Billing\Concerns\TracksBillingReminderDelivery;
use App\Support\Billing\BillingNotificationRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * 請求通知を「1 度だけ」送る冪等 dispatch 窓口。
 */
class BillingNotificationDispatcher
{
    /**
     * billing_notifications に (type, invoice_id) 複合 UNIQUE 行を insertOrIgnore し (重複は
     * 0 件 = 既送扱い)、新規行のときだけ通知を queue する。
     *
     * notify() の enqueue 例外は failed 記録 + ログに留めて握りつぶす (webhook 本処理を巻き込まない)。
     * 宛先 null (請求宛先を解決できない組織) は notify せず failed(missing_billing_recipient) として
     * 記録する (Laravel が mail channel を skip して status=queued が滞留するのを防ぐ)。
     */
    public function sendOnce(
        Organization $organization,
        BillingNotificationType $type,
        string $invoiceId,
        Notification&TracksBillingDelivery $notification,
    ): void {
        Assert::stringNotEmpty($invoiceId);
        Assert::true(
            $notification->deliveryType() === $type
                && $notification->deliveryInvoiceId() === $invoiceId,
            'BillingNotification delivery key mismatch.',
        );

        // insertOrIgnore (= INSERT ... ON CONFLICT DO NOTHING) で冪等 insert する。
        // save() + catch(UniqueConstraintViolationException) だと PostgreSQL では UNIQUE 衝突が
        // 「文エラー」として親トランザクション全体を abort し (SQLSTATE 25P02)、後続の
        // markProcessed/markFailed まで巻き添えで落ちる (webhook の TX 文脈 / RefreshDatabase 下
        // 双方で発生)。SQLite は文単位で済むため顕在化しない driver gap に注意。
        $now = CarbonImmutable::now();
        $inserted = BillingNotification::query()->insertOrIgnore([
            'organization_id' => $organization->getKey(),
            'type' => $type->value,
            'invoice_id' => $invoiceId,
            'status' => BillingNotificationStatus::Queued->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted === 0) {
            return; // 既送 (または送信中) = (type, invoice_id) 既存
        }

        // 宛先確認。null なら mail skip で queued 滞留するため failed として確定。
        if ($organization->routeNotificationForMail($notification) === null) {
            BillingNotificationRecorder::markFailedReason($type, $invoiceId, 'missing_billing_recipient');

            return;
        }

        try {
            $organization->notify($notification);
        } catch (Throwable $e) {
            BillingNotificationRecorder::markFailed($type, $invoiceId, $e);
            Log::warning('billing notification enqueue failed', [
                'type' => $type->value,
                'invoice_id' => $invoiceId,
                'error' => $e::class,
            ]);
        }
    }

    /**
     * 予告 (reminder) 通知を「1 度だけ」送る。billing_notifications に (type, dedup_key) 複合 UNIQUE
     * 行を insertOrIgnore し (重複は 0 件 = 既送扱い)、新規行のときだけ通知を queue する。
     *
     * sendOnce (invoice 経路) と同型のガード／失敗吸収。結果を {@see BillingReminderDispatchResult}
     * で返しコマンド側が種別毎に queued/skipped/failed を集計できるようにする。
     */
    public function sendReminderOnce(
        Organization $organization,
        BillingNotificationType $type,
        string $dedupKey,
        Notification&TracksBillingReminderDelivery $notification,
    ): BillingReminderDispatchResult {
        Assert::stringNotEmpty($dedupKey);
        Assert::true(
            $notification->deliveryType() === $type
                && $notification->deliveryDedupKey() === $dedupKey,
            'BillingNotification reminder delivery key mismatch.',
        );

        // insertOrIgnore で冪等 insert (sendOnce と同一理由: PostgreSQL の TX abort 回避)。
        $now = CarbonImmutable::now();
        $inserted = BillingNotification::query()->insertOrIgnore([
            'organization_id' => $organization->getKey(),
            'type' => $type->value,
            'dedup_key' => $dedupKey,
            'status' => BillingNotificationStatus::Queued->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted === 0) {
            return BillingReminderDispatchResult::Skipped; // 既送 ((type, dedup_key) 既存)
        }

        if ($organization->routeNotificationForMail($notification) === null) {
            BillingNotificationRecorder::markFailedReasonByDedupKey($type, $dedupKey, 'missing_billing_recipient');

            return BillingReminderDispatchResult::Failed;
        }

        try {
            $organization->notify($notification);
        } catch (Throwable $e) {
            BillingNotificationRecorder::markFailedByDedupKey($type, $dedupKey, $e);
            Log::warning('billing reminder enqueue failed', [
                'type' => $type->value,
                'dedup_key' => $dedupKey,
                'error' => $e::class,
            ]);

            return BillingReminderDispatchResult::Failed;
        }

        return BillingReminderDispatchResult::Queued;
    }
}
