# Round 2: Round 1 の指摘への対応

Critical 0 件、Warning 2 件 + Suggestion 3 件 + 差分未提示 1 件 + 検証コマンド省略の指摘を受けた。
**すべて対応した**（反論・見送りはゼロ）。対応マトリクスは以下。

# 対応マトリクス: impl-review Round 1

Critical は 0 件。Warning 2 件と Suggestion 3 件を以下のとおり捌いた。

## [Warning] `runLlmEvidenceStage()` の帰属照合が設計より弱い (OR 畳み込み)

- 判断: **対応する** (指摘は正しい。実バグ)
- 根拠: 実装は `$attributed[$template] = true` を「1 行でも一致したら」立てていたため、
  同じ template に正しい行と壊れた行が混在すると pass していた。設計の
  「**成功行がすべて** `metadata_missing = false` ∧ 期待 organization / subject」を満たしていない。
  帰属が落ちる現実的な経路 (リトライ後の行だけ配線が抜ける等) を素通りさせる。
- 対応内容:
  - 畳み込みを **AND** にし、かつ**純関数へ切り出した**:
    `SmokeFailureClassifier::fullyAttributedTemplates(list<array{string, bool}>): list<string>`。
    DB 読み出しはコマンドに残し (設計の責務分割を維持)、集合演算だけを classifier に置いた
    = **DB なしの Unit テストで畳み込み規則を直接固定できる**。
  - 回帰テストを 4 件追加 (`SmokeFailureClassifierTest`):
    全一致 / **同一 template に正+誤が混在** / 誤が先に来る順序不変 / 観測 0 件。
    2 件目が指摘そのもの (OR 実装なら赤)。

## [Warning] `--json` が fail-secure 失敗時に機械可読出力にならない

- 判断: **対応する**
- 根拠: `--json` は「DTO `toArray()` → `json_encode` の 1 経路」が設計の契約であり、
  fail-secure 失敗だけ plain text になると機械側 (bug-hunt レーンの呼び出し元) が
  「出力が無い」と「失敗した」を区別できない。
- 対応内容: fail-secure 失敗を `preflight` 段として `recordStage()` し、
  通常の `finish()` を通す。`--json` は `failure_class=preflight` を含む DTO を返し、
  人間向けは段テーブルの detail に理由が出る。
  テスト追加: `fail-secure 失敗でも --json は DTO の 1 経路で機械可読出力を返す`。

## [Suggestion] preflight 表示に DB 名が出ていない

- 判断: **対応する**
- 根拠: 費用の防壁が「どの状態で」成立/不成立になったかを実行ログだけで読めるべき。
- 対応内容: `captureLaneContext()` を新設し、`env` / `db` / `fake_storage` / `fake_llm` を
  **実測値**として context に載せる (従来は `fake_storage=on` 等を決め打ちで出していた =
  fail-secure を通過した後にしか出ないとはいえ、期待値の写経だった)。
  fail-secure 失敗時にも context が出るようになったため、不成立の原因が 1 画面で読める。

## [Suggestion] `QueryException` catch が `resolveOrganization()` だけに限定されている

- 判断: **対応する**
- 根拠: 指摘どおり `users()` / `TicketLedgerService` / `DefaultProjectResolver` でも DB 例外は起きうる。
  `--json` 契約のための追加なら、DB を触る preflight 全体を同じ失敗 DTO に閉じるのが一貫する。
- 対応内容: DB を読む部分を `runDatabasePreflight()` に括り出し、呼び出し側の **1 箇所**で
  `QueryException` を捕まえる形にした。

## [Suggestion] DirectFetchInventory の justification 「対象は常に 1 組織」が強すぎる

- 判断: **対応する**
- 根拠: `--org` 省略時は eligible な組織を探索するため、文言が事実とずれている。
  目録の根拠は「実際に何をしているか」を正確に書くためのものなので、誇張は直す。
- 対応内容: 「`--org` 省略時は使い捨ての bug-hunt DB 内で条件を満たす組織を探索するが、
  最終的に触るのは選ばれた 1 組織だけで、組織を跨ぐ read/write は 1 箇所も無い」に修正。

## [Warning] 施策 9 (ドキュメント) が Round 1 の diff に含まれていなかった

- 判断: **対応する** (指摘は差分提示の不備。実装は存在する)
- 根拠: Round 1 の diff を `app/ tests/ database/ scripts/ resources/fixtures/` に絞ったため、
  `docs/architecture.md` / `AGENTS.md` / `.claude/skills/app-bug-hunt/SKILL.md` が未提示だった。
- 対応内容: Round 2 のプロンプトにドキュメント差分を全文添付する。

## [Verification] `pnpm test` / `pnpm build` / packages 系の省略

- 判断: **対応する** (省略をやめた)
- 根拠: 「全 green でコミット」が AGENTS.md の規約であり、UI 未変更を理由に自己判断で
  省略すると規約に対しては未完了になる。
- 対応内容: 全部実行した。`pnpm test` 130 files / 1299 tests passed、`pnpm build` 成功、
  `pnpm typecheck:packages` / `build:packages` / `test:packages` (10 files / 106 tests) すべて green。

## 追加/変更した実装差分 (Round 1 以降)

