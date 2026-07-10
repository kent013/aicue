<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * Stripe webhook イベントの処理状態。
 * processed は再処理しない (冪等 skip)。failed は Stripe の再送で再処理し得る。
 */
enum WebhookEventStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Received => '受信',
            self::Processed => '処理済',
            self::Failed => '失敗',
        };
    }
}
