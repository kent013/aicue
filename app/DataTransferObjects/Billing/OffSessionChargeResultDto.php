<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

/**
 * P8a: off-session Invoice 課金 (payOffSessionInvoice) の typed 結果。
 *
 * カード起因の失敗 (card_declined / authentication_required 等) は例外ではなくこの DTO で
 * 返す — リトライ/通知/終端の判断は Service 層の責務。Stripe 障害・設定不備は
 * 従来どおり例外で伝播する (fail-closed)。
 */
final readonly class OffSessionChargeResultDto
{
    private function __construct(
        public bool $paid,
        public string $invoiceId,
        /** 実回収額 (税込 JPY, invoice.amount_paid。credit balance 適用で amount_due より小さいことがある)。失敗時は null。 */
        public ?int $amountPaid,
        /** 請求額 (税込 JPY, invoice.amount_due)。amount cross-check の照合対象。失敗時は null。 */
        public ?int $amountDue,
        /** InvoicePayment 経由で解決した PaymentIntent id。 */
        public ?string $paymentIntentId,
        /** Stripe error code (例: card_declined, authentication_required)。成功時は null。 */
        public ?string $failureCode,
        /** decline code (例: insufficient_funds)。失敗時のみ。 */
        public ?string $declineCode,
    ) {}

    public static function paid(string $invoiceId, int $amountPaid, int $amountDue, ?string $paymentIntentId): self
    {
        return new self(true, $invoiceId, $amountPaid, $amountDue, $paymentIntentId, null, null);
    }

    public static function failed(string $invoiceId, ?string $failureCode, ?string $declineCode): self
    {
        return new self(false, $invoiceId, null, null, null, $failureCode, $declineCode);
    }

    /**
     * SCA (追加認証) 要求か。true なら終端させず pending 維持 + 復旧導線
     * (hosted_invoice_url) を案内する。
     */
    public function requiresAction(): bool
    {
        return $this->failureCode === 'authentication_required';
    }
}
