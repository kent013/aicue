【アプリの使命（North Star）— AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【セキュリティ不変条件（アプリ都合で緩めない）— AGENTS.md より】

1. tenant キー不信: ownership/actor/tenant キーを payload から受け取らない（ProhibitsProtectedKeys + MassAssignmentSafetyTest）
2. 子は親に属する: nested route の不整合は認可より前に 404（NestedRouteIdorDefenseTest inventory 登録必須）
3. cross-org 不可: 組織を跨ぐ read/write をしない（relation / org-scoped 解決経由のみ）
4. untrusted 文字列は UserInput 型経由でのみ prompt に入れる
5. 権限判定は常に laratrust_team_id を明示
6. PII は CipherSweet
7. 課金の冪等性: チケットは reserve→commit/release の 2 フェーズ
8. 外部 URL 取得は SSRF 検査経由

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。

仕組みが機能していない段階で値を弄るな。方向性が間違っているなら、設計そのものを見直せ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest（RefreshDatabase はグローバル適用・--parallel）
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project 階層）
- DB は PostgreSQL。キューは database ドライバ（専用 connection 分離の既存例: database-analysis / database-media）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性、ロック順・競合）
2. 既存コードとの整合性（命名規約、パターン、API。見本: AnalysisJobService / AnalysisPipeline / RunManualAnalysis / TicketLedgerService(内部変更禁止) / ManualAnalysisController / ScenarioService。実ファイルの読み込み可）
3. PHPStan level 10 適合性
4. テスト計画の網羅性（各施策に Pest テスト、実 ffmpeg・実 S3・実キューに触れない）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript 型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、IDOR、AGENTS.md のセキュリティ不変条件、字幕→ffmpeg の注入境界）
10. DESIGN.md 準拠（DS token、disabled 禁止、Lucide アイコン）
11. Atomic Design 準拠（features/manual 配下の配置、階層逆流なし）

【確定仕様の優先順位】
/workspace/doc/10_実装仕様.md の §10.8 が §10.1〜§10.7 に優先する。特に:
- §10.8-1 チケット予約の冪等キー・TTL 付け替え・tries=1・例外時 release
- §10.8-6 レンダ中の編集競合・scenario_version スナップショット固定
- §10.8-8 render 冪等（同一 (manual, 操作種別) の in-flight 1 つ・failed のみ再トリガー）
- §10.5 COST_RENDER 初期固定 3・preview は 0・残高事前チェック
- AnalysisJob/RenderJob は共通抽象化せず個別実装（§10.8 採用しなかった項）
- TicketLedgerService の内部変更禁止（TTL=30 分固定。レンダは 30 分未満で commit）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: render（レンダ: 採用テイク合成 → 完成 mp4。ffmpeg + チケット 2 フェーズ）

作成: 2026-07-11 / ステータス: ドラフト（Codex 詳細レビュー前）

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

**グローバルロック順**（AnalysisPipeline の順に render_jobs を同順位追加。逆順取得ゼロ）:

```
(analysis_jobs | render_jobs) → video_manuals → ticket_reservations → organizations
```

