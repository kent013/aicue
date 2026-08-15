<?php

declare(strict_types=1);

namespace App\Services\Recovery\Streams;

use App\Contracts\Recovery\StuckWorkStream;
use App\Enums\Recovery\RecoveryOutcome;
use App\Enums\Recovery\RecoveryStream;
use App\Services\Manual\AnalysisJobService;
use Carbon\CarbonImmutable;

/**
 * 滞留した AI 解析ジョブ。閾値は manual.analysis_stale_after_minutes (30 分) で、
 * queued は発生時刻 (created_at)、running は進捗時刻 (updated_at) を起点にする。
 *
 * **閾値は config/manual.php に置いたまま**にする (ジョブの timeout < retry_after <
 * 予約 TTL <= 滞留閾値 の序列を AnalysisTimeBudgetInvariantTest が固定しているため。
 * 回収側の設定へ移すと序列の情報源が 2 つに割れる)。
 */
final readonly class StaleAnalysisJobStream implements StuckWorkStream
{
    public function __construct(private AnalysisJobService $jobs) {}

    public function stream(): RecoveryStream
    {
        return RecoveryStream::AnalysisJob;
    }

    public function candidateIds(CarbonImmutable $sweptAt, ?int $afterId, int $pageSize): array
    {
        // 候補列挙も Service へ委譲する。滞留の述語を stream と Service に**複製しない**
        // (片方だけ書き換えられると、行ロック下の再評価で塞いだ誤回収がそのまま再発する)
        return $this->jobs->staleJobIds($sweptAt, $afterId, $pageSize);
    }

    public function recover(int $id, CarbonImmutable $sweptAt): RecoveryOutcome
    {
        // 行ロック下で滞留の述語ごと再評価するのは Service 側の責務 (failStaleJob)。
        // stream は id を渡すだけで、行も判定結果も持ち回らない
        return $this->jobs->failStaleJob($id, $sweptAt)
            ? RecoveryOutcome::Recovered
            : RecoveryOutcome::Skipped;   // 競合で前進済み / 進捗が進んだ = 失敗ではない
    }

    public function sweepItemLimit(): ?int
    {
        return null;
    }
}
