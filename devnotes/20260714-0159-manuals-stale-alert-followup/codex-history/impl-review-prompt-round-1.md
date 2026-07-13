# 実装レビュー依頼: T032 manuals-stale-alert-followup (Round 1)

## アプリの使命 (North Star / AGENTS.md)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。
- v1: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項 (AGENTS.md)

1. テストなしの実装完了報告
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)

セキュリティ不変条件: tenant キー不信 / 子は親に属する(404 が認可より前) / cross-org 不可 / 権限判定は laratrust_team_id 明示 / PII は CipherSweet 等。

## 思考原則 — 全議論に適用

まず仮説を立てろ。データに真摯に向き合え。先人の知恵(Laravel/Svelte)を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system: レビュアーの役割

あなたは Laravel 12 (PHP 8.4) + Svelte 5 (runes) + Inertia.js のシニアレビュアー。以下の改善実装をレビューする。

観点:
- **設計との一致性**: 詳細設計書どおりに実装されているか
- **正確性**: staleness 判定 (failed && snapshot!==null && manual.scenario_version > snapshot) にロジック欠陥・境界バグはないか。ロック順・冪等性・並行性の回帰はないか
- **PHPStan 適合性** (level 10): 型の widen / ignore / baseline を使っていないか
- **DTO/JsonResource パターン**: `response()->json()` 直書きがないか (Inertia props + 既存 DTO のみか)
- **テスト網羅性**: 判定行列 (stale/not-stale/legacy/succeeded/preview 独立/CTA 保持) と snapshot 書込み・不変が Feature/Unit/Vitest で固定されているか。バグ修正がテストファーストか
- **セキュリティ**: 保護キー・cross-org・404 順序の回帰がないか
- **DESIGN.md 準拠**: hex 直書き / token 逸脱を増やしていないか
- **Atomic Design 準拠**: atom(Input) を無状態に保ち、層の逆流がないか

出力形式: ファイルごとに判定 → Critical / Warning / Suggestion に分類 → 最後に全体判定 **APPROVED / CHANGES_REQUESTED**。

---

## user: 詳細設計書

（`devnotes/20260714-0159-manuals-stale-alert-followup/detailed-design.md` を参照。要約: bug-hunt F-1-1/F-1-2/F-1-3 の修正。中核は F-1-1 = 失敗 job の error alert が、その後 scenario 保存で version が進んでも残留する「stale alert」を、DB 権威の `scenario_version` スナップショット比較でサーバ側から抑制する。施策1 migration(`scenario_version_at_terminal` カラム追加)、施策2 両 failJob で失敗確定時 scenario_version を snapshot、施策3 VideoManualService に displayXxxJob + isStaleFailure、施策4 Controller::show を委譲、施策5 SOP「短すぎ」を tooShort に分離、施策6 作成フォーム oninput エラークリア、施策7 テスト。）

## user: 実装差分 (git diff)

以下は worktree の実装差分。フルパスは `/workspace/.claude/worktrees/tasks/T032/`。

