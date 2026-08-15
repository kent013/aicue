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
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Billing\TicketLedgerService;
use App\Services\Notification\NotificationCenterService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Webmozart\Assert\Assert;

/**
 * AI 解析ジョブの状態機械 (trigger / failJob / failStaleJob)。doc/10 §10.8-8。
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
        private readonly NotificationCenterService $notifications,
    ) {}

    /**
     * AI 解析のトリガー (§10.8-8 冪等 + 残高事前チェック + enqueue)。
     *
     * - 実行可能状態: status ∈ {draft, ready} のみ (ready→analyzing = 再解析は正式遷移)
     * - analyze 冪等: 同一 manual の in-flight (queued/running) は 1 つ → 409
     * - 残高事前チェックは fail-fast の入口ゲート (真の残高保証は pipeline の reserve)
     * - $actor はジョブ実行者 (通知宛先の導出用)。web 経路では必ず存在するが、
     *   将来の CLI 経路に備え nullable (未指定時は triggered_by NULL = creator のみ宛先)
     */
    public function trigger(Project $project, VideoManual $manual, ?User $actor = null): AnalysisJob
    {
        $job = DB::transaction(function () use ($project, $manual, $actor): AnalysisJob {
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
            // 残高事前チェック (reserve はジョブ開始時 = §10.5。ここは fail-fast の入口ゲート)。
            // 判定は表示 clamp 済みの balance() ではなく真値 availableTrueBalance() を使う
            // (返金債務で負に振れた出所を clamp が隠すと誤判定になる)
            $organization = $this->resolveOrganization($project);
            $cost = config()->integer('manual.analysis_ticket_cost');
            $balance = $this->tickets->availableTrueBalance($organization);
            if ($balance < $cost) {
                throw InsufficientTicketsException::forReserve($cost, $balance);
            }

            $job = $locked->analysisJobs()->make();
            $job->status = JobStatus::Queued;
            $job->sourceDocument()->associate($document);
            if ($actor !== null) {
                $job->triggeredBy()->associate($actor); // Auth 導出のみ (保護キー。payload 直送は 422)
            }
            $job->save();

            $locked->forceFill(['status' => VideoManualStatus::Analyzing])->save();

            // キュー投入は**業務 tx の内側**で行う (AG-114 確定 1)。payload は job id のみ。
            // jobs 行が同一 tx に乗るため「保存済み・未投入」が構造的に消え、rollback すれば
            // jobs 行ごと巻き戻る。原子性の前提 (driver=database / キュー DB 接続 = 業務 DB /
            // after_commit=false) は QueueDispatchAtomicityGuard が起動時に fail-closed 検査する。
            RunManualAnalysis::dispatch($job->id);

            return $job;
        });

        return $job;
    }

    /**
     * ジョブの失敗確定 (冪等)。pipeline catch / Job::failed の合流点。
     *
     * - terminal (succeeded/failed) 済みは no-op (terminal tx 勝ち・二重 fail を握る)
     * - manual は analyzing のときのみ復帰 (cuts があれば ready、無ければ draft)
     * - 予約は Reserved のみ release (並行 commit/release 済みは LogicException → 握って冪等)
     *
     * @return bool 実際に failed へ遷移させたか (terminal 済み no-op は false)
     */
    public function failJob(AnalysisJob $job, string $error): bool
    {
        $failed = DB::transaction(function () use ($job, $error): bool {
            /** @var AnalysisJob $locked */
            $locked = AnalysisJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();

            return $this->failLockedJob($locked, $error);
        });

        // terminal 遷移が実際に起きたときだけ・commit 後に通知する (at-most-once。詳細設計
        // 「配信保証仕様」)。通知例外は NotificationCenterService 内 catch + report で
        // ジョブ本流を壊さない。二重 fail は上の terminal guard (false) が通知ごと握る
        if ($failed) {
            $this->notifications->notifyAnalysisFinished($job->refresh());
        }

        return $failed;
    }

    /**
     * 滞留ジョブの失敗確定 (回収経路の唯一の口)。
     *
     * **行ロックを取ったうえで滞留の述語ごと再評価する** — 候補を列挙してから
     * ロックを取るまでの間に worker が進捗を書いた running ジョブは 1 行も返らないので、
     * 正常に動いているものを失敗にしない (誤回収の防止)。
     *
     * @param  positive-int  $id  滞留回収の候補列挙 (staleJobIds) が返した主キー
     * @return bool 実際に failed へ遷移させたか
     */
    public function failStaleJob(int $id, CarbonImmutable $sweptAt): bool
    {
        $threshold = $this->staleThreshold($sweptAt);

        // 通知のためにモデルを引き直さない (クラス起点の主キークエリを 1 本増やさないため)。
        // トランザクションからロック済みモデルをそのまま返す
        $failed = DB::transaction(function () use ($id, $threshold): ?AnalysisJob {
            $locked = $this->lockStaleJob($id, $threshold);
            if ($locked === null) {
                return null; // 述語が成立しない (前進済み / terminal / 進捗が進んだ)
            }

            return $this->failLockedJob($locked, '解析がタイムアウトしました。再実行してください。')
                ? $locked
                : null;
        });

        if ($failed !== null) {
            $this->notifications->notifyAnalysisFinished($failed->refresh()); // failJob と同じ形
        }

        return $failed !== null;
    }

    /**
     * 滞留候補の主キーを昇順で返す (回収の候補列挙。述語は applyStalePredicate が唯一の正本)。
     *
     * @param  positive-int|null  $afterId
     * @param  positive-int  $pageSize
     * @return list<positive-int>
     */
    public function staleJobIds(CarbonImmutable $sweptAt, ?int $afterId, int $pageSize): array
    {
        /** @var list<positive-int> $ids */
        $ids = $this->applyStalePredicate(AnalysisJob::query(), $this->staleThreshold($sweptAt))
            ->when($afterId !== null, fn (Builder $query) => $query->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit($pageSize)
            ->pluck('id')
            ->all();

        return $ids;
    }

    /**
     * ロック済みジョブの失敗確定の本体 (failJob と failStaleJob が共有する 1 つの実装)。
     *
     * manual のロック順 (job → manual)、terminal guard、予約解放、
     * scenario_version_at_terminal の書き込みを 2 か所に複製しないためにここへ集約する。
     */
    private function failLockedJob(AnalysisJob $locked, string $error): bool
    {
        if ($locked->status->isTerminal()) {
            return false;
        }

        // manual を先に lock で取得し (job → manual のロック順を維持)、失敗確定時の
        // scenario_version を job にスナップショットする (stale alert 判定の順序基準。T032)。
        /** @var VideoManual $manual */
        $manual = VideoManual::query()->whereKey($locked->video_manual_id)->lockForUpdate()->firstOrFail();

        $locked->status = JobStatus::Failed;
        $locked->error = $error;
        $locked->scenario_version_at_terminal = $manual->scenario_version;
        $locked->save();

        // manual 復帰 (analyzing のときのみ。cuts があれば ready、無ければ draft = 概念設計 §4)
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
    }

    /**
     * id は回収の候補列挙由来。**候補列挙と同じ述語**を WHERE に入れることでロック後の再評価になる。
     *
     * @param  positive-int  $id
     */
    private function lockStaleJob(int $id, CarbonImmutable $threshold): ?AnalysisJob
    {
        return $this->applyStalePredicate(AnalysisJob::query()->whereKey($id), $threshold)
            ->lockForUpdate()
            ->first();
    }

    /** 滞留の閾値 (queued は発生時刻、running は進捗時刻を比べる相手) */
    private function staleThreshold(CarbonImmutable $sweptAt): CarbonImmutable
    {
        return $sweptAt->subMinutes(config()->integer('manual.analysis_stale_after_minutes'));
    }

    /**
     * 滞留の述語 (**この 1 か所だけが正本**)。
     * queued は発生時刻 (created_at)、running は進捗時刻 (updated_at) を起点にする。
     *
     * @param  EloquentBuilder<AnalysisJob>  $query
     * @return EloquentBuilder<AnalysisJob>
     */
    private function applyStalePredicate(EloquentBuilder $query, CarbonImmutable $threshold): EloquentBuilder
    {
        return $query->where(fn (Builder $outer) => $outer
            ->where(fn (Builder $queued) => $queued
                ->where('status', JobStatus::Queued->value)
                ->where('created_at', '<=', $threshold))
            ->orWhere(fn (Builder $running) => $running
                ->where('status', JobStatus::Running->value)
                ->where('updated_at', '<=', $threshold)));
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
