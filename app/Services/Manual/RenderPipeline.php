<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\DataTransferObjects\Manual\Render\RenderClipSource;
use App\DataTransferObjects\Manual\Render\RenderClipSpec;
use App\DataTransferObjects\Manual\Render\RenderManifest;
use App\DataTransferObjects\Manual\Render\RenderResult;
use App\Enums\Billing\TicketReservationStatus;
use App\Enums\Manual\JobStatus;
use App\Enums\Manual\MaterialType;
use App\Enums\Manual\RenderErrorCode;
use App\Enums\Manual\RenderKind;
use App\Enums\Manual\RenderStep;
use App\Enums\Manual\TakeStatus;
use App\Enums\Manual\VideoManualStatus;
use App\Exceptions\Billing\InsufficientTicketsException;
use App\Exceptions\Manual\RenderScenarioChangedException;
use App\Jobs\Manual\DeleteRenderOutputsJob;
use App\Models\Cut;
use App\Models\Organization;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\VideoManual;
use App\Services\Billing\TicketLedgerService;
use App\Services\Notification\NotificationCenterService;
use App\Services\Render\RenderObjectStorage;
use App\Services\Render\VideoComposer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use LogicException;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * レンダパイプライン本体 (startJob → buildManifest → compose → upload → finalize)。概念設計 §5。
 * AnalysisPipeline を見本にした個別実装 (§10.8 方針: 共通抽象化しない)。
 *
 * - チケット 2 フェーズ (render のみ): startJob で reserve (冪等キー =
 *   render_jobs.ticket_reservation_id)、terminal tx (finalize) で complete + commit + succeeded を
 *   原子化 (無課金 succeeded / 課金済み failed を構造的に排除)
 * - version スナップショット固定 (§10.8-6): buildManifest が読み取り一貫性の確定点。
 *   以後 ffmpeg 実行中に cuts / takes が変わっても参照しない
 * - 失敗は catch → RenderJobService::failJob (行ロック + terminal guard で冪等)
 *
 * グローバルロック順 (**正本は docs/architecture.md のロック順序節**。以下は参考転記であり、
 * 乖離時は正本を優先する):
 *   render_jobs → video_manuals → ticket_reservations → organizations
 * 各経路の取得列 (すべてグローバル順の部分列 = 逆順取得ゼロで循環待ちを構成できない):
 *   - RenderJobService::trigger:        video_manuals のみ (balance() はロックなし集計)
 *   - RenderJobService::triggerPreview: video_manuals → organizations
 *   - RenderPipeline::startJob:         render_jobs → (render のみ reserve 内部: organizations)
 *   - RenderPipeline::buildManifest:    video_manuals (読み取り一貫性の確定点)
 *   - RenderPipeline::finalize:         render_jobs → video_manuals →
 *                                       (render のみ commit 内部: ticket_reservations → organizations)
 *   - RenderJobService::failJob:        render_jobs → video_manuals →
 *                                       (release 内部: ticket_reservations → organizations)
 *   - DeleteRenderOutputsJob::handle:   行ロックなし (読み取り検証 → tx 外 S3 削除 → CAS update の 3 段)
 */
class RenderPipeline
{
    public function __construct(
        private readonly RenderJobService $jobs,
        private readonly VideoComposer $composer,
        private readonly RenderObjectStorage $storage,
        private readonly TicketLedgerService $tickets,
        private readonly NotificationCenterService $notifications,
    ) {}

