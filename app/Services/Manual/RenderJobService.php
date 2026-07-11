<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\DataTransferObjects\Manual\Render\OrderedCut;
use App\DataTransferObjects\Manual\Render\RenderResult;
use App\Enums\Billing\TicketReservationStatus;
use App\Enums\Manual\JobStatus;
use App\Enums\Manual\MaterialType;
use App\Enums\Manual\RenderConflictType;
use App\Enums\Manual\RenderErrorCode;
use App\Enums\Manual\RenderKind;
use App\Enums\Manual\TakeStatus;
use App\Enums\Manual\VideoManualStatus;
use App\Exceptions\Billing\InsufficientTicketsException;
use App\Exceptions\Manual\RenderConflictException;
use App\Jobs\Manual\DeleteRenderOutputsJob;
use App\Jobs\Manual\RunManualRender;
use App\Models\Organization;
use App\Models\Project;
use App\Models\RenderJob;
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
 * レンダジョブの状態機械 (trigger / triggerPreview / failJob / recoverStale)。doc/10 §10.8-8。
 * AnalysisJobService を見本にした個別実装 (§10.8 方針: 共通抽象化しない)。
 *
 * VideoManualStatus 遷移表 (本サービスが関与する遷移。詳細は docs/architecture.md):
 * - ready → rendering: trigger() (行ロック + from-state guard。violate → 409)
 * - rendering → ready: failJob() (kind=render かつ rendering のときのみ復帰)
 * - rendering → published: completeRenderIntoLockedManual() (RenderPipeline::finalize の
 *   terminal tx 内のみ。経路は ScenarioWritePathInventoryTest 検出 5 が固定)
 * (preview は manual status を一切触らない = 編集と並走する)
 *
 * 共有ロック規約: status を書く経路は必ず VideoManual 行を lockForUpdate() した
 * 同一 tx 内で反映する (ScenarioWritePathInventoryTest が経路を deny-by-default で固定)。
 *
 * グローバルロック順 (正本: docs/architecture.md のロック順序節。以下は参考転記):
 *   render_jobs → video_manuals → ticket_reservations → organizations
 */
class RenderJobService
{
    public function __construct(
        private readonly TicketLedgerService $tickets,
        private readonly NotificationCenterService $notifications,
    ) {}

    /**
     * 完成レンダのトリガー (§10.8-8 冪等 + 残高事前チェック + enqueue)。
     *
     * - 実行可能状態: ready のみ (published は編集で ready に戻してから = §10.8-6)
     * - render 冪等: 同一 manual の in-flight kind=render は 1 つ → 409 (preview は妨げない)
     * - 採用テイク欠落は 422 (スキップしない: 標準化された成果物の完全性)
     * - 尺上限ソフトゲート 422 (§10.8-1: TTL 内 commit)・残高事前チェック 402
     * - $actor はジョブ実行者 (通知宛先の導出用)。web 経路では必ず存在するが、
     *   将来の CLI 経路に備え nullable (未指定時は triggered_by NULL = creator のみ宛先)
     */
    public function trigger(Project $project, VideoManual $manual, ?User $actor = null): RenderJob
    {
        $job = DB::transaction(function () use ($project, $manual, $actor): RenderJob {
            // 共有ロック規約: status を書くため VideoManual 行ロック (親 relation 経由 = 子∈親も担保)
            /** @var VideoManual $locked */
            $locked = $project->manuals()->whereKey($manual->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== VideoManualStatus::Ready) {
                throw new RenderConflictException(RenderConflictType::StatusNotRenderable);
            }
            if ($this->hasInFlight($locked, RenderKind::Render)) {
                throw new RenderConflictException(RenderConflictType::InFlight);
            }

            $ordered = CutSequencer::orderedWithLabels($locked);
            $this->assertAllCutsHaveAdoptedReadyTakes($ordered);
            $this->assertTotalSourceDurationWithinLimit($ordered);

            // 残高事前チェック (reserve はジョブ開始時 = §10.5。ここは fail-fast の入口ゲート)
            $cost = config()->integer('manual.render_ticket_cost');
            $balance = $this->tickets->balance($this->resolveOrganization($project));
            if ($balance < $cost) {
                throw InsufficientTicketsException::forReserve($cost, $balance);
            }

            // job 作成 (kind/status/scenario_version は明示代入) + rendering へ
            $job = $locked->renderJobs()->make();
            $job->kind = RenderKind::Render;
            $job->status = JobStatus::Queued;
            $job->scenario_version = $locked->scenario_version; // §10.8-6 スナップショット
            if ($actor !== null) {
                $job->triggeredBy()->associate($actor); // Auth 導出のみ (保護キー。payload 直送は 422)
            }
            $job->save();

            $locked->forceFill(['status' => VideoManualStatus::Rendering])->save();

            return $job;
        });

        // commit 後に dispatch (payload は job id のみ。dispatch 喪失は recoverStale が回収)
        RunManualRender::dispatch($job->id);

        return $job;
    }

