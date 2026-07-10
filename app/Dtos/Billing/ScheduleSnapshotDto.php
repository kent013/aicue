<?php

declare(strict_types=1);

namespace App\Dtos\Billing;

/**
 * Stripe Subscription Schedule のリモート状態スナップショット。
 * StripeScheduleGateway::retrieve() が SDK オブジェクトから正規化して返す。
 */
final readonly class ScheduleSnapshotDto
{
    /**
     * @param  list<array{base_price_id: string|null, start_date: int|null, end_date: int|null}>  $phases
     */
    public function __construct(
        public string $id,
        public array $phases,
        public string $endBehavior,
    ) {}
}
