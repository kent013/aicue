# 詳細設計: ai-analysis（AI 解析: SOP→作業分解→シナリオ生成→Cut materialize）

作成: 2026-07-11
ステータス: **APPROVED**（Codex gpt-5.3-codex/high 詳細レビュー。Round 5 で残 Warning 1 件まで
収束 → 修正案どおり反映し反映確認（Round 6 相当の確認交換）で APPROVED。Round 2 は Codex 側の
ファイル読込障害により暫定判定。概念設計は gpt-5.4/medium Round 4 で APPROVED。
履歴は codex-history/ と detailed-review-round-{1..6}.md 参照）

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

### セキュリティ不変条件（AGENTS.md。特に本設計に効くもの）

- tenant キー不信（ticket_reservation_id 等を payload から受けない）
- 子は親に属する（nested route 不整合は認可より前に 404。NestedRouteIdorDefenseTest 登録）
- untrusted 文字列は UserInput 型経由でのみ prompt へ
- チケットは reserve→commit/release の 2 フェーズ（TicketLedgerService の公開 API のみ使用）

### コーディングルール
- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ずFactoryで生成**（`Model::create()` 手組み禁止）
- 新モデル（AnalysisJob）には **AnalysisJobFactory を追加**し `docs/architecture.md` / `docs/factories.md` に追記
- **DTO + JsonResource** パターン / **アーリーリターン** / `declare(strict_types=1)` + 日本語コメント
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

[devnotes/20260711-0137-ai-analysis/conceptual-design.md](./conceptual-design.md)（Codex gpt-5.4 概念レビュー APPROVED。履歴は codex-history/）

要点:
- パイプライン: extract → decompose → generate → materialize（queued job、tries=1、timeout=1380。時間 budget は施策 6）
- チケット 2 フェーズ: `analysis_jobs.ticket_reservation_id` を予約の冪等キーに（§10.8-1）。
  **terminal tx** で materialize + commit + succeeded を原子化（無課金 succeeded を構造的に排除）
- analyze 冪等（§10.8-8）: in-flight 1 つ・`status ∈ {draft, ready}` のみ実行可（ready→analyzing は
  本設計で正式化し doc/10 §10.2 を更新）
- stale 回復 cron（queued/running 30 分超 → failJob）。failJob は行ロック + status guard で冪等
- LLM 入力は UTF-8 バイト上限（token budget 導出）、出力は JSON 指示 + DTO 検証 + 有界リトライ（最大 2 回）
- SourceDocument は追記型 immutable（差し替え = 新規行。解析は `latest('id')`）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | analysis_jobs スキーマ + enum + Model + Factory | `database/migrations/2026_07_11_000000_create_analysis_jobs_table.php`, `app/Enums/Manual/JobStatus.php`, `app/Enums/Manual/AnalysisStep.php`, `app/Models/AnalysisJob.php`, `app/Models/VideoManual.php`, `app/Support/Security/MassAssignmentProtectedKeys.php`, `database/factories/AnalysisJobFactory.php` | 高 |
| 2 | config/manual.php + ScenarioLimits 昇格 + token budget 不変条件テスト | `config/manual.php`, `app/Support/Manual/ScenarioLimits.php`, `app/Http/Requests/Projects/UpdateScenarioRequest.php`, `tests/Architecture/AnalysisTokenBudgetInvariantTest.php` | 高 |
| 3 | ルート 3 本 + IDOR inventory 登録 | `routes/web.php`, `tests/Architecture/NestedRouteIdorDefenseTest.php` | 高 |
| 4 | SOP アップロード（作成時 + 専用 route） | `app/Http/Requests/Projects/StoreVideoManualRequest.php`, `app/Http/Requests/Projects/StoreSourceDocumentRequest.php`, `app/Services/Manual/SourceDocumentService.php`, `app/Http/Controllers/Projects/SourceDocumentController.php`, `app/Http/Controllers/Projects/VideoManualController.php`, `app/Services/Manual/VideoManualService.php` | 高 |
| 5 | 解析トリガー + ポーリング（Service / 例外 / DTO / Resource / Controller / Policy） | `app/Services/Manual/AnalysisJobService.php`, `app/Exceptions/Manual/AnalysisConflictException.php`, `app/Enums/Manual/AnalysisConflictType.php`, `app/Http/Resources/Manual/AnalysisConflictResource.php`, `app/DataTransferObjects/Manual/AnalysisJobData.php`, `app/Http/Resources/Manual/AnalysisJobResource.php`, `app/Http/Controllers/Projects/ManualAnalysisController.php`, `app/Policies/VideoManualPolicy.php`, `app/Http/Resources/Billing/InsufficientTicketsResource.php`, `bootstrap/app.php` | 高 |
| 6 | 解析ジョブ本体（Job / Pipeline / terminal tx / 例外） | `app/Jobs/Manual/RunManualAnalysis.php`, `app/Services/Manual/AnalysisPipeline.php`, `app/Exceptions/Manual/AnalysisFailedException.php`, `app/Exceptions/Manual/LlmOutputInvalidException.php` | 高 |
| 7 | SOP テキスト抽出（composer 依存追加） | `app/Services/Manual/SopTextExtractor.php`, `app/DataTransferObjects/Manual/Analysis/ExtractedText.php`, `composer.json`（smalot/pdfparser, phpoffice/phpspreadsheet） | 高 |
| 8 | プロンプト 3 種 + 解析 DTO 群 | `resources/prompts/{sop-extract,work-decomposition,scenario-generation}.yaml`, `app/Prompts/{SopExtractPrompt,WorkDecompositionPrompt,ScenarioGenerationPrompt}.php`, `app/DataTransferObjects/Manual/Analysis/{ExtractedSopData,WorkDecompositionData,WorkDecompositionStepData,GeneratedScenarioData}.php`, `app/Enums/Manual/LlmOutputInvalidReason.php`, `app/Support/Manual/LlmJson.php` | 高 |
| 9 | materialize + 書き込み経路 inventory テスト | `app/Services/Manual/ScenarioService.php`, `tests/Architecture/ScenarioWritePathInventoryTest.php` | 高 |
| 10 | stale 回復 cron | `routes/console.php`（`analysis:recover-stale-jobs`） | 高 |
| 11 | フロントエンド（アップロード + AnalysisPanel + ポーリング + TS 型） | `resources/js/pages/Manuals/Create.svelte`, `resources/js/pages/Manuals/Show.svelte`, `resources/js/components/features/manual/AnalysisPanel.svelte`, `resources/js/types/manual.ts` | 高 |
| 12 | テスト一式 | `tests/Feature/Projects/{ManualAnalyzeTest,AnalysisPipelineTest,AnalysisJobPollingTest,SourceDocumentUploadTest,AnalysisRecoverStaleJobsTest}.php`, `tests/js/components/features/AnalysisPanel.test.ts`, `tests/js/pages/ManualsCreate.test.ts` | 高 |
| 13 | ドキュメント更新 | `doc/10_実装仕様.md`（§10.2）, `docs/architecture.md`, `docs/factories.md`, `docs/supply-chain/review-checklist.md` 観点での依存追加記録 | 中 |

全施策**テストファースト**（施策 12 のテストを先に書き fail 確認 → 実装 → green）。

---

## 施策 1: analysis_jobs スキーマ + enum + Model + Factory

### 変更箇所
- `database/migrations/2026_07_11_000000_create_analysis_jobs_table.php`（新規）
- `app/Enums/Manual/JobStatus.php` / `app/Enums/Manual/AnalysisStep.php`（新規）
- `app/Models/AnalysisJob.php`（新規）
- `app/Models/VideoManual.php`（`analysisJobs()` relation 追加）
- `app/Support/Security/MassAssignmentProtectedKeys.php`（`ticket_reservation_id` 追記）
- `database/factories/AnalysisJobFactory.php`（新規）

### 波及変更
- TypeScript型定義: 施策 11 の `AnalysisJobStatus` union（値集合を JobStatus と一致）
- API Resource/DTO: 施策 5 の AnalysisJobData
- テストファイル: `MassAssignmentSafetyTest`（既存 Architecture テストが新 Model を自動走査。
  ticket_reservation_id が fillable に無いことを検証）、`FormRequestProhibitedKeyTest`（自動追従）

### 変更後コード（骨子）

```php
// migration
Schema::create('analysis_jobs', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('video_manual_id')->constrained()->cascadeOnDelete();
    $table->foreignId('source_document_id')->nullable()->constrained()->nullOnDelete();
    $table->string('status')->default(JobStatus::Queued->value); // string + アプリ層 cast (enum 追加に強い)
    $table->string('step')->nullable();
    $table->unsignedSmallInteger('progress')->nullable();
    $table->foreignId('ticket_reservation_id')->nullable()->constrained('ticket_reservations')->nullOnDelete();
    $table->json('result_json')->nullable();
    $table->text('error')->nullable();
    $table->timestamps();
    $table->index(['video_manual_id', 'status']); // in-flight 判定
    $table->index(['status', 'updated_at']);      // stale 回復走査
});
```

```php
// app/Enums/Manual/JobStatus.php (§10.2)
enum JobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /** terminal (成否確定) か。failJob / recover の guard に使う */
    public function isTerminal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }
}

// app/Enums/Manual/AnalysisStep.php (§10.1 step 列)
enum AnalysisStep: string
{
    case Extract = 'extract';
    case Decompose = 'decompose';
    case Generate = 'generate';
}
```

