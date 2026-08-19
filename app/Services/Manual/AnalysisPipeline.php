<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\DataTransferObjects\LlmCallContextData;
use App\DataTransferObjects\Manual\Analysis\ExtractedSopData;
use App\DataTransferObjects\Manual\Analysis\ExtractedText;
use App\DataTransferObjects\Manual\Analysis\GeneratedScenarioData;
use App\DataTransferObjects\Manual\Analysis\ImageAnalysisMediaData;
use App\DataTransferObjects\Manual\Analysis\PdfAnalysisMediaData;
use App\DataTransferObjects\Manual\Analysis\WorkDecompositionData;
use App\DataTransferObjects\Manual\Analysis\WorkDecompositionResponseData;
use App\Enums\Billing\TicketReservationStatus;
use App\Enums\Llm\UntrustedInputRejectionReason;
use App\Enums\Manual\AnalysisStep;
use App\Enums\Manual\JobStatus;
use App\Enums\Security\ExternalCallKind;
use App\Exceptions\Billing\InsufficientTicketsException;
use App\Exceptions\Llm\PromptResponseRejectedException;
use App\Exceptions\Llm\UntrustedInputRejectedException;
use App\Exceptions\Manual\AnalysisFailedException;
use App\Exceptions\Manual\JobOwnershipLostException;
use App\Exceptions\Manual\LlmOutputInvalidException;
use App\Models\AnalysisJob;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SourceDocument;
use App\Models\VideoManual;
use App\Prompts\ScenarioGenerationPrompt;
use App\Prompts\SopExtractFromMediaPrompt;
use App\Prompts\SopExtractPrompt;
use App\Prompts\WorkDecompositionPrompt;
use App\Services\Billing\TicketLedgerService;
use App\Services\Notification\NotificationCenterService;
use App\Support\Manual\AnalysisAcceptanceGate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * AI 解析パイプライン本体 (extract → decompose → generate → materialize)。概念設計 §4。
 *
 * - チケット 2 フェーズ: startJob で reserve (冪等キー = analysis_jobs.ticket_reservation_id)、
 *   terminal tx (finalize) で materialize + commit + succeeded を原子化
 *   (無課金 succeeded / 課金済み failed を構造的に排除)。
 *   リトライは各段の内側 (startJob の後・finalize の前) に閉じており予約行に触れないため、
 *   何回再試行しても reserve/commit/release は高々 1 回ずつ
 * - LLM 呼び出しの有界リトライ: JSON 検証失敗 (LlmOutputInvalidException) と transient な
 *   provider/connection 例外を、config manual.analysis_llm_max_retries 回まで再試行する
 *   (打ち切り条件は「試行回数 ∧ 実時間 deadline」。isTransient() は deny-by-default)
 * - 失敗は catch → AnalysisJobService::failJob (行ロック + terminal guard で冪等)
 */
class AnalysisPipeline
{
    /**
     * transient と断定できる provider 側 HTTP status のうち「時間切れ」系
     * (generic PrismException 経由で来る。文言は timedOut)。
     */
    private const TIMED_OUT_HTTP_STATUS = 408;

    /**
     * transient と断定できる provider 側 HTTP status のうち「混雑」系
     * (generic PrismException 経由で来る。文言は providerBusy)。
     * 429/413/529 は専用例外型で来るため、ここには含めない。
     *
     * retryable 集合 = TIMED_OUT_HTTP_STATUS ∪ PROVIDER_BUSY_HTTP_STATUSES と定義し、
     * isTransient() と userMessageFor() が同じ定数を読む (status 解釈を二重管理しない)。
     *
     * @var list<int>
     */
    private const PROVIDER_BUSY_HTTP_STATUSES = [500, 502, 503, 504];

    public function __construct(
        private readonly AnalysisJobService $jobs,
        private readonly ScenarioService $scenarios,
        private readonly SopTextExtractor $extractor,
        private readonly TicketLedgerService $tickets,
        private readonly NotificationCenterService $notifications,
        private readonly ScenarioBookendBuilder $bookend,
        private readonly AnalysisMediaValidator $mediaValidator,
    ) {}

