<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

/**
 * P8a: Stripe customer の default payment method 状態
 * (invoice_settings.default_payment_method)。
 * オートリチャージ有効化の fail-closed 判定・UI 表示 (brand/last4) に使う。
 */
final readonly class DefaultPaymentMethodDto
{
    public function __construct(
        public ?string $paymentMethodId,
        public ?string $brand,
        public ?string $last4,
    ) {}

    public static function none(): self
    {
        return new self(null, null, null);
    }

    public function exists(): bool
    {
        return $this->paymentMethodId !== null;
    }
}
