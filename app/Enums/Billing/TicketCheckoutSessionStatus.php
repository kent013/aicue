<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * ticket_checkout_sessions の状態。
 *
 * - pending: Checkout URL 発行済み・決済待ち (expires_at > now のときのみ live)
 * - completed: webhook (checkout.session.completed) で付与済み
 * - expired: 期限切れ回収 / 別 count 再購入時の明示 expire
 */
enum TicketCheckoutSessionStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Expired = 'expired';
}
