Round 2 の Warning に対応しました。再レビューし全体判定を返してください。

## [Warning] scenario_version 契約が write を保証していない → 対応
検出 4b (containsAdoptedTakeIdWrite) と同型の `containsScenarioVersionWrite()` を scanner に追加しました (配列キー `'scenario_version' =>` / プロパティ `->scenario_version =` の write 形のみ検出。displayXxxJob の read は非検出)。契約テストを containsScenarioVersionToken → containsScenarioVersionWrite に変更。`'scenario_version' => 0` を削除すると write が消えてテストが fail します (fail-first)。scanner 自己検証テスト (array/property write=true、read/comment=false) も追加。

## [Suggestion] テスト名/コメントを検査範囲に合わせる → 対応
テスト名を「VideoManualService に status/scenario_version の明示 write が実在する」に、コメントも検査範囲 (ファイル全体) に一致させました。

## 変更後の差分 (tests のみ。app 側は Round 1 から不変)
```diff
diff --git a/tests/Architecture/ScenarioWritePathInventoryTest.php b/tests/Architecture/ScenarioWritePathInventoryTest.php
index a29c8bc..222da4a 100644
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
@@ -183,6 +189,45 @@ public static function containsScenarioVersionToken(string $source): bool
         return false;
     }
 
+    /**
+     * scenario_version の書き込み形の検出 (検出 4b と同型。read と区別する):
+     * - `'scenario_version' => <式>` (forceFill/update の配列キー)
+     * - `->scenario_version = <式>` (プロパティ代入)
+     * displayXxxJob の read (`$manual->scenario_version` の参照) は検出しない。
+     */
+    public static function containsScenarioVersionWrite(string $source): bool
+    {
+        $tokens = token_get_all($source);
+        $count = count($tokens);
+
+        for ($i = 0; $i < $count; $i++) {
+            $token = $tokens[$i];
+
+            // パターン A: 'scenario_version' => ...
+            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING
+                && self::stringLiteralEquals($token[1], 'scenario_version')) {
+                $j = self::nextNonWhitespace($tokens, $i);
+                if ($j !== null && is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_ARROW) {
+                    return true;
+                }
+            }
+
+            // パターン B: ->scenario_version = ...
+            if (is_array($token) && $token[0] === T_OBJECT_OPERATOR) {
+                $j = self::nextNonWhitespace($tokens, $i);
+                if ($j === null || ! is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING || $tokens[$j][1] !== 'scenario_version') {
+                    continue;
+                }
+                $k = self::nextNonWhitespace($tokens, $j);
+                if ($k !== null && $tokens[$k] === '=') {
+                    return true;
+                }
+            }
+        }
+
+        return false;
+    }
+
     /**
      * 書き込み形の検出:
      * - `'status' => <式>` の式内 (depth 0 の `,`/`]`/`)` まで) に VideoManualStatus 識別子
@@ -628,6 +673,44 @@ class N {}
     expect(ScenarioWritePathScanner::containsAdoptedTakeIdWrite($captureTakeService))->toBeTrue();
 });
 
+test('T066: VideoManualService に status/scenario_version の明示 write が実在する (allowlist の degenerate PASS 防止 + 明示代入の fail-first 契約)', function (): void {
+    // duplicate() は複製 manual の初期状態を DB default に委ねず status=Draft / scenario_version=0 を
+    // 明示 write する。その **write 形** が VideoManualService 内に実在することを token ベースで担保する
+    // (明示代入を消すと write が消え、この契約テストが fail = fail-first。STATUS_WRITE_ALLOWED /
+    //  SCENARIO_VERSION_ALLOWED の degenerate = 未使用 allowlist 化も防ぐ)。
+    // scenario_version は displayXxxJob の read があるため token 出現では区別できず、write 形で判定する。
+    $appDir = ScenarioWritePathScanner::appDir();
+    $videoManualService = (string) file_get_contents($appDir.'/Services/Manual/VideoManualService.php');
+
+    expect(ScenarioWritePathScanner::containsStatusWrite($videoManualService))->toBeTrue();
+    expect(ScenarioWritePathScanner::containsScenarioVersionWrite($videoManualService))->toBeTrue();
+});
+
+test('scanner 自己検証: scenario_version の書き込み形を検出し read/コメントは無視する', function (): void {
+    $arrayWrite = <<<'PHP'
+<?php
+class SVA { public function go($m): void { $m->forceFill(['scenario_version' => 0])->save(); } }
+PHP;
+    $propertyWrite = <<<'PHP'
+<?php
+class SVB { public function go($m): void { $m->scenario_version = 0; } }
+PHP;
+    $read = <<<'PHP'
+<?php
+class SVC { public function go($m): int { return $m->scenario_version; } }
+PHP;
+    $comment = <<<'PHP'
+<?php
+// $m->forceFill(['scenario_version' => 0]) や $m->scenario_version = 0 はコメント
+class SVD {}
+PHP;
+
+    expect(ScenarioWritePathScanner::containsScenarioVersionWrite($arrayWrite))->toBeTrue();
+    expect(ScenarioWritePathScanner::containsScenarioVersionWrite($propertyWrite))->toBeTrue();
+    expect(ScenarioWritePathScanner::containsScenarioVersionWrite($read))->toBeFalse();
+    expect(ScenarioWritePathScanner::containsScenarioVersionWrite($comment))->toBeFalse();
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

## テスト結果
- ManualDuplicateTest + ScenarioWritePathInventoryTest: 22 passed (自己検証テスト追加)
- composer phpstan: No errors / pint: passed