```php
// app/Models/AnalysisJob.php
/**
 * AnalysisJob (VideoManual 配下の AI 解析ジョブ)。doc/10 §10.1。
 *
 * - video_manual_id / source_document_id / ticket_reservation_id は保護キーのため $fillable 外
 * - status / step / progress / result_json / error は AnalysisJobService / AnalysisPipeline が
 *   管理する状態のため $fillable を持たない (TicketReservation と同じ明示代入のみの規約)
 *
 * @property int $id
 * @property int $video_manual_id
 * @property int|null $source_document_id
 * @property JobStatus $status
 * @property AnalysisStep|null $step
 * @property int|null $progress
 * @property int|null $ticket_reservation_id
 * @property array<array-key, mixed>|null $result_json
 * @property string|null $error
 */
class AnalysisJob extends Model
{
    /** @use HasFactory<AnalysisJobFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
            'step' => AnalysisStep::class,
            'progress' => 'integer',
            'result_json' => 'array',
        ];
    }

    // videoManual() / sourceDocument() / ticketReservation() BelongsTo
}
```

```php
// VideoManual に追加 (route param {analysisJob} の scopeBindings 推論と一致する relation 名)
/** @return HasMany<AnalysisJob, $this> */
public function analysisJobs(): HasMany
{
    return $this->hasMany(AnalysisJob::class);
}
```

```php
// MassAssignmentProtectedKeys::all() の billing セクションに追記
'reservation_id',
'ticket_reservation_id', // AI-CUE: analysis_jobs の予約冪等キー (doc/10 §10.1)
```

```php
// AnalysisJobFactory (親 Factory 連鎖 + 状態 state)
public function definition(): array
{
    return [
        'video_manual_id' => VideoManual::factory(),
        'source_document_id' => null,
        'status' => JobStatus::Queued,
        'step' => null,
        'progress' => null,
        'ticket_reservation_id' => null,
        'result_json' => null,
        'error' => null,
    ];
}
public function forManual(VideoManual $manual): static { /* state */ }
public function running(): static { /* status=Running, step=Extract, progress=10 */ }
public function failed(string $error = '解析に失敗しました'): static { /* ... */ }
```

### PHPStan適合チェック
- [x] enum cast は casts() で宣言、@property で型付け
- [x] $fillable なし（明示代入のみ）で MassAssignmentSafetyTest 適合
- [x] Factory generics（`@extends Factory<AnalysisJob>`）

### テスト計画
- [ ] `MassAssignmentSafetyTest` / `FormRequestProhibitedKeyTest` が自動で新キーを検証（追加実装不要を確認）
- [ ] AnalysisJobFactory 経由の生成が施策 12 の全テストの土台

### リスク
- `source_document_id` nullOnDelete: SourceDocument は追記型 immutable のため実運用で delete は
  発生しないが、FK 制約としては安全側（削除時に job を壊さない）

---

## 施策 2: config/manual.php + ScenarioLimits + token budget 不変条件

### 変更箇所
- `config/manual.php`（新規）
- `app/Support/Manual/ScenarioLimits.php`（新規。UpdateScenarioRequest の private 定数を昇格）
- `app/Http/Requests/Projects/UpdateScenarioRequest.php`（定数参照へ変更）
- `tests/Architecture/AnalysisTokenBudgetInvariantTest.php`（新規）

### 変更後コード（骨子）

```php
// config/manual.php
return [
    // AI 解析 1 回のチケット消費 (doc/10 §10.5 COST_ANALYSIS)
    'analysis_ticket_cost' => 1,
    // LLM 出力 JSON の検証失敗時の有界リトライ回数 (§10.7-2)
    'analysis_llm_max_retries' => 2,
    // LLM 入力上限 (UTF-8 bytes)。token budget 導出: context 200,000 - 出力予約 16,000
    // - 固定プロンプト 4,000 = 180,000 token。byte-fallback BPE では token 数 <= バイト数が
    // 安全側上界のため strlen で保証する (AnalysisTokenBudgetInvariantTest が算術を固定)
    'analysis_max_text_bytes' => 150_000,
    // 抽出テキストの実質空判定 (これ未満は「テキストを抽出できません」)
    'analysis_min_text_bytes' => 100,
    // stale ジョブ回復閾値 (分)。queued: dispatch 喪失、running: worker 異常終了
    'analysis_stale_after_minutes' => 30,
    // SOP アップロード上限 (bytes) と許可 mime
    'source_document_max_bytes' => 20 * 1024 * 1024,
    'source_document_mimes' => ['pdf', 'xlsx', 'xls', 'txt'],
];
```

```php
// app/Support/Manual/ScenarioLimits.php (手動保存と LLM 生成 DTO の共通有界値)
final class ScenarioLimits
{
    public const int MAX_STEPS = 100;
    public const int MAX_POINTS_PER_STEP = 20;
    public const int MAX_SCENE_CHARS = 1000;
    public const int MAX_NARRATION_CHARS = 2000;
    public const int MAX_SUBTITLE_PRIMARY_CHARS = 100; // DB string(100) と一致
    public const int MAX_SUBTITLE_SECONDARY_CHARS = 2000;
}
```

```php
// AnalysisTokenBudgetInvariantTest (Architecture)
//
// 上界の根拠 (数学的・言語非依存): tokenizer は入力バイト列を「空でない区間」に分割する
// (partition) ため、いかなる入力でも token 数 <= バイト数。従って
// 「入力バイト数 <= 入力 token budget」なら context 超過は起きない。
// budget = context - 出力予約 - 固定プロンプト余裕 = 200,000 - 16,000 - 4,000 = 180,000。
// config 既定値 150,000 bytes は budget 180,000 に対するマージン込みの値
// (定数・式・doc/10 の記述を一致させる。モデル/tokenizer 変更時は要再確認 = 概念設計の運用条件)。
const MODEL_CONTEXT_TOKENS = 200_000;   // claude-sonnet-4-5 (prompts YAML の model と対)
const OUTPUT_RESERVE_TOKENS = 16_000;   // YAML max_tokens と一致させる
const PROMPT_OVERHEAD_TOKENS = 4_000;   // 固定 system/prompt + UserInput タグの余裕
const INPUT_BUDGET_TOKENS = MODEL_CONTEXT_TOKENS - OUTPUT_RESERVE_TOKENS - PROMPT_OVERHEAD_TOKENS; // 180,000

test('LLM 入力バイト上限が入力 token budget を超えない (分割上界: token数<=バイト数)', function (): void {
    expect(config()->integer('manual.analysis_max_text_bytes'))
        ->toBeLessThanOrEqual(INPUT_BUDGET_TOKENS);
});

test('解析プロンプト YAML の max_tokens は出力予約と一致する', function (): void {
    foreach (['sop-extract', 'work-decomposition', 'scenario-generation'] as $name) {
        $yaml = Yaml::parseFile(resource_path("prompts/{$name}.yaml"));
        expect($yaml['max_tokens'] ?? null)->toBe(OUTPUT_RESERVE_TOKENS);
    }
});
```

### 波及変更
- テストファイル: `UpdateScenarioRequest` の既存テスト（ScenarioUpdateTest の有界入力テスト）は
  値が変わらないため修正不要（定数の置き場所のみ変更）

### PHPStan適合チェック
- [x] config()->integer() / config()->array() で narrow

### テスト計画
- [ ] AnalysisTokenBudgetInvariantTest（上記 2 本）
- [ ] 既存 `QuotaKeyConfigInvariantTest` 型の drift 防止パターンを踏襲

### リスク
- なし（定数の昇格は機械的）

---

## 施策 3: ルート 3 本 + IDOR inventory 登録

### 変更箇所
- `routes/web.php`（既存 `Route::scopeBindings()` グループ = L356-370 に追記）
- `tests/Architecture/NestedRouteIdorDefenseTest.php`（inventory 追記）

### 変更後コード

```php
Route::scopeBindings()->group(function (): void {
    // ... 既存 manuals routes ...
    // SOP アップロード (追記型 immutable。差し替え = 新規行。doc/10 §10.3)
    Route::post('/projects/{project}/manuals/{manual}/source-documents', [SourceDocumentController::class, 'store'])
        ->name('projects.manuals.source-documents.store');
    // AI 解析トリガー (残高事前チェック→job 投入。同一オリジン XHR/JSON。doc/10 §10.3, §10.8-8)
    Route::post('/projects/{project}/manuals/{manual}/analyze', [ManualAnalysisController::class, 'store'])
        ->name('projects.manuals.analyze');
    // job 状態ポーリング ({analysisJob} は $manual->analysisJobs() 経由 = cross-manual 404)
    Route::get('/projects/{project}/manuals/{manual}/jobs/{analysisJob}', [ManualAnalysisController::class, 'show'])
        ->name('projects.manuals.jobs.show');
});
```

`NestedRouteIdorDefenseTest` inventory 追記（`$s` = ScopeBindings）:
```php
// SOP アップロード / AI 解析 / job ポーリング ({manual} は $project->manuals()、
// {analysisJob} は $manual->analysisJobs() 経由。不整合は認可より前に 404)
'projects.manuals.source-documents.store' => $s,
'projects.manuals.analyze' => $s,
'projects.manuals.jobs.show' => $s,
```

### 波及変更
- TypeScript型定義: なし（URL はフロントで文字列組み立て。既存規約どおり）
- テストファイル: `NestedRouteIdorDefenseTest` は deny-by-default のため未登録だと fail
  （テストファーストの fail はこれ自体）

### PHPStan適合チェック
- [x] route 定義のみ（該当なし）

### テスト計画
- [ ] cross-org / cross-project / cross-manual の 404 は施策 12 の Feature テストで実挙動を検証

