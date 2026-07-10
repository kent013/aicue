# 詳細設計: render（レンダ: 採用テイク合成 → 完成 mp4。ffmpeg + チケット 2 フェーズ）

作成: 2026-07-11 / ステータス: **APPROVED**（Codex gpt-5.3-codex/high 詳細レビュー Round 3。
全 16 施策 APPROVE・Critical/Warning なし。履歴は codex-history/）
改訂履歴: Round 1 反映（ロック順の単一真実源化・failed() error_code 分類・ASS 境界
ハードニング・filename 契約・ポーリング scheduler 1 本化・reconcile 可観測性 ほか）、
Round 2 反映（ASS 正規化順の修正・S3 削除の tx 分離 + CAS NULL 化・境界テスト追加）、
Round 3 反映（mb_substr によるマルチバイト安全な切り詰め）

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。
- v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項（AGENTS.md）

1. テストなしの実装完了報告（不変条件は Architecture/Feature テスト登録まで含めて「実装済み」）
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う）
5. LLM 呼び出しの Prism 直呼び（本設計は LLM 呼び出しなし）
6. prompt 文字列のコード直書き（同上）
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示）

### セキュリティ不変条件（AGENTS.md。アプリ都合で緩めない）

1. tenant キー不信（`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`）
2. 子は親に属する（nested route 不整合は認可より前に 404。`NestedRouteIdorDefenseTest` 登録必須）
3. cross-org 不可（relation / org-scoped 解決経由のみ）
7. 課金の冪等性（チケットは reserve→commit/release の 2 フェーズ）

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）。**RefreshDatabase はグローバル適用**
  （個別 `DatabaseTransactions` 使用禁止）、`--parallel` 実行
- **テストデータは必ず Factory で生成**。新モデル `RenderJob` は **`RenderJobFactory` を追加**し
  `docs/factories.md` / `docs/architecture.md` へ追記
- **DTO + JsonResource** パターン、アーリーリターン推奨、`declare(strict_types=1)` + 日本語コメント
- Controller は薄く（Service 委譲）、transaction は Service 内、保護キーは forceFill / relation 明示代入
- フォーマット: `composer fix` / `pnpm lint:fix`。PHP 8.4 + Laravel 12 + Svelte 5 + Inertia + TS

## 概念設計リファレンス

[devnotes/20260711-0549-render/conceptual-design.md](conceptual-design.md)（**APPROVED**:
Codex gpt-5.4 概念レビュー Round 5。§番号参照は概念設計のもの）

**設計の骨格（再掲）**: T003 AnalysisJob 系（`AnalysisJobService` / `AnalysisPipeline` /
`RunManualAnalysis`）を見本に、§10.8 方針どおり**共通抽象化せず個別実装**。
チケット 2 フェーズ（予約冪等キー・TTL 付け替え・commit は Reserved のみ・tries=1）、
version スナップショット固定（§10.8-6）、render 冪等（§10.8-8）、
ポーリングと成果物アクセスの権限分離、ffmpeg は `VideoComposer` 抽象 + `Process` facade 隔離。

**グローバルロック順（単一真実源。詳細レビュー Round 1 反映）**:

```
render_jobs → video_manuals → ticket_reservations → organizations
```

（analysis 系の既存順 `analysis_jobs → video_manuals → …` と同構造。analysis_jobs と
render_jobs を同一 tx でロックする経路は存在しないため両者の相対順は定義不要）

- **正本は `docs/architecture.md` のロック順序節**（施策 16 で追記）とし、
  `RenderPipeline` クラス docblock は**参考転記**（正本への参照リンク付き。
  AnalysisPipeline::finalize docblock と同じ流儀。「単一真実源」は正本側であり、
  転記は複製である旨を双方に明記して乖離時は正本優先 = 詳細レビュー Round 2 Suggestion）
- 全経路は下表のとおり**グローバル順の部分列のみ**で構成する（逆順取得ゼロ =
  循環待ちを構成できない）。新経路追加時は本表 + docs/architecture.md を同時更新

| 経路 | 取得列（すべてグローバル順の部分列） |
|---|---|
| RenderJobService::trigger | video_manuals のみ（balance() はロックなし集計） |
| RenderJobService::triggerPreview | video_manuals → organizations |
| RenderPipeline::startJob | render_jobs → (render のみ reserve 内部: organizations) |
| RenderPipeline::buildManifest | video_manuals（読み取り一貫性の確定点） |
| RenderPipeline::finalize | render_jobs → video_manuals → (render のみ commit 内部: ticket_reservations → organizations) |
| RenderJobService::failJob | render_jobs → video_manuals → (release 内部: ticket_reservations → organizations) |
| DeleteRenderOutputsJob::handle | 行ロックなし（読み取り検証 → tx 外 S3 削除 → CAS update の 3 段） |

## 施策一覧

| # | 施策名 | 主な変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | render_jobs テーブル + RenderJob Model + enum 3 種 + Factory | migration / `app/Models/RenderJob.php` / `app/Enums/Manual/{RenderStep,RenderKind,RenderErrorCode}.php` / `database/factories/RenderJobFactory.php` / `app/Models/VideoManual.php` | P0 |
| 2 | config + 専用 queue connection + 時間 budget 不変条件 | `config/manual.php` / `config/queue.php` / `tests/Architecture/RenderTimeBudgetInvariantTest.php` | P0 |
| 3 | RenderConflictException + Type + Resource | `app/Exceptions/Manual/RenderConflictException.php` / `app/Enums/Manual/RenderConflictType.php` / `app/Http/Resources/Manual/RenderConflictResource.php` | P0 |
| 4 | RenderJobService（trigger / triggerPreview / failJob / completeRenderIntoLockedManual / recoverStale） | `app/Services/Manual/RenderJobService.php` | P0 |
| 5 | RenderManifest DTO 群 + マニフェスト構築 | `app/DataTransferObjects/Manual/Render/*.php` | P0 |
| 6 | AssSubtitleWriter（字幕 ASS 生成・エスケープ境界） | `app/Services/Render/AssSubtitleWriter.php` | P0 |
| 7 | VideoComposer 抽象 + FfmpegVideoComposer + RenderObjectStorage | `app/Services/Render/{VideoComposer,FfmpegVideoComposer,RenderObjectStorage}.php` | P0 |
| 8 | RenderPipeline + RunManualRender | `app/Services/Manual/RenderPipeline.php` / `app/Jobs/Manual/RunManualRender.php` | P0 |
| 9 | DeleteRenderOutputsJob + console 2 command | `app/Jobs/Manual/DeleteRenderOutputsJob.php` / `routes/console.php` | P1 |
| 10 | routes + Controller + FormRequest + rate limiter | `routes/web.php` / `app/Http/Controllers/Projects/{ManualRenderController,ManualDownloadController}.php` / `app/Http/Requests/Projects/{TriggerRenderRequest,DownloadManualRequest}.php` / `app/Providers/AppServiceProvider.php` | P0 |
| 11 | Policy（render / download。親委譲） | `app/Policies/VideoManualPolicy.php` | P0 |
| 12 | RenderJobData DTO + RenderJobResource + TS 型 + enum 同期テスト | `app/DataTransferObjects/Manual/RenderJobData.php` / `app/Http/Resources/Manual/RenderJobResource.php` / `resources/js/types/manual.ts` / `tests/Architecture/ManualEnumTsSyncInvariantTest.php` | P0 |
| 13 | Architecture テスト登録（IDOR inventory / 書き込み経路 inventory 拡張） | `tests/Architecture/{NestedRouteIdorDefenseTest,ScenarioWritePathInventoryTest}.php` | P0 |
| 14 | フロント RenderPanel + Show props | `resources/js/pages/Manuals/Show.svelte` / `resources/js/components/features/manual/RenderPanel.svelte` / `app/Http/Controllers/Projects/VideoManualController.php` | P1 |
| 15 | Feature/Unit テスト一式 | `tests/Feature/Manual/Render*.php` / `tests/Unit/Render/*.php` / `tests/js/**` | P0 |
| 16 | ドキュメント更新 | `doc/10_実装仕様.md` / `docs/architecture.md` / `docs/factories.md` | P2 |

---

## 施策 1: render_jobs テーブル + RenderJob Model + enum 3 種 + Factory

### 変更箇所

- 新規: `database/migrations/2026_07_11_100000_create_render_jobs_table.php`
- 新規: `app/Models/RenderJob.php`
- 新規: `app/Enums/Manual/RenderStep.php` / `RenderKind.php` / `RenderErrorCode.php`
- 新規: `database/factories/RenderJobFactory.php`
- 変更: `app/Models/VideoManual.php`（`renderJobs()` relation 追加）
- 変更: `app/Enums/Manual/JobStatus.php`（コメントのみ: 「RenderJob も使用」へ更新）