    public function run(int $renderJobId): void
    {
        $job = RenderJob::query()->findOrFail($renderJobId);
        $workDir = null;
        $uploadedKey = null;

        try {
            if (! $this->startJob($job)) {
                return; // 重複配送 / stale 回復後の遅延配送 → no-op
            }

            $manifest = $this->buildManifest($job);

            // compose (DB 外・ロック外)
            $workDir = $this->makeWorkDir($job);
            $localSources = $this->downloadSources($manifest, $workDir);
            $composed = $this->composer->compose(
                $manifest,
                $localSources,
                $workDir,
                fn (int $composedClips, int $totalClips) => $this->onClipComposed($job, $composedClips, $totalClips),
            );
            $this->updateProgress($job, RenderStep::Concat, 90);

            // upload → finalize (terminal tx)
            $this->storage->upload($composed->localPath, $manifest->outputKey);
            $uploadedKey = $manifest->outputKey;

            $result = new RenderResult(
                outputPath: $manifest->outputKey,
                clipDurationsMs: $composed->clipDurationsMs,
                totalDurationMs: $composed->totalDurationMs,
            );
            if ($this->finalize($job, $result)) {
                $uploadedKey = null; // succeeded に到達した出力は正 (後始末しない)
                // succeeded 到達時のみ・terminal tx の commit 後に通知 (kind=render のみ。
                // finalize が $job->refresh() 済み。preview は通知しない)
                if ($job->kind === RenderKind::Render) {
                    $this->notifications->notifyRenderFinished($job);
                }
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->jobs->failJob($job, $this->errorCodeFor($exception), $this->userMessageFor($exception));
        } finally {
            // アップロード済みで succeeded 未達 (失敗 / stale 先勝ち) の出力はベストエフォート削除
            if ($uploadedKey !== null) {
                try {
                    $this->storage->delete($uploadedKey);
                } catch (Throwable $cleanupException) {
                    report($cleanupException); // 孤児オブジェクトは reconcile 対象外のため記録だけ残す
                }
            }
            if ($workDir !== null) {
                File::deleteDirectory($workDir);
            }
        }
    }

    /** 開始 tx: queued guard + (render のみ) 予約の冪等確保 (§10.8-1) + running へ */
    private function startJob(RenderJob $job): bool
    {
        return DB::transaction(function () use ($job): bool {
            /** @var RenderJob $locked */
            $locked = RenderJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== JobStatus::Queued) {
                return false; // 重複配送 guard
            }

            if ($locked->kind === RenderKind::Render) {
                $organization = $this->resolveOrganization($locked);
                $this->ensureReservation($locked, $organization); // 残高不足はここで throw → catch → failJob
            }

            $locked->status = JobStatus::Running;
            $locked->step = RenderStep::Compose;
            $locked->progress = 5;
            $locked->save();
            $job->refresh();

            return true;
        });
    }

    /**
     * 予約の冪等確保: 有効な Reserved があれば再利用 (再試行で二重予約しない)。
     * 失効済み Reserved は明示 release して付け替え、Released/Committed/なしは新規 reserve。
     */
    private function ensureReservation(RenderJob $locked, Organization $organization): void
    {
        $reservation = $locked->ticketReservation;
        if ($reservation !== null
            && $reservation->status === TicketReservationStatus::Reserved
            && $reservation->expires_at->isFuture()) {
            return; // 再利用 (再試行で二重予約しない)
        }
        if ($reservation !== null && $reservation->status === TicketReservationStatus::Reserved) {
            // 失効済みだが cron 未回収の Reserved → 明示 release して付け替え (§10.8-1)
            try {
                $this->tickets->release($reservation);
            } catch (LogicException) {
                // 並行 release 済み
            }
        }
        $cost = config()->integer('manual.render_ticket_cost');
        $new = $this->tickets->reserve($organization, $cost); // 不足は InsufficientTicketsException
        $locked->ticketReservation()->associate($new);
        $locked->save();
    }

    /**
     * マニフェスト構築 tx (読み取り一貫性の確定点。version 固定 §10.8-6)。
     * - version 不一致 → RenderScenarioChangedException → failJob(scenario_version_changed)
     * - preview 追加 guard: analyzing/rendering に遷移していたら同例外 (トリガー後の競合防御)
     * - render の採用テイク欠落は起き得ない (trigger 422 + rendering 中 adopt/delete 409) が
     *   防御的に例外 → failJob。preview は欠落 cut を Placeholder に落とす
     */
    private function buildManifest(RenderJob $job): RenderManifest
    {
        return DB::transaction(function () use ($job): RenderManifest {
            $project = $this->resolveProject($job);
            /** @var VideoManual $lockedManual */
            $lockedManual = $project->manuals()
                ->whereKey($job->video_manual_id)->lockForUpdate()->firstOrFail();

            if ($lockedManual->scenario_version !== $job->scenario_version) {
                throw RenderScenarioChangedException::versionMismatch();
            }
            if ($job->kind === RenderKind::Preview
                && in_array($lockedManual->status, [VideoManualStatus::Analyzing, VideoManualStatus::Rendering], true)) {
                throw RenderScenarioChangedException::manualBusy();
            }

            $clips = [];
            foreach (CutSequencer::orderedWithLabels($lockedManual) as $entry) {
                $clips[] = $this->clipSpecFor($job, $entry->cut, $entry->label);
            }
            if ($clips === []) {
                throw new LogicException("render job {$job->id}: クリップが 0 件です (cuts 不在)");
            }

            return new RenderManifest(
                renderJobId: $job->id,
                kind: $job->kind,
                scenarioVersion: $job->scenario_version,
                outputKey: $this->outputKeyFor($lockedManual, $job),
                clips: $clips,
            );
        });
    }

