<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\DataTransferObjects\LlmCallContextData;
use App\DataTransferObjects\Manual\Analysis\ExtractedSopData;
use App\DataTransferObjects\Manual\Analysis\ExtractedText;
use App\DataTransferObjects\Manual\Analysis\GeneratedScenarioData;
use App\DataTransferObjects\Manual\Analysis\WorkDecompositionData;
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
use App\Prompts\SopExtractPrompt;
use App\Prompts\WorkDecompositionPrompt;
use App\Services\Billing\TicketLedgerService;
use App\Services\Notification\NotificationCenterService;
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

            $text = $this->extractor->extract($document);
            $extracted = $this->runExtractStep($job, $document, $text, $deadline, $context);
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
        ExtractedText $text,
        CarbonImmutable $deadline,
        LlmCallContextData $context,
    ): ExtractedSopData {
        $extracted = $this->withBoundedRetry(
            $job,
            $deadline,
            AnalysisStep::Extract,
            fn (): ExtractedSopData => ExtractedSopData::fromLlmText(
                SopExtractPrompt::make($text->text, $context)->executeSync(),
            ),
        );

        $document->extracted_json = $extracted->toArray();
        $document->save();
        $this->updateProgress($job, AnalysisStep::Decompose, 35);

        return $extracted;
    }

    /** decompose 段: 作業分解表 + result_json 保存 (write-only 監査スナップショット) */
    private function runDecomposeStep(
        AnalysisJob $job,
        ExtractedSopData $extracted,
        CarbonImmutable $deadline,
        LlmCallContextData $context,
    ): WorkDecompositionData {
        $decomposition = $this->withBoundedRetry(
            $job,
            $deadline,
            AnalysisStep::Decompose,
            fn (): WorkDecompositionData => WorkDecompositionData::fromLlmText(
                WorkDecompositionPrompt::make($extracted->toJsonString(), $context)->executeSync(),
            ),
        );

        // 終端後の自前書き込みを塞ぐ: 進捗と result_json は running のときだけ書く
        $this->writeProgress($job, [
            'result_json' => $decomposition->toArray(),
            'step' => AnalysisStep::Generate->value,
            'progress' => 65,
        ]);

        return $decomposition;
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
     * @param  array{step: string, progress: int, result_json?: array<string, mixed>}  $attributes
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