```diff
diff --git a/app/Console/Commands/Development/PipelineSmokeCommand.php b/app/Console/Commands/Development/PipelineSmokeCommand.php
new file mode 100644
index 0000000..9cf8461
--- /dev/null
+++ b/app/Console/Commands/Development/PipelineSmokeCommand.php
@@ -0,0 +1,962 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Console\Commands\Development;
+
+use App\DataTransferObjects\Capture\Sha256Checksum;
+use App\DataTransferObjects\Capture\TakeRegistrationInput;
+use App\DataTransferObjects\Capture\TakeUploadInput;
+use App\DataTransferObjects\LlmCostReportData;
+use App\DataTransferObjects\LlmCostRowData;
+use App\DataTransferObjects\Smoke\SmokeRunResultData;
+use App\DataTransferObjects\Smoke\SmokeStageResultData;
+use App\Enums\LlmCostGroupBy;
+use App\Enums\Manual\JobStatus;
+use App\Enums\Manual\TakeStatus;
+use App\Enums\Manual\VideoManualStatus;
+use App\Enums\Smoke\SmokeFailureClass;
+use App\Enums\Smoke\SmokeStage;
+use App\Jobs\Manual\RunManualAnalysis;
+use App\Jobs\Manual\RunManualRender;
+use App\Models\AnalysisJob;
+use App\Models\Cut;
+use App\Models\LlmCallLog;
+use App\Models\Organization;
+use App\Models\Project;
+use App\Models\RenderJob;
+use App\Models\User;
+use App\Models\VideoManual;
+use App\Services\Billing\TicketLedgerService;
+use App\Services\Capture\CaptureTakeService;
+use App\Services\Capture\TakeRegistrationService;
+use App\Services\Capture\TakeUploadService;
+use App\Services\LlmCostReportService;
+use App\Services\Manual\AnalysisJobService;
+use App\Services\Manual\RenderJobService;
+use App\Services\Manual\VideoManualService;
+use App\Services\Project\DefaultProjectResolver;
+use App\Services\Project\ProjectService;
+use App\Services\Storage\Fakes\FakeObjectStore;
+use App\Support\BughuntDatabaseGuard;
+use App\Support\FakeStorageGate;
+use App\Support\Smoke\SmokeFailureClassifier;
+use Carbon\CarbonImmutable;
+use Illuminate\Console\Command;
+use Illuminate\Console\ConfirmableTrait;
+use Illuminate\Database\Eloquent\Builder;
+use Illuminate\Database\QueryException;
+use Illuminate\Http\UploadedFile;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\File;
+use Illuminate\Support\Facades\Process;
+use Illuminate\Support\Str;
+use RuntimeException;
+use Throwable;
+use Webmozart\Assert\Assert;
+
+/**
+ * SOP 投入 → AI 解析 → 撮影テイク → ffmpeg 合成 → mp4 の全段が通ることを
+ * **実 LLM** で確認する通し確認コマンド (bug-hunt 専用・課金あり)。
+ *
+ * ★ **品質は一切判定しない**。見るのは「期待した状態遷移が起きたか」だけである
+ *   (字幕の文言・カット数の妥当性・尺の妥当性は判定しない)。
+ * ★ **保証しないもの**の一覧は `docs/architecture.md` §パイプライン通し確認 が正本。
+ * ★ constructor は引数を持たない。すべての依存は fail-secure 4 条件を通過した**後**に
+ *   `handle()` 内で遅延解決する (`artisan list` / `help` を含むあらゆる artisan 起動で
+ *   コマンドが構築されるため、fake が gate 判定より前に解決されるのを防ぐ)。
+ */
+class PipelineSmokeCommand extends Command
+{
+    use ConfirmableTrait;
+
+    /** worker 待ちのポーリング間隔 (秒)。 */
+    private const int POLL_INTERVAL_SECONDS = 2;
+
+    /** ジョブ側 timeout に足す余裕 (秒)。上限値はジョブ定数から導出し独立したリテラルを置かない。 */
+    private const int WAIT_MARGIN_SECONDS = 120;
+
+    /** テイク動画の尺 (秒)。1 本だけ生成して全 cut で使い回す。 */
+    private const int TAKE_SECONDS = 2;
+
+    /** 外部プロセスの実行上限 (秒)。 */
+    private const int PROCESS_TIMEOUT_SECONDS = 120;
+
+    /**
+     * `llm-evidence` 段で成功行を要求する prompt_template (母集団もこの集合で絞る)。
+     *
+     * @var list<string>
+     */
+    private const array REQUIRED_TEMPLATES = ['sop-extract', 'work-decomposition', 'scenario-generation'];
+
+    /** @var string */
+    protected $signature = 'dev:pipeline-smoke
+        {--check : preflight だけ実行して終了する (LLM を 1 回も呼ばない = 費用ゼロ)}
+        {--org= : 対象組織 id (省略時は条件を満たす先頭の組織)}
+        {--json : 機械可読出力}
+        {--force : 実行確認を省略する (fail-secure 条件は迂回できない)}';
+
+    /** @var string */
+    protected $description = 'SOP 投入→AI 解析→撮影テイク→ffmpeg 合成→mp4 の全段が通ることを実 LLM で確認する (bug-hunt 専用・課金あり)。';
+
+    /** @var list<SmokeStageResultData> 実行済みの段 */
+    private array $stages = [];
+
+    /** @var array<string, string> 実行対象の表示 (env / db / org / ffmpeg 版など) */
+    private array $context = [];
+
+    /** この実行分の境界 (llm_call_logs.id)。`--check` では取らない。 */
+    private ?int $baselineId = null;
+
+    public function handle(): int
+    {
+        $startedAt = CarbonImmutable::now();
+
+        // ── fail-secure 4 条件 (--force でも迂回できない) ──────────────
+        $blocker = $this->failSecureBlocker();
+        $this->captureLaneContext();
+        if ($blocker !== null) {
+            // 出力は DTO の 1 経路に揃える (--json が例外的に plain text にならないようにする)
+            $this->recordStage(
+                SmokeStage::Preflight,
+                false,
+                $startedAt,
+                "fail-secure 条件を満たしていないため実行しません: {$blocker}",
+                SmokeFailureClass::Preflight,
+            );
+
+            return $this->finish($startedAt, checkOnly: (bool) $this->option('check'), cost: null);
+        }
+
+        // ── preflight (--check はここまでで終了。DB を 1 行も変更しない) ──
+        $preflight = $this->runPreflight();
+        if ($preflight === null) {
+            return $this->finish($startedAt, checkOnly: (bool) $this->option('check'), cost: null);
+        }
+        [$organization, $actor] = $preflight;
+
+        if ($this->option('check') === true) {
+            return $this->finish($startedAt, checkOnly: true, cost: null);
+        }
+
+        if (! $this->confirmToProceed($this->costWarning(), true)) {
+            // ★第 2 引数 true = **常に**確認する。既定 callback は production でしか確認しないため、
+            //   bughunt.local では確認が一度も出ないまま課金が走ってしまう。
+            $this->warn('中止しました (何も実行していません)。');
+
+            return self::INVALID;
+        }
+
+        // 「この実行分」の境界。preflight 通過直後・fixture 段より前に 1 回だけ取る
+        // (将来どの段で LLM が増えても取りこぼさない)。0 件時は 0 = 全行対象。
+        $maxId = LlmCallLog::query()->max('id');
+        $this->baselineId = is_numeric($maxId) ? (int) $maxId : 0;
+
+        $workDir = storage_path('app/smoke/'.Str::ulid()->toString());
+        File::ensureDirectoryExists($workDir);
+
+        try {
+            $this->runStages($organization, $actor, $workDir);
+        } finally {
+            File::deleteDirectory($workDir);
+        }
+
+        return $this->finish($startedAt, checkOnly: false, cost: $this->costReport());
+    }
+
+    // ─────────────────────────────────────────────────────────────────
+    // fail-secure / preflight
+    // ─────────────────────────────────────────────────────────────────
+
+    /**
+     * fail-secure 4 条件。満たさない最初の条件の説明を返す (満たしていれば null)。
+     *
+     * 4 は**自プロセスの config** であり worker の設定は見ていない。worker が fake なら
+     * 「llm_call_logs の記録行が 0」として段 llm-evidence で落ちる (2 層で守る)。
+     */
+    private function failSecureBlocker(): ?string
+    {
+        if (! $this->laravel->environment('bughunt.local')) {
+            return 'env が bughunt.local ではありません (実 LLM / 実 ffmpeg / チケット消費を dev / production で走らせない)';
+        }
+        if (! app(BughuntDatabaseGuard::class)->isBughuntDatabase()) {
+            return '接続先が bug-hunt DB ではありません (dev DB へ fixture をばら撒かない)';
+        }
+        if (! app(FakeStorageGate::class)->enabled()) {
+            return 'fake storage が無効です (実 S3 へ書かない)';
+        }
+        if (config('testing.fake_llm') !== false) {
+            return 'fake LLM が有効です (fake のまま「通った」と報告しない)';
+        }
+
+        return null;
+    }
+
+    /**
+     * レーンの状態を表示用 context に写す (fail-secure の成立状態を実行ログだけで読めるようにする)。
+     * 値は**実測した状態**であり、期待値の決め打ちではない。
+     */
+    private function captureLaneContext(): void
+    {
+        $this->context['env'] = (string) $this->laravel->environment();
+        $this->context['db'] = DB::connection()->getDatabaseName();
+        $this->context['fake_storage'] = app(FakeStorageGate::class)->enabled() ? 'on' : 'off';
+        $this->context['fake_llm'] = config('testing.fake_llm') === false ? 'off' : 'on';
+    }
+
+    /**
+     * preflight。成功したら [対象組織, actor] を返し、失敗したら null を返す
+     * (`--check` の成功時も [組織, actor] を返し、呼び出し側がそこで打ち切る)。
+     *
+     * @return array{Organization, User}|null
+     */
+    private function runPreflight(): ?array
+    {
+        $startedAt = CarbonImmutable::now();
+
+        $ffmpegVersion = $this->probeBinary(config()->string('manual.render_ffmpeg_binary'));
+        $ffprobeVersion = $this->probeBinary(config()->string('manual.render_ffprobe_binary'));
+        $this->context['ffmpeg'] = $ffmpegVersion ?? 'MISSING';
+        $this->context['ffprobe'] = $ffprobeVersion ?? 'MISSING';
+        if ($ffmpegVersion === null || $ffprobeVersion === null) {
+            return $this->failPreflight($startedAt, 'ffmpeg / ffprobe を実行できません (manual.render_ffmpeg_binary / render_ffprobe_binary)');
+        }
+
+        foreach (['database-analysis', 'database-render'] as $connection) {
+            if (config("queue.connections.{$connection}") === null) {
+                return $this->failPreflight($startedAt, "queue connection {$connection} が未定義です");
+            }
+        }
+
+        $fixture = $this->fixturePath();
+        $contents = is_file($fixture) ? file_get_contents($fixture) : false;
+        if (! is_string($contents) || strlen($contents) < config()->integer('manual.analysis_min_text_bytes')) {
+            return $this->failPreflight($startedAt, "SOP fixture が読めないか短すぎます: {$fixture}");
+        }
+
+        // DB へ触る検査はここから (組織 / actor / 残高 / Project)。未 provision / 未 migrate の
+        // bug-hunt DB では例外になるが、それも preflight の失敗として扱う
+        // (--json の契約を壊さず、原因を段の detail に残す)。
+        try {
+            $resolved = $this->runDatabasePreflight($startedAt);
+        } catch (QueryException $exception) {
+            return $this->failPreflight(
+                $startedAt,
+                'DB を読めません (bug-hunt DB が未 provision / 未 migrate の可能性): '.self::describe($exception),
+            );
+        }
+        if ($resolved === null) {
+            return null; // 失敗は runDatabasePreflight が段として記録済み
+        }
+
+        $this->recordStage(SmokeStage::Preflight, true, $startedAt, 'ok', null);
+
+        return $resolved;
+    }
+
+    /**
+     * DB を読む preflight (組織 / actor / 残高 / Project)。
+     * 例外は呼び出し側の 1 箇所で `QueryException` として捕まえる (--json の契約を守る)。
+     *
+     * @return array{Organization, User}|null
+     */
+    private function runDatabasePreflight(CarbonImmutable $startedAt): ?array
+    {
+        $organization = $this->resolveOrganization();
+        if ($organization === null) {
+            return $this->failPreflight($startedAt, '条件を満たす組織が見つかりません (チケット残高と所属 user を確認してください)');
+        }
+        $this->context['org'] = '#'.$organization->id;
+
+        /** @var User|null $actor */
+        $actor = $organization->users()->orderBy('users.id')->first();
+        if ($actor === null) {
+            return $this->failPreflight($startedAt, "組織 #{$organization->id} に所属 user がいません");
+        }
+        $this->context['actor'] = '#'.$actor->id;
+
+        $balance = app(TicketLedgerService::class)->availableTrueBalance($organization);
+        $required = $this->requiredTickets();
+        $this->context['tickets'] = "{$balance} (required {$required})";
+        if ($balance < $required) {
+            return $this->failPreflight($startedAt, "チケット残高が不足しています (残高 {$balance} / 必要 {$required})");
+        }
+
+        // Project 不在は preflight の失敗にしない (fixture 段で作る)
+        $project = app(DefaultProjectResolver::class)->resolve($organization);
+        $this->context['project'] = $project === null ? 'will-create' : 'existing #'.$project->id;
+
+        return [$organization, $actor];
+    }
+
+    /** preflight 失敗の記録 (段の detail に理由をそのまま出す)。 */
+    private function failPreflight(CarbonImmutable $startedAt, string $reason): null
+    {
+        $this->recordStage(
+            SmokeStage::Preflight,
+            false,
+            $startedAt,
+            $reason,
+            SmokeFailureClassifier::classify(
+                SmokeStage::Preflight, false, null, false, false, false, false, false, true, false,
+            ),
+        );
+
+        return null;
+    }
+
+    /** `{binary} -version` の 1 行目 (実行できなければ null)。 */
+    private function probeBinary(string $binary): ?string
+    {
+        try {
+            $result = Process::timeout(self::PROCESS_TIMEOUT_SECONDS)->run([$binary, '-version']);
+        } catch (Throwable) {
+            return null;
+        }
+        if (! $result->successful()) {
+            return null;
+        }
+
+        $firstLine = strtok($result->output(), "\n");
+
+        return $firstLine === false ? 'unknown' : trim($firstLine);
+    }
+
+    /**
+     * 対象組織の解決。`--org` 指定があればその組織、無ければ条件を満たす先頭の組織。
+     *
+     * ★ `--org` の主キー指定は「運用者が CLI で組織を名指しする」形であり
+     *   `DirectFetchInventory` へ `OperatorInvokedConsoleCommand` として登録済み。
+     */
+    private function resolveOrganization(): ?Organization
+    {
+        $option = $this->option('org');
+        if (is_string($option) && $option !== '') {
+            if (! ctype_digit($option)) {
+                return null;
+            }
+
+            /** @var Organization|null */
+            return Organization::query()->whereKey((int) $option)->first();
+        }
+
+        $required = $this->requiredTickets();
+        $tickets = app(TicketLedgerService::class);
+        foreach (Organization::query()->orderBy('id')->cursor() as $organization) {
+            if (! $organization->users()->exists()) {
+                continue;
+            }
+            if ($tickets->availableTrueBalance($organization) >= $required) {
+                return $organization;
+            }
+        }
+
+        return null;
+    }
+
+    /** 1 回の通し確認が消費するチケット枚数 (解析 + レンダ)。 */
+    private function requiredTickets(): int
+    {
+        return config()->integer('manual.analysis_ticket_cost')
+            + config()->integer('manual.render_ticket_cost');
+    }
+
+    /** 確認プロンプトに出す警告文 (見積りは断定しない)。 */
+    private function costWarning(): string
+    {
+        return '実 LLM を 3 段呼び出し、チケットを '.$this->requiredTickets().' 枚消費します。'
+            .' 1 回あたりおよそ $0.07〜0.12 (リトライが起きると最大 3 倍程度)。'
+            .' 実測値は実行後のコストレポートに出ます。';
+    }
+
+    // ─────────────────────────────────────────────────────────────────
+    // 段の実行
+    // ─────────────────────────────────────────────────────────────────
+
+    private function runStages(Organization $organization, User $actor, string $workDir): void
+    {
+        $fixture = $this->runFixtureStage($organization, $actor, $workDir);
+        if ($fixture === null) {
+            return;
+        }
+        [$project, $manual] = $fixture;
+
+        if (! $this->runAnalysisStage($project, $manual, $actor)) {
+            return;
+        }
+        if (! $this->runLlmEvidenceStage($organization, $manual)) {
+            return;
+        }
+        if (! $this->runCaptureStage($organization, $project, $manual, $workDir)) {
+            return;
+        }
+        $renderJob = $this->runRenderStage($project, $manual, $actor);
+        if ($renderJob === null) {
+            return;
+        }
+        $this->runArtifactStage($renderJob, $workDir);
+    }
+
+    /**
+     * fixture 段: Default Project (不在時のみ作成) + SOP つき manual の作成。
+     *
+     * @return array{Project, VideoManual}|null
+     */
+    private function runFixtureStage(Organization $organization, User $actor, string $workDir): ?array
+    {
+        $startedAt = CarbonImmutable::now();
+        try {
+            $project = app(DefaultProjectResolver::class)->resolve($organization)
+                ?? app(ProjectService::class)->createProject($organization, 'pipeline-smoke', null);
+
+            // UploadedFile は保存時に元ファイルを触りうるため、fixture 本体ではなく複製を渡す
+            $localCopy = $workDir.'/pipeline-smoke-sop.txt';
+            File::copy($this->fixturePath(), $localCopy);
+
+            $manual = app(VideoManualService::class)->create(
+                $project,
+                'pipeline-smoke '.CarbonImmutable::now()->format('Y-m-d H:i'),
+                null,
+                $actor->id,
+                new UploadedFile($localCopy, 'pipeline-smoke-sop.txt', 'text/plain', null, test: true),
+            );
+
+            $documents = $manual->sourceDocuments()->count();
+            $ok = $manual->status === VideoManualStatus::Draft && $documents === 1;
+            $detail = "manual=#{$manual->id} documents={$documents} status={$manual->status->value}";
+
+            return $this->gate(SmokeStage::Fixture, $ok, $startedAt, $detail) ? [$project, $manual] : null;
+        } catch (Throwable $exception) {
+            $this->gate(SmokeStage::Fixture, false, $startedAt, self::describe($exception));
+
+            return null;
+        }
+    }
+
+    /** analysis 段: 解析ジョブを起票し worker の完了を待つ。 */
+    private function runAnalysisStage(Project $project, VideoManual $manual, User $actor): bool
+    {
+        $startedAt = CarbonImmutable::now();
+        try {
+            $job = app(AnalysisJobService::class)->trigger($project, $manual, $actor);
+        } catch (Throwable $exception) {
+            return $this->gate(SmokeStage::Analysis, false, $startedAt, self::describe($exception));
+        }
+
+        $timeout = (new RunManualAnalysis(0))->timeout + self::WAIT_MARGIN_SECONDS;
+        [$status, $timedOut] = $this->waitForJob($job, $timeout);
+
+        $manual->refresh();
+        $cuts = $manual->cuts()->count();
+        $ok = $status === JobStatus::Succeeded
+            && $manual->status === VideoManualStatus::Ready
+            && $cuts >= 1
+            && $manual->scenario_version >= 1;
+        $detail = "job=#{$job->id} status={$status->value} cuts={$cuts}"
+            ." scenario_version={$manual->scenario_version}"
+            .($job->error === null ? '' : " error={$job->error}")
+            .($job->step === null ? '' : " step={$job->step->value}");
+
+        return $this->gate(SmokeStage::Analysis, $ok, $startedAt, $detail, $status, $timedOut);
+    }
+
+    /**
+     * llm-evidence 段 (DB 読み取りのみ): 実呼び出しの証拠と帰属の証拠。
+     *
+     * これが「施策 1 の配線が実 LLM 経路で本当に効いていること」の唯一の機械的な確認である
+     * (テストレーンでは Prompt::$fake がイベントを発火しないため観測できない)。
+     */
+    private function runLlmEvidenceStage(Organization $organization, VideoManual $manual): bool
+    {
+        $startedAt = CarbonImmutable::now();
+
+        $succeeded = [];
+        /** @var list<array{string, bool}> $observations 成功行ごとの (template, 帰属が期待どおりか) */
+        $observations = [];
+        $mismatches = [];
+        foreach ($this->runScopedLogs()->whereNull('failure_reason')->where('input_tokens', '>', 0)->get() as $log) {
+            $template = $log->prompt_template;
+            if ($template === null) {
+                continue;
+            }
+            $succeeded[$template] = true;
+
+            $expectedType = $manual->getMorphClass();
+            $expectedId = (string) $manual->id;
+            $matched = ! $log->metadata_missing
+                && $log->organization_id === $organization->id
+                && $log->subject_type === $expectedType
+                && $log->subject_id === $expectedId;
+            // 畳み込み (AND) は純関数へ委譲する。ここは DB から観測を集めるだけ
+            $observations[] = [$template, $matched];
+            if ($matched) {
+                continue;
+            }
+            $mismatches[] = sprintf(
+                '%s: organization_id=%s subject_type=%s subject_id=%s metadata_missing=%s'
+                .' (期待 organization_id=%d subject_type=%s subject_id=%s)',
+                $template,
+                $log->organization_id === null ? 'null' : (string) $log->organization_id,
+                $log->subject_type ?? 'null',
+                $log->subject_id ?? 'null',
+                $log->metadata_missing ? 'true' : 'false',
+                $organization->id,
+                $expectedType,
+                $expectedId,
+            );
+        }
+
+        $succeededTemplates = array_keys($succeeded);
+        $attributedTemplates = SmokeFailureClassifier::fullyAttributedTemplates($observations);
+        $missingTemplates = array_values(array_diff(self::REQUIRED_TEMPLATES, $succeededTemplates));
+        $incomplete = SmokeFailureClassifier::llmRecordingIncomplete(
+            self::REQUIRED_TEMPLATES,
+            $succeededTemplates,
+            $attributedTemplates,
+        );
+
+        $ok = $missingTemplates === [] && ! $incomplete;
+        $detail = sprintf(
+            'succeeded=%d/%d attributed=%d/%d retry_rows=%d',
+            count($succeededTemplates),
+            count(self::REQUIRED_TEMPLATES),
+            count($attributedTemplates),
+            count(self::REQUIRED_TEMPLATES),
+            $this->runScopedLogs()->whereNotNull('failure_reason')->count(),
+        );
+        if ($missingTemplates !== []) {
+            $detail .= ' 成功行が無い template: '.implode(', ', $missingTemplates);
+        }
+        if ($mismatches !== []) {
+            $detail .= ' 帰属が期待と違う行: '.implode(' / ', $mismatches);
+        }
+
+        return $this->gate(
+            SmokeStage::LlmEvidence,
+            $ok,
+            $startedAt,
+            $detail,
+            llmRecordingIncomplete: $incomplete,
+        );
+    }
+
+    /** capture 段: 全 cut にテイクを 1 本ずつ置いて採用する (動画は 1 本生成して使い回す)。 */
+    private function runCaptureStage(
+        Organization $organization,
+        Project $project,
+        VideoManual $manual,
+        string $workDir,
+    ): bool {
+        $startedAt = CarbonImmutable::now();
+        try {
+            $takePath = $this->generateTakeVideo($workDir);
+            $sizeBytes = filesize($takePath);
+            Assert::integer($sizeBytes, 'テイク動画のサイズを取得できません');
+            $digest = hash_file('sha256', $takePath, binary: true);
+            Assert::string($digest, 'テイク動画の sha256 を計算できません');
+            $checksum = Sha256Checksum::fromBase64(base64_encode($digest));
+
+            $adopted = 0;
+            /** @var Cut $cut */
+            foreach ($manual->cuts()->orderBy('id')->get() as $cut) {
+                $this->uploadAndAdoptTake($organization, $project, $manual, $cut, $takePath, $sizeBytes, $checksum);
+                $adopted++;
+            }
+
+            // 採用テイクの有無は relation 経由で数える (採用キーの列名を持ち出さない)
+            $unadopted = $manual->cuts()->doesntHave('adoptedTake')->count();
+            $ok = $adopted >= 1 && $unadopted === 0;
+
+            return $this->gate(SmokeStage::Capture, $ok, $startedAt, "takes={$adopted} unadopted={$unadopted}");
+        } catch (Throwable $exception) {
+            return $this->gate(SmokeStage::Capture, false, $startedAt, self::describe($exception));
+        }
+    }
+
+    /** 1 cut 分の presign → オブジェクト書き込み → 登録 → 採用。 */
+    private function uploadAndAdoptTake(
+        Organization $organization,
+        Project $project,
+        VideoManual $manual,
+        Cut $cut,
+        string $takePath,
+        int $sizeBytes,
+        Sha256Checksum $checksum,
+    ): void {
+        $clientTakeId = Str::ulid()->toString();
+        $ticket = app(TakeUploadService::class)->issue(
+            $organization,
+            $project,
+            $manual,
+            $cut,
+            new TakeUploadInput($clientTakeId, $sizeBytes, 'video/mp4', $checksum),
+        );
+
+        // 予約行は必ず organization → project → manual → cut の確認済み relation から辿る
+        // (presigned URL を parse して key を復元しない / payload から tenant キーを復元しない)
+        $reservation = $cut->uploadReservations()
+            ->where('client_take_id', $clientTakeId)
+            ->latest('id')
+            ->firstOrFail();
+
+        $stream = fopen($takePath, 'rb');
+        Assert::resource($stream, null, 'テイク動画を開けません');
+        try {
+            app(FakeObjectStore::class)->storeStreamed(
+                $reservation->video_path,
+                $stream,
+                'video/mp4',
+                $checksum->base64,
+            );
+        } finally {
+            fclose($stream);
+        }
+
+        $result = app(TakeRegistrationService::class)->register(
+            $project,
+            $manual,
+            $cut,
+            new TakeRegistrationInput($ticket->ticket, $clientTakeId, self::TAKE_SECONDS * 1000, null),
+        );
+        if ($result->take->status !== TakeStatus::Ready) {
+            throw new RuntimeException("テイクが ready になりません: take=#{$result->take->id} status={$result->take->status->value}");
+        }
+
+        app(CaptureTakeService::class)->adopt($project, $manual, $cut, $result->take);
+    }
+
+    /** ダミーのテイク動画を 1 本だけ生成する (全 cut で使い回す)。 */
+    private function generateTakeVideo(string $workDir): string
+    {
+        $path = $workDir.'/take.mp4';
+        $result = Process::path($workDir)->timeout(self::PROCESS_TIMEOUT_SECONDS)->run([
+            config()->string('manual.render_ffmpeg_binary'), '-y',
+            '-f', 'lavfi', '-i', 'testsrc2=size=640x360:rate=30:duration='.self::TAKE_SECONDS,
+            '-f', 'lavfi', '-i', 'sine=frequency=440:duration='.self::TAKE_SECONDS,
+            '-c:v', 'libx264', '-preset', 'veryfast', '-pix_fmt', 'yuv420p',
+            '-c:a', 'aac', '-ar', '48000', '-ac', '2', '-shortest',
+            $path,
+        ]);
+        if (! $result->successful() || ! is_file($path)) {
+            throw new RuntimeException('テイク動画を生成できません: '.trim($result->errorOutput()));
+        }
+
+        return $path;
+    }
+
+    /** render 段: レンダジョブを起票し worker の完了を待つ。 */
+    private function runRenderStage(Project $project, VideoManual $manual, User $actor): ?RenderJob
+    {
+        $startedAt = CarbonImmutable::now();
+        try {
+            $job = app(RenderJobService::class)->trigger($project, $manual, $actor);
+        } catch (Throwable $exception) {
+            $this->gate(SmokeStage::Render, false, $startedAt, self::describe($exception));
+
+            return null;
+        }
+
+        $timeout = (new RunManualRender(0))->timeout + self::WAIT_MARGIN_SECONDS;
+        [$status, $timedOut] = $this->waitForJob($job, $timeout);
+
+        $manual->refresh();
+        $ok = $status === JobStatus::Succeeded
+            && $manual->status === VideoManualStatus::Published
+            && $job->output_path !== null;
+        $detail = "job=#{$job->id} status={$status->value} manual_status={$manual->status->value}"
+            .' output='.($job->output_path ?? 'null')
+            .($job->error_code === null ? '' : " error_code={$job->error_code->value}");
+
+        $passed = $this->gate(
+            SmokeStage::Render,
+            $ok,
+            $startedAt,
+            $detail,
+            $status,
+            $timedOut,
+            hasRenderErrorCode: $job->error_code !== null,
+        );
+
+        return $passed ? $job : null;
+    }
+
+    /** artifact 段: 出力オブジェクトを ffprobe で読む (品質は見ない。尺 > 0 と映像ストリームのみ)。 */
+    private function runArtifactStage(RenderJob $job, string $workDir): bool
+    {
+        $startedAt = CarbonImmutable::now();
+        $outputPath = $job->output_path;
+        Assert::stringNotEmpty($outputPath, 'render 段の成功条件が output_path 非 null を保証している');
+
+        $store = app(FakeObjectStore::class);
+        if ($store->head($outputPath) === null) {
+            return $this->gate(
+                SmokeStage::Artifact, false, $startedAt,
+                "出力オブジェクトを読み出せません: {$outputPath}",
+                outputReadable: false,
+            );
+        }
+
+        $local = $workDir.'/output.mp4';
+        File::copy($store->absolutePath($outputPath), $local);
+
+        $probe = Process::timeout(self::PROCESS_TIMEOUT_SECONDS)->run([
+            config()->string('manual.render_ffprobe_binary'),
+            '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $local,
+        ]);
+        if (! $probe->successful()) {
+            return $this->gate(
+                SmokeStage::Artifact, false, $startedAt,
+                'ffprobe が失敗しました: '.trim($probe->errorOutput()),
+                ffprobeFailed: true,
+            );
+        }
+
+        [$duration, $videoStreams] = self::readProbe($probe->output());
+        $ok = $videoStreams >= 1 && $duration > 0.0;
+
+        return $this->gate(
+            SmokeStage::Artifact,
+            $ok,
+            $startedAt,
+            sprintf('duration=%.2fs video_streams=%d', $duration, $videoStreams),
+            ffprobeFailed: false,
+        );
+    }
+
+    /**
+     * ffprobe の JSON から [尺 (秒), 映像ストリーム数] を取り出す。
+     *
+     * @return array{float, int}
+     */
+    private static function readProbe(string $json): array
+    {
+        /** @var mixed $decoded */
+        $decoded = json_decode($json, true);
+        if (! is_array($decoded)) {
+            return [0.0, 0];
+        }
+
+        $format = $decoded['format'] ?? null;
+        $duration = 0.0;
+        if (is_array($format) && isset($format['duration']) && is_numeric($format['duration'])) {
+            $duration = (float) $format['duration'];
+        }
+
+        $streams = $decoded['streams'] ?? null;
+        $videoStreams = 0;
+        if (is_array($streams)) {
+            foreach ($streams as $stream) {
+                if (is_array($stream) && ($stream['codec_type'] ?? null) === 'video') {
+                    $videoStreams++;
+                }
+            }
+        }
+
+        return [$duration, $videoStreams];
+    }
+
+    // ─────────────────────────────────────────────────────────────────
+    // 待機・記録・出力
+    // ─────────────────────────────────────────────────────────────────
+
+    /**
+     * worker の完了待ち。失敗は待たずに即座に打ち切る。
+     *
+     * @return array{JobStatus, bool} [観測した状態, 上限に到達したか]
+     */
+    private function waitForJob(AnalysisJob|RenderJob $job, int $timeoutSeconds): array
+    {
+        $deadline = CarbonImmutable::now()->addSeconds($timeoutSeconds);
+        while (true) {
+            $job->refresh(); // 主キー同一性クエリを書かずに再読込する (インスタンス起点)
+            if ($job->status === JobStatus::Succeeded || $job->status === JobStatus::Failed) {
+                return [$job->status, false];
+            }
+            if (CarbonImmutable::now()->greaterThanOrEqualTo($deadline)) {
+                return [$job->status, true];
+            }
+            sleep(self::POLL_INTERVAL_SECONDS);
+        }
+    }
+
+    /** 段の結果を記録し、成功なら true を返す (呼び出し側はこれで打ち切りを判断する)。 */
+    private function gate(
+        SmokeStage $stage,
+        bool $ok,
+        CarbonImmutable $startedAt,
+        string $detail,
+        ?JobStatus $jobStatus = null,
+        bool $timedOut = false,
+        bool $hasRenderErrorCode = false,
+        bool $outputReadable = true,
+        bool $ffprobeFailed = false,
+        bool $llmRecordingIncomplete = false,
+    ): bool {
+        $failureClass = SmokeFailureClassifier::classify(
+            $stage,
+            $ok,
+            $jobStatus,
+            $timedOut,
+            $this->hasLlmFailureRow(),
+            $this->hasLlmSuccessRow(),
+            $llmRecordingIncomplete,
+            $hasRenderErrorCode,
+            $outputReadable,
+            $ffprobeFailed,
+        );
+        $this->recordStage($stage, $ok, $startedAt, $detail, $failureClass);
+
+        return $ok;
+    }
+
+    private function recordStage(
+        SmokeStage $stage,
+        bool $ok,
+        CarbonImmutable $startedAt,
+        string $detail,
+        ?SmokeFailureClass $failureClass,
+    ): void {
+        $this->stages[] = new SmokeStageResultData(
+            stage: $stage,
+            ok: $ok,
+            elapsedMs: self::elapsedMs($startedAt),
+            detail: $detail,
+            failureClass: $failureClass,
+        );
+    }
+
+    /**
+     * この実行分の llm_call_logs (母集団は必ず 3 template に絞る)。
+     *
+     * @return Builder<LlmCallLog>
+     */
+    private function runScopedLogs(): Builder
+    {
+        return LlmCallLog::query()
+            ->where('id', '>', $this->baselineId ?? 0)   // 順序比較 = 主キー同一性クエリではない
+            ->whereIn('prompt_template', self::REQUIRED_TEMPLATES);
+    }
+
+    private function hasLlmFailureRow(): bool
+    {
+        return $this->baselineId !== null && $this->runScopedLogs()->whereNotNull('failure_reason')->exists();
+    }
+
+    private function hasLlmSuccessRow(): bool
+    {
+        return $this->baselineId !== null
+            && $this->runScopedLogs()->whereNull('failure_reason')->where('input_tokens', '>', 0)->exists();
+    }
+
+    /** この実行分のコストレポート (集計は LlmCostReportService 1 本。二重実装しない)。 */
+    private function costReport(): ?LlmCostReportData
+    {
+        if ($this->baselineId === null) {
+            return null;
+        }
+
+        return app(LlmCostReportService::class)->report(
+            LlmCostGroupBy::PromptTemplate,
+            afterId: $this->baselineId,
+        );
+    }
+
+    /** 結果の組み立てと出力。 */
+    private function finish(CarbonImmutable $startedAt, bool $checkOnly, ?LlmCostReportData $cost): int
+    {
+        $failureClass = null;
+        foreach ($this->stages as $stage) {
+            if (! $stage->ok) {
+                $failureClass = $stage->failureClass;
+                break;
+            }
+        }
+        $passed = $failureClass === null;
+
+        $result = new SmokeRunResultData(
+            passed: $passed,
+            checkOnly: $checkOnly,
+            context: $this->context,
+            stages: $this->stages,
+            failureClass: $failureClass,
+            cost: $cost,
+            totalElapsedMs: self::elapsedMs($startedAt),
+        );
+
+        if ($this->option('json') === true) {
+            $this->line(json_encode($result->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
+        } else {
+            $this->renderHuman($result);
+        }
+
+        return $passed ? self::SUCCESS : self::FAILURE;
+    }
+
+    private function renderHuman(SmokeRunResultData $result): void
+    {
+        $this->line('== preflight ==');
+        foreach ($result->context as $key => $value) {
+            $this->line("{$key}={$value}");
+        }
+
+        $this->line('');
+        $this->line('== stages ==');
+        $this->table(['stage', 'status', 'elapsed', 'failure_class', 'detail'], array_map(
+            static fn (SmokeStageResultData $stage): array => [
+                $stage->stage->value,
+                $stage->ok ? 'ok' : 'NG',
+                sprintf('%.1fs', $stage->elapsedMs / 1000),
+                $stage->failureClass->value ?? '-',
+                $stage->detail,
+            ],
+            $result->stages,
+        ));
+
+        $cost = $result->cost;
+        if ($cost !== null) {
+            $this->line('');
+            $this->line('== llm cost (this run) ==');
+            $this->table(['prompt_template', 'calls', 'in_tok', 'out_tok', 'usd', 'jpy', 'meta_missing'], array_map(
+                static fn (LlmCostRowData $row): array => [
+                    $row->key,
+                    (string) $row->calls,
+                    (string) $row->inputTokens,
+                    (string) $row->outputTokens,
+                    $row->totalCostUsd ?? '-',
+                    $row->totalCostJpy ?? '-',
+                    (string) $row->metadataMissingCalls,
+                ],
+                [...$cost->rows, $cost->total],
+            ));
+            $this->line('注: JPY は各行の記録時レート (fx_snapshot) の合計であり単一レート換算ではない');
+        }
+
+        $this->line('');
+        $this->line(sprintf(
+            'RESULT: %s (total %.1fs%s)',
+            $result->passed ? 'PASS' : 'FAIL',
+            $result->totalElapsedMs / 1000,
+            $result->failureClass === null ? '' : ', failure_class='.$result->failureClass->value,
+        ));
+    }
+
+    /** @return int<0, max> */
+    private static function elapsedMs(CarbonImmutable $startedAt): int
+    {
+        $elapsed = (int) round(abs(CarbonImmutable::now()->getPreciseTimestamp(3) - $startedAt->getPreciseTimestamp(3)));
+        Assert::natural($elapsed);
+
+        return $elapsed;
+    }
+
+    /** 例外の要約 (内部詳細を出しすぎず、どこで落ちたかが分かる程度)。 */
+    private static function describe(Throwable $exception): string
+    {
+        return $exception::class.': '.Str::limit($exception->getMessage(), 300);
+    }
+
+    private function fixturePath(): string
+    {
+        return base_path('resources/fixtures/pipeline-smoke-sop.txt');
+    }
+}
diff --git a/app/Support/Smoke/SmokeFailureClassifier.php b/app/Support/Smoke/SmokeFailureClassifier.php
new file mode 100644
index 0000000..90bf351
--- /dev/null
+++ b/app/Support/Smoke/SmokeFailureClassifier.php
@@ -0,0 +1,130 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Smoke;
+
+use App\Enums\Manual\JobStatus;
+use App\Enums\Smoke\SmokeFailureClass;
+use App\Enums\Smoke\SmokeStage;
+
+/**
+ * pipeline smoke の失敗分類器 (純関数)。
+ * 配置と流儀は `App\Support\Billing\GatewayFailureClassifier` に合わせている。
+ *
+ * 判定順 (先に一致したものを返す):
+ *  1. 段が成功 → null (分類しない)
+ *  2. preflight → Preflight
+ *  3. timeout ∧ queued → Wiring / 4. timeout ∧ running → StageTimeout
+ *  5. render ∧ error_code → Render
+ *  6. artifact ∧ 読めない → Storage / 7. artifact ∧ ffprobe 失敗 → Render
+ *  8. llm-evidence ∧ 成功行あり ∧ 記録不完全 → Wiring
+ *  9. LLM 起因になり得る段 ∧ (failure 行あり ∨ 成功行なし) → Llm
+ * 10. それ以外 → Unknown
+ */
+final readonly class SmokeFailureClassifier
+{
+    /**
+     * LLM が原因になり得る段 (`Llm` 分類の適用範囲を**この集合に閉じる**)。
+     *
+     * @var list<SmokeStage>
+     */
+    private const array LLM_ATTRIBUTABLE_STAGES = [SmokeStage::Analysis, SmokeStage::LlmEvidence];
+
+    /**
+     * 失敗の観測分類。**成功した段では null を返す**。
+     *
+     * @param  bool  $stageSucceeded  段が成功したか
+     * @param  ?JobStatus  $jobStatus  観測したジョブ状態 (段によっては null)
+     * @param  bool  $timedOut  待機上限に到達したか
+     * @param  bool  $hasLlmFailureRow  この実行分に failure_reason 行があるか
+     * @param  bool  $hasLlmSuccessRow  この実行分に成功行があるか
+     * @param  bool  $llmRecordingIncomplete  成功行はあるが記録が不完全か (帰属欠落 or template 欠落)
+     * @param  bool  $hasRenderErrorCode  render_jobs.error_code が非 null か
+     * @param  bool  $outputReadable  出力オブジェクトを読み出せたか
+     * @param  bool  $ffprobeFailed  ffprobe が非 0 終了したか
+     */
+    public static function classify(
+        SmokeStage $stage,
+        bool $stageSucceeded,
+        ?JobStatus $jobStatus,
+        bool $timedOut,
+        bool $hasLlmFailureRow,
+        bool $hasLlmSuccessRow,
+        bool $llmRecordingIncomplete,
+        bool $hasRenderErrorCode,
+        bool $outputReadable,
+        bool $ffprobeFailed,
+    ): ?SmokeFailureClass {
+        if ($stageSucceeded) {
+            return null; // 成功時のリトライ痕 (failure_reason 行) を失敗として分類しない
+        }
+
+        return match (true) {
+            $stage === SmokeStage::Preflight => SmokeFailureClass::Preflight,
+            $timedOut && $jobStatus === JobStatus::Queued => SmokeFailureClass::Wiring,
+            $timedOut && $jobStatus === JobStatus::Running => SmokeFailureClass::StageTimeout,
+            $stage === SmokeStage::Render && $hasRenderErrorCode => SmokeFailureClass::Render,
+            $stage === SmokeStage::Artifact && ! $outputReadable => SmokeFailureClass::Storage,
+            $stage === SmokeStage::Artifact && $ffprobeFailed => SmokeFailureClass::Render,
+            // LLM は動いているのにアプリ側の記録経路が欠けている = 配線の問題 (provider の問題ではない)
+            $stage === SmokeStage::LlmEvidence && $hasLlmSuccessRow && $llmRecordingIncomplete => SmokeFailureClass::Wiring,
+            in_array($stage, self::LLM_ATTRIBUTABLE_STAGES, true)
+                && ($hasLlmFailureRow || ! $hasLlmSuccessRow) => SmokeFailureClass::Llm,
+            default => SmokeFailureClass::Unknown,
+        };
+    }
+
+    /**
+     * 成功行ごとの観測を「**その成功行がすべて**帰属していた template」へ畳み込む (AND)。
+     *
+     * OR で畳むと「正しい行が 1 本あれば通る」になり、設計の
+     * 「成功行がすべて `metadata_missing = false` ∧ 期待した organization / subject」を満たさない
+     * (同じ template に正しい行と壊れた行が混在したときに見逃す)。
+     *
+     * DB 読み出しは呼び出し側 (コマンド) が行い、本関数は集合演算だけを行う
+     * = DB なしの Unit テストで畳み込み規則を直接固定できる。
+     *
+     * @param  list<array{string, bool}>  $observations  成功行ごとの (prompt_template, 帰属が期待どおりか)
+     * @return list<string>  出現順を保った template 名 (すべての行で帰属が期待どおりだったもの)
+     */
+    public static function fullyAttributedTemplates(array $observations): array
+    {
+        /** @var array<string, bool> $byTemplate */
+        $byTemplate = [];
+        foreach ($observations as [$template, $matched]) {
+            $byTemplate[$template] = ($byTemplate[$template] ?? true) && $matched;
+        }
+
+        return array_keys(array_filter($byTemplate));
+    }
+
+    /**
+     * 「LLM は成功しているのに記録が欠けている」の導出 (純関数。DB 読み出しは呼び出し側の責務)。
+     *
+     * 2 原因をまとめて 1 つの bool にする:
+     *   - 必要 template の成功行が足りない (analysis は成功したのに記録が落ちた)
+     *   - 成功行はあるが帰属 (organization / subject) が期待と違う
+     *
+     * ★ 呼び出し側の責務: `$succeededTemplates` / `$attributedTemplates` は
+     *   **`$requiredTemplates` に限定した集合**であること (クエリに
+     *   `->whereIn('prompt_template', $requiredTemplates)` を付ければ足りる)。
+     *   対象外の template が混ざると本 smoke と無関係な行まで「不完全」と判定してしまう。
+     *
+     * @param  list<string>  $requiredTemplates  期待する prompt_template (3 段)
+     * @param  list<string>  $succeededTemplates  この実行分の成功行が存在した template (required に限定)
+     * @param  list<string>  $attributedTemplates  うち帰属が期待どおりだった template (required に限定)
+     */
+    public static function llmRecordingIncomplete(
+        array $requiredTemplates,
+        array $succeededTemplates,
+        array $attributedTemplates,
+    ): bool {
+        if ($succeededTemplates === []) {
+            return false; // 成功行が 1 行も無いのは「記録の不備」ではなく Llm 側の疑い
+        }
+
+        return array_diff($requiredTemplates, $succeededTemplates) !== []
+            || array_diff($succeededTemplates, $attributedTemplates) !== [];
+    }
+}
diff --git a/tests/Feature/Console/PipelineSmokeCommandTest.php b/tests/Feature/Console/PipelineSmokeCommandTest.php
new file mode 100644
index 0000000..4a3450a
--- /dev/null
+++ b/tests/Feature/Console/PipelineSmokeCommandTest.php
@@ -0,0 +1,298 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Organization;
+use App\Models\Project;
+use App\Models\User;
+use App\Models\VideoManual;
+use App\Services\Billing\TicketLedgerService;
+use App\Services\Project\ProjectService;
+use Illuminate\Console\Command;
+use Illuminate\Support\Facades\Artisan;
+use Illuminate\Support\Facades\DB;
+use Kent013\PrismPrompt\Prompt;
+
+/*
+ * pipeline smoke コマンド (施策 6) の**固有ロジック**を実 LLM なしに固定する。
+ *
+ * 固定するのは「fail-secure 条件 / preflight / 確認 / 出力」まで。
+ * 各段の配線は段ごとの Feature テストが既に持っており、ffmpeg を Process::fake すると
+ * このコマンドの唯一の固有価値 (実 ffmpeg が本当に回るか) が消えて偽グリーンになるため、
+ * 全段を fake で通すテストは**書かない**。
+ * `llm-evidence` 段の判定は純関数として SmokeFailureClassifierTest が固定する。
+ */
+
+/**
+ * fail-secure 4 条件を満たす状態にする (bug-hunt レーン相当)。
+ *
+ * - env: bughunt.local
+ * - DB 名: bug_hunt (接続名だけを差し替える。実 DB はテスト DB のまま)
+ * - fake storage: on / fake LLM: off
+ * - ffmpeg / ffprobe: PHP バイナリで代用 (`-version` が 0 終了する = preflight の分岐だけを固定する)
+ */
+function enterSmokeLane(): void
+{
+    app()->detectEnvironment(fn (): string => 'bughunt.local');
+    DB::connection()->setDatabaseName('bug_hunt');
+    config()->set('testing.fake_storage', true);
+    config()->set('testing.fake_llm', false);
+    config()->set('manual.render_ffmpeg_binary', PHP_BINARY);
+    config()->set('manual.render_ffprobe_binary', PHP_BINARY);
+}
+
+/**
+ * preflight を通せる組織 (所属 user あり・チケット残高十分) を作る。
+ *
+ * @return array{Organization, User}
+ */
+function smokeReadyOrganization(int $tickets = 100): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    app(TicketLedgerService::class)->grant($organization, $tickets, 'pipeline-smoke test');
+
+    return [$organization, $owner];
+}
+
+/**
+ * @param  array<string, mixed>  $parameters
+ * @return array{int, string}
+ */
+function runPipelineSmoke(array $parameters = []): array
+{
+    $exitCode = Artisan::call('dev:pipeline-smoke', $parameters);
+
+    return [$exitCode, Artisan::output()];
+}
+
+// ── fail-secure 4 条件 (--force でも迂回できない) ───────────────────────
+
+it('bughunt.local 以外の env では実行しない', function (): void {
+    smokeReadyOrganization();
+    // enterSmokeLane() を呼ばない = env は testing のまま
+
+    [$exitCode, $output] = runPipelineSmoke(['--check' => true]);
+
+    expect($exitCode)->toBe(Command::FAILURE)
+        ->and($output)->toContain('env が bughunt.local ではありません')
+        ->and(Prompt::isFaking())->toBeFalse();
+});
+
+it('fail-secure 失敗でも --json は DTO の 1 経路で機械可読出力を返す', function (): void {
+    smokeReadyOrganization();
+    // env は testing のまま = fail-secure 1 で落ちる
+
+    [$exitCode, $output] = runPipelineSmoke(['--check' => true, '--json' => true]);
+    $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
+
+    expect($exitCode)->toBe(Command::FAILURE)
+        ->and($decoded['passed'])->toBeFalse()
+        ->and($decoded['failure_class'])->toBe('preflight')
```

