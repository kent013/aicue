【使命】AI-CUE: SOP 起点に AI が動画シナリオ生成、PWA 撮影で標準化マニュアル動画。思考ゼロ・編集ゼロ。
【禁止事項】1 テストなし完了禁止 / 2 PHPStan widen 禁止 / 4 response()->json 直書き禁止 / 5 DatabaseTransactions 個別禁止。
【ドメイン規約】シナリオ整合の共有ロック規約: cuts/scenario_version/status を書く経路は対象 VideoManual を lockForUpdate した同一 tx 内で反映。ScenarioWritePathInventoryTest (deny-by-default 静的走査) が inventory 強制。
【ツール制限】コマンド実行・書き込み禁止。読み込み可。
---
あなたは Laravel + Svelte 改善実装のコードレビュアーです。観点: 設計一致 / 正確性 / PHPStan L10 / DTO・JsonResource / テスト網羅 / セキュリティ(ロック規約) / DESIGN.md / Atomic(該当時)。出力: ファイルごと判定、[Critical]/[Warning]/[Suggestion]、Critical/Warning に修正案、全体判定 APPROVED/CHANGES_REQUESTED、日本語。

---

## 詳細設計書(要点)
duplicate() で複製 manual の status=VideoManualStatus::Draft / scenario_version=0 を INSERT 時に明示代入 (DB default 依存を排除)。ScenarioWritePathInventoryTest の STATUS_WRITE_ALLOWED に VideoManualService.php を追加 (file 単位 = 既存 3 ファイルと同粒度。テスト自身のコメントが本変更を想定)、SCENARIO_VERSION_ALLOWED は既に含まれるため read/write 両理由をコメント追記。ManualDuplicateTest に振る舞いテスト(元 status/version に関わらず Draft/0・created_by・元 manual 不変)と契約テスト(Reflection でメソッドソースに明示代入があることを機械的に要求 = fail-first)を追加。純 backend。

## 実装差分（git diff）
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
index a29c8bc..b8c3c0b 100644
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
diff --git a/tests/Feature/Projects/ManualDuplicateTest.php b/tests/Feature/Projects/ManualDuplicateTest.php
index 2db8901..2fabd3a 100644
--- a/tests/Feature/Projects/ManualDuplicateTest.php
+++ b/tests/Feature/Projects/ManualDuplicateTest.php
@@ -14,7 +14,9 @@
 use App\Models\Take;
 use App\Models\VideoManual;
 use App\Services\Manual\CutSequencer;
+use App\Services\Manual\VideoManualService;
 use Illuminate\Support\Facades\Log;
+use Webmozart\Assert\Assert;
 
 /*
  * VideoManual 複製 (別名保存)。保存済み cuts を雛形に新タイトル・カテゴリで新規作成する。
@@ -112,6 +114,52 @@ function seedScenario(VideoManual $manual): void
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
+test('duplicate() は複製 manual の status/scenario_version を明示代入する (DB default 非依存の契約)', function (): void {
+    // 振る舞いテストは DB default により実装前でも成功するため、明示代入の存在を
+    // メソッドソースから機械的に要求する (fail-first + 明示代入削除の検出)。
+    $method = new ReflectionMethod(VideoManualService::class, 'duplicate');
+    $fileName = $method->getFileName();
+    $startLine = $method->getStartLine();
+    $endLine = $method->getEndLine();
+    Assert::string($fileName);
+    Assert::integer($startLine);
+    Assert::integer($endLine);
+    $lines = file($fileName);
+    Assert::isArray($lines);
+    $body = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
+
+    // 新規行の初期値を DB default に委ねず、enum/0 を明示代入していること
+    expect($body)->toContain("'status' => VideoManualStatus::Draft");
+    expect($body)->toContain("'scenario_version' => 0");
+});
+
 test('複製は category 未指定なら未分類で作成される', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
     $project = Project::factory()->forOrganization($organization)->create();
```

## テスト結果
- ManualDuplicateTest + ScenarioWritePathInventoryTest: 21 passed (契約テストは実装前 fail を確認済み = fail-first)
- composer test 全体: 1788 passed / 2 skipped / 0 failed
- composer phpstan: No errors / pint: passed
- pnpm typecheck / lint / build / test(776): OK (JS 変更なし)
