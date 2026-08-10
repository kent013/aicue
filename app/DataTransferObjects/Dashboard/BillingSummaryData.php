<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Dashboard;

use App\Enums\Billing\OnboardingBillingState;

/**
 * チケット残高 + 容量 Quota (低残高警告と高使用率警告は別個のフラグ)。
 * TS 側 types/dashboard.ts の BillingSummary と対で保守する。
 */
final readonly class BillingSummaryData
{
    public function __construct(
        public int $ticketBalance,
        public bool $isLowBalance,          // balance < billing.ticket_low_balance_threshold
        public int $storageUsedBytes,       // StorageUsageService::occupiedBytes
        public ?int $storageLimitBytes,     // QuotaService::limits[max_storage_bytes] (無制限は null)
        public ?int $storageUsagePercent,   // 0-100 に clamp (limit null なら null)
        /**
         * 課金状態 (BillingAccess::state)。**真偽値に潰さない** — 「未契約」と
         * 「支払い不健全」は次の一手が違うため、画面が区別できる必要がある
         * (bug-hunt 20260811-003230 F-2-01)。利用可否だけが要るときは
         * `$billingState->grantsAccess()` で判定できる (情報量は真偽値より広い)。
         */
        public OnboardingBillingState $billingState,
    ) {}

    /**
     * `value-of<OnboardingBillingState>` を使う (単なる `string` にしない)。
     * 既存の `BillingFeedbackDto` の `@phpstan-type` が同じ書き方で、
     * enum の値集合を PHPStan に伝えている先例である。
     *
     * @return array{ticket_balance: int, is_low_balance: bool, storage_used_bytes: int,
     *   storage_limit_bytes: int|null, storage_usage_percent: int|null,
     *   billing_state: value-of<OnboardingBillingState>}
     */
    public function toArray(): array
    {
        return [
            'ticket_balance' => $this->ticketBalance,
            'is_low_balance' => $this->isLowBalance,
            'storage_used_bytes' => $this->storageUsedBytes,
            'storage_limit_bytes' => $this->storageLimitBytes,
            'storage_usage_percent' => $this->storageUsagePercent,
            'billing_state' => $this->billingState->value,
        ];
    }
}
