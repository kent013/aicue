<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

use App\Enums\Billing\PurchaseFormState;

/**
 * チケット購入画面 (/purchase-tickets) の Inertia page prop。
 *
 * TS 側は resources/js/types/billing.ts の PurchaseTicketsPageProps と exact 対で保守する。
 *
 * ticketAttemptToken は**チケット決済専用**の attempt token
 * (ticket_checkout_sessions.attempt_token / Stripe key `purchase:{token}` の名前空間)。
 * サブスク checkout 用 token (P9) とは別テーブル・別 key 空間のため型名で区別する。
 *
 * @phpstan-import-type PurchaseTierShape from PurchaseTierDto
 * @phpstan-import-type TicketBalanceShape from TicketBalanceDto
 *
 * @phpstan-type PurchaseTicketsPageShape array{
 *   tiers: list<PurchaseTierShape>,
 *   minCount: int,
 *   maxCount: int,
 *   defaultCount: int,
 *   balance: TicketBalanceShape,
 *   canManage: bool,
 *   ticketAttemptToken: string,
 *   purchased: bool,
 *   autoRechargeEnabled: bool,
 *   formState: string,
 *   boundCount: int|null,
 *   resumeUrl: string|null,
 *   newPurchaseUrl: string
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
        /** P5 由来の per-source 残高 snapshot (画面で再計算しない) */
        public TicketBalanceDto $balance,
        public bool $canManage,
        public string $ticketAttemptToken,
        public bool $purchased,
        /** P8b: 購入フォームの状態 (normal / resume / completed) */
        public PurchaseFormState $formState,
        /** resume / completed で表示する確定枚数 (normal は null) */
        public ?int $boundCount,
        /** resume の「決済を続ける」遷移先 (Stripe Checkout URL)。それ以外は null */
        public ?string $resumeUrl,
        /** 「新しく購入し直す」= ?fresh=1 の自画面 URL */
        public string $newPurchaseUrl,
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
            'balance' => $this->balance->toArray(),
            'canManage' => $this->canManage,
            'ticketAttemptToken' => $this->ticketAttemptToken,
            'purchased' => $this->purchased,
            'autoRechargeEnabled' => $this->autoRechargeEnabled,
            'formState' => $this->formState->value,
            'boundCount' => $this->boundCount,
            'resumeUrl' => $this->resumeUrl,
            'newPurchaseUrl' => $this->newPurchaseUrl,
        ];
    }
}