### リスク
- `{analysisJob}` の 3 param route は本アプリ初。scopeBindings の連鎖解決
  （project→manuals→analysisJobs）は Laravel 標準機能で、relation 名 `analysisJobs` と
  param 名 `analysisJob` の一致で推論される（Feature テストで実挙動を固定）

---

## 施策 4: SOP アップロード

### 変更箇所
- `app/Http/Requests/Projects/StoreVideoManualRequest.php`（`document` フィールド追加）
- `app/Http/Requests/Projects/StoreSourceDocumentRequest.php`（新規）
- `app/Services/Manual/SourceDocumentService.php`（新規）
- `app/Services/Manual/VideoManualService.php`（create に document 引き回し）
- `app/Http/Controllers/Projects/VideoManualController.php`（store で UploadedFile を渡す）
- `app/Http/Controllers/Projects/SourceDocumentController.php`（新規）

### 波及変更
- TypeScript型定義: なし（multipart form）
- テストファイル: `VideoManualCrudTest`（store の既存テストは影響なし = document 任意）

### 変更後コード（骨子）

```php
// StoreVideoManualRequest::rules() に追加 (multipart。任意)
'document' => [
    'nullable',
    'file',
    'mimes:'.implode(',', config()->array('manual.source_document_mimes')),
    'max:'.intdiv(config()->integer('manual.source_document_max_bytes'), 1024), // KB 単位
],
```

```php
// app/Services/Manual/SourceDocumentService.php
/**
 * SOP (SourceDocument) の保存。追記型 immutable (更新・削除 API を持たない)。
 * - file_path はサーバ生成 (projects/{pid}/manuals/{mid}/source-documents/{ulid}.{ext})
 * - 専用 route 経由 (storeForManual) は VideoManual 行ロック + 状態 guard
 *   (analyze trigger と直列化。conceptual-design §2)
 */
class SourceDocumentService
{
    /** 専用 route (POST .../source-documents)。draft/ready のみ許可 */
    public function storeForManual(Project $project, VideoManual $manual, UploadedFile $file): SourceDocument
    {
        return DB::transaction(function () use ($project, $manual, $file): SourceDocument {
            /** @var VideoManual $locked */
            $locked = $project->manuals()->whereKey($manual->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [VideoManualStatus::Draft, VideoManualStatus::Ready], true)) {
                // Inertia form 経路のため 422 (ValidationException) で返す (409 JSON は XHR 専用契約)
                throw ValidationException::withMessages([
                    'document' => ['解析中・書き出し中・公開済みのマニュアルには手順書を追加できません。'],
                ]);
            }

            return $this->appendDocument($locked, $file);
        });
    }

    /** VideoManualService::create の tx 内から呼ぶ (新規 manual は競合なし・状態 guard 不要) */
    public function appendDocument(VideoManual $manual, UploadedFile $file): SourceDocument
    {
        // サーバ側 MIME 再判定 (polyglot 対策): クライアント拡張子でなく内容 sniff
        // (getMimeType = finfo) が許可集合に含まれることを検証。不一致は 422
        $sniffedMime = $file->getMimeType();
        if ($sniffedMime === null || ! in_array($sniffedMime, self::allowedMimeTypes(), true)) {
            throw ValidationException::withMessages([
                'document' => ['対応していないファイル形式です (PDF / Excel / テキストのみ)。'],
            ]);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $path = sprintf(
            'projects/%d/manuals/%d/source-documents/%s.%s',
            $manual->project_id, $manual->id, (string) Str::ulid(), $extension,
        );
        // ファイル書き込みは行 insert より先。行 insert 失敗時は best-effort で即時削除し
        // 孤児ファイルの常態化を防ぐ (tx rollback 経路の残渣はストレージ Quota フェーズの掃除対象)
        Storage::putFileAs(dirname($path), $file, basename($path));
        try {
            $document = $manual->sourceDocuments()->make([
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime' => $sniffedMime,
                'size_bytes' => $file->getSize(),
            ]);
            $document->save();
        } catch (Throwable $exception) {
            Storage::delete($path); // best-effort (失敗しても rethrow を優先)
            throw $exception;
        }

        return $document;
    }

    /** 許可 MIME (内容 sniff 値)。config の拡張子リストと対で保守 */
    private static function allowedMimeTypes(): array
    {
        return [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // xlsx
            'application/vnd.ms-excel',                                          // xls
            'text/plain',
        ];
    }
}
```

```php
// VideoManualService::create のシグネチャ拡張 (最後に ?UploadedFile $document = null)
public function create(Project $project, string $title, ?int $categoryId, int $userId, ?UploadedFile $document = null): VideoManual
{
    return DB::transaction(function () use (...): VideoManual {
        // ... 既存処理 (Project 行ロック → manual 作成 → category associate) ...
        if ($document !== null) {
            $this->sourceDocuments->appendDocument($manual, $document);
        }
        return $manual;
    });
}
```

```php
// SourceDocumentController::store (Inertia form。back + flash)
public function store(StoreSourceDocumentRequest $request, Project $project, VideoManual $manual, SourceDocumentService $documents): RedirectResponse
{
    $organization = $this->resolveCurrentOrganization($request);
    $this->resolveOrganizationProject($organization, $project); // 認可より前に 404
    Gate::authorize('update', $manual); // 編集者のみ

    $file = $request->validatedDocument(); // UploadedFile へ narrow するヘルパ
    $documents->storeForManual($project, $manual, $file);

    return back()->with('success', '手順書をアップロードしました');
}
```

`StoreSourceDocumentRequest`: `ProhibitsProtectedKeys` + `document` を required で同 rules。

### PHPStan適合チェック
- [x] `validatedDocument(): UploadedFile`（Assert::isInstanceOf で narrow。mixed を返さない）
- [x] `$file->getSize()` は int|false → Assert で narrow

### テスト計画（SourceDocumentUploadTest）
- [ ] 作成時アップロード: POST manuals + document → SourceDocument 行 + Storage::fake に存在
- [ ] 専用 route: draft manual に追加できる / 2 回目は行が増える（immutable 追記）
- [ ] analyzing 中は 422（ValidationException）で行・ファイル不変
- [ ] mime / サイズ超過 422、保護キー（video_manual_id 等）送出 422
- [ ] 拡張子偽装（.pdf 拡張子の非 PDF 内容）→ サーバ側 sniff で 422（polyglot 対策）
- [ ] 撮影者 403、cross-org / cross-project 404
- [ ] `Storage::fake()` で実 S3 に触れない

### リスク
- tx 内 Storage 書き込み: 行 insert 失敗は catch で即時削除（best-effort）。外側 tx rollback
  経路（category 再解決失敗等）の残渣のみ孤児になり得るが、頻度は低く掃除はストレージ
  Quota フェーズのスコープ（概念設計で明示済み）

---

## 施策 5: 解析トリガー + ポーリング

### VideoManualStatus 遷移表（本フィーチャが関与する全遷移。doc/10 §10.2 へ転記 = 施策 13）

| 遷移 | 書き込み経路（唯一） | from-state guard（行ロック下） |
|---|---|---|
| draft/ready → analyzing | `AnalysisJobService::trigger()` | `status ∈ {draft, ready}` かつ in-flight 無し。violate → 409 |
| analyzing → ready | `ScenarioService::materializeIntoLockedManual()`（terminal tx 内） | `status === analyzing`。violate → LogicException（terminal tx rollback） |
| analyzing → ready(cuts≥1) / draft(cuts=0) | `AnalysisJobService::failJob()` | `status === analyzing` のときのみ復帰（それ以外は manual 不変） |
| （既存）draft→ready / published→ready | `ScenarioService::save()` | 既存実装のまま |

全経路が **VideoManual 行ロック + from-state guard** を持つため「後勝ち」は構造的に発生しない
（guard 不成立の書き込みは no-op か例外）。この表は状態遷移 Feature テスト
（ManualAnalyzeTest / AnalysisPipelineTest）と ScenarioWritePathInventoryTest（施策 9）で固定する。
status 遷移の責務を 1 クラスに集約しない理由: 共有ロック規約（AGENTS.md ドメイン固有規約 1）が
「cuts / scenario_version / status は同一の行ロック tx 内で書く」ことを要求するため、
analyzing→ready は cuts を書く materialize と同一メソッドに置くのが規約準拠
（`ScenarioService::save()` が status を書くのと同じ配置原理）。

### 変更箇所
- `app/Services/Manual/AnalysisJobService.php`（新規: trigger / failJob / recoverStale）
- `app/Enums/Manual/AnalysisConflictType.php` + `app/Exceptions/Manual/AnalysisConflictException.php` + `app/Http/Resources/Manual/AnalysisConflictResource.php`（新規: 409 契約）
- `app/DataTransferObjects/Manual/AnalysisJobData.php` + `app/Http/Resources/Manual/AnalysisJobResource.php`（新規）
- `app/Http/Controllers/Projects/ManualAnalysisController.php`（新規: store / show）
- `app/Policies/VideoManualPolicy.php`（`analyze` ability 追加）
- `app/Http/Resources/Billing/InsufficientTicketsResource.php` + `bootstrap/app.php`（402 JSON）
- `app/Http/Controllers/Projects/VideoManualController.php`（show props に analysis 追加）

### 波及変更
- TypeScript型定義: `types/manual.ts`（施策 11。AnalysisJobProps / ポーリング応答型）
- API Resource/DTO: AnalysisJobData が show props / ポーリング応答 / analyze 応答の共通 shape
- テストファイル: ManualAnalyzeTest / AnalysisJobPollingTest（施策 12）

### 変更後コード（骨子）

