<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

use App\Enums\Billing\EntitlementDeniedReason;
use App\Enums\Billing\SubscriptionState;

/**
 * subscription の利用可否 (entitlement) を導出した結果。
 *
 * **state 単体では判定しない**。`SubscriptionService::deriveEntitlement` が唯一の生成経路で、
 * state + PM 有無 + trial_ends_at + Stripe status snapshot を合成して `entitled` を確定する。
 * 否定時は `reason` を必ず付ける (フロントの状態説明出し分け用)。
 *
 * @phpstan-type EntitlementShape array{
 *   entitled: bool,
 *   state: string,
 *   reason: string|null
 * }
 */
final readonly class SubscriptionEntitlementDto
{
    public function __construct(
        public bool $entitled,
        public SubscriptionState $state,
        public ?EntitlementDeniedReason $reason,
    ) {}

    public static function granted(SubscriptionState $state): self
    {
        return new self(true, $state, null);
    }

    public static function denied(SubscriptionState $state, EntitlementDeniedReason $reason): self
    {
        return new self(false, $state, $reason);
    }

    /**
     * @return EntitlementShape
     */
    public function toArray(): array
    {
        return [
            'entitled' => $this->entitled,
            'state' => $this->state->value,
            'reason' => $this->reason?->value,
        ];
    }
}