### 波及変更

- TypeScript 型定義: 施策 12（RenderKind/RenderStep/RenderErrorCode の literal union）
- API Resource/DTO: 施策 12
- テストファイル: `MassAssignmentSafetyTest` は自動走査（$fillable なしのため追加登録不要を確認）、
  施策 13/15

### 変更後コード（骨子）

migration（`create_analysis_jobs_table` を見本に）:

```php
Schema::create('render_jobs', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('video_manual_id')->constrained()->cascadeOnDelete();
    $table->string('kind');                       // RenderKind: render|preview
    $table->string('status')->default('queued');  // JobStatus (既存 enum 共用)
    $table->string('step')->nullable();           // RenderStep: compose|concat
    $table->unsignedSmallInteger('progress')->nullable();
    $table->unsignedInteger('scenario_version');  // §10.8-6 スナップショット (NOT NULL)
    $table->foreignId('ticket_reservation_id')->nullable()
        ->constrained('ticket_reservations')->nullOnDelete(); // preview は常に NULL
    $table->string('output_path', 1024)->nullable(); // 世代交代の削除後は NULL 化
    $table->text('error')->nullable();
    $table->string('error_code')->nullable();     // RenderErrorCode (3 値で閉じる)
    $table->timestamps();
    $table->index(['video_manual_id', 'kind', 'status']); // in-flight 判定 / 最新 succeeded 解決
    $table->index(['status', 'updated_at']);              // stale 回復走査
    $table->index(['kind', 'status']);                    // org preview 上限 / reconcile 走査
});
```

enum（backed string・v1 で閉じる）:

```php
enum RenderStep: string { case Compose = 'compose'; case Concat = 'concat'; }
enum RenderKind: string { case Render = 'render'; case Preview = 'preview'; }
enum RenderErrorCode: string
{
    case ScenarioVersionChanged = 'scenario_version_changed'; // preview のトリガー後編集
    case Timeout = 'timeout';                                 // stale 回復 / timeout kill
    case Internal = 'internal';                               // それ以外 (詳細は report())
}
```

`RenderJob` Model は `AnalysisJob` と同型（**$fillable なし** = status/step/progress/kind/
scenario_version/output_path/error/error_code は Service 管理・明示代入のみ。
`video_manual_id` / `ticket_reservation_id` は保護キー登録済み）:

```php
/**
 * @property int $id
 * @property int $video_manual_id
 * @property RenderKind $kind
 * @property JobStatus $status
 * @property RenderStep|null $step
 * @property int|null $progress
 * @property int $scenario_version
 * @property int|null $ticket_reservation_id
 * @property string|null $output_path
 * @property string|null $error
 * @property RenderErrorCode|null $error_code
 */
class RenderJob extends Model
{
    /** @use HasFactory<RenderJobFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => RenderKind::class,
            'status' => JobStatus::class,
            'step' => RenderStep::class,
            'progress' => 'integer',
            'scenario_version' => 'integer',
            'error_code' => RenderErrorCode::class,
        ];
    }
    // videoManual(): BelongsTo<VideoManual, $this> / ticketReservation(): BelongsTo<TicketReservation, $this>
}
```

`VideoManual::renderJobs()`（route param `{renderJob}` の scopeBindings 推論名と一致）:

```php
/** @return HasMany<RenderJob, $this> */
public function renderJobs(): HasMany
{
    return $this->hasMany(RenderJob::class);
}
```

`RenderJobFactory` は `AnalysisJobFactory` と同型: `definition()`（video_manual_id 連鎖・
kind=render・status=queued・scenario_version=0）+ states
`preview()` / `forManual()` / `running()` / `succeeded(string $outputPath)` /
`failed(RenderErrorCode $code, string $error)`。

### PHPStan 適合チェック

- [x] casts() の戻り値 `array<string, string>`、relation generics（`HasMany<RenderJob, $this>`）
- [x] @property で enum cast 型を宣言（AnalysisJob と同一流儀）
- [x] DTO を返す（Model からの直接配列返却なし）

### テスト計画

- [ ] `MassAssignmentSafetyTest` が render_jobs の保護キー（video_manual_id /
      ticket_reservation_id）$fillable 不含を検出対象にすること（既存走査で自動。green 確認）
- [ ] Factory の親連鎖（`RenderJob::factory()->create()` で VideoManual まで生成）

### リスク

- doc/10 §10.1 に無い列（kind / scenario_version / error_code）の追加 → 施策 16 で
  doc/10 §10.1 を同時更新（概念設計で承認済みの divergence）

---

## 施策 2: config + 専用 queue connection + 時間 budget 不変条件

### 変更箇所

- `config/manual.php`（追記）
- `config/queue.php`（`database-render` connection 追記）
- 新規: `tests/Architecture/RenderTimeBudgetInvariantTest.php`

### 変更後コード

`config/manual.php` 追記（既存 analysis 系の下に render セクション）:

```php
// ── レンダ (doc/10 §10.5 / §10.8-1 / 概念設計 §9) ──────────────────
'render_ticket_cost' => 3,                    // COST_RENDER (v1 固定。係数化は後続)
'render_stale_after_minutes' => 30,           // running の stale 閾値
'render_queued_stale_after_minutes' => 10,    // queued の短 SLA (編集ブロック最小化)
'render_max_total_source_ms' => 1_200_000,    // 尺上限ソフトゲート (20 分)
'render_default_take_duration_ms' => 60_000,  // duration_ms NULL テイクの保守的代用値
'render_max_inflight_previews_per_org' => 3,  // org 同時 preview 上限
'preview_placeholder_seconds' => 3,           // 採用テイク欠落 cut のプレースホルダ尺
'render_resolution' => '1920x1080',
'render_fps' => 30,
'render_ffmpeg_binary' => env('RENDER_FFMPEG_BINARY', 'ffmpeg'),
'render_ffprobe_binary' => env('RENDER_FFPROBE_BINARY', 'ffprobe'),
'render_subtitle_font' => env('RENDER_SUBTITLE_FONT', 'Noto Sans CJK JP'),
'render_playback_url_ttl_minutes' => 10,      // preview 再生 / DL 署名 URL の TTL
```

`config/queue.php`（database-analysis の下）:

```php
// レンダ専用 (RunManualRender)。運用契約: worker は
// `php artisan queue:work database-render` を必須登録 (docs/architecture.md)
'database-render' => [
    'driver' => 'database',
    'connection' => env('DB_QUEUE_CONNECTION'),
    'table' => env('DB_QUEUE_TABLE', 'jobs'),
    'queue' => 'render',
    'retry_after' => 1680,
    'after_commit' => false,
],
```

`RenderTimeBudgetInvariantTest`（`AnalysisTimeBudgetInvariantTest` と同型）:
`RunManualRender::$timeout (1500) < retry_after (1680) < 予約 TTL (1800) ≤ stale running 閾値
(render_stale_after_minutes*60 = 1800)` + `queued 閾値 (600) < running 閾値 (1800)` を CI 固定。
テスト docblock に「**`render_max_total_source_ms` を引き上げる際は timeout 1500s に
実レンダが収まるかを実測で再確認せよ**（尺上限と時間 budget は連動する運用値）」の
注記を含める（詳細レビュー Round 1 Suggestion）。

### テスト計画

- [ ] `RenderTimeBudgetInvariantTest`（上記連鎖 + queued < running）

### リスク

- `.env.example` に RENDER_FFMPEG_BINARY 等を追記（`EnvExampleInvariantTest` の対象確認）

---

## 施策 3: RenderConflictException + RenderConflictType + Resource

### 変更箇所

- 新規: `app/Enums/Manual/RenderConflictType.php` / `app/Exceptions/Manual/RenderConflictException.php` /
  `app/Http/Resources/Manual/RenderConflictResource.php`
  （`AnalysisConflict*` 3 点セットと同型。`code: 'render_conflict'` 厳格一致）

### 変更後コード（骨子）

```php
enum RenderConflictType: string
{
    case InFlight = 'in_flight';                     // 同一 manual・同一 kind の in-flight あり
    case StatusNotRenderable = 'status_not_renderable';   // render: ready 以外
    case StatusNotPreviewable = 'status_not_previewable'; // preview: analyzing・rendering 中
    case OrgPreviewLimit = 'org_preview_limit';      // org 同時 preview 上限超過

    // UI 向け説明文はサーバで確定 (render/preview で文脈が異なるため case を分け、
    // クライアントの文言分岐を不要にする = 詳細レビュー Round 1 Suggestion)
    public function message(): string { ... }
}
```