    /**
     * プレビューのトリガー (チケット非消費・manual status 遷移なし・org 上限直列化)。
     *
     * org 同時 preview 上限は Organization 行ロックで直列化する (reserve と同じ手法。
     * ロック順 video_manuals → organizations はグローバル順の部分列)。
     */
    public function triggerPreview(Project $project, VideoManual $manual, ?User $actor = null): RenderJob
    {
        $job = DB::transaction(function () use ($project, $manual, $actor): RenderJob {
            /** @var VideoManual $locked */
            $locked = $project->manuals()->whereKey($manual->id)->lockForUpdate()->firstOrFail();

            // 状態 guard: analyzing / rendering は 409 (cuts が動く最中)。draft は cuts 不在 = 422
            if (in_array($locked->status, [VideoManualStatus::Analyzing, VideoManualStatus::Rendering], true)) {
                throw new RenderConflictException(RenderConflictType::StatusNotPreviewable);
            }
            if (! $locked->cuts()->exists()) {
                throw ValidationException::withMessages(['scenario' => ['シナリオがありません。']]);
            }
            // preview 冪等 (同一 manual の in-flight preview は 1 つ)
            if ($this->hasInFlight($locked, RenderKind::Preview)) {
                throw new RenderConflictException(RenderConflictType::InFlight);
            }

            // org 上限: Organization 行ロックで直列化 (reserve の残高判定と同じ直列化点)
            $organization = $this->resolveOrganization($project);
            Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $limit = config()->integer('manual.render_max_inflight_previews_per_org');
            if ($this->orgInFlightPreviewCount($organization) >= $limit) {
                throw new RenderConflictException(RenderConflictType::OrgPreviewLimit);
            }

            $job = $locked->renderJobs()->make();
            $job->kind = RenderKind::Preview;
            $job->status = JobStatus::Queued;
            $job->scenario_version = $locked->scenario_version;
            if ($actor !== null) {
                $job->triggeredBy()->associate($actor); // Auth 導出のみ (preview は通知対象外だが監査用に記録)
            }
            $job->save();

            return $job; // manual status は変更しない (編集と並走)
        });

        RunManualRender::dispatch($job->id);

        return $job;
    }

