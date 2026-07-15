Round 1 の Critical/Warning に対応しました。再レビューし全体判定を返してください。

## [Critical] Reflection 文字列検査は brittle → 対応 (手法差し替え)
Feature テストの Reflection 文字列一致検査を削除し、ScenarioWritePathInventoryTest の既存 "degenerate PASS 防止" パターン (adopted_take_id の write 実在テストと同型) に倣った token ベースの契約テストへ差し替えました。`ScenarioWritePathScanner::containsStatusWrite(VideoManualService source)` が true (= duplicate() に status 明示 write が実在) を要求。token 走査なので整形に頑健、実装形状ではなく inventory 契約を固定します。明示代入を消すと fail (fail-first) かつ STATUS_WRITE_ALLOWED が degenerate 化するのを防ぎます。

## [Warning] Webmozart\Assert 過剰 → 対応
Reflection テスト撤去に伴い Assert / VideoManualService の import も削除。

## [Warning] status を ->value に → 反論 (詳細設計レビューで合意済み)
コードベースの status 書き込みは全て enum インスタンス (ScenarioService L141/268/273・RenderJobService・AnalysisJobService。`->value` は 0 件)。cast 済みで enum を forceFill が canonical。詳細設計レビュー Round 2 で本反論は受理済みです。

## [Suggestion] T066 集約 → 見送り (allowlist と docblock 双方に理由を書くのは監査性のため意図的)