`RenderConflictException`（`render(Request): JsonResponse` → `RenderConflictResource` 409）。
Resource の shape は `{ code: 'render_conflict', conflict_type, message }`。

### PHPStan 適合チェック

- [x] Resource `toArray(Request): array{code: 'render_conflict', conflict_type: string, message: string}`

### テスト計画

- [ ] 409 応答 shape（Feature 内で検証。施策 15）

---

## 施策 4: RenderJobService（状態機械。共有ロック規約の新 4 経路）

### 変更箇所

- 新規: `app/Services/Manual/RenderJobService.php`（`AnalysisJobService` を見本に個別実装）

### 波及変更

- `ScenarioWritePathInventoryTest` allowlist（施策 13）
- `docs/architecture.md` の経路 inventory 表（施策 16）

### 変更後コード（メソッド仕様）

```php
class RenderJobService
{
    public function __construct(private readonly TicketLedgerService $tickets) {}

    /** 完成レンダのトリガー (§10.8-8 冪等 + 残高事前チェック + enqueue) */
    public function trigger(Project $project, VideoManual $manual): RenderJob
    /** プレビューのトリガー (チケット非消費・status 遷移なし・org 上限直列化) */
    public function triggerPreview(Project $project, VideoManual $manual): RenderJob
    /** 失敗確定 (冪等)。pipeline catch / Job::failed / recoverStale の合流点 */
    public function failJob(RenderJob $job, RenderErrorCode $code, string $error): bool
    /** finalize 専用: ロック済み manual へ cut_length_ms / total_length_ms / published を反映 */
    public function completeRenderIntoLockedManual(VideoManual $lockedManual, RenderResult $result): void
    /** stale 回復 (queued=10 分 / running=30 分の 2 閾値) */
    public function recoverStale(): int
}
```

**trigger()**（tx + VideoManual 行ロック。手順は概念設計 §4 の 1〜9）:

```php
$job = DB::transaction(function () use ($project, $manual): RenderJob {
    /** @var VideoManual $locked */
    $locked = $project->manuals()->whereKey($manual->id)->lockForUpdate()->firstOrFail();

    // 1. 状態 guard: ready のみ (published は編集で ready に戻してから = §10.8-6)
    if ($locked->status !== VideoManualStatus::Ready) {
        throw new RenderConflictException(RenderConflictType::StatusNotRenderable);
    }
    // 2. render 冪等: 同一 manual の in-flight kind=render は 1 つ (§10.8-8)
    if ($this->hasInFlight($locked, RenderKind::Render)) {
        throw new RenderConflictException(RenderConflictType::InFlight);
    }
    // 3. 採用テイク検証 (欠落 = 422。スキップしない: 標準化された成果物の完全性)
    $this->assertAllCutsHaveAdoptedReadyTakes($locked); // 欠落カットの表示ラベル一覧を message に
    // 4. 尺上限ソフトゲート (§10.8-1: TTL 内 commit)
    $this->assertTotalSourceDurationWithinLimit($locked); // NULL は default_take_duration_ms で代用
    // 5. 残高事前チェック (reserve はジョブ開始時 = §10.5)
    $cost = config()->integer('manual.render_ticket_cost');
    $balance = $this->tickets->balance($this->resolveOrganization($project));
    if ($balance < $cost) {
        throw InsufficientTicketsException::forReserve($cost, $balance);
    }
    // 6. job 作成 (kind/status/scenario_version は明示代入) + rendering へ
    $job = $locked->renderJobs()->make();
    $job->kind = RenderKind::Render;
    $job->status = JobStatus::Queued;
    $job->scenario_version = $locked->scenario_version; // §10.8-6 スナップショット
    $job->save();
    $locked->forceFill(['status' => VideoManualStatus::Rendering])->save();

    return $job;
});
RunManualRender::dispatch($job->id); // commit 後。喪失は recoverStale が回収
```

**triggerPreview()**（status 遷移なし・チケット検査なし）:

```php
return DB::transaction(function () use ($project, $manual): RenderJob {
    /** @var VideoManual $locked */
    $locked = $project->manuals()->whereKey($manual->id)->lockForUpdate()->firstOrFail();

    // 状態 guard: analyzing / rendering は 409 (cuts が動く最中)。draft は cuts 不在 = 422
    if (in_array($locked->status, [VideoManualStatus::Analyzing, VideoManualStatus::Rendering], true)) {
        throw new RenderConflictException(RenderConflictType::StatusNotRenderable);
    }
    if (! $locked->cuts()->exists()) {
        throw ValidationException::withMessages(['scenario' => ['シナリオがありません。']]);
    }
    // preview 冪等 (同一 manual)
    if ($this->hasInFlight($locked, RenderKind::Preview)) {
        throw new RenderConflictException(RenderConflictType::InFlight);
    }
    // org 上限: Organization 行ロックで直列化 (Round 2 Critical。reserve と同じ手法。
    // ロック順 video_manuals → organizations はグローバル順の部分列)
    $organization = $this->resolveOrganization($project);
    Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
    if ($this->orgInFlightPreviewCount($organization) >= config()->integer('manual.render_max_inflight_previews_per_org')) {
        throw new RenderConflictException(RenderConflictType::OrgPreviewLimit);
    }

    $job = $locked->renderJobs()->make();
    $job->kind = RenderKind::Preview;
    $job->status = JobStatus::Queued;
    $job->scenario_version = $locked->scenario_version;
    $job->save();

    return $job; // manual status は変更しない (編集と並走)
});
// dispatch は tx 外 (trigger と同じ)
```

`orgInFlightPreviewCount()` は org 配下の全 project → manuals → render_jobs を
whereHas で数える（`RenderJob::query()->whereIn('status', [...])->where('kind', preview)
->whereHas('videoManual.project', fn ($q) => $q->where('organization_id' ...))`。
Project→org の実カラムは既存 relation 定義に従い whereHas チェーンで表現。cross-org を
作らないため必ず relation 経由）。

**failJob()**（`AnalysisJobService::failJob` と同型・冪等）:

- render_jobs 行ロック → terminal は no-op（false）
- status=failed + error + error_code
- kind=Render のみ: manual 行ロック → `status === Rendering` のときのみ Ready へ復帰
  （render は ready からのみ始まるため cuts は必ず存在。preview は status を触らない）
- 予約 Reserved のみ release（LogicException 握って冪等）

**completeRenderIntoLockedManual()**（`materializeIntoLockedManual` と同じ
「ロック済み前提メソッド」二層防御）:

```php
/**
 * 前提: RenderPipeline::finalize の terminal tx 内・manual 行ロック済み。
 * 1. runtime 検査: tx 外呼び出し / rendering 以外は LogicException (terminal tx ごと rollback → failJob)
 * 2. 経路の構造的限定: 宣言は本クラスのみ・呼び出しは RenderPipeline のみ
 *    (ScenarioWritePathInventoryTest に materialize と同型の検出 5 を追加 = 施策 13)
 */
public function completeRenderIntoLockedManual(VideoManual $lockedManual, RenderResult $result): void
{
    if (DB::transactionLevel() === 0) { throw new LogicException(...); }
    if ($lockedManual->status !== VideoManualStatus::Rendering) { throw new LogicException(...); }

    foreach ($result->clipDurationsMs as $cutId => $ms) {
        // manifest 由来の cut id を relation 経由で再解決 (存在しない = 編集済みは起き得ない:
        // rendering 中は保存 409。防御的に whereKey→update のみ)
        $lockedManual->cuts()->whereKey($cutId)->update(['cut_length_ms' => $ms]);
    }
    $lockedManual->forceFill([
        'total_length_ms' => $result->totalDurationMs,
        'status' => VideoManualStatus::Published,
    ])->save();
}
```

**recoverStale()**: queued（created_at ≤ now−10 分）/ running（updated_at ≤ now−30 分）を
pluck → 各 failJob（`RenderErrorCode::Timeout`、文言「書き出しがタイムアウトしました。
再実行してください。」）。`AnalysisJobService::recoverStale` と同型。

### PHPStan 適合チェック

- [x] 戻り値型明示、`Assert::isInstanceOf`（org 導出）、`config()->integer()`
- [x] DTO（RenderResult）受け渡し。配列は `array<int, int>`（cutId→ms）で PHPDoc 固定

### テスト計画