    /**
     * ジョブの失敗確定 (冪等)。pipeline catch / Job::failed / recoverStale の合流点。
     *
     * - terminal (succeeded/failed) 済みは no-op (terminal tx 勝ち・二重 fail を握る)
     * - kind=render のみ: manual が rendering のときのみ ready へ復帰
     *   (render は ready からのみ始まるため cuts は必ず存在。preview は status を触らない)
     * - 予約は Reserved のみ release (並行 commit/release 済みは LogicException → 握って冪等)
     *
     * @return bool 実際に failed へ遷移させたか (terminal 済み no-op は false)
     */
    public function failJob(RenderJob $job, RenderErrorCode $code, string $error): bool
    {
        $failed = DB::transaction(function () use ($job, $code, $error): bool {
            /** @var RenderJob $locked */
            $locked = RenderJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status->isTerminal()) {
                return false;
            }

            $locked->status = JobStatus::Failed;
            $locked->error = $error;
            $locked->error_code = $code;
            $locked->save();

            // manual 復帰 (kind=render かつ rendering のときのみ。preview は status を触らない)
            if ($locked->kind === RenderKind::Render) {
                /** @var VideoManual $manual */
                $manual = VideoManual::query()->whereKey($locked->video_manual_id)->lockForUpdate()->firstOrFail();
                if ($manual->status === VideoManualStatus::Rendering) {
                    $manual->forceFill(['status' => VideoManualStatus::Ready])->save();
                }
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

        // terminal 遷移が実際に起きたときだけ・commit 後に通知する (kind=render のみ。
        // preview はノイズ・status 遷移も無いため通知しない。at-most-once = 詳細設計「配信保証仕様」。
        // 通知例外は NotificationCenterService 内 catch + report でジョブ本流を壊さない)
        if ($failed) {
            $job->refresh();
            if ($job->kind === RenderKind::Render) {
                $this->notifications->notifyRenderFinished($job);
            }
        }

        return $failed;
    }

    /**
     * finalize 専用: ロック済み manual へ cut_length_ms / total_length_ms / published を反映する。
     *
     * **ロック済み前提メソッド** (ScenarioService::materializeIntoLockedManual と同じ二層防御):
     * 1. runtime 検査: tx 外呼び出し / rendering 以外は LogicException
     *    (terminal tx ごと rollback → failJob)
     * 2. 経路の構造的限定: 宣言は本クラスのみ・呼び出しは RenderPipeline のみ
     *    (ScenarioWritePathInventoryTest 検出 5 が deny-by-default で機械検証する)
     */
    public function completeRenderIntoLockedManual(VideoManual $lockedManual, RenderResult $result): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('completeRender はロック済みトランザクション内からのみ呼び出せます');
        }
        if ($lockedManual->status !== VideoManualStatus::Rendering) {
            throw new LogicException('completeRender は rendering 中のみ実行できます');
        }

        foreach ($result->clipDurationsMs as $cutId => $durationMs) {
            // manifest 由来の cut id を relation 経由で再解決 (rendering 中は保存 409 のため
            // 編集済みは起き得ないが、防御的に whereKey→update のみ)
            $lockedManual->cuts()->whereKey($cutId)->update(['cut_length_ms' => $durationMs]);
        }

        $lockedManual->forceFill([
            'total_length_ms' => $result->totalDurationMs,
            'status' => VideoManualStatus::Published,
        ])->save();
    }

    /**
     * stale ジョブの回復 (cron)。queued と running で閾値を分ける (概念設計 §5):
     * - queued: created_at が render_queued_stale_after_minutes (10 分) 超過
     *   (dispatch 喪失。render は enqueue 時点で編集を止めるため短 SLA で fail させる)
     * - running: updated_at が render_stale_after_minutes (30 分) 超過 (worker 異常終了)
     *
     * @return int 実際に回復 (failed 遷移) した件数
     */
    public function recoverStale(): int
    {
        $queuedThreshold = CarbonImmutable::now()
            ->subMinutes(config()->integer('manual.render_queued_stale_after_minutes'));
        $runningThreshold = CarbonImmutable::now()
            ->subMinutes(config()->integer('manual.render_stale_after_minutes'));

        $staleIds = RenderJob::query()
            ->where(function (Builder $query) use ($queuedThreshold, $runningThreshold): void {
                $query
                    ->where(function (Builder $query) use ($queuedThreshold): void {
                        $query->where('status', JobStatus::Queued->value)
                            ->where('created_at', '<=', $queuedThreshold);
                    })
                    ->orWhere(function (Builder $query) use ($runningThreshold): void {
                        $query->where('status', JobStatus::Running->value)
                            ->where('updated_at', '<=', $runningThreshold);
                    });
            })
            ->pluck('id');

        $recovered = 0;
        foreach ($staleIds as $id) {
            $job = RenderJob::query()->whereKey($id)->first();
            if ($job === null) {
                continue;
            }
            // failJob 内で行ロック + terminal guard 再検証するため、競合したジョブはそこで no-op (false)
            if ($this->failJob($job, RenderErrorCode::Timeout, '書き出しがタイムアウトしました。再実行してください。')) {
                $recovered++;
            }
        }

        return $recovered;
    }