```php
// AnalysisConflictType (409 の判別) — ScenarioConflictType と同型
enum AnalysisConflictType: string
{
    case InFlight = 'in_flight';               // queued/running の job が既に存在
    case StatusNotAnalyzable = 'status_not_analyzable'; // analyzing/rendering/published
    public function message(): string { /* ユーザー向け文言 */ }
}
// AnalysisConflictException::render() は AnalysisConflictResource (code='analysis_conflict') を 409 で返す
```

```php
// AnalysisJobService::trigger (§10.8-8 冪等 + 残高事前チェック + enqueue)
public function trigger(Project $project, VideoManual $manual): AnalysisJob
{
    $job = DB::transaction(function () use ($project, $manual): AnalysisJob {
        // 共有ロック規約: status を書くため VideoManual 行ロック (親 relation 経由 = 子∈親も担保)
        /** @var VideoManual $locked */
        $locked = $project->manuals()->whereKey($manual->id)->lockForUpdate()->firstOrFail();

        // 実行可能状態 guard (ready→analyzing は本設計で正式化。doc/10 §10.2 更新は施策 13)
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
```

```php
// AnalysisJobService::failJob (冪等。pipeline catch / Job::failed / recoverStale の合流点)
public function failJob(AnalysisJob $job, string $error): void
{
    DB::transaction(function () use ($job, $error): void {
        /** @var AnalysisJob $locked */
        $locked = AnalysisJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
        if ($locked->status->isTerminal()) {
            return; // succeeded/failed 済み → no-op (terminal tx 勝ち・二重 fail を握る)
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
    });
}
```

```php
// AnalysisJobService::recoverStale (cron から。戻り値 = 回復件数)
public function recoverStale(): int
{
    $threshold = CarbonImmutable::now()->subMinutes(config()->integer('manual.analysis_stale_after_minutes'));
    $staleIds = AnalysisJob::query()
        ->where(fn ($q) => $q
            ->where(fn ($q) => $q->where('status', JobStatus::Queued)->where('created_at', '<=', $threshold))
            ->orWhere(fn ($q) => $q->where('status', JobStatus::Running)->where('updated_at', '<=', $threshold)))
        ->pluck('id');
    // 1 件ずつ failJob (行ロック + guard で冪等。releaseStale と同型)
    foreach ($staleIds as $id) { /* find + failJob('解析がタイムアウトしました。再実行してください。') */ }
}
```

```php
// AnalysisJobData (show props / ポーリング / analyze 応答の共通 shape。ScenarioDocumentData と同流儀)
final readonly class AnalysisJobData
{
    public function __construct(
        public int $id,
        public JobStatus $status,
        public ?AnalysisStep $step,
        public ?int $progress,
        public ?string $error,
        public VideoManualStatus $manualStatus,
    ) {}

    public static function fromJob(AnalysisJob $job, VideoManual $manual): self { /* ... */ }

    /** @return array{id: int, status: string, step: string|null, progress: int|null,
     *   error: string|null, manual_status: string} */
    public function toArray(): array { /* ... */ }
}
// AnalysisJobResource は ScenarioResource と同じく DTO を包む JsonResource ($wrap = null)
```

```php
// ManualAnalysisController
class ManualAnalysisController extends Controller
{
    use ResolvesCurrentOrganization;

    /** AI 解析トリガー (XHR/JSON。201 + AnalysisJobResource) */
    public function store(Request $request, Project $project, VideoManual $manual, AnalysisJobService $analysis): JsonResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('analyze', $manual);

        $job = $analysis->trigger($project, $manual);
        $manual->refresh(); // trigger で analyzing へ遷移済み

        return AnalysisJobResource::make(AnalysisJobData::fromJob($job, $manual))
            ->response($request)
            ->setStatusCode(201);
    }

    /** job 状態ポーリング (撮影者も read 可) */
    public function show(Request $request, Project $project, VideoManual $manual, AnalysisJob $analysisJob): AnalysisJobResource
    {
        $organization = $this->resolveCurrentOrganization($request);
        $this->resolveOrganizationProject($organization, $project);
        // {analysisJob} ∈ {manual} は scopeBindings が担保済み。inline 再検査は二重防御
        // (oauthSessions controller の organization_id 再検査と同じ位置づけ)
        if ($analysisJob->video_manual_id !== $manual->id) {
            abort(404);
        }
        Gate::authorize('view', $manual);

        return AnalysisJobResource::make(AnalysisJobData::fromJob($analysisJob, $manual));
    }
}
```

```php
// VideoManualPolicy に追加 (親委譲 = 編集者のみ)
/** AI 解析の実行: プロジェクトを操作できる人 (編集者)。撮影者は不可 */
public function analyze(User $user, VideoManual $manual): bool
{
    $project = $manual->project;

    return $project !== null && $this->projectPolicy->update($user, $project);
}
```

```php
// bootstrap/app.php の InsufficientTicketsException render を拡張 (402 JSON)
$exceptions->render(function (InsufficientTicketsException $exception, Request $request) {
    if ($request->is('api/*')) {
        return null; // ApiExceptionRenderer に委譲 (既存)
    }
    if ($request->expectsJson()) {
        // XHR (analyze 等) は 402 + JsonResource (response()->json() 直書きはしない)
        return InsufficientTicketsResource::make($exception)
            ->response($request)
            ->setStatusCode(402);
    }
    return back()->with('error', $exception->getMessage()); // 既存の web 挙動を維持
});
// InsufficientTicketsResource: { code: 'insufficient_tickets', message: string }
```

```php
// VideoManualController::show props に追加
'analysis' => [
    'job' => ($latest = $manual->analysisJobs()->latest('id')->first()) === null
        ? null
        : AnalysisJobData::fromJob($latest, $manual)->toArray(),
    'hasDocument' => $manual->sourceDocuments()->exists(),
],
```

ポーリングの ETag/Last-Modified（doc §10.8-8 の「任意」）は v1 では**実装しない**
（2〜3 秒間隔 × 編集画面滞在中のみで負荷は無視できる。導入時は
`setLastModified($job->updated_at)` を Resource 応答に付けるだけの追加で済む）。

### PHPStan適合チェック
- [x] JsonResponse / JsonResource 戻り値型を明示
- [x] config()->integer() で narrow、Assert::isInstanceOf(Organization) で HasOneThrough を narrow
- [x] DTO は readonly + typed array PHPDoc

### テスト計画（ManualAnalyzeTest / AnalysisJobPollingTest）
- [ ] draft + document + 残高あり → 201 / job=queued / manual=analyzing / RunManualAnalysis dispatch（Queue::fake）
- [ ] ready からの再解析 201（正式遷移）
- [ ] in-flight (queued/running) → 409 code=analysis_conflict, type=in_flight（DB 不変）
- [ ] analyzing/rendering/published → 409 type=status_not_analyzable
- [ ] failed 後の再トリガー → 201（冪等ルール: failed のみ再実行可）
- [ ] document 無し → 422（job 作らない・status 不変）
- [ ] 残高 0 → **402** code=insufficient_tickets（job 作らない・status 不変・予約も作らない）
- [ ] 撮影者 (project_member) analyze → 403 / ポーリング GET → 200
- [ ] cross-org / cross-project manual → 404、他 manual の job id → 404（scopeBindings）
- [ ] analyze payload に ticket_reservation_id / video_manual_id 等の保護キー送出 → 422
- [ ] 未ログイン XHR → 401 JSON

### リスク
- 残高事前チェックは TOCTOU（チェック後に他ジョブが消費）があるが、真の残高保証は
  pipeline の reserve が担う（不足なら failJob でユーザーにエラー表示）。入口ゲートは UX 用

---

## 施策 6: 解析ジョブ本体（RunManualAnalysis + AnalysisPipeline）

### 変更箇所
- `app/Jobs/Manual/RunManualAnalysis.php`（新規）
- `app/Services/Manual/AnalysisPipeline.php`（新規）
- `app/Exceptions/Manual/AnalysisFailedException.php`（新規: ユーザー向けメッセージ付き失敗）
- `app/Exceptions/Manual/LlmOutputInvalidException.php`（新規: 有界リトライのトリガー）
- `config/queue.php`（`database-analysis` connection 追加 = retry_after を解析ジョブ専用に設定）
- `tests/Architecture/AnalysisTimeBudgetInvariantTest.php`（新規: 時間 budget の連鎖を CI 固定）

### 時間 budget（worst-case から導出。値の根拠を一本化）

| 項目 | 値 | 根拠 |
|---|---|---|
| LLM worst-case | 1,080 秒 | 3 段 × (1+リトライ2) 試行 × client timeout 120 秒 |
| 抽出 + 解析/DB 余裕 | 180 秒 | PDF/XLSX 抽出・レスポンス解析・ロック待ちのマージン |
| **job `$timeout`** | **1,380 秒 (23 分)** | 上記合計 1,260 秒 + マージン |
| **queue `retry_after`** | **1,560 秒 (26 分)** | `timeout < retry_after` (Laravel 要件: 二重処理防止)。既定の database 接続 (90 秒) では不足するため **専用 connection `database-analysis`** を追加し、job 側 `$connection` で指定 |
| **予約 TTL** | 1,800 秒 (30 分) | TicketLedgerService::RESERVATION_TTL_MINUTES（変更しない）。startJob で予約直後から worst-case 完走 (23 分) しても TTL 内 |
| stale 回復閾値 | 1,800 秒 (30 分) | `analysis_stale_after_minutes`。step 更新間隔の worst-case (1 段 = 360 秒) ≪ 閾値で誤回収なし |

