<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

use App\DataTransferObjects\Marketing\PricingPlanDto;
use App\Enums\Billing\PlanPriceKind;
use App\Models\Billing\Plan;

/**
 * プラン 1 件分 (Billing 内部 DTO)。料金表専用の {@see PricingPlanDto}
 * とは責務分離する (こちらはログイン後のオンボーディング / 課金画面用)。
 *
 * currentBaseAmount の契約: null = plan_prices (base) を持たない = Checkout 対象外の
 * **無料表示** (PricingPlanDto::baseAmountJpy と同一意味論)。通貨は JPY 固定のため
 * 通貨フィールドを持たない (AI-CUE の金額契約)。
 *
 * 月次付与枚数は持たない (D28: 月次付与は廃止 = 全 tier 0)。能力値 (プロジェクト数等) は
 * config/quota.php の「値」で表現する規約のため DTO には載せない。
 *
 * @phpstan-type PlanDtoShape array{
 *   code: string,
 *   name: string,
 *   currentBaseAmount: int|null,
 *   isActive: bool
 * }
 */
final readonly class PlanDto
{
    public function __construct(
        public string $code,
        public string $name,
        public ?int $currentBaseAmount,
        public bool $isActive,
    ) {}

    public static function fromModel(Plan $plan): self
    {
        return new self(
            code: $plan->code,
            name: $plan->name,
            currentBaseAmount: $plan->currentPrice(PlanPriceKind::Base)?->amount,
            isActive: $plan->is_active,
        );
    }

    /**
     * @return PlanDtoShape
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'currentBaseAmount' => $this->currentBaseAmount,
            'isActive' => $this->isActive,
        ];
    }
}