    /**
     * 出力世代の収束 (reconciliation。概念設計 §5 の (b) 系統)。
     * 「output_path 非 NULL かつ同 manual・同 kind により新しい succeeded job が存在する」
     * render_jobs を走査し DeleteRenderOutputsJob を再投入する (削除 job は冪等のため重複無害)。
     *
     * @return array{dispatched: int, skipped: int} 可観測性: 件数推移で「削除が進まない」異常を検知する
     */
    public function reconcileOutputs(): array
    {
        $candidateIds = RenderJob::query()->whereNotNull('output_path')->pluck('id');

        $dispatched = 0;
        $skipped = 0;
        foreach ($candidateIds as $id) {
            $job = RenderJob::query()->whereKey($id)->first();
            if ($job === null || $job->output_path === null) {
                $skipped++;

                continue;
            }
            if (! $this->newerSucceededExists($job)) {
                $skipped++; // 最新世代 (または唯一の出力) は削除しない

                continue;
            }
            DeleteRenderOutputsJob::dispatch($job->id);
            $dispatched++;
        }

        return ['dispatched' => $dispatched, 'skipped' => $skipped];
    }

    /** 同 manual・同 kind でより新しい succeeded job が存在するか (世代交代済み判定) */
    public function newerSucceededExists(RenderJob $job): bool
    {
        return RenderJob::query()
            ->where('video_manual_id', $job->video_manual_id)
            ->where('kind', $job->kind->value)
            ->where('status', JobStatus::Succeeded->value)
            ->where('id', '>', $job->id)
            ->exists();
    }

    /** 同一 manual・同一 kind の in-flight (queued/running) job が存在するか */
    private function hasInFlight(VideoManual $locked, RenderKind $kind): bool
    {
        return $locked->renderJobs()
            ->where('kind', $kind->value)
            ->whereIn('status', [JobStatus::Queued->value, JobStatus::Running->value])
            ->exists();
    }

    /**
     * 採用テイク検証 (欠落 = 422。スキップしない: 標準化された成果物の完全性)。
     * adopted_take_id NULL または採用テイクが ready でないカットの表示ラベル一覧を message に含める。
     *
     * @param  list<OrderedCut>  $ordered
     */
    private function assertAllCutsHaveAdoptedReadyTakes(array $ordered): void
    {
        $missing = [];
        foreach ($ordered as $entry) {
            $take = $entry->cut->adoptedTake;
            if ($take === null || $take->status !== TakeStatus::Ready) {
                $missing[] = $entry->label;
            }
        }
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'takes' => ['採用テイクが未設定のカットがあります: '.implode('、', $missing)],
            ]);
        }
    }

    /**
     * 尺上限ソフトゲート (§10.8-1: TTL 内 commit)。クライアント申告値ベースで、
     * ハード保証はジョブ timeout が担う。duration_ms NULL は保守的な既定尺で代用する。
     *
     * @param  list<OrderedCut>  $ordered
     */
    private function assertTotalSourceDurationWithinLimit(array $ordered): void
    {
        $defaultMs = config()->integer('manual.render_default_take_duration_ms');
        $totalMs = 0;
        foreach ($ordered as $entry) {
            $cut = $entry->cut;
            if ($cut->material_type === MaterialType::Still && $cut->static_display_seconds !== null) {
                $totalMs += $cut->static_display_seconds * 1000;

                continue;
            }
            $totalMs += $cut->adoptedTake->duration_ms ?? $defaultMs;
        }

        if ($totalMs > config()->integer('manual.render_max_total_source_ms')) {
            throw ValidationException::withMessages([
                'takes' => ['動画の合計尺が上限を超えています。マニュアルを分割してください。'],
            ]);
        }
    }

    /** org 配下の in-flight preview 数 (cross-org を作らないため relation 経由の whereHas のみ) */
    private function orgInFlightPreviewCount(Organization $organization): int
    {
        return RenderJob::query()
            ->where('kind', RenderKind::Preview->value)
            ->whereIn('status', [JobStatus::Queued->value, JobStatus::Running->value])
            ->whereHas(
                'videoManual.project.organization',
                fn (EloquentBuilder $query): EloquentBuilder => $query->whereKey($organization->getKey()),
            )
            ->count();
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