```diff
diff --git a/app/Exceptions/Manual/AnalysisFailedException.php b/app/Exceptions/Manual/AnalysisFailedException.php
index eb8101a..e3bb871 100644
--- a/app/Exceptions/Manual/AnalysisFailedException.php
+++ b/app/Exceptions/Manual/AnalysisFailedException.php
@@ -12,12 +12,18 @@
  */
 final class AnalysisFailedException extends RuntimeException
 {
-    /** テキスト抽出不能 (画像/スキャン手順書・破損・実質空・バイナリ) */
+    /** テキスト抽出不能 (画像/スキャン手順書・破損・バイナリ) */
     public static function unextractable(): self
     {
         return new self('テキストを抽出できません。画像・スキャンの手順書は現在未対応です。');
     }
 
+    /** 抽出できたが本文が実質空 (min_text_bytes 未満)。画像扱いと混同しない明示文言 */
+    public static function tooShort(): self
+    {
+        return new self('手順書の本文が短すぎます。もう少し詳しい手順書をアップロードしてください。');
+    }
+
     /** LLM 入力上限 (UTF-8 バイト) 超過 */
     public static function tooLarge(): self
     {
diff --git a/app/Http/Controllers/Projects/VideoManualController.php b/app/Http/Controllers/Projects/VideoManualController.php
index 6293ba0..66e3bc8 100644
--- a/app/Http/Controllers/Projects/VideoManualController.php
+++ b/app/Http/Controllers/Projects/VideoManualController.php
@@ -89,7 +89,7 @@ public function store(StoreVideoManualRequest $request, Project $project, VideoM
     }
 
     /** 詳細 (撮影者も閲覧可) */
-    public function show(Request $request, Project $project, VideoManual $manual, SeoManager $seo): Response
+    public function show(Request $request, Project $project, VideoManual $manual, SeoManager $seo, VideoManualService $manuals): Response
     {
         $organization = $this->resolveCurrentOrganization($request);
         // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
@@ -104,6 +104,11 @@ public function show(Request $request, Project $project, VideoManual $manual, Se
 
         $category = $manual->category;
 
+        // stale な失敗 (失敗確定後に scenario 保存が成立) は job=null で抑制する (T032 / F-1-1)
+        $analysisJob = $manuals->displayAnalysisJob($manual);
+        $renderJob = $manuals->displayRenderJob($manual);
+        $previewJob = $manuals->displayPreviewJob($manual);
+
         return Inertia::render('Manuals/Show', [
             'project' => [
                 'id' => $project->id,
@@ -120,19 +125,20 @@ public function show(Request $request, Project $project, VideoManual $manual, Se
             ],
             // AI 解析パネル (最新 job + 手順書有無)。AnalysisJobData::toArray() と対
             'analysis' => [
-                'job' => ($latest = $manual->analysisJobs()->latest('id')->first()) === null
+                'job' => $analysisJob === null
                     ? null
-                    : AnalysisJobData::fromJob($latest, $manual)->toArray(),
+                    : AnalysisJobData::fromJob($analysisJob, $manual)->toArray(),
                 'hasDocument' => $manual->sourceDocuments()->exists(),
             ],
             // レンダパネル (最新 render job / 最新 preview job / 再生可能 preview)。RenderProps と対
             'render' => [
-                'job' => ($render = $manual->renderJobs()->where('kind', RenderKind::Render->value)->latest('id')->first()) === null
+                'job' => $renderJob === null
                     ? null
-                    : RenderJobData::fromJob($render, $manual)->toArray(),
-                'previewJob' => ($preview = $manual->renderJobs()->where('kind', RenderKind::Preview->value)->latest('id')->first()) === null
+                    : RenderJobData::fromJob($renderJob, $manual)->toArray(),
+                'previewJob' => $previewJob === null
                     ? null
-                    : RenderJobData::fromJob($preview, $manual)->toArray(),
+                    : RenderJobData::fromJob($previewJob, $manual)->toArray(),
+                // playbackJobId は succeeded preview のみを見るため staleness 抑制の対象外 (不変)
                 'playbackJobId' => $manual->renderJobs()
                     ->where('kind', RenderKind::Preview->value)
                     ->where('status', JobStatus::Succeeded->value)
diff --git a/app/Models/AnalysisJob.php b/app/Models/AnalysisJob.php
index 3a814a8..e8fe91b 100644
--- a/app/Models/AnalysisJob.php
+++ b/app/Models/AnalysisJob.php
@@ -30,6 +30,7 @@
  * @property int|null $triggered_by
  * @property array<array-key, mixed>|null $result_json
  * @property string|null $error
+ * @property int|null $scenario_version_at_terminal
  * @property Carbon|null $created_at
  * @property Carbon|null $updated_at
  */
diff --git a/app/Models/RenderJob.php b/app/Models/RenderJob.php
index fa996bd..f42cea0 100644
--- a/app/Models/RenderJob.php
+++ b/app/Models/RenderJob.php
@@ -35,6 +35,7 @@
  * @property string|null $output_path
  * @property string|null $error
  * @property RenderErrorCode|null $error_code
+ * @property int|null $scenario_version_at_terminal
  * @property Carbon|null $created_at
  * @property Carbon|null $updated_at
  */
diff --git a/app/Services/Manual/AnalysisJobService.php b/app/Services/Manual/AnalysisJobService.php
index e3d048c..ab38cbd 100644
--- a/app/Services/Manual/AnalysisJobService.php
+++ b/app/Services/Manual/AnalysisJobService.php
@@ -120,13 +120,17 @@ public function failJob(AnalysisJob $job, string $error): bool
                 return false;
             }
 
+            // manual を先に lock で取得し (job → manual のロック順を維持)、失敗確定時の
+            // scenario_version を job にスナップショットする (stale alert 判定の順序基準。T032)。
+            /** @var VideoManual $manual */
+            $manual = VideoManual::query()->whereKey($locked->video_manual_id)->lockForUpdate()->firstOrFail();
+
             $locked->status = JobStatus::Failed;
             $locked->error = $error;
+            $locked->scenario_version_at_terminal = $manual->scenario_version;
             $locked->save();
 
             // manual 復帰 (analyzing のときのみ。cuts があれば ready、無ければ draft = 概念設計 §4)
-            /** @var VideoManual $manual */
-            $manual = VideoManual::query()->whereKey($locked->video_manual_id)->lockForUpdate()->firstOrFail();
             if ($manual->status === VideoManualStatus::Analyzing) {
                 $manual->forceFill([
                     'status' => $manual->cuts()->exists() ? VideoManualStatus::Ready : VideoManualStatus::Draft,
diff --git a/app/Services/Manual/RenderJobService.php b/app/Services/Manual/RenderJobService.php
index c071fd4..5c0bd1f 100644
--- a/app/Services/Manual/RenderJobService.php
+++ b/app/Services/Manual/RenderJobService.php
@@ -181,18 +181,20 @@ public function failJob(RenderJob $job, RenderErrorCode $code, string $error): b
                 return false;
             }
 
+            // preview/render とも失敗確定時の scenario_version を snapshot する必要があるため、
+            // manual を常に lock で取得する (従来は kind=render のみ取得だった)。ロック順 job → manual。
+            /** @var VideoManual $manual */
+            $manual = VideoManual::query()->whereKey($locked->video_manual_id)->lockForUpdate()->firstOrFail();
+
             $locked->status = JobStatus::Failed;
             $locked->error = $error;
             $locked->error_code = $code;
+            $locked->scenario_version_at_terminal = $manual->scenario_version;
             $locked->save();
 
             // manual 復帰 (kind=render かつ rendering のときのみ。preview は status を触らない)
-            if ($locked->kind === RenderKind::Render) {
-                /** @var VideoManual $manual */
-                $manual = VideoManual::query()->whereKey($locked->video_manual_id)->lockForUpdate()->firstOrFail();
-                if ($manual->status === VideoManualStatus::Rendering) {
-                    $manual->forceFill(['status' => VideoManualStatus::Ready])->save();
-                }
+            if ($locked->kind === RenderKind::Render && $manual->status === VideoManualStatus::Rendering) {
+                $manual->forceFill(['status' => VideoManualStatus::Ready])->save();
             }
 
             // 予約 release (Reserved のみ。並行 commit/release 済みは LogicException → 握って冪等)
diff --git a/app/Services/Manual/SopTextExtractor.php b/app/Services/Manual/SopTextExtractor.php
index 1e223bd..7e2062c 100644
--- a/app/Services/Manual/SopTextExtractor.php
+++ b/app/Services/Manual/SopTextExtractor.php
@@ -47,7 +47,7 @@ public function extract(SourceDocument $document): ExtractedText
 
         $bytes = strlen($text);
         if ($bytes < config()->integer('manual.analysis_min_text_bytes')) {
-            throw AnalysisFailedException::unextractable(); // 画像/スキャン → v1 未対応の明示文言
+            throw AnalysisFailedException::tooShort(); // 短い有効テキスト → 画像未対応と別文言
         }
         if ($bytes > config()->integer('manual.analysis_max_text_bytes')) {
             throw AnalysisFailedException::tooLarge();
diff --git a/app/Services/Manual/VideoManualService.php b/app/Services/Manual/VideoManualService.php
index 9726bd6..88cfdd3 100644
--- a/app/Services/Manual/VideoManualService.php
+++ b/app/Services/Manual/VideoManualService.php
@@ -4,8 +4,12 @@
 
 namespace App\Services\Manual;
 
+use App\Enums\Manual\JobStatus;
+use App\Enums\Manual\RenderKind;
 use App\Jobs\Capture\DeleteTakeObjectsJob;
+use App\Models\AnalysisJob;
 use App\Models\Project;
+use App\Models\RenderJob;
 use App\Models\SourceDocument;
 use App\Models\Take;
 use App\Models\VideoManual;
@@ -101,4 +105,49 @@ public function delete(Project $project, VideoManual $manual): void
             DeleteTakeObjectsJob::dispatch($paths); // tx 成功後に media queue へ (重複キーは除去済み)
         }
     }
+
+    /**
+     * 表示用の最新解析 job。stale な失敗 (失敗確定後に scenario 保存が成立) は null を返す。
+     * これにより Show の解析パネルは矛盾した「解析失敗」alert を出さない (T032 / F-1-1)。
+     */
+    public function displayAnalysisJob(VideoManual $manual): ?AnalysisJob
+    {
+        $job = $manual->analysisJobs()->latest('id')->first();
+
+        return $job !== null && $this->isStaleFailure($manual, $job->status, $job->scenario_version_at_terminal)
+            ? null
+            : $job;
+    }
+
+    /** 表示用の最新 kind=render の job (stale 失敗は null)。 */
+    public function displayRenderJob(VideoManual $manual): ?RenderJob
+    {
+        return $this->latestRenderJobForDisplay($manual, RenderKind::Render);
+    }
+
+    /** 表示用の最新 kind=preview の job (stale 失敗は null)。 */
+    public function displayPreviewJob(VideoManual $manual): ?RenderJob
+    {
+        return $this->latestRenderJobForDisplay($manual, RenderKind::Preview);
+    }
+
+    private function latestRenderJobForDisplay(VideoManual $manual, RenderKind $kind): ?RenderJob
+    {
+        $job = $manual->renderJobs()->where('kind', $kind->value)->latest('id')->first();
+
+        return $job !== null && $this->isStaleFailure($manual, $job->status, $job->scenario_version_at_terminal)
+            ? null
+            : $job;
+    }
+
+    /**
+     * 失敗 job が stale か (失敗確定後に scenario 保存が成立 = version が進んだ)。
+     * snapshot が null (旧データ / 非失敗) の場合は not stale = 表示 (保守的に隠さない)。
+     */
+    private function isStaleFailure(VideoManual $manual, JobStatus $status, ?int $versionAtTerminal): bool
+    {
+        return $status === JobStatus::Failed
+            && $versionAtTerminal !== null
+            && $manual->scenario_version > $versionAtTerminal;
+    }
 }
diff --git a/database/migrations/2026_07_14_020000_add_scenario_version_at_terminal_to_job_tables.php b/database/migrations/2026_07_14_020000_add_scenario_version_at_terminal_to_job_tables.php
new file mode 100644
index 0000000..7ca561e
--- /dev/null
+++ b/database/migrations/2026_07_14_020000_add_scenario_version_at_terminal_to_job_tables.php
@@ -0,0 +1,38 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\Schema;
+
+return new class extends Migration
+{
+    /**
+     * analysis_jobs / render_jobs に失敗確定時の manual.scenario_version スナップショットを追加する。
+     *
+     * stale alert 判定用 (T032 / bug-hunt F-1-1)。「失敗確定後に scenario 保存が成立し
+     * scenario_version が進んだ」失敗 job を server 側で stale と判定して alert を抑制する。
+     * nullable: 既存行・非失敗行は null (null = not stale = 保守的に表示)。
+     * scenario_version と同じ unsignedInteger。サービス内で明示代入のみ ($fillable 不含)。
+     */
+    public function up(): void
+    {
+        Schema::table('analysis_jobs', function (Blueprint $table): void {
+            $table->unsignedInteger('scenario_version_at_terminal')->nullable()->after('error');
+        });
+        Schema::table('render_jobs', function (Blueprint $table): void {
+            $table->unsignedInteger('scenario_version_at_terminal')->nullable()->after('error_code');
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::table('analysis_jobs', function (Blueprint $table): void {
+            $table->dropColumn('scenario_version_at_terminal');
+        });
+        Schema::table('render_jobs', function (Blueprint $table): void {
+            $table->dropColumn('scenario_version_at_terminal');
+        });
+    }
+};
diff --git a/resources/js/pages/Manuals/Create.svelte b/resources/js/pages/Manuals/Create.svelte
index 503b580..3b70be9 100644
--- a/resources/js/pages/Manuals/Create.svelte
+++ b/resources/js/pages/Manuals/Create.svelte
@@ -62,6 +62,10 @@
                             bind:value={form.title}
                             error={invalid}
                             aria-describedby={describedBy}
+                            oninput={() => {
+                                // 入力し始めたらその場でタイトルエラーをクリア (次 submit を待たない)
+                                if (form.errors.title) form.clearErrors("title");
+                            }}
                         />
                     {/snippet}
                 </FormField>
diff --git a/tests/Architecture/ScenarioWritePathInventoryTest.php b/tests/Architecture/ScenarioWritePathInventoryTest.php
index 78fce35..cb55497 100644
--- a/tests/Architecture/ScenarioWritePathInventoryTest.php
+++ b/tests/Architecture/ScenarioWritePathInventoryTest.php
@@ -14,7 +14,8 @@
  * | ScenarioService::save() | cuts / scenario_version / status (rendering·analyzing guard 付き) |
  * | ScenarioService::materializeIntoLockedManual() | cuts / scenario_version / status (analyzing→ready のみ) |
  * | AnalysisJobService::trigger() | status (draft·ready→analyzing のみ) |
- * | AnalysisJobService::failJob() | status (analyzing→ready·draft のみ。cuts 有無で決定) |
+ * | AnalysisJobService::failJob() | status (analyzing→ready·draft のみ。cuts 有無で決定。scenario_version は snapshot 読みのみ) |
+ * | VideoManualService::displayXxxJob() | 書き込みなし (stale 判定で scenario_version を読むのみ) |
  * | RenderJobService::trigger() | status (ready→rendering のみ。scenario_version はスナップショット読み) |
  * | RenderJobService::failJob() | status (rendering→ready のみ。kind=render に限る) |
  * | RenderJobService::completeRenderIntoLockedManual() | cuts.cut_length_ms / total_length_ms / status (rendering→published のみ) |
@@ -49,6 +50,11 @@ final class ScenarioWritePathScanner
         'Services/Manual/RenderJobService.php',
         'Services/Manual/RenderPipeline.php',
         'Models/RenderJob.php',
+        // T032: failJob が失敗確定時の scenario_version を job にスナップショット読みする
+        // (書き込むのは scenario_version_at_terminal であり scenario_version ではない)
+        'Services/Manual/AnalysisJobService.php',
+        // T032: stale alert 判定 (displayXxxJob) が manual.scenario_version を読み取る (read-only)
+        'Services/Manual/VideoManualService.php',
     ];
 
     /** 検出 2 の allowlist (app/ 相対パス) */
diff --git a/tests/Feature/Projects/AnalysisPipelineTest.php b/tests/Feature/Projects/AnalysisPipelineTest.php
index 54260be..3f9ff06 100644
--- a/tests/Feature/Projects/AnalysisPipelineTest.php
+++ b/tests/Feature/Projects/AnalysisPipelineTest.php
@@ -359,7 +359,7 @@ function fakeSuccessfulLlm(): void
     expect($manual->cuts()->whereKey($oldCut->id)->exists())->toBeFalse();
 });
 
-test('抽出不能 (実質空の SOP) は failed + ユーザー向け文言', function (): void {
+test('実質空の SOP は failed + tooShort 文言 (画像未対応と弁別)', function (): void {
     [, , , , $document, $job] = pipelineContext();
     Storage::put($document->file_path, '短すぎ'); // min_text_bytes (100) 未満
 
@@ -367,7 +367,8 @@ function fakeSuccessfulLlm(): void
 
     $job->refresh();
     expect($job->status)->toBe(JobStatus::Failed);
-    expect($job->error)->toBe('テキストを抽出できません。画像・スキャンの手順書は現在未対応です。');
+    // 抽出はできたが本文が短いケース → 画像/スキャン (unextractable) とは別文言 (T032 F-1-2)
+    expect($job->error)->toBe('手順書の本文が短すぎます。もう少し詳しい手順書をアップロードしてください。');
 });
 
 test('バイト上限超過の SOP は failed (分割を促す文言)', function (): void {
diff --git a/tests/Feature/Projects/ManualJobSnapshotTest.php b/tests/Feature/Projects/ManualJobSnapshotTest.php
new file mode 100644
index 0000000..491b575
--- /dev/null
+++ b/tests/Feature/Projects/ManualJobSnapshotTest.php
@@ -0,0 +1,80 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\JobStatus;
+use App\Enums\Manual\RenderErrorCode;
+use App\Enums\Manual\VideoManualStatus;
+use App\Models\AnalysisJob;
+use App\Models\RenderJob;
+use App\Models\VideoManual;
+use App\Services\Manual\AnalysisJobService;
+use App\Services\Manual\RenderJobService;
+
+/*
+ * T032 施策2: failJob が失敗確定時の manual.scenario_version を job にスナップショットする。
+ * この snapshot が stale alert 判定 (VideoManualService::displayXxxJob) の順序基準になる。
+ */
+
+test('AnalysisJobService::failJob は失敗確定時の scenario_version を snapshot する', function (): void {
+    $manual = VideoManual::factory()->create([
+        'status' => VideoManualStatus::Analyzing->value,
+        'scenario_version' => 3,
+    ]);
+    $job = AnalysisJob::factory()->forManual($manual)->running()->create();
+
+    $result = app(AnalysisJobService::class)->failJob($job, '解析に失敗しました');
+
+    expect($result)->toBeTrue();
+    $job->refresh();
+    expect($job->status)->toBe(JobStatus::Failed);
+    expect($job->scenario_version_at_terminal)->toBe(3);
+});
+
+test('RenderJobService::failJob (preview) は snapshot を記録し manual.status を触らない', function (): void {
+    $manual = VideoManual::factory()->create([
+        'status' => VideoManualStatus::Ready->value,
+        'scenario_version' => 5,
+    ]);
+    $job = RenderJob::factory()->forManual($manual)->preview()->running()->create();
+
+    $result = app(RenderJobService::class)->failJob($job, RenderErrorCode::Internal, '失敗');
+
+    expect($result)->toBeTrue();
+    $job->refresh();
+    expect($job->status)->toBe(JobStatus::Failed);
+    expect($job->scenario_version_at_terminal)->toBe(5);
+    // preview 失敗では manual を lock 取得するようになるが status は不変 (編集と並走)
+    expect($manual->refresh()->status)->toBe(VideoManualStatus::Ready);
+});
+
+test('RenderJobService::failJob (render) は snapshot を記録し rendering→ready へ復帰する', function (): void {
+    $manual = VideoManual::factory()->create([
+        'status' => VideoManualStatus::Rendering->value,
+        'scenario_version' => 2,
+    ]);
+    $job = RenderJob::factory()->forManual($manual)->running()->create(['scenario_version' => 2]);
+
+    app(RenderJobService::class)->failJob($job, RenderErrorCode::Internal, '失敗');
+
+    $job->refresh();
+    expect($job->scenario_version_at_terminal)->toBe(2);
+    expect($manual->refresh()->status)->toBe(VideoManualStatus::Ready);
+});
+
+test('terminal 済み job への再 failJob は no-op (snapshot 不変)', function (): void {
+    $manual = VideoManual::factory()->create([
+        'status' => VideoManualStatus::Ready->value,
+        'scenario_version' => 4,
+    ]);
+    // 既に失敗確定 (snapshot=1 の旧世代) の job。scenario_version はその後 4 まで進んでいる
+    $job = AnalysisJob::factory()->forManual($manual)->failed()->create([
+        'scenario_version_at_terminal' => 1,
+    ]);
+
+    $result = app(AnalysisJobService::class)->failJob($job, '再失敗');
+
+    expect($result)->toBeFalse();
+    // terminal guard で早期 return: snapshot も status も上書きされない
+    expect($job->refresh()->scenario_version_at_terminal)->toBe(1);
+});
diff --git a/tests/Feature/Projects/ManualStaleJobDisplayTest.php b/tests/Feature/Projects/ManualStaleJobDisplayTest.php
new file mode 100644
index 0000000..b5c819a
--- /dev/null
+++ b/tests/Feature/Projects/ManualStaleJobDisplayTest.php
@@ -0,0 +1,143 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Manual\ScenarioSaveInput;
+use App\Enums\Manual\JobStatus;
+use App\Enums\Manual\RenderErrorCode;
+use App\Enums\Manual\VideoManualStatus;
+use App\Models\AnalysisJob;
+use App\Models\Project;
+use App\Models\RenderJob;
+use App\Models\User;
+use App\Models\VideoManual;
+use App\Services\Manual\ScenarioService;
+use Inertia\Testing\AssertableInertia as Assert;
+
+/*
+ * T032 施策3/4 (bug-hunt F-1-1): GET projects.manuals.show の Inertia props で
+ * 「失敗確定後に scenario 保存が成立して version が進んだ」失敗 job を stale として抑制する。
+ * 判定 = failed && snapshot!==null && manual.scenario_version > snapshot。
+ */
+
+/**
+ * owner + project + manual のセットアップ。
+ *
+ * @return array{User, Project, VideoManual}
+ */
+function staleDisplayContext(int $scenarioVersion = 1, VideoManualStatus $status = VideoManualStatus::Ready): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create([
+        'status' => $status->value,
+        'scenario_version' => $scenarioVersion,
+    ]);
+
+    return [$owner, $project, $manual];
+}
+
+test('HIGH: 解析失敗後に version が進むと analysis.job は null (stale 抑制)', function (): void {
+    [$owner, $project, $manual] = staleDisplayContext(scenarioVersion: 2);
+    // 失敗確定は version=1 のとき → その後 save で version=2 まで進んだ
+    AnalysisJob::factory()->forManual($manual)->failed()->create([
+        'scenario_version_at_terminal' => 1,
+    ]);
+
+    $this->actingAs($owner)->get(route('projects.manuals.show', [$project, $manual]))
+        ->assertInertia(fn (Assert $page) => $page
+            ->component('Manuals/Show')
+            ->where('analysis.job', null));
+});
+
+test('not stale: version が進んでいない失敗 job は表示する', function (): void {
+    [$owner, $project, $manual] = staleDisplayContext(scenarioVersion: 1);
+    AnalysisJob::factory()->forManual($manual)->failed()->create([
+        'scenario_version_at_terminal' => 1,
+    ]);
+
+    $this->actingAs($owner)->get(route('projects.manuals.show', [$project, $manual]))
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('analysis.job.status', JobStatus::Failed->value));
+});
+
+test('legacy: snapshot=null の失敗 job は null 化されない (保守的に表示)', function (): void {
+    [$owner, $project, $manual] = staleDisplayContext(scenarioVersion: 5);
+    AnalysisJob::factory()->forManual($manual)->failed()->create([
+        'scenario_version_at_terminal' => null,
+    ]);
+
+    $this->actingAs($owner)->get(route('projects.manuals.show', [$project, $manual]))
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('analysis.job.status', JobStatus::Failed->value));
+});
+
+test('render 失敗後に version が進むと render.job は null', function (): void {
+    [$owner, $project, $manual] = staleDisplayContext(scenarioVersion: 3);
+    RenderJob::factory()->forManual($manual)->failed()->create([
+        'scenario_version' => 2,
+        'scenario_version_at_terminal' => 2,
+    ]);
+
+    $this->actingAs($owner)->get(route('projects.manuals.show', [$project, $manual]))
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('render.job', null));
+});
+
+test('scenario_version_changed CTA: snapshot が現行 version と一致なら render.job を保持', function (): void {
+    [$owner, $project, $manual] = staleDisplayContext(scenarioVersion: 4);
+    RenderJob::factory()->forManual($manual)
+        ->failed(RenderErrorCode::ScenarioVersionChanged)
+        ->create([
+            'scenario_version' => 4,
+            'scenario_version_at_terminal' => 4,
+        ]);
+
+    $this->actingAs($owner)->get(route('projects.manuals.show', [$project, $manual]))
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('render.job.status', JobStatus::Failed->value)
+            ->where('render.job.error_code', RenderErrorCode::ScenarioVersionChanged->value));
+});
+
+test('succeeded は version が進んでも抑制されない', function (): void {
+    [$owner, $project, $manual] = staleDisplayContext(scenarioVersion: 9);
+    AnalysisJob::factory()->forManual($manual)->succeeded()->create([
+        'scenario_version_at_terminal' => 1,
+    ]);
+
+    $this->actingAs($owner)->get(route('projects.manuals.show', [$project, $manual]))
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('analysis.job.status', JobStatus::Succeeded->value));
+});
+
+test('preview 独立: preview 失敗が stale でも playbackJobId は succeeded preview を維持', function (): void {
+    [$owner, $project, $manual] = staleDisplayContext(scenarioVersion: 2);
+    // 古い succeeded preview (再生可能) と、その後の失敗 preview (stale)
+    $playable = RenderJob::factory()->forManual($manual)->preview()
+        ->succeeded('previews/out.mp4')->create(['scenario_version' => 1]);
+    RenderJob::factory()->forManual($manual)->preview()->failed()->create([
+        'scenario_version' => 1,
+        'scenario_version_at_terminal' => 1,
+    ]);
+
+    $this->actingAs($owner)->get(route('projects.manuals.show', [$project, $manual]))
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('render.previewJob', null)
+            ->where('render.playbackJobId', $playable->id));
+});
+
+test('統合: ScenarioService::save の実経路 (no-op 保存) で version++ すると解析失敗が stale 化', function (): void {
+    // 保存世代基準の契約 (no-op でも version++ で stale) を実経路で固定する
+    [$owner, $project, $manual] = staleDisplayContext(scenarioVersion: 0);
+    AnalysisJob::factory()->forManual($manual)->failed()->create([
+        'scenario_version_at_terminal' => 0,
+    ]);
+
+    // cuts 無しの no-op 保存 (内容無変更でも version=1 へ)
+    app(ScenarioService::class)->save($project, $manual, new ScenarioSaveInput(0, []));
+    expect($manual->refresh()->scenario_version)->toBe(1);
+
+    $this->actingAs($owner)->get(route('projects.manuals.show', [$project, $manual]))
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('analysis.job', null));
+});
diff --git a/tests/Unit/Manual/SopTextExtractorTest.php b/tests/Unit/Manual/SopTextExtractorTest.php
index f468c70..05e33eb 100644
--- a/tests/Unit/Manual/SopTextExtractorTest.php
+++ b/tests/Unit/Manual/SopTextExtractorTest.php
@@ -82,10 +82,19 @@ function storedDocument(string $contents, string $mime, string $ext): SourceDocu
         ->toThrow(AnalysisFailedException::class, 'テキストを抽出できません');
 });
 
-test('実質空 (min_text_bytes 未満) は unextractable', function (): void {
+test('実質空 (min_text_bytes 未満) は tooShort (画像未対応と別文言)', function (): void {
     Storage::fake();
     $document = storedDocument('短い', 'text/plain', 'txt');
 
+    // 抽出はできたが本文が短すぎるケース。画像/スキャン (unextractable) とは別文言で弁別する
+    expect(fn () => app(SopTextExtractor::class)->extract($document))
+        ->toThrow(AnalysisFailedException::class, '本文が短すぎます');
+});
+
+test('未知 mime は従来どおり unextractable (テキストを抽出できません)', function (): void {
+    Storage::fake();
+    $document = storedDocument(str_repeat('内容', 100), 'image/png', 'png');
+
     expect(fn () => app(SopTextExtractor::class)->extract($document))
         ->toThrow(AnalysisFailedException::class, 'テキストを抽出できません');
 });
diff --git a/tests/js/pages/ManualsCreate.test.ts b/tests/js/pages/ManualsCreate.test.ts
index 75e61bc..08654da 100644
--- a/tests/js/pages/ManualsCreate.test.ts
+++ b/tests/js/pages/ManualsCreate.test.ts
@@ -1,5 +1,15 @@
-import { describe, expect, it } from "vitest";
-import { render, screen } from "@testing-library/svelte";
+import { beforeEach, describe, expect, it, vi } from "vitest";
+import { fireEvent, render, screen } from "@testing-library/svelte";
+import { reactiveUseForm } from "../support/reactiveUseForm.svelte";
+
+const { formState } = vi.hoisted(() => ({ formState: { current: null as unknown } }));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    useForm: () => formState.current,
+    page: { props: {}, url: "/" },
+}));
+
 import Create from "@/pages/Manuals/Create.svelte";
 
 const baseProps = {
@@ -10,7 +20,17 @@ const baseProps = {
     ],
 };
 
+/** 反応的フェイクフォームを毎テスト用意する (errors は $state で clearErrors 再描画を観測可能) */
+function setupForm(errors: Record<string, string> = {}): void {
+    formState.current = reactiveUseForm(
+        { title: "", category: "", document: null as File | null },
+        errors,
+    );
+}
+
 describe("Manuals/Create", () => {
+    beforeEach(() => setupForm());
+
     it("タイトル入力とカテゴリ選択 (未分類既定) を描画する", () => {
         render(Create, { props: baseProps });
 
@@ -49,4 +69,33 @@ describe("Manuals/Create", () => {
         expect(input.getAttribute("type")).toBe("file");
         expect(input.getAttribute("accept")).toBe(".pdf,.xlsx,.xls,.txt");
     });
+
+    it("タイトル入力 (oninput) でタイトルエラーがその場でクリアされる", async () => {
+        setupForm({ title: "タイトルを入力してください" });
+        render(Create, { props: baseProps });
+
+        // 初期はエラー文言が表示されている
+        expect(screen.getByText("タイトルを入力してください")).toBeInTheDocument();
+
+        const title = screen.getByLabelText(/タイトル/);
+        await fireEvent.input(title, { target: { value: "ネ" } });
+
+        // clearErrors("title") が呼ばれ、$state 反応で文言が消える
+        expect(
+            (formState.current as { clearErrors: ReturnType<typeof vi.fn> }).clearErrors,
+        ).toHaveBeenCalledWith("title");
+        expect(screen.queryByText("タイトルを入力してください")).toBeNull();
+    });
+
+    it("タイトルエラーが無いとき oninput は clearErrors を呼ばない", async () => {
+        setupForm();
+        render(Create, { props: baseProps });
+
+        const title = screen.getByLabelText(/タイトル/);
+        await fireEvent.input(title, { target: { value: "ネジ" } });
+
+        expect(
+            (formState.current as { clearErrors: ReturnType<typeof vi.fn> }).clearErrors,
+        ).not.toHaveBeenCalled();
+    });
 });
diff --git a/tests/js/support/reactiveUseForm.svelte.ts b/tests/js/support/reactiveUseForm.svelte.ts
new file mode 100644
index 0000000..e4aef57
--- /dev/null
+++ b/tests/js/support/reactiveUseForm.svelte.ts
@@ -0,0 +1,43 @@
+import { vi } from "vitest";
+
+/**
+ * 反応的な useForm フェイク (.svelte.ts なので $state が使える)。
+ *
+ * fakeUseForm は errors が非反応な plain object のため「clearErrors で赤枠/文言が消える」
+ * 再描画を観測できない。本フェイクは errors を $state で持ち、clearErrors がキーを削除すると
+ * バインド先 (FormField の error prop) が再評価される = ユーザー体験と同じ挙動を検証できる。
+ */
+export function reactiveUseForm<TData extends Record<string, unknown>>(
+  initial: TData,
+  initialErrors: Record<string, string> = {},
+): TData & {
+  errors: Record<string, string>;
+  processing: boolean;
+  clearErrors: (...keys: string[]) => void;
+  transform: (fn: (data: TData) => unknown) => { post: ReturnType<typeof vi.fn> };
+  post: ReturnType<typeof vi.fn>;
+} {
+  const errors = $state<Record<string, string>>({ ...initialErrors });
+  const post = vi.fn();
+
+  const form = {
+    ...initial,
+    get errors() {
+      return errors;
+    },
+    processing: false,
+    clearErrors: vi.fn((...keys: string[]) => {
+      if (keys.length === 0) {
+        for (const key of Object.keys(errors)) delete errors[key];
+        return;
+      }
+      for (const key of keys) delete errors[key];
+    }),
+    transform() {
+      return { post };
+    },
+    post,
+  };
+
+  return form;
+}

```

