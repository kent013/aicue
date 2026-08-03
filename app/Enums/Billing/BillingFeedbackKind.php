<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * P9: 課金 Checkout / portal の着地フィードバック種別。
 * Inertia::location() の full page redirect を跨いだ後、/billing 着地で
 * ユーザーに「購入を受け付けたか / 処理中か / 既に受付済みか」を伝える。
 *
 * T088 で PurchaseFormState::Completed を撤去したため、**購入完了をユーザーに知らせる
 * 唯一の経路が本 feedback (one-shot)** になっている。
 */
enum BillingFeedbackKind: string
{
    case PurchaseReceived = 'purchase_received';
    case PurchaseProcessing = 'purchase_processing';
    case PurchaseAlreadyReceived = 'purchase_already_received';
    case CheckoutRetryRequired = 'checkout_retry_required';
    case PortalReturned = 'portal_returned';
}
