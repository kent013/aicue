<?php

declare(strict_types=1);

namespace App\Services\Capture;

use App\Enums\Capture\TakeUploadReservationStatus;
use App\Models\Organization;
use App\Models\Take;
use App\Models\TakeUploadReservation;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * 容量 Quota の使用量集計 (doc/10 §10.8-4 の真実源)。
 * カウンタキャッシュは持たない (二重帳簿禁止。真実源 = 集計クエリ)。
 */
class StorageUsageService
{
    /**
     * Quota 判定に渡す占有量 (bytes_used + bytes_pending) の安全合成。
     * 呼び出し側で生の加算をさせない (overflow は上限側に丸める。checkAddition の
     * 事前条件 current >= 0 も本メソッドが保証する)。
     */
    public function occupiedBytes(Organization $organization): int
    {
        $used = $this->bytesUsed($organization);
        $pending = $this->bytesPending($organization);

        return $used > PHP_INT_MAX - $pending ? PHP_INT_MAX : $used + $pending;
    }

    /** takes.size_bytes の org 合計 (takes→cuts→video_manuals→projects→custom_teams join) */
    public function bytesUsed(Organization $organization): int
    {
        return (int) Take::query()
            ->join('cuts', 'cuts.id', '=', 'takes.cut_id')
            ->join('video_manuals', 'video_manuals.id', '=', 'cuts.video_manual_id')
            ->join('projects', 'projects.id', '=', 'video_manuals.project_id')
            ->join('custom_teams', 'custom_teams.id', '=', 'projects.custom_team_id')
            ->where('custom_teams.organization_id', $organization->id)
            ->sum('takes.size_bytes');
    }

    /**
     * 予約中の org 合計 (Quota 占有分):
     * - pending かつ未失効
     * - verifying は**全件** (claim 中に集計から消えて上限超過を許さない。概念設計 D3。
     *   stale verifying は cron が released 化して解放する)
     */
    public function bytesPending(Organization $organization): int
    {
        return (int) TakeUploadReservation::query()
            ->where('organization_id', $organization->id)
            ->where(function (Builder $query): void {
                $query->where(fn (Builder $q) => $q
                    ->where('status', TakeUploadReservationStatus::Pending)
                    ->where('expires_at', '>', now()))
                    ->orWhere('status', TakeUploadReservationStatus::Verifying);
            })
            ->sum('size_bytes');
    }
}
