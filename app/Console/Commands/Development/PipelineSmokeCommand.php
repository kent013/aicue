<?php

declare(strict_types=1);

namespace App\Console\Commands\Development;

use App\DataTransferObjects\Capture\Sha256Checksum;
use App\DataTransferObjects\Capture\TakeRegistrationInput;
use App\DataTransferObjects\Capture\TakeUploadInput;
use App\DataTransferObjects\LlmCostReportData;
use App\DataTransferObjects\LlmCostRowData;
use App\DataTransferObjects\Smoke\SmokeRunResultData;
use App\DataTransferObjects\Smoke\SmokeStageResultData;
use App\Enums\LlmCostGroupBy;
use App\Enums\Manual\JobStatus;
use App\Enums\Manual\TakeStatus;
use App\Enums\Manual\VideoManualStatus;
use App\Enums\Smoke\SmokeFailureClass;
use App\Enums\Smoke\SmokeStage;
use App\Jobs\Manual\RunManualAnalysis;
use App\Jobs\Manual\RunManualRender;
use App\Models\AnalysisJob;
use App\Models\Cut;
use App\Models\LlmCallLog;
use App\Models\Organization;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Billing\TicketLedgerService;
use App\Services\Capture\CaptureTakeService;
use App\Services\Capture\TakeRegistrationService;
use App\Services\Capture\TakeUploadService;
use App\Services\LlmCostReportService;
use App\Services\Manual\AnalysisJobService;
use App\Services\Manual\RenderJobService;
use App\Services\Manual\VideoManualService;
use App\Services\Project\DefaultProjectResolver;
use App\Services\Project\ProjectService;
use App\Services\Storage\Fakes\FakeObjectStore;
use App\Support\BughuntDatabaseGuard;
use App\Support\FakeStorageGate;
use App\Support\Media\FfmpegSafetyArguments;
use App\Support\Smoke\SmokeFailureClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * SOP 投入 → AI 解析 → 撮影テイク → ffmpeg 合成 → mp4 の全段が通ることを
 * **実 LLM** で確認する通し確認コマンド (bug-hunt 専用・課金あり)。
 *
 * ★ **品質は一切判定しない**。見るのは「期待した状態遷移が起きたか」だけである
 *   (字幕の文言・カット数の妥当性・尺の妥当性は判定しない)。
 * ★ **保証しないもの**の一覧は `docs/architecture.md` §パイプライン通し確認 が正本。
 * ★ constructor は引数を持たない。**業務 Service と `FakeObjectStore` は fail-secure 4 条件を
 *   通過した後**に `handle()` 内で遅延解決する (`artisan list` / `help` を含むあらゆる artisan
 *   起動でコマンドが構築されるため、fake が gate 判定より前に解決されるのを防ぐ)。
 *   条件の評価そのものに必要な依存 (DB 接続名 / `FakeStorageGate`) は `evaluateFailSecure()` が
 *   **その条件を評価する直前にだけ**解決し、不成立が確定したらそれ以降は解決しない。
 */
class PipelineSmokeCommand extends Command
{
    use ConfirmableTrait;

    /** worker 待ちのポーリング間隔 (秒)。 */
    private const int POLL_INTERVAL_SECONDS = 2;

    /** ジョブ側 timeout に足す余裕 (秒)。上限値はジョブ定数から導出し独立したリテラルを置かない。 */
    private const int WAIT_MARGIN_SECONDS = 120;

    /** テイク動画の尺 (秒)。1 本だけ生成して全 cut で使い回す。 */
    private const int TAKE_SECONDS = 2;

    /** 外部プロセスの実行上限 (秒)。 */
    private const int PROCESS_TIMEOUT_SECONDS = 120;

    /**
     * `llm-evidence` 段で成功行を要求する prompt_template (母集団もこの集合で絞る)。
     *
     * @var list<string>
     */
    private const array REQUIRED_TEMPLATES = ['sop-extract', 'work-decomposition', 'scenario-generation'];

    /** @var string */
    protected $signature = 'dev:pipeline-smoke
        {--check : preflight だけ実行して終了する (LLM を 1 回も呼ばない = 費用ゼロ)}
        {--org= : 対象組織 id (省略時は条件を満たす先頭の組織)}
        {--json : 機械可読出力 (結果 JSON は最終行に 1 行)}
        {--force : 実行確認を省略する (fail-secure 条件は迂回できない)}';

    /** @var string */
    protected $description = 'SOP 投入→AI 解析→撮影テイク→ffmpeg 合成→mp4 の全段が通ることを実 LLM で確認する (bug-hunt 専用・課金あり)。';

    /** @var list<SmokeStageResultData> 実行済みの段 */
    private array $stages = [];

    /** @var array<string, string> 実行対象の表示 (env / db / org / ffmpeg 版など) */
    private array $context = [];

