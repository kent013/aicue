<?php

declare(strict_types=1);

namespace App\Services\Recovery\Streams;

use App\Contracts\Recovery\StuckWorkStream;
use App\Enums\Recovery\RecoveryOutcome;
use App\Enums\Recovery\RecoveryStream;
use App\Services\Manual\RenderJobService;
use Carbon\CarbonImmutable;

/**
 * 滞留したレンダジョブ。閾値は 2 本ある —
 * queued は manual.render_queued_stale_after_minutes (10 分。dispatch 喪失)、
 * running は manual.render_stale_after_minutes (30 分。worker 異常終了)。
 *
 * **閾値は config/manual.php に置いたまま**にする (解析と同じ理由。序列の情報源を割らない)。
 */
final readonly class StaleRenderJobStream implements StuckWorkStream
{
    public function __construct(private RenderJobService $jobs) {}

    public function stream(): RecoveryStream
    {
        return RecoveryStream::RenderJob;
    }

    public function candidateIds(CarbonImmutable $sweptAt, ?int $afterId, int $pageSize): array
    {
        // 候補列挙も Service へ委譲する。滞留の述語を stream と Service に**複製しない**
        return $this->jobs->staleJobIds($sweptAt, $afterId, $pageSize);
    }

    public function recover(int $id, CarbonImmutable $sweptAt): RecoveryOutcome
    {
        return $this->jobs->failStaleJob($id, $sweptAt)
            ? RecoveryOutcome::Recovered
            : RecoveryOutcome::Skipped;   // 競合で前進済み / 進捗が進んだ = 失敗ではない
    }

    public function sweepItemLimit(): ?int
    {
        return null;
    }
}
