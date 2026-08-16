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
use App\Enums\Manual\VideoManualStatus;
use App\Enums\Security\ExternalCallKind;
use App\Exceptions\Billing\InsufficientTicketsException;
use App\Exceptions\Manual\JobOwnershipLostException;
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
use Illuminate\Support\Facades\Log;
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

            // ★ preflight suppression (裁定 AG-082 標準形 (2)): S3 PUT の直前で所有権を再検証する。
            //   updateProgress() という**自前の書き込みの後**に置くことが要点
            //   (書き込みの前に検証すると、書き込み中の接続断で旧担当が PUT できる窓が開く)。
            //   ffmpeg compose / S3 GET の前には置かない — ローカル CPU と冪等な読み取りであり、
            //   取り消せない外部副作用を持たないため (docs/architecture.md の残余窓 3)。
            $this->assertStillOwned($job, RenderStep::Concat);

            // upload → finalize (terminal tx)
            $this->storage->upload($composed->localPath, $manifest->outputKey);
            $uploadedKey = $manifest->outputKey;

            $result = new RenderResult(
                outputPath: $manifest->outputKey,
                clipDurationsMs: $composed->clipDurationsMs,
                totalDurationMs: $composed->totalDurationMs,
                placeholderCutCount: $manifest->placeholderCutCount(),
            );
            if ($this->finalize($job, $result)) {
                $uploadedKey = null; // succeeded に到達した出力は正 (後始末しない)
                // succeeded 到達時のみ・terminal tx の commit 後に通知 (kind=render のみ。
                // finalize が $job->refresh() 済み。preview は通知しない)
                if ($job->kind === RenderKind::Render) {
                    $this->notifications->notifyRenderFinished($job);
                }
            }
        } catch (JobOwnershipLostException $exception) {
            // preflight suppression: 既に terminal 化されている = 自分は旧担当。
            // failJob も通知もチケット release も呼ばない。$uploadedKey は null のままなので
            // finally の後始末は work dir の削除だけを行う (孤児オブジェクトを作らずに降りる)。
            // return ではなく catch で受けるのは、片付け経路 (finally) を 1 本に保つため。
            Log::warning('レンダジョブの所有権を失ったため出力アップロードを中止しました', $exception->logContext());
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

    /**
     * カット 1 枚分のクリップ仕様 (欠落は render=防御例外 / preview=Placeholder)。
     *
     * 「使用できる採用テイクがあるか」の判定は **AdoptedReadyTakeCoverage が唯一の所在**である
     * (ここで式を書き直すと render の 422 と preview の扱いが再び乖離する = bug-hunt F-1-01)。
     */
    private function clipSpecFor(RenderJob $job, Cut $cut, string $label): RenderClipSpec
    {
        if (AdoptedReadyTakeCoverage::isMissing($cut)) {
            if ($job->kind === RenderKind::Render) {
                // trigger 422 + rendering 排他により起き得ない。防御的に fail させる
                throw new LogicException("render job {$job->id}: 採用テイク欠落 ({$label})");
            }

            return new RenderClipSpec(
                cutId: $cut->id,
                label: $label,
                source: RenderClipSource::Placeholder,
                takeSourcePath: null,
                stillDisplaySeconds: null,
                subtitlePrimary: $cut->subtitle_primary,
                subtitleSecondary: $cut->subtitle_secondary,
            );
        }

        // 述語が false なら採用テイクは必ず存在する。PHPStan level 10 は静的には ?Take のままなので
        // Assert で絞る (述語の再実装ではない = TakeStatus を参照しない)。
        $take = $cut->adoptedTake;
        Assert::notNull($take, 'isMissing() が false なら採用テイクは必ず存在する');

        // 実効素材種別の式は EffectiveMaterialType が唯一の所在 (ここに書き直さない)。
        // 尺ゲート (RenderJobService) も同じ 2 クラスを呼ぶ = ゲートとレンダで尺が食い違わない
        $isStill = EffectiveMaterialType::of($cut, $take) === MaterialType::Still;

        return new RenderClipSpec(
            cutId: $cut->id,
            label: $label,
            source: $isStill ? RenderClipSource::TakeStill : RenderClipSource::TakeVideo,
            takeSourcePath: $take->video_path,
            stillDisplaySeconds: $isStill ? StillDisplayDuration::secondsFor($cut) : null,
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
        $succeeded = DB::transaction(function () use ($job, $result): bool {
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
            // 生成物の説明 (manifest 由来の実績値)。書き込み位置が finalize なのはロック順序の要請で、
            // 値が確定する buildManifest は video_manuals を先にロックしているため、そこで
            // render_jobs を UPDATE するとグローバル順 render_jobs → video_manuals の逆順取得になる。
            $locked->placeholder_cut_count = $result->placeholderCutCount;
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

            // 旧世代 output の削除投入を **terminal tx の内側**で行う (AG-114 確定 1)。
            // 削除 job は冪等のため重複無害。喪失時の回収役 (render:reconcile-outputs) は
            // 別要因 (worker 異常終了) のために残す。
            foreach ($oldJobIds as $oldJobId) {
                DeleteRenderOutputsJob::dispatch($oldJobId);
            }

            return true;
        });

        if ($succeeded) {
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
     * ローカル名から拡張子を落としている (旧: `src{$index}.mp4`)。
     * 拡張子は**以前から既に嘘**だった — `video/webm` / `video/quicktime` のテイクも
     * `.mp4` という名前で落ちており、合成は最初から **ffmpeg の内容プローブ**に依存している。
     * 画像素材を足すにあたって嘘を増やす理由が無いので、名前から拡張子ごと外す。
     * 前例は TakeThumbnailPipeline の `"{$workDir}/source"` (同じく拡張子なしで ffmpeg に渡す)。
     *
     * @return array<int, string>
     */
    private function downloadSources(RenderManifest $manifest, string $workDir): array
    {
        $localSources = [];
        foreach ($manifest->clips as $index => $clip) {
            if ($clip->takeSourcePath === null) {
                continue;
            }
            $localPath = "{$workDir}/src{$index}";
            $this->storage->downloadToLocal($clip->takeSourcePath, $localPath);
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
     * 所有権の再検証 (preflight suppression)。AnalysisPipeline と同型
     * (§10.8 方針: 共通抽象化しない。個別実装を見本に合わせる)。
     *
     * 所有権 = (行の主キー, `running`)。行が消えている (null) 場合も所有権喪失として扱う
     * (deny-by-default)。
     *
     * @throws JobOwnershipLostException
     */
    private function assertStillOwned(RenderJob $job, RenderStep $step): void
    {
        $current = RenderJob::query()->whereKey($job->getKey())->first();
        if ($current !== null && $current->status === JobStatus::Running) {
            return; // アーリーリターン (正常系)
        }

        throw JobOwnershipLostException::whileRunning(
            jobType: RenderJob::class,
            jobId: $job->id,
            actualStatus: $current?->status,
            stage: $step->value,
            externalCall: ExternalCallKind::ObjectStoragePut,
        );
    }

    /**
     * step/progress の表示用更新 (AnalysisPipeline::writeProgress と同型)。
     *
     * ★ **条件付き UPDATE (`where status=running`)**。compose は最大 25 分走り、
     *   `onClipComposed()` から高頻度に呼ばれるため、terminal 化後の書き戻しが
     *   最も起きやすい経路である (「failed なのに progress=62」を作らない)。
     * ★ `Builder::update()` は `updated_at` を自動付与する (stale 判定の
     *   「最終 step 更新時刻」という意味は従来どおり。ただし terminal 行では動かない)。
     * ★ AnalysisPipeline::writeProgress と違い cast の正規化を挟まないのは、ここで書く 2 列が
     *   **cast 適用後と同一表現のスカラー** (`RenderStep` の backing value と int) だけだからである。
     *   配列 / 日時など cast で表現が変わる列をここへ足すときは、あちらと同じく
     *   `forceFill()->getAttributes()` を通すこと。
     */
    private function updateProgress(RenderJob $job, RenderStep $step, int $progress): void
    {
        RenderJob::query()
            ->whereKey($job->getKey())
            ->where('status', JobStatus::Running->value)
            ->update(['step' => $step->value, 'progress' => $progress]);
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
