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
     *
     * 読み取り順は **pending → used** を維持すること (並行制御上の不変条件):
     * issue() は Organization 行ロック下で呼ばれるが、テイク登録の finalize
     * (verifying 予約→Take 確定) は VideoManual ロックしか取らず直列化されない。
     * READ COMMITTED では 2 本の読み取りの隙間に finalize がコミットしうるため、
     * used→pending の順だと当該予約が「どちらにも数えられず」過少計上になり
     * Quota を一時的にバイパスできる。pending→used の順なら同じ競合は
     * 二重計上 (= 拒否側・安全側) に倒れる。
     */
    public function occupiedBytes(Organization $organization): int
    {
        $pending = $this->bytesPending($organization);
        $used = $this->bytesUsed($organization);

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