    /** この実行分の境界 (llm_call_logs.id)。`--check` では取らない。 */
    private ?int $baselineId = null;

    public function handle(): int
    {
        $startedAt = CarbonImmutable::now();

        // ── fail-secure 4 条件 (--force でも迂回できない) ──────────────
        // 観測は条件を 1 つ通過するごとに 1 つだけ取る。未到達の条件は 'unknown' のままにし、
        // **不成立が確定した時点でそれ以上の依存を解決しない**。
        [$blocker, $this->context] = $this->evaluateFailSecure();
        if ($blocker !== null) {
            // 出力は DTO の 1 経路に揃える (--json が例外的に plain text にならないようにする)
            $this->recordStage(
                SmokeStage::Preflight,
                false,
                $startedAt,
                "fail-secure 条件を満たしていないため実行しません: {$blocker}",
                SmokeFailureClass::Preflight,
            );

            return $this->finish($startedAt, checkOnly: (bool) $this->option('check'), cost: null);
        }

        // ── preflight (--check はここまでで終了。DB を 1 行も変更しない) ──
        $preflight = $this->runPreflight();
        if ($preflight === null) {
            return $this->finish($startedAt, checkOnly: (bool) $this->option('check'), cost: null);
        }
        [$organization, $actor] = $preflight;

        if ($this->option('check') === true) {
            return $this->finish($startedAt, checkOnly: true, cost: null);
        }

        if (! $this->confirmToProceed($this->costWarning(), true)) {
            // ★第 2 引数 true = **常に**確認する。既定 callback は production でしか確認しないため、
            //   bughunt.local では確認が一度も出ないまま課金が走ってしまう。
            //   拒否も出力は DTO の 1 経路に揃える (--json が plain text にならないようにする)。
            return $this->abort($startedAt, '実行確認で拒否されました (何も実行していません)');
        }

        // 「この実行分」の境界。preflight 通過直後・fixture 段より前に 1 回だけ取る
        // (将来どの段で LLM が増えても取りこぼさない)。0 件時は 0 = 全行対象。
        $maxId = LlmCallLog::query()->max('id');
        $this->baselineId = is_numeric($maxId) ? (int) $maxId : 0;

        $workDir = storage_path('app/smoke/'.Str::ulid()->toString());
        File::ensureDirectoryExists($workDir);

        try {
            $this->runStages($organization, $actor, $workDir);
        } finally {
            File::deleteDirectory($workDir);
        }

        return $this->finish($startedAt, checkOnly: false, cost: $this->costReport());
    }

    // ─────────────────────────────────────────────────────────────────
    // fail-secure / preflight
    // ─────────────────────────────────────────────────────────────────

    /**
     * fail-secure 4 条件の評価。満たさない最初の条件の説明と、**そこまでに観測した値**を返す。
     *
     * ★ 観測は条件 1 つにつき 1 つだけ取り、**不成立が確定したらそれ以上の依存を解決しない**
     *   (未到達の条件は `unknown` のまま出す)。「4 条件を通過する前に依存を解決しない」を
     *   出力の都合で崩さないための形である。
     * ★ 4 は**自プロセスの config** であり worker の設定は見ていない。worker が fake なら
     *   「llm_call_logs の記録行が 0」として段 llm-evidence で落ちる (2 層で守る)。
     *
     * @return array{string|null, array<string, string>} [不成立の説明 (満たしていれば null), 観測値]
     */
    private function evaluateFailSecure(): array
    {
        $context = ['env' => 'unknown', 'db' => 'unknown', 'fake_storage' => 'unknown', 'fake_llm' => 'unknown'];

        $environment = (string) $this->laravel->environment();
        $context['env'] = $environment;
        if ($environment !== 'bughunt.local') {
            return ['env が bughunt.local ではありません (実 LLM / 実 ffmpeg / チケット消費を dev / production で走らせない)', $context];
        }

        // 判定の SSOT は BughuntDatabaseGuard。名前だけを見る純関数を使い、観測値も同時に出す
        $database = DB::connection()->getDatabaseName();
        $context['db'] = $database;
        if (! BughuntDatabaseGuard::matches($database)) {
            return ['接続先が bug-hunt DB ではありません (dev DB へ fixture をばら撒かない)', $context];
        }

        $fakeStorage = app(FakeStorageGate::class)->enabled();
        $context['fake_storage'] = $fakeStorage ? 'on' : 'off';
        if (! $fakeStorage) {
            return ['fake storage が無効です (実 S3 へ書かない)', $context];
        }

        $realLlm = config('testing.fake_llm') === false;
        $context['fake_llm'] = $realLlm ? 'off' : 'on';
        if (! $realLlm) {
            return ['fake LLM が有効です (fake のまま「通った」と報告しない)', $context];
        }

        return [null, $context];
    }