| 経路 | 取得列 |
|---|---|
| RenderJobService::trigger / triggerPreview | video_manuals → (preview のみ organizations) |
| RenderPipeline::startJob | render_jobs → (render のみ reserve: organizations) |
| RenderPipeline::buildManifest | video_manuals（読み取り一貫性の確定点） |
| RenderPipeline::finalize | render_jobs → video_manuals → (render のみ commit: ticket_reservations → organizations) |
| RenderJobService::failJob | render_jobs → video_manuals → (release: ticket_reservations → organizations) |

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
    case StatusNotRenderable = 'status_not_renderable'; // render: ready 以外 / preview: analyzing・rendering
    case OrgPreviewLimit = 'org_preview_limit';      // org 同時 preview 上限超過

    public function message(): string { /* UI 向け説明文。サーバで確定 */ }
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
 * - 正規化 (writeDialogueText):
 *   1. CR/LF → \N (ASS 改行)、単独 CR も対象
 *   2. `{` `}` → 全角 `｛` `｝` に置換 (ASS override tag {\...} 注入の無効化)
 *   3. リテラル `\N` `\n` `\h` → バックスラッシュを全角 `＼` に置換 (制御綴りの無効化)
 *   4. その他の C0/C1 制御文字 → 除去
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
    public function temporaryDownloadUrl(string $key, string $filename): string; // attachment disposition
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

    public function failed(?Throwable $exception): void
    {
        $job = RenderJob::query()->find($this->renderJobId);
        if ($job !== null) {
            app(RenderJobService::class)->failJob($job, RenderErrorCode::Timeout,
                '書き出しが中断されました。再実行してください。');
        }
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

    public function handle(RenderObjectStorage $storage): void
    {
        DB::transaction(function () use ($storage): void {
            $job = RenderJob::query()->whereKey($this->renderJobId)->lockForUpdate()->first();
            if ($job === null || $job->output_path === null) { return; }               // 冪等
            if ($this->isLatestSucceededOfKind($job)) { return; }                      // 世代交代済みのみ削除
            $manual = $job->videoManual; // prefix 検証 (期待 prefix 外は report + no-op)
            if ($manual === null || ! str_starts_with($job->output_path, $prefix = $storage->keyPrefixFor($manual))) {
                report(new RuntimeException(...)); return;
            }
            $storage->delete($job->output_path);
            $job->output_path = null; // 削除済み実体を指し続けない (playback 404 と整合)
            $job->save();
        });
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
（冪等のため重複無害）。戻り値は再投入件数。

### テスト計画

- [ ] handle: 旧世代のみ削除 + output_path NULL 化 / 最新 succeeded は no-op /
      NULL 済み no-op / prefix 不一致は削除せず report
- [ ] reconcileOutputs: 取り残し検出で dispatch（Queue::fake）
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
export type RenderConflictType = "in_flight" | "status_not_renderable" | "org_preview_limit";
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

`ManualEnumTsSyncInvariantTest`（Round 3 反映。types/manual.ts を読み、
`RenderKind` / `RenderStep` / `RenderErrorCode` / `RenderConflictType` の TS literal union と
PHP enum の値集合一致を正規表現抽出で検証。乖離で fail）。

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
- **preview job の追跡**: 同じポーリング機構（preview は manual_status が変わらないため
  job.status のみで終端判定）。succeeded → `<video>` を `playback` route URL で表示。
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


---

## 概念設計（APPROVED 済み・参照用）

# 概念設計: render（レンダ: 採用テイク合成 → 完成 mp4。ffmpeg + チケット 2 フェーズ）

作成: 2026-07-11 / 対象アプリ: AI-CUE (/workspace)
ステータス: **APPROVED**（Codex gpt-5.4/medium 概念レビュー Round 5。履歴は codex-history/）
Round 3 反映（字幕は ASS 一時ファイル経由で filtergraph 直埋め禁止・保持ポリシーを
「収束」契約 + reconciliation に修正・RenderErrorCode を 3 値で閉じ enum/TS 同期テスト・
並行上限テストの実証方法を明確化）
改訂: Round 1 反映（ポーリングと成果物アクセスの権限分離・preview 再生の専用 route・
queued 短 SLA 回復・throttle/同時実行上限の契約化・出力保持ポリシー・専用削除 job）、
Round 2 反映（org preview 上限の Organization 行ロック直列化・playback の最新世代条件・
削除 job の payload を job id + relation 再解決に変更・error_code enum・IDOR route 名一覧）

## 背景・課題

AI-CUE の使命（North Star）は「専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を
作れるようにする」こと。本フィーチャはその**最終成果物**を生成する:

```
採用済みテイク群（T004 実装済み）
  → RenderJob（compose: カットごとにクリップ正規化 + 字幕焼き込み）
  → RenderJob（concat: cut 順に連結 → 完成 mp4）
  → S3 出力（output_path）、manual status: rendering → published
  → プレビュー / ダウンロード（署名 URL）
```

現状:
- VideoManual/Cut/Take、シナリオ編集（T002）、AI 解析（T003）、撮影/採用（T004）実装済み
- `render_jobs` テーブル・route・ffmpeg 合成・レンダ UI は未実装（本設計の対象）
- **見本にする既存基盤**: T003 の `AnalysisJob` + `AnalysisJobService` + `AnalysisPipeline`
  （チケット reserve/commit/release・予約冪等キー・tries=1・TTL 付け替え・terminal tx・
  stale 回復 cron）。§10.8 の方針どおり **AnalysisJob/RenderJob は共通抽象化せず個別実装**
- v1 スコープ: **字幕のみ（TTS なし）**、自前 ffmpeg ワーカー、単一言語（多言語は後続）
- 動画正規化の前提: v1 の Take はアップロード後の ffmpeg 正規化を**行っていない**
  （`TakeRegistrationService` は登録時に即 ready。`duration_ms` はクライアント申告 = 表示用）。
  したがってレンダ側 compose 段が**クリップごとの正規化（再エンコード）を兼ねる**必要がある

## 改善アイデア（何をどう変えるか）

### 1. データモデル（doc/10 §10.1 準拠 + §10.8-6 の version スナップショット）

**新テーブル `render_jobs`** + `RenderJob` Model + `RenderJobFactory`:

| カラム | 型 | 備考 |
|---|---|---|
| id | bigint PK | |
| video_manual_id | FK→video_manuals, NOT NULL | **protected**, cascade |
| kind | string enum | `RenderKind`: render / preview（§10.8-8「別操作種別」の実体） |
| status | string enum | `JobStatus`（既存 T003 enum を共用）: queued/running/succeeded/failed |
| step | string enum NULL | `RenderStep`: compose / concat（§10.1） |
| progress | int NULL | 0-100（ポーリング表示用の粗い値） |
| scenario_version | int NOT NULL | **開始時スナップショット**（§10.8-6。トリガー tx で確定） |
| ticket_reservation_id | FK→ticket_reservations, NULL | **protected**。予約の冪等キー（§10.8-1）。preview は常に NULL |
| output_path | string NULL | S3 キー（succeeded 時に設定。**世代交代で削除後は NULL 化**） |
| error | text NULL | 失敗理由（ユーザー向け要約・表示用） |
| error_code | string enum NULL | `RenderErrorCode`（失敗種別の型付き判別子。Round 2 反映） |
| timestamps | | |

- `result_json` は analysis 専用のため持たない（§10.1 の共通表は「等」表記。レンダに中間成果なし）
- **`kind` / `scenario_version` は §10.1 の列表に無い追加列**。`kind` は §10.8-8
  「preview と render は別操作種別（in-flight 判定・課金有無が異なる）」を 1 テーブルで
  表現する最小手段（preview 専用テーブルはカラムがほぼ同一で過剰）。`scenario_version` は
  §10.8-6 のスナップショット要件の実体。**本設計の承認と同時に doc/10 §10.1 を更新**する
- 新 enum: `App\Enums\Manual\RenderStep`（compose/concat）、`App\Enums\Manual\RenderKind`
  （render/preview）、`App\Enums\Manual\RenderErrorCode`
  （**v1 は `scenario_version_changed` / `timeout` / `internal` の 3 値で閉じる**。
  DB cast・readonly DTO・TS literal union を完全一致させ、**PHP enum ⇔ TS union の
  値集合同期テスト**（types/manual.ts を読み比較する Pest テスト）を追加する = Round 3 反映。
  フロントの CTA 分岐は自由文 error でなく error_code で行う = 文言変更で壊れない）。
  `JobStatus` は既存を共用（doc/10 §10.2 どおり）
- 保護キー: `video_manual_id` / `ticket_reservation_id` は `MassAssignmentProtectedKeys`
  登録済み（追記不要）。RenderJob の
  status/step/progress/kind/scenario_version/output_path/error/**error_code** は
  Service 管理状態のため **$fillable を持たない**（AnalysisJob と同じ明示代入のみの規約）
- `VideoManual::renderJobs(): HasMany<RenderJob>`（route param `{renderJob}` の
  scopeBindings 推論と一致する relation 名）

### 2. ルート（§10.3。PC 編集画面、web guard・org-scoped・認可前 404）

| メソッド | パス | 用途 | 応答 |
|---|---|---|---|
| POST | `.../manuals/{manual}/render` | 完成動画レンダ（`video_render` チケット消費） | 201 + RenderJobResource |
| POST | `.../manuals/{manual}/preview` | プレビュー生成（チケット非消費） | 201 + RenderJobResource |
| GET | `.../manuals/{manual}/render-jobs/{renderJob}` | job 状態ポーリング（**成果物 URL は含めない**） | 200 + RenderJobResource |
| GET | `.../manuals/{manual}/render-jobs/{renderJob}/playback` | **preview の再生**（kind=preview の succeeded のみ。編集者専用） | 302（S3 署名 URL） |
| GET | `.../manuals/{manual}/download?lang=` | 完成 mp4 の署名 URL へ redirect | 302（S3 署名 URL） |

**ポーリングと成果物アクセスの権限分離（Round 1 Critical 反映）**: ポーリング（`view` =
撮影者も可）は status/step/progress の進捗情報のみを返し、**署名 URL を一切含めない**。
成果物へのアクセスは専用 route + 専用 ability に分離する:
- preview 再生 = `playback` route（`render` ability。preview は編集者専用機能）。
  302 を返す条件は「**当該 job が同 manual の preview の最新 succeeded job であり
  output_path が非 NULL**」（Round 2 反映: 世代 1 保持と整合。旧世代 job は実体削除済みの
  可能性があるため 404）。kind=render の job / 未完了 job も 404（download route が正）
- 完成 mp4 = `download` route（`download` ability）。**v1 の完成動画取得は download route のみ**
  （published のインライン再生は権限モデル簡潔化のため v1 スコープ外。Round 1 Warning 反映）

- 全て既存 `.../manuals/{manual}` 系と同じ `Route::scopeBindings()` グループ +
  `NestedRouteIdorDefenseTest` inventory 登録。**登録する route 名（5 本全て）**:
  `projects.manuals.render` / `projects.manuals.preview` /
  `projects.manuals.render-jobs.show` / `projects.manuals.render-jobs.playback` /
  `projects.manuals.download`（Round 2 反映: 件数でなく名前で固定し登録漏れを防ぐ）。
  cross-org 404 は既存 `EnsureProjectBelongsToRouteOrganization`
  （`project.in-route-org`）+ inline guard で担保
- **ポーリング URI は §10.3 の `GET .../jobs/{job}` から `.../render-jobs/{renderJob}` へ変更**:
  既存 `jobs/{analysisJob}` は `VideoManual::analysisJobs()` に bind 済みで、render_jobs は
  別テーブルのため同一 param では scopeBindings の relation 推論（= IDOR 防御の第一層）が
  成立しない。ポーリング応答 shape・フロントの polling 実装は AnalysisJob 系を踏襲
  （「流用/共通化」はコード規約レベルで達成し、URL は型ごとに分ける）。
  **doc/10 §10.3 を本設計承認と同時に更新**
- `download` の `lang` は v1 では**任意 + `ja` のみ許可**（それ以外は 422。feature_multilang
  は後続。クエリ形状だけ §10.3 と互換に保つ）。ダウンロード可能条件:
  `status=published` かつ最新 succeeded render job の `output_path` あり（無ければ 409 系の
  conflict 応答ではなく **404**: 完成物が存在しない）。応答は
  `redirect()->away(署名URL)`（`response-content-disposition: attachment` を署名に含める。
  DTO/JsonResource 規約の対象外 = JSON を返さない）
- render/preview トリガーは同一オリジン XHR（JSON 応答）。409/402/422 の精緻な HTTP 契約が
  必要なため `ManualAnalysisController` と同型の JsonResource 構成
- **abuse 耐性の契約（Round 1 Warning 反映）**: render/preview の POST 2 route に named
  rate limiter `render-trigger`（`RateLimiter::for`。**user id + org id 単位で 6 回/分**）を
  middleware として固定する。加えて **org 単位の同時 preview 上限**（config
  `render_max_inflight_previews_per_org => 3`）をトリガー tx 内で検査し、超過は 409
  （`RenderConflictType::OrgPreviewLimit`）。preview はチケット非消費のため、
  この 2 段（レート + 同時実行数）が無料 ffmpeg 実行の負荷上限を構造的に決める

### 3. 認可（§10.5: 編集者 = render/download。撮影者は不可）

`VideoManualPolicy` に親委譲メソッドを追加（直 fetch 禁止・`ProjectPolicy::update` 委譲）:
- `render(User, VideoManual)`: render / preview 両トリガーの ability（preview も編集者専用。
  §10.5 の権限表は編集者=render/download、撮影者に preview は無い）
- `download(User, VideoManual)`: download + ポーリング…ではなく、**ポーリングは `view`**
  （AnalysisJob ポーリングと同じ read 権限。撮影者もレンダ進行を見られて害がない）

### 4. トリガー: `RenderJobService::trigger()`（render）/ `triggerPreview()`（preview）

`AnalysisJobService::trigger()` と同型。tx + **VideoManual 行ロック**（共有ロック規約:
status を書く / version を読むため。`ScenarioWritePathInventoryTest` inventory 登録）:

**render（チケット消費・status 遷移あり）**:
1. 行ロック（`$project->manuals()->whereKey(...)->lockForUpdate()` = 子∈親も担保）
2. 実行可能状態 guard: `status === ready` のみ（それ以外は **409** `RenderConflictException`）。
   - published からの直接再レンダは**不可**（編集で published→ready に戻してから =
     §10.8-6 の「再レンダは明示トリガー」。無変更の published 再レンダは同一出力に
     チケットを重複消費するだけなので状態機械から排除）
   - 失敗後の再トリガー: failJob が rendering→ready へ戻すため「failed のみ再トリガー可」
     （§10.8-8）は ready guard + in-flight 判定で構造的に満たされる
3. **render 冪等（§10.8-8）**: 同一 manual の in-flight（queued/running）な `kind=render`
   job が存在 → **409**（preview の in-flight は別種別なので妨げない）
4. **入力検証（採用テイク欠落 = エラー方針）**: 全カット（step/point）を走査し、
   `adopted_take_id` が NULL または採用テイクが `status !== ready` のカットが 1 つでもあれば
   **422**（欠落カットの表示ラベル一覧を message に含める）。
   **スキップではなくエラーを採用**: 使命は「標準化されたマニュアル動画」であり、
   歯抜けの完成動画を黙って出すのは成果物の標準性を壊す。撮影漏れはトリガー時に
   明示して撮影へ差し戻すのが正しい導線（チケット消費前に fail-fast できる利点も）
5. **尺上限 guard（§10.8-1: レンダ 30 分未満 = TTL 内 commit）**: 採用テイク
   `duration_ms`（NULL は保守的に config の既定尺で代用）+ 静止画 `static_display_seconds`
   の合計が config `render_max_total_source_ms`（初期 20 分）超過 → **422**
   （「動画を分割してください」）。クライアント申告値ベースのソフトゲートで、
   ハード保証はジョブ timeout（§6）が担う
6. **残高事前チェック**: `balance(org) < COST_RENDER(=3)` → **402**（reserve はジョブ開始時 = §10.5）
7. `RenderJob` を relation 経由で作成（kind=render, status=queued,
   **scenario_version = 行ロック下の `$locked->scenario_version` をスナップショット**）
8. manual status を `ready → rendering` に forceFill（enqueue 時点で遷移させ、
   シナリオ保存 / テイク採用・削除を既存の rendering guard（409）で排他。
   `ScenarioService::save()` / `CaptureTakeService::adopt()`・`delete()` に実装済みであることを
   確認済み = 補強不要。Feature テストで整合を固定）
9. commit 後に `RunManualRender::dispatch($job->id)`

**preview（チケット非消費・status 遷移なし）**:
- 行ロック + guard: `status ∈ {ready, published}`（analyzing/rendering は 409、draft は
  cuts 不在のため 422「シナリオがありません」）。**manual status は変更しない**
  （プレビューは編集中確認の中核 UX。編集を妨げない = doc/09 §9.7）
- preview 冪等: 同一 manual の in-flight な `kind=preview` job が存在 → 409
- **org 同時 preview 上限は Organization 行ロックで直列化（Round 2 Critical 反映）**:
  manual 行ロックは manual 間の競合を防がないため、上限検査 + job 作成の前に
  **Organization 行を `lockForUpdate()`** し（`TicketLedgerService::reserve` の残高判定と
  同じ直列化手法）、ロック下で org 全体の in-flight preview 数を数えて超過なら 409
  （`RenderConflictType::OrgPreviewLimit`）。取得順は `video_manuals → organizations` =
  既存グローバルロック順（… → video_manuals → ticket_reservations → organizations）の
  部分列で循環待ちを作らない。テストは 2 段構え（Round 3/4 反映）:
  (a) 逐次 Feature テストで上限 409 の境界を固定、(b) **並行実行主体を分けた直列化の
  実証テスト**: 同一プロセスの 2 connection では 2 本目の同期呼び出しがロック待ちで
  停止し 1 本目を解放できないため、**subprocess（`php artisan tinker` 相当の子プロセス or
  Symfony Process で起動するテスト用 artisan command）で triggerPreview を実行**し、
  「(1) 親が connection A で Organization 行ロック保持 → (2) 子プロセスで triggerPreview
  開始（開始を通知）→ (3) 子が未完了であることを確認 → (4) A を commit → (5) 子が完了し、
  解放後の in-flight 件数で判定したことを確認」の順序を同期ポイント付きで検証する。
  ハング防止に子プロセス側で短い `lock_timeout` とテスト全体のタイムアウトを設定
- 採用テイク欠落は**許容**（欠落カットはプレースホルダ映像（黒背景）+ 字幕で合成 =
  doc/02「テイク未登録でもプレビュー再生できる」）
- チケット関連の検査・予約は一切行わない（COST=0。乱用防止は in-flight 1 本 +
  org 同時実行上限 + `render-trigger` rate limiter で担保 = §2 の契約）
- scenario_version はスナップショットする（進行中に編集されたら「その時点の版」の
  プレビューであることを応答に示せる。preview は status を持たないため編集と並走する）

org 導出は `$project->organization`（HasOneThrough）。payload のチケット/org 値は受けない。
`RenderConflictException`（新設。`AnalysisConflictException` と同型: `RenderConflictType`
enum + 専用 Resource で 409/422 を返す）。402 は既存 `InsufficientTicketsException::render()`
（T003 で expectsJson → 402 対応済み）をそのまま使う。

### 5. レンダジョブ: `RunManualRender` + `RenderPipeline`

**`App\Jobs\Manual\RunManualRender`**（ShouldQueue。`RunManualAnalysis` と同型）:
- payload は **`renderJobId: int` のみ**（payload 不信任）
- `$tries = 1`（§10.8-1）、専用 queue connection **`database-render`**（queue=render）を新設
- **時間 budget（§10.8-1: TTL=30 分内に commit）**:
  `job timeout (1500s = 25 分) < queue retry_after (1680s) < 予約 TTL (1800s)`。
  連鎖は `RenderTimeBudgetInvariantTest`（Architecture テスト。
  `AnalysisTimeBudgetInvariantTest` と同型）で CI 固定
- `failed(Throwable)` は最終防衛線として `RenderJobService::failJob()` を冪等に呼ぶ

**`App\Services\Render\RenderPipeline`**（本体。`AnalysisPipeline` と同型の状態機械）:

```
run(int $jobId):
  1. startJob tx { job 行ロック。status !== queued なら no-op return（重複配送 guard）
       kind=render のみ: ensureReservation(job, org)   ← §10.8-1（AnalysisPipeline と同一手順:
         有効な Reserved は再利用 / 失効 Reserved は明示 release して新規 reserve 付け替え /
         なしは新規 reserve。残高不足 InsufficientTicketsException → catch → failJob）
       status=running, step=compose, progress=5 }
  2. マニフェスト構築 tx（読み取り一貫性の確定点）:
     tx { manual 行ロック（$project->manuals() 経由再解決）
          guard: manual.scenario_version === job.scenario_version（不一致 → 例外 → failJob。
            render では rendering guard により起き得ないが preview では起きうる =
            「トリガー後〜開始前」に編集された preview は古い版を黙って出さず fail する。
            失敗種別は **error_code=`scenario_version_changed`**（型付き判別子。Round 2 反映）
            + 表示用 error 文言「編集中にシナリオが変更されたため、プレビューを作り直して
            ください」。フロントは error_code で「作り直す」CTA を出す =
            単なる失敗扱いにしない（Round 1 Warning 反映））
          cuts を表示順（step の sort_order → 直後にその points を sort_order 順）でロード、
          各 cut の採用テイク（video_path / duration_ms）・material_type・
          static_display_seconds・subtitle_primary / subtitle_secondary を
          RenderManifest DTO（in-memory・readonly）に確定 }
     以後 ffmpeg 実行中に cuts / takes が変わっても参照しない（version 固定の実体）
  3. compose 段（cut ごと。DB 外・ロック外）:
     - S3 から採用テイクを一時ディレクトリへダウンロード
     - クリップ正規化 + 字幕焼き込み: H.264/AAC・解像度/fps は config 固定値へ再エンコード
       （v1 Take は正規化未実施のため必須）。字幕は **焼き込み（burn-in）を採用**:
       成果物の使命は「どこでも同じに再生される標準化マニュアル動画」であり、
       サイドカー字幕はプレーヤー依存で表示が保証されない。subtitle_secondary（完全情報）を
       画面下部に常時表示、subtitle_primary（名称・数値）があれば上部に強調表示
     - material_type=still: 採用テイク動画の先頭フレームを静止画化し
       static_display_seconds 尺で保持（+ 無音声トラック）
     - preview のプレースホルダ cut（採用テイクなし）: 黒背景 + 字幕を
       config `preview_placeholder_seconds`（初期 3 秒）尺で生成
     - クリップごとに ffprobe で実尺を取得（cut_length_ms の派生元）
     - progress を compose 済みクリップ数比で 5→80 に更新（表示用の単発 update）
  4. concat 段: 正規化済みクリップを concat → 最終 mp4。step=concat, progress=90
  5. S3 アップロード: output キーは version 付きで再実行安全（doc/09 §9.7）
     render:  projects/{p}/manuals/{m}/renders/v{scenario_version}-{jobId}.mp4
     preview: projects/{p}/manuals/{m}/previews/v{scenario_version}-{jobId}.mp4
  6. finalize（terminal tx。AnalysisPipeline::finalize と同型の原子化）:
     tx { job 行ロック → guard: status === running（stale 回復 cron 先勝ちなら何もしない =
            無課金 succeeded / 課金済み failed を構造的に排除）
          manual 行ロック（$project->manuals() 経由）
          kind=render のみ:
            - guard: manual.status === rendering かつ
              manual.scenario_version === job.scenario_version（防御的再検証）
            - cuts.cut_length_ms（manifest の実測値）+ manual.total_length_ms を反映
            - TicketLedgerService::commit(reservation)（非 Reserved は LogicException →
              terminal tx 全体 rollback → failJob。§10.8-1「commit は Reserved のみ」）
            - manual status: rendering → published
          共通（render/preview）: 旧世代（同 manual・同 kind の直前 succeeded job）が
            あれば commit 後に **`DeleteRenderOutputsJob::dispatch(旧 job id)`** を積む
          job: status=succeeded, progress=100, output_path 保存 }
     **`DeleteRenderOutputsJob`（Round 1/2 Warning 反映）**: `DeleteTakeObjectsJob` は
     Take 概念の job のため流用しない（「似ているからで統合しない」）。さらに
     **payload は S3 キーでなく render job id**（任意キー削除の過大権限を排除）:
     handle は job 行を再ロードし、(a) 当該 job が「同 manual・同 kind の最新 succeeded」で
     **ない**こと（= 世代交代済み）を relation 経由で再検証、(b) output_path が manual 配下の
     期待 prefix（`projects/{p}/manuals/{m}/…`）であることを検証してから S3 削除 +
     **job の output_path を NULL 化**（削除済み実体を指し続けない = playback 404 と整合。
     media queue・tries=3・冪等: output_path NULL なら no-op）
     **出力保持ポリシー（Round 1/3 Warning 反映）**: render / preview とも
     「**非同期で最新 succeeded 1 世代へ収束**」を契約とする（tries=3 の非同期削除は
     恒久失敗しうるため「常に 1 世代」は保証しない）。収束の担保は 2 系統:
     (a) finalize 時の削除 job 投入（即時経路）、(b) **専用 command
     `render:reconcile-outputs`**（Round 4 反映: stale 回復とは別概念のため
     `render:recover-stale-jobs` に同居させない。schedule は同じ 5 分毎で良いが
     コマンド・テストの責務を分離）が「output_path 非 NULL かつ世代交代済み」の
     render_jobs を走査し削除 job を再投入（削除 job は冪等のため重複投入は無害）。
     失敗時は X. の後始末で S3 に成果物を残さない（Quota 計上外でも肥大しない）
  X. catch (Throwable): report + RenderJobService::failJob(job, ユーザー向け要約)
     + 一時ディレクトリ掃除（finally）+ **S3 アップロード後の失敗なら当該出力キーを
     ベストエフォート削除**（孤児オブジェクトを残さない）
```

**`RenderJobService::failJob()`**（`AnalysisJobService::failJob()` と同型・冪等）:
- job 行ロック + terminal guard（succeeded/failed は no-op）→ status=failed +
  error（表示文言）+ error_code（`RenderErrorCode`。timeout=`timeout`、
  version 不一致=`scenario_version_changed`、それ以外=`internal`）
- kind=render のみ: manual 行ロック → `status === rendering` のときのみ **ready へ復帰**
  （render は ready からしか始まらないため cuts は必ず存在する。preview は status を
  触っていないので復帰なし）
- 予約が Reserved なら release（LogicException は握って冪等 = 例外時 release の保証）

**stale 回復**: `render:recover-stale-jobs` console command（5 分毎 schedule。
`analysis:recover-stale-jobs` と同型・§10.8 方針どおり個別実装）。**queued と running で
閾値を分ける（Round 1 Warning 反映）**:
- `queued` が config `render_queued_stale_after_minutes`（**10 分**）超過 → failJob
  （dispatch 喪失 / キュー詰まり。render は enqueue 時点で manual を rendering に倒し
  編集を止めるため、queued 滞留は短い SLA で fail させ「何もできない時間」を最小化する。
  遅延配送が後から届いても pipeline 冒頭の queued guard で二重実行にならない）
- `running` が config `render_stale_after_minutes`（30 分）超過 → failJob
  （worker 異常終了。timeout 1500s + マージン）
安全性の本体は閾値ではなく finalize の job 行ロック + running guard
（誤回収された生存 pipeline は commit しない）。

**ロック順**（`AnalysisPipeline` のグローバル順に render_jobs を追加。全経路で逆順取得なし）:
`render_jobs → video_manuals → ticket_reservations → organizations`
（analysis_jobs と render_jobs は同一 tx 内で両方ロックする経路が存在しないため同順位で共存可）。

### 6. ffmpeg の隔離: `VideoComposer` 抽象 + Process ラッパ

doc/09 §9.7 の方針どおり **`VideoComposer` インターフェース**の背後に実装を隠す:

- `App\Services\Render\VideoComposer`（interface）:
  `compose(RenderManifest $manifest, TemporaryDirectory $work): ComposedVideo`
  （ComposedVideo = ローカル最終 mp4 パス + クリップ実測尺 list の DTO）。
  将来 AWS MediaConvert 等への差し替え点
- `App\Services\Render\FfmpegVideoComposer`（v1 実装）: ffmpeg/ffprobe コマンド組み立て。
  実行は **Laravel の `Process` facade** 経由（`Process::fake()` でテスト可能 =
  「専用 Service/Process ラッパに隔離」の要件を框架の公式作法で満たす。自前ラッパ不要）。
  binary パスは config（`render_ffmpeg_binary` / `render_ffprobe_binary`）
- S3 入出力は `RenderPipeline` 側（`Storage::disk('s3')` + テスト `Storage::fake()`）。
  composer はローカルファイルのみ扱う（責務分離: composer は「合成」だけ）
- **字幕の安全境界（Round 3 反映）**: 字幕テキストを **filtergraph へ直接埋め込まない**
  （Process の配列引数はシェル展開しか防がず、filtergraph 内の `:` `,` `\` `'` 改行の
  解釈は防げない）。カットごとに**一時 ASS ファイルを生成**し、filtergraph には
  `subtitles=<サーバ生成の一時ファイルパス>` のみを渡す（パスは英数字のみの temp dir =
  filtergraph メタ文字を含まない）。ASS 生成は**専用 Service（`AssSubtitleWriter`）に隔離**し、
  字幕本文へ **ASS 形式固有の正規化/エスケープ**を実装する（改行 → `\N`、`{` `}` は
  ASS override tag 注入になるため除去/エスケープ、**リテラルの `\N` `\n` `\h`・CR・
  制御文字も正規化対象に含める** = Round 4 Suggestion 反映）。入力（攻撃的文字列）と
  生成ファイルの双方を検証する Unit テスト + メタ文字・改行・`{\...}` タグ・日本語の
  Feature テストを追加。フォントは日本語対応フォント（Noto Sans CJK）を config で明示

### 7. ポーリング: `GET .../render-jobs/{renderJob}`（進捗のみ）

- `RenderJobData` DTO（readonly・kind を判別子に持つ）+ `RenderJobResource`
  （`AnalysisJobData`/`AnalysisJobResource` と同型）:
  `id / kind / status / step / progress / error / error_code / manual_status`
- **署名 URL・output_path はポーリング応答に一切含めない**（Round 1 Critical 反映:
  `view` 権限の応答に成果物アクセスを混ぜない。preview 再生は playback route =
  `render` ability、完成 mp4 は download route = `download` ability に分離）
- TS 側は `kind` を literal union（"render" | "preview"）で持ち、パネルの分岐漏れを型で検出
- 認可は `view`（撮影者も進捗は read 可）。cross-manual job id は scopeBindings +
  inline 再検査で 404

### 8. フロントエンド（Inertia + Svelte 5 runes、DS token、Lucide、disabled 禁止）

- `Manuals/Show.svelte` + 新 feature component `features/manual/RenderPanel.svelte`
  （`AnalysisPanel.svelte` の polling パターンを踏襲）:
  - ready + 編集権限: 「完成動画を生成」ボタン（**disabled にしない**。採用テイク欠落 /
    残高不足 / 尺超過は押下時にサーバの 422/402 メッセージを表示）+
    「プレビュー生成」ボタン
  - rendering: 進捗表示（step ラベル: 合成中/連結中 + progress bar）。
    `GET .../render-jobs/{id}` を 2.5 秒間隔でポーリング、succeeded → `router.reload()`、
    failed → エラー表示 + 再実行導線
  - published: 「ダウンロード」リンク（download route への通常遷移のみ。インライン再生は
    v1 スコープ外 = Round 1 Warning 反映）。編集済みで ready に戻った場合は
    「再生成が必要」の案内
  - preview 完了: `<video src={playback route URL}>` で再生（302 → S3 署名 URL を
    ブラウザが追従。編集者専用画面でのみ表示）。failed の CTA 分岐は
    **error_code の literal union**（`scenario_version_changed` → 「作り直す」CTA）で行う
- Show props に `render: { job: RenderJobProps | null }` を追加。
  `resources/js/types/manual.ts` に `RenderJobProps` / `RenderStep` / `RENDER_STEP_LABELS` 追加

### 9. 設定・運用

- `config/manual.php` 追記: `render_ticket_cost => 3`（COST_RENDER。§10.5 初期固定値。
  尺/解像度係数化は後続）、`render_stale_after_minutes => 30`・
  `render_queued_stale_after_minutes => 10`（queued 短 SLA）、
  `render_max_total_source_ms => 1_200_000`、`render_max_inflight_previews_per_org => 3`、
  `preview_placeholder_seconds => 3`、`render_resolution => '1920x1080'`・`render_fps => 30`、
  `render_ffmpeg_binary` / `render_ffprobe_binary` / `render_subtitle_font`
- `config/queue.php` に `database-render` connection（queue=render, retry_after=1680）。
  運用契約: worker に `php artisan queue:work database-render` を必須登録
  （docs/architecture.md へ追記。ffmpeg バイナリは worker ホスト要件）
- schedule: `render:recover-stale-jobs` 5 分毎

## 期待効果

- **使命の完結**: SOP → シナリオ → ナビ撮影 → **完成マニュアル動画（mp4）** の
  エンドツーエンドが成立する（AI-CUE の最終成果物。「編集ゼロ」の実体 =
  合成・字幕・連結を全自動化）
- プレビュー（チケット非消費）で「撮影前にシナリオ段階の確認」ができ、
  撮り直しコストを下げる（doc/02 のプレビュー UX）
- チケット 2 フェーズ + 予約冪等キー + version スナップショット + rendering 排他により、
  再試行・並行トリガー・TTL 切れ・編集競合のそれぞれで「二重課金しない / 完成物が
  編集中シナリオと食い違わない / rendering で詰まない」方向へ収束（セキュリティ不変条件 7）

## 実装方針（概要）

| レイヤ | 追加/変更 |
|---|---|
| DB | `render_jobs` migration、`RenderStep`/`RenderKind` enum、`RenderJobFactory` |
| Model | `RenderJob`（$fillable なし・FK 保護）、`VideoManual::renderJobs()` |
| Routes | render POST / preview POST / render-jobs GET / playback GET / download GET（scopeBindings + IDOR inventory + `render-trigger` rate limiter） |
| Controller | `ManualRenderController`（store/preview/show/playback）、`ManualDownloadController`（show） |
| Policy | `VideoManualPolicy::render` / `::download`（親委譲） |
| Service | `RenderJobService`（trigger/triggerPreview/failJob/recoverStale）、`RenderPipeline`、`VideoComposer` interface + `FfmpegVideoComposer`、`RenderManifest`/`ComposedVideo` DTO |
| Job | `RunManualRender`（tries=1, timeout=1500, 専用 connection database-render）、`DeleteRenderOutputsJob`（media queue・payload=job id・relation 再解決 + prefix 検証 + output_path NULL 化） |
| Console | `render:recover-stale-jobs`（5 分毎。queued=10 分 / running=30 分の 2 閾値）、`render:reconcile-outputs`（5 分毎。旧世代出力の削除 job 再投入 = 責務分離） |
| Exception/Enum | `RenderConflictException` + `RenderConflictType` + Resource、`RenderErrorCode` |
| Front | Show の RenderPanel（生成/プレビュー/進捗/DL）、TS 型 |
| Config | manual.php（COST_RENDER=3 ほか）、queue.php（database-render） |
| Test | Feature（下記）+ Architecture（IDOR inventory・ScenarioWritePathInventoryTest 追記・RenderTimeBudgetInvariantTest）+ Vitest |
| Docs | doc/10 §10.1（kind/scenario_version）・§10.3（render-jobs URI）、docs/architecture.md、docs/factories.md |

**テストの重点（実 ffmpeg・実 S3・実キューに触れない）**:
`Process::fake()`（ffmpeg/ffprobe）+ `Storage::fake()` + `Queue::fake()`/sync。
- render 成功: status ready→rendering→published、output_path 記録、cut_length_ms/total_length_ms 反映
- **version 固定**: preview トリガー後に scenario 保存 → マニフェスト構築で version 不一致 fail。
  render 中の scenario 保存 / adopt / take 削除は 409（既存 guard との整合）
- published→ready 戻し後の再レンダは明示トリガーのみ（published 直接 render は 409）
- チケット 2 フェーズ: 再試行で二重予約しない（予約再利用）、TTL 失効 Reserved の
  release + 新規付け替え、失敗時 release、commit は Reserved のみ（非 Reserved は
  rollback + failJob）、**preview は予約ゼロ**（台帳・予約テーブル無変化）
- render 冪等: 同時 in-flight 1（render/preview は互いに独立）、failed のみ再実行可
- 残高不足 402（トリガー時）/ 採用テイク欠落 422 / 尺超過 422 / org preview 上限 409
  （**異なる manual への並行 trigger でも上限を超えない** = Organization 行ロック直列化）
- download: published + output_path で署名 URL redirect、未完成 404、lang≠ja 422、撮影者 403
- playback: **最新 succeeded preview** で署名 URL redirect、旧世代（output_path NULL 化済み /
  最新でない）404、kind=render 404、未完了 404、撮影者 403
- **ポーリング応答に output_path / 署名 URL が含まれない**（権限分離の固定）
- 認可: 撮影者は render/preview/download/playback 不可（403）、ポーリングは可
- 保持ポリシー: 再レンダ成功で旧世代 job id の削除 job が積まれ、実行で S3 削除 +
  旧 job の output_path NULL 化（最新 1 世代へ収束）。最新 succeeded を指す job id では
  no-op、prefix 不一致キーは削除しない。`render:reconcile-outputs` が取り残しを再投入。
  失敗時にアップロード済み出力が残らない
- 字幕エスケープ: filtergraph メタ文字・改行・ASS override tag（`{\...}`）・日本語を含む
  入力で ASS ファイル生成が安全（Unit）、filtergraph に字幕本文が直接現れない
- RenderErrorCode の PHP enum ⇔ TS union 値集合同期テスト
- cross-org / cross-project / cross-manual 404（IDOR inventory）
- 保護キー直送 422、stale 回復（**queued=10 分 / running=30 分** の 2 閾値）、
  failJob の status 戻し + error / error_code 記録

## 制約・前提

- **doc/10 §10.8 が §10.1〜§10.7 に優先**（予約冪等キー・tries=1・version 固定・render 冪等）
- `TicketLedgerService` は**内部変更しない**（TTL 延長 API は追加しない = §10.8 保留項。
  30 分内 commit は timeout 1500s + 尺上限ソフトゲートで担保）
- 共有ロック規約: status / scenario_version / cuts（cut_length_ms 含む）の全書き込みは
  VideoManual 行ロック下 + `ScenarioWritePathInventoryTest` inventory 登録
  （`RenderJobService::trigger` / `::failJob` / `RenderPipeline::finalize` を追加）
- AnalysisJob/RenderJob は共通抽象化しない（§10.8。同型コードの重複は意図的）
- ffmpeg は worker ホストに前提として存在（Docker image 要件。テストでは Process::fake）

## スコープ外（後続フェーズ）

- 多言語（feature_multilang: 字幕言語別出力・lang 切替）、TTS 音声（TtsProvider 差し込み）
- COST_RENDER の尺/解像度係数化（v1 は固定 3）
- サイドカー字幕・画質バリアント・レンダ出力の容量 Quota 計上（v1 は takes のみ計上）
- 分割 job / TTL 延長による 30 分超レンダ（v1 は尺上限で回避）
- レンダ進捗の WebSocket/SSE push（v1 はポーリング）
- Take アップロード時の事前正規化パイプライン（compose 段の再エンコードで代替）
- published 完成動画のインライン再生（v1 の完成動画取得は download route のみ）
- レンダのユーザー操作キャンセル（queued 短 SLA 回復 + failed 再トリガーで代替）


---

## 関連する現行コード（抜粋）

### app/Services/Manual/AnalysisJobService.php（見本: trigger/failJob/recoverStale）
```php
<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\Enums\Billing\TicketReservationStatus;
use App\Enums\Manual\AnalysisConflictType;
use App\Enums\Manual\JobStatus;
use App\Enums\Manual\VideoManualStatus;
use App\Exceptions\Billing\InsufficientTicketsException;
use App\Exceptions\Manual\AnalysisConflictException;
use App\Jobs\Manual\RunManualAnalysis;
use App\Models\AnalysisJob;
use App\Models\Organization;
use App\Models\Project;
use App\Models\VideoManual;
use App\Services\Billing\TicketLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Webmozart\Assert\Assert;

/**
 * AI 解析ジョブの状態機械 (trigger / failJob / recoverStale)。doc/10 §10.8-8。
 *
 * VideoManualStatus 遷移表 (本サービスが関与する遷移。詳細は docs/architecture.md):
 * - draft/ready → analyzing: trigger() (行ロック + from-state guard。violate → 409)
 * - analyzing → ready/draft: failJob() (analyzing のときのみ復帰。cuts 有無で決定)
 * (analyzing → ready の成功遷移は ScenarioService::materializeIntoLockedManual = terminal tx 内)
 *
 * 共有ロック規約: status を書く経路は必ず VideoManual 行を lockForUpdate() した
 * 同一 tx 内で反映する (ScenarioWritePathInventoryTest が経路を deny-by-default で固定)。
 */
class AnalysisJobService
{
    public function __construct(
        private readonly TicketLedgerService $tickets,
    ) {}

    /**
     * AI 解析のトリガー (§10.8-8 冪等 + 残高事前チェック + enqueue)。
     *
     * - 実行可能状態: status ∈ {draft, ready} のみ (ready→analyzing = 再解析は正式遷移)
     * - analyze 冪等: 同一 manual の in-flight (queued/running) は 1 つ → 409
     * - 残高事前チェックは fail-fast の入口ゲート (真の残高保証は pipeline の reserve)
     */
    public function trigger(Project $project, VideoManual $manual): AnalysisJob
    {
        $job = DB::transaction(function () use ($project, $manual): AnalysisJob {
            // 共有ロック規約: status を書くため VideoManual 行ロック (親 relation 経由 = 子∈親も担保)
            /** @var VideoManual $locked */
            $locked = $project->manuals()->whereKey($manual->id)->lockForUpdate()->firstOrFail();

            // 実行可能状態 guard (ready→analyzing は再解析の正式遷移。doc/10 §10.2)
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

    /**
     * ジョブの失敗確定 (冪等)。pipeline catch / Job::failed / recoverStale の合流点。
     *
     * - terminal (succeeded/failed) 済みは no-op (terminal tx 勝ち・二重 fail を握る)
     * - manual は analyzing のときのみ復帰 (cuts があれば ready、無ければ draft)
     * - 予約は Reserved のみ release (並行 commit/release 済みは LogicException → 握って冪等)
     *
     * @return bool 実際に failed へ遷移させたか (terminal 済み no-op は false)
     */
    public function failJob(AnalysisJob $job, string $error): bool
    {
        return DB::transaction(function () use ($job, $error): bool {
            /** @var AnalysisJob $locked */
            $locked = AnalysisJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status->isTerminal()) {
                return false;
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

            return true;
        });
    }

    /**
     * stale ジョブの回復 (cron)。queued: dispatch 喪失、running: worker 異常終了。
     * failJob は行ロック + terminal guard で冪等 (TicketLedgerService::releaseStale と同型)。
     *
     * @return int 実際に回復 (failed 遷移) した件数 (走査中に terminal へ先着されたものは数えない)
     */
    public function recoverStale(): int
    {
        $threshold = CarbonImmutable::now()->subMinutes(config()->integer('manual.analysis_stale_after_minutes'));
        $staleIds = AnalysisJob::query()
            ->where(function (Builder $query) use ($threshold): void {
                $query
                    ->where(function (Builder $query) use ($threshold): void {
                        $query->where('status', JobStatus::Queued->value)
                            ->where('created_at', '<=', $threshold);
                    })
                    ->orWhere(function (Builder $query) use ($threshold): void {
                        $query->where('status', JobStatus::Running->value)
                            ->where('updated_at', '<=', $threshold);
                    });
            })
            ->pluck('id');

        $recovered = 0;
        foreach ($staleIds as $id) {
            $job = AnalysisJob::query()->whereKey($id)->first();
            if ($job === null) {
                continue;
            }
            // failJob 内で行ロック + terminal guard 再検証するため、競合したジョブはそこで no-op (false)
            if ($this->failJob($job, '解析がタイムアウトしました。再実行してください。')) {
                $recovered++;
            }
        }

        return $recovered;
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

```

### app/Services/Manual/AnalysisPipeline.php（見本: startJob/ensureReservation/finalize/ロック順）
```php
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
            $this->finalize($job, $generated);
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

```

### app/Jobs/Manual/RunManualAnalysis.php（見本: tries/timeout/専用 connection/failed フック）
```php
<?php

declare(strict_types=1);

namespace App\Jobs\Manual;

use App\Models\AnalysisJob;
use App\Services\Manual\AnalysisJobService;
use App\Services\Manual\AnalysisPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * AI 解析の queue job (薄い殻。本体は AnalysisPipeline)。
 *
 * - payload は analysisJobId のみ (モデル/チケット/org 値を payload に持たない = payload 不信任)
 * - 専用 connection database-analysis (retry_after=1560) で流す。運用契約:
 *   本番/ステージングは `php artisan queue:work database-analysis` を worker 定義に必須登録
 *   (docs/architecture.md。滞留は recoverStale cron が 30 分で failJob する)
 */
class RunManualAnalysis implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** 自動再試行しない (§10.8-1。再実行は analyze 再トリガーのみ) */
    public int $tries = 1;

    /**
     * worst-case (LLM 3 段 × 3 試行 × client timeout 120s = 1,080s) + 抽出/解析余裕 180s + マージン。
     * timeout (1,380) < retry_after (1,560) < 予約 TTL (1,800) の連鎖は
     * AnalysisTimeBudgetInvariantTest が CI 固定する。
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

### app/Http/Controllers/Projects/ManualAnalysisController.php（見本: 2 層 guard + JsonResource）
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\DataTransferObjects\Manual\AnalysisJobData;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\AnalyzeManualRequest;
use App\Http\Resources\Manual\AnalysisJobResource;
use App\Models\AnalysisJob;
use App\Models\Project;
use App\Models\VideoManual;
use App\Services\Manual\AnalysisJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * AI 解析のトリガー (store) と job 状態ポーリング (show)。doc/10 §10.3。
 * 同一オリジン XHR (JSON 応答)。409/402 契約のため Inertia でなく JsonResource を返す。
 *
 * nested route の URL 整合は ManualScenarioController と同じ 2 層 (認可より前に 404):
 * 1. {project} ∈ current org (resolveOrganizationProject = inline guard)
 * 2. {manual} ∈ {project}、{analysisJob} ∈ {manual} (routes 側の Route::scopeBindings())
 */
class ManualAnalysisController extends Controller
{
    use ResolvesCurrentOrganization;

    /** AI 解析トリガー (201 + AnalysisJobResource)。編集者のみ。保護キー直送は 422 */
    public function store(AnalyzeManualRequest $request, Project $project, VideoManual $manual, AnalysisJobService $analysis): JsonResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
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
        // URL 整合 guard: 認可より前に 404
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

### tests/Architecture/ScenarioWritePathInventoryTest.php（冒頭 130 行: allowlist 構造）
```php
<?php

declare(strict_types=1);

/*
 * シナリオ整合の共有ロック規約 (AGENTS.md ドメイン固有規約 1) の書き込み経路 inventory。
 *
 * 「cuts / video_manuals.scenario_version / video_manuals.status を書き込む全経路は、
 *   対象 VideoManual 行を lockForUpdate() で取得した同一トランザクション内で反映する」
 *
 * 経路 (メソッド粒度。docs/architecture.md と対):
 * | 経路 | 書いてよいもの |
 * |---|---|
 * | ScenarioService::save() | cuts / scenario_version / status (rendering·analyzing guard 付き) |
 * | ScenarioService::materializeIntoLockedManual() | cuts / scenario_version / status (analyzing→ready のみ) |
 * | AnalysisJobService::trigger() | status (draft·ready→analyzing のみ) |
 * | AnalysisJobService::failJob() | status (analyzing→ready·draft のみ。cuts 有無で決定) |
 *
 * deny-by-default の token ベース静的走査 (PrismDirectDispatchScanner と同じ token_get_all 流儀。
 * コメント/docblock/文字列リテラル**内容**中の出現は無視する)。走査対象: app/ 配下の .php。
 *
 * 検出 1: 識別子/配列キー 'scenario_version' の出現 → allowlist 外のファイルなら fail
 * 検出 2: 書き込み形 `'status' => ... VideoManualStatus::...` / `->status = ... VideoManualStatus::...`
 *         (`VideoManualStatus::class` = cast 宣言は書き込みでないため除外) → allowlist 外なら fail
 * 検出 3: materializeIntoLockedManual の宣言は ScenarioService.php のみ、
 *         呼び出しは AnalysisPipeline.php のみ (ScenarioService 自身の中の呼び出しも fail =
 *         ファイル単位 allowlist の抜け穴を塞ぐ)
 */
final class ScenarioWritePathScanner
{
    /**
     * 検出 1 の allowlist (app/ 相対パス)。ScenarioDocumentData は読み取り shape の直列化のみ。
     * CaptureTakeService は adopt の 409 (ScenarioConflictException) に current_version を
     * 載せるための読み取りのみ (書き込みは検出 2 が別途 deny する)。
     */
    private const SCENARIO_VERSION_ALLOWED = [
        'Services/Manual/ScenarioService.php',
        'DataTransferObjects/Manual/ScenarioDocumentData.php',
        'Services/Capture/CaptureTakeService.php',
    ];

    /** 検出 2 の allowlist (app/ 相対パス) */
    private const STATUS_WRITE_ALLOWED = [
        'Services/Manual/ScenarioService.php',
        'Services/Manual/AnalysisJobService.php',
    ];

    /**
     * 検出 4a の allowlist: 識別子/配列キー 'adopted_take_id' の出現 (読み書き問わず)。
     * - CaptureTakeService: adopt / 削除時 null 化 (VideoManual 行ロック tx 内 = 唯一の書き込み経路)
     * - Cut.php: relation 宣言 (belongsTo 第 2 引数)
     * - CaptureCutData: 読み取り shape の直列化のみ
     * - MassAssignmentProtectedKeys: 保護キー台帳 (文字列リストのみ)
     */
    private const ADOPTED_TAKE_ID_ALLOWED = [
        'Services/Capture/CaptureTakeService.php',
        'Models/Cut.php',
        'DataTransferObjects/Capture/CaptureCutData.php',
        'Support/Security/MassAssignmentProtectedKeys.php',
    ];

    /**
     * 検出 4b の allowlist: 書き込み形 (`['adopted_take_id' => ...]` 配列キー /
     * `->adopted_take_id =` プロパティ代入)。CaptureCutData の配列キー出現は toArray() の
     * 読み取り直列化 (`'adopted_take_id' => $cut->adopted_take_id`) で、token パターンでは
     * 書き込み (forceFill の配列キー) と区別できないため allowlist に含める
     * (検出 4a が出現ファイル自体を 4 ファイルに固定しているため、新規ファイルへの
     * 書き込みはどちらの検出でも fail する)。
     */
    private const ADOPTED_TAKE_ID_WRITE_ALLOWED = [
        'Services/Capture/CaptureTakeService.php',
        'DataTransferObjects/Capture/CaptureCutData.php',
    ];

    /**
     * @return array<string, list<string>> 検出種別 => 違反ファイル (app/ 相対パス)
     */
    public static function findViolations(): array
    {
        $appDir = self::appDir();

        $violations = [
            'scenario_version' => [],
            'status_write' => [],
            'materialize_declaration' => [],
            'materialize_call' => [],
            'adopted_take_id' => [],
            'adopted_take_id_write' => [],
        ];

        foreach (self::phpFiles($appDir) as $path) {
            $relative = substr($path, strlen($appDir) + 1);
            $source = file_get_contents($path);
            if ($source === false) {
                throw new RuntimeException("Failed to read PHP source: {$path}");
            }

            if (self::containsScenarioVersionToken($source)
                && ! in_array($relative, self::SCENARIO_VERSION_ALLOWED, true)) {
                $violations['scenario_version'][] = $relative;
            }
            if (self::containsStatusWrite($source)
                && ! in_array($relative, self::STATUS_WRITE_ALLOWED, true)) {
                $violations['status_write'][] = $relative;
            }
            if (self::containsMaterializeDeclaration($source)
                && $relative !== 'Services/Manual/ScenarioService.php') {
                $violations['materialize_declaration'][] = $relative;
            }
            if (self::containsMaterializeCall($source)
                && $relative !== 'Services/Manual/AnalysisPipeline.php') {
                $violations['materialize_call'][] = $relative;
            }
            if (self::containsAdoptedTakeIdToken($source)
                && ! in_array($relative, self::ADOPTED_TAKE_ID_ALLOWED, true)) {
                $violations['adopted_take_id'][] = $relative;
            }
            if (self::containsAdoptedTakeIdWrite($source)
                && ! in_array($relative, self::ADOPTED_TAKE_ID_WRITE_ALLOWED, true)) {
                $violations['adopted_take_id_write'][] = $relative;
            }
        }

        foreach ($violations as $key => $files) {
            sort($files);
            $violations[$key] = $files;
        }

        return $violations;
    }
```

### routes/web.php（manuals ブロック抜粋）
```php

        // VideoManual (Project 配下の動画マニュアル)。一覧は projects.show が内包する。
        // {manual} は scopeBindings で $project->manuals() 経由の解決
        // (子→親不整合は認可より前に 404。NestedRouteIdorDefenseTest 登録済み)
        Route::get('/projects/{project}/manuals/create', [VideoManualController::class, 'create'])
            ->name('projects.manuals.create');
        Route::post('/projects/{project}/manuals', [VideoManualController::class, 'store'])
            ->name('projects.manuals.store');
        Route::scopeBindings()->group(function (): void {
            Route::get('/projects/{project}/manuals/{manual}', [VideoManualController::class, 'show'])
                ->name('projects.manuals.show');
            Route::get('/projects/{project}/manuals/{manual}/edit', [VideoManualController::class, 'edit'])
                ->name('projects.manuals.edit');
            Route::patch('/projects/{project}/manuals/{manual}', [VideoManualController::class, 'update'])
                ->name('projects.manuals.update');
            // シナリオ document 一括保存 (doc/09 §9.4 / doc/10 §10.3)。同一オリジン XHR (JSON 応答)。
            // {manual} ∈ {project} は scopeBindings、{project} ∈ current org は
            // project.in-route-org middleware + controller inline guard の 2 層 (既存 group が担保)
            Route::put('/projects/{project}/manuals/{manual}/scenario', [ManualScenarioController::class, 'update'])
                ->name('projects.manuals.scenario.update');
            // SOP アップロード (追記型 immutable。差し替え = 新規行。doc/10 §10.3)
            Route::post('/projects/{project}/manuals/{manual}/source-documents', [SourceDocumentController::class, 'store'])
                ->name('projects.manuals.source-documents.store');
            // AI 解析トリガー (残高事前チェック→job 投入。同一オリジン XHR/JSON。doc/10 §10.3, §10.8-8)
            Route::post('/projects/{project}/manuals/{manual}/analyze', [ManualAnalysisController::class, 'store'])
                ->name('projects.manuals.analyze');
            // job 状態ポーリング ({analysisJob} は $manual->analysisJobs() 経由 = cross-manual 404)
            Route::get('/projects/{project}/manuals/{manual}/jobs/{analysisJob}', [ManualAnalysisController::class, 'show'])
                ->name('projects.manuals.jobs.show');
            Route::delete('/projects/{project}/manuals/{manual}', [VideoManualController::class, 'destroy'])
                ->name('projects.manuals.destroy');
        });

        // プロジェクトメンバー管理 (追加は payload の user_id、削除は URL の {user})。
        // {user} は URL 整合 guard (org member か) で認可より前に 404 (NestedRouteIdorDefenseTest 登録済み)
        Route::post('/projects/{project}/members', [ProjectMemberController::class, 'store'])
```

### app/Policies/VideoManualPolicy.php（全文）
```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Models\VideoManual;

/**
 * VideoManual (Project 配下の動画マニュアル) の認可。
 * 子リソースは親 Policy に委譲する (直 fetch 禁止)。
 *
 * 権限表 (doc/10 §10.5): 編集者 (project_admin / org 管理者) = write 全可、
 * 撮影者 (project_member) = show / 一覧のみ。write 判定は ProjectPolicy::update が担う。
 */
class VideoManualPolicy
{
    public function __construct(
        private readonly ProjectPolicy $projectPolicy,
    ) {}

    /** 閲覧: プロジェクトを閲覧できる人 (撮影者も可) */
    public function view(User $user, VideoManual $manual): bool
    {
        $project = $manual->project;

        return $project !== null && $this->projectPolicy->view($user, $project);
    }

    /** 作成: プロジェクトを操作できる人 (対象 VideoManual が無いため Project を追加引数に取る) */
    public function create(User $user, Project $project): bool
    {
        return $this->projectPolicy->update($user, $project);
    }

    /** 更新 (メタデータ): プロジェクトを操作できる人 */
    public function update(User $user, VideoManual $manual): bool
    {
        $project = $manual->project;

        return $project !== null && $this->projectPolicy->update($user, $project);
    }

    /** 削除: プロジェクトを操作できる人 */
    public function delete(User $user, VideoManual $manual): bool
    {
        $project = $manual->project;

        return $project !== null && $this->projectPolicy->update($user, $project);
    }

    /** AI 解析の実行: プロジェクトを操作できる人 (編集者)。撮影者は不可 */
    public function analyze(User $user, VideoManual $manual): bool
    {
        $project = $manual->project;

        return $project !== null && $this->projectPolicy->update($user, $project);
    }
}

```

### app/Services/Billing/TicketLedgerService.php（公開 API 署名のみ）
- balance(Organization): int / reserve(Organization, int): TicketReservation（TTL 30 分・org 行ロック・不足 InsufficientTicketsException）
- commit(TicketReservation): void（行ロック + Reserved guard。非 Reserved は LogicException）
- release(TicketReservation): void（同上）/ releaseStale(): int（5 分毎 cron）