    public function run(int $analysisJobId): void
    {
        // T0 = run() 入口。実時間 deadline (ソフト予算) は **メソッドの第 1 文**で確定させる
        // (findOrFail / startJob も deadline の内側に入る = 設計の T0 定義と一致させる)。
        // deadline は各 LLM 試行の「開始可否」だけを決め、走行中の呼び出しは中断しない
        // (中断は prompt YAML の client_options.timeout)。
        // ハード上限は RunManualAnalysis::$timeout (worker の SIGALRM)。
        $deadline = CarbonImmutable::now()
            ->addSeconds(config()->integer('manual.analysis_deadline_seconds'));

        $job = AnalysisJob::query()->findOrFail($analysisJobId);

        try {
            if (! $this->startJob($job)) {
                return; // 重複配送 / stale 回復後の遅延配送 → no-op
            }
            $document = $job->sourceDocument;
            Assert::notNull($document, 'trigger が必ず associate している');

            // LLM コスト記録の帰属 (llm_call_logs.organization_id / subject_*)。
            // startJob() が true を返した直後 = 実際に走る担当だと確定した後に 1 度だけ解決し、
            // 3 段すべての prompt factory へ引数で渡す (パイプラインを stateful にしない)。
            // リトライでも同じ context が使われるため、再試行で出た失敗行にも同じ帰属が付く。
            $context = $this->resolveCallContext($job);

            $extracted = $this->runExtractStage($job, $document, $deadline, $context);
            $decomposition = $this->runDecomposeStep($job, $extracted, $deadline, $context);
            $generated = $this->runGenerateStep($job, $decomposition, $deadline, $context);
            if ($this->finalize($job, $generated)) {
                // succeeded 到達時のみ・terminal tx の commit 後に通知 (stale 先勝ち false は通知しない)
                $this->notifications->notifyAnalysisFinished($job->refresh());
            }
        } catch (JobOwnershipLostException $exception) {
            // preflight suppression: 既に terminal 化されている = 自分は旧担当。
            // failJob も通知もチケット release も呼ばない (すべて先着が済ませている)。
            // report() しない — これは「正常だが観測したい事象」であり、固定 event 名で集計する。
            Log::warning('解析ジョブの所有権を失ったため外部呼び出しを中止しました', $exception->logContext());

            return;
        } catch (Throwable $exception) {
            report($exception);
            // 観測: スキーマ違反で最終失敗したときも再試行ログと同じキーを残す (集計で突き合わせるため)。
            // 応答本文は載せない。分岐には使わない (failJob の文言は userMessageFor が決める)。
            // stage は失敗時点の analysis_jobs.step 列から分かるため、ここでは job id を出して
            // 段の情報を 2 系統で持たない。
            if ($exception instanceof LlmOutputInvalidException) {
                Log::warning('AI 解析が LLM 応答のスキーマ違反で失敗しました', [
                    'analysis_job_id' => $job->id,
                    'failure_category' => $exception->reason->value,
                    'failure_path' => $exception->path,
                ]);
            }
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

    /**
     * extract 段の入口 (画像・スキャン SOP の OCR 対応)。`resolveExtractInput()`
     * (経路決定・媒体検証) と `runExtractStep()` (LLM 呼び出し) の両方を包み、
     * 成功・失敗を問わず extract 段の終端をちょうど 1 回だけログする
     * (「媒体検証成功」と「LLM 呼び出し失敗」の 2 つの outcome 付きログが同じジョブに
     * 残ってしまう問題を、単一の終端ログに統合して解消する)。
     */
    private function runExtractStage(
        AnalysisJob $job,
        SourceDocument $document,
        CarbonImmutable $deadline,
        LlmCallContextData $context,
    ): ExtractedSopData {
        $isImage = in_array($document->mime, ['image/jpeg', 'image/png'], true);
        $ocrEnabled = config()->boolean('manual.ocr_analysis_enabled');
        // 初期値: 画像 + フラグ有効なら最初から 'ocr'、それ以外は 'text'。
        // PDF が品質ゲート失敗から OCR フォールバックへ入る場合は、resolveExtractInput()
        // が参照渡しでこの値を 'ocr' へ更新する (media 検証を試みる直前に更新するため、
        // 検証が失敗して例外が飛んでも route は正しく 'ocr' のまま catch へ渡る)。
        $route = ($isImage && $ocrEnabled) ? 'ocr' : 'text';
        // 媒体検証が成功した後に LLM 呼び出しが失敗した場合でも、検証済みの媒体メタデータ
        // (容量・ページ数・画素数) をログへ残すため、$input をこのスコープで保持し続ける。
        $input = null;

        try {
            $input = $this->resolveExtractInput($document, $isImage, $ocrEnabled, $route);
            $extracted = $this->runExtractStep($job, $document, $input, $deadline, $context);

            $this->logExtractStageTerminal($job, $document, $route, $input, null);

            return $extracted;
        } catch (Throwable $exception) {
            $this->logExtractStageTerminal($job, $document, $route, $input, $exception);

            throw $exception;
        }
    }

    /**
     * text 抽出を試み、失敗理由が OCR 経路の対象なら media 検証へフォールバックする。
     * 対象外の理由 (tooLarge 等) や、画像/PDF 以外の mime での失敗はそのまま再送出する
     * (既存の catch → failJob 経路がそのまま処理する)。ログは出さない (呼び出し元
     * `runExtractStage()` が終端をまとめて 1 回ログする)。
     *
     * @param  string  $route  呼び出し元の route (参照渡し)。PDF が OCR フォールバックへ
     *                         入ると判断した瞬間 (media 検証を試みる前) に 'ocr' へ更新する
     *                         (戻り値の型だけで route を判定すると、media 検証自体が失敗したケースで
     *                         route を復元できないため)。
     */
    private function resolveExtractInput(
        SourceDocument $document,
        bool $isImage,
        bool $ocrEnabled,
        string &$route,
    ): ExtractedText|ImageAnalysisMediaData|PdfAnalysisMediaData {
        if ($isImage && $ocrEnabled) {
            // 画像は SopTextExtractor::kindFor() の default 分岐が unextractable を投げる
            // (テキスト抽出は元々試みない対象)。ここで直接 media 検証へ回す
            // ($route は呼び出し元で既に 'ocr' に初期化済み)。
            return $this->mediaValidator->validateImage($document);
        }

        try {
            return $this->extractor->extract($document);
        } catch (AnalysisFailedException $exception) {
            $isPdf = $document->mime === 'application/pdf';
            if ($ocrEnabled && $isPdf && $exception->reason->isOcrEligibleForPdf()) {
                $route = 'ocr'; // media 検証を試みる直前に更新 (この後の呼び出しが失敗しても正しい)

                return $this->mediaValidator->validatePdfForOcr($document);
            }

            throw $exception; // OCR 対象外、またはフラグ無効時はそのまま失敗 (既存の catch → failJob)
        }
    }

    /**
     * extract 段の終端ログ。**`run()` の 1 回の実行 (= `runExtractStage()` の 1 回の呼び出し)
     * につきちょうど 1 回**だけ呼ばれる (`runExtractStage()` の成功パス・catch の両方から、
     * この 1 メソッドだけを経由する)。
     *
     * ★ **保証しないもの (誇張しない)**: 「ジョブ 1 件につき生涯で 1 回」ではない。
     *   永続化された冪等キーは持たないため、同じ `analysis_job_id` に対して `run()` が
     *   複数回実行されれば (stale 回復による再キューイング等)、その都度 1 行ずつ増える。
     *   `docs/rollout-checklists.md` の評価指標は「ジョブ単位」で集計するが、これは
     *   「解析ジョブ 1 件を単位に丸める」という集計方針であって、本ログの出力回数の
     *   保証ではない。厳密な一意性が必要な集計は `analysis_jobs` の終端状態と
     *   突き合わせて行うこと。
     *
     * ★ **例外 1 件**: `JobOwnershipLostException` のときだけ、本メソッドは早期 return して
     *   ログを出さない (所有権喪失は「失敗」ではなく「別の担当が既に処理した」という
     *   正常系のノイズなので、失敗率の集計対象に含めない)。
     */
    private function logExtractStageTerminal(
        AnalysisJob $job,
        SourceDocument $document,
        string $route,
        ExtractedText|ImageAnalysisMediaData|PdfAnalysisMediaData|null $input,
        ?Throwable $exception,
    ): void {
        if ($exception instanceof JobOwnershipLostException) {
            return;
        }

        $media = $input instanceof ExtractedText ? null : $input;

        Log::info('AI 解析の抽出段 (終端)', [
            'analysis_job_id' => $job->id,
            'route' => $route,
            'source_mime' => $document->mime,
            'outcome' => $exception === null ? 'ok' : 'failed',
            // 失敗理由は固定語彙のカテゴリに正規化する (実装クラス名を集計キーにしない)
            'failure_category' => $exception === null ? null : $this->observabilityCategoryFor($exception),
            'media_size_bytes' => $media?->sizeBytes,
            'media_pages' => $media instanceof PdfAnalysisMediaData ? $media->pageCount : null,
            'media_pixels' => $media instanceof ImageAnalysisMediaData ? $media->pixelCount : null,
        ]);
    }

    /**
     * 失敗理由を固定語彙のカテゴリへ正規化する。`userMessageFor()` と判定材料
     * (reason enum / HTTP status) を共有し、集計キーの語彙を二重管理しない。
     */
    private function observabilityCategoryFor(Throwable $exception): string
    {
        $status = $this->extractHttpStatus($exception); // userMessageFor() と同じ既存メソッドを再利用

        return match (true) {
            $exception instanceof AnalysisFailedException => $exception->reason->value,
            $exception instanceof LlmOutputInvalidException => 'llm_output_invalid_'.$exception->reason->value,
            $exception instanceof UntrustedInputRejectedException => match ($exception->reason) {
                UntrustedInputRejectionReason::TooLarge => 'too_large',
                UntrustedInputRejectionReason::InvalidEncoding => 'unreadable_encoding',
            },
            $exception instanceof PromptResponseRejectedException => 'unsafe_response',
            $exception instanceof ConnectionException => 'timed_out',
            $exception instanceof PrismRateLimitedException,
            $exception instanceof PrismProviderOverloadedException => 'provider_busy',
            $exception instanceof PrismRequestTooLargeException => 'too_large',
            // generic PrismException: userMessageFor() と同じ status 定数で分類する
            $status === self::TIMED_OUT_HTTP_STATUS => 'timed_out',
            $status !== null && in_array($status, self::PROVIDER_BUSY_HTTP_STATUSES, true) => 'provider_busy',
            default => 'unknown', // 上記いずれにも当たらない残余 (実装クラス名は出さない)
        };
    }

    /**
     * extract 段: 統一 JSON 化 + extracted_json 保存 (write-only 監査スナップショット)。
     *
     * ★ `SourceDocument::extracted_json` は**条件付き UPDATE にしない** (T131):
     *   これは write-only の監査スナップショットであって状態機械の一部ではなく、guard には
     *   job → document の join が要る。failed 行の document に抽出結果が残っても不整合にならない
     *   (むしろ調査に役立つ)。「終端後の**ジョブ状態・進捗**書き込みの禁止」が対象を
     *   ジョブ行に限っているのはこのためである。
     */
    private function runExtractStep(
        AnalysisJob $job,
        SourceDocument $document,
        ExtractedText|ImageAnalysisMediaData|PdfAnalysisMediaData $input,
        CarbonImmutable $deadline,
        LlmCallContextData $context,
    ): ExtractedSopData {
        $extracted = $this->withBoundedRetry(
            $job,
            $deadline,
            AnalysisStep::Extract,
            function () use ($input, $context): ExtractedSopData {
                // PHPStan level 10 で型が確実に絞り込まれるよう、match(true) ではなく
                // 素直な if/early-return にする (3 型を 2 群に束ねる match + default は避ける)
                if ($input instanceof ExtractedText) {
                    return ExtractedSopData::fromLlmText(
                        SopExtractPrompt::make($input->text, $context)->executeSync(),
                    );
                }

                return AnalysisAcceptanceGate::validateOcrResult(
                    ExtractedSopData::fromLlmText(
                        SopExtractFromMediaPrompt::make($input, $context)->executeSync(),
                    ),
                );
            },
        );

        $document->extracted_json = $extracted->toArray();
        $document->save();
        $this->updateProgress($job, AnalysisStep::Decompose, 35);

        return $extracted;
    }

    /**
     * decompose 段: 作業分解表 (result_json) + 手順書への所見 (validation_json) を 1 回の
     * LLM 呼び出しで受け取り、**同じ条件付き UPDATE で**保存する。
     *
     * ★ 次段 (generate) へ渡すのは `decomposition` **だけ**である。
     *   所見を次段の入力 JSON に混ぜない (入力 token を無駄にせず、生成器の指示も汚さない)。
     */
    private function runDecomposeStep(
        AnalysisJob $job,
        ExtractedSopData $extracted,
        CarbonImmutable $deadline,
        LlmCallContextData $context,
    ): WorkDecompositionData {
        $response = $this->withBoundedRetry(
            $job,
            $deadline,
            AnalysisStep::Decompose,
            fn (): WorkDecompositionResponseData => WorkDecompositionResponseData::fromLlmText(
                WorkDecompositionPrompt::make($extracted->toJsonString(), $context)->executeSync(),
            ),
        );

        // 終端後の自前書き込みを塞ぐ: 進捗と 2 つの JSON は running のときだけ書く
        $this->writeProgress($job, [
            'result_json' => $response->decomposition->toArray(),
            'validation_json' => $response->validation->toArray(),
            'step' => AnalysisStep::Generate->value,
            'progress' => 65,
        ]);

        return $response->decomposition;
    }

    /** generate 段: カット群生成 */
    private function runGenerateStep(
        AnalysisJob $job,
        WorkDecompositionData $decomposition,
        CarbonImmutable $deadline,
        LlmCallContextData $context,
    ): GeneratedScenarioData {
        $generated = $this->withBoundedRetry(
            $job,
            $deadline,
            AnalysisStep::Generate,
            fn (): GeneratedScenarioData => GeneratedScenarioData::fromLlmText(
                ScenarioGenerationPrompt::make($decomposition->toJsonString(), $context)->executeSync(),
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
     *   - 滞留予約の解放 (課金の定期実行): ticket_reservations → organizations (前方リソースを保持しない)
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

            // 導入/総括カットを terminal tx 内 (locked manual 参照) で決定的に前後付与する。
            // 再掲元は今回生成の steps のみ (DB 既存 cuts 不参照)。
            $steps = $this->bookend->wrap($lockedManual, $generated->toScenarioSteps());

            // cuts + version + status(analyzing→ready) はロック済み manual 前提メソッドで反映
            // (内側 transaction を張らない。analyzing guard 違反は LogicException → 全体 rollback)
            $this->scenarios->materializeIntoLockedManual($lockedManual, $steps);

            // ロック 3: reservation/org 行 (TicketLedgerService::commit 内部。savepoint)
            $reservation = $locked->ticketReservation;
            Assert::notNull($reservation, 'startJob が必ず予約を付けている');
            // commit-wins: TTL 超過や stale releaser 先着 (Released) でも生存 hold は課金する
            // (二重課金は consume:{id} の UNIQUE が防ぐ)。失効 monthly hold のみ no-charge。
            // 戻り値 (TicketCommitResult) は可観測性のためのもので分岐には使わない
            $this->tickets->commit($reservation);

            $locked->status = JobStatus::Succeeded;
            $locked->progress = 100;
            $locked->save();

            return true;
        });
    }

    /**
     * LLM 段の共通有界リトライ。
     *
     * 打ち切り条件は 2 つ:
     *  (a) 試行回数 (config manual.analysis_llm_max_retries。計 1+N 試行)
     *  (b) 実時間 deadline (config manual.analysis_deadline_seconds)
     *
     * deadline の判定は **「deadline を過ぎたか」の真偽のみ**で行い、残り時間を
     * client timeout へ反映しない。これは意図的である: deadline の 1 秒前に開始した
     * 試行にも client timeout の全体 (C) を許すことで、job の worst-case を
     * 「D + C」という単純な形に閉じている (概念設計 §時間 budget)。
     * 残り時間を timeout に渡す実装へ変えるとこのモデルが壊れる。
     *
     * ★ preflight suppression (裁定 AG-082 標準形 (2)): **`$attempt()` の直前**で所有権を
     *   再検証する。ここに 1 箇所置くだけで extract / decompose / generate の 3 段 ×
     *   全リトライ試行を覆う (挿入点が 1 つ = 新しい段を足しても抜けようがない)。
     *   deadline 判定 (時計の読み取り) は自前の書き込みではないため、
     *   preflight と `$attempt()` の間に書き込みは 1 つも無い。
     *
     * @template T
     *
     * @param  callable(): T  $attempt
     * @return T
     */
    private function withBoundedRetry(
        AnalysisJob $job,
        CarbonImmutable $deadline,
        AnalysisStep $step,
        callable $attempt,
    ): mixed {
        $maxRetries = config()->integer('manual.analysis_llm_max_retries');
        for ($tryCount = 0; ; $tryCount++) {
            if (CarbonImmutable::now()->greaterThanOrEqualTo($deadline)) {
                throw AnalysisFailedException::timedOut();
            }
            // ★外部呼び出しの直前 (これより後に自前の書き込みを挟まない)
            $this->assertStillOwned($job, $step);
            try {
                return $attempt();
            } catch (Throwable $exception) {
                if ($tryCount >= $maxRetries || ! $this->isTransient($exception)) {
                    throw $exception; // 打ち切り → run() の catch → failJob
                }
                Log::warning('AI 解析の LLM 呼び出しを再試行します', [
                    'step' => $step->value,
                    'attempt' => $tryCount + 1,
                    'max_attempts' => $maxRetries + 1,
                    'exception' => $exception::class,
                    // スキーマ違反のときだけ分類と違反位置が入る (validation 起因かを集計で分けるため)。
                    // **応答本文は載せない** (LLM 由来の可変文字列)
                    'failure_category' => $exception instanceof LlmOutputInvalidException
                        ? $exception->reason->value
                        : null,
                    'failure_path' => $exception instanceof LlmOutputInvalidException
                        ? $exception->path
                        : null,
                ]);
            }
        }
    }

    /**
     * 再試行してよい例外か (deny-by-default)。
     *
     * 写像の根拠 (vendor 実装より):
     * - cURL 28/6/7/35/52 → Guzzle ConnectException → Illuminate ConnectionException
     * - HTTP 429/529/413 は Prism の専用例外型
     * - それ以外の HTTP エラーは generic PrismException だが、previous に
     *   Illuminate\Http\Client\RequestException を保持するので status を型安全に読める
     *
     * 判定順は **retryable を先・deny を後**にする。deny 側を先に置くと、将来
     * 「retryable な型が deny 型の派生になる」変更が入ったときに黙って非 retry 化するため。
     * deny 側は同じ理由で `::class` の厳密比較にしている (派生型を巻き込まない)。
     */
    private function isTransient(Throwable $exception): bool
    {
        // (1) retryable と断定できる型を先に許可する
        if ($exception instanceof LlmOutputInvalidException
            || $exception instanceof ConnectionException
            || $exception instanceof PrismProviderOverloadedException) {
            return true;
        }

        // (2) 決定論的 (再試行しても同じ結果) を厳密比較で deny する
        if ($exception::class === PrismRateLimitedException::class
            || $exception::class === PrismRequestTooLargeException::class) {
            return false;
        }

        // (3) generic PrismException は previous の HTTP status で判定する
        $status = $this->extractHttpStatus($exception);

        return $status === self::TIMED_OUT_HTTP_STATUS
            || ($status !== null && in_array($status, self::PROVIDER_BUSY_HTTP_STATUSES, true));
    }

    /**
     * generic PrismException が保持する provider 側 HTTP status を型安全に取り出す。
     * 取得できない場合は null (= 判定不能 → fail-fast)。
     *
     * `PrismException::providerRequestErrorWithDetails()` は previous に
     * Illuminate\Http\Client\RequestException を渡すため、そこから status を読む
     * (`getCode()` は他 factory で 0 になるため多義的で使わない)。
     */
    private function extractHttpStatus(Throwable $exception): ?int
    {
        if (! $exception instanceof PrismException) {
            return null;
        }

        $previous = $exception->getPrevious();
        if (! $previous instanceof RequestException) {
            return null;
        }

        return $previous->response->status();
    }

    /**
     * 所有権の再検証 (preflight suppression)。
     *
     * 所有権 = (行の主キー, `running`)。`startJob()` の `lockForUpdate + status === Queued`
     * guard により 1 行が `running` になるのは高々 1 回で、再実行は新しい行を起票するため、
     * `status` の再読込がそのまま所有権の再検証になる (claim token を持たない根拠は
     * docs/architecture.md §ジョブの重複実行と結果の一回性)。
     *
     * 行が消えている (null) 場合も所有権喪失として扱う (deny-by-default)。
     *
     * @throws JobOwnershipLostException
     */
    private function assertStillOwned(AnalysisJob $job, AnalysisStep $step): void
    {
        $current = AnalysisJob::query()->whereKey($job->getKey())->first();
        if ($current !== null && $current->status === JobStatus::Running) {
            return; // アーリーリターン (正常系)
        }

        throw JobOwnershipLostException::whileRunning(
            jobType: AnalysisJob::class,
            jobId: $job->id,
            actualStatus: $current?->status,
            stage: $step->value,
            externalCall: ExternalCallKind::LlmCompletion,
        );
    }

    /**
     * ジョブ行の進捗系列の更新 (status は書かない)。
     *
     * ★ **条件付き UPDATE (`where status=running`)** にする理由:
     *   preflight で「terminal 化後は外部を呼ばない」ようにした以上、
     *   「terminal 化後に自前の DB を書く」経路も同時に塞ぐ。素の `save()` だと
     *   stale 回復 cron が failed にした行へ step/progress/updated_at を書き戻し、
     *   「failed なのに progress=65」という不整合を作る。
     * ★ `Builder::update()` は `updated_at` を自動付与する (stale 判定の
     *   「最終 step 更新時刻」という意味は従来どおり。ただし terminal 行では動かない)。
     * ★ 状態機械は status のみが真実源であり、本メソッドは status を書かない。
     *   **array shape で書ける列を閉じている** — `status` 等の保護列を渡せないことを
     *   PHPStan level 10 が静的に弾く。
     * ★ `Builder::update()` は `updated_at` 以外の列に**モデルの cast を適用しない**
     *   (`addUpdatedAtColumn()` だけが cast を通す)。素で渡すと `result_json` (cast=array) の
     *   エンコードが driver の grammar 任せになり、`save()` 経路と表現がずれうる。
     *   そこでモデルへ `forceFill()` してから `getAttributes()` を取り、**cast 済みの生値**を
     *   UPDATE に渡す (Laravel 自身が `addUpdatedAtColumn()` で使っているのと同じ手口)。
     *
     * @param  array{step: string, progress: int, result_json?: array<string, mixed>,
     *   validation_json?: array{verdict: string, reason: string, works: list<string>,
     *   split_recommended: bool}}  $attributes
     */
    private function writeProgress(AnalysisJob $job, array $attributes): void
    {
        $casted = (new AnalysisJob)->forceFill($attributes)->getAttributes();

        AnalysisJob::query()
            ->whereKey($job->getKey())
            ->where('status', JobStatus::Running->value)
            ->update($casted);
    }

    /** step/progress の表示用更新 (条件付き UPDATE 経路へ寄せる)。 */
    private function updateProgress(AnalysisJob $job, AnalysisStep $step, int $progress): void
    {
        $this->writeProgress($job, ['step' => $step->value, 'progress' => $progress]);
    }

    /** job → manual → project の導出 (payload 不信任。DB から relation 経由で再解決) */
    private function resolveProject(AnalysisJob $job): Project
    {
        $project = $job->videoManual?->project;
        Assert::isInstanceOf($project, Project::class, 'analysis job は必ず project 配下の manual に属する');

        return $project;
    }

    /**
     * LLM 呼び出しの帰属コンテキストの導出 (payload 不信任。すべて DB から relation 経由で再解決)。
     *
     * subject は **VideoManual** にする。費用を知りたい単位は成果物 (マニュアル) であって
     * job ではない (再解析で job は増えるが「このマニュアルに合計いくらかけたか」が運用の要求)。
     * なお集計層はこの判断を一切知らない (見るのは subject_type / subject_id の 2 列だけ)。
     *
     * ★ 参照のみで書き込みも判定もしない (startJob の行ロック外で呼んでも状態を変えない)。
     */
    private function resolveCallContext(AnalysisJob $job): LlmCallContextData
    {
        $manual = $job->videoManual;
        Assert::isInstanceOf($manual, VideoManual::class, 'analysis job は必ず manual に属する');

        return LlmCallContextData::for(
            $this->resolveOrganization($job)->id,
            $manual,
            $job->triggered_by,
        );
    }

    /** job → manual → project → organization の導出 */
    private function resolveOrganization(AnalysisJob $job): Organization
    {
        $organization = $this->resolveProject($job)->organization;
        Assert::isInstanceOf($organization, Organization::class, 'project は必ず組織に属する');

        return $organization;
    }

    /**
     * ユーザー向けエラー文言 (内部詳細を error 列に漏らさない)。
     * 理由ごとに「次に取れる行動」が変わるため分岐する (H4)。
     *
     * HTTP status の取り出しは isTransient() と同じ extractHttpStatus() を使う
     * (retryable 判定と文言分岐で status の解釈を二重管理しない)。
     */
    private function userMessageFor(Throwable $exception): string
    {
        $status = $this->extractHttpStatus($exception); // 二重呼び出しを避けて一度だけ取る

        return match (true) {
            $exception instanceof AnalysisFailedException,
            $exception instanceof InsufficientTicketsException => $exception->getMessage(),
            $exception instanceof LlmOutputInvalidException => $exception->userMessage(),
            // 窓口が untrusted 入力を prompt に載せる前に拒否した (LLM は 1 回も呼ばれていない)。
            // 拒否理由は網羅 match で写像し、到達不能な else を作らない。
            $exception instanceof UntrustedInputRejectedException => match ($exception->reason) {
                UntrustedInputRejectionReason::TooLarge => AnalysisFailedException::tooLarge()->getMessage(),
                UntrustedInputRejectionReason::InvalidEncoding => AnalysisFailedException::unreadableEncoding()->getMessage(),
            },
            // 実行単位が応答を捨てた (system prompt の合言葉が応答に現れた)。原因は断定しない
            $exception instanceof PromptResponseRejectedException => AnalysisFailedException::unsafeResponse()->getMessage(),
            // provider 応答が client timeout を超えた (cURL 28 等)
            $exception instanceof ConnectionException => AnalysisFailedException::timedOut()->getMessage(),
            // provider 混雑 (429 / 529)
            $exception instanceof PrismRateLimitedException,
            $exception instanceof PrismProviderOverloadedException => AnalysisFailedException::providerBusy()->getMessage(),
            // 入力過大 (413) は既存の「分割してアップロード」文言を再利用する
            $exception instanceof PrismRequestTooLargeException => AnalysisFailedException::tooLarge()->getMessage(),
            // generic PrismException: previous の HTTP status で理由を分ける
            // (status 集合は isTransient() と同じ定数を読む = 将来の drift を構造的に防ぐ)
            $status === self::TIMED_OUT_HTTP_STATUS => AnalysisFailedException::timedOut()->getMessage(),
            $status !== null && in_array($status, self::PROVIDER_BUSY_HTTP_STATUSES, true) => AnalysisFailedException::providerBusy()->getMessage(),
            default => '解析に失敗しました。時間をおいて再実行してください。',
        };
    }
}