    /**
     * preflight。成功したら [対象組織, actor] を返し、失敗したら null を返す
     * (`--check` の成功時も [組織, actor] を返し、呼び出し側がそこで打ち切る)。
     *
     * @return array{Organization, User}|null
     */
    private function runPreflight(): ?array
    {
        $startedAt = CarbonImmutable::now();

        $ffmpegVersion = $this->probeBinary(config()->string('manual.render_ffmpeg_binary'));
        $ffprobeVersion = $this->probeBinary(config()->string('manual.render_ffprobe_binary'));
        $this->context['ffmpeg'] = $ffmpegVersion ?? 'MISSING';
        $this->context['ffprobe'] = $ffprobeVersion ?? 'MISSING';
        if ($ffmpegVersion === null || $ffprobeVersion === null) {
            return $this->failPreflight($startedAt, 'ffmpeg / ffprobe を実行できません (manual.render_ffmpeg_binary / render_ffprobe_binary)');
        }

        foreach (['database-analysis', 'database-render'] as $connection) {
            if (config("queue.connections.{$connection}") === null) {
                return $this->failPreflight($startedAt, "queue connection {$connection} が未定義です");
            }
        }

        $fixture = $this->fixturePath();
        $contents = is_file($fixture) ? file_get_contents($fixture) : false;
        if (! is_string($contents) || strlen($contents) < config()->integer('manual.analysis_min_text_bytes')) {
            return $this->failPreflight($startedAt, "SOP fixture が読めないか短すぎます: {$fixture}");
        }

        // DB へ触る検査はここから (組織 / actor / 残高 / Project)。未 provision / 未 migrate の
        // bug-hunt DB では例外になるが、それも preflight の失敗として扱う
        // (--json の契約を壊さず、原因を段の detail に残す)。
        try {
            $resolved = $this->runDatabasePreflight($startedAt);
        } catch (QueryException $exception) {
            return $this->failPreflight(
                $startedAt,
                'DB を読めません (bug-hunt DB が未 provision / 未 migrate の可能性): '.self::describe($exception),
            );
        }
        if ($resolved === null) {
            return null; // 失敗は runDatabasePreflight が段として記録済み
        }

        $this->recordStage(SmokeStage::Preflight, true, $startedAt, 'ok', null);

        return $resolved;
    }

    /**
     * DB を読む preflight (組織 / actor / 残高 / Project)。
     * 例外は呼び出し側の 1 箇所で `QueryException` として捕まえる (--json の契約を守る)。
     *
     * @return array{Organization, User}|null
     */
    private function runDatabasePreflight(CarbonImmutable $startedAt): ?array
    {
        $organization = $this->resolveOrganization();
        if ($organization === null) {
            return $this->failPreflight($startedAt, '条件を満たす組織が見つかりません (チケット残高と所属 user を確認してください)');
        }
        $this->context['org'] = '#'.$organization->id;

        /** @var User|null $actor */
        $actor = $organization->users()->orderBy('users.id')->first();
        if ($actor === null) {
            return $this->failPreflight($startedAt, "組織 #{$organization->id} に所属 user がいません");
        }
        $this->context['actor'] = '#'.$actor->id;

        $balance = app(TicketLedgerService::class)->availableTrueBalance($organization);
        $required = $this->requiredTickets();
        $this->context['tickets'] = "{$balance} (required {$required})";
        if ($balance < $required) {
            return $this->failPreflight($startedAt, "チケット残高が不足しています (残高 {$balance} / 必要 {$required})");
        }

        // Project 不在は preflight の失敗にしない (fixture 段で作る)
        $project = app(DefaultProjectResolver::class)->resolve($organization);
        $this->context['project'] = $project === null ? 'will-create' : 'existing #'.$project->id;

        return [$organization, $actor];
    }

    /** preflight 失敗の記録 (段の detail に理由をそのまま出す)。 */
    private function failPreflight(CarbonImmutable $startedAt, string $reason): null
    {
        $this->recordStage(
            SmokeStage::Preflight,
            false,
            $startedAt,
            $reason,
            SmokeFailureClassifier::classify(
                SmokeStage::Preflight, false, null, false, false, false, false, false, true, false,
            ),
        );

        return null;
    }

    /** `{binary} -version` の 1 行目 (実行できなければ null)。 */
    private function probeBinary(string $binary): ?string
    {
        try {
            $result = Process::timeout(self::PROCESS_TIMEOUT_SECONDS)
                ->run([$binary, ...FfmpegSafetyArguments::all(), '-version']);
        } catch (Throwable) {
            return null;
        }
        if (! $result->successful()) {
            return null;
        }

        $firstLine = strtok($result->output(), "\n");

        return $firstLine === false ? 'unknown' : trim($firstLine);
    }

