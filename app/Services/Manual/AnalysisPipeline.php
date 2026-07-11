<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\DataTransferObjects\Manual\Analysis\ExtractedSopData;
use App\DataTransferObjects\Manual\Analysis\ExtractedText;
use App\DataTransferObjects\Manual\Analysis\GeneratedScenarioData;
use App\DataTransferObjects\Manual\Analysis\WorkDecompositionData;
use App\Enums\Billing\TicketReservationStatus;
use App\Enums\Manual\AnalysisStep;
use App\Enums\Manual\JobStatus;
use App\Exceptions\Billing\InsufficientTicketsException;
use App\Exceptions\Manual\AnalysisFailedException;
use App\Exceptions\Manual\LlmOutputInvalidException;
use App\Models\AnalysisJob;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SourceDocument;
use App\Models\VideoManual;
use App\Prompts\ScenarioGenerationPrompt;
use App\Prompts\SopExtractPrompt;
use App\Prompts\WorkDecompositionPrompt;
use App\Services\Billing\TicketLedgerService;
use App\Services\Notification\NotificationCenterService;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * AI 解析パイプライン本体 (extract → decompose → generate → materialize)。概念設計 §4。
 *
 * - チケット 2 フェーズ: startJob で reserve (冪等キー = analysis_jobs.ticket_reservation_id)、
 *   terminal tx (finalize) で materialize + commit + succeeded を原子化
 *   (無課金 succeeded / 課金済み failed を構造的に排除)
 * - LLM 出力の有界リトライ: JSON 検証失敗 (LlmOutputInvalidException) のみ最大
 *   config manual.analysis_llm_max_retries 回再試行
 * - 失敗は catch → AnalysisJobService::failJob (行ロック + terminal guard で冪等)
 */
class AnalysisPipeline
{
    public function __construct(
        private readonly AnalysisJobService $jobs,
        private readonly ScenarioService $scenarios,
        private readonly SopTextExtractor $extractor,
        private readonly TicketLedgerService $tickets,
        private readonly NotificationCenterService $notifications,
    ) {}

    public function run(int $analysisJobId): void
    {
        $job = AnalysisJob::query()->findOrFail($analysisJobId);
        try {
            if (! $this->startJob($job)) {
                return; // 重複配送 / stale 回復後の遅延配送 → no-op
            }
            $document = $job->sourceDocument;
            Assert::notNull($document, 'trigger が必ず associate している');

            $text = $this->extractor->extract($document);
            $extracted = $this->runExtractStep($job, $document, $text);
            $decomposition = $this->runDecomposeStep($job, $extracted);
            $generated = $this->runGenerateStep($job, $decomposition);
            if ($this->finalize($job, $generated)) {
                // succeeded 到達時のみ・terminal tx の commit 後に通知 (stale 先勝ち false は通知しない)
                $this->notifications->notifyAnalysisFinished($job->refresh());
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->jobs->failJob($job, $this->userMessageFor($exception));
        }
    }

    /** 開始 tx: queued guard + 予約の冪等確保 (§10.8-1) + running へ */
    private function startJob(AnalysisJob $job): bool
    {
        return DB::transaction(function () use ($job): bool {
            /** @var AnalysisJob $locked */
            $locked = AnalysisJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== JobStatus::Queued) {
                return false; // 重複配送 guard
            }

            $organization = $this->resolveOrganization($locked);
            $this->ensureReservation($locked, $organization); // 残高不足はここで throw → catch → failJob

            $locked->status = JobStatus::Running;
            $locked->step = AnalysisStep::Extract;
            $locked->progress = 10;
            $locked->save();
            $job->refresh();

            return true;
        });
    }