## Round 1 で未提示だったドキュメント差分 (施策 9)

```diff
diff --git a/.claude/skills/app-bug-hunt/SKILL.md b/.claude/skills/app-bug-hunt/SKILL.md
index 4b8b989..97743f8 100644
--- a/.claude/skills/app-bug-hunt/SKILL.md
+++ b/.claude/skills/app-bug-hunt/SKILL.md
@@ -74,7 +74,12 @@ ## 禁止事項 — 絶対遵守
    実リクエストは従来どおり全面禁止**で、検知したら即中断して報告する (egress ガードの許可先に LLM API ドメインを
    加えるだけで、他は不変。SSRF/egress ガードの他ドメイン全面禁止は変わらない)。`--fake-llm` 時は LLM も canned
    (実接続なし)。real-llm は実キー必須で、未設定なら provision が fail-fast する (`--fake-llm` を案内)。
-5. **誤検知をバグとして断定しない。** 期待仕様が設計文書 (devnotes/docs) から確認できないものは
+5. **`pipeline-smoke` を実行しない。** `scripts/bug-hunt-shard.sh pipeline-smoke` は
+   **LLM を 3 段とも実呼び出しする = 実行するたびに課金が発生する**。実行するのは親
+   (orchestrator) のみで、子 wrapper にも露出していない (`BUGHUNT_ORCHESTRATOR` 無しでは
+   副作用の前に die する)。探索中にパイプラインの通し確認が要ると判断したら、
+   自分で走らせずレポートに「親へ依頼」と書く。
+6. **誤検知をバグとして断定しない。** 期待仕様が設計文書 (devnotes/docs) から確認できないものは
    「要確認」に分類し、severity を付けない。
 
 ## 並列モード (--parallel[=N]) — 親セッションの手順
diff --git a/AGENTS.md b/AGENTS.md
index cf835be..6a411bb 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -31,7 +31,11 @@ ## 禁止事項
 2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
 3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
 4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
-5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
+5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)。
+   **factory は `LlmCallContextData` を必須引数で受け、`->withMetadata($context->toMetadata())` で
+   帰属 (organization / subject) を付ける** — 付け忘れは PHPStan level 10 が落とし、帰属キーの登録は
+   `PromptUntrustedInputContractTest` の inventory が deny-by-default で強制する
+   (欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない)
 6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
 7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
    招待送信等は `back()->with(...)` で完結させる)
@@ -278,6 +282,13 @@ ## bug-hunt (LLM 探索的バグハント、オプトイン)
 - **dev DB 防御 (非交渉)**: 全 DB 操作は `scripts/bug-hunt-shard.sh` の用途別 wrapper (`env -i` で
   shell の `DB_*`/`PG*` を遮断 + DB名 regex + role guard) 経由のみ。生 artisan/psql/tinker/createdb/dropdb 禁止。
   `provision`/`teardown` は `BUGHUNT_ORCHESTRATOR=1` を持つ親のみ (worker は default-deny)。
+- **パイプライン通し確認 (`pipeline-smoke`)**: `scripts/bug-hunt-shard.sh pipeline-smoke --shard I --run-id TS`
+  が `dev:pipeline-smoke` を走らせ、SOP 投入 → AI 解析 → 撮影テイク → ffmpeg 合成 → mp4 の
+  **全段が通ること**だけを確認する (生成物の品質は判定しない)。**LLM を 3 段とも実呼び出しするため
+  実行そのものが課金である** (`--check` は preflight のみ = 費用ゼロ)。`provision`/`teardown` と同じく
+  **`BUGHUNT_ORCHESTRATOR=1` を持つ親のみ**が実行でき (費用の防壁)、子 (探索エージェント) 用の
+  wrapper `tmp/bug-hunt/shard-{i}-cmd.sh` には**露出しない**。段の定義・合否条件・失敗分類の語彙・
+  **保証しないもの**は `docs/architecture.md` §パイプライン通し確認 が正本。
 - **worktree 既定**: bug-hunt は worktree から走る (`scripts/bughunt-worktree-hook.sh` の PreToolUse ガードが
   main 直叩きを早期に止める。配線は `.claude/settings.bughunt-hook.example.json` を `.claude/settings.json` にマージ)。
 - **スケルトン**: `screens.md` / `operations.md` / `stories/` はテンプレートでは空スケルトン。初回に
diff --git a/docs/architecture.md b/docs/architecture.md
index 8fbca03..e645474 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -1528,3 +1528,97 @@ ## 課金記録の保持期間 (7 年) の決着 (T143 / T144 / T145)
   本番で日次処理が止まっていないことも保証しない (責務は終了コードと scheduler 運用)。
   畳み込みで失われるもの (返金逆仕訳の逆引き / 消費の冪等キー / signup grant の部分 UNIQUE
   index の保護範囲) は `docs/billing-retention-runbook.md` §7 が一覧を持つ。
+
+## パイプライン通し確認 (pipeline smoke) と LLM コストレポート (T147)
+
+`dev:pipeline-smoke` は **SOP 投入 → AI 解析 → 撮影テイク → ffmpeg 合成 → mp4** の全段が
+**実際に最後まで回ること**だけを機械で確認するコマンドである。bug-hunt レーン専用で、
+起動導線は `scripts/bug-hunt-shard.sh pipeline-smoke --shard I --run-id TS`
+(`BUGHUNT_ORCHESTRATOR=1` 必須 = 費用の防壁)。
+
+### 実行を許す条件 (fail-secure。`--force` でも迂回できない)
+
+1. `app()->environment('bughunt.local')` — 実 LLM / 実 ffmpeg / チケット消費を dev / production で走らせない
+2. `BughuntDatabaseGuard::isBughuntDatabase()` — dev DB へ fixture をばら撒かない
+3. `FakeStorageGate::enabled()` — 実 S3 へ書かない
+4. `config('testing.fake_llm') === false` — fake のまま「通った」と報告しない
+
+4 は**自プロセスの config** であり worker の設定は見ていない。worker が fake なら
+`llm_call_logs` の記録行が 0 になり `llm-evidence` 段で落ちる (2 層で守る)。
+確認プロンプトは `confirmToProceed($warning, true)` で**常に**出す (既定 callback は
+production でしか確認しないため、bughunt.local では確認なしで課金が走ってしまう)。
+
+### 段と成功条件 (**これだけを見る**)
+
+| 段 | 成功条件 |
+|---|---|
+| `preflight` | ffmpeg / ffprobe 実行可 ∧ queue connection 2 本 ∧ SOP fixture ∧ 対象組織 (所属 user ∧ 残高 4 枚) |
+| `fixture` | manual が `draft` ∧ `source_documents` 1 件 |
+| `analysis` | `analysis_jobs.status = succeeded` ∧ `video_manuals.status = ready` ∧ `cuts` ≥ 1 ∧ `scenario_version` ≥ 1 |
+| `llm-evidence` | 3 template それぞれに成功行 (`failure_reason IS NULL` ∧ `input_tokens > 0`) があり、**そのすべてが `metadata_missing = false` ∧ 期待した organization / subject を持つ** |
+| `capture` | 全 cut に採用テイク (`ready`) がある |
+| `render` | `render_jobs.status = succeeded` ∧ `video_manuals.status = published` ∧ `output_path` 非 NULL |
+| `artifact` | 出力を読み出せ、ffprobe が 0 終了し、映像ストリーム ≥ 1 ∧ 尺 > 0 |
+
+「この実行分」は `llm_call_logs.id > baselineId` で切り出す (`baselineId` は preflight 通過直後・
+`fixture` 段の前に 1 回だけ取る)。`llm-evidence` の母集団は `whereIn('prompt_template', 3 template)`
+で絞る (同 shard で他の prompt が走っても混ざらない)。
+
+### 失敗分類 (`SmokeFailureClass`。観測のためであり制御フローを変えない)
+
+`preflight` / `wiring` / `stage_timeout` / `llm` / `render` / `storage` / `unknown`。
+判定は `App\Support\Smoke\SmokeFailureClassifier::classify()` の純関数 1 本で、判定順は
+「成功段は分類しない → preflight → timeout×queued=wiring / timeout×running=stage_timeout →
+render の error_code → artifact の読めない=storage / ffprobe 失敗=render →
+**llm-evidence で成功行はあるが記録が不完全=wiring** → LLM 起因になり得る段だけ llm → unknown」。
+
+- **`llm` は `analysis` / `llm-evidence` に閉じる**。他の段の失敗を provider のせいにしない
+- **記録の不備 (帰属欠落 / 必要 template の成功行欠落) は `wiring`**。
+  `llm` に混ぜると「レート制限で落ちた」と「`withMetadata()` を書き忘れた」が同じ札になる
+- リトライは最終的に成功しても `failure_reason` 行を残すため、**成功した段は分類しない**
+
+### LLM 呼び出しの帰属 (記録側の配線)
+
+`app/Prompts/` の factory は `LlmCallContextData` を**必須引数**で受け、
+`->withMetadata($context->toMetadata())` で `organization_id` / `user_id` /
+`subject_type` / `subject_id` を載せる。AI 解析では subject = **`VideoManual`**
+(費用を知りたい単位は成果物であって job ではない)。禁止事項 5 (LLM 呼び出しは factory 経由のみ) が
+既に強制しているため、**帰属を迂回する経路が構造的に存在しない**。記録層の列は 1 本も増やしていない。
+
+3 層で固定する: **型** (必須引数 = PHPStan level 10) / **構造**
+(`PromptUntrustedInputContractTest` が組み立て済み Prompt の `metadata_context` を reflection で検査) /
+**実地** (本 smoke の `llm-evidence` 段)。
+
+### LLM コストレポート
+
+集計は `LlmCostReportService` 1 本で、入口は 2 つ (**1 実装・複数入口**):
+smoke 末尾の「この実行分」と `operations:llm-cost-report` の期間集計。
+
+- 軸は `LlmCostGroupBy` の 4 つ (`prompt_template` / `model` / `organization` / `subject`)。
+  すべて**素の列 GROUP BY** で、GROUP BY キーへ SQL 関数を適用しない
+- **USD が主** (`total_cost_usd` は `pricing_snapshot` から決定的)。
+  **JPY は副**で、期間合計は「各行の記録時レート (`fx_snapshot`) での合計」であり
+  単一レートで USD を換算した値ではない
+- **未解決 (null) は 0 に潰さない**。件数 (`usd_unresolved_calls` / `jpy_unresolved_calls`) で別に返す
+  (整数集計列だけ `COALESCE(SUM(...), 0)` を掛ける = 0 件時の TOTAL が TypeError にならない)
+- 期間は**半開区間 `since <= created_at < until`** で **UTC 解釈** (JST とは 9 時間ずれる)。
+  日付のみの `--until` はその日を含む (排他境界を翌日 0 時にする)
+- `metadata_missing_calls` は**帰属配線の健全性シグナル**である (0 でないなら呼び出し側の配線が欠けている)
+
+### 保証しないもの (誇張しない)
+
+1. **生成物の品質は一切保証しない**。判定しているのは「期待した状態遷移が起きたか」だけ
+2. **実 S3 は検証していない**。通るのは `FakeObjectStore` の checksum 三者一致だけ
+3. **ブラウザ (撮影 PWA) の実機経路は検証していない**。CLI から Service を呼んでいる
+4. **worker プロセスの LLM モードを直接は見ていない**。`llm_call_logs` の記録行の存在で
+   間接的に実呼び出しを実証している
+5. **費用は「この実行で記録された行の合計」**であり provider 側の請求額とは一致しない
+6. **帰属メタデータが「イベント経由で `llm_call_logs` に記録されること」はテストレーンでは
+   検証できない** (`Prompt::$fake` は `executePrism()` の先頭で短絡して
+   `PromptExecutionCompleted` を発火せず、`PromptFake::record()` は metadata を記録しない)。
+   テストレーンで検証できるのは「factory が組み立てた Prompt が `metadata_context` に
+   帰属キーを持つこと」(reflection) までで、**listener を経て DB へ入ったことを確かめられるのは
+   本 smoke の `llm-evidence` 段だけ**である
+7. **並行実行に対する保証は無い**。「この実行分」は `llm_call_logs.id` の差分で切り出しており、
+   同一 shard で別の LLM 呼び出しが並行すると混入する
+8. **1 回通ったことは、次も通ることを意味しない**。実 LLM の出力は非決定的である
```

