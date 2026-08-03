<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

/**
 * P8a (D29): オンボーディング同意提示 + 事前同意記録が共有する「同意条件」の単一計算源 DTO。
 *
 * AutoRechargeService::consentTermsFor() が返す値がそのまま画面に表示され、
 * recordPreConsent() が同じ計算源で記録する (表示金額 = 記録金額の一致をテストで固定)。
 * TS 側 `AutoRechargeConsentTerms` (resources/js/types/billing.ts) と厳密一致契約。
 *
 * @phpstan-type AutoRechargeConsentTermsShape array{
 *   thresholdCount: int,
 *   maxCount: int,
 *   maxAmountJpy: int,
 *   unitAmountJpy: int,
 *   consentVersion: string
 * }
 */
final readonly class AutoRechargeConsentTermsDto
{
    public function __construct(
        public int $thresholdCount,
        public int $maxCount,
        /** 1 回の自動購入の上限額 (税込・整数円) = currentTierFor(maxCount)->unitAmount * maxCount */
        public int $maxAmountJpy,
        /** maxCount 適用時の単価 (税込・整数円) */
        public int $unitAmountJpy,
        public string $consentVersion,
    ) {}

    /** @return AutoRechargeConsentTermsShape */
    public function toArray(): array
    {
        return [
            'thresholdCount' => $this->thresholdCount,
            'maxCount' => $this->maxCount,
            'maxAmountJpy' => $this->maxAmountJpy,
            'unitAmountJpy' => $this->unitAmountJpy,
            'consentVersion' => $this->consentVersion,
        ];
    }
}
