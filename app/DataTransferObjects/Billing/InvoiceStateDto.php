<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

/**
 * P8a: リコンサイルが参照する Stripe invoice の現在状態
 * (raw payload を Service に持ち込まない境界)。
 * status: 'draft' | 'open' | 'paid' | 'void' | 'uncollectible' | 'deleted'
 */
final readonly class InvoiceStateDto
{
    public function __construct(
        public string $status,
        public ?int $amountPaid,
        /** 請求額 (invoice.amount_due)。amount cross-check の照合対象。 */
        public ?int $amountDue,
        public ?string $paymentIntentId,
        /**
         * PaymentIntent が SCA (requires_action / authentication_required) 待ちか。
         * true の間は attempt を終端させない (復旧可能)。
         */
        public bool $requiresAction,
        /** SCA (追加認証) の復旧導線。Stripe ホスト決済ページ。 */
        public ?string $hostedInvoiceUrl,
    ) {}
}