## 変更後の差分 (git diff)
```diff
diff --git a/app/Services/Manual/VideoManualService.php b/app/Services/Manual/VideoManualService.php
index bb96382..a4934a5 100644
--- a/app/Services/Manual/VideoManualService.php
+++ b/app/Services/Manual/VideoManualService.php
@@ -7,6 +7,7 @@
 use App\Enums\Manual\CutType;
 use App\Enums\Manual\JobStatus;
 use App\Enums\Manual\RenderKind;
+use App\Enums\Manual\VideoManualStatus;
 use App\Jobs\Capture\DeleteTakeObjectsJob;
 use App\Models\AnalysisJob;
 use App\Models\Cut;
@@ -58,15 +59,18 @@ public function create(Project $project, string $title, ?int $categoryId, int $u
     /**
      * VideoManual の複製 (別名保存)。保存済み cuts (シナリオ) を雛形に、新タイトル・カテゴリで
      * 新規 manual を作る。**takes / adopted_take_id / render 成果物 / source_documents /
-     * analysis_jobs は複製しない** (新規撮影・再合成前提)。status=draft・scenario_version=0
-     * (いずれも DB default) にリセットする。
+     * analysis_jobs は複製しない** (新規撮影・再合成前提)。複製 manual は必ず
+     * status=Draft・scenario_version=0 から開始する (この初期状態を INSERT 時に明示代入し、
+     * DB カラム default に依存しない = 将来の migration default 変更による silent break を防ぐ)。
      *
-     * シナリオ整合の共有ロック規約 (AGENTS.md ドメイン規約 1) の新しい書き込み経路:
-     *  - 元 manual を lockForUpdate してシナリオを一貫読み取り
+     * シナリオ整合の共有ロック規約 (AGENTS.md ドメイン規約 1) の書き込み経路:
+     *  - 元 manual を lockForUpdate してシナリオを一貫読み取り (read/copy の一貫性を確保)
      *  - cuts の書き込み先は**新規** manual。新 manual を save() 後に同一 tx 内で
      *    lockForUpdate 再取得し、その locked インスタンスの relation 経由で cut を作成する
      *    (「対象 VideoManual 行を lockForUpdate で取得した同一 tx 内で反映」を literal に満たす)
-     *  - scenario_version / status のリテラル書き込みはしない (新規行は DB default 依存)
+     *  - scenario_version / status は新 manual の INSERT 時に明示代入する (新規行生成のため
+     *    lockForUpdate 前だが、その tx が生成した排他的新規行であり既存行への並行書き込みではない)。
+     *    ScenarioWritePathInventoryTest の STATUS_WRITE_ALLOWED / SCENARIO_VERSION_ALLOWED に登録済み
      */
     public function duplicate(Project $project, VideoManual $source, string $title, ?int $categoryId, int $userId): VideoManual
     {
@@ -77,9 +81,15 @@ public function duplicate(Project $project, VideoManual $source, string $title,
             /** @var VideoManual $lockedSource */
             $lockedSource = $locked->manuals()->whereKey($source->id)->lockForUpdate()->firstOrFail();
 
-            // 新 manual (status/scenario_version は DB default = draft/0)。created_by はサーバ導出
+            // 新 manual: status=Draft / scenario_version=0 を INSERT 時に明示代入して
+            // 不変条件をアプリ層で固定する (DB default 依存をやめ silent break を防ぐ)。
+            // created_by はサーバ導出。すべて排他的新規行 (並行書き込みなし) の初期値。
             $new = $locked->manuals()->make(['title' => $title]);
-            $new->forceFill(['created_by' => $userId])->save();
+            $new->forceFill([
+                'created_by' => $userId,
+                'status' => VideoManualStatus::Draft,
+                'scenario_version' => 0,
+            ])->save();
             if ($categoryId !== null) {
                 // 保存時再解決: 既存 create() と同一の firstOrFail。通常の不正/他 project category は
                 // FormRequest の Rule::exists で 422 (検証時) に落ち、ここで 404 になるのは
diff --git a/tests/Architecture/ScenarioWritePathInventoryTest.php b/tests/Architecture/ScenarioWritePathInventoryTest.php
index a29c8bc..0d9111b 100644
--- a/tests/Architecture/ScenarioWritePathInventoryTest.php
+++ b/tests/Architecture/ScenarioWritePathInventoryTest.php
@@ -17,9 +17,10 @@
  * | AnalysisJobService::failJob() | status (analyzing→ready·draft のみ。cuts 有無で決定。scenario_version は snapshot 読みのみ) |
  * | VideoManualService::displayXxxJob() | 書き込みなし (stale 判定で scenario_version を読むのみ) |
  * | VideoManualService::duplicate() | cuts (lockForUpdate 済みの新 manual 経由で作成)。元 manual を
- *   lockForUpdate して一貫読み取り。scenario_version/status/adopted_take_id のリテラル書き込みは
- *   しない (新規行は DB default 依存) ため検出 1/2/4 は非対象 = allowlist 変更不要。将来 duplicate が
- *   status を書くよう変わったら検出 2 の STATUS_WRITE_ALLOWED への追加が必要になる |
+ *   lockForUpdate して一貫読み取り。複製 manual の INSERT 時に status=Draft / scenario_version=0 を
+ *   明示代入する (新規行生成 = lockForUpdate 前だが、その tx が生成した排他的新規行・同一 tx 内反映で
+ *   既存行への並行書き込みではない)。検出 1 (scenario_version) は SCENARIO_VERSION_ALLOWED、
+ *   検出 2 (status) は STATUS_WRITE_ALLOWED に登録済み。検出 4 (adopted_take_id) は複製しないため非対象 |
  * | RenderJobService::trigger() | status (ready→rendering のみ。scenario_version はスナップショット読み) |
  * | RenderJobService::failJob() | status (rendering→ready のみ。kind=render に限る) |
  * | RenderJobService::completeRenderIntoLockedManual() | cuts.cut_length_ms / total_length_ms / status (rendering→published のみ) |
@@ -57,7 +58,9 @@ final class ScenarioWritePathScanner
         // T032: failJob が失敗確定時の scenario_version を job にスナップショット読みする
         // (書き込むのは scenario_version_at_terminal であり scenario_version ではない)
         'Services/Manual/AnalysisJobService.php',
-        // T032: stale alert 判定 (displayXxxJob) が manual.scenario_version を読み取る (read-only)
+        // VideoManualService は 2 理由で許可: (1) T032 stale alert 判定 (displayXxxJob) が
+        // manual.scenario_version を read (read-only)。(2) T066 duplicate() が複製 manual の
+        // INSERT 時に scenario_version=0 を明示 write (新規行生成 + 同一 tx。既存行への並行 write ではない)
         'Services/Manual/VideoManualService.php',
     ];
 
@@ -68,6 +71,9 @@ final class ScenarioWritePathScanner
         // trigger: ready→rendering / failJob: rendering→ready / complete...: rendering→published。
         // RenderPipeline は VideoManualStatus を直接書かない (全て Service メソッド経由)
         'Services/Manual/RenderJobService.php',
+        // T066: duplicate() が複製 manual の INSERT 時に status=Draft を明示代入
+        // (新規行生成 + 同一 tx。既存行への並行書き込みではないためロック規約の趣旨に整合)
+        'Services/Manual/VideoManualService.php',
     ];
 
     /**
@@ -628,6 +634,18 @@ class N {}
     expect(ScenarioWritePathScanner::containsAdoptedTakeIdWrite($captureTakeService))->toBeTrue();
 });
 
+test('T066: VideoManualService::duplicate() が status を明示 write する (allowlist の degenerate PASS 防止 + 明示代入の fail-first 契約)', function (): void {
+    // duplicate() は複製 manual の初期状態を DB default に委ねず status=Draft を明示代入する。
+    // その write が実在することを token ベースで担保する (明示代入を消すと STATUS_WRITE_ALLOWED
+    // への追加が degenerate = 未使用 allowlist になり、この契約テストが fail する)。
+    $appDir = ScenarioWritePathScanner::appDir();
+    $videoManualService = (string) file_get_contents($appDir.'/Services/Manual/VideoManualService.php');
+
+    expect(ScenarioWritePathScanner::containsStatusWrite($videoManualService))->toBeTrue();
+    // scenario_version も同 forceFill で明示代入する (read/write いずれの出現でも token は真)
+    expect(ScenarioWritePathScanner::containsScenarioVersionToken($videoManualService))->toBeTrue();
+});
+
 test('scanner 自己検証: cast 宣言 (VideoManualStatus::class) と読み取りは検出しない', function (): void {
     $cast = <<<'PHP'
 <?php
diff --git a/tests/Feature/Projects/ManualDuplicateTest.php b/tests/Feature/Projects/ManualDuplicateTest.php
index 2db8901..727cd47 100644
--- a/tests/Feature/Projects/ManualDuplicateTest.php
+++ b/tests/Feature/Projects/ManualDuplicateTest.php
@@ -112,6 +112,37 @@ function seedScenario(VideoManual $manual): void
     }
 });
 
+test('複製は元 manual の status/version に関わらず必ず Draft/0 を明示代入し、元 manual は不変', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    // 元 manual を default とは異なる進行状態にする (rendering / version 9)
+    $source = VideoManual::factory()->forProject($project)->create([
+        'title' => '進行中元',
+        'status' => VideoManualStatus::Rendering->value,
+        'scenario_version' => 9,
+    ]);
+
+    $this->actingAs($owner)->post("/projects/{$project->id}/manuals/{$source->id}/duplicate", [
+        'title' => '不変条件確認',
+    ])->assertSessionHas('success');
+
+    /** @var VideoManual $copy */
+    $copy = $project->manuals()->where('id', '!=', $source->id)->firstOrFail();
+    // 複製先は default に依存せず Draft/0
+    expect($copy->status)->toBe(VideoManualStatus::Draft);
+    expect($copy->scenario_version)->toBe(0);
+    // created_by は複製実行者由来 (duplicate の契約を明文化)
+    expect($copy->created_by)->toBe($owner->id);
+    // 元 manual は不変 (複製は元を書き換えない)
+    $source->refresh();
+    expect($source->status)->toBe(VideoManualStatus::Rendering);
+    expect($source->scenario_version)->toBe(9);
+});
+
+// 注: 「明示代入が存在すること (DB default 非依存)」の fail-first 契約は、振る舞いだけでは
+// DB default で pass してしまうため、ScenarioWritePathInventoryTest の degenerate PASS 防止
+// (token ベースで VideoManualService に status 書き込みが実在することを要求) で担保する。
+
 test('複製は category 未指定なら未分類で作成される', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
     $project = Project::factory()->forOrganization($organization)->create();
```

## テスト結果 (再実行)
- ManualDuplicateTest + ScenarioWritePathInventoryTest: 21 passed
- composer phpstan: No errors / pint: passed