## 再検証 (実測。Round 2 時点)

- `composer test`: `tests=4400 passed=4398 skipped=2 failed=0`
- `composer phpstan` (level 10): `[OK] No errors`
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck`: passed
- `pnpm test`: `Test Files 130 passed / Tests 1299 passed`
- `pnpm build`: `✓ built in 4.09s`
- `pnpm typecheck:packages` / `pnpm build:packages`: passed
- `pnpm test:packages`: `Test Files 10 passed / Tests 106 passed`
- `bash scripts/bug-hunt-shard.sh self-test`: all passed
- **実 LLM を呼ぶ本実行は依然として未実施** (課金が出るためオーナー判断待ち)。
  `--check` (費用ゼロ preflight) のみ実測済み。

## 確認してほしいこと

1. `fullyAttributedTemplates()` の切り出し方 (DB 読みはコマンド / 集合演算は classifier) が、
   設計の責務分割 (`llmRecordingIncomplete()` と同じ流儀) と整合しているか
2. fail-secure 失敗を `preflight` 段として記録する形にしたことで、
   **fail-secure が「最初の実効文」である**という費用の防壁の性質が損なわれていないか
   (`captureLaneContext()` は blocker 判定の**後**に呼んでおり、判定そのものは従来どおり冒頭)
3. 施策 9 のドキュメント (「保証しないもの」8 項目 / 探索エージェントへの実行禁止 /
   AGENTS.md の帰属規約) が、**テストで検証していないことを検証したと書いていない**か
4. 全体判定
