<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

use App\Enums\Billing\BillingFeedbackKind;

/**
 * P9: /billing 着地時のフィードバック。
 * Controller は着地 hop が積んだ one-shot flash (kind) からのみ構築し、
 * UI は raw query を見ずにこの DTO のみを描画する。
 *
 * @phpstan-type BillingFeedbackShape array{kind: value-of<BillingFeedbackKind>, message: string}
 */
final readonly class BillingFeedbackDto
{
    private function __construct(
        public BillingFeedbackKind $kind,
        public string $message,
    ) {}

    /** kind から確定文言を引いて組み立てる (文言の出典は enum 一本)。 */
    public static function fromKind(BillingFeedbackKind $kind): self
    {
        return new self($kind, $kind->message());
    }

    /**
     * @return BillingFeedbackShape
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'message' => $this->message,
        ];
    }
}
