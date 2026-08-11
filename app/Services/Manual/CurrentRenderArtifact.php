<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderKind;
use App\Models\RenderJob;
use App\Models\VideoManual;

/**
 * 「いま受け取れるレンダ成果物はどれか」の**唯一の選択式** (playback / download / 詳細画面 props)。
 *
 * 定義は保持ポリシー (RenderJobService::newerSucceededExists / DeleteRenderOutputsJob) と
 * **同じ世代定義**である: 実体が残るのは「同 manual・同 kind の最新 succeeded」だけなので、
 * 最新 succeeded の output_path が NULL (= 生成に失敗した / 掃除された) なら
 * **旧世代へフォールバックしない** (削除済みオブジェクトの署名 URL を出さないため)。
 *
 * **持たない責務**: published 判定 (完成動画の公開状態) と ability 判定は呼び出し側にある。
 * ここは「どの行か」だけを答える (名前が示す役割を超えない)。読み取り専用。
 */
final class CurrentRenderArtifact
{
    /** 同 manual・同 kind で現在受け取れる succeeded job (無ければ null) */
    public static function currentSucceeded(VideoManual $manual, RenderKind $kind): ?RenderJob
    {
        $job = $manual->renderJobs()
            ->where('kind', $kind->value)
            ->where('status', JobStatus::Succeeded->value)
            ->latest('id')
            ->first();

        if ($job === null || $job->output_path === null) {
            return null; // 旧世代へフォールバックしない (実体が無い可能性がある)
        }

        return $job;
    }
}