- [ ] trigger: ready 以外 409（draft/analyzing/rendering/published 各遷移元）
- [ ] trigger: in-flight render 409 / preview の in-flight は妨げない
- [ ] trigger: 採用テイク欠落 422（NULL / ready でない take。欠落ラベルが message に含まれる）
- [ ] trigger: 尺上限超過 422 / 残高不足 402 / 成功で queued job + rendering + version スナップショット
- [ ] triggerPreview: analyzing/rendering 409・cuts なし 422・org 上限 409（逐次境界）
- [ ] **org 上限の直列化実証**（Round 4 反映）: 親が別 connection で Organization 行ロック保持 →
      子プロセス（Symfony Process 起動のテスト用 artisan command）で triggerPreview 開始（開始通知）→
      未完了確認 → commit → 子完了 + 解放後件数で判定。子側 `SET LOCAL lock_timeout` +
      テスト全体タイムアウトでハング防止
- [ ] failJob: 冪等（terminal no-op）・rendering→ready 復帰・preview は status 不変・
      Reserved release / released 済みは握る
- [ ] recoverStale: queued 10 分 / running 30 分の 2 閾値・error_code=timeout

### リスク

- Organization 行ロックは reserve と同じ行のため、preview トリガーとチケット reserve の
  並行時にロック待ちが発生する（数 ms オーダー。設計上許容）

---

## 施策 5: RenderManifest DTO 群

### 変更箇所

- 新規: `app/DataTransferObjects/Manual/Render/RenderManifest.php` / `RenderClipSpec.php` /
  `RenderClipSource.php`（enum）/ `RenderResult.php`

### 変更後コード（骨子）

```php
/** クリップ素材の種別 (compose 段の分岐を型で固定) */
enum RenderClipSource: string
{
    case TakeVideo = 'take_video';   // 採用テイク動画 (video cut)
    case TakeStill = 'take_still';   // 採用テイク先頭フレームの静止画化 (still cut)
    case Placeholder = 'placeholder';// preview 専用: 黒背景 + 字幕
}

final readonly class RenderClipSpec
{
    public function __construct(
        public int $cutId,
        public string $label,                 // 手順N / 急所N-M (派生。エラー表示・ログ用)
        public RenderClipSource $source,
        public ?string $takeVideoPath,        // S3 キー (Placeholder は null)
        public ?int $stillDisplaySeconds,     // TakeStill のみ
        public ?string $subtitlePrimary,
        public string $subtitleSecondary,
    ) {}
}

final readonly class RenderManifest
{
    /** @param list<RenderClipSpec> $clips */
    public function __construct(
        public int $renderJobId,
        public RenderKind $kind,
        public int $scenarioVersion,
        public string $outputKey,             // S3 出力キー (version 付き = 再実行安全)
        public array $clips,
    ) {}
}

final readonly class RenderResult
{
    /** @param array<int, int> $clipDurationsMs cutId => 実測尺 */
    public function __construct(
        public string $outputPath,            // S3 キー (アップロード済み)
        public array $clipDurationsMs,
        public int $totalDurationMs,
    ) {}
}
```

マニフェスト構築（RenderPipeline 内 = 施策 8）: cuts を表示順
（step: `parent_cut_id IS NULL` を sort_order 順 → 直後に配下 point を sort_order 順)で走査し、
`adoptedTake` を eager load して DTO 化。render では欠落は起き得ない（trigger 422 済み +
rendering 中は adopt/delete 409）が、**防御的に欠落検出時は例外 → failJob**。preview は
欠落 cut を `Placeholder` に落とす。

### PHPStan 適合チェック

- [x] readonly DTO・`list<RenderClipSpec>` / `array<int, int>` の generics 明示

### テスト計画

- [ ] 表示順（step → 配下 point）と label 派生（手順N/急所N-M）の Unit テスト

---

## 施策 6: AssSubtitleWriter（字幕の安全境界）

### 変更箇所

- 新規: `app/Services/Render/AssSubtitleWriter.php`

### 変更後コード（仕様）

```php
/**
 * カット 1 枚分の ASS 字幕ファイルを生成する (概念設計 §6 の安全境界)。
 * - 字幕本文は filtergraph に直接埋めない (このクラスが唯一の字幕テキスト出力点)
 * - 正規化 (writeDialogueText)。**順序が本質** (詳細レビュー Round 2: 先に改行を \N へ
 *   変換するとリテラル無効化がその \N まで潰す論理矛盾があったため、入力由来の
 *   バックスラッシュ無効化 → 改行変換の順に固定):
 *   1. 入力中のリテラル `\N` `\n` `\h` を無効化 (バックスラッシュを全角 `＼` に置換)
 *   2. 改行の正規化: CRLF / 単独 CR → LF
 *   3. 正規化済み LF → `\N` (ASS 改行。ここで生成した \N は以降の手順で触らない)
 *   4. `{` `}` → 全角 `｛` `｝` に置換 (ASS override tag {\...} 注入の無効化)
 *   5. 不可視制御文字の包括除去: C0/C1 制御文字 (LF は手順 3 で消費済み) + zero-width 系
 *      (U+200B-200F, U+202A-202E, U+2060-2064, U+FEFF) を除去
 *   6. 長さ上限: 1 行 (手順 3 で生成した \N での分割単位) 最大 100 文字・総文字数最大
 *      500 文字。切り詰めは mb_substr() 等のマルチバイト安全 API で行う (UTF-8 途中切断の
 *      禁止 = Round 3 Suggestion)。超過は切り詰め + 構造化ログ
 *      (subtitle_secondary は text 型のため防御必須)
 *   7. 出力ファイルは BOM なし UTF-8 固定 (BOM/エンコーディング検出に依存しない)
 * - スタイル: subtitle_secondary = 画面下部 (メイン)、subtitle_primary = 上部帯 (名称・数値)。
 *   フォントは config manual.render_subtitle_font
 */
class AssSubtitleWriter
{
    public function write(RenderClipSpec $clip, int $durationMs, string $filePath): void;
}
```

出力先 `filePath` は呼び出し元（FfmpegVideoComposer）が管理する一時 dir 配下の
`clip{n}.ass`（英数字のみ = filtergraph メタ文字を含まない）。

### テスト計画

- [ ] Unit: 攻撃的入力（`'` `:` `,` 改行・CR・`{\an8}`・リテラル `\N`・制御文字・日本語混在）で
      生成ファイルに override tag / 生制御綴りが現れない（入力と生成ファイル双方を検証）
- [ ] Unit: **実改行とリテラル `\N` が同一入力に共存**するケースで、実改行だけが
      ASS 改行（`\N`）として残る（正規化順の固定 = Round 2 Critical）
- [ ] Unit: zero-width 文字（U+200B / U+202E / U+FEFF）の除去・極端な長文の切り詰め +
      ログ・出力が BOM なし UTF-8
- [ ] Unit: 通常の日本語字幕が Dialogue 行に保持される

---

## 施策 7: VideoComposer 抽象 + FfmpegVideoComposer + RenderObjectStorage

### 変更箇所

- 新規: `app/Services/Render/VideoComposer.php`（interface）
- 新規: `app/Services/Render/FfmpegVideoComposer.php`
- 新規: `app/Services/Render/RenderObjectStorage.php`
- 変更: `app/Providers/AppServiceProvider.php`（`VideoComposer::class → FfmpegVideoComposer::class` bind）

### 変更後コード（骨子）

```php
interface VideoComposer
{
    /**
     * マニフェストのクリップ群を合成し、ローカル最終 mp4 を返す。
     * @param array<int, string> $localSources cutId => ローカル素材パス (Placeholder cut は不在)
     * @param callable(int $composedClips, int $totalClips): void $onClipComposed 進捗通知
     */
    public function compose(RenderManifest $manifest, array $localSources, string $workDir, callable $onClipComposed): ComposedLocalVideo;
}

/** @property string $localPath / @property array<int,int> $clipDurationsMs / @property int $totalDurationMs */
final readonly class ComposedLocalVideo { ... } // DataTransferObjects/Manual/Render/
```

`FfmpegVideoComposer`（`Illuminate\Support\Facades\Process` 経由。テストは `Process::fake()`）:

1. クリップごと: `AssSubtitleWriter` で `clip{n}.ass` 生成 → ffmpeg 実行（配列引数・
   `->timeout()` 個別設定・失敗は `RenderCompositionException`）:
   - TakeVideo: `-i {src}` + `-vf scale={W}:{H}:force_original_aspect_ratio=decrease,
     pad={W}:{H}:(ow-iw)/2:(oh-ih)/2,fps={fps},subtitles={ass},format=yuv420p`
     + `-c:v libx264 -preset veryfast -crf 23 -c:a aac -ar 48000 -ac 2`
     + 音声トラック欠落対策 `-f lavfi -i anullsrc=r=48000:cl=stereo` を第 2 入力 +
     `-map 0:v -map "0:a?"` 系の分岐（実装時に音声有無を ffprobe で判定して map を確定）
   - TakeStill: ffmpeg で先頭フレーム抽出（`-frames:v 1`）→ `-loop 1 -t {sec} -i frame.png` +
     anullsrc + 同フィルタ列
   - Placeholder（preview のみ）: `-f lavfi -i color=black:s={W}x{H}:d={sec}` + anullsrc + subtitles
2. `ffprobe` で各クリップ実測尺（`-show_entries format=duration`）→ clipDurationsMs
3. concat demuxer（`list.txt` は work dir 内の連番ファイル名のみ）→ `-c copy` で最終 mp4
4. filtergraph に入るのは **サーバ生成の一時パスと数値のみ**（字幕本文・ユーザー由来文字列は
   一切入らない = 施策 6 の境界）

`RenderObjectStorage`（S3 入出力。`TakeObjectStorage` と同じく `Storage::disk('s3')`）:

```php
class RenderObjectStorage
{
    public function downloadToLocal(string $key, string $localPath): void; // readStream → 一時ファイル
    public function upload(string $localPath, string $key): void;         // putFileAs / writeStream
    public function temporaryPlaybackUrl(string $key): string;            // TTL config
    /**
     * DL 用署名 URL (attachment disposition)。契約 (詳細レビュー Round 1 で明文化):
     * - filename は CR/LF・制御文字を除去し、Content-Disposition は
     *   RFC 5987 (filename*=UTF-8''...) + ASCII fallback (filename="...") の両建てで署名に含める
     * - ヘッダ注入 (改行) 不能であることを Unit テストで固定
     */
    public function temporaryDownloadUrl(string $key, string $filename): string;
    public function delete(string $key): void;
    public function keyPrefixFor(VideoManual $manual): string; // projects/{p}/manuals/{m}/
}
```

出力キー: `projects/{projectId}/manuals/{manualId}/renders/v{version}-{jobId}.mp4`
（preview は `previews/`）。

### PHPStan 適合チェック

- [x] interface + DTO 戻り値（配列返却なし）、callable 型 PHPDoc
- [x] `Process::command(array)` の配列引数（シェル連結なし）

### テスト計画

- [ ] Unit（`Process::fake()`）: 生成コマンドに字幕本文が現れない / subtitles= の値が
      workdir 配下の .ass パスのみ / 解像度・fps が config 値
- [ ] Unit: temporaryDownloadUrl の filename 契約（CR/LF 除去・RFC 5987 + ASCII fallback・
      ヘッダ注入不能）
- [ ] Unit: ffprobe 出力（fake）から clipDurationsMs / total が正しく導出
- [ ] Unit: ffmpeg 非 0 終了で `RenderCompositionException`
- [ ] Feature 側は `VideoComposer` を fake 実装に差し替え（container swap）て Process 非依存

### リスク

- 実 ffmpeg でのフィルタ列・音声 map の最終確認は実装時（Process::fake の Unit では
  コマンド構造のみ検証。実機検証は staging worker で行う運用項目として docs に明記）

---

## 施策 8: RenderPipeline + RunManualRender

### 変更箇所

- 新規: `app/Services/Manual/RenderPipeline.php`（`AnalysisPipeline` を見本に個別実装）
- 新規: `app/Jobs/Manual/RunManualRender.php`

### 変更後コード（骨子）

`RunManualRender`（`RunManualAnalysis` と同型）:

```php
class RunManualRender implements ShouldQueue
{
    public int $tries = 1;        // §10.8-1
    public int $timeout = 1500;   // 25 分 < retry_after 1680 < TTL 1800 (施策 2 の invariant)

    public function __construct(public readonly int $renderJobId)
    {
        $this->onConnection('database-render');
    }

    public function handle(RenderPipeline $pipeline): void { $pipeline->run($this->renderJobId); }

    /**
     * catch を通らない失敗の最終防衛線。error_code は既定 Internal とし、
     * timeout と判別できる場合のみ Timeout（詳細レビュー Round 1: deploy 中断・worker 停止を
     * Timeout に誤分類すると運用判断を誤る）。
     */
    public function failed(?Throwable $exception): void
    {
        $job = RenderJob::query()->find($this->renderJobId);
        if ($job === null) {
            return;
        }
        $code = $exception instanceof \Illuminate\Queue\TimeoutExceededException
            ? RenderErrorCode::Timeout
            : RenderErrorCode::Internal;
        app(RenderJobService::class)->failJob($job, $code,
            '書き出しが中断されました。再実行してください。');
    }
}
```

`RenderPipeline::run(int $renderJobId)`:

```
1. startJob tx { render_jobs 行ロック。status !== queued → no-op return (重複配送 guard)
     kind=render のみ ensureReservation(job, org):   // AnalysisPipeline と同一手順 §10.8-1
       有効 Reserved は再利用 / 失効 Reserved は release して付け替え / なしは新規 reserve
       (残高不足 InsufficientTicketsException → catch → failJob)
     status=running, step=compose, progress=5 }
2. buildManifest tx { manual 行ロック ($project->manuals() 経由再解決)
     guard: manual.scenario_version === job.scenario_version
       (不一致 → RenderScenarioChangedException → failJob(error_code=scenario_version_changed))
     preview 追加 guard: status が analyzing/rendering に遷移していたら同例外
       (トリガー後に AI 解析や render が始まった場合の防御)
     cuts + adoptedTake を表示順ロード → RenderManifest 化 (render で採用欠落検出は
       防御例外 → failJob) }
3. compose (DB 外・ロック外): TemporaryDirectory (笑わない一時 dir: storage/app/render/{jobId})
     RenderObjectStorage::downloadToLocal で素材取得 (cutId => local path)
     VideoComposer::compose(manifest, sources, workDir, onClipComposed: progress 5→80 更新
       + step=concat 遷移は composer 完了後 progress=90)
4. upload: RenderObjectStorage::upload(local, manifest->outputKey)
5. finalize tx {
     render_jobs 行ロック → guard: status === running (stale 回復先勝ちは何もしない =
       無課金 succeeded / 課金済み failed の排除。アップロード済み出力は catch 側と同じ
       後始末で削除)
     manual 行ロック ($project->manuals() 経由)
     kind=render のみ:
       guard: manual.status === rendering && version 一致 (防御的再検証。違反 → LogicException
         → 全体 rollback → failJob)
       RenderJobService::completeRenderIntoLockedManual($lockedManual, $result)
       TicketLedgerService::commit($reservation) // 非 Reserved は LogicException → rollback → failJob
     job: status=succeeded, progress=100, output_path=outputKey
     旧世代 (同 manual・同 kind・output_path 非 NULL・id < 自分の succeeded) があれば
       DeleteRenderOutputsJob::dispatch(旧 job id) // afterCommit 相当: tx 後に dispatch
   }
X. catch (Throwable): report + failJob(job, errorCodeFor(e), userMessageFor(e))
   finally: 一時 dir 掃除。アップロード済みで succeeded 未達なら outputKey をベストエフォート削除
```

補足:
- 旧世代削除 job の dispatch は **finalize tx の commit 後**（`DB::afterCommit` or tx 外で
  実行）。dispatch 喪失は `render:reconcile-outputs` が回収（施策 9）
- `errorCodeFor()`: RenderScenarioChangedException → ScenarioVersionChanged /
  それ以外 → Internal（timeout は failed() フックが Timeout を付与）
- progress 更新は AnalysisPipeline::updateProgress と同じ tx 不要の単発 save
  （updated_at が stale 判定を兼ねる）

### PHPStan 適合チェック

- [x] payload は int のみ・relation 再解決（payload 不信任）
- [x] 例外型ごとの error_code マッピングを match で網羅

### テスト計画（`VideoComposer` fake + `Storage::fake('s3')` + `Queue::fake()`）

- [ ] 成功系: ready→rendering→published、output_path 記録、cut_length_ms/total_length_ms 反映、
      commit 済み（台帳負 delta）、progress=100
- [ ] **version 固定**: preview トリガー後に scenario 保存 → buildManifest で
      failJob(error_code=scenario_version_changed)。render 中の scenario 保存/adopt/delete は
      409（既存 guard との整合を Feature で再確認）
- [ ] チケット 2 フェーズ: 再配送で二重予約しない（既存 Reserved 再利用）/ TTL 失効 Reserved の
      release + 付け替え / compose 例外で release / commit は Reserved のみ（非 Reserved →
      rollback + failed）/ **preview は予約・台帳が一切動かない**