連鎖 **`timeout (1380) < retry_after (1560) < TTL (1800) ≤ stale 閾値 (1800)`** を
`AnalysisTimeBudgetInvariantTest` で CI 固定する（config/定数を弄って連鎖を壊せない）:

```php
test('解析ジョブの時間 budget 連鎖 (timeout < retry_after < 予約TTL <= stale閾値)', function (): void {
    $timeout = (new RunManualAnalysis(1))->timeout;
    $retryAfter = config()->integer('queue.connections.database-analysis.retry_after');

    // 予約 TTL は台帳の公開 API (reserve) で実測する: 固定時刻で reserve し
    // expires_at − now を実 TTL とする (TicketLedgerService の private 定数を
    // ハードコード複製しない = 台帳側の TTL 変更をこのテストが実際に検出できる)
    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
    [$organization] = createOrganizationWithOwner();
    $tickets = app(TicketLedgerService::class);
    $tickets->grant($organization, 1, '時間 budget テスト用');
    $reservation = $tickets->reserve($organization, 1);
    $ttlSeconds = (int) CarbonImmutable::now()->diffInSeconds($reservation->expires_at);

    $staleSeconds = config()->integer('manual.analysis_stale_after_minutes') * 60;
    expect($timeout)->toBeLessThan($retryAfter);
    expect($retryAfter)->toBeLessThan($ttlSeconds);
    expect($ttlSeconds)->toBeLessThanOrEqual($staleSeconds);
});

test('解析ジョブの connection/queue 名が設定と drift しない', function (): void {
    $job = new RunManualAnalysis(1);
    expect($job->connection)->toBe('database-analysis'); // onConnection() が設定
    expect(config()->string('queue.connections.database-analysis.queue'))->toBe('analysis');
    expect(config()->string('queue.connections.database-analysis.driver'))->toBe('database');
});

test('LLM worst-case (3段×3試行×client timeout) が job timeout に収まる', function (): void {
    $attempts = 1 + config()->integer('manual.analysis_llm_max_retries'); // 3
    $clientTimeout = 120; // 各 YAML client_options.timeout と一致 (YAML 走査で検証)
    expect(3 * $attempts * $clientTimeout + 180)->toBeLessThanOrEqual((new RunManualAnalysis(1))->timeout);
});
```

```php
// config/queue.php connections に追加 (driver は既定 database と同一。retry_after のみ専用値)
'database-analysis' => [
    'driver' => 'database',
    'connection' => env('DB_QUEUE_CONNECTION'),
    'table' => 'jobs',
    'queue' => 'analysis',
    'retry_after' => 1560,
    'after_commit' => false,
],
```

運用ノート（**運用契約**。施策 13 で docs/architecture.md にも記載する）:
- connection を明示した job は `QUEUE_CONNECTION=sync` でも **database-analysis へ投入される**
  （env の既定を上書きする）。専用 worker が居ないとジョブは滞留する
- **本番/ステージングの worker プロセス定義・デプロイ手順・監視対象に
  `php artisan queue:work database-analysis` を必須項目として登録する**
  （queued 滞留は stale 回復 cron が 30 分で failJob するため、滞留 = 監視で気づける）
- ローカル/テストの検証方法: パイプラインの同期実行は **`AnalysisPipeline::run()` の直接呼び出し**、
  dispatch の検証は **`Queue::fake()`**（sync ドライバでの自動実行には依存しない）

### 変更後コード（骨子）

```php
// RunManualAnalysis (queue job は薄い殻。本体は Pipeline)
class RunManualAnalysis implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** 自動再試行しない (§10.8-1。再実行は analyze 再トリガーのみ) */
    public int $tries = 1;

    /**
     * worst-case (3 段 × 3 試行 × 120s = 1,080s) + 抽出/解析余裕 180s + マージン。
     * timeout < retry_after (1,560s) < 予約 TTL (1,800s) の連鎖は
     * AnalysisTimeBudgetInvariantTest が CI 固定する
     */
    public int $timeout = 1380;

    public function __construct(public readonly int $analysisJobId)
    {
        // retry_after を解析専用値にした connection (config/queue.php)。既定 database は 90s のため。
        // Queueable trait が $connection プロパティを既に定義しているため、プロパティ再宣言でなく
        // onConnection() で指定する (typed 再宣言は trait composition エラーになる)
        $this->onConnection('database-analysis');
    }

    public function handle(AnalysisPipeline $pipeline): void
    {
        $pipeline->run($this->analysisJobId);
    }

    /** catch を通らない失敗 (timeout kill 等) の最終防衛線。failJob は冪等 */
    public function failed(?Throwable $exception): void
    {
        $job = AnalysisJob::query()->find($this->analysisJobId);
        if ($job !== null) {
            app(AnalysisJobService::class)->failJob($job, '解析が中断されました。再実行してください。');
        }
    }
}
```

```php
// AnalysisPipeline::run の骨格 (概念設計 §4 の忠実な実装)
public function run(int $analysisJobId): void
{
    $job = AnalysisJob::query()->findOrFail($analysisJobId);
    try {
        if (! $this->startJob($job)) {
            return; // 重複配送 / stale 回復後の遅延配送 → no-op
        }
        $document = $job->sourceDocument;
        Assert::notNull($document, 'trigger が必ず associate している');

        $text = $this->extractor->extract($document);                       // 抽出 + バイト上限
        $extracted = $this->runExtractStep($job, $document, $text);         // LLM 1 段目
        $decomposition = $this->runDecomposeStep($job, $extracted);         // LLM 2 段目
        $generated = $this->runGenerateStep($job, $decomposition);          // LLM 3 段目
        $this->finalize($job, $generated);                                  // terminal tx
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

        $organization = $this->resolveOrganization($locked); // manual→project→organization
        $this->ensureReservation($locked, $organization);    // 残高不足はここで throw → catch → failJob

        $locked->status = JobStatus::Running;
        $locked->step = AnalysisStep::Extract;
        $locked->progress = 10;
        $locked->save();
        $job->refresh();

        return true;
    });
}

/** 予約の冪等確保: 有効な Reserved があれば再利用。Released/失効/Committed は新規 reserve→付け替え */
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
 * terminal tx: materialize + commit + succeeded を原子化 (概念設計 §4-5)。
 * transaction / 行ロックは本メソッド (最外層) だけが張る。
 *
 * グローバルロック順 (全経路がこの順でのみ取得する。逆順取得ゼロ = デッドロックなし):
 *   analysis_jobs → video_manuals → ticket_reservations → organizations
 *
 * TicketLedgerService 内部の実取得順 (実装から転記。内部変更はしない):
 *   - reserve / grant:   organizations のみ (L243/L42 lockOrganizationRow)
 *   - commit / release:  ticket_reservations (lockReservationRow) → organizations (lockOrganizationRow)
 * 各経路の取得列:
 *   - trigger:      video_manuals のみ (balance() はロックなしの集計)
 *   - startJob:     analysis_jobs → (reserve: organizations)
 *   - finalize:     analysis_jobs → video_manuals → (commit: ticket_reservations → organizations)
 *   - failJob:      analysis_jobs → video_manuals → (release: ticket_reservations → organizations)
 *   - releaseStale (billing cron): ticket_reservations → organizations (前方リソースを保持しない)
 *   - ScenarioService::save: video_manuals のみ
 * いずれもグローバル順の部分列であり循環待ちは構成できない。
 */
private function finalize(AnalysisJob $job, GeneratedScenarioData $generated): void
{
    DB::transaction(function () use ($job, $generated): void {
        // ロック 1: job 行 (stale 回復 cron との直列化点)
        /** @var AnalysisJob $locked */
        $locked = AnalysisJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
        if ($locked->status !== JobStatus::Running) {
            return; // stale 回復 cron が先勝ち → materialize も commit もしない (無課金 succeeded 排除)
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
    });
}

/** LLM 段の共通有界リトライ (JSON 検証失敗のみ。長さ・provider 例外はリトライしない) */
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
```

- 各 step メソッドは `withBoundedRetry` 内で「Prompt factory → executeSync → DTO::fromLlmText」を
  実行し、成功後に job の step/progress を更新（extract 完了 35 / decompose 完了 65 /
  generate 完了 90。tx 不要の単発 update だが `whereKey(...)->lockForUpdate()` は不要 =
  progress は表示用の粗い値で、状態機械は status のみが真実源）
- `runExtractStep` は成功時に `source_documents.extracted_json` へ DTO->toArray() を保存
  （write-only 監査スナップショット）、`runDecomposeStep` は `analysis_jobs.result_json` へ保存
- `userMessageFor(Throwable)`: AnalysisFailedException / LlmOutputInvalidException /
  InsufficientTicketsException はそのままユーザー向け文言、その他は汎用文言
  「解析に失敗しました。時間をおいて再実行してください」（内部詳細を error 列に漏らさない）

### PHPStan適合チェック
- [x] withBoundedRetry は @template で型を貫通（`@param callable(): T` → `@return T`）
- [x] HasOneThrough の organization は Assert::isInstanceOf で narrow
- [x] DTO 戻り値の generics 明示

### テスト計画（AnalysisPipelineTest。Prompt::fake + Storage::fake + `AnalysisPipeline::run()` 直接呼び出し）
- [ ] 成功パス: fake 3 応答 → cuts materialize / manual=ready / scenario_version+1 /
      job=succeeded / 予約 committed / extracted_json・result_json 保存
- [ ] 再試行で二重予約しない: 予約付き job を再度 run → reserve が増えない（queued guard も検証）
- [ ] TTL 切れ付け替え: Released 予約付きの queued job → 新予約で完走、旧予約は Released のまま
- [ ] 失敗時 release: LLM 3 回不正 JSON → job=failed + error / manual 復帰 / 予約 released
- [ ] commit は Reserved のみ: finalize 前に予約を Released に細工 → terminal tx rollback /
      cuts 不変 / failed（「failed ∧ committed」「succeeded ∧ released」の非共存アサーション）
