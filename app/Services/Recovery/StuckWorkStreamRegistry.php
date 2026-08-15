<?php

declare(strict_types=1);

namespace App\Services\Recovery;

use App\Contracts\Recovery\StuckWorkStream;
use App\Enums\Recovery\RecoveryStream;
use App\Services\Recovery\Streams\ExpiredTicketReservationStream;
use App\Services\Recovery\Streams\StaleAnalysisJobStream;
use App\Services\Recovery\Streams\StaleRenderJobStream;
use App\Services\Recovery\Streams\StaleUploadReservationStream;
use App\Services\Recovery\Streams\StaleWebhookEventStream;
use Webmozart\Assert\Assert;

/**
 * 滞留回収の系列一覧。**解決は RecoveryStream 起点**で行う。
 *
 * 登録キーは各 stream 自身の stream() が名乗る値なので、
 * 「宣言と実装がずれる」形は構造的に作れない。重複・欠落は constructor の Assert が落とす。
 */
final class StuckWorkStreamRegistry
{
    /** @var array<value-of<RecoveryStream>, StuckWorkStream> */
    private array $streams;

    public function __construct(
        StaleAnalysisJobStream $analysisJobs,
        StaleRenderJobStream $renderJobs,
        ExpiredTicketReservationStream $ticketReservations,
        StaleWebhookEventStream $webhookEvents,
        StaleUploadReservationStream $uploadReservations,
    ) {
        $this->streams = [];
        foreach ([$analysisJobs, $renderJobs, $ticketReservations, $webhookEvents, $uploadReservations] as $stream) {
            $this->streams[$stream->stream()->value] = $stream;
        }

        Assert::count(
            $this->streams,
            count(RecoveryStream::cases()),
            'stream の登録に重複または欠落があります (RecoveryStream の case と 1 対 1 であること)',
        );
    }

    public function get(RecoveryStream $stream): StuckWorkStream
    {
        return $this->streams[$stream->value];
    }

    /** @return list<StuckWorkStream> */
    public function all(): array
    {
        return array_values($this->streams);
    }
}