    /**
     * 対象組織の解決。`--org` 指定があればその組織、無ければ条件を満たす先頭の組織。
     *
     * ★ `--org` の主キー指定は「運用者が CLI で組織を名指しする」形であり
     *   `DirectFetchInventory` へ `OperatorInvokedConsoleCommand` として登録済み。
     */
    private function resolveOrganization(): ?Organization
    {
        $option = $this->option('org');
        if (is_string($option) && $option !== '') {
            if (! ctype_digit($option)) {
                return null;
            }

            /** @var Organization|null */
            return Organization::query()->whereKey((int) $option)->first();
        }

        $required = $this->requiredTickets();
        $tickets = app(TicketLedgerService::class);
        foreach (Organization::query()->orderBy('id')->cursor() as $organization) {
            if (! $organization->users()->exists()) {
                continue;
            }
            if ($tickets->availableTrueBalance($organization) >= $required) {
                return $organization;
            }
        }

        return null;
    }

    /** 1 回の通し確認が消費するチケット枚数 (解析 + レンダ)。 */
    private function requiredTickets(): int
    {
        return config()->integer('manual.analysis_ticket_cost')
            + config()->integer('manual.render_ticket_cost');
    }

    /** 確認プロンプトに出す警告文 (見積りは断定しない)。 */
    private function costWarning(): string
    {
        return '実 LLM を 3 段呼び出し、チケットを '.$this->requiredTickets().' 枚消費します。'
            .' 1 回あたりおよそ $0.07〜0.12 (リトライが起きると最大 3 倍程度)。'
            .' 実測値は実行後のコストレポートに出ます。';
    }

    // ─────────────────────────────────────────────────────────────────
    // 段の実行
    // ─────────────────────────────────────────────────────────────────

    private function runStages(Organization $organization, User $actor, string $workDir): void
    {
        $fixture = $this->runFixtureStage($organization, $actor, $workDir);
        if ($fixture === null) {
            return;
        }
        [$project, $manual] = $fixture;

        if (! $this->runAnalysisStage($project, $manual, $actor)) {
            return;
        }
        if (! $this->runLlmEvidenceStage($organization, $manual)) {
            return;
        }
        if (! $this->runCaptureStage($organization, $project, $manual, $workDir)) {
            return;
        }
        $renderJob = $this->runRenderStage($project, $manual, $actor);
        if ($renderJob === null) {
            return;
        }
        $this->runArtifactStage($renderJob, $workDir);
    }

    /**
     * fixture 段: Default Project (不在時のみ作成) + SOP つき manual の作成。
     *
     * @return array{Project, VideoManual}|null
     */
    private function runFixtureStage(Organization $organization, User $actor, string $workDir): ?array
    {
        $startedAt = CarbonImmutable::now();
        try {
            $project = app(DefaultProjectResolver::class)->resolve($organization)
                ?? app(ProjectService::class)->createProject($organization, 'pipeline-smoke', null);

            // UploadedFile は保存時に元ファイルを触りうるため、fixture 本体ではなく複製を渡す
            $localCopy = $workDir.'/pipeline-smoke-sop.txt';
            File::copy($this->fixturePath(), $localCopy);

            $manual = app(VideoManualService::class)->create(
                $project,
                'pipeline-smoke '.CarbonImmutable::now()->format('Y-m-d H:i'),
                null,
                $actor->id,
                new UploadedFile($localCopy, 'pipeline-smoke-sop.txt', 'text/plain', null, test: true),
            );

            $documents = $manual->sourceDocuments()->count();
            $ok = $manual->status === VideoManualStatus::Draft && $documents === 1;
            $detail = "manual=#{$manual->id} documents={$documents} status={$manual->status->value}";

            return $this->gate(SmokeStage::Fixture, $ok, $startedAt, $detail) ? [$project, $manual] : null;
        } catch (Throwable $exception) {
            $this->gate(SmokeStage::Fixture, false, $startedAt, self::describe($exception));

            return null;
        }
    }

    /** analysis 段: 解析ジョブを起票し worker の完了を待つ。 */
    private function runAnalysisStage(Project $project, VideoManual $manual, User $actor): bool
    {
        $startedAt = CarbonImmutable::now();
        try {
            $job = app(AnalysisJobService::class)->trigger($project, $manual, $actor);
        } catch (Throwable $exception) {
            return $this->gate(SmokeStage::Analysis, false, $startedAt, self::describe($exception));
        }

        $timeout = (new RunManualAnalysis(0))->timeout + self::WAIT_MARGIN_SECONDS;
        [$status, $timedOut] = $this->waitForJob($job, $timeout);

        $manual->refresh();
        $cuts = $manual->cuts()->count();
        $ok = $status === JobStatus::Succeeded
            && $manual->status === VideoManualStatus::Ready
            && $cuts >= 1
            && $manual->scenario_version >= 1;
        $detail = "job=#{$job->id} status={$status->value} cuts={$cuts}"
            ." scenario_version={$manual->scenario_version}"
            .($job->error === null ? '' : " error={$job->error}")
            .($job->step === null ? '' : " step={$job->step->value}");

        return $this->gate(SmokeStage::Analysis, $ok, $startedAt, $detail, $status, $timedOut);
    }