- [ ] インターリーブ: (a) cron 先勝ち（job を failed に）→ run 完走しても cuts/commit 無し
      (b) pipeline 先勝ち → 後追い failJob が no-op
- [ ] 有界リトライ: 不正 JSON ×2 → 3 回目成功で succeeded（Prompt::fake の呼び出し回数検証）
- [ ] manual 復帰の分岐: cuts 有り（再解析失敗）→ ready / cuts 無し → draft

### リスク
- LLM 応答が仕様的に正しいが空 steps の場合 → DTO 検証（steps ≥ 1）で LlmOutputInvalidException
  → リトライ → 失敗（materialize が空シナリオで ready を作ることはない）

---

## 施策 7: SOP テキスト抽出

### 変更箇所
- `app/Services/Manual/SopTextExtractor.php` + `app/DataTransferObjects/Manual/Analysis/ExtractedText.php`（新規）
- `composer.json`: `smalot/pdfparser`（PDF テキスト抽出）、`phpoffice/phpspreadsheet`（Excel）

### 変更後コード（骨子）

```php
final readonly class ExtractedText
{
    public function __construct(
        public string $text,
        public int $byteLength,     // strlen (UTF-8 bytes) = token budget 判定値
        public string $sourceKind,  // pdf | spreadsheet | plain (診断用)
    ) {}
}

class SopTextExtractor
{
    /** mime で分岐して抽出。空/超過/破損は AnalysisFailedException (ユーザー向け文言) */
    public function extract(SourceDocument $document): ExtractedText
    {
        $contents = Storage::get($document->file_path); // 不在は例外 → failJob (汎用文言)
        // 分岐はアップロード時に内容 sniff 済みの $document->mime を使う
        // (クライアント拡張子は信頼しない。施策 4 の再判定と対)
        $kind = $this->kindFor($document->mime); // 'pdf' | 'spreadsheet' | 'plain' の literal union
        try {
            $text = match ($kind) {
                'pdf' => $this->fromPdf($contents),          // Smalot\PdfParser\Parser::parseContent
                'spreadsheet' => $this->fromSpreadsheet($contents), // 一時ファイル経由で IOFactory::load → 全シートのセルをタブ/改行結合
                'plain' => $contents,
            };
        } catch (Throwable $exception) {
            // parser の内部例外はユーザー向け文言へ正規化 (詳細は report で内部ログのみ)
            report($exception);
            throw AnalysisFailedException::unextractable();
        }
        // UTF-8 妥当性の担保 (旧 XLS の SJIS 系・PDF の壊れた埋め込み対策)。
        // 推測変換で未知バイナリを「日本語らしき無意味文字列」へ化けさせない strict 手順:
        //   1. mb_check_encoding($text, 'UTF-8') OK → そのまま
        //   2. NG → mb_detect_encoding($text, ['UTF-8', 'SJIS-win', 'EUC-JP'], strict: true)。
        //      判定不能 (false) → AnalysisFailedException::unextractable() (バイナリ扱い。変換しない)
        //   3. 判定 encoding から mb_convert_encoding → 再度 mb_check_encoding で検証。不合格 → unextractable()
        //   4. mb_scrub は「検証合格後の残存破損の限定補修」としてのみ使用 (救済変換には使わない)
        $text = $this->ensureUtf8($text); // JSON 化・UserInput 生成を不正バイトで壊さない
        $text = $this->normalize($text); // 連続空白圧縮 + trim

        $bytes = strlen($text);
        if ($bytes < config()->integer('manual.analysis_min_text_bytes')) {
            throw AnalysisFailedException::unextractable(); // 画像/スキャン → v1 未対応の明示文言
        }
        if ($bytes > config()->integer('manual.analysis_max_text_bytes')) {
            throw AnalysisFailedException::tooLarge();
        }

        return new ExtractedText($text, $bytes, $kind);
    }
}
```

- PhpSpreadsheet はファイルパス入力のため `tmpfile()`/`tempnam` に書き出して読み込み、finally で削除
- 依存追加は `pnpm run audit:gate` を通し、`docs/supply-chain/review-checklist.md` の観点
  （メンテ状況・popularity・脆弱性履歴）を PR に記録

### PHPStan適合チェック
- [x] Storage::get の string|null → Assert::string
- [x] match は kindFor() の戻り値 enum/literal union で網羅

### テスト計画
- [ ] plain テキスト抽出（fixtures/sop.txt）
- [ ] PDF テキスト抽出（テスト用の最小 PDF fixture）
- [ ] xlsx 抽出（PhpSpreadsheet で生成した fixture）
- [ ] 空ファイル → unextractable / max_text_bytes 超過 → tooLarge（config を一時的に下げて検証）
- [ ] SJIS-win テキスト fixture → strict 検出で UTF-8 へ変換され UserInput 生成が壊れない
- [ ] 判定不能バイナリ fixture（乱数バイト列）→ unextractable（推測変換で LLM に渡らない）
- [ ] 変換後の再検証 NG ケース → unextractable

### リスク
- parser 品質（表崩れ・結合セル）は v1 の既知限界。抽出結果は LLM の統一 JSON 化で吸収し、
  最終品質は編集画面での人手修正が担保（doc/03 §3.4 の運用前提）

---

## 施策 8: プロンプト 3 種 + 解析 DTO 群

### 変更箇所
- `resources/prompts/sop-extract.yaml` / `work-decomposition.yaml` / `scenario-generation.yaml`（新規）
- `app/Prompts/SopExtractPrompt.php` / `WorkDecompositionPrompt.php` / `ScenarioGenerationPrompt.php`（新規）
- `app/DataTransferObjects/Manual/Analysis/ExtractedSopData.php` / `WorkDecompositionData.php` / `WorkDecompositionStepData.php` / `GeneratedScenarioData.php`（新規）

### 変更後コード（骨子）

YAML は 3 本とも共通の枠（`DefensiveInstructions::forUserInputJa()` preamble、
`client_options.timeout: 120`、`max_tokens: 16000`、name 一意）。本文は doc/10 §10.4 草案 +
doc/03 ルールを反映。例（work-decomposition.yaml、§10.4 草案ベース）:

```yaml
name: work-decomposition
provider: anthropic
model: claude-sonnet-4-5-20250929
max_tokens: 16000
client_options:
  timeout: 120
system_prompt: |
  {{ \Kent013\PrismPrompt\Values\DefensiveInstructions::forUserInputJa() }}

  あなたは製造現場の作業標準化エキスパートです。資料を「読む」のではなく、
  作業者の体の動き（動詞）ごとに「1 動作 1 行」で解体・再構築します。
  出力は JSON のみ（前後に説明文・コードフェンスを付けない）。
prompt: |
  次の抽出済み手順書データから「作業分解表」を作成し、JSON で出力してください。
  ルール:
  - 一動作・一 No（1 文に複数動詞があれば行を分ける）
  - 手順列は物理的な動詞のみ（「〇〇の清掃」等の括りは禁止）
  - 急所は判断基準・数値・良否境界・資料の注釈のみ。1 急所 1 要素
  - 資料にない語を足さない（指差呼称含め忠実に）
  出力スキーマ: { "steps": [ { "no": int, "action": string, "points": [string] } ] }

  抽出済み手順書データ:
  {{ $extracted }}
```

sop-extract.yaml は `{{ $text }}` から統一 JSON
（`{ "header": {...}, "sections": [ { "title": string|null, "steps": [ { "no": int,
"work_process": string, "work_points": [string], "safety_points": [string],
"quality_points": [string], "pm_points": [string] } ] } ] }`。doc/03 §3.4 の unified スキーマ）。
scenario-generation.yaml は §10.4 草案どおり（narration 語尾統一・字幕①/②・shot_type 原則、
`{ "cuts": [...] }` スキーマ + steps/points 上限の明示）。

```php
// factory (ExampleSummaryPrompt と同型。Prompt::load は app/Prompts のみ = PromptGuardrailTest)
final class WorkDecompositionPrompt
{
    public static function make(string $extractedJson): TextPrompt
    {
        return Prompt::load('work-decomposition', [
            'extracted' => UserInput::from($extractedJson), // 不変条件 4: untrusted は UserInput
        ]);
    }
}
```

```php
// DTO の共通形: fromLlmText がコードフェンス除去 + json_decode + 構造検証。
// 不正は LlmOutputInvalidException (有界リトライのトリガー)
final readonly class WorkDecompositionData
{
    /** @param list<WorkDecompositionStepData> $steps */
    public function __construct(public array $steps) {}

    public static function fromLlmText(string $text): self
    {
        $decoded = LlmJson::decode($text); // 共通ヘルパ: fence 除去 + json_decode + array 検証
        // steps: 1..ScenarioLimits::MAX_STEPS、各 step: no int / action 非空 string /
        // points: list<string> 0..MAX_POINTS_PER_STEP。違反は LlmOutputInvalidException
        // LlmOutputInvalidException は reason を backed enum LlmOutputInvalidReason
        // (InvalidJson='invalid_json' | SchemaViolation='schema_violation') で保持し、
        // report() ログで失敗分類を機械集計できるようにする (文字列 drift を型で防止)
    }

    public function toJsonString(): string { /* 次段プロンプトへ渡す正規化 JSON */ }

    /** @return array<string, mixed> result_json 保存用 */
    public function toArray(): array { /* ... */ }
}
```

