Round 1 の指摘への対応を報告する。対応マトリクスと、指摘に関係する 4 ファイルの
**Round 1 提示時からの通算差分** (main からの diff) を添える。

# 対応マトリクス: impl-review Round 1

## [Warning] `ClaudeHooksWiringTest` S12c が union 全体の非空しか見ておらず、代表を持たない glob (とくに `.github/workflows/*`) の故障を検出できない

- 判断: 対応する
- 根拠: 指摘のとおり。走査域は 7 本の glob の和集合なので、1 本だけ綴りが壊れても他が非空なら
  S12c は緑のままになる。これは本 TODO が塞ごうとしている「空振りしても緑」そのものである。
- 対応内容: `CLAUDE_HOOKS_TOOL_SELFWIRING_SCAN_GLOBS` を
  **glob => 代表ファイル | null** の写像へ変えた (代表ファイル = 非空が契約 / `null` = 当たらない
  ことが正常。3 通り目を作らない)。S12c は glob ごとに代表ファイルを当てているかを見る。
  `.github/workflows/*` には `ci.yml` を代表として割り当て、
  scripts の下位ディレクトリを見る glob だけを `null` にして理由を docblock へ書いた。
  併せて glob 1 本ぶんを返す `claudeHooksSelfWiringGlobFiles()` を切り出し、
  負のコントロールも glob ごとに空になることを見るようにした。
  赤の確認: `.github/workflows/*` の綴りを壊すと S12c が
  「glob [...] が代表ファイル ... を当てていません」で赤くなることを実行して確認済み。

## [Warning] `AppNameHardcodeTest` は slug が既定値 'app' の間、判定経路が一度も実行されない (負のコントロールはファイル列挙までしか裏取りしていない)

- 判断: 対応する
- 根拠: 指摘のとおり。列挙が生きていることと、判定が生きていることは別である。
  slug を設定した瞬間に効く判定が壊れたまま緑、という状態を許してしまう。
- 対応内容: 判定を `appSlugHardcodeViolations(array $roots, string $needle)` へ分離し、
  「自己検査」ケースで両方向を固定した。
  - 当たる語: `declare(strict_types=1);` (app/ 配下の PHP は全数が宣言している。
    その事実は `StrictTypesDeclarationGateTest` が deny-by-default で強制している) → 非空
  - 当たらない語: このリポジトリのどこにも書かれていない語 → 空
  赤の確認: 判定の `str_contains` を潰すと自己検査が赤くなることを実行して確認済み。
  分類メモ側にも「裏取りが押さえる範囲」を追記し、誇張しないよう限定した。

## [Suggestion] `ProjectMemberPivotWriteScanner::findViolations()` の戻り値を `findDetections()` と同じ固定 array shape にする

- 判断: 対応する
- 根拠: 2 種別を必ず返す契約が型に出るほうがよい。コストも小さい。
- 対応内容: docblock を
  `array{project_members_literal: list<string>, members_relation_write: list<string>}` にし、
  実装も foreach ではなく 2 キーを明示的に組み立てる形へ変えた (PHPStan level 10 で No errors)。

## [Suggestion] inline 側の床値 200 に実測件数の記録がない

- 判断: 対応する
- 根拠: 床値の余裕がどれだけあるかは、次にこの値を触る人が必要とする情報である。
- 対応内容: 実測して床値とコメントを揃えた。
  - `ValidationAttributeCoverageTest` の inline 母集団: 床値 400 (実測 793 件)
  - `ProjectMemberPivotWritePathTest` の走査ファイル: 床値 400 (実測 827 件)
  他 2 か所 (FormRequest 34 / Model 40) は元から実測値を書いてある。


## 差分 (main からの diff。指摘に関係する 4 ファイルのみ)