    /**
     * llm-evidence 段 (DB 読み取りのみ): 実呼び出しの証拠と帰属の証拠。
     *
     * これが「施策 1 の配線が実 LLM 経路で本当に効いていること」の唯一の機械的な確認である
     * (テストレーンでは Prompt::$fake がイベントを発火しないため観測できない)。
     */
    private function runLlmEvidenceStage(Organization $organization, VideoManual $manual): bool
    {
        $startedAt = CarbonImmutable::now();

        $succeeded = [];
        /** @var list<array{string, bool}> $observations 成功行ごとの (template, 帰属が期待どおりか) */
        $observations = [];
        $mismatches = [];
        foreach ($this->runScopedLogs()->whereNull('failure_reason')->where('input_tokens', '>', 0)->get() as $log) {
            $template = $log->prompt_template;
            if ($template === null) {
                continue;
            }
            $succeeded[$template] = true;

            $expectedType = $manual->getMorphClass();
            $expectedId = (string) $manual->id;
            $matched = ! $log->metadata_missing
                && $log->organization_id === $organization->id
                && $log->subject_type === $expectedType
                && $log->subject_id === $expectedId;
            // 畳み込み (AND) は純関数へ委譲する。ここは DB から観測を集めるだけ
            $observations[] = [$template, $matched];
            if ($matched) {
                continue;
            }
            $mismatches[] = sprintf(
                '%s: organization_id=%s subject_type=%s subject_id=%s metadata_missing=%s'
                .' (期待 organization_id=%d subject_type=%s subject_id=%s)',
                $template,
                $log->organization_id === null ? 'null' : (string) $log->organization_id,
                $log->subject_type ?? 'null',
                $log->subject_id ?? 'null',
                $log->metadata_missing ? 'true' : 'false',
                $organization->id,
                $expectedType,
                $expectedId,
            );
        }

        $succeededTemplates = array_keys($succeeded);
        $attributedTemplates = SmokeFailureClassifier::fullyAttributedTemplates($observations);
        $missingTemplates = array_values(array_diff(self::REQUIRED_TEMPLATES, $succeededTemplates));
        $incomplete = SmokeFailureClassifier::llmRecordingIncomplete(
            self::REQUIRED_TEMPLATES,
            $succeededTemplates,
            $attributedTemplates,
        );

        $ok = $missingTemplates === [] && ! $incomplete;
        $detail = sprintf(
            'succeeded=%d/%d attributed=%d/%d retry_rows=%d',
            count($succeededTemplates),
            count(self::REQUIRED_TEMPLATES),
            count($attributedTemplates),
            count(self::REQUIRED_TEMPLATES),
            $this->runScopedLogs()->whereNotNull('failure_reason')->count(),
        );
        if ($missingTemplates !== []) {
            $detail .= ' 成功行が無い template: '.implode(', ', $missingTemplates);
        }
        if ($mismatches !== []) {
            $detail .= ' 帰属が期待と違う行: '.implode(' / ', $mismatches);
        }

        return $this->gate(
            SmokeStage::LlmEvidence,
            $ok,
            $startedAt,
            $detail,
            llmRecordingIncomplete: $incomplete,
        );
    }

    /** capture 段: 全 cut にテイクを 1 本ずつ置いて採用する (動画は 1 本生成して使い回す)。 */
    private function runCaptureStage(
        Organization $organization,
        Project $project,
        VideoManual $manual,
        string $workDir,
    ): bool {
        $startedAt = CarbonImmutable::now();
        try {
            $takePath = $this->generateTakeVideo($workDir);
            $sizeBytes = filesize($takePath);
            Assert::integer($sizeBytes, 'テイク動画のサイズを取得できません');
            $digest = hash_file('sha256', $takePath, binary: true);
            Assert::string($digest, 'テイク動画の sha256 を計算できません');
            $checksum = Sha256Checksum::fromBase64(base64_encode($digest));

            $adopted = 0;
            /** @var Cut $cut */
            foreach ($manual->cuts()->orderBy('id')->get() as $cut) {
                $this->uploadAndAdoptTake($organization, $project, $manual, $cut, $takePath, $sizeBytes, $checksum);
                $adopted++;
            }

            // 採用テイクの有無は relation 経由で数える (採用キーの列名を持ち出さない)
            $unadopted = $manual->cuts()->doesntHave('adoptedTake')->count();
            $ok = $adopted >= 1 && $unadopted === 0;

            return $this->gate(SmokeStage::Capture, $ok, $startedAt, "takes={$adopted} unadopted={$unadopted}");
        } catch (Throwable $exception) {
            return $this->gate(SmokeStage::Capture, false, $startedAt, self::describe($exception));
        }
    }

