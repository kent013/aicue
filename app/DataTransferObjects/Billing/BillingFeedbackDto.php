<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

use App\Enums\Billing\BillingFeedbackKind;

/**
 * P9: /billing 着地時のフィードバック。
 * Controller が query (session_id / portal / replayed / retry) を解釈して構築し、
 * UI は raw query を見ずにこの DTO のみを描画する。
 *
 * @phpstan-type SimpleBillingFeedbackKind 'purchase_received'|'purchase_processing'|'purchase_already_received'|'checkout_retry_required'|'portal_returned'
 * @phpstan-type BillingFeedbackShape array{kind: SimpleBillingFeedbackKind, message: string}
 */
final readonly class BillingFeedbackDto
{
    private function __construct(
        public BillingFeedbackKind $kind,
        public string $message,
    ) {}

    /**
     * CTA を持たない通常フィードバック (purchase_received / processing / already / retry / portal)。
     */
    public static function simple(BillingFeedbackKind $kind, string $message): self
    {
        return new self($kind, $message);
    }

    /**
     * @return BillingFeedbackShape
     */
    public function toArray(): array
    {
        /** @var SimpleBillingFeedbackKind $kindValue */
        $kindValue = $this->kind->value;

        return [
            'kind' => $kindValue,
            'message' => $this->message,
        ];
    }
}
