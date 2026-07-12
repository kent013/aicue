<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Dashboard;

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
        public bool $hasBillingAccess,      // BillingAccess::hasActiveAccess (billing entitlement。free 組織は true)
    ) {}

    /**
     * @return array{ticket_balance: int, is_low_balance: bool, storage_used_bytes: int,
     *   storage_limit_bytes: int|null, storage_usage_percent: int|null,
     *   has_billing_access: bool}
     */
    public function toArray(): array
    {
        return [
            'ticket_balance' => $this->ticketBalance,
            'is_low_balance' => $this->isLowBalance,
            'storage_used_bytes' => $this->storageUsedBytes,
            'storage_limit_bytes' => $this->storageLimitBytes,
            'storage_usage_percent' => $this->storageUsagePercent,
            'has_billing_access' => $this->hasBillingAccess,
        ];
    }
}
