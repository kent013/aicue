<?php

declare(strict_types=1);

namespace App\Enums\Manual;

/**
 * 解析/レンダジョブの状態 (doc/10 §10.2)。
 * AnalysisJob / RenderJob が共用する。
 */
enum JobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /** terminal (成否確定) か。failJob / recoverStale の guard に使う */
    public function isTerminal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }
}