    /** カット 1 枚分のクリップ仕様 (欠落は render=防御例外 / preview=Placeholder) */
    private function clipSpecFor(RenderJob $job, Cut $cut, string $label): RenderClipSpec
    {
        $take = $cut->adoptedTake;
        if ($take === null || $take->status !== TakeStatus::Ready) {
            if ($job->kind === RenderKind::Render) {
                // trigger 422 + rendering 排他により起き得ない。防御的に fail させる
                throw new LogicException("render job {$job->id}: 採用テイク欠落 ({$label})");
            }

            return new RenderClipSpec(
                cutId: $cut->id,
                label: $label,
                source: RenderClipSource::Placeholder,
                takeVideoPath: null,
                stillDisplaySeconds: null,
                subtitlePrimary: $cut->subtitle_primary,
                subtitleSecondary: $cut->subtitle_secondary,
            );
        }

        $isStill = $cut->material_type === MaterialType::Still;

        return new RenderClipSpec(
            cutId: $cut->id,
            label: $label,
            source: $isStill ? RenderClipSource::TakeStill : RenderClipSource::TakeVideo,
            takeVideoPath: $take->video_path,
            stillDisplaySeconds: $isStill
                ? ($cut->static_display_seconds ?? config()->integer('manual.preview_placeholder_seconds'))
                : null,
            subtitlePrimary: $cut->subtitle_primary,
            subtitleSecondary: $cut->subtitle_secondary,
        );
    }

    /**
     * terminal tx: complete + commit + succeeded を原子化 (概念設計 §5-6)。
     *
     * @return bool succeeded に到達したか (stale 回復先勝ちなら false = 出力を後始末する)
     */
    private function finalize(RenderJob $job, RenderResult $result): bool
    {
        /** @var list<int> $oldJobIds */
        $oldJobIds = [];
        $succeeded = DB::transaction(function () use ($job, $result, &$oldJobIds): bool {
            // ロック 1: job 行 (stale 回復 cron との直列化点)
            /** @var RenderJob $locked */
            $locked = RenderJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== JobStatus::Running) {
                return false; // stale 回復 cron が先勝ち → complete も commit もしない (無課金 succeeded 排除)
            }

            // ロック 2: manual 行 (共有ロック規約。親 relation 経由再解決 = 子∈親も担保)
            $project = $this->resolveProject($locked);
            /** @var VideoManual $lockedManual */
            $lockedManual = $project->manuals()
                ->whereKey($locked->video_manual_id)->lockForUpdate()->firstOrFail();

            if ($locked->kind === RenderKind::Render) {
                // 防御的再検証 (違反 → LogicException → 全体 rollback → failJob)
                if ($lockedManual->status !== VideoManualStatus::Rendering
                    || $lockedManual->scenario_version !== $locked->scenario_version) {
                    throw new LogicException(
                        "render job {$locked->id}: finalize guard 違反 (status/version が rendering 開始時と不一致)",
                    );
                }
                // cut_length_ms / total_length_ms / published はロック済み manual 前提メソッドで反映
                $this->jobs->completeRenderIntoLockedManual($lockedManual, $result);

                // ロック 3: reservation/org 行 (TicketLedgerService::commit 内部)。
                // commit-wins: TTL 超過や stale releaser 先着 (Released) でも生存 hold は課金する
                // (二重課金は consume:{id} の UNIQUE が防ぐ)。失効 monthly hold のみ no-charge。
                // 戻り値 (TicketCommitResult) は可観測性のためのもので分岐には使わない
                $reservation = $locked->ticketReservation;
                Assert::notNull($reservation, 'startJob が必ず予約を付けている');
                $this->tickets->commit($reservation);
            }

            $locked->status = JobStatus::Succeeded;
            $locked->progress = 100;
            $locked->output_path = $result->outputPath;
            $locked->save();

            // 旧世代 (同 manual・同 kind・output_path 非 NULL・id < 自分の succeeded)
            $oldJobIds = RenderJob::query()
                ->where('video_manual_id', $locked->video_manual_id)
                ->where('kind', $locked->kind->value)
                ->where('status', JobStatus::Succeeded->value)
                ->whereNotNull('output_path')
                ->where('id', '<', $locked->id)
                ->get()
                ->map(static fn (RenderJob $old): int => $old->id)
                ->all();

            return true;
        });

