<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\Enums\Billing\TicketReservationStatus;
use App\Enums\Manual\AnalysisConflictType;
use App\Enums\Manual\JobStatus;
use App\Enums\Manual\VideoManualStatus;
use App\Exceptions\Billing\InsufficientTicketsException;
use App\Exceptions\Manual\AnalysisConflictException;
use App\Jobs\Manual\RunManualAnalysis;
use App\Models\AnalysisJob;
use App\Models\Organization;
use App\Models\Project;
use App\Models\VideoManual;
use App\Services\Billing\TicketLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Webmozart\Assert\Assert;

/**
 * AI 解析ジョブの状態機械 (trigger / failJob / recoverStale)。doc/10 §10.8-8。
 *
 * VideoManualStatus 遷移表 (本サービスが関与する遷移。詳細は docs/architecture.md):
 * - draft/ready → analyzing: trigger() (行ロック + from-state guard。violate → 409)
 * - analyzing → ready/draft: failJob() (analyzing のときのみ復帰。cuts 有無で決定)
 * (analyzing → ready の成功遷移は ScenarioService::materializeIntoLockedManual = terminal tx 内)
 *
 * 共有ロック規約: status を書く経路は必ず VideoManual 行を lockForUpdate() した
 * 同一 tx 内で反映する (ScenarioWritePathInventoryTest が経路を deny-by-default で固定)。
 */
class AnalysisJobService
{
    public function __construct(
        private readonly TicketLedgerService $tickets,
    ) {}

    /**
     * AI 解析のトリガー (§10.8-8 冪等 + 残高事前チェック + enqueue)。
     *
     * - 実行可能状態: status ∈ {draft, ready} のみ (ready→analyzing = 再解析は正式遷移)
     * - analyze 冪等: 同一 manual の in-flight (queued/running) は 1 つ → 409
     * - 残高事前チェックは fail-fast の入口ゲート (真の残高保証は pipeline の reserve)
     */
    public function trigger(Project $project, VideoManual $manual): AnalysisJob
    {
        $job = DB::transaction(function () use ($project, $manual): AnalysisJob {
            // 共有ロック規約: status を書くため VideoManual 行ロック (親 relation 経由 = 子∈親も担保)
            /** @var VideoManual $locked */
            $locked = $project->manuals()->whereKey($manual->id)->lockForUpdate()->firstOrFail();

            // 実行可能状態 guard (ready→analyzing は再解析の正式遷移。doc/10 §10.2)
            if (! in_array($locked->status, [VideoManualStatus::Draft, VideoManualStatus::Ready], true)) {
                throw new AnalysisConflictException(AnalysisConflictType::StatusNotAnalyzable);
            }
            // analyze 冪等: 同一 manual の in-flight は 1 つ (§10.8-8)
            $inFlight = $locked->analysisJobs()
                ->whereIn('status', [JobStatus::Queued->value, JobStatus::Running->value])
                ->exists();
            if ($inFlight) {
                throw new AnalysisConflictException(AnalysisConflictType::InFlight);
            }
            // 解析対象 SOP (追記型の最新。行ロック下で決定的に選択)
            $document = $locked->sourceDocuments()->latest('id')->first();
            if ($document === null) {
                throw ValidationException::withMessages(['document' => ['手順書をアップロードしてください。']]);
            }
            // 残高事前チェック (reserve はジョブ開始時 = §10.5。ここは fail-fast の入口ゲート)
            $organization = $this->resolveOrganization($project);
            $cost = config()->integer('manual.analysis_ticket_cost');
            $balance = $this->tickets->balance($organization);
            if ($balance < $cost) {
                throw InsufficientTicketsException::forReserve($cost, $balance);
            }

            $job = $locked->analysisJobs()->make();
            $job->status = JobStatus::Queued;
            $job->sourceDocument()->associate($document);
            $job->save();

            $locked->forceFill(['status' => VideoManualStatus::Analyzing])->save();

            return $job;
        });

        // commit 後に dispatch (payload は job id のみ。dispatch 喪失は recoverStale が回収)
        RunManualAnalysis::dispatch($job->id);

        return $job;
    }

    /**
     * ジョブの失敗確定 (冪等)。pipeline catch / Job::failed / recoverStale の合流点。
     *
     * - terminal (succeeded/failed) 済みは no-op (terminal tx 勝ち・二重 fail を握る)
     * - manual は analyzing のときのみ復帰 (cuts があれば ready、無ければ draft)
     * - 予約は Reserved のみ release (並行 commit/release 済みは LogicException → 握って冪等)
     *
     * @return bool 実際に failed へ遷移させたか (terminal 済み no-op は false)
     */
    public function failJob(AnalysisJob $job, string $error): bool
    {
        return DB::transaction(function () use ($job, $error): bool {
            /** @var AnalysisJob $locked */
            $locked = AnalysisJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status->isTerminal()) {
                return false;
            }

            $locked->status = JobStatus::Failed;
            $locked->error = $error;
            $locked->save();

            // manual 復帰 (analyzing のときのみ。cuts があれば ready、無ければ draft = 概念設計 §4)
            /** @var VideoManual $manual */
            $manual = VideoManual::query()->whereKey($locked->video_manual_id)->lockForUpdate()->firstOrFail();
            if ($manual->status === VideoManualStatus::Analyzing) {
                $manual->forceFill([
                    'status' => $manual->cuts()->exists() ? VideoManualStatus::Ready : VideoManualStatus::Draft,
                ])->save();
            }

            // 予約 release (Reserved のみ。並行 commit/release 済みは LogicException → 握って冪等)
            $reservation = $locked->ticketReservation;
            if ($reservation !== null && $reservation->status === TicketReservationStatus::Reserved) {
                try {
                    $this->tickets->release($reservation);
                } catch (LogicException) {
                    // 並行 release/commit 済み
                }
            }

            return true;
        });
    }

    /**
     * stale ジョブの回復 (cron)。queued: dispatch 喪失、running: worker 異常終了。
     * failJob は行ロック + terminal guard で冪等 (TicketLedgerService::releaseStale と同型)。
     *
     * @return int 実際に回復 (failed 遷移) した件数 (走査中に terminal へ先着されたものは数えない)
     */
    public function recoverStale(): int
    {
        $threshold = CarbonImmutable::now()->subMinutes(config()->integer('manual.analysis_stale_after_minutes'));
        $staleIds = AnalysisJob::query()
            ->where(function (Builder $query) use ($threshold): void {
                $query
                    ->where(function (Builder $query) use ($threshold): void {
                        $query->where('status', JobStatus::Queued->value)
                            ->where('created_at', '<=', $threshold);
                    })
                    ->orWhere(function (Builder $query) use ($threshold): void {
                        $query->where('status', JobStatus::Running->value)
                            ->where('updated_at', '<=', $threshold);
                    });
            })
            ->pluck('id');

        $recovered = 0;
        foreach ($staleIds as $id) {
            $job = AnalysisJob::query()->whereKey($id)->first();
            if ($job === null) {
                continue;
            }
            // failJob 内で行ロック + terminal guard 再検証するため、競合したジョブはそこで no-op (false)
            if ($this->failJob($job, '解析がタイムアウトしました。再実行してください。')) {
                $recovered++;
            }
        }

        return $recovered;
    }

    /**
     * project → organization の導出 (HasOneThrough)。payload のチケット/org 値は一切受けない。
     */
    private function resolveOrganization(Project $project): Organization
    {
        $organization = $project->organization;
        Assert::isInstanceOf($organization, Organization::class, 'project は必ず組織に属する');

        return $organization;
    }
}