## user: テスト結果

- `composer test` (Pest --parallel, RefreshDatabase): **1622 passed, 2 skipped, 0 failed** (6808 assertions)
- `composer phpstan` (level 10): **No errors**
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck`: passed
- `pnpm test` (vitest): **527 passed**
- `pnpm build`: 成功

新規/更新テスト:
- `tests/Feature/Projects/ManualStaleJobDisplayTest.php` (9 ケース: HIGH stale 抑制 / not-stale / legacy null / render null / scenario_version_changed CTA 保持 / succeeded 非抑制 / preview 独立 playbackJobId 維持 / ScenarioService::save 実経路の no-op 保存で version++ → stale 化)
- `tests/Feature/Projects/ManualJobSnapshotTest.php` (4 ケース: analysis/render/preview snapshot 記録・preview status 不変・terminal 再 failJob no-op)
- `tests/Unit/Manual/SopTextExtractorTest.php` (実質空 → tooShort 文言 / 未知 mime → unextractable 維持)
- `tests/Feature/Projects/AnalysisPipelineTest.php` (実質空 SOP の pipeline error 文言を tooShort に更新)
- `tests/js/pages/ManualsCreate.test.ts` + `tests/js/support/reactiveUseForm.svelte.ts` (oninput でタイトルエラーがクリアされる / エラーが無いとき clearErrors を呼ばない)
- `tests/Architecture/ScenarioWritePathInventoryTest.php` (検出1 の scenario_version 読み取り allowlist に AnalysisJobService / VideoManualService を登録)

## user: design system / Atomic 参照

- 変更した Svelte は `resources/js/pages/Manuals/Create.svelte` のみ。既存 `Input` atom (`components/atoms/Input.svelte`) の `oninput` を `...rest` 透過で渡すだけで、新規 hex / SVG / コンポーネントは無い。状態(errors)は page が保持し atom は無状態のまま。
- 参考: Input は FormField(molecule) の children snippet 経由で使われ、error 表示は FormField が担う。

以上をレビューし、全体判定を APPROVED / CHANGES_REQUESTED で示せ。