    /**
     * 予約の冪等確保: 有効な Reserved があれば再利用 (再試行で二重予約しない)。
     * 失効済み Reserved は明示 release して付け替え、Released/Committed/なしは新規 reserve。
     */
    private function ensureReservation(AnalysisJob $locked, Organization $organization): void
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
        $cost = config()->integer('manual.analysis_ticket_cost');
        $new = $this->tickets->reserve($organization, $cost); // 不足は InsufficientTicketsException
        $locked->ticketReservation()->associate($new);
        $locked->save();
    }

    /** extract 段: 統一 JSON 化 + extracted_json 保存 (write-only 監査スナップショット) */
    private function runExtractStep(AnalysisJob $job, SourceDocument $document, ExtractedText $text): ExtractedSopData
    {
        $extracted = $this->withBoundedRetry(
            fn (): ExtractedSopData => ExtractedSopData::fromLlmText(
                SopExtractPrompt::make($text->text)->executeSync(),
            ),
        );

        $document->extracted_json = $extracted->toArray();
        $document->save();
        $this->updateProgress($job, AnalysisStep::Decompose, 35);

        return $extracted;
    }

    /** decompose 段: 作業分解表 + result_json 保存 (write-only 監査スナップショット) */
    private function runDecomposeStep(AnalysisJob $job, ExtractedSopData $extracted): WorkDecompositionData
    {
        $decomposition = $this->withBoundedRetry(
            fn (): WorkDecompositionData => WorkDecompositionData::fromLlmText(
                WorkDecompositionPrompt::make($extracted->toJsonString())->executeSync(),
            ),
        );

        $job->result_json = $decomposition->toArray();
        $job->step = AnalysisStep::Generate;
        $job->progress = 65;
        $job->save();

        return $decomposition;
    }

    /** generate 段: カット群生成 */
    private function runGenerateStep(AnalysisJob $job, WorkDecompositionData $decomposition): GeneratedScenarioData
    {
        $generated = $this->withBoundedRetry(
            fn (): GeneratedScenarioData => GeneratedScenarioData::fromLlmText(
                ScenarioGenerationPrompt::make($decomposition->toJsonString())->executeSync(),
            ),
        );

        $this->updateProgress($job, AnalysisStep::Generate, 90);

        return $generated;
    }

    /**
     * terminal tx: materialize + commit + succeeded を原子化 (概念設計 §4-5)。
     * transaction / 行ロックは本メソッド (最外層) だけが張る。
     *
     * グローバルロック順 (全経路がこの順でのみ取得する。逆順取得ゼロ = デッドロックなし):
     *   analysis_jobs → video_manuals → ticket_reservations → organizations
     *
     * TicketLedgerService 内部の実取得順 (実装から転記。内部変更はしない):
     *   - reserve / grant:   organizations のみ (lockOrganizationRow)
     *   - commit / release:  ticket_reservations (lockReservationRow) → organizations
     * 各経路の取得列:
     *   - trigger:      video_manuals のみ (balance() はロックなしの集計)
     *   - startJob:     analysis_jobs → (reserve: organizations)
     *   - finalize:     analysis_jobs → video_manuals → (commit: ticket_reservations → organizations)
     *   - failJob:      analysis_jobs → video_manuals → (release: ticket_reservations → organizations)
     *   - releaseStale (billing cron): ticket_reservations → organizations (前方リソースを保持しない)
     *   - ScenarioService::save: video_manuals のみ
     * いずれもグローバル順の部分列であり循環待ちは構成できない。
     *
     * @return bool succeeded に到達したか (stale 回復先勝ちなら false = 通知しない。
     *              RenderPipeline::finalize と同型の bool 返却)
     */
    private function finalize(AnalysisJob $job, GeneratedScenarioData $generated): bool
    {
        return DB::transaction(function () use ($job, $generated): bool {
            // ロック 1: job 行 (stale 回復 cron との直列化点)
            /** @var AnalysisJob $locked */
            $locked = AnalysisJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== JobStatus::Running) {
                return false; // stale 回復 cron が先勝ち → materialize も commit もしない (無課金 succeeded 排除)
            }

            // ロック 2: manual 行 (共有ロック規約。親 relation 経由再解決 = 子∈親も担保)
            $project = $this->resolveProject($locked);
            /** @var VideoManual $lockedManual */
            $lockedManual = $project->manuals()
                ->whereKey($locked->video_manual_id)->lockForUpdate()->firstOrFail();

            // cuts + version + status(analyzing→ready) はロック済み manual 前提メソッドで反映
            // (内側 transaction を張らない。analyzing guard 違反は LogicException → 全体 rollback)
            $this->scenarios->materializeIntoLockedManual($lockedManual, $generated->toScenarioSteps());

            // ロック 3: reservation/org 行 (TicketLedgerService::commit 内部。savepoint)
            $reservation = $locked->ticketReservation;
            Assert::notNull($reservation, 'startJob が必ず予約を付けている');
            // 非 Reserved は LogicException → terminal tx 全体 rollback (materialize も巻き戻る) → failJob
            $this->tickets->commit($reservation);

            $locked->status = JobStatus::Succeeded;
            $locked->progress = 100;
            $locked->save();

            return true;
        });
    }

    /**
     * LLM 段の共通有界リトライ (JSON 検証失敗のみ。長さ・provider 例外はリトライしない)。
     *
     * @template T
     *
     * @param  callable(): T  $attempt
     * @return T
     */
    private function withBoundedRetry(callable $attempt): mixed
    {
        $maxRetries = config()->integer('manual.analysis_llm_max_retries');
        for ($tryCount = 0; ; $tryCount++) {
            try {
                return $attempt();
            } catch (LlmOutputInvalidException $exception) {
                if ($tryCount >= $maxRetries) {
                    throw $exception; // 計 (1 + maxRetries) 試行で打ち切り → failJob
                }
            }
        }
    }

    /**
     * step/progress の表示用更新 (tx 不要の単発 update。状態機械は status のみが真実源。
     * updated_at の更新が stale 判定の「最終 step 更新時刻」を兼ねる)。
     */
    private function updateProgress(AnalysisJob $job, AnalysisStep $step, int $progress): void
    {
        $job->step = $step;
        $job->progress = $progress;
        $job->save();
    }

    /** job → manual → project の導出 (payload 不信任。DB から relation 経由で再解決) */
    private function resolveProject(AnalysisJob $job): Project
    {
        $project = $job->videoManual?->project;
        Assert::isInstanceOf($project, Project::class, 'analysis job は必ず project 配下の manual に属する');

        return $project;
    }

    /** job → manual → project → organization の導出 */
    private function resolveOrganization(AnalysisJob $job): Organization
    {
        $organization = $this->resolveProject($job)->organization;
        Assert::isInstanceOf($organization, Organization::class, 'project は必ず組織に属する');

        return $organization;
    }

    /** ユーザー向けエラー文言 (内部詳細を error 列に漏らさない) */
    private function userMessageFor(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof AnalysisFailedException,
            $exception instanceof InsufficientTicketsException => $exception->getMessage(),
            $exception instanceof LlmOutputInvalidException => $exception->userMessage(),
            default => '解析に失敗しました。時間をおいて再実行してください。',
        };
    }
}
