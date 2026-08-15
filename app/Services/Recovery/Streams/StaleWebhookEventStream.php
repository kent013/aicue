<?php

declare(strict_types=1);

namespace App\Services\Recovery\Streams;

use App\Contracts\Recovery\StuckWorkStream;
use App\Enums\Recovery\RecoveryOutcome;
use App\Enums\Recovery\RecoveryStream;
use App\Services\Billing\StripeWebhookProcessor;
use Carbon\CarbonImmutable;

/**
 * 本処理中にプロセスが落ちて received のまま残った Stripe webhook 記録。
 *
 * 放置すると Stripe の再送は受理側に弾かれて 200 で終わり、Stripe 側も配信成功と
 * 判断して再送を打ち切るため、決済済みチケットの付与が**無音で失われる**。
 *
 * 5 値のうち 4 値を使う唯一の stream である (Recovered / Deferred / Escalated / Skipped)。
 */
final readonly class StaleWebhookEventStream implements StuckWorkStream
{
    public function __construct(private StripeWebhookProcessor $webhooks) {}

    public function stream(): RecoveryStream
    {
        return RecoveryStream::WebhookEvent;
    }

    public function candidateIds(CarbonImmutable $sweptAt, ?int $afterId, int $pageSize): array
    {
        return $this->webhooks->staleRecordIds($sweptAt, $afterId, $pageSize);
    }

    public function recover(int $id, CarbonImmutable $sweptAt): RecoveryOutcome
    {
        return $this->webhooks->recoverStuckEvent($id, $sweptAt);
    }

    public function sweepItemLimit(): ?int
    {
        return null;
    }
}