- [ ] stale 先勝ち: recoverStale が failed 化した後に pipeline が完走しても
      succeeded/commit されない（terminal tx guard）
- [ ] 失敗時: status=failed + error/error_code、manual rendering→ready、
      アップロード済み出力が S3 に残らない
- [ ] 世代交代: 再レンダ成功で旧 job id の DeleteRenderOutputsJob が dispatch される

---

## 施策 9: DeleteRenderOutputsJob + console 2 command

### 変更箇所

- 新規: `app/Jobs/Manual/DeleteRenderOutputsJob.php`
- 変更: `routes/console.php`（`render:recover-stale-jobs` / `render:reconcile-outputs` 追加）
- 新規（Service 内）: `RenderJobService::reconcileOutputs(): int`

### 変更後コード（骨子）

```php
/**
 * 世代交代した render 出力の S3 削除 (payload は job id のみ = 任意キー削除の権限を持たない)。
 * media queue。冪等: output_path NULL / 最新 succeeded 自身 / prefix 不一致は no-op。
 */
class DeleteRenderOutputsJob implements ShouldQueue
{
    public int $tries = 3;
    /** @var list<int> */
    public array $backoff = [60, 180];

    public function __construct(public readonly int $renderJobId)
    {
        $this->onConnection('database-media');
    }

    /**
     * 外部 I/O (S3) は tx 外 (詳細レビュー Round 2: 行ロック保持のまま S3 を呼ぶと
     * ロック/接続を長時間保持する)。検証 tx → S3 削除 → CAS NULL 化 tx の 3 段。
     */
    public function handle(RenderObjectStorage $storage): void
    {
        // 段 1: 短い検証 tx (削除対象・最新世代・prefix)。ロックは取らず読むだけで良い
        // (判定が古くなっても段 3 の CAS が守る)
        $job = RenderJob::query()->find($this->renderJobId);
        if ($job === null || $job->output_path === null) { return; }        // 冪等
        if ($this->isLatestSucceededOfKind($job)) { return; }               // 世代交代済みのみ削除
        $manual = $job->videoManual;
        if ($manual === null || ! str_starts_with($job->output_path, $storage->keyPrefixFor($manual))) {
            Log::warning('render output prefix mismatch', ['render_job_id' => $job->id, 'output_path' => $job->output_path]);
            return; // 期待 prefix 外は削除しない (過大削除の防止)
        }
        $pathToDelete = $job->output_path;

        // 段 2: S3 削除 (tx 外。存在しないキーは no-op = 冪等)
        $storage->delete($pathToDelete);

        // 段 3: CAS で NULL 化 (検証時の値と一致する行のみ。最新世代を誤って NULL 化しない。
        // ここで失敗/クラッシュしても再実行・reconcile が段 1-2 の冪等性で収束させる)
        RenderJob::query()->whereKey($job->id)
            ->where('output_path', $pathToDelete)
            ->update(['output_path' => null]);
    }
}
```

console（既存 command 群と同型のクロージャ command + 5 分毎 schedule）:

```php
Artisan::command('render:recover-stale-jobs', function (RenderJobService $jobs) { ... });
Schedule::command('render:recover-stale-jobs')->everyFiveMinutes();

// 出力世代の収束 (reconciliation)。stale 回復とは別責務 (概念設計 Round 4)
Artisan::command('render:reconcile-outputs', function (RenderJobService $jobs) { ... });
Schedule::command('render:reconcile-outputs')->everyFiveMinutes()->onOneServer()->withoutOverlapping();
```

`reconcileOutputs()`: kind ごとに「output_path 非 NULL かつ 同 manual・同 kind により新しい
succeeded job が存在する」render_jobs を走査し `DeleteRenderOutputsJob::dispatch(id)` を再投入
（冪等のため重複無害）。**可観測性（詳細レビュー Round 1 反映）**: 戻り値は
`{dispatched: int, skipped: int}` 相当の集計 DTO（または command 出力に
`dispatched/skipped` を info 出力）とし、`DeleteRenderOutputsJob` 側の prefix 不一致
report は構造化ログ（`Log::warning` + context: render_job_id / output_path）で出す =
再投入が継続する異常（削除が進まない）を件数推移で検知できる。

### テスト計画

- [ ] handle: 旧世代のみ削除 + output_path NULL 化 / 最新 succeeded は no-op /
      NULL 済み no-op / prefix 不一致は削除せず構造化ログ
- [ ] handle: **S3 削除中に DB トランザクション/行ロックを保持しない**（段 2 実行時点で
      transactionLevel=0 を fake storage 内で検証）
- [ ] handle: S3 削除後の CAS 不一致（output_path が検証時と異なる）で NULL 化しない /
      段 3 前のクラッシュ相当から再実行で収束（冪等）
- [ ] reconcileOutputs: 取り残し検出で dispatch（Queue::fake）+ dispatched/skipped 集計
- [ ] recover-stale command: queued 10 分 / running 30 分（施策 4 と重複しない command 層の smoke）

---

## 施策 10: routes + Controller + FormRequest + rate limiter

### 変更箇所

- 変更: `routes/web.php`（manuals の scopeBindings グループに 5 route 追加）
- 新規: `app/Http/Controllers/Projects/ManualRenderController.php`（store / preview / show / playback）
- 新規: `app/Http/Controllers/Projects/ManualDownloadController.php`（show）
- 新規: `app/Http/Requests/Projects/TriggerRenderRequest.php`（`AnalyzeManualRequest` と同型:
  入力なし + `protectedKeyMissingRules()`。render / preview 共用）
- 新規: `app/Http/Requests/Projects/DownloadManualRequest.php`（`lang => ['sometimes', 'string', 'in:ja']` +
  protected keys）
- 変更: `app/Providers/AppServiceProvider.php`（`RateLimiter::for('render-trigger', ...)`）

### 現行コード（routes/web.php L379-384 付近・追加位置）

```php
            Route::post('/projects/{project}/manuals/{manual}/analyze', [ManualAnalysisController::class, 'store'])
                ->name('projects.manuals.analyze');
            Route::get('/projects/{project}/manuals/{manual}/jobs/{analysisJob}', [ManualAnalysisController::class, 'show'])
                ->name('projects.manuals.jobs.show');
```

### 変更後コード（同グループ内に追記）

```php
            // レンダ / プレビュー (チケット消費は render のみ。同一オリジン XHR/JSON。§10.3, §10.8)
            Route::post('/projects/{project}/manuals/{manual}/render', [ManualRenderController::class, 'store'])
                ->middleware('throttle:render-trigger')
                ->name('projects.manuals.render');
            Route::post('/projects/{project}/manuals/{manual}/preview', [ManualRenderController::class, 'preview'])
                ->middleware('throttle:render-trigger')
                ->name('projects.manuals.preview');
            // レンダ job ポーリング (進捗のみ。成果物 URL は含めない = 権限分離)
            Route::get('/projects/{project}/manuals/{manual}/render-jobs/{renderJob}', [ManualRenderController::class, 'show'])
                ->name('projects.manuals.render-jobs.show');
            // preview 再生 (render ability。最新 succeeded preview のみ 302)
            Route::get('/projects/{project}/manuals/{manual}/render-jobs/{renderJob}/playback', [ManualRenderController::class, 'playback'])
                ->name('projects.manuals.render-jobs.playback');
            // 完成 mp4 ダウンロード (download ability。published + 最新 succeeded render のみ)
            Route::get('/projects/{project}/manuals/{manual}/download', [ManualDownloadController::class, 'show'])
                ->name('projects.manuals.download');
```

`ManualRenderController`（`ManualAnalysisController` と同型の 2 層 guard:
`resolveOrganizationProject` inline guard + scopeBindings）:

```php
public function store(TriggerRenderRequest $request, Project $project, VideoManual $manual, RenderJobService $render): JsonResponse
{
    $organization = $this->resolveCurrentOrganization($request);
    $this->resolveOrganizationProject($organization, $project); // 認可より前に 404
    Gate::authorize('render', $manual);
    $job = $render->trigger($project, $manual);
    $manual->refresh();
    return RenderJobResource::make(RenderJobData::fromJob($job, $manual))
        ->response($request)->setStatusCode(201);
}

public function preview(...): JsonResponse // 同型。$render->triggerPreview()

public function show(Request $request, Project $project, VideoManual $manual, RenderJob $renderJob): RenderJobResource
{
    // inline 再検査は AnalysisJob show と同じ二重防御
    if ($renderJob->video_manual_id !== $manual->id) { abort(404); }
    Gate::authorize('view', $manual);
    return RenderJobResource::make(RenderJobData::fromJob($renderJob, $manual));
}

public function playback(Request $request, Project $project, VideoManual $manual, RenderJob $renderJob, RenderObjectStorage $storage): RedirectResponse
{
    if ($renderJob->video_manual_id !== $manual->id) { abort(404); }
    Gate::authorize('render', $manual); // preview は編集者専用
    // 404 条件: kind!=preview / succeeded でない / output_path NULL / 最新 succeeded でない
    if ($renderJob->kind !== RenderKind::Preview || $renderJob->status !== JobStatus::Succeeded
        || $renderJob->output_path === null || ! $this->isLatestSucceededPreview($manual, $renderJob)) {
        abort(404);
    }
    return redirect()->away($storage->temporaryPlaybackUrl($renderJob->output_path));
}
```

`ManualDownloadController::show`（`download` ability。`lang` は DownloadManualRequest で検証）:

```php
$organization = $this->resolveCurrentOrganization($request);
$this->resolveOrganizationProject($organization, $project);
Gate::authorize('download', $manual);
if ($manual->status !== VideoManualStatus::Published) { abort(404); }
$job = $manual->renderJobs()->where('kind', RenderKind::Render->value)
    ->where('status', JobStatus::Succeeded->value)->whereNotNull('output_path')
    ->latest('id')->first();
if ($job === null || $job->output_path === null) { abort(404); }
$filename = $manual->title.'.mp4'; // sanitize (ASCII fallback + RFC 5987) は Storage 側 helper
return redirect()->away($storage->temporaryDownloadUrl($job->output_path, $filename));
```

rate limiter（AppServiceProvider::configureRateLimiting に追記）:

```php
// レンダ/プレビュートリガー (無料 preview の abuse 上限。概念設計 §2)
RateLimiter::for('render-trigger', function (Request $request): Limit {
    $userId = $request->user()?->id ?? 'guest';
    $orgId = $request->session()->get('current_organization_id', 'none');
    return Limit::perMinute(6)->by("render-trigger:{$userId}:{$orgId}");
});
```

### 波及変更

- `NestedRouteIdorDefenseTest` inventory（施策 13）
- TS の route 呼び出し（施策 14）

### PHPStan 適合チェック

- [x] JsonResponse / RedirectResponse / JsonResource の戻り値型明示
- [x] `response()->json()` 直書きなし（Resource + `->response()` / redirect のみ）

### テスト計画

- [ ] 5 route の cross-org / cross-project / cross-manual 404（認可より前）
- [ ] 保護キー直送 422（TriggerRenderRequest）
- [ ] lang=en 422 / lang=ja 302 / lang 省略 302
- [ ] throttle 429（7 回目）
- [ ] playback の 404 マトリクス（kind=render / running / output_path NULL / 旧世代）

---

## 施策 11: Policy（render / download）

### 変更箇所

- 変更: `app/Policies/VideoManualPolicy.php`（`analyze` の下に追加）

### 変更後コード

```php
/** レンダ/プレビューの実行: プロジェクトを操作できる人 (編集者)。撮影者は不可 (§10.5) */
public function render(User $user, VideoManual $manual): bool
{
    $project = $manual->project;
    return $project !== null && $this->projectPolicy->update($user, $project);
}

/** 完成動画のダウンロード: 編集者のみ (§10.5) */
public function download(User $user, VideoManual $manual): bool
{
    $project = $manual->project;
    return $project !== null && $this->projectPolicy->update($user, $project);
}
```

### テスト計画

- [ ] 撮影者（project_member）: render/preview/download/playback 403、ポーリング 200
- [ ] 編集者（project_admin / org 管理者）: 全て許可

---

## 施策 12: RenderJobData DTO + RenderJobResource + TS 型 + enum 同期テスト

### 変更箇所

- 新規: `app/DataTransferObjects/Manual/RenderJobData.php` / `app/Http/Resources/Manual/RenderJobResource.php`
- 変更: `resources/js/types/manual.ts`
- 新規: `tests/Architecture/ManualEnumTsSyncInvariantTest.php`

### 変更後コード（骨子）

`RenderJobData`（`AnalysisJobData` と同型 + kind/error_code。**成果物 URL は持たない**）:

```php
final readonly class RenderJobData
{
    public function __construct(
        public int $id,
        public RenderKind $kind,
        public JobStatus $status,
        public ?RenderStep $step,
        public ?int $progress,
        public ?string $error,
        public ?RenderErrorCode $errorCode,
        public VideoManualStatus $manualStatus,
    ) {}
    public static function fromJob(RenderJob $job, VideoManual $manual): self { ... }
    /** @return array{id:int, kind:string, status:string, step:string|null, progress:int|null,
     *   error:string|null, error_code:string|null, manual_status:string} */
    public function toArray(): array { ... }
}
```

`resources/js/types/manual.ts` 追記:

```ts
/** PHP: App\Enums\Manual\RenderKind / RenderStep / RenderErrorCode と対 (値集合同期テストあり) */
export type RenderKind = "render" | "preview";
export type RenderStep = "compose" | "concat";
export type RenderErrorCode = "scenario_version_changed" | "timeout" | "internal";

export const RENDER_STEP_LABELS: Record<RenderStep, string> = {
    compose: "カットを合成中",
    concat: "動画を連結中",
};

/** PHP: RenderJobData::toArray() と対 (show props / ポーリング / トリガー 201 の共通 shape) */
export interface RenderJobProps {
    id: number;
    kind: RenderKind;
    status: AnalysisJobStatus; // JobStatus 共用 (queued|running|succeeded|failed)
    step: RenderStep | null;
    progress: number | null;
    error: string | null;
    error_code: RenderErrorCode | null;
    manual_status: VideoManualStatus;
}

/** PHP: App\Enums\Manual\RenderConflictType と対 */
export type RenderConflictType =
    | "in_flight"
    | "status_not_renderable"
    | "status_not_previewable"
    | "org_preview_limit";
export interface RenderConflictBody {
    code: "render_conflict";
    conflict_type: RenderConflictType;
    message: string;
}

/** PHP: VideoManualController::show の render props と対 */
export interface RenderProps {
    job: RenderJobProps | null;        // 最新 kind=render
    previewJob: RenderJobProps | null; // 最新 kind=preview
    playbackJobId: number | null;      // 再生可能な最新 succeeded preview (無ければ null)
}
```

`ManualEnumTsSyncInvariantTest`（概念設計 Round 3 反映。types/manual.ts を読み、
`RenderKind` / `RenderStep` / `RenderErrorCode` / `RenderConflictType` に加えて
**`AnalysisJobStatus`（= JobStatus 共用）** の TS literal union と PHP enum の値集合一致を
正規表現抽出で検証。乖離・抽出不能（degenerate PASS）で fail）。

### テスト計画

- [ ] 同期テスト本体 + degenerate PASS 防止（union が抽出できない場合は fail）

---

## 施策 13: Architecture テスト登録

### 変更箇所

- 変更: `tests/Architecture/NestedRouteIdorDefenseTest.php`（inventory に 5 route 追加）
- 変更: `tests/Architecture/ScenarioWritePathInventoryTest.php`（allowlist + 検出 5 追加）

### 変更後コード（骨子）

NestedRouteIdorDefenseTest（`$s = ScopeBindings`）:

```php
        // レンダ/プレビュー/ポーリング/再生/DL ({manual} は $project->manuals()、
        // {renderJob} は $manual->renderJobs() 経由。§10.3)
        'projects.manuals.render' => $s,
        'projects.manuals.preview' => $s,
        'projects.manuals.render-jobs.show' => $s,
        'projects.manuals.render-jobs.playback' => $s,
        'projects.manuals.download' => $s,
```

ScenarioWritePathInventoryTest:

- `SCENARIO_VERSION_ALLOWED` += `Services/Manual/RenderJobService.php`（trigger の
  スナップショット読み）, `Services/Manual/RenderPipeline.php`（buildManifest/finalize の
  guard 読み）, `Models/RenderJob.php`（casts の配列キー = 書き込みでない）
- `STATUS_WRITE_ALLOWED` += `Services/Manual/RenderJobService.php`
  （trigger: ready→rendering / failJob: rendering→ready / complete...: rendering→published。
  **RenderPipeline は VideoManualStatus を直接書かない** = 全て Service メソッド経由）