```php
// GeneratedScenarioData: cuts 配列を検証しつつ steps ツリーへ変換
// - type: step|point / parent_no: point は既出 step の no を参照 (無参照・前方参照は不正)
// - shot_type: hiki|yori / scene 非空 <= MAX_SCENE_CHARS / narration <= MAX_NARRATION_CHARS
// - subtitle_primary null 可 <= 100 / subtitle_secondary <= 2000
// - steps 1..100 / points/step 0..20 (ScenarioLimits と同値 = 手動保存と同じ有界性)
/** @return list<ScenarioStepInput> materialize 入力 (id=null, materialType=null, staticDisplaySeconds=null) */
public function toScenarioSteps(): array { /* ... */ }
```

### 波及変更
- Architecture テスト（既存が自動追従。**登録漏れがあると fail する**）:
  - `PromptYamlContractTest`: name 一意
  - `PromptClientTimeoutInvariantTest`: client_options.timeout 必須
  - `DefensiveInstructionsPresenceTest`: preamble 必須
  - `PromptUntrustedInputContractTest`: `{{ $var }}` は UserInput 経由

### PHPStan適合チェック
- [x] LlmJson::decode は `array<array-key, mixed>` を返し、各 DTO が Assert で narrow
- [x] DTO は readonly / list<> 型 PHPDoc / toArray の typed array shape

### テスト計画
- [ ] 各 DTO の fromLlmText: 正常 / コードフェンス付き / 不正 JSON / スキーマ違反 /
      上限超過 / parent_no 不整合 → LlmOutputInvalidException（unit）
- [ ] factory: Prompt::fake で UserInput タグが描画されること（ExampleSummaryPromptTest と同型）
- [ ] PromptGuardrailTest ほか既存 Architecture テストが green

### リスク
- プロンプト品質（生成シナリオの质）は実データでの調整が必要 → プロンプト本文の改善は
  YAML 差し替えのみで可能な構造（コード変更不要）にしておく

---

## 施策 9: materialize + 書き込み経路 inventory

### 変更箇所
- `app/Services/Manual/ScenarioService.php`（`materializeIntoLockedManual()` 追加 + upsertCut の共用化）
- `tests/Architecture/ScenarioWritePathInventoryTest.php`（新規）
- `docs/architecture.md`（経路 inventory 表の更新。施策 13）

### 変更後コード（骨子）

