<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * Stripe webhook イベントの処理状態。
 *
 * - `received`: 受理済み・未終局。**「処理中」と「次の回収待ち」を兼ねる**
 *   (どちらかは `updated_at` が滞留の閾値を超えたかで区別する)
 * - `processed`: 終局 (再処理しない)
 * - `failed`: HTTP 経路での失敗。Stripe の再送で再処理し得る
 * - `recovery_pending`: 滞留を検出したが**自動再実行の対象外**と判定して置いた静止状態。
 *   理由は `stripe_webhook_events.recovery_reason` に残る。自動では二度と動かさない
 */
enum WebhookEventStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Failed = 'failed';
    case RecoveryPending = 'recovery_pending';

    public function label(): string
    {
        return match ($this) {
            self::Received => '受信',
            self::Processed => '処理済',
            self::Failed => '失敗',
            self::RecoveryPending => '回収待ち',
        };
    }
}