```diff
diff --git a/tests/Architecture/AppNameHardcodeTest.php b/tests/Architecture/AppNameHardcodeTest.php
index f91a36ee..e412caa0 100644
--- a/tests/Architecture/AppNameHardcodeTest.php
+++ b/tests/Architecture/AppNameHardcodeTest.php
@@ -9,18 +9,98 @@
  * コード中に slug を直書きすると、テンプレート派生アプリ間の copy-paste で別アプリの
  * 名前が混入する事故が起きる (spirux の tests/bootstrap.php に aigenba- が残っていた実例)。
  *
- * 検査: app/ routes/ database/ tests/ resources/js/ scripts/ の中に
+ * 検査: app/ routes/ database/ resources/js/ scripts/ の中に
  * config('template.slug') 以外の経路で slug 既定値が現れないこと。
  * 既定 slug は 'app' で一般語のため、ここでは「.env.example の TEMPLATE_APP_SLUG 値」を
  * 動的に取得して走査する (アプリが slug を変更した後も機能する)。
+ *
+ * 空振り検査 (AGENTS.md §静的検査 (gate) と走査器の共通規約 (b) の
+ * 「違反が 0 件」と「母集団が 0 件」の区別): 本 gate は**走査根の非空が不変条件**である。
+ * 5 本の走査根はどれか 1 本が改名・移動しても違反ゼロのまま緑になるため、
+ * 「空振り検査」ケースが 5 本すべての生存 (実在かつファイルを持つ) を固定し、
+ * その直後の負のコントロールが「根を差し替えると母集団が空になる」ことを示す。
+ *
+ * ★slug が既定値 'app' のままの間、**判定は一般語の誤検出を避けるため意図的に走らない**。
+ *   その間も判定そのものが壊れていないことを示すため、判定を
+ *   `appSlugHardcodeViolations()` へ分離し、**当たる語**と**当たらない語**の両方向を
+ *   実在の走査根に対して裏取りする (「自己検査」ケース)。
+ *   派生アプリが固有 slug を設定した瞬間に、この判定がそのまま働く。
  */
 
-test('アプリ slug がコードにハードコードされていない', function (): void {
+/**
+ * slug 走査の根 (リポジトリ相対パス)。
+ *
+ * @return list<string>
+ */
+function appSlugScanRoots(): array
+{
+    return ['app', 'routes', 'database', 'resources/js', 'scripts'];
+}
+
+/**
+ * 走査根配下の全ファイル (絶対パス)。根が実在しなければ空を返す。
+ *
+ * @return list<string>
+ */
+function appSlugScanFiles(string $absoluteRoot): array
+{
+    if (! is_dir($absoluteRoot)) {
+        return [];
+    }
+
+    $files = [];
+    $iterator = new RecursiveIteratorIterator(
+        new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS)
+    );
+    /** @var SplFileInfo $file */
+    foreach ($iterator as $file) {
+        if ($file->isFile()) {
+            $files[] = $file->getPathname();
+        }
+    }
+    sort($files);
+
+    return $files;
+}
+
+/** .env.example が宣言する TEMPLATE_APP_SLUG の値 (未宣言なら空文字)。 */
+function appSlugFromEnvExample(): string
+{
     $envExample = file_get_contents(base_path('.env.example'));
     expect($envExample)->toBeString();
     /** @var string $envExample */
     preg_match('/^TEMPLATE_APP_SLUG=(.+)$/m', $envExample, $m);
-    $slug = trim($m[1] ?? '');
+
+    return trim($m[1] ?? '');
+}
+
+/**
+ * 走査根の一覧から、与えた語を含むファイル (リポジトリ相対パス) を集める。
+ *
+ * 判定を関数へ分離してあるのは、slug が既定値のままでも**判定そのものを両方向で
+ * 裏取りできるようにする**ためである (下の「自己検査」ケース)。
+ *
+ * @param  list<string>  $roots  走査根 (リポジトリ相対パス)
+ * @return list<string>
+ */
+function appSlugHardcodeViolations(array $roots, string $needle): array
+{
+    $violations = [];
+    foreach ($roots as $root) {
+        foreach (appSlugScanFiles(base_path($root)) as $path) {
+            $contents = file_get_contents($path);
+            if ($contents !== false && str_contains($contents, $needle)) {
+                $violations[] = str_replace(base_path().'/', '', $path);
+            }
+        }
+    }
+    sort($violations);
+
+    return $violations;
+}
+
+test('アプリ slug がコードにハードコードされていない', function (): void {
+    $slug = appSlugFromEnvExample();
 
     // 既定値 'app' は一般語のため走査対象外 (派生アプリが固有 slug を設定した時点で発動する)
     if ($slug === '' || $slug === 'app') {
@@ -29,28 +109,34 @@
         return;
     }
 
-    $directories = ['app', 'routes', 'database', 'resources/js', 'scripts'];
-    $violations = [];
+    $violations = appSlugHardcodeViolations(appSlugScanRoots(), $slug);
 
-    foreach ($directories as $dir) {
-        $path = base_path($dir);
-        if (! is_dir($path)) {
-            continue;
-        }
-        $iterator = new RecursiveIteratorIterator(
-            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
-        );
-        /** @var SplFileInfo $file */
-        foreach ($iterator as $file) {
-            if (! $file->isFile()) {
-                continue;
-            }
-            $contents = file_get_contents($file->getPathname());
-            if ($contents !== false && str_contains($contents, $slug)) {
-                $violations[] = str_replace(base_path().'/', '', $file->getPathname());
-            }
-        }
+    expect($violations)->toBe([], 'slug "'.$slug.'" のハードコードを検出: '.implode(', ', $violations));
+});
+
+test('自己検査: 判定が当たる語を拾い、当たらない語を拾わない', function (): void {
+    // slug が既定値 'app' の間、上の判定は早期 return するので一度も実行されない。
+    // 判定が壊れたまま緑になるのを防ぐため、実在の走査根に対して両方向を固定する。
+    // 当たる語: app/ 配下の PHP は全数が strict_types を宣言している
+    // (StrictTypesDeclarationGateTest が deny-by-default で強制している事実に乗る)。
+    expect(appSlugHardcodeViolations(['app'], 'declare(strict_types=1);'))
+        ->not->toBe([], '判定が「必ず在る語」を拾えていません');
+    // 当たらない語: どのファイルにも書かれていない語では 1 件も拾わない (誤検出しない)。
+    expect(appSlugHardcodeViolations(appSlugScanRoots(), 'slug-that-must-not-exist-in-this-repository'))
+        ->toBe([]);
+});
+
+test('空振り検査: 5 本の走査根がいずれも生きている (実在しファイルを持つ)', function (): void {
+    foreach (appSlugScanRoots() as $root) {
+        $absolute = base_path($root);
+        expect(is_dir($absolute))->toBeTrue("走査根 {$root} が存在しません");
+        expect(appSlugScanFiles($absolute))->not->toBe([], "走査根 {$root} にファイルがありません");
     }
+});
 
-    expect($violations)->toBe([], 'slug "'.$slug.'" のハードコードを検出: '.implode(', ', $violations));
+test('負のコントロール: 走査根を差し替えると母集団が空になる', function (): void {
+    // 上の生存検査が空振りしていないことの裏取り。走査根の改名・移動を模して
+    // 実在しないパスを渡すと母集団が 0 件になる = 生存検査が赤くなる。
+    expect(appSlugScanFiles(base_path('app-renamed')))->toBe([]);
+    expect(appSlugScanFiles(base_path('resources/js-renamed')))->toBe([]);
 });
diff --git a/tests/Architecture/ClaudeHooksWiringTest.php b/tests/Architecture/ClaudeHooksWiringTest.php
index 3d69273e..91d24a2f 100644
--- a/tests/Architecture/ClaudeHooksWiringTest.php
+++ b/tests/Architecture/ClaudeHooksWiringTest.php
@@ -10,8 +10,9 @@
  * 常設 hook 配線の台帳 (deny-by-default) と、hook スクリプトの実挙動ゲート。
  *
  * 本テストは 2 層で構成する:
- *  - 静的層 (S01〜S12b): `.claude/settings.json` が下の台帳と**完全一致**することを見る。
+ *  - 静的層 (S01〜S12c): `.claude/settings.json` が下の台帳と**完全一致**することを見る。
  *    台帳に無い hook・イベント・トップレベルキーはすべて違反 = 配線の正本が 1 か所になる。
+ *    末尾の S12c は S12b の走査域が空振りしていないことの検査である。
  *  - 実起動層 (B01〜B51): hook スクリプトと起動子を**別プロセスで本当に起動**して、
  *    終了コード・標準出力の空・告知の回数・排他・敵対的な検索パス・symlink の置き場での
  *    振る舞いを実証する。静的検査だけでは「書いてあるが効いていない」を検出できない。
@@ -67,16 +68,27 @@
  * S12b の走査対象 (実行面のファイルのみ)。文書は走査しない —
  * 禁止を説明する文章にコマンド名が出るのは正常であり、走査すると必ず落ちるためである。
  *
- * @var list<string>
+ * **glob ごとに「非空が契約か」を申告する** (S12c が使う)。S12b は「禁止語句が 1 件も無いこと」を
+ * 見るので、glob が当たらなくなっても緑になる。union 全体の非空だけを見ると、
+ * 代表を持たない glob の改名・綴り間違い・対象移動を取りこぼす。
+ * 値は次の 2 通りで、**3 通り目を作らない**:
+ *  - 代表ファイルのリポジトリ相対パス = その glob は非空が契約であり、この 1 本が母集団に居ること
+ *  - `null` = 当たらないことが正常な glob (理由を下のコメントに書く)
+ *
+ * `scripts/{下位ディレクトリ}/*.sh` が `null` なのは、恒久スクリプトを下位ディレクトリへ
+ * 置く運用が現在なく、置いたときに走査域へ入るための先回りの glob だからである
+ * (0 件は違反ではない)。**件数そのものは pin しない** (スクリプトの増減は日常の変更である)。
+ *
+ * @var array<string, string|null>
  */
 const CLAUDE_HOOKS_TOOL_SELFWIRING_SCAN_GLOBS = [
-    'scripts/*.sh',
-    'scripts/*/*.sh',
-    '.claude/settings*.json',
-    'docker/Dockerfile',
-    'composer.json',
-    'package.json',
-    '.github/workflows/*',
+    'scripts/*.sh' => 'scripts/bug-hunt-shard.sh',
+    'scripts/*/*.sh' => null,
+    '.claude/settings*.json' => '.claude/settings.json',
+    'docker/Dockerfile' => 'docker/Dockerfile',
+    'composer.json' => 'composer.json',
+    'package.json' => 'package.json',
+    '.github/workflows/*' => '.github/workflows/ci.yml',
 ];
 
 // =============================================================================
