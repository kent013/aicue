<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

/**
 * チケット購入画面 (/purchase-tickets) の Inertia page prop。
 *
 * TS 側は resources/js/types/billing.ts の PurchaseTicketsPageProps と exact 対で保守する。
 *
 * @phpstan-import-type PurchaseTierShape from PurchaseTierDto
 *
 * @phpstan-type PurchaseTicketsPageShape array{
 *   tiers: list<PurchaseTierShape>,
 *   minCount: int,
 *   maxCount: int,
 *   defaultCount: int,
 *   balance: int,
 *   canManage: bool,
 *   attemptToken: string,
 *   purchased: bool,
 *   autoRechargeEnabled: bool
 * }
 */
final readonly class PurchaseTicketsPageDto
{
    /**
     * @param  list<PurchaseTierDto>  $tiers
     */
    public function __construct(
        public array $tiers,
        public int $minCount,
        public int $maxCount,
        public int $defaultCount,
        public int $balance,
        public bool $canManage,
        public string $attemptToken,
        public bool $purchased,
        /** P8a: オートリチャージが有効か (購入導線の案内文言の出し分けに使う。既定 false)。 */
        public bool $autoRechargeEnabled = false,
    ) {}

    /**
     * @return PurchaseTicketsPageShape
     */
    public function toArray(): array
    {
        return [
            'tiers' => array_map(
                static fn (PurchaseTierDto $tier): array => $tier->toArray(),
                $this->tiers,
            ),
            'minCount' => $this->minCount,
            'maxCount' => $this->maxCount,
            'defaultCount' => $this->defaultCount,
            'balance' => $this->balance,
            'canManage' => $this->canManage,
            'attemptToken' => $this->attemptToken,
            'purchased' => $this->purchased,
            'autoRechargeEnabled' => $this->autoRechargeEnabled,
        ];
    }
}