    /** 1 cut 分の presign → オブジェクト書き込み → 登録 → 採用。 */
    private function uploadAndAdoptTake(
        Organization $organization,
        Project $project,
        VideoManual $manual,
        Cut $cut,
        string $takePath,
        int $sizeBytes,
        Sha256Checksum $checksum,
    ): void {
        $clientTakeId = Str::ulid()->toString();
        $ticket = app(TakeUploadService::class)->issue(
            $organization,
            $project,
            $manual,
            $cut,
            new TakeUploadInput($clientTakeId, $sizeBytes, 'video/mp4', $checksum),
        );

        // 予約行は必ず organization → project → manual → cut の確認済み relation から辿る
        // (presigned URL を parse して key を復元しない / payload から tenant キーを復元しない)
        $reservation = $cut->uploadReservations()
            ->where('client_take_id', $clientTakeId)
            ->latest('id')
            ->firstOrFail();

        $stream = fopen($takePath, 'rb');
        Assert::resource($stream, null, 'テイク動画を開けません');
        try {
            app(FakeObjectStore::class)->storeStreamed(
                $reservation->video_path,
                $stream,
                'video/mp4',
                $checksum->base64,
            );
        } finally {
            fclose($stream);
        }

        $result = app(TakeRegistrationService::class)->register(
            $project,
            $manual,
            $cut,
            new TakeRegistrationInput($ticket->ticket, $clientTakeId, self::TAKE_SECONDS * 1000, null),
        );
        if ($result->take->status !== TakeStatus::Ready) {
            throw new RuntimeException("テイクが ready になりません: take=#{$result->take->id} status={$result->take->status->value}");
        }

        app(CaptureTakeService::class)->adopt($project, $manual, $cut, $result->take);
    }

    /** ダミーのテイク動画を 1 本だけ生成する (全 cut で使い回す)。 */
    private function generateTakeVideo(string $workDir): string
    {
        $path = $workDir.'/take.mp4';
        $result = Process::path($workDir)->timeout(self::PROCESS_TIMEOUT_SECONDS)->run([
            config()->string('manual.render_ffmpeg_binary'), ...FfmpegSafetyArguments::all(), '-y',
            '-f', 'lavfi', '-i', 'testsrc2=size=640x360:rate=30:duration='.self::TAKE_SECONDS,
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration='.self::TAKE_SECONDS,
            '-c:v', 'libx264', '-preset', 'veryfast', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-ar', '48000', '-ac', '2', '-shortest',
            $path,
        ]);
        if (! $result->successful() || ! is_file($path)) {
            throw new RuntimeException('テイク動画を生成できません: '.trim($result->errorOutput()));
        }

        return $path;
    }

    /** render 段: レンダジョブを起票し worker の完了を待つ。 */
    private function runRenderStage(Project $project, VideoManual $manual, User $actor): ?RenderJob
    {
        $startedAt = CarbonImmutable::now();
        try {
            $job = app(RenderJobService::class)->trigger($project, $manual, $actor);
        } catch (Throwable $exception) {
            $this->gate(SmokeStage::Render, false, $startedAt, self::describe($exception));

            return null;
        }

        $timeout = (new RunManualRender(0))->timeout + self::WAIT_MARGIN_SECONDS;
        [$status, $timedOut] = $this->waitForJob($job, $timeout);

        $manual->refresh();
        $ok = $status === JobStatus::Succeeded
            && $manual->status === VideoManualStatus::Published
            && $job->output_path !== null;
        $detail = "job=#{$job->id} status={$status->value} manual_status={$manual->status->value}"
            .' output='.($job->output_path ?? 'null')
            .($job->error_code === null ? '' : " error_code={$job->error_code->value}");

        $passed = $this->gate(
            SmokeStage::Render,
            $ok,
            $startedAt,
            $detail,
            $status,
            $timedOut,
            hasRenderErrorCode: $job->error_code !== null,
        );

        return $passed ? $job : null;
    }

    /** artifact 段: 出力オブジェクトを ffprobe で読む (品質は見ない。尺 > 0 と映像ストリームのみ)。 */
    private function runArtifactStage(RenderJob $job, string $workDir): bool
    {
        $startedAt = CarbonImmutable::now();
        $outputPath = $job->output_path;
        Assert::stringNotEmpty($outputPath, 'render 段の成功条件が output_path 非 null を保証している');

        $store = app(FakeObjectStore::class);
        if ($store->head($outputPath) === null) {
            return $this->gate(
                SmokeStage::Artifact, false, $startedAt,
                "出力オブジェクトを読み出せません: {$outputPath}",
                outputReadable: false,
            );
        }

        $local = $workDir.'/output.mp4';
        File::copy($store->absolutePath($outputPath), $local);

        $probe = Process::timeout(self::PROCESS_TIMEOUT_SECONDS)->run([
            config()->string('manual.render_ffprobe_binary'), ...FfmpegSafetyArguments::all(),
            '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $local,
        ]);
        if (! $probe->successful()) {
            return $this->gate(
                SmokeStage::Artifact, false, $startedAt,
                'ffprobe が失敗しました: '.trim($probe->errorOutput()),
                ffprobeFailed: true,
            );
        }

        [$duration, $videoStreams] = self::readProbe($probe->output());
        $ok = $videoStreams >= 1 && $duration > 0.0;

        return $this->gate(
            SmokeStage::Artifact,
            $ok,
            $startedAt,
            sprintf('duration=%.2fs video_streams=%d', $duration, $videoStreams),
            ffprobeFailed: false,
        );
    }