- **検出 5（新設・materialize 検出 3 と同型）**: `completeRenderIntoLockedManual` の
  宣言は `Services/Manual/RenderJobService.php` のみ、呼び出しは
  `Services/Manual/RenderPipeline.php` のみ
- degenerate PASS 防止テストに検出 5 の実在検証を追加
- 経路 inventory コメント表に 3 経路を追記（docs/architecture.md と対）

### テスト計画

- [ ] scanner 自己検証（検出 5 の宣言/呼び出しパターン）+ 全体 green

---

## 施策 14: フロント RenderPanel + Show props

### 変更箇所

- 変更: `app/Http/Controllers/Projects/VideoManualController.php`（show の props に `render` 追加）
- 新規: `resources/js/components/features/manual/RenderPanel.svelte`
- 変更: `resources/js/pages/Manuals/Show.svelte`（RenderPanel 組み込み + Props 型）
- 新規: `tests/js/components/features/manual/RenderPanel.test.ts`（Vitest）

### 現行コード（VideoManualController::show L114-121）

```php
            'analysis' => [
                'job' => ($latest = $manual->analysisJobs()->latest('id')->first()) === null
                    ? null
                    : AnalysisJobData::fromJob($latest, $manual)->toArray(),
                'hasDocument' => $manual->sourceDocuments()->exists(),
            ],
            'canManage' => $user->can('update', $manual),
```

### 変更後コード

```php
            'analysis' => [ /* 既存のまま */ ],
            // レンダパネル (最新 render job / 最新 preview job / 再生可能 preview)。RenderProps と対
            'render' => [
                'job' => ($render = $manual->renderJobs()->where('kind', RenderKind::Render->value)->latest('id')->first()) === null
                    ? null : RenderJobData::fromJob($render, $manual)->toArray(),
                'previewJob' => ($preview = $manual->renderJobs()->where('kind', RenderKind::Preview->value)->latest('id')->first()) === null
                    ? null : RenderJobData::fromJob($preview, $manual)->toArray(),
                'playbackJobId' => $manual->renderJobs()->where('kind', RenderKind::Preview->value)
                    ->where('status', JobStatus::Succeeded->value)->whereNotNull('output_path')
                    ->latest('id')->value('id'),
            ],
            'canManage' => $user->can('update', $manual),
```

`RenderPanel.svelte`（`AnalysisPanel.svelte` の polling パターンを踏襲。Props:
projectId / manualId / manualStatus / job / previewJob / playbackJobId / canManage）:

- **ready + canManage**: 「完成動画を生成」（`Clapperboard` icon）+「プレビュー生成」（`Play`）
  ボタン。**disabled にしない** — 押下時に 422/402/409 のサーバメッセージを表示
  （code 厳格一致: `render_conflict` / `insufficient_tickets` / バリデーション 422 の
  `errors.takes` など）
- **rendering（または render job が queued/running）**: step ラベル
  （RENDER_STEP_LABELS）+ progress bar。`GET .../render-jobs/{id}` を 2.5 秒間隔で
  ポーリング（AnalysisPanel と同じ $effect + visibilitychange + 401/419 停止処理）。
  succeeded → `router.reload()`、failed → error 表示 + 「再実行」
- **ポーリングは 1 コンポーネント内で scheduler 1 本**（詳細レビュー Round 1 反映:
  render/preview を別タイマーで追うと `router.reload()` の衝突でチラつく）。単一の
  `$effect` + `setInterval` が「追跡中の job id 集合（render job / preview job）」を保持し、
  周期ごとに追跡中 job を順に fetch。終端条件のみ kind 別分岐
  （render: succeeded → `router.reload()` / preview: succeeded → `<video>` 表示）。
  reload は render 側終端でのみ発火（preview 終端は local state 更新のみ）
- **preview job の追跡**: preview は manual_status が変わらないため job.status のみで
  終端判定。succeeded → `<video>` を `playback` route URL で表示。
  failed + `error_code === "scenario_version_changed"` → 「シナリオが変更されています」+
  「作り直す」CTA（preview 再 POST）
- **published + canManage**: `Download` icon リンク（`projects.manuals.download` へ通常遷移）
- **published 以外で完成動画が過去にあった場合**（job=succeeded だが manual=ready）:
  「シナリオが編集されています。最新の内容で再生成してください」案内
- ポーリング実装は AnalysisPanel からの共通化はしない（依存 shape が異なる。
  将来 3 つ目のポーリングが必要になったら hooks へ抽出 = 過剰抽象の回避）

Vitest: 状態別レンダリング（ready/rendering/published/failed + error_code CTA）、
ポーリング応答での進捗更新、402/409 押下時エラー表示。

### テスト計画

- [ ] Vitest 上記 + `pnpm typecheck`（RenderProps 整合）

### リスク

- Show 画面の props 追加は既存 `Props` interface 変更 → typecheck が波及検出

---

## 施策 15: Feature/Unit テスト一式（配置）

| ファイル | 検証対象 |
|---|---|
| `tests/Feature/Manual/RenderTriggerTest.php` | trigger/triggerPreview の全 guard（409/422/402）、冪等、version スナップショット、rendering 遷移、throttle 429 |
| `tests/Feature/Manual/RenderPipelineTest.php` | 成功系 E2E（fake composer）、チケット 2 フェーズ全系、version 固定、stale 競合、失敗復帰、出力後始末 |
| `tests/Feature/Manual/RenderPreviewConcurrencyTest.php` | org 上限逐次境界 + subprocess 直列化実証（pgsql 専用・`RUN_CONCURRENCY_TESTS` guard 付き） |
| `tests/Feature/Manual/RenderPollingAndArtifactAccessTest.php` | show/playback/download の権限・404 マトリクス・署名 URL redirect・応答に URL/パス非含有 |
| `tests/Feature/Manual/RenderRetentionTest.php` | DeleteRenderOutputsJob（no-op 系含む）、reconcileOutputs、世代収束 |
| `tests/Feature/Manual/RenderStaleRecoveryTest.php` | recoverStale 2 閾値、error_code=timeout、command smoke |
| `tests/Unit/Render/AssSubtitleWriterTest.php` | 攻撃的入力の正規化（入力・生成ファイル双方） |
| `tests/Unit/Render/FfmpegVideoComposerTest.php` | Process::fake でコマンド構造・字幕本文非含有・尺導出・失敗例外 |
| `tests/Architecture/RenderTimeBudgetInvariantTest.php` | 時間 budget 連鎖 |
| `tests/Architecture/ManualEnumTsSyncInvariantTest.php` | PHP enum ⇔ TS union 同期 |
| `tests/js/components/features/manual/RenderPanel.test.ts` | パネル状態遷移・CTA |

原則: 実 ffmpeg・実 S3・実キュー・実 LLM に触れない
（`Process::fake` / `Storage::fake('s3')` / `Queue::fake` / container swap の fake VideoComposer）。
`RefreshDatabase` はグローバル適用のまま（個別 DatabaseTransactions 禁止）。

---

## 施策 16: ドキュメント更新

| ファイル | 更新内容 |
|---|---|
| `doc/10_実装仕様.md` | §10.1 render_jobs 列（kind / scenario_version / error_code 追加・result_json は analysis のみ）、§10.3 ポーリング URI を `render-jobs/{renderJob}` + playback route 追記、§10.5 COST_RENDER=3 の config 名 |
| `docs/architecture.md` | シナリオ整合 inventory 表に RenderJobService 3 経路追加、レンダジョブの運用契約（database-render worker 必須登録・時間 budget・recover/reconcile cron・ffmpeg バイナリ要件・実機検証項目）、media queue の DeleteRenderOutputsJob 追記 |
| `docs/factories.md` | RenderJobFactory 追記 |
| `.env.example` | RENDER_FFMPEG_BINARY / RENDER_FFPROBE_BINARY / RENDER_SUBTITLE_FONT |

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone（単一 worktree で施策 1→16 を順に実装） |
| 判断根拠 | 施策間の依存が深い（Service → Pipeline → Controller → Front）。テーブル・enum・route が同一フィーチャの垂直スライスで、分割すると中間状態が壊れる |
| 競合リスク | `routes/web.php` / `VideoManualPolicy` / `VideoManualController::show` / `config/manual.php` / `types/manual.ts` / `ScenarioWritePathInventoryTest` は他フィーチャと共有。実装順を P0 → P1 → P2 とし、共有ファイル変更は各施策内で最小差分に留める |
| 実装内段階 | (1) 状態機械 + 課金閉塞（施策 1-4, 9 の recover）→ (2) 合成パイプライン（5-8）→ (3) API/UI（10-14）→ (4) 仕上げ（15-16）。各段階で `composer test` green |