        if ($succeeded) {
            // dispatch は commit 後 (喪失は render:reconcile-outputs が回収)
            foreach ($oldJobIds as $oldJobId) {
                DeleteRenderOutputsJob::dispatch($oldJobId);
            }
            $job->refresh();
        }

        return $succeeded;
    }

    /**
     * S3 出力キー (version 付き = 再実行安全。doc/09 §9.7)。
     * render: .../renders/v{version}-{jobId}.mp4 / preview: .../previews/v{version}-{jobId}.mp4
     */
    private function outputKeyFor(VideoManual $manual, RenderJob $job): string
    {
        $prefix = $this->storage->keyPrefixFor($manual);
        $directory = $job->kind === RenderKind::Render ? 'renders' : 'previews';

        return "{$prefix}{$directory}/v{$job->scenario_version}-{$job->id}.mp4";
    }

    /**
     * S3 から採用テイク素材を work dir へ取得する (cutId => local path。Placeholder cut は不在)。
     *
     * @return array<int, string>
     */
    private function downloadSources(RenderManifest $manifest, string $workDir): array
    {
        $localSources = [];
        foreach ($manifest->clips as $index => $clip) {
            if ($clip->takeVideoPath === null) {
                continue;
            }
            $localPath = "{$workDir}/src{$index}.mp4";
            $this->storage->downloadToLocal($clip->takeVideoPath, $localPath);
            $localSources[$clip->cutId] = $localPath;
        }

        return $localSources;
    }

    /** 作業ディレクトリ (storage/app/render/{jobId}。finally で必ず削除する) */
    private function makeWorkDir(RenderJob $job): string
    {
        $workDir = storage_path("app/render/{$job->id}");
        File::ensureDirectoryExists($workDir);

        return $workDir;
    }

    /** compose 進捗 (クリップ数比で 5→80) */
    private function onClipComposed(RenderJob $job, int $composedClips, int $totalClips): void
    {
        $progress = $totalClips > 0
            ? 5 + intdiv(75 * $composedClips, $totalClips)
            : 80;
        $this->updateProgress($job, RenderStep::Compose, $progress);
    }

    /**
     * step/progress の表示用更新 (tx 不要の単発 update。状態機械は status のみが真実源。
     * updated_at の更新が stale 判定の「最終 step 更新時刻」を兼ねる)。
     */
    private function updateProgress(RenderJob $job, RenderStep $step, int $progress): void
    {
        $job->step = $step;
        $job->progress = $progress;
        $job->save();
    }

    /** job → manual → project の導出 (payload 不信任。DB から relation 経由で再解決) */
    private function resolveProject(RenderJob $job): Project
    {
        $project = $job->videoManual?->project;
        Assert::isInstanceOf($project, Project::class, 'render job は必ず project 配下の manual に属する');

        return $project;
    }

    /** job → manual → project → organization の導出 */
    private function resolveOrganization(RenderJob $job): Organization
    {
        $organization = $this->resolveProject($job)->organization;
        Assert::isInstanceOf($organization, Organization::class, 'project は必ず組織に属する');

        return $organization;
    }

    /** 例外型 → error_code (timeout は RunManualRender::failed() フックが付与する) */
    private function errorCodeFor(Throwable $exception): RenderErrorCode
    {
        return match (true) {
            $exception instanceof RenderScenarioChangedException => RenderErrorCode::ScenarioVersionChanged,
            default => RenderErrorCode::Internal,
        };
    }

    /** ユーザー向けエラー文言 (内部詳細を error 列に漏らさない) */
    private function userMessageFor(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof RenderScenarioChangedException,
            $exception instanceof InsufficientTicketsException => $exception->getMessage(),
            default => '書き出しに失敗しました。時間をおいて再実行してください。',
        };
    }
}