    /**
     * ffprobe の JSON から [尺 (秒), 映像ストリーム数] を取り出す。
     *
     * @return array{float, int}
     */
    private static function readProbe(string $json): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return [0.0, 0];
        }

        $format = $decoded['format'] ?? null;
        $duration = 0.0;
        if (is_array($format) && isset($format['duration']) && is_numeric($format['duration'])) {
            $duration = (float) $format['duration'];
        }

        $streams = $decoded['streams'] ?? null;
        $videoStreams = 0;
        if (is_array($streams)) {
            foreach ($streams as $stream) {
                if (is_array($stream) && ($stream['codec_type'] ?? null) === 'video') {
                    $videoStreams++;
                }
            }
        }

        return [$duration, $videoStreams];
    }

    // ─────────────────────────────────────────────────────────────────
    // 待機・記録・出力
    // ─────────────────────────────────────────────────────────────────

    /**
     * worker の完了待ち。失敗は待たずに即座に打ち切る。
     *
     * @return array{JobStatus, bool} [観測した状態, 上限に到達したか]
     */
    private function waitForJob(AnalysisJob|RenderJob $job, int $timeoutSeconds): array
    {
        $deadline = CarbonImmutable::now()->addSeconds($timeoutSeconds);
        while (true) {
            $job->refresh(); // 主キー同一性クエリを書かずに再読込する (インスタンス起点)
            if ($job->status === JobStatus::Succeeded || $job->status === JobStatus::Failed) {
                return [$job->status, false];
            }
            if (CarbonImmutable::now()->greaterThanOrEqualTo($deadline)) {
                return [$job->status, true];
            }
            sleep(self::POLL_INTERVAL_SECONDS);
        }
    }

    /** 段の結果を記録し、成功なら true を返す (呼び出し側はこれで打ち切りを判断する)。 */
    private function gate(
        SmokeStage $stage,
        bool $ok,
        CarbonImmutable $startedAt,
        string $detail,
        ?JobStatus $jobStatus = null,
        bool $timedOut = false,
        bool $hasRenderErrorCode = false,
        bool $outputReadable = true,
        bool $ffprobeFailed = false,
        bool $llmRecordingIncomplete = false,
    ): bool {
        $failureClass = SmokeFailureClassifier::classify(
            $stage,
            $ok,
            $jobStatus,
            $timedOut,
            $this->hasLlmFailureRow(),
            $this->hasLlmSuccessRow(),
            $llmRecordingIncomplete,
            $hasRenderErrorCode,
            $outputReadable,
            $ffprobeFailed,
        );
        $this->recordStage($stage, $ok, $startedAt, $detail, $failureClass);

        return $ok;
    }

    private function recordStage(
        SmokeStage $stage,
        bool $ok,
        CarbonImmutable $startedAt,
        string $detail,
        ?SmokeFailureClass $failureClass,
    ): void {
        $this->stages[] = new SmokeStageResultData(
            stage: $stage,
            ok: $ok,
            elapsedMs: self::elapsedMs($startedAt),
            detail: $detail,
            failureClass: $failureClass,
        );
    }

    /**
     * この実行分の llm_call_logs (母集団は必ず 3 template に絞る)。
     *
     * @return Builder<LlmCallLog>
     */
    private function runScopedLogs(): Builder
    {
        return LlmCallLog::query()
            ->where('id', '>', $this->baselineId ?? 0)   // 順序比較 = 主キー同一性クエリではない
            ->whereIn('prompt_template', self::REQUIRED_TEMPLATES);
    }

    private function hasLlmFailureRow(): bool
    {
        return $this->baselineId !== null && $this->runScopedLogs()->whereNotNull('failure_reason')->exists();
    }

    private function hasLlmSuccessRow(): bool
    {
        return $this->baselineId !== null
            && $this->runScopedLogs()->whereNull('failure_reason')->where('input_tokens', '>', 0)->exists();
    }

    /** この実行分のコストレポート (集計は LlmCostReportService 1 本。二重実装しない)。 */
    private function costReport(): ?LlmCostReportData
    {
        if ($this->baselineId === null) {
            return null;
        }

        return app(LlmCostReportService::class)->report(
            LlmCostGroupBy::PromptTemplate,
            afterId: $this->baselineId,
        );
    }

    /** 結果の組み立てと出力。 */
    private function finish(CarbonImmutable $startedAt, bool $checkOnly, ?LlmCostReportData $cost): int
    {
        $result = $this->buildResult($startedAt, $checkOnly, $cost, aborted: false);
        $this->emit($result);

        return $result->passed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * 実行前の中止 (確認で拒否された等)。**何も実行していない**ことを DTO で返す。
     * 出力経路は成功 / 失敗と同じ 1 本にし、`--json` だけ plain text になる穴を作らない。
     */
    private function abort(CarbonImmutable $startedAt, string $reason): int
    {
        $this->context['aborted'] = $reason;
        $this->emit($this->buildResult($startedAt, checkOnly: false, cost: null, aborted: true));

        return self::INVALID;
    }

    /** 段の記録から結果 DTO を組み立てる (中止時は段が無くても passed = false)。 */
    private function buildResult(
        CarbonImmutable $startedAt,
        bool $checkOnly,
        ?LlmCostReportData $cost,
        bool $aborted,
    ): SmokeRunResultData {
        $failureClass = null;
        foreach ($this->stages as $stage) {
            if (! $stage->ok) {
                $failureClass = $stage->failureClass;
                break;
            }
        }

        return new SmokeRunResultData(
            passed: ! $aborted && $failureClass === null,
            checkOnly: $checkOnly,
            context: $this->context,
            stages: $this->stages,
            failureClass: $failureClass,
            cost: $cost,
            totalElapsedMs: self::elapsedMs($startedAt),
        );
    }

    /**
     * 結果の出力 (成功 / 失敗 / 中止で**同じ 1 本**を通る)。
     *
     * ★ `--json` の契約は「**結果 JSON は最終行に 1 行で出る**」である。
     *   `--force` なしで実行すると `ConfirmableTrait` が人間向けの確認 UI を先に描くため、
     *   標準出力の先頭は JSON ではない (bug-hunt レーンの導線は常に `--force` を付けるので
     *   その経路では JSON だけが出る)。「stdout 全体が JSON」とは書かない。
     */
    private function emit(SmokeRunResultData $result): void
    {
        if ($this->option('json') === true) {
            $this->line(json_encode($result->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->renderHuman($result);
    }

    private function renderHuman(SmokeRunResultData $result): void
    {
        $this->line('== preflight ==');
        foreach ($result->context as $key => $value) {
            $this->line("{$key}={$value}");
        }

        $this->line('');
        $this->line('== stages ==');
        $this->table(['stage', 'status', 'elapsed', 'failure_class', 'detail'], array_map(
            static fn (SmokeStageResultData $stage): array => [
                $stage->stage->value,
                $stage->ok ? 'ok' : 'NG',
                sprintf('%.1fs', $stage->elapsedMs / 1000),
                $stage->failureClass->value ?? '-',
                $stage->detail,
            ],
            $result->stages,
        ));

        $cost = $result->cost;
        if ($cost !== null) {
            $this->line('');
            $this->line('== llm cost (this run) ==');
            $this->table(['prompt_template', 'calls', 'in_tok', 'out_tok', 'usd', 'jpy', 'meta_missing'], array_map(
                static fn (LlmCostRowData $row): array => [
                    $row->key,
                    (string) $row->calls,
                    (string) $row->inputTokens,
                    (string) $row->outputTokens,
                    $row->totalCostUsd ?? '-',
                    $row->totalCostJpy ?? '-',
                    (string) $row->metadataMissingCalls,
                ],
                [...$cost->rows, $cost->total],
            ));
            $this->line('注: JPY は各行の記録時レート (fx_snapshot) の合計であり単一レート換算ではない');
        }

        $this->line('');
        $this->line(sprintf(
            'RESULT: %s (total %.1fs%s)',
            $result->passed ? 'PASS' : (isset($result->context['aborted']) ? 'ABORT' : 'FAIL'),
            $result->totalElapsedMs / 1000,
            $result->failureClass === null ? '' : ', failure_class='.$result->failureClass->value,
        ));
    }

    /** @return int<0, max> */
    private static function elapsedMs(CarbonImmutable $startedAt): int
    {
        $elapsed = (int) round(abs(CarbonImmutable::now()->getPreciseTimestamp(3) - $startedAt->getPreciseTimestamp(3)));
        Assert::natural($elapsed);

        return $elapsed;
    }

    /** 例外の要約 (内部詳細を出しすぎず、どこで落ちたかが分かる程度)。 */
    private static function describe(Throwable $exception): string
    {
        return $exception::class.': '.Str::limit($exception->getMessage(), 300);
    }

    private function fixturePath(): string
    {
        return base_path('resources/fixtures/pipeline-smoke-sop.txt');
    }
}