@@ -368,6 +380,47 @@ function claudeHooksExpectNotContains(string $haystack, string $needle, string $
     expect(str_contains($haystack, $needle))->toBeFalse("{$reason} (現れてはならない文字列: {$needle})");
 }
 
+/**
+ * S12b の走査域 (リポジトリ相対パスの昇順)。
+ *
+ * @param  string|null  $root  走査根の絶対パス (null = リポジトリルート)。
+ *                             負のコントロールで別の根を渡すために引数化してある
+ * @return list<string>
+ */
+function claudeHooksSelfWiringScanFiles(?string $root = null): array
+{
+    $files = [];
+    foreach (array_keys(CLAUDE_HOOKS_TOOL_SELFWIRING_SCAN_GLOBS) as $glob) {
+        $files = [...$files, ...claudeHooksSelfWiringGlobFiles($glob, $root)];
+    }
+
+    $files = array_values(array_unique($files));
+    sort($files);
+
+    return $files;
+}
+
+/**
+ * glob 1 本が当てるファイル (リポジトリ相対パス)。
+ *
+ * @param  string|null  $root  走査根の絶対パス (null = リポジトリルート)
+ * @return list<string>
+ */
+function claudeHooksSelfWiringGlobFiles(string $glob, ?string $root = null): array
+{
+    $root = rtrim($root ?? base_path(), '/');
+
+    $files = [];
+    foreach (glob($root.'/'.$glob) ?: [] as $path) {
+        if (is_file($path)) {
+            $files[] = ltrim(str_replace($root, '', $path), '/');
+        }
+    }
+    sort($files);
+
+    return $files;
+}
+
 /** 台帳から起動子の実文字列を取り出す (台帳の写しではなく本物を走らせるため)。 */
 function claudeHooksLauncherCommand(string $event): string
 {
@@ -595,20 +648,44 @@ function claudeHooksWriteExitStub(string $path, int $exitCode): void
 test('S12b: 実行面のファイルが索引ツールに配線を書かせる呼び出しを持たないこと', function (): void {
     $violations = [];
 
-    foreach (CLAUDE_HOOKS_TOOL_SELFWIRING_SCAN_GLOBS as $glob) {
-        foreach (glob(base_path($glob)) ?: [] as $path) {
-            if (! is_file($path)) {
-                continue;
-            }
-            if (preg_match('/code-review-graph\s+(install|init|uninstall)\b/', claudeHooksReadFile($path)) === 1) {
-                $violations[] = str_replace(base_path().'/', '', $path);
-            }
+    foreach (claudeHooksSelfWiringScanFiles() as $relative) {
+        if (preg_match('/code-review-graph\s+(install|init|uninstall)\b/', claudeHooksReadFile(base_path($relative))) === 1) {
+            $violations[] = $relative;
         }
     }
 
     expect($violations)->toBe([], "配線の正本が二重化する呼び出しがある:\n".implode("\n", $violations));
 });
 
+test('S12c (空振り検査): S12b の走査域が glob ごとの申告どおりであること', function (): void {
+    $files = claudeHooksSelfWiringScanFiles();
+
+    // 非空: glob がすべて外れても S12b は違反ゼロで緑になる
+    expect($files)->not->toBe([], 'S12b の走査域が空です (glob が 1 つも当たっていません)');
+
+    // glob ごと: 非空が契約の glob は代表ファイルを当てていること。
+    // union 全体だけを見ると、代表を持たない glob が 1 本壊れても他が非空なら緑のままになる。
+    foreach (CLAUDE_HOOKS_TOOL_SELFWIRING_SCAN_GLOBS as $glob => $representative) {
+        $matched = claudeHooksSelfWiringGlobFiles($glob);
+
+        if ($representative === null) {
+            continue; // 当たらないことが正常な glob (理由は台帳の docblock)
+        }
+
+        // `toContain()` は可変長引数なので理由は第 2 引数に渡せない (冒頭のヘルパと同じ理由)
+        expect(in_array($representative, $matched, true))
+            ->toBeTrue("glob [{$glob}] が代表ファイル {$representative} を当てていません");
+    }
+});
+
+test('S12c の負のコントロール: 走査根を差し替えると走査域が空になる', function (): void {
+    // 上の検査が空振りしていないことの裏取り。実在しない根を渡すと 0 件になる。
+    expect(claudeHooksSelfWiringScanFiles(base_path('nonexistent-scan-root')))->toBe([]);
+    foreach (array_keys(CLAUDE_HOOKS_TOOL_SELFWIRING_SCAN_GLOBS) as $glob) {
+        expect(claudeHooksSelfWiringGlobFiles($glob, base_path('nonexistent-scan-root')))->toBe([]);
+    }
+});
+
 // =============================================================================
 // 実起動層: 索引更新 hook (B01〜B25)
 // =============================================================================
diff --git a/tests/Architecture/ProjectMemberPivotWritePathTest.php b/tests/Architecture/ProjectMemberPivotWritePathTest.php
index 60c315f1..4809d707 100644
--- a/tests/Architecture/ProjectMemberPivotWritePathTest.php
+++ b/tests/Architecture/ProjectMemberPivotWritePathTest.php
@@ -14,6 +14,13 @@
  * 検出 A: 文字列リテラル 'project_members' の出現 (DB::table 直書き経路の deny)
  * 検出 B: `members()->attach|detach|sync|syncWithoutDetaching|toggle` の呼び出し形
  * いずれも allowlist 外の app/ コードに現れたら fail。
+ *
+ * 空振り検査 (AGENTS.md §静的検査 (gate) と走査器の共通規約 (b) の
+ * 「違反が 0 件」と「母集団が 0 件」の区別): 本 gate は**母集団の非空が不変条件**である。
+ * 走査根 app/ の移動や token 判定の綻びで検出が 0 件になると、経路が増えても違反ゼロで緑になる。
+ * 「空振り検査」ケースが (1) 走査した PHP ファイルの非空 (2) allowlist の各ファイルが
+ * 実際に検出されていること を固定し、その直後の負のコントロールが
+ * 「走査根を差し替えると検出が空になる」ことを示す。
  */
 
 final class ProjectMemberPivotWriteScanner
@@ -35,33 +42,79 @@ final class ProjectMemberPivotWriteScanner
     ];
 
     /**
-     * @return array<string, list<string>> 検出種別 => 違反ファイル (app/ 相対パス)
+     * @return array{project_members_literal: list<string>, members_relation_write: list<string>}
      */
-    public static function findViolations(): array
+    public static function allowlists(): array
     {
-        $appDir = self::appDir();
-        $violations = [
+        return [
+            'project_members_literal' => self::PROJECT_MEMBERS_LITERAL_ALLOWED,
+            'members_relation_write' => self::MEMBERS_WRITE_ALLOWED,
+        ];
+    }
+
+    /**
+     * 走査根配下で検出したファイルを allowlist で絞らずに返す (空振り検査用)。
+     *
+     * @param  string|null  $rootDirectory  走査根の絶対パス (null = app/)
+     * @return array{project_members_literal: list<string>, members_relation_write: list<string>}
+     */
+    public static function findDetections(?string $rootDirectory = null): array
+    {
+        $root = $rootDirectory ?? self::appDir();
+        $detections = [
             'project_members_literal' => [],
             'members_relation_write' => [],
         ];
 
-        foreach (self::phpFiles($appDir) as $path) {
-            $relative = substr($path, strlen($appDir) + 1);
+        foreach (self::phpFiles($root) as $path) {
+            $relative = substr($path, strlen($root) + 1);
             $source = file_get_contents($path);
             if ($source === false) {
                 throw new RuntimeException("Failed to read PHP source: {$path}");
             }
 
-            if (self::containsProjectMembersLiteral($source)
-                && ! in_array($relative, self::PROJECT_MEMBERS_LITERAL_ALLOWED, true)) {
-                $violations['project_members_literal'][] = $relative;
+            if (self::containsProjectMembersLiteral($source)) {
+                $detections['project_members_literal'][] = $relative;
             }
-            if (self::containsMembersRelationWrite($source)
-                && ! in_array($relative, self::MEMBERS_WRITE_ALLOWED, true)) {
-                $violations['members_relation_write'][] = $relative;
+            if (self::containsMembersRelationWrite($source)) {
+                $detections['members_relation_write'][] = $relative;
             }
         }
 
+        return $detections;
+    }
+
+    /**
+     * 走査した PHP ファイル (絶対パス)。走査根が実在しなければ空を返す。
+     *
+     * @return list<string>
+     */
+    public static function scannedFiles(?string $rootDirectory = null): array
+    {
+        return self::phpFiles($rootDirectory ?? self::appDir());
+    }
+
+    /**
+     * 検出種別 => 違反ファイル (app/ 相対パス)。2 種別を必ず返す。
+     *
+     * @return array{project_members_literal: list<string>, members_relation_write: list<string>}
+     */
+    public static function findViolations(): array
+    {
+        $allowlists = self::allowlists();
+        $detections = self::findDetections();
+
+        $violations = [
+            'project_members_literal' => array_values(array_diff(
+                $detections['project_members_literal'],
+                $allowlists['project_members_literal'],
+            )),
+            'members_relation_write' => array_values(array_diff(
+                $detections['members_relation_write'],
+                $allowlists['members_relation_write'],
+            )),
+        ];
+
         return $violations;
     }
 
@@ -134,7 +187,7 @@ private static function nextMeaningful(array $tokens, int $from): ?int
         return null;
     }
 
-    private static function appDir(): string
+    public static function appDir(): string
     {
         $dir = realpath(__DIR__.'/../../app');
         if ($dir === false) {
@@ -149,6 +202,10 @@ private static function appDir(): string
      */
     private static function phpFiles(string $dir): array
     {
+        if (! is_dir($dir)) {
+            return [];
+        }
+
         $files = [];
         $iterator = new RecursiveIteratorIterator(
             new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
@@ -171,3 +228,32 @@ private static function phpFiles(string $dir): array
     expect($violations['project_members_literal'])->toBe([]);
     expect($violations['members_relation_write'])->toBe([]);
 });
+
+test('空振り検査: 走査の母集団が空でなく、allowlist の各ファイルが実際に検出されている', function (): void {
+    // (1) 走査根が生きていること
+    $scanned = ProjectMemberPivotWriteScanner::scannedFiles();
+    expect($scanned)->not->toBe([], '走査根 app/ に PHP ファイルがありません');
+    expect(count($scanned))->toBeGreaterThanOrEqual(400); // 床値 (実測 827 件)
+
+    // (2) 検出そのものが生きていること。allowlist は「検出されるが許す」ファイルなので、
+    //     検出結果に現れないなら token 判定が壊れている (違反ゼロは検出停止でも成立する)。
+    $detections = ProjectMemberPivotWriteScanner::findDetections();
+    foreach (ProjectMemberPivotWriteScanner::allowlists() as $kind => $allowed) {
+        foreach ($allowed as $relative) {
+            // `toContain()` は可変長引数なので理由は第 2 引数に渡せない (渡すと検索語が増える)
+            expect(in_array($relative, $detections[$kind], true))->toBeTrue(
+                "検出 {$kind} が allowlist の {$relative} を拾えていません (走査が空振りしています)",
+            );
+        }
+    }
+});
+
+test('負のコントロール: 走査根を差し替えると検出が空になる', function (): void {
+    // 上の検査が空振りしていないことの裏取り。走査根の改名・移動を模して
+    // 一致するもののないディレクトリ / 実在しないパスを渡すと検出が 0 件になる。
+    expect(ProjectMemberPivotWriteScanner::findDetections(base_path('config')))->toBe([
+        'project_members_literal' => [],
+        'members_relation_write' => [],
+    ]);
+    expect(ProjectMemberPivotWriteScanner::scannedFiles(base_path('app-renamed')))->toBe([]);
+});
diff --git a/tests/Architecture/ValidationAttributeCoverageTest.php b/tests/Architecture/ValidationAttributeCoverageTest.php
index 3598b6d7..d9eea669 100644
--- a/tests/Architecture/ValidationAttributeCoverageTest.php
+++ b/tests/Architecture/ValidationAttributeCoverageTest.php
@@ -19,6 +19,13 @@
  *
  * 規約: validation の呼び出し経路を追加する場合 (`validator()` helper 等) は、本テストの
  *       検出対象パターンにも必ず追加すること。
+ *
+ * 空振り検査 (AGENTS.md §静的検査 (gate) と走査器の共通規約 (b) の
+ * 「違反が 0 件」と「母集団が 0 件」の区別): 本 gate は**母集団の非空が不変条件**である。
+ * 検査 1 は app/Http/Requests、検査 2 は app/ (Requests を除く) を走査根に持ち、
+ * どちらかが改名・移動すると未登録キーゼロのまま緑になる。末尾の「空振り検査」ケースが
+ * 2 つの母集団の非空・床値・代表要素を固定し、その直後の負のコントロールが
+ * 「走査根を差し替えると母集団が空になる」ことを示す。
  */
 
 /**
@@ -57,13 +64,17 @@
  * (FormRequestProhibitedKeyTest と同一パターン。関数名は Pest のグローバル関数衝突を避け
  * validationCoverage* プレフィックスにする)。
  *
+ * @param  string|null  $base  走査根の絶対パス (null = app/Http/Requests)。
+ *                             負のコントロールで別の根を渡すために引数化してある
  * @return list<class-string<FormRequest>>
  */
-function validationCoverageFormRequestClasses(): array
+function validationCoverageFormRequestClasses(?string $base = null): array
 {
     $classes = [];
-    $base = app_path('Http/Requests');
-    expect(is_dir($base))->toBeTrue();
+    $base ??= app_path('Http/Requests');
+    if (! is_dir($base)) {
+        return [];
+    }
 
     $iterator = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
@@ -281,27 +292,32 @@ function validationCoverageExtractArrayKeys(array $tokens, int $start, int $end)
 /**
  * app/ 配下 (app/Http/Requests を除く) の inline validation 呼び出しを走査する。
  *
- * @return array{keys: array<string, list<string>>, unparseable: list<string>}
+ * @param  string|null  $root  走査根の絶対パス (null = app/)。
+ *                             負のコントロールで別の根を渡すために引数化してある
+ * @return array{keys: array<string, list<string>>, unparseable: list<string>, scannedFiles: list<string>}
  */
-function validationCoverageScanInlineCalls(): array
+function validationCoverageScanInlineCalls(?string $root = null): array
 {
     $keysByCall = [];
     $unparseable = [];
+    $root ??= app_path();
 
-    $iterator = new RecursiveIteratorIterator(
-        new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS),
-    );
     $files = [];
-    /** @var SplFileInfo $file */
-    foreach ($iterator as $file) {
-        if (! $file->isFile() || $file->getExtension() !== 'php') {
-            continue;
-        }
-        $path = $file->getPathname();
-        if (str_starts_with($path, app_path('Http/Requests').'/')) {
-            continue;
+    if (is_dir($root)) {
+        $iterator = new RecursiveIteratorIterator(
+            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
+        );
+        /** @var SplFileInfo $file */
+        foreach ($iterator as $file) {
+            if (! $file->isFile() || $file->getExtension() !== 'php') {
+                continue;
+            }
+            $path = $file->getPathname();
+            if (str_starts_with($path, app_path('Http/Requests').'/')) {
+                continue;
+            }
+            $files[] = $path;
         }
-        $files[] = $path;
     }
     sort($files);
 
@@ -384,7 +400,7 @@ function validationCoverageScanInlineCalls(): array
         }
     }
 
-    return ['keys' => $keysByCall, 'unparseable' => $unparseable];
+    return ['keys' => $keysByCall, 'unparseable' => $unparseable, 'scannedFiles' => $files];
 }
 
 // ──────────────────────────── 検査 1 (FormRequest) ────────────────────────────
@@ -427,6 +443,30 @@ function validationCoverageScanInlineCalls(): array
     expect($violations)->toBe([], 'attributes 未登録キー: '.implode(', ', $violations));
 });
 
+// ──────────────────────────── 空振り検査 (母集団が 0 件で緑にならないこと) ────────────────────────────
+
+test('空振り検査: 2 つの母集団が空でない (走査根が生きている)', function (): void {
+    // 検査 1 の母集団: app/Http/Requests の FormRequest
+    expect(is_dir(app_path('Http/Requests')))->toBeTrue('走査根 app/Http/Requests が存在しません');
+    $formRequests = validationCoverageFormRequestClasses();
+    expect($formRequests)->not->toBe([], '走査根 app/Http/Requests から FormRequest が 1 件も見つかりません');
+    expect(count($formRequests))->toBeGreaterThanOrEqual(25); // 床値 (実測 34 件)
+
+    // 検査 2 の母集団: app/ (Requests を除く) の PHP ファイル
+    $scanned = validationCoverageScanInlineCalls()['scannedFiles'];
+    expect($scanned)->not->toBe([], '走査根 app/ に inline validation の走査対象がありません');
+    expect(count($scanned))->toBeGreaterThanOrEqual(400); // 床値 (実測 793 件)
+});
+
+test('負のコントロール: 走査根を差し替えると 2 つの母集団が空になる', function (): void {
+    // 上の非空検査が空振りしていないことの裏取り。走査根の改名・移動を模して
+    // 別ディレクトリ / 実在しないパスを渡すと母集団が 0 件になる。
+    expect(validationCoverageFormRequestClasses(app_path('Models')))->toBe([]);
+    expect(validationCoverageFormRequestClasses(app_path('Http/Requests-renamed')))->toBe([]);
+    expect(validationCoverageScanInlineCalls(app_path('Http/Requests'))['scannedFiles'])->toBe([]);
+    expect(validationCoverageScanInlineCalls(base_path('app-renamed'))['scannedFiles'])->toBe([]);
+});
+
 // ──────────────────────────── 検査 2 (inline validation, fail-closed) ────────────────────────────
 
 test('inline validation のルールキーが validation attributes に登録されている (fail-closed)', function (): void {

```

## テスト結果 (再実行)

- `composer phpstan`: No errors (level 10)
- `vendor/bin/pint --test`: passed
- Architecture レーン: 1122 tests, 1122 passed (0 failed)
- 赤の確認 (今回の修正ぶん):
  - `.github/workflows/*` の glob の綴りを壊す → S12c が
    「glob [.github-BROKEN/workflows/*] が代表ファイル .github/workflows/ci.yml を当てていません」で赤
  - `appSlugHardcodeViolations()` の `str_contains` を潰す → 自己検査が
    「判定が「必ず在る語」を拾えていません」で赤
  - どちらも書き換えを戻した後に緑を再確認済み

残る指摘があれば挙げてほしい。無ければ全体判定を APPROVED で返してほしい。