```php
/**
 * AI 解析結果の materialize (共有ロック規約の第 2 の書き込み経路。概念設計 §5)。
 *
 * **ロック済み前提メソッド**: transaction / lockForUpdate は呼び出し側 (AnalysisPipeline::
 * finalize の terminal tx) が最外層で張る。本メソッドは内側 transaction を張らない
 * (transaction/lock の層を 1 箇所に統一しロック順逆転を構造的に防ぐ)。
 * 前提の担保は 2 層 (PHPDoc 前提だけに依存しない):
 *  1. **呼び出し経路の構造的限定**: 本メソッドの呼び出し元は AnalysisPipeline のみ。
 *     ScenarioWritePathInventoryTest が「app/ 内で `materializeIntoLockedManual(` を
 *     呼ぶファイルは AnalysisPipeline だけ」を deny-by-default で機械検証する (施策 9)
 *  2. runtime 検査 (defensive):
 *     - DB::transactionLevel() === 0 → LogicException (tx 外呼び出しの検出)
 *     - $lockedManual->status !== analyzing → LogicException (terminal tx ごと rollback → failJob)
 *
 * - 既存 cuts 全削除 → 生成ツリー挿入 (再解析は全置換)。sort_order/parent/type はサーバ導出
 * - version+1 と status(analyzing→ready) を cuts と同一 tx で反映 (共有ロック規約)
 *
 * @param list<ScenarioStepInput> $steps
 */
public function materializeIntoLockedManual(VideoManual $lockedManual, array $steps): void
{
    if (DB::transactionLevel() === 0) {
        throw new LogicException('materialize はロック済みトランザクション内からのみ呼び出せます');
    }
    if ($lockedManual->status !== VideoManualStatus::Analyzing) {
        throw new LogicException('materialize は analyzing 中のみ実行できます');
    }

    // 全置換 (each->delete: save() と同じ理由で bulk delete を避ける。配下 Take は FK cascade)
    $lockedManual->cuts()->get()->each->delete();

    $changed = true; // 生成は常に実変更 (upsertCut の isDirty 追跡は新規行で必ず true)
    foreach ($steps as $stepIndex => $stepInput) {
        /** @var Collection<int, Cut> $noExisting */
        $noExisting = new Collection;
        $step = $this->upsertCut($lockedManual, $noExisting, $stepInput, CutType::Step, null, $stepIndex, $changed);
        foreach ($stepInput->points as $pointIndex => $pointInput) {
            $this->upsertCut($lockedManual, $noExisting, $pointInput, CutType::Point, $step->id, $pointIndex, $changed);
        }
    }

    $lockedManual->forceFill([
        'scenario_version' => $lockedManual->scenario_version + 1,
        'status' => VideoManualStatus::Ready,
    ])->save();
}
```

（`upsertCut` は既存実装のまま流用可能: `$input->id === null` の新規経路 + 空の existing
Collection で「全行 insert」として機能する。`GeneratedScenarioData::toScenarioSteps()` が
id=null を保証）

```php
// ScenarioWritePathInventoryTest — deny-by-default の token ベース静的走査
// (PrismDirectDispatchScanner と同じ token_get_all 流儀。コメント/docblock/文字列リテラル中の
//  出現は無視し誤検出しない)。走査対象: app/ 配下の .php
//
// 検出 1: 識別子/配列キー 'scenario_version' の出現 → allowlist 外のファイルなら fail
//   allowlist: ScenarioService.php (save/materializeIntoLockedManual)、
//              VideoManual.php (@property 宣言は docblock のため token 走査では元々対象外)
// 検出 2: 書き込み形 `'status' => VideoManualStatus::` / `->status = VideoManualStatus::`
//   → allowlist (ScenarioService.php / AnalysisJobService.php) 外なら fail
// 検出 3: materializeIntoLockedManual の「呼び出し」の経路限定。token 列で
//   `T_OBJECT_OPERATOR (->) + T_STRING(materializeIntoLockedManual) + '('` = 呼び出し、
//   `T_FUNCTION + T_STRING(materializeIntoLockedManual)` = 宣言、を区別する。
//   - 宣言は ScenarioService.php にのみ存在してよい
//   - 呼び出しは AnalysisPipeline.php にのみ存在してよい (それ以外のファイルは fail。
//     **ScenarioService 自身の中に新たな呼び出しを書いても fail** = ファイル単位 allowlist の
//     抜け穴を塞ぐ)
//
// scanner の自己検証 (PromptGuardrailTest の scanner self-test と同型):
//   - 合成ソース「コメント内の materializeIntoLockedManual(」→ 検出しない
//   - 合成ソース「$this->materializeIntoLockedManual($m, $s);」→ 呼び出しとして検出する
//   - 合成ソース「public function materializeIntoLockedManual(」→ 宣言として検出する
//   - 走査対象 (app/) が解決でき、対象ファイル数 > 0 (degenerate PASS 防止)
```

### PHPStan適合チェック
- [x] `new Collection` は `Collection<int, Cut>` として @var 注記
- [x] list<ScenarioStepInput> の型貫通

### テスト計画
- [ ] materialize 成功: steps+points ツリーが sort_order 0..N-1 / parent_cut_id / type
      サーバ導出で保存され、version+1 / status=ready
- [ ] 再解析: 既存 cuts が全置換される（旧 cut id が消える）
- [ ] analyzing 以外で呼ぶと LogicException（terminal tx rollback 経路は施策 6 のテスト）
- [ ] ScenarioWritePathInventoryTest 本体: 現行コードベースで green（allowlist 網羅）
- [ ] scanner 自己検証: 合成ソースで「コメント内出現は無視 / `->materializeIntoLockedManual(`
      呼び出しは検出 / `function materializeIntoLockedManual(` 宣言は検出」を unit で固定
      （許可外呼び出しを検出できることの証明）
- [ ] 走査対象ディレクトリ解決 + 対象ファイル数 > 0 の degenerate PASS 防止

### リスク
- token 走査でもエイリアス経由の間接呼び出し（callable 化等）は検出できないが、
  そのような迂回はレビューで弾く前提（fail-closed の第一防衛 + runtime 検査の第二防衛）

---

## 施策 10: stale 回復 cron

### 変更箇所
- `routes/console.php`

### 変更後コード

```php
/*
| AI 解析 cron: dispatch 喪失 (queued 滞留) と worker 異常終了 (running 滞留) の回復。
| failJob は行ロック + terminal guard で冪等 (billing:release-stale-reservations と同型)。
*/
Artisan::command('analysis:recover-stale-jobs', function (AnalysisJobService $jobs) {
    $recovered = $jobs->recoverStale();
    $this->info("recovered {$recovered} stale analysis job(s)");
})->purpose('滞留した解析ジョブ (queued/running が閾値超過) を失敗確定し予約を解放する');

Schedule::command('analysis:recover-stale-jobs')->everyFiveMinutes();
```

### テスト計画（AnalysisRecoverStaleJobsTest）
- [ ] queued 31 分経過 → failed + manual 復帰 + 予約 released（travel() で時間操作）
- [ ] running 31 分経過（updated_at 古い）→ 同上
- [ ] 閾値内の queued/running は回収されない
- [ ] terminal (succeeded/failed) は対象外
- [ ] 回収後に遅延配送された RunManualAnalysis が no-op（queued guard）

### リスク
- なし（既存 cron パターンの踏襲）

---

## 施策 11: フロントエンド

### 変更箇所
- `resources/js/types/manual.ts`（AnalysisJob 型追加）
- `resources/js/pages/Manuals/Create.svelte`（手順書ファイル入力）
- `resources/js/pages/Manuals/Show.svelte`（AnalysisPanel 組み込み + props 拡張）
- `resources/js/components/features/manual/AnalysisPanel.svelte`（新規）

### 波及変更
- Inertia Props: `Manuals/Show` の Props interface に `analysis` を追加（PHP 側 typed array と対）
- テストファイル: `tests/js/pages/ManualsShow.test.ts`（既存があれば props 追加に追従）

### 変更後コード（骨子）

```ts
// types/manual.ts に追加 (PHP: AnalysisJobData::toArray() と対)
export type AnalysisJobStatus = "queued" | "running" | "succeeded" | "failed";
export type AnalysisStep = "extract" | "decompose" | "generate";

export interface AnalysisJobProps {
    id: number;
    status: AnalysisJobStatus;
    step: AnalysisStep | null;
    progress: number | null;
    error: string | null;
    manual_status: VideoManualStatus;
}

export const ANALYSIS_STEP_LABELS: Record<AnalysisStep, string> = {
    extract: "手順書を読み取り中",
    decompose: "作業を分解中",
    generate: "シナリオを生成中",
};
```

```svelte
<!-- AnalysisPanel.svelte (features/manual)。責務: AI 解析の起動・進捗・エラー表示 -->
<script lang="ts">
    // props: projectId, manualId, manualStatus, job (AnalysisJobProps|null), hasDocument, canManage
    // 状態: analyzing 判定は manualStatus === "analyzing" || job?.status が queued/running
    // 起動: fetch POST `/projects/{p}/manuals/{m}/analyze` (X-XSRF-TOKEN = lib/csrf.ts)
    //   - 201 → job を state に置きポーリング開始
    //   - 402/409/422 → 応答 JSON の message をエラー表示 (ボタンは disabled にしない = DESIGN.md)
    //   - ready からの起動は ConfirmDialog「既存のシナリオは AI 生成結果で置き換えられます」
    // ポーリング: $effect + setInterval 2500ms で GET `/jobs/{id}`。
    //   succeeded → clearInterval + router.reload()（ready 反映）
    //   failed → clearInterval + error 表示 + 「再実行」ボタン
    //   onDestroy/$effect cleanup で必ず clearInterval
    //   visibilitychange 連動: document.hidden で停止、再表示で即時 1 回 fetch → 再開
    //   （バックグラウンドタブの無駄打ちを避ける）
    // アイコン: Sparkles (起動) / LoaderCircle (進捗) — @lucide/svelte のみ
    // スタイル: DS token のみ (text-*, bg-surface-*, border-border 等)
</script>
```

- `Manuals/Show.svelte`: 「シナリオ (準備中)」Card を AnalysisPanel に置き換え
  （canManage=false の撮影者には進捗表示のみ = analyze ボタン無し）
- `Manuals/Create.svelte`: `document` の `<input type="file">` を FormField 経由で追加。
  `useForm` は multipart を自動処理（`form.post` に file を含める）。accept は
  `.pdf,.xlsx,.xls,.txt`

### PHPStan適合チェック
- 該当なし（TS 側は `pnpm typecheck` / literal union の drift は手動確認の既存規約）

### テスト計画（Vitest）
- [ ] AnalysisPanel: draft+document で解析ボタン表示 / 押下 → fetch POST が飛ぶ（fetch mock）
- [ ] 402/422 応答でエラーメッセージ表示（ボタンは押せるまま）
- [ ] analyzing 中は進捗表示 + step ラベル、succeeded 応答で router.reload 呼び出し
- [ ] failed 表示 + 再実行ボタン
- [ ] ManualsCreate: file 入力が form data に含まれる

### リスク
- ポーリングの多重起動（Inertia ページ再訪）→ $effect cleanup で interval を必ず破棄

---

## 施策 12: テスト一式（配置と responsibility）

| ファイル | 検証対象 |
|---|---|
| `tests/Feature/Projects/SourceDocumentUploadTest.php` | 施策 4（アップロード・422/403/404・immutable 追記） |
| `tests/Feature/Projects/ManualAnalyzeTest.php` | 施策 5（トリガー冪等・402/409/422/403/404・保護キー・dispatch） |
| `tests/Feature/Projects/AnalysisJobPollingTest.php` | 施策 5（GET jobs: shape・権限・cross-manual 404） |
| `tests/Feature/Projects/AnalysisPipelineTest.php` | 施策 6-9（成功/失敗/2 フェーズ/インターリーブ/リトライ/materialize） |
| `tests/Feature/Projects/AnalysisRecoverStaleJobsTest.php` | 施策 10（stale 回復） |
| `tests/Architecture/AnalysisTokenBudgetInvariantTest.php` | 施策 2（token budget 算術） |
| `tests/Architecture/ScenarioWritePathInventoryTest.php` | 施策 9（書き込み経路 deny-by-default） |
| `tests/js/components/features/AnalysisPanel.test.ts` | 施策 11 |
| `tests/js/pages/ManualsCreate.test.ts` | 施策 11 |

共通セットアップ: `createOrganizationWithOwner()` + `Project::factory()->forOrganization()` +
`VideoManual::factory()->forProject()` + `SourceDocument::factory()->forManual()`（既存ヘルパ）。
LLM は `Prompt::fake([...])`（fixture: 統一 JSON / 作業分解 JSON / cuts JSON の 3 応答）、
チケットは `TicketLedgerService::grant()` で残高を作る（実 Stripe 不要）。
`RefreshDatabase` はグローバル適用（個別 DatabaseTransactions 禁止）。

並列実行（`--parallel`）下の決定性:
- キューのモードはテストごとに明示する（トリガーのみ検証 = `Queue::fake()`、
  パイプライン実走 = **`AnalysisPipeline::run()` の直接呼び出し**。dispatch の同期実行には
  依存しない = 専用 connection の運用ノートと整合）。暗黙の env 依存にしない
- 時刻依存（stale 回復・TTL）は `$this->travelTo(CarbonImmutable::parse('...'))` で固定時刻に
  ピン留めし、実時刻へ依存しない
- fixture は全て Factory 生成（id はプロセス別 DB で自動採番。手組み id 直書きをしない）
- `Storage::fake()` / `Prompt::fake()` はテスト内で完結（StrayLlmCallGuard が fake 漏れを検出）

---

## 施策 13: ドキュメント更新

- `doc/10_実装仕様.md` §10.2: `ready → analyzing`（再解析。SOP 差し替え後の明示トリガー）と
  失敗復帰規則（cuts ≥1 → ready / 無し → draft）を追記（施策 5 の遷移表を転記）。
  §10.5 の LLM 入力上限（UTF-8 バイト・token budget 導出）の根拠も config と同一の値・式で追記
- `NestedRouteIdorDefenseTest` inventory 更新（施策 3）を実装完了条件として明記（監査性）
- `docs/architecture.md`: AnalysisJobService / AnalysisPipeline / SopTextExtractor /
  SourceDocumentService の行を Service 表へ追加、共有不変条件セクションの経路 inventory を
  「Architecture テストへ昇格済み」に更新（メソッド粒度の表を転記）。
  **運用契約**: 解析ジョブは専用 connection `database-analysis` — 本番/ステージングの
  worker プロセス定義・デプロイ手順・監視対象に `queue:work database-analysis` を必須登録
  （施策 6 の運用ノートを転記）
- `docs/factories.md`: AnalysisJobFactory 追記
- `AGENTS.md` ドメイン固有規約 1: 「経路 inventory を持つ Architecture テストへ昇格する」の
  条件が成立 → `ScenarioWritePathInventoryTest` 昇格済みの旨を追記
- 依存追加（smalot/pdfparser / phpoffice/phpspreadsheet）の採用理由と audit:gate green を
  PR 説明に記録（`docs/supply-chain/review-checklist.md` の新規依存観点）

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 新テーブル + 新 Service 群 + queue job + LLM + UI を跨ぐ大きめの独立フィーチャ。既存ファイルへの変更は追記的（routes / Policy / bootstrap / ScenarioService へのメソッド追加）で、他 TODO と競合しにくいが、変更ファイル数が多く worktree で一括実装 → 全 green → マージが安全 |
| 競合リスク | `ScenarioService` / `routes/web.php` / `NestedRouteIdorDefenseTest` / `types/manual.ts` は他フィーチャ（撮影 PWA・レンダ）も触る想定 → 本フィーチャを先に main へ入れてから後続に着手する順序で回避 |
| 段階化 | (1) 状態機械 + 課金の閉塞（施策 1,2,3,5,6 の骨格,10） → (2) 抽出 + LLM + materialize（施策 7,8,9） → (3) UI（施策 4 の画面,11） の順にテストを green にしていく |

## 使命・禁止事項チェック

- 全施策が North Star（SOP 起点の AI カット設計）の中核に直接寄与する
- LLM は Prompt factory 経由のみ / prompt は YAML / UserInput 経由（禁止事項 5,6・不変条件 4）
- `response()->json()` 直書きなし（JsonResource + render() パターン。禁止事項 4）
- disabled ボタンなし（402/422 は押下時にエラー表示。禁止事項 8）
- テストなしの実装完了なし（全不変条件が Feature/Architecture テストに登録される。禁止事項 1）
- チケットは reserve→commit/release の 2 フェーズ・TicketLedgerService 公開 API のみ（不変条件 7）
