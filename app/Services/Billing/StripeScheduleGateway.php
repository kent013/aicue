<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Dtos\Billing\ScheduleSnapshotDto;
use Laravel\Cashier\Cashier;
use Stripe\Exception\InvalidRequestException;

/**
 * Stripe Subscription Schedule API の集約 gateway。
 *
 * schedule の 4 操作 (create / update phases / release / retrieve) を 1 クラスに集約し、
 * Stripe SDK 呼び出しをここに閉じる (テストは本 gateway を mock 差替)。
 * 全 mutation は idempotency_key を渡す。
 */
class StripeScheduleGateway
{
    /** from_subscription で schedule を作成し、その ID を返す。 */
    public function createFromSubscription(string $stripeSubscriptionId, string $idempotencyKey): string
    {
        $schedule = Cashier::stripe()->subscriptionSchedules->create(
            ['from_subscription' => $stripeSubscriptionId],
            ['idempotency_key' => $idempotencyKey],
        );

        return $schedule->id;
    }

    /**
     * schedule の phases を設定する。
     *
     * @param  list<array{items: list<array{price: string, quantity?: int}>, start_date?: int, end_date?: int}>  $phases
     */
    public function updatePhases(string $scheduleId, array $phases, string $endBehavior, string $idempotencyKey): void
    {
        Cashier::stripe()->subscriptionSchedules->update(
            $scheduleId,
            ['phases' => $phases, 'end_behavior' => $endBehavior],
            ['idempotency_key' => $idempotencyKey],
        );
    }

    /**
     * schedule を release する (subscription を schedule 管理から外す)。
     * 既に released/canceled/completed なら冪等成功扱い。
     */
    public function release(string $scheduleId, string $idempotencyKey): void
    {
        try {
            Cashier::stripe()->subscriptionSchedules->release($scheduleId, [], ['idempotency_key' => $idempotencyKey]);
        } catch (InvalidRequestException $e) {
            $schedule = Cashier::stripe()->subscriptionSchedules->retrieve($scheduleId, []);
            if (! in_array($schedule->status, ['released', 'canceled', 'completed'], true)) {
                throw $e;
            }
            // 既に終端状態 = 冪等成功。
        }
    }

    /** subscription から現在の schedule を取得して正規化する。無ければ null。 */
    public function retrieve(string $stripeSubscriptionId): ?ScheduleSnapshotDto
    {
        $subscription = Cashier::stripe()->subscriptions->retrieve($stripeSubscriptionId, ['expand' => ['schedule']]);
        $scheduleId = $subscription->schedule;
        if ($scheduleId === null) {
            return null;
        }

        $scheduleIdStr = is_string($scheduleId) ? $scheduleId : $scheduleId->id;
        $schedule = Cashier::stripe()->subscriptionSchedules->retrieve($scheduleIdStr, []);

        /** @var list<array{base_price_id: string|null, start_date: int|null, end_date: int|null}> $phases */
        $phases = [];
        foreach ($schedule->phases as $phase) {
            $items = $phase->items ?? [];
            $firstPrice = null;
            foreach ($items as $item) {
                $price = $item->price ?? null;
                $firstPrice = is_string($price) ? $price : ($price->id ?? null);
                if ($firstPrice !== null) {
                    break;
                }
            }

            $phases[] = [
                'base_price_id' => $firstPrice,
                'start_date' => $this->positiveEpoch($phase->start_date ?? null),
                'end_date' => $this->positiveEpoch($phase->end_date ?? null),
            ];
        }

        return new ScheduleSnapshotDto(
            id: $scheduleIdStr,
            phases: $phases,
            endBehavior: $schedule->end_behavior ?? 'release',
        );
    }

    private function positiveEpoch(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }
}
