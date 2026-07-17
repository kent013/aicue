<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Marketing;

/**
 * 料金表 (pricing) 表示専用のプラン 1 件分 (Billing 内部 DTO と責務分離)。
 *
 * baseAmountJpy の契約: AI-CUE のプラン台帳では「plan_prices (base) を持たない =
 * Checkout 対象外の無料プラン」(PlanSeeder の既存意味論。Billing/Index.svelte の
 * formatPrice(null)=>「無料」と同じ表示契約)。「お問い合わせ」種別のプランを将来
 * Plan 行として導入する場合は、null の多義化を避けるため表示状態の明示フィールド
 * 追加を先に行うこと。
 *
 * maxStorageGb は GiB 換算の表示値 (intdiv(bytes, 1024**3) 切り捨て)。
 *
 * @phpstan-type PricingPlanShape array{
 *   code: string,
 *   name: string,
 *   baseAmountJpy: int|null,
 *   maxProjects: int|null,
 *   maxMembers: int|null,
 *   maxStorageGb: int|null
 * }
 */
final readonly class PricingPlanDto
{
    public function __construct(
        public string $code,
        public string $name,
        public ?int $baseAmountJpy,
        public ?int $maxProjects,
        public ?int $maxMembers,
        public ?int $maxStorageGb,
    ) {}

    /**
     * @return PricingPlanShape
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'baseAmountJpy' => $this->baseAmountJpy,
            'maxProjects' => $this->maxProjects,
            'maxMembers' => $this->maxMembers,
            'maxStorageGb' => $this->maxStorageGb,
        ];
    }
}
