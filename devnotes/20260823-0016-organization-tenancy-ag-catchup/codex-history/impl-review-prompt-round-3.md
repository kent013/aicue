# Round 3: 指摘への対応

Round 2 の指摘に対する対応を下に示す。**対応マトリクス**と**修正差分**を読み、
残っている問題があれば再度 [Critical] / [Warning] / [Suggestion] で指摘し、
最後に **全体判定: APPROVED または CHANGES_REQUESTED** を 1 行で書いてほしい。

なお本ラウンドが実装レビューの**最終ラウンド**である。
残る限界のうち「本 PR の範囲で直すべきもの」と「別 TODO へ送るべきもの」の切り分けも示してほしい。

## 1. 対応マトリクス

# 対応マトリクス: impl-review Round 2

## [Critical] `withoutRouteDefinitionUris()` が `->name()` / `->as()` も外し、撤去 route 名を見逃す
- 判断: 対応する
- 根拠: 指摘のとおり。`->name('organizations.switch')` のリテラルが抽出結果から消え、
  撤去 route 名の台帳が `routes/` の中で丸ごと効かなくなっていた。docblock とも矛盾していた。
- 対応内容: 除外集合から `name` / `as` / `domain` を外し、**URI を受ける引数だけ**にした。
  負例 (`->name(removedRouteName)` が検出されること) を gate に足した。

## [Critical] canonical builder の import 判定が `str_contains()` だけで、コメントでも前提を満たす
- 判断: 対応する
- 根拠: 指摘のコード片で実際に免除できた。
- 対応内容: `importedOrganizationUrlBuilders()` を新設し、**コメントを潰した写しの上で
  `import { … } from "@/lib/org-url"` を構文で読み、取り込んだローカル名 (別名つきは別名側) を
  解決**するようにした。呼び出しの照合はその名前だけを使う。
  指摘のコード片 (コメントに module 名 + 同名関数の自前定義) が検出されることを実測で確認した。

## [Warning] `notCurrentOrgUrl()` は大文字 C のため接頭辞の負例になっていない
- 判断: 対応する
- 対応内容: fixture を `notcurrentOrgUrl()` と `myorgUrl()` の 2 形へ直した
  (lookbehind が無ければ実際に一致する形)。

## [Critical] 区分の前提が「許可対象となった個々の出現」と結び付いていない
- 判断: 対応する
- 根拠: 指摘のとおり、同じ根・同じ件数で別 path へ置換できた。
- 対応内容: 検出結果に**根から終端までの path 全体** (`LegacyUrlOccurrence::$path`) を持たせ、
  区分の前提を**出現ごとの path** で判定するようにした。
  - `CanonicalCaptureEntry`: すべての出現の path が route 表の入口の URI と**完全一致**すること
    (`/app` → `/app/projects/1` の置換は path が変わって落ちる)
  - `FilesystemPath`: すべての出現の path が**実在するディレクトリ**であること
    (この検査で `correlate.py` の docstring が実在しない例示パスだったことが判明したので、
     例示を相対の形へ書き直して登録を 2 件 → 1 件へ直した)
  - `StorageObjectKey` / `AbsenceAssertion`: ファイル単位の印のまま (path で表せる性質ではない)
  - `OrganizationRelativePath`: 利用側に加えて**値を受ける記号 (`symbol`)** の名指しを必須にした
- 残る限界: 記号の一致は**データフローの証明ではない** (同じファイルにあることまで)。
  値が本当に builder へ渡ることは利用側の component テスト / Feature テストが担う、と
  目録の docblock に明記した。

## [Warning] 5 区分の前提に不適合な合成 entry の負例が無い
- 判断: 対応する
- 対応内容: 5 区分すべてについて**成立・不成立の両方向**を合成 entry で固定した
  (出現 0 件の登録が拒否されることも含む)。

## [Critical] handler gate が中間 parameter の欠落を検出できない
- 判断: 対応する
- 根拠: 指摘のとおり。部分列一致では `['organization', 'manual']` が通ってしまう。
- 対応内容: 判定を **route parameter の並びの先頭からの連続一致 (prefix)** に変えた。
  負例に「中間を飛ばした形」を足し、正例・欠落・飛ばし・順序違いの 4 形を固定した。

## [Warning] 裸の `/app` が検出される負例が無い
- 判断: 対応する
- 対応内容: `legacy-paths.md` に裸の形を足した (件数 pin も更新)。

## [Warning] `routes/` の負例が `redirect()` だけ
- 判断: 対応する
- 対応内容: `->name(removedRouteName)` の負例を足した (上の Critical と対)。

## [Warning] 自己検査の数え方が本体の `matchesIn()` から独立していない
- 判断: 対応する (説明を狭める)
- 根拠: 指摘のとおり。独立しているのは抽出方式だけである。
- 対応内容: docblock を「**独立しているのは抽出方式だけ**であり、根の位置と境界の判定は
  本体を共有しているのでその欠陥からは独立していない」と書き直し、
  位置判定の検出力は種別ごとの正例・負例が担うことを明記した。

## [Warning] symlink / NUL / 不正 UTF-8 の負例見送りへの異論
- 判断: 対応する (見送りを撤回)
- 根拠: 指摘のとおり、追跡下 fixture は不要だった (純関数と一時ディレクトリで足りる)。
- 対応内容: 内容の判定を `contentsUnresolvedReason()` へ純関数として切り出し、
  合成文字列で両方向を固定した。symlink は一時ディレクトリに壊れた symlink /
  リポジトリ外へ向く symlink / リポジトリ内へ向く symlink / 通常ファイルの 4 形を作って固定した。

## 2. 修正差分 (Round 2 提示分からの差分)

```diff
diff --git a/.claude/skills/app-bug-hunt/coverage/correlate.py b/.claude/skills/app-bug-hunt/coverage/correlate.py
index e3ac7bd0..924668a2 100644
--- a/.claude/skills/app-bug-hunt/coverage/correlate.py
+++ b/.claude/skills/app-bug-hunt/coverage/correlate.py
@@ -422,7 +422,7 @@ class TestedByIndex:
 
 
 def _normalize_abs_file(abs_file: str) -> str:
-    """/workspace/<...>/app/Foo.php や /workspace/app/Foo.php を app/ 相対へ。"""
+    """絶対パス (末尾が app/Foo.php のような形) を app/ 相対のパスへ畳む。"""
     # 最後に出てくる 'app/' から後ろを相対パスとして採用
     idx = abs_file.rfind("/app/")
     if idx >= 0:
diff --git a/tests/Architecture/LegacyOrganizationlessUrlAbsenceTest.php b/tests/Architecture/LegacyOrganizationlessUrlAbsenceTest.php
index 3b4b08e4..57ac7c3a 100644
--- a/tests/Architecture/LegacyOrganizationlessUrlAbsenceTest.php
+++ b/tests/Architecture/LegacyOrganizationlessUrlAbsenceTest.php
@@ -2,14 +2,16 @@
 
 declare(strict_types=1);
 
+use Illuminate\Support\Facades\Route;
 use Tests\Support\LegacyUrl\LegacyUrlAllowance;
+use Tests\Support\LegacyUrl\LegacyUrlAllowanceKind;
 use Tests\Support\LegacyUrl\LegacyUrlExtractionMode;
 use Tests\Support\LegacyUrl\LegacyUrlScannedFile;
 use Tests\Support\LegacyUrl\LegacyUrlScanner;
 use Tests\Support\LegacyUrl\LegacyUrlScanRoots;
 
 /*
- * 組織を持たない**旧 URL** と**撤去した route 名**がリポジトリに 1 件も残っていない
+ * 組織を持たない**旧 URL** と**撤去した route 名**が、走査できた範囲に 1 件も残っていない
  * (家系裁定 AG-037 / 施策 10)。
  *
  * ## なぜ必要か
@@ -36,10 +38,22 @@
  * 検出語をわざと持つ見本だけが「自己検査専用」へ名指しで入り、
  * その件数は `LegacyUrlSelfCheckPopulationTest` が完全一致で pin する。
  *
- * ## 保証しないもの
+ * ## 検出力の主張は次の範囲に**狭める** (誇張しない)
  *
- * 走査器 (`LegacyUrlScanner`) と母集団 (`LegacyUrlScanRoots`) の docblock が正本である。
- * リポジトリの外 (利用者のブックマーク・送信済みメール・ブラウザ履歴) は対象外である。
+ * 「1 件も無い」と言えるのは、**次の形で書かれた旧 URL**に限る。
+ * ここに挙げた形は `Tests\Support\SourceLiterals` と `LegacyUrlScanner` の限界そのものであり、
+ * **主張から明示的に除く** (走査器共通規約 (b): 明記した構文の検出力は主張しない)。
+ *
+ *  - **相対 path として 1 つのリテラル / 1 行に収まって書かれたもの**だけを見る。
+ *    実行時に連結する形 (`'/dash'.$suffix` / `'/' + name` / `${base}/x`) は**見えない**。
+ *  - **scheme と host を伴う絶対 URL は対象外**である (`https://example.com/dashboard`)。
+ *    外部サービスの URL と自アプリの URL を字面で区別できないためで、
+ *    host の後ろの path は根の位置と見なさない。
+ *  - **query (`?`) や hash (`#`) の中に置いた path は見ない** (`?next=/billing`)。
+ *    正規化した path を取り出す層は持たない。
+ *  - script の抽出は言語の構文解析ではない (正規表現リテラルの判定は発見的規則)。
+ *    誤読すると引用符の対応がずれ、**見逃す方向にも倒れうる**。
+ *  - リポジトリの外 (利用者のブックマーク・送信済みメール・ブラウザ履歴) は対象外である。
  */
 
 /** 走査対象の抽出方式が 5 規則とも生きている (走査根が壊れても気付ける)。 */
@@ -94,6 +108,109 @@
     expect($short)->toBe([]);
 });
 
+/**
+ * 許可した出現の path 全体を、目録のキーごとに集める。
+ *
+ * @return array<string, list<string>>
+ */
+function legacyUrlObservedPathsByKey(): array
+{
+    $paths = [];
+    foreach (LegacyUrlScanRoots::population()->scanned as $file) {
+        foreach (LegacyUrlScanner::scanFile($file) as $occurrence) {
+            $key = LegacyUrlAllowance::keyOf($occurrence->relative, $occurrence->ruleId, $occurrence->matched);
+            $paths[$key][] = $occurrence->path;
+        }
+    }
+
+    return $paths;
+}
+
+/** route 表の `capture.entry` の URI (先頭スラッシュつき)。 */
+function legacyUrlCaptureEntryUri(): string
+{
+    $routes = Route::getRoutes();
+    $routes->refreshNameLookups();
+
+    return '/'.ltrim((string) $routes->getByName('capture.entry')?->uri(), '/');
+}
+
+test('許可目録の区分ごとの前提がすべて満たされている (区分を判定に使う)', function (): void {
+    $captureEntryUri = legacyUrlCaptureEntryUri();
+    $repositoryRoot = LegacyUrlScanRoots::repositoryRoot();
+    $observed = legacyUrlObservedPathsByKey();
+
+    $violations = [];
+    foreach (LegacyUrlAllowance::entries() as $entry) {
+        $key = LegacyUrlAllowance::keyOf($entry['path'], $entry['rule'], $entry['matched']);
+        $violation = LegacyUrlAllowance::preconditionViolation(
+            $entry,
+            $repositoryRoot,
+            $captureEntryUri,
+            $observed[$key] ?? [],
+        );
+        if ($violation !== null) {
+            $violations[] = "{$entry['path']} [{$entry['kind']->value}]: {$violation}";
+        }
+    }
+
+    sort($violations);
+    expect($violations)->toBe([]);
+});
+
+test('負例: 区分ごとの前提は成立しない入力を拒否する (5 区分の両方向)', function (): void {
+    $repositoryRoot = LegacyUrlScanRoots::repositoryRoot();
+    $entryUri = LegacyUrlScanner::captureRoot();
+    $subPath = $entryUri.'/'.LegacyUrlScanner::organizationSegment();
+
+    $make = static fn (LegacyUrlAllowanceKind $kind, string $path, ?string $consumer = null, ?string $symbol = null): array => [
+        'path' => $path,
+        'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+        'matched' => LegacyUrlScanner::captureRoot(),
+        'count' => 1,
+        'kind' => $kind,
+        'consumer' => $consumer,
+        'symbol' => $symbol,
+        'reason' => '負例のための合成登録であり、前提が成立しないことを確かめるためだけに使う',
+    ];
+    $check = static fn (array $entry, array $paths): ?string => LegacyUrlAllowance::preconditionViolation(
+        $entry, $repositoryRoot, $entryUri, $paths,
+    );
+
+    // 出現が 1 件も無い登録は、どの区分でも拒否する
+    expect($check($make(LegacyUrlAllowanceKind::FilesystemPath, 'composer.json'), []))->not->toBeNull();
+
+    // CanonicalCaptureEntry: 入口そのものは通り、配下つきは落ちる
+    expect($check($make(LegacyUrlAllowanceKind::CanonicalCaptureEntry, 'composer.json'), [$entryUri]))->toBeNull();
+    expect($check($make(LegacyUrlAllowanceKind::CanonicalCaptureEntry, 'composer.json'), [$subPath]))->not->toBeNull();
+
+    // FilesystemPath: 実在するディレクトリは通り、実在しないパスは落ちる
+    expect($check($make(LegacyUrlAllowanceKind::FilesystemPath, 'composer.json'), [$entryUri]))->toBeNull();
+    expect($check($make(LegacyUrlAllowanceKind::FilesystemPath, 'composer.json'), [$subPath]))->not->toBeNull();
+
+    // StorageObjectKey: 鍵の印を持つファイルは通り、持たないファイルは落ちる
+    expect($check($make(LegacyUrlAllowanceKind::StorageObjectKey, 'doc/09_詳細実装設計.md'), [$entryUri]))->toBeNull();
+    expect($check($make(LegacyUrlAllowanceKind::StorageObjectKey, 'composer.json'), [$entryUri]))->not->toBeNull();
+
+    // AbsenceAssertion: 撤去の語を持つファイルは通り、持たないファイルは落ちる
+    expect($check($make(LegacyUrlAllowanceKind::AbsenceAssertion, 'docs/architecture.md'), [$entryUri]))->toBeNull();
+    expect($check($make(LegacyUrlAllowanceKind::AbsenceAssertion, 'composer.json'), [$entryUri]))->not->toBeNull();
+
+    // OrganizationRelativePath: 利用側と記号の両方が要る
+    expect($check(
+        $make(LegacyUrlAllowanceKind::OrganizationRelativePath, 'composer.json', 'resources/js/pages/Dashboard.svelte', 'BILLING_CALLOUTS'),
+        [$entryUri],
+    ))->toBeNull();
+    expect($check(
+        $make(LegacyUrlAllowanceKind::OrganizationRelativePath, 'composer.json', 'resources/js/pages/Dashboard.svelte', 'NoSuchSymbolInConsumer'),
+        [$entryUri],
+    ))->not->toBeNull();
+    expect($check(
+        $make(LegacyUrlAllowanceKind::OrganizationRelativePath, 'composer.json', null, null),
+        [$entryUri],
+    ))->not->toBeNull();
+});
+
 test('旧 URL と撤去 route 名は許可目録に登録したものを除いて 0 件', function (): void {
     $allowed = LegacyUrlAllowance::counts();
     $observed = [];
@@ -101,7 +218,7 @@
 
     foreach (LegacyUrlScanRoots::population()->scanned as $file) {
         foreach (LegacyUrlScanner::scanFile($file) as $occurrence) {
-            $key = $occurrence->relative."\0".$occurrence->ruleId;
+            $key = LegacyUrlAllowance::keyOf($occurrence->relative, $occurrence->ruleId, $occurrence->matched);
             $observed[$key] = ($observed[$key] ?? 0) + 1;
             if (! array_key_exists($key, $allowed)) {
                 $violations[] = $occurrence->describe();
@@ -115,58 +232,47 @@
     // ★件数は完全一致 (増えても減っても赤 / 登録が実在しなくなっても赤)
     $mismatched = [];
     foreach ($allowed as $key => $count) {
-        [$path, $rule] = explode("\0", $key);
+        [$path, $rule, $matched] = explode("\0", $key);
         $actual = $observed[$key] ?? 0;
         if ($actual !== $count) {
-            $mismatched[] = "{$path} [{$rule}] 登録 {$count} 件 / 実測 {$actual} 件";
+            $mismatched[] = "{$path} [{$rule}] {$matched} 登録 {$count} 件 / 実測 {$actual} 件";
         }
     }
     expect($mismatched)->toBe([]);
 });
 
-test('負例: 見本の旧 URL を検出できる (検出力の裏取り)', function (): void {
-    $base = base_path('tests/Architecture/fixtures/legacy-url/');
-
-    $markdown = new LegacyUrlScannedFile(
-        relative: 'fixture.md',
-        contents: (string) file_get_contents($base.'legacy-paths.md'),
-        mode: LegacyUrlExtractionMode::PlainText,
-        ruleId: LegacyUrlScanner::RULE_MARKDOWN_TEXT,
-    );
-    $php = new LegacyUrlScannedFile(
-        relative: 'fixture.php',
-        contents: (string) file_get_contents($base.'legacy-php-source.txt'),
-        mode: LegacyUrlExtractionMode::SourceLiteral,
-        ruleId: LegacyUrlScanner::RULE_PHP_LITERAL,
-    );
-    $script = new LegacyUrlScannedFile(
-        relative: 'fixture.ts',
-        contents: (string) file_get_contents($base.'legacy-script-source.txt'),
-        mode: LegacyUrlExtractionMode::SourceLiteral,
-        ruleId: LegacyUrlScanner::RULE_SCRIPT_LITERAL,
+test('負例: 種別ごとに見本の旧 URL を検出できる (検出力の裏取り)', function (
+    string $fixture,
+    LegacyUrlExtractionMode $mode,
+    string $rule,
+    string $relative,
+    int $expected,
+): void {
+    $file = new LegacyUrlScannedFile(
+        relative: $relative,
+        contents: (string) file_get_contents(base_path('tests/Architecture/fixtures/legacy-url/'.$fixture)),
+        mode: $mode,
+        ruleId: $rule,
     );
 
+    expect(LegacyUrlScanner::scanFile($file))->toHaveCount($expected);
+})->with([
     // Markdown: 旧パス 11 件 + 撤去 route 名 1 件
-    expect(LegacyUrlScanner::scanFile($markdown))->toHaveCount(12);
+    'markdown' => ['legacy-paths.md', LegacyUrlExtractionMode::PlainText, LegacyUrlScanner::RULE_MARKDOWN_TEXT, 'fixture.md', 13],
+    // Markdown の負例: 新 URL・接頭辞/打ち消し/接尾辞・絶対 URL は 1 件も拾わない
+    'markdown-negative' => ['allowed-paths.md', LegacyUrlExtractionMode::PlainText, LegacyUrlScanner::RULE_MARKDOWN_TEXT, 'fixture.md', 0],
     // PHP: リテラルの旧パス 2 件 (コメントは数えない) + 撤去 route 名 1 件
-    expect(LegacyUrlScanner::scanFile($php))->toHaveCount(3);
-    // script: リテラルの旧パス 1 件 (コメント / 組織 URL 組み立ての入口 / 組織 prefix は数えない)
-    expect(LegacyUrlScanner::scanFile($script))->toHaveCount(1);
-});
-
-test('負例: 新 URL と紛らわしい語を誤検出しない (接頭辞・打ち消し・接尾辞の 3 形を含む)', function (): void {
-    $allowed = new LegacyUrlScannedFile(
-        relative: 'fixture.md',
-        contents: (string) file_get_contents(base_path('tests/Architecture/fixtures/legacy-url/allowed-paths.md')),
-        mode: LegacyUrlExtractionMode::PlainText,
-        ruleId: LegacyUrlScanner::RULE_MARKDOWN_TEXT,
-    );
-
-    expect(array_map(
-        static fn (object $occurrence): string => (string) $occurrence->describe(),
-        LegacyUrlScanner::scanFile($allowed),
-    ))->toBe([]);
-});
+    'php' => ['legacy-php-source.txt', LegacyUrlExtractionMode::SourceLiteral, LegacyUrlScanner::RULE_PHP_LITERAL, 'fixture.php', 3],
+    // script: 入口の引数・組織 prefix・コメント・正規表現リテラルを除いた 5 件
+    // (直書き 1 / 接頭辞つき偽入口 2 / コメントの偽入口の次行 1 / メンバ呼びの偽入口 1)
+    'script' => ['legacy-script-source.txt', LegacyUrlExtractionMode::SourceLiteral, LegacyUrlScanner::RULE_SCRIPT_LITERAL, 'fixture.ts', 5],
+    // script: 入口の module を取り込まずに同名関数を自前定義しても免除にならない
+    'script-shadowed' => ['legacy-shadowed-builder.txt', LegacyUrlExtractionMode::SourceLiteral, LegacyUrlScanner::RULE_SCRIPT_LITERAL, 'fixture.ts', 1],
+    // JSON: 値の旧パス 1 件 + 1 行に 2 個の撤去 route 名 (件数で数える)
+    'data' => ['legacy-data-source.txt', LegacyUrlExtractionMode::PlainText, LegacyUrlScanner::RULE_DATA_TEXT, 'fixture.json', 3],
+    // Blade: 属性値の旧パス 1 件 (route helper 経由と組織 prefix つきは数えない)
+    'blade' => ['legacy-blade-source.txt', LegacyUrlExtractionMode::PlainText, LegacyUrlScanner::RULE_BLADE_TEXT, 'fixture.blade.php', 1],
+]);
 
 test('種別ごとの割り当て: 拡張子は宣言した抽出方式と規則 ID へ 1:1 で写る', function (): void {
     // ★「どの種別をどう抽出するか」は分類表が唯一の正本である。ここが壊れると
@@ -189,7 +295,94 @@
     }
 });
 
+test('負例: routes/ では撤去 route 名が名前づけの引数でも検出される', function (): void {
+    // ★`->name()` を除外集合へ入れると、撤去 route 名の台帳が routes/ の中で丸ごと効かなくなる。
+    $source = "<?php\nRoute::get('/x', H::class)->name('".LegacyUrlScanner::removedRouteName()."');\n";
+
+    $file = new LegacyUrlScannedFile(
+        relative: 'routes/web.php',
+        contents: $source,
+        mode: LegacyUrlExtractionMode::SourceLiteral,
+        ruleId: LegacyUrlScanner::RULE_PHP_LITERAL,
+    );
+
+    expect(array_map(
+        static fn (object $occurrence): string => (string) $occurrence->ruleId,
+        LegacyUrlScanner::scanFile($file),
+    ))->toBe([LegacyUrlScanner::RULE_REMOVED_ROUTE_NAME]);
+});
+
 test('負例: 未知の拡張子は未分類として落ちる (fail-closed)', function (): void {
     expect(LegacyUrlScanRoots::classify('resources/js/app.unknownext'))->toBeNull();
     expect(LegacyUrlScanRoots::classify('app/Models/User.php'))->not->toBeNull();
 });
+
+test('負例: 走査対象に分類したのに読めない内容は未解決になる (fail-closed)', function (): void {
+    // ★母集団の unresolved が 0 件であることは「異常入力を未解決へ送れる」ことの裏取りにならない。
+    //   判定を純関数へ切り出してあるので、合成した内容で両方向を固定する。
+    expect(LegacyUrlScanRoots::contentsUnresolvedReason("ふつうの本文\n"))->toBeNull();
+    expect(LegacyUrlScanRoots::contentsUnresolvedReason("bin\0ary"))->not->toBeNull();
+    expect(LegacyUrlScanRoots::contentsUnresolvedReason("\xff\xfe invalid"))->not->toBeNull();
+});
+
+test('負例: symlink の解決は壊れている / リポジトリ外を未解決にする (fail-closed)', function (): void {
+    $repositoryRoot = LegacyUrlScanRoots::repositoryRoot();
+    $directory = sys_get_temp_dir().'/legacy-url-symlink-'.bin2hex(random_bytes(6));
+    mkdir($directory);
+
+    try {
+        // 通常ファイルは symlink ではないので理由なし
+        $plain = $directory.'/plain.txt';
+        file_put_contents($plain, 'x');
+        expect(LegacyUrlScanRoots::symlinkUnresolvedReason($repositoryRoot, $plain))->toBeNull();
+
+        // 壊れた symlink
+        $broken = $directory.'/broken';
+        symlink($directory.'/does-not-exist', $broken);
+        expect(LegacyUrlScanRoots::symlinkUnresolvedReason($repositoryRoot, $broken))->not->toBeNull();
+
+        // リポジトリ外へ解決される symlink
+        $outside = $directory.'/outside';
+        symlink($plain, $outside);
+        expect(LegacyUrlScanRoots::symlinkUnresolvedReason($repositoryRoot, $outside))->not->toBeNull();
+
+        // リポジトリ内へ解決される symlink は理由なし
+        $inside = $directory.'/inside';
+        symlink($repositoryRoot.'/composer.json', $inside);
+        expect(LegacyUrlScanRoots::symlinkUnresolvedReason($repositoryRoot, $inside))->toBeNull();
+    } finally {
+        foreach (['broken', 'outside', 'inside'] as $link) {
+            @unlink($directory.'/'.$link);
+        }
+        @unlink($directory.'/plain.txt');
+        @rmdir($directory);
+    }
+});
+
+test('負例: routes/ は route 定義の URI だけを外し、他のリテラルは検出する', function (): void {
+    // ★ファイルごと外すと、そこが旧 URL の抜け道になる。外すのは URI 引数 1 つだけである。
+    // ★合成入力の旧 URL は**走査器が組み立てた根**から作る (この gate 自身が旧 URL 文字列を持たない)。
+    $roots = LegacyUrlScanner::legacyRoots();
+    $definitionUri = $roots[4];   // route 定義の URI 引数 (外れる側)
+    $inlineRedirect = $roots[5];  // route 定義以外のリテラル (残る側)
+    $source = "<?php\n"
+        ."Route::prefix('/x')->group(function (): void {\n"
+        ."    Route::get('{$definitionUri}', DashboardController::class)->name('x');\n"
+        ."    Route::get('/legacy', fn () => redirect('{$inlineRedirect}'));\n"
+        ."});\n";
+
+    $file = new LegacyUrlScannedFile(
+        relative: 'routes/web.php',
+        contents: $source,
+        mode: LegacyUrlExtractionMode::SourceLiteral,
+        ruleId: LegacyUrlScanner::RULE_PHP_LITERAL,
+    );
+
+    $matched = array_map(
+        static fn (object $occurrence): string => (string) $occurrence->matched,
+        LegacyUrlScanner::scanFile($file),
+    );
+
+    // route 定義の URI は外れ、redirect の直書きだけが残る
+    expect($matched)->toBe([$inlineRedirect]);
+});
diff --git a/tests/Architecture/LegacyUrlSelfCheckPopulationTest.php b/tests/Architecture/LegacyUrlSelfCheckPopulationTest.php
index 2b9801b0..841c7671 100644
--- a/tests/Architecture/LegacyUrlSelfCheckPopulationTest.php
+++ b/tests/Architecture/LegacyUrlSelfCheckPopulationTest.php
@@ -17,6 +17,17 @@
  * 増えても減っても赤になるので、見本を黙って増やすことも、
  * 実装のついでに旧 URL を見本ファイルへ退避することもできない。
  *
+ * ## 数え方 (**抽出方式からだけ**独立させる)
+ *
+ * **全文を 1 行ずつ**見て、根の一致と撤去 route 名の**出現数**を数える。
+ * 本体の抽出方式 (コメントを外す / 入口の引数を外す / route 定義の URI を外す) は通さない —
+ * ここで数えたいのは「見本が検出語を何個持っているか」であって、本体が何件検出するかではない。
+ *
+ * ★**独立しているのは抽出方式だけである** (誇張しない)。根の位置と境界の判定は本体の
+ *   `matchesIn()` を共有しているので、**その判定の欠陥からは独立していない**。
+ *   根の位置判定そのものの検出力は `LegacyOrganizationlessUrlAbsenceTest` の
+ *   種別ごとの正例・負例が担う。
+ *
  * ## この gate 自身は旧 URL 文字列を持たない
  *
  * 持つのは**パスと件数**だけである (旧 URL を書くと、この gate 自身が検出対象になる)。
@@ -25,23 +36,23 @@
 /** 自己検査専用のファイル名 (完全一致)。 */
 const LEGACY_URL_SELF_CHECK_FILES = [
     'tests/Architecture/fixtures/legacy-url/allowed-paths.md',
+    'tests/Architecture/fixtures/legacy-url/legacy-blade-source.txt',
+    'tests/Architecture/fixtures/legacy-url/legacy-data-source.txt',
     'tests/Architecture/fixtures/legacy-url/legacy-paths.md',
     'tests/Architecture/fixtures/legacy-url/legacy-php-source.txt',
     'tests/Architecture/fixtures/legacy-url/legacy-script-source.txt',
+    'tests/Architecture/fixtures/legacy-url/legacy-shadowed-builder.txt',
 ];
 
-/**
- * 各見本が持つ検出語の件数 (完全一致)。
- *
- * ★件数は**全文走査**で数える (見本の中身がどの言語かにかかわらず同じ数え方をする)。
- *   ソースの見本はコメントにも検出語を置いてあるので、リテラルだけを見る本体の数え方とは
- *   一致しない。ここで数えたいのは「見本が検出語を何個持っているか」である。
- */
+/** 各見本が持つ検出語の件数 (完全一致)。 */
 const LEGACY_URL_SELF_CHECK_COUNTS = [
     'tests/Architecture/fixtures/legacy-url/allowed-paths.md' => 0,
-    'tests/Architecture/fixtures/legacy-url/legacy-paths.md' => 12,
+    'tests/Architecture/fixtures/legacy-url/legacy-blade-source.txt' => 1,
+    'tests/Architecture/fixtures/legacy-url/legacy-data-source.txt' => 3,
+    'tests/Architecture/fixtures/legacy-url/legacy-paths.md' => 13,
     'tests/Architecture/fixtures/legacy-url/legacy-php-source.txt' => 5,
-    'tests/Architecture/fixtures/legacy-url/legacy-script-source.txt' => 5,
+    'tests/Architecture/fixtures/legacy-url/legacy-script-source.txt' => 9,
+    'tests/Architecture/fixtures/legacy-url/legacy-shadowed-builder.txt' => 1,
 ];
 
 test('自己検査専用の分類は目録と完全一致する', function (): void {
@@ -60,9 +71,8 @@
         $hits = 0;
         foreach (explode("\n", $file->contents) as $line) {
             $hits += count(LegacyUrlScanner::matchesIn($line));
-            if (str_contains($line, LegacyUrlScanner::removedRouteName())) {
-                $hits++;
-            }
+            // ★出現数で数える (1 行 1 件にすると同じ行へ 2 個目を足しても動かない)
+            $hits += substr_count($line, LegacyUrlScanner::removedRouteName());
         }
         $counts[$file->relative] = $hits;
     }
diff --git a/tests/Architecture/OrganizationRouteHandlerParameterTest.php b/tests/Architecture/OrganizationRouteHandlerParameterTest.php
index 6038e6e4..b9555681 100644
--- a/tests/Architecture/OrganizationRouteHandlerParameterTest.php
+++ b/tests/Architecture/OrganizationRouteHandlerParameterTest.php
@@ -2,39 +2,62 @@
 
 declare(strict_types=1);
 
-use App\Models\Organization;
+use Illuminate\Http\Request;
 use Illuminate\Routing\Route as RoutingRoute;
 use Illuminate\Support\Facades\Route;
 
 /*
- * `{organization}` を持つ route の handler は **organization 引数を受ける** (家系裁定 AG-037)。
+ * `{organization}` を持つ route の handler は **route parameter を宣言順に受ける**
+ * (家系裁定 AG-037)。
  *
  * ## なぜ必要か (実測事故)
  *
  * framework は route parameter を **位置で** handler の引数へ割り当てる
  * (`RouteDependencyResolverTrait::resolveMethodDependencies` はクラス型を差し込んだ後、
  *  残りを `array_values($routeParameters)` から順に埋める)。したがって組織 URL 配下の
- * handler が `{organization}` を受けないと、**後続の引数が 1 つずつずれる**。
+ * handler が `{organization}` を受けない・順序が違うと、**引数が 1 つずつずれる**。
  *
  * 実測では通知の既読化 (`notifications.read`) が `string $notification` に Organization を
- * 受け取り、通知が見つからず 404 になっていた。**型が合わないのに例外にならない**
- * (Organization は `__toString()` を持たないが、そのまま検索値として渡ってしまう) ため、
+ * 受け取り、通知が見つからず 404 になっていた。**型が合わないのに例外にならない**ため、
  * 失敗は「なぜか 404」という形でしか現れない。
  *
  * ## 判定
  *
- * `{organization}` を持つ route の handler に `organization` という名前の引数があること。
- * **使うかどうかは問わない** — 位置ずれを防ぐことが目的なので、受けていれば足りる。
+ * handler の引数のうち **route parameter と同じ名前のもの**を宣言順に取り出し、
+ * それが route parameter の並びの**先頭からの連続した並び (prefix)** であることを求める。
+ *  - `{organization}` を受けていなければ不一致になる (先頭が合わない)
+ *  - 途中の parameter を飛ばしても不一致になる (`[organization, manual]` は prefix ではない)
+ *  - 順序が違っても不一致になる (位置ずれそのもの)
+ * **部分列では足りない**: 途中を飛ばすと、飛ばした parameter の値が次の引数へ入る。
+ * closure route も同じ resolution を通るので**同じ検査を掛ける**。
  *
  * ## 保証しないもの
  *
- * - handler が closure の route は対象外である (`app/` の外に本体があり、位置の契約も違う)。
- * - `{organization}` 以外の parameter の順序ずれは見ない (この検査は組織セグメントの導入で
- *   全業務 route が 1 つずれたことへの回帰固定である)。
  * - 引数の**型**は見ない (binding が Organization を返すことは binder 側の契約)。
+ * - route parameter と無関係な名前の引数 (DI で解決されるもの) は数えない。
+ * - `{organization}` を持たない route は母集団に入らない (本検査は組織セグメントの
+ *   導入で全業務 route が 1 つずれたことへの回帰固定である)。
  */
 
-test('{organization} を持つ route の handler はすべて organization 引数を受ける', function (): void {
+/**
+ * handler の引数のうち route parameter と同名のものを宣言順に返す。
+ *
+ * @param  list<string>  $routeParameters
+ * @return list<string>
+ */
+function organizationRouteHandlerParameterNames(ReflectionFunctionAbstract $handler, array $routeParameters): array
+{
+    $names = [];
+    foreach ($handler->getParameters() as $parameter) {
+        if (in_array($parameter->getName(), $routeParameters, true)) {
+            $names[] = $parameter->getName();
+        }
+    }
+
+    return $names;
+}
+
+test('{organization} を持つ route の handler は route parameter を宣言順に受ける', function (): void {
     $routes = Route::getRoutes();
     $routes->refreshNameLookups();
 
@@ -43,35 +66,39 @@
 
     /** @var RoutingRoute $route */
     foreach ($routes as $route) {
-        if (! in_array('organization', $route->parameterNames(), true)) {
-            continue;
-        }
-
-        $action = $route->getActionName();
-        if ($action === 'Closure') {
+        $parameters = $route->parameterNames();
+        if (! in_array('organization', $parameters, true)) {
             continue;
         }
 
-        [$class, $method] = str_contains($action, '@')
-            ? explode('@', $action, 2)
-            : [$action, '__invoke'];
-
-        if (! class_exists($class) || ! method_exists($class, $method)) {
+        $action = $route->getAction('uses');
+        try {
+            $handler = $action instanceof Closure
+                ? new ReflectionFunction($action)
+                : (function (string $uses): ReflectionFunctionAbstract {
+                    [$class, $method] = str_contains($uses, '@')
+                        ? explode('@', $uses, 2)
+                        : [$uses, '__invoke'];
+
+                    return new ReflectionMethod($class, $method);
+                })(is_string($action) ? $action : '');
+        } catch (ReflectionException $exception) {
             // 解決できない形は落とす (fail-closed)
-            $violations[] = ($route->getName() ?? $route->uri()).' -> 解決できない handler: '.$action;
+            $violations[] = ($route->getName() ?? $route->uri()).' -> handler を解決できません: '
+                .$exception->getMessage();
 
             continue;
         }
 
         $population++;
-        $names = array_map(
-            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
-            (new ReflectionMethod($class, $method))->getParameters(),
-        );
+        $declared = organizationRouteHandlerParameterNames($handler, $parameters);
+        // route parameter の並びの**先頭から**同じ本数を取る (prefix 一致を求める)
+        $expected = array_slice($parameters, 0, count($declared));
 
-        if (! in_array('organization', $names, true)) {
+        if ($declared === [] || $declared !== $expected) {
             $violations[] = ($route->getName() ?? $route->uri())
-                ." -> {$class}::{$method}(".implode(', ', $names).')';
+                .' -> handler の引数 ['.implode(', ', $declared).'] が route parameter ['
+                .implode(', ', $parameters).'] の並びと一致しません';
         }
     }
 
@@ -82,27 +109,42 @@
     expect($violations)->toBe([]);
 });
 
-test('負例: organization 引数を持たない合成 handler を検出できる', function (): void {
-    $withOrganization = new class
-    {
-        public function show(Organization $organization, string $notification): string
-        {
-            return $organization->slug.$notification;
-        }
+test('負例: 欠落・中間の飛ばし・順序違いのいずれも検出できる (検出力の裏取り)', function (): void {
+    $parameters = ['organization', 'project', 'manual'];
+
+    /** @var array<string, ReflectionFunction> $handlers */
+    $handlers = [
+        // 正例: 先頭から連続して受けている
+        'ok-all' => new ReflectionFunction(
+            static fn (Request $request, string $organization, int $project, int $manual): string => $organization.$project.$manual,
+        ),
+        // 正例: 先頭から連続していれば途中で打ち切ってよい (残りは framework が使わない)
+        'ok-prefix' => new ReflectionFunction(
+            static fn (Request $request, string $organization, int $project): string => $organization.$project,
+        ),
+        // 負例 1: organization を受けていない (欠落)
+        'missing-organization' => new ReflectionFunction(
+            static fn (Request $request, int $project, int $manual): string => $project.$manual,
+        ),
+        // 負例 2: 中間を飛ばした (project の値が manual へ入る)
+        'skips-middle' => new ReflectionFunction(
+            static fn (Request $request, string $organization, int $manual): string => $organization.$manual,
+        ),
+        // 負例 3: 順序が違う
+        'reordered' => new ReflectionFunction(
+            static fn (Request $request, int $project, string $organization): string => $organization.$project,
+        ),
+    ];
+
+    $violates = static function (ReflectionFunction $handler) use ($parameters): bool {
+        $declared = organizationRouteHandlerParameterNames($handler, $parameters);
+
+        return $declared === [] || $declared !== array_slice($parameters, 0, count($declared));
     };
-    $withoutOrganization = new class
-    {
-        public function show(string $notification): string
-        {
-            return $notification;
-        }
-    };
-
-    $names = static fn (object $handler): array => array_map(
-        static fn (ReflectionParameter $parameter): string => $parameter->getName(),
-        (new ReflectionMethod($handler, 'show'))->getParameters(),
-    );
 
-    expect(in_array('organization', $names($withOrganization), true))->toBeTrue();
-    expect(in_array('organization', $names($withoutOrganization), true))->toBeFalse();
+    expect($violates($handlers['ok-all']))->toBeFalse();
+    expect($violates($handlers['ok-prefix']))->toBeFalse();
+    expect($violates($handlers['missing-organization']))->toBeTrue();
+    expect($violates($handlers['skips-middle']))->toBeTrue();
+    expect($violates($handlers['reordered']))->toBeTrue();
 });
diff --git a/tests/Architecture/fixtures/legacy-url/allowed-paths.md b/tests/Architecture/fixtures/legacy-url/allowed-paths.md
index 9f0aa879..016902ca 100644
--- a/tests/Architecture/fixtures/legacy-url/allowed-paths.md
+++ b/tests/Architecture/fixtures/legacy-url/allowed-paths.md
@@ -7,7 +7,6 @@ # 誤検出してはいけない見本 (負例)
 - テンプレートリテラル: /organizations/${slug}/billing
 - 山括弧の置換子: /organizations/<slug>/manage/users
 - 根の下の第 2 セグメント: /organizations/acme/billing/purchase-tickets
-- 正規の分岐入口: /app
 - 接頭辞つき: /myapp
 - 打ち消しつき: /app-old
 - 接尾辞つき: /appx
diff --git a/tests/Architecture/fixtures/legacy-url/legacy-blade-source.txt b/tests/Architecture/fixtures/legacy-url/legacy-blade-source.txt
new file mode 100644
index 00000000..ce8efe75
--- /dev/null
+++ b/tests/Architecture/fixtures/legacy-url/legacy-blade-source.txt
@@ -0,0 +1,4 @@
+{{-- Blade のコメントも全文走査の対象である --}}
+<a href="/manage/users">メンバー</a>
+<a href="{{ route('dashboard', ['organization' => $organization->slug]) }}">ダッシュボード</a>
+<a href="/organizations/acme/billing">請求</a>
diff --git a/tests/Architecture/fixtures/legacy-url/legacy-data-source.txt b/tests/Architecture/fixtures/legacy-url/legacy-data-source.txt
new file mode 100644
index 00000000..ded1910a
--- /dev/null
+++ b/tests/Architecture/fixtures/legacy-url/legacy-data-source.txt
@@ -0,0 +1,5 @@
+{
+  "start_url": "/dashboard",
+  "scope": "/organizations/acme/app",
+  "note": "撤去した route 名 organizations.switch を 2 回 organizations.switch"
+}
diff --git a/tests/Architecture/fixtures/legacy-url/legacy-paths.md b/tests/Architecture/fixtures/legacy-url/legacy-paths.md
index 4e2760c5..0d8104d0 100644
--- a/tests/Architecture/fixtures/legacy-url/legacy-paths.md
+++ b/tests/Architecture/fixtures/legacy-url/legacy-paths.md
@@ -14,4 +14,5 @@ # 旧 URL の見本 (正例)
 - 遮断の着地は /billing-required)
 - 管理は /manage/users|
 - 撮影 PWA の配下は /app/projects/1/manuals
+- 裸の入口も許可目録に無ければ検出する /app
 - 撤去した route 名は organizations.switch である
diff --git a/tests/Architecture/fixtures/legacy-url/legacy-script-source.txt b/tests/Architecture/fixtures/legacy-url/legacy-script-source.txt
index bd4303a9..e8fd5ea6 100644
--- a/tests/Architecture/fixtures/legacy-url/legacy-script-source.txt
+++ b/tests/Architecture/fixtures/legacy-url/legacy-script-source.txt
@@ -1,7 +1,14 @@
 // コメントの /dashboard は参照ではない
 /* ブロックコメントの /projects も参照ではない */
+import { orgUrl, currentOrgUrl } from "@/lib/org-url";
+const quotePattern = /["]/;
 const a = "/billing";
 const b = orgUrl(slug, "/projects");
 const c = currentOrgUrl(`/manage/users`);
 const d = `/organizations/${slug}/notifications`;
 const e = "https://example.com/dashboard";
+const f = notcurrentOrgUrl("/purchase-tickets");
+const i = myorgUrl("/notifications");
+// currentOrgUrl(
+const g = "/billing-required";
+const h = someObject.orgUrl("/onboarding");
diff --git a/tests/Architecture/fixtures/legacy-url/legacy-shadowed-builder.txt b/tests/Architecture/fixtures/legacy-url/legacy-shadowed-builder.txt
new file mode 100644
index 00000000..0fb26488
--- /dev/null
+++ b/tests/Architecture/fixtures/legacy-url/legacy-shadowed-builder.txt
@@ -0,0 +1,4 @@
+function orgUrl(slug, path) {
+    return path;
+}
+const leaked = orgUrl(slug, "/projects");
diff --git a/tests/Support/LegacyUrl/LegacyUrlAllowance.php b/tests/Support/LegacyUrl/LegacyUrlAllowance.php
index 4b1fc45d..cdec2883 100644
--- a/tests/Support/LegacyUrl/LegacyUrlAllowance.php
+++ b/tests/Support/LegacyUrl/LegacyUrlAllowance.php
@@ -4,106 +4,396 @@
 
 namespace Tests\Support\LegacyUrl;
 
+use RuntimeException;
+
 /**
- * 旧 URL 検出の許可目録 (deny-by-default。**件数まで完全一致**)。
+ * 旧 URL 検出の許可目録 (deny-by-default。**対象パターンと件数まで完全一致**)。
  *
- * ★形式は **パス + 検出規則 ID + 区分 + 件数 + 30 文字以上の理由**である。
- * ★**旧 URL の文字列そのものを目録へ写さない**。写すと目録自身が検出対象になり
- *   「自分を許可するための登録」という再帰が始まる。目録が持つのは
- *   「どの規則の、どのファイルの、何件を許すか」だけである。
- * ★**ファイル全体を走査から外さない**。登録した規則 ID 以外の検出は、
+ * ★形式は **パス + 検出規則 ID + 一致した語 + 区分 + 件数 + 30 文字以上の理由**である。
+ *   「どの語を何件許すか」まで固定するので、**同じファイル・同じ件数で別の旧 URL へ
+ *   置き換えても通らない**。
+ * ★**旧 URL の文字列そのものを目録へ写さない**。語は走査器が断片から組み立てた値
+ *   (`LegacyUrlScanner::legacyRoots()` / `captureRoot()` / `removedRouteName()`) から選ぶので、
+ *   目録自身が検出対象になる再帰は起きない。
+ * ★区分 (`LegacyUrlAllowanceKind`) は**判定に使う**。区分ごとの前提を
+ *   `preconditionViolation()` が機械で確かめ、満たさない登録は赤になる
+ *   (説明ラベルにしない = 走査器共通規約 (d))。
+ * ★**ファイル全体を走査から外さない**。登録した (規則 ID, 語) 以外の検出は、
  *   同じファイルの中でも引き続き違反になる。
  * ★件数は完全一致である (増えても減っても赤)。減ったときも赤にするのは、
  *   許可の理由が消えたのに登録が残る状態を作らないためである。
  */
 final class LegacyUrlAllowance
 {
+    /**
+     * オブジェクトストレージの鍵を扱っている印 (区分 `StorageObjectKey` の前提)。
+     *
+     * ★**2 つのどちらかが同じファイルに現れること**を求める。
+     *   本番の鍵は組織 id で始まる接頭辞を持ち、鍵の妥当性を検査する層は鍵の型名を持つ。
+     *   どちらも無いファイルは「保存先の鍵である」と名乗れない。
+     *
+     * @var list<string>
+     */
+    public const array STORAGE_KEY_MARKERS = ['orgs/', 'StorageKey'];
+
+    /** 撤去を説明する語 (区分 `AbsenceAssertion` の前提)。 */
+    public const string REMOVAL_MARKER = '撤去';
+
     /** インスタンス化しない (目録の置き場)。 */
     private function __construct() {}
 
+    /**
+     * 走査器が組み立てた旧パスの根から、末尾が一致する 1 本を選ぶ。
+     *
+     * ★目録に旧 URL の文字列を書かないための入口である (綴りを写すと目録自身が検出対象になる)。
+     *   一致が 1 本でなければ例外にする (綴り間違いを黙って許さない)。
+     */
+    private static function legacyRootEndingWith(string $suffix): string
+    {
+        $matched = array_values(array_filter(
+            LegacyUrlScanner::legacyRoots(),
+            static fn (string $root): bool => str_ends_with($root, $suffix),
+        ));
+
+        if (count($matched) !== 1) {
+            throw new RuntimeException("旧パスの根を一意に選べません: {$suffix}");
+        }
+
+        return $matched[0];
+    }
+
     /**
      * 登録一覧。
      *
-     * @return list<array{path: string, rule: string, kind: LegacyUrlAllowanceKind, count: int, reason: string}>
+     * @return list<array{path: string, rule: string, matched: string, count: int, kind: LegacyUrlAllowanceKind, consumer: ?string, symbol: ?string, reason: string}>
      */
     public static function entries(): array
     {
         return [
-            [
-                'path' => 'tests/Architecture/PromptDefenseWindowGateTest.php',
-                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
-                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
-                'count' => 2,
-                'reason' => 'prompt factory の走査根をリポジトリルートからの相対ディレクトリとして組み立てている箇所であり、URL ではなくファイルシステムのパスである',
-            ],
             [
                 'path' => '.claude/skills/app-bug-hunt/coverage/correlate.py',
                 'rule' => LegacyUrlScanner::RULE_SCRIPT_LITERAL,
-                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
+                'matched' => LegacyUrlScanner::captureRoot(),
                 'count' => 1,
-                'reason' => 'スタックトレースの絶対パスをリポジトリ相対へ畳む処理の説明文であり、'
-                    .'指しているのは app/ ディレクトリのファイルパスで、画面の URL ではない。',
+                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
+                'consumer' => null,
+                'symbol' => null,
+                'reason' => 'スタックトレースの絶対パスをリポジトリ相対へ畳む処理が探す区切りであり、指しているのはアプリ実装のディレクトリで画面の URL ではない',
             ],
             [
                 'path' => '.claude/skills/app-bug-hunt/coverage/test_out_of_scope.py',
                 'rule' => LegacyUrlScanner::RULE_SCRIPT_LITERAL,
-                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
+                'matched' => LegacyUrlScanner::captureRoot(),
                 'count' => 1,
-                'reason' => '対象外判定の見本に置いた管理画面の実装ディレクトリのパスであり、'
-                    .'ファイルシステム上の位置を指す文字列で画面の URL ではない。',
+                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
+                'consumer' => null,
+                'symbol' => null,
+                'reason' => '対象外判定の見本に置いた管理画面の実装ディレクトリのパスであり、ファイルシステム上の位置を指す文字列で画面の URL ではない',
             ],
             [
-                'path' => 'docs/architecture.md',
-                'rule' => LegacyUrlScanner::RULE_REMOVED_ROUTE_NAME,
-                'kind' => LegacyUrlAllowanceKind::AbsenceAssertion,
+                'path' => 'app/Support/Seo/CrawlPolicy.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => LegacyUrlScanner::captureRoot(),
                 'count' => 1,
-                'reason' => '撤去した切替 endpoint の route 名を「撤去済みである」と説明する 1 行であり、'
-                    .'撤去の記録としてこの名前を書けないと、何を撤去したのかが文書から読めなくなる。',
+                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
+                'consumer' => null,
+                'symbol' => null,
+                'reason' => 'クローラに拒否させる経路の宣言であり、正規の分岐入口そのものを名指ししている (入口は認証必須なので索引させない)',
             ],
             [
                 'path' => 'doc/08_システムアーキテクチャ設計.md',
                 'rule' => LegacyUrlScanner::RULE_MARKDOWN_TEXT,
-                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
+                'matched' => self::legacyRootEndingWith('ojects'),
                 'count' => 1,
-                'reason' => 'オブジェクトストレージのキー prefix の書式であり、画面の URL ではない。'
-                    .'鍵は組織 id で始まる別の体系で、URL の組織セグメントとは無関係である。',
+                'kind' => LegacyUrlAllowanceKind::StorageObjectKey,
+                'consumer' => null,
+                'symbol' => null,
+                'reason' => 'オブジェクトストレージのキー prefix の書式であり、画面の URL ではない。鍵は組織 id で始まる別の体系で URL の組織セグメントとは無関係である',
             ],
             [
                 'path' => 'doc/09_詳細実装設計.md',
                 'rule' => LegacyUrlScanner::RULE_MARKDOWN_TEXT,
-                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
+                'matched' => self::legacyRootEndingWith('ojects'),
+                'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::StorageObjectKey,
+                'consumer' => null,
+                'symbol' => null,
+                'reason' => 'テイク動画を置くオブジェクトストレージの鍵の書式であり、画面の URL ではない。鍵は組織 id で始まる別の体系である',
+            ],
+            [
+                'path' => 'doc/10_実装仕様.md',
+                'rule' => LegacyUrlScanner::RULE_MARKDOWN_TEXT,
+                'matched' => LegacyUrlScanner::captureRoot(),
                 'count' => 1,
-                'reason' => 'オブジェクトストレージに置くテイク動画の鍵の書式であり、画面の URL ではない。'
-                    .'鍵は組織 id で始まる別の体系で、URL の組織セグメントとは無関係である。',
+                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
+                'consumer' => null,
+                'symbol' => null,
+                'reason' => '撮影 PWA の prefix が「PWA 専用」を意味しないことの説明で、正規の分岐入口そのものを指している',
+            ],
+            [
+                'path' => 'docs/architecture.md',
+                'rule' => LegacyUrlScanner::RULE_MARKDOWN_TEXT,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 3,
+                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
+                'consumer' => null,
+                'symbol' => null,
+                'reason' => 'テイク API の prefix の由来と、組織文脈を持たない入口 2 本の説明であり、いずれも正規の分岐入口そのものを指している',
+            ],
+            [
+                'path' => 'docs/architecture.md',
+                'rule' => LegacyUrlScanner::RULE_REMOVED_ROUTE_NAME,
+                'matched' => LegacyUrlScanner::removedRouteName(),
+                'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::AbsenceAssertion,
+                'consumer' => null,
+                'symbol' => null,
+                'reason' => '撤去した切替 endpoint の route 名を「撤去済みである」と説明する 1 行であり、撤去の記録としてこの名前が書けないと何を撤去したのかが文書から読めなくなる',
+            ],
+            [
+                'path' => 'docs/supported-browsers.md',
+                'rule' => LegacyUrlScanner::RULE_MARKDOWN_TEXT,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
+                'consumer' => null,
+                'symbol' => null,
+                'reason' => 'PWA の manifest が持つ start_url の値の説明であり、正規の分岐入口そのものを指している',
+            ],
+            [
+                'path' => 'public/manifest.webmanifest',
+                'rule' => LegacyUrlScanner::RULE_DATA_TEXT,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
+                'consumer' => null,
+                'symbol' => null,
+                'reason' => 'ホーム画面追加の start_url であり、正規の分岐入口そのものである (組織を持たない入口であることが仕様)',
             ],
             [
                 'path' => 'resources/js/types/dashboard.ts',
                 'rule' => LegacyUrlScanner::RULE_SCRIPT_LITERAL,
+                'matched' => self::legacyRootEndingWith('illing'),
+                'count' => 1,
                 'kind' => LegacyUrlAllowanceKind::OrganizationRelativePath,
+                'consumer' => 'resources/js/pages/Dashboard.svelte',
+                'symbol' => 'BILLING_CALLOUTS',
+                'reason' => '課金 callout の CTA を持つ静的な表であり、画面から識別名を受け取れない。値は組織相対パスで利用側が組織 URL へ写す',
+            ],
+            [
+                'path' => 'resources/js/types/dashboard.ts',
+                'rule' => LegacyUrlScanner::RULE_SCRIPT_LITERAL,
+                'matched' => self::legacyRootEndingWith('arding'),
+                'count' => 2,
+                'kind' => LegacyUrlAllowanceKind::OrganizationRelativePath,
+                'consumer' => 'resources/js/pages/Dashboard.svelte',
+                'symbol' => 'BILLING_CALLOUTS',
+                'reason' => '課金 callout の CTA を持つ静的な表であり、画面から識別名を受け取れない。値は組織相対パスで利用側が組織 URL へ写す',
+            ],
+            [
+                'path' => 'resources/views/app.blade.php',
+                'rule' => LegacyUrlScanner::RULE_BLADE_TEXT,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
+                'consumer' => null,
+                'symbol' => null,
+                'reason' => 'PWA 専用 manifest を出し分ける条件の説明コメントであり、正規の分岐入口そのものを指している',
+            ],
+            [
+                'path' => 'tests/Architecture/ExternalClientTimeoutInventoryTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
+                'consumer' => null,
+                'symbol' => null,
+                'reason' => '到達境界の走査根をリポジトリルートからの相対ディレクトリとして組み立てている箇所であり、URL ではなくファイルシステムのパスである',
+            ],
+            [
+                'path' => 'tests/Architecture/ExternalSeamInventoryTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
+                'consumer' => null,
+                'symbol' => null,
+                'reason' => '外部到達点の走査根をリポジトリルートからの相対ディレクトリとして組み立てている箇所であり、URL ではなくファイルシステムのパスである',
+            ],
+            [
+                'path' => 'tests/Architecture/FlashNotificationRelayDriftTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
+                'consumer' => null,
+                'symbol' => null,
+                'reason' => '通知中継の走査根をリポジトリルートからの相対ディレクトリとして組み立てている箇所であり、URL ではなくファイルシステムのパスである',
+            ],
+            [
+                'path' => 'tests/Architecture/InvitationResolutionInventoryTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
+                'consumer' => null,
+                'symbol' => null,
+                'reason' => '招待解決の走査根をリポジトリルートからの相対ディレクトリとして組み立てている箇所であり、URL ではなくファイルシステムのパスである',
+            ],
+            [
+                'path' => 'tests/Architecture/LlmDefenseConfigGateTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
+                'consumer' => null,
+                'symbol' => null,
+                'reason' => 'LLM 防御設定の走査根をリポジトリルートからの相対ディレクトリとして組み立てている箇所であり、URL ではなくファイルシステムのパスである',
+            ],
+            [
+                'path' => 'tests/Architecture/MembershipWriteLockInventoryTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
+                'consumer' => null,
+                'symbol' => null,
+                'reason' => '所属書き込みの走査根をリポジトリルートからの相対ディレクトリとして組み立てている箇所であり、URL ではなくファイルシステムのパスである',
+            ],
+            [
+                'path' => 'tests/Architecture/PostBootRouteMutationInventoryTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
+                'consumer' => null,
+                'symbol' => null,
+                'reason' => '後付け経路の走査根をリポジトリルートからの相対ディレクトリとして組み立てている箇所であり、URL ではなくファイルシステムのパスである',
+            ],
+            [
+                'path' => 'tests/Architecture/PromptDefenseWindowGateTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => LegacyUrlScanner::captureRoot(),
                 'count' => 3,
-                'reason' => '課金 callout の CTA を持つ静的な表であり、画面から識別名を受け取れない。'
-                    .'値は組織相対パスで、利用側 (Dashboard.svelte) が currentOrgUrl() で組織 URL へ写す。',
+                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
+                'consumer' => null,
+                'symbol' => null,
+                'reason' => 'prompt factory の走査根をリポジトリルートからの相対ディレクトリとして組み立てている箇所であり、URL ではなくファイルシステムのパスである',
+            ],
+            [
+                'path' => 'tests/Browser/CaptureAppBoundaryTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 7,
+                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
+                'consumer' => null,
+                'symbol' => null,
+                'reason' => '撮影 PWA が入口の配下から自動で離脱しないことを実ブラウザで確かめる検査であり、正規の分岐入口そのものを叩く',
+            ],
+            [
+                'path' => 'tests/Feature/Billing/GateInversionF07RegressionTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
+                'consumer' => null,
+                'symbol' => null,
+                'reason' => '未契約の組織でも撮影の入口へ到達できることの回帰であり、正規の分岐入口そのものを叩く',
+            ],
+            [
+                'path' => 'tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
+                'consumer' => null,
+                'symbol' => null,
+                'reason' => '課金ゲートが撮影の入口を遮らないことの検査であり、正規の分岐入口そのものを叩く',
+            ],
+            [
+                'path' => 'tests/Feature/Capture/CaptureManualBrowsingTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 2,
+                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
+                'consumer' => null,
+                'symbol' => null,
+                'reason' => '撮影 PWA の一覧へ入る導線の検査であり、正規の分岐入口そのものを叩く',
+            ],
+            [
+                'path' => 'tests/Feature/Capture/CapturePwaScopeTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 3,
+                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
+                'consumer' => null,
+                'symbol' => null,
+                'reason' => 'PWA の scope と start_url の契約を固定する検査であり、正規の分岐入口そのものを名指しする',
+            ],
+            [
+                'path' => 'tests/Feature/Organization/OrganizationEntryTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 7,
+                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
+                'consumer' => null,
+                'symbol' => null,
+                'reason' => '組織文脈を持たない入口の分岐 (所属 0 / 1 / 複数) を固定する検査であり、正規の分岐入口そのものを叩く',
             ],
             [
                 'path' => 'tests/Feature/Organizations/TwoFactorEnforcementTest.php',
                 'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => self::legacyRootEndingWith('illing'),
+                'count' => 1,
                 'kind' => LegacyUrlAllowanceKind::OrganizationRelativePath,
-                'count' => 3,
-                'reason' => 'データセットが渡すのは組織 URL の**後ろに継ぐ suffix** であり、'
-                    .'同じテストの中で "/organizations/{slug}" と連結してから要求している (単独の URL ではない)。',
+                'consumer' => 'tests/Feature/Organizations/TwoFactorEnforcementTest.php',
+                'symbol' => '{$suffix}',
+                'reason' => 'データセットが渡すのは組織 URL の後ろに継ぐ suffix であり、同じテストの中で組織 URL と連結してから要求している',
             ],
             [
-                'path' => 'tests/Unit/Services/Storage/FakeStorageKeyTest.php',
+                'path' => 'tests/Feature/Organizations/TwoFactorEnforcementTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => self::legacyRootEndingWith('hboard'),
+                'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::OrganizationRelativePath,
+                'consumer' => 'tests/Feature/Organizations/TwoFactorEnforcementTest.php',
+                'symbol' => '{$suffix}',
+                'reason' => 'データセットが渡すのは組織 URL の後ろに継ぐ suffix であり、同じテストの中で組織 URL と連結してから要求している',
+            ],
+            [
+                'path' => 'tests/Feature/Organizations/TwoFactorEnforcementTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => self::legacyRootEndingWith('ojects'),
+                'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::OrganizationRelativePath,
+                'consumer' => 'tests/Feature/Organizations/TwoFactorEnforcementTest.php',
+                'symbol' => '{$suffix}',
+                'reason' => 'データセットが渡すのは組織 URL の後ろに継ぐ suffix であり、同じテストの中で組織 URL と連結してから要求している',
+            ],
+            [
+                'path' => 'tests/Support/ExternalSeam/ExternalSeamInventory.php',
                 'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 1,
                 'kind' => LegacyUrlAllowanceKind::FilesystemPath,
+                'consumer' => null,
+                'symbol' => null,
+                'reason' => '外部到達点の目録が持つ走査根の相対ディレクトリであり、URL ではなくファイルシステムのパスである',
+            ],
+            [
+                'path' => 'tests/Unit/Services/Storage/FakeStorageKeyTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => self::legacyRootEndingWith('ojects'),
                 'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::StorageObjectKey,
+                'consumer' => null,
+                'symbol' => null,
                 'reason' => 'オブジェクトストレージの鍵 (保存先のパス) を組み立てる期待値であり、画面の URL ではないので組織セグメントを持たない',
-            ],
-        ];
+            ],        ];
     }
 
     /**
-     * 許可の件数表 ("path\0rule" => count)。
+     * 許可の件数表 ("path\0rule\0matched" => count)。**キーの重複は例外**にする。
      *
      * @return array<string, int>
      */
@@ -111,9 +401,143 @@ public static function counts(): array
     {
         $counts = [];
         foreach (self::entries() as $entry) {
-            $counts[$entry['path']."\0".$entry['rule']] = $entry['count'];
+            $key = self::keyOf($entry['path'], $entry['rule'], $entry['matched']);
+            if (array_key_exists($key, $counts)) {
+                throw new RuntimeException("許可目録のキーが重複しています: {$entry['path']} / {$entry['rule']}");
+            }
+            $counts[$key] = $entry['count'];
         }
 
         return $counts;
     }
+
+    /** 目録のキー (パス + 規則 ID + 一致した語)。 */
+    public static function keyOf(string $path, string $rule, string $matched): string
+    {
+        return $path."\0".$rule."\0".$matched;
+    }
+
+    /**
+     * 区分ごとの前提が満たされていないときの理由 (満たしていれば null)。
+     *
+     * ★ここが `kind` を**判定に使う**唯一の場所である。前提を持たない区分は作らない。
+     *
+     * @param  array{path: string, rule: string, matched: string, count: int, kind: LegacyUrlAllowanceKind, consumer: ?string, symbol: ?string, reason: string}  $entry
+     * @param  string  $captureEntryUri  route 表の `capture.entry` の URI (先頭スラッシュつき)
+     * @param  list<string>  $observedPaths  この登録が許した出現の path 全体
+     */
+    public static function preconditionViolation(
+        array $entry,
+        string $repositoryRoot,
+        string $captureEntryUri,
+        array $observedPaths,
+    ): ?string {
+        $contents = @file_get_contents($repositoryRoot.'/'.$entry['path']);
+        if ($contents === false) {
+            return "登録したパスが読めません: {$entry['path']}";
+        }
+        if ($observedPaths === []) {
+            return '許可した出現が 1 件も見つかりません (登録が実在しない)';
+        }
+
+        return match ($entry['kind']) {
+            // ★**出現ごとの path 全体**が正規の分岐入口そのものであること。
+            //   `/app` を `/app/projects/1` へ置き換えると path が変わって落ちる。
+            LegacyUrlAllowanceKind::CanonicalCaptureEntry => self::allPathsAre($observedPaths, $captureEntryUri)
+                ?? ($captureEntryUri === LegacyUrlScanner::captureRoot()
+                    ? null
+                    : "route 表の入口の URI が撮影 PWA の根と一致しません: {$captureEntryUri}"),
+            // ★**出現ごとの path 全体**が実在するディレクトリであること。
+            LegacyUrlAllowanceKind::FilesystemPath => self::allPathsAreDirectories($observedPaths, $repositoryRoot),
+            LegacyUrlAllowanceKind::StorageObjectKey => self::containsAny($contents, self::STORAGE_KEY_MARKERS)
+                ? null
+                : '保存先の鍵を扱っている印が同じファイルに現れません',
+            LegacyUrlAllowanceKind::AbsenceAssertion => str_contains($contents, self::REMOVAL_MARKER)
+                ? null
+                : '撤去を説明する語が同じファイルに現れません',
+            LegacyUrlAllowanceKind::OrganizationRelativePath => self::consumerViolation($entry, $repositoryRoot),
+        };
+    }
+
+    /**
+     * すべての出現の path が期待値と一致するか (違えば理由)。
+     *
+     * @param  list<string>  $observedPaths
+     */
+    private static function allPathsAre(array $observedPaths, string $expected): ?string
+    {
+        foreach ($observedPaths as $path) {
+            if ($path !== $expected) {
+                return "正規の分岐入口そのものではない出現があります: {$path}";
+            }
+        }
+
+        return null;
+    }
+
+    /**
+     * すべての出現の path が実在するディレクトリか (違えば理由)。
+     *
+     * @param  list<string>  $observedPaths
+     */
+    private static function allPathsAreDirectories(array $observedPaths, string $repositoryRoot): ?string
+    {
+        foreach ($observedPaths as $path) {
+            if (! is_dir($repositoryRoot.'/'.ltrim($path, '/'))) {
+                return "実在するディレクトリではありません: {$path}";
+            }
+        }
+
+        return null;
+    }
+
+    /**
+     * 印のどれかが本文に現れるか。
+     *
+     * @param  list<string>  $markers
+     */
+    private static function containsAny(string $contents, array $markers): bool
+    {
+        foreach ($markers as $marker) {
+            if (str_contains($contents, $marker)) {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
+    /**
+     * 組織相対パスの登録が名指しした**利用側**の検査。
+     *
+     * @param  array{path: string, rule: string, matched: string, count: int, kind: LegacyUrlAllowanceKind, consumer: ?string, reason: string}  $entry
+     */
+    private static function consumerViolation(array $entry, string $repositoryRoot): ?string
+    {
+        $consumer = $entry['consumer'];
+        $symbol = $entry['symbol'];
+        if ($consumer === null || $symbol === null) {
+            return '組織相対パスの登録は利用側のファイルと、そこで値を受ける記号を名指しすること';
+        }
+
+        $source = @file_get_contents($repositoryRoot.'/'.$consumer);
+        if ($source === false) {
+            return "利用側のファイルが読めません: {$consumer}";
+        }
+
+        // 利用側が「組織 URL を組み立てている」ことを確かめる。
+        // module の取り込み (script) か、組織セグメントつきの組み立て (PHP テスト) のどちらか。
+        $buildsOrganizationUrl = str_contains($source, LegacyUrlScanner::ORGANIZATION_URL_MODULE)
+            || str_contains($source, '/'.LegacyUrlScanner::organizationSegment().'/');
+        if (! $buildsOrganizationUrl) {
+            return "利用側が組織 URL を組み立てていません: {$consumer}";
+        }
+
+        // ★登録した値を受ける記号が利用側に現れること。
+        //   **これはデータフローの証明ではない** (同じファイルにあることまでしか言えない)。
+        //   値が本当にその builder へ渡ることは利用側の component テスト / Feature テストが担う。
+        return str_contains($source, $symbol)
+            ? null
+            : "利用側に登録した記号が現れません: {$consumer} / {$symbol}";
+    }
 }
diff --git a/tests/Support/LegacyUrl/LegacyUrlAllowanceKind.php b/tests/Support/LegacyUrl/LegacyUrlAllowanceKind.php
index 33f5c336..2c9d23f6 100644
--- a/tests/Support/LegacyUrl/LegacyUrlAllowanceKind.php
+++ b/tests/Support/LegacyUrl/LegacyUrlAllowanceKind.php
@@ -7,33 +7,51 @@
 /**
  * 旧 URL 検出の許可区分。
  *
- * ★区分は**限定列挙**である。「なんとなく直せない」を入れる口を作らない。
- *   新しい区分を足す操作そのものがレビューに見えることが目的である。
+ * ★区分は**限定列挙**であり、**それぞれが機械で確かめられる前提を持つ**
+ *   (`LegacyUrlAllowance::preconditionViolation()` が区分ごとに検査する)。
+ *   前提を持たない区分は「説明ラベル」にすぎず、走査器共通規約 (d)
+ *   「集めた走査結果を判定に使わない形を作らない」に触れる。
+ * ★新しい区分を足す操作そのものがレビューに見えることが目的なので、
+ *   区分を増やすときは**前提の検査も同じ変更で書く**。
  */
 enum LegacyUrlAllowanceKind: string
 {
     /**
-     * URL ではなく**保存先のパス**である (ファイルシステム / オブジェクトストレージの鍵)。
+     * **正規の分岐入口** (`capture.entry`) としての出現。
      *
-     * 走査根を組み立てる `dirname(__DIR__, 2).'/app/Prompts'` や、
-     * 保存先の鍵 `orgs/{org}/projects/…` のような形は、字面が URL の根と一致するだけで
-     * 画面の経路ではない。
+     * 前提: 一致した語が撮影 PWA の根そのものであり、かつ route 表の `capture.entry` の
+     * URI がその語と一致すること (入口が動いたら登録ごと赤くなる)。
+     */
+    case CanonicalCaptureEntry = 'canonical_capture_entry';
+
+    /**
+     * URL ではなく**リポジトリ内のディレクトリのパス**である。
+     *
+     * 前提: 一致した語をリポジトリルートからの相対パスとして解決したとき、
+     * **実在するディレクトリ**であること (`/app` → `app/`)。
      */
     case FilesystemPath = 'filesystem_path';
 
     /**
-     * 旧 URL が**もう存在しないこと自体を確かめている**記述。
+     * URL ではなく**オブジェクトストレージの鍵**である。
+     *
+     * 前提: 同じファイルに鍵の接頭辞 (`LegacyUrlAllowance::STORAGE_KEY_PREFIX`) が現れること。
+     */
+    case StorageObjectKey = 'storage_object_key';
+
+    /**
+     * 撤去したものが**もう無いこと自体を説明している**記述。
      *
-     * 「この URL は 404 になる」ことを固定するテストは、対象の旧 URL を持つのが役目である。
+     * 前提: 同じファイルに撤去の語 (`LegacyUrlAllowance::REMOVAL_MARKER`) が現れること。
      */
     case AbsenceAssertion = 'absence_assertion';
 
     /**
      * **組織相対パス**として宣言された値で、組織 prefix は利用側が付ける。
      *
-     * 静的な表 (画面から slug を受け取れない定数) が持つ相対パスがこれに当たる。
-     * 登録するときは「利用側が必ず組織 URL の入口を通す」ことを同じ変更で確かめること
-     * (通していなければそれは旧 URL であり、許可ではなく修正が要る)。
+     * 前提: 登録が名指しした**利用側のファイル**が実在し、そこに組織 URL 組み立ての入口
+     * (`LegacyUrlScanner::ORGANIZATION_URL_MODULE` の関数) が現れること。
+     * 利用側を書かない登録は作れない (「なんとなく直せない」を入れる口を塞ぐ)。
      */
     case OrganizationRelativePath = 'organization_relative_path';
 }
diff --git a/tests/Support/LegacyUrl/LegacyUrlOccurrence.php b/tests/Support/LegacyUrl/LegacyUrlOccurrence.php
index 60124deb..c1e4618f 100644
--- a/tests/Support/LegacyUrl/LegacyUrlOccurrence.php
+++ b/tests/Support/LegacyUrl/LegacyUrlOccurrence.php
@@ -7,8 +7,11 @@
 /**
  * 旧 URL / 撤去 route 名の検出 1 件。
  *
- * ★`ruleId` は**構文文脈まで識別する安定 ID** である (単なる `legacy-path` にしない)。
- *   同じファイルの中で別の構文の出現と置き換わっても件数だけでは通らない形にするため。
+ * ★`ruleId` が識別するのは**抽出方式** (どの種別のファイルをどう読んだか) までである。
+ *   同じファイル内での構文の入れ替わりまでは表さないので、許可目録は
+ *   `ruleId` に加えて**一致した語 (`matched`)** と**件数**でキーを作る
+ *   (`LegacyUrlAllowance::keyOf()`)。ここを `ruleId` だけにすると
+ *   「同じ件数で別の旧 URL へ置き換える」迂回が通る。
  */
 final readonly class LegacyUrlOccurrence
 {
@@ -19,13 +22,20 @@ public function __construct(
         public int $line,
         /** 検出規則の安定 ID (`LegacyUrlScanner::RULE_*`)。 */
         public string $ruleId,
-        /** 一致した語 (旧パスの根、または撤去 route 名)。 */
+        /** 一致した語 (旧パスの根、または撤去 route 名)。許可目録のキーに使う。 */
         public string $matched,
+        /**
+         * 根から終端までの **path 全体** (撤去 route 名のときは語そのもの)。
+         *
+         * ★許可目録の**区分ごとの前提**はこちらを見る。根だけで許すと
+         *   「同じ根で別の path へ置き換える」迂回を止められない。
+         */
+        public string $path,
     ) {}
 
     /** 失敗メッセージ用の 1 行表現。 */
     public function describe(): string
     {
-        return "{$this->relative}:{$this->line} [{$this->ruleId}] {$this->matched}";
+        return "{$this->relative}:{$this->line} [{$this->ruleId}] {$this->path}";
     }
 }
diff --git a/tests/Support/LegacyUrl/LegacyUrlScanRoots.php b/tests/Support/LegacyUrl/LegacyUrlScanRoots.php
index 63d8681f..d1a68aaf 100644
--- a/tests/Support/LegacyUrl/LegacyUrlScanRoots.php
+++ b/tests/Support/LegacyUrl/LegacyUrlScanRoots.php
@@ -54,7 +54,6 @@ final class LegacyUrlScanRoots
      */
     private const array NOT_SCANNED_PATHS = [
         'devnotes/' => '設計・レビューの記録であり実行されない。当時の URL 表記は履歴であって参照ではないため、書き換えると記録が事実でなくなる',
-        'routes/' => 'route 定義の URI は group の prefix からの相対セグメントであり、組織 prefix の中では根だけの記述が正しい姿になる。実 route 表が 1 本残らず組織 URL 配下にあることは OrganizationScopedRouteCoverageTest が解決済みの route 表で固定するので、ここを走査しても新しい保証は増えない',
         'doc/reference/' => '現場から預かった業務資料 (SOP・撮影シナリオ・モックアップ・プロンプト案) であり、本アプリの URL を 1 つも持たない。編集の権利も本リポジトリに無い',
         'docs/TODO-closed.md' => 'クローズ済み TODO の記録は当時の事実である。過去の作業説明に現れる旧 URL を書き換えると記録そのものが嘘になるため、履歴として走査から外す',
         'composer.lock' => '依存解決の生成物であり人が書く記述を含まない。パッケージ名や URL は上流の値であって本アプリの経路ではない',
@@ -77,8 +76,6 @@ final class LegacyUrlScanRoots
         'docx' => 'オフィス文書のバイナリであり、テキストとしての URL 参照を持たない (現場から預かった資料)',
         'xlsx' => 'オフィス文書のバイナリであり、テキストとしての URL 参照を持たない (撮影シナリオ表)',
         'mp4' => '動画バイナリであり、テキストとしての URL 参照を持たない (見本素材)',
-        'patch' => 'レビュー履歴として保存した差分の生の写しであり、当時のコードの記録である (置き場所は devnotes 配下だけ)',
-        'err' => '実行時の標準エラー出力の記録であり、当時の実行の記録である (置き場所は devnotes 配下だけ)',
     ];
 
     /**
@@ -93,7 +90,10 @@ final class LegacyUrlScanRoots
         'tests/Architecture/fixtures/legacy-url/legacy-paths.md' => '旧 URL を検出できることを確かめる正例の見本。検出したい語をわざと持つのが役目であり、rule ID では表せない',
         'tests/Architecture/fixtures/legacy-url/allowed-paths.md' => '誤検出してはいけない新 URL・無関係な語の見本。旧 URL の根と紛らわしい語をわざと持つのが役目である',
         'tests/Architecture/fixtures/legacy-url/legacy-php-source.txt' => 'PHP の文字列リテラルとコメントの扱いを分ける検出力の見本。旧 URL をコメントとリテラルの両方に持つ',
-        'tests/Architecture/fixtures/legacy-url/legacy-script-source.txt' => 'script の文字列リテラル・コメント・組織 URL 組み立ての入口の扱いを分ける検出力の見本である',
+        'tests/Architecture/fixtures/legacy-url/legacy-script-source.txt' => 'script の文字列リテラル・コメント・正規表現リテラル・組織 URL 組み立ての入口の扱いを分ける検出力の見本である',
+        'tests/Architecture/fixtures/legacy-url/legacy-shadowed-builder.txt' => '入口の module を取り込まずに同名関数を自前定義した形の見本。規則 3 の免除が効かないことを確かめる',
+        'tests/Architecture/fixtures/legacy-url/legacy-data-source.txt' => 'JSON / webmanifest の値と、1 行に 2 個の撤去 route 名を持つ見本。件数の数え方を確かめる',
+        'tests/Architecture/fixtures/legacy-url/legacy-blade-source.txt' => 'Blade テンプレートの属性値と route helper 経由の記述を分ける検出力の見本である',
     ];
 
     /**
@@ -274,6 +274,26 @@ public static function classify(string $relative): ?array
         return null;
     }
 
+    /**
+     * 内容の分類 (純関数。**母集団の確定も自己検証も必ずここを通る**)。
+     *
+     * ★同じ判定を 2 本持たない。NUL 判定と UTF-8 検証を 1 つの入口に閉じることで、
+     *   合成した文字列からも実母集団からも同じ経路で確かめられる。
+     *   返すのは「走査対象に分類したのに読めない」理由 (問題なければ null)。
+     */
+    public static function contentsUnresolvedReason(string $contents): ?string
+    {
+        if (str_contains($contents, "\0")) {
+            // 走査対象に分類したのにバイナリ = 分類が誤っている。無言で外さない
+            return '走査対象に分類されているが NUL を含む (分類の誤り)';
+        }
+        if (! mb_check_encoding($contents, 'UTF-8')) {
+            return 'UTF-8 として不正';
+        }
+
+        return null;
+    }
+
     /** 母集団を確定する (唯一の経路)。 */
     public static function population(): LegacyUrlScanPopulation
     {
@@ -323,14 +343,9 @@ public static function population(): LegacyUrlScanPopulation
 
                 continue;
             }
-            if (str_contains($contents, "\0")) {
-                // ★走査対象に分類したのにバイナリ = 分類が誤っている。無言で外さない
-                $unresolved[$relative] = '走査対象に分類されているが NUL を含む (分類の誤り)';
-
-                continue;
-            }
-            if (! mb_check_encoding($contents, 'UTF-8')) {
-                $unresolved[$relative] = 'UTF-8 として不正';
+            $contentsReason = self::contentsUnresolvedReason($contents);
+            if ($contentsReason !== null) {
+                $unresolved[$relative] = $contentsReason;
 
                 continue;
             }
diff --git a/tests/Support/LegacyUrl/LegacyUrlScanner.php b/tests/Support/LegacyUrl/LegacyUrlScanner.php
index 024acc88..e82b3bd0 100644
--- a/tests/Support/LegacyUrl/LegacyUrlScanner.php
+++ b/tests/Support/LegacyUrl/LegacyUrlScanner.php
@@ -111,15 +111,13 @@ public static function legacyRoots(): array
     }
 
     /**
-     * 撮影 PWA の根 (断片から組み立てる)。**この根だけは配下つきでのみ旧 URL である**。
+     * 撮影 PWA の根 (断片から組み立てる)。
      *
-     * ★裸のこれは**正規の分岐入口** (`capture.entry`) であり、今後も残る
-     *   (PWA の `start_url` / robots の宣言 / 入口の Feature テストが正しく持つ)。
-     *   旧 URL なのは配下 (`…/projects/…` 等) を持つ形だけで、そちらは
-     *   組織 URL 配下 (`/organizations/{slug}/app/…`) へ移設済みである。
-     * ★この「配下つきのみ」規則があるので、正規入口のための許可目録は要らない
-     *   (許可目録は**目録の中身が旧 URL 文字列を持つ**という再帰を招きやすく、
-     *   規則で表せるならそちらが良い)。
+     * ★裸のこれは**正規の分岐入口** (`capture.entry`) として残るが、**規則では免除しない**。
+     *   免除すると「どこにでも直書きしてよい」ことになり、
+     *   「入口への導線は route helper 経由だけ」という不変条件が消えるためである。
+     *   正規入口として実在する出現は `LegacyUrlAllowance` へ**パス + 規則 + 語 + 件数**で
+     *   exact-fit 登録する (目録は旧 URL 文字列を持たず、語は走査器が組み立てた値を使う)。
      */
     public static function captureRoot(): string
     {
@@ -132,6 +130,13 @@ public static function removedRouteName(): string
         return 'organizations.'.'switch';
     }
 
+    /**
+     * 組織 URL 組み立ての唯一の入口 module (規則 3 の前提)。
+     *
+     * ★断片から組み立てる必要は無い (旧 URL の語ではない)。
+     */
+    public const string ORGANIZATION_URL_MODULE = '@/lib/org-url';
+
     /** 組織セグメントの接頭辞 (断片から組み立てる。規則 2 の判定に使う)。 */
     public static function organizationSegment(): string
     {
@@ -148,20 +153,25 @@ public static function scanFile(LegacyUrlScannedFile $file): array
         $occurrences = [];
 
         foreach (self::extract($file) as $chunk) {
-            foreach (self::matchesIn($chunk['value']) as $matched) {
+            foreach (self::matchesIn($chunk['value']) as $match) {
                 $occurrences[] = new LegacyUrlOccurrence(
                     relative: $file->relative,
                     line: $chunk['line'],
                     ruleId: $file->ruleId,
-                    matched: $matched,
+                    matched: $match['root'],
+                    path: $match['path'],
                 );
             }
-            if (str_contains($chunk['value'], self::removedRouteName())) {
+            // ★出現ごとに 1 件数える (1 行 1 件にすると、同じ行へ 2 個目を足しても
+            //   件数が変わらず exact-fit の許可目録を迂回できる)
+            $removedRouteName = self::removedRouteName();
+            for ($i = substr_count($chunk['value'], $removedRouteName); $i > 0; $i--) {
                 $occurrences[] = new LegacyUrlOccurrence(
                     relative: $file->relative,
                     line: $chunk['line'],
                     ruleId: self::RULE_REMOVED_ROUTE_NAME,
-                    matched: self::removedRouteName(),
+                    matched: $removedRouteName,
+                    path: $removedRouteName,
                 );
             }
         }
@@ -186,12 +196,52 @@ public static function extract(LegacyUrlScannedFile $file): array
         }
 
         if ($file->ruleId === self::RULE_PHP_LITERAL) {
-            return SourceLiterals::php($file->contents);
+            $literals = SourceLiterals::php($file->contents);
+
+            return str_starts_with($file->relative, 'routes/')
+                ? self::withoutRouteDefinitionUris($file->contents, $literals)
+                : $literals;
         }
 
         return self::scriptLiteralsWithOrgUrlAllowance($file->contents, str_ends_with($file->relative, '.py'));
     }
 
+    /**
+     * route 定義の **URI 引数**だけを外したリテラル列 (`routes/` 専用の規則 4)。
+     *
+     * ★`routes/web.php` の URI は group の prefix からの**相対セグメント**であり、
+     *   組織 prefix の中では根だけの記述が正しい姿になる。解決済みの route 表が
+     *   1 本残らず組織 URL 配下にあることは `OrganizationScopedRouteCoverageTest` が固定する。
+     * ★外すのは**その 1 引数だけ**である。`redirect('/projects')` のような route 定義以外の
+     *   リテラルと、撤去 route 名は `routes/` の中でも引き続き検出する
+     *   (ファイルごと走査から外すと、そこが抜け道になる)。
+     * ★判定は「リテラルの直前が route 定義の呼び出しで、括弧も改行も跨がない」ことである。
+     *   動的に組み立てた URI (`Route::get($uri, …)`) はそもそもリテラルではないので対象外である。
+     *
+     * @param  list<array{line: int, offset: int, value: string}>  $literals
+     * @return list<array{line: int, offset: int, value: string}>
+     */
+    private static function withoutRouteDefinitionUris(string $source, array $literals): array
+    {
+        $kept = [];
+        foreach ($literals as $literal) {
+            $before = substr($source, 0, $literal['offset']);
+            // ★外すのは **URI を受ける引数**だけである。`->name()` / `->as()` を外すと
+            //   撤去 route 名の台帳が routes/ の中で丸ごと効かなくなる (実測で指摘された穴)。
+            $isRouteUri = preg_match(
+                '/(?:Route::(?:get|post|put|patch|delete|options|any|view|redirect|permanentRedirect|match|prefix)'
+                .'|->prefix)\(\s*(?:\[[^\]]*\]\s*,\s*)?$/',
+                $before,
+            ) === 1;
+            if ($isRouteUri) {
+                continue;
+            }
+            $kept[] = $literal;
+        }
+
+        return $kept;
+    }
+
     /**
      * script のリテラル抽出。**組織 URL 組み立ての入口へ渡した相対パスは除く** (規則 3)。
      *
@@ -202,9 +252,16 @@ public static function extract(LegacyUrlScannedFile $file): array
      */
     private static function scriptLiteralsWithOrgUrlAllowance(string $source, bool $hashComments): array
     {
+        // ★前後関係の判定は**コメントを潰した写し**で行う (位置は元と同じ)。
+        //   生ソースを見ると `// currentOrgUrl(` の 1 行が次のリテラルまで届いて免除になる
+        $masked = SourceLiterals::maskComments($source, $hashComments);
+        // ★入口の名前は **import 宣言から解決する**。部分文字列一致にすると
+        //   コメントに module 名を書くだけで免除の前提を満たせてしまう (実測で指摘された穴)
+        $builderNames = self::importedOrganizationUrlBuilders($masked);
+
         $kept = [];
         foreach (SourceLiterals::script($source, $hashComments) as $literal) {
-            if (self::isOrganizationUrlBuilderArgument($source, $literal['offset'])) {
+            if ($builderNames !== [] && self::isOrganizationUrlBuilderArgument($masked, $literal['offset'], $builderNames)) {
                 continue;
             }
             $kept[] = $literal;
@@ -214,22 +271,71 @@ private static function scriptLiteralsWithOrgUrlAllowance(string $source, bool $
     }
 
     /**
-     * その位置のリテラルが `orgUrl(...)` / `currentOrgUrl(...)` の引数として現れているか。
+     * 組織 URL 組み立ての module から**実際に取り込まれたローカル名**。
+     *
+     * ★`import { orgUrl, currentOrgUrl as u } from "@/lib/org-url";` の形を構文で読む。
+     *   別名つき取り込みは別名側を返す (呼び出しに現れる名前で照合するため)。
+     * ★入力は**コメントを潰した写し**である (コメントの中の import 宣言では前提を満たせない)。
      *
-     * ★「開き括弧を閉じないまま入口名まで遡れるか」で判定する。`[^()]*` が括弧を跨がせないので、
-     *   入口を呼んだ後の別の呼び出し (`foo(bar(), '/x')`) は一致しない。
+     * @return list<string>
      */
-    private static function isOrganizationUrlBuilderArgument(string $source, int $literalOffset): bool
+    private static function importedOrganizationUrlBuilders(string $maskedSource): array
     {
-        $before = substr($source, 0, $literalOffset);
+        $pattern = '/import\s*\{([^}]*)\}\s*from\s*[\'"]'
+            .preg_quote(self::ORGANIZATION_URL_MODULE, '/')
+            .'[\'"]/';
+        if (preg_match_all($pattern, $maskedSource, $matches) === false) {
+            return [];
+        }
 
-        return preg_match('/(?:currentOrgUrl|orgUrl)\(\s*[^()]*$/', $before) === 1;
+        $names = [];
+        foreach ($matches[1] as $clause) {
+            foreach (explode(',', $clause) as $specifier) {
+                $parts = preg_split('/\s+as\s+/', trim($specifier)) ?: [];
+                $local = trim((string) end($parts));
+                if (preg_match('/\A[A-Za-z_$][A-Za-z0-9_$]*\z/', $local) === 1
+                    && in_array($local, $names, true) === false) {
+                    $names[] = $local;
+                }
+            }
+        }
+
+        return $names;
     }
 
     /**
-     * 1 つの断片に含まれる旧パスの根 (重複を保つ = 件数がそのまま出現数になる)。
+     * その位置のリテラルが `orgUrl(...)` / `currentOrgUrl(...)` の引数として現れているか (規則 3)。
      *
-     * @return list<string>
+     * ★条件は 3 つとも満たすこと:
+     *   1. 呼び出し名の直前が**識別子の文字でない** (`notOrgUrl(` / `x.orgUrl(` を弾く)
+     *   2. 開き括弧から遡る間に**括弧を跨がない** (別の呼び出しの引数を免除しない)
+     *   3. 名前が **import 宣言から解決したローカル名**であること (呼び出し側で解決済み)
+     *
+     * ★入力は**コメントを潰した写し**である (呼び出し側が渡す)。生ソースを渡すと
+     *   コメントの中の `orgUrl(` が次のリテラルまで届いて免除を作れてしまう。
+     *
+     * @param  list<string>  $builderNames
+     */
+    private static function isOrganizationUrlBuilderArgument(string $maskedSource, int $literalOffset, array $builderNames): bool
+    {
+        $before = substr($maskedSource, 0, $literalOffset);
+        $alternatives = implode('|', array_map(
+            static fn (string $name): string => preg_quote($name, '/'),
+            $builderNames,
+        ));
+
+        return preg_match('/(?<![A-Za-z0-9_$.])(?:'.$alternatives.')\(\s*[^()]*$/', $before) === 1;
+    }
+
+    /**
+     * 1 つの断片に含まれる旧パス (重複を保つ = 件数がそのまま出現数になる)。
+     *
+     * ★返すのは**根と、根から終端までの path 全体**の 2 つである。
+     *   許可目録のキーには根を使い (目録が旧 URL 文字列を持たないため)、
+     *   **区分ごとの前提の判定には path 全体を使う** — 根だけだと
+     *   「同じ根で別の path へ置き換える」迂回 (`/app` → `/app/projects/1`) を止められない。
+     *
+     * @return list<array{root: string, path: string}>
      */
     public static function matchesIn(string $chunk): array
     {
@@ -244,22 +350,40 @@ public static function matchesIn(string $chunk): array
                 if (! self::isRootPosition($chunk, $position, $organizationSegment)) {
                     continue;
                 }
-                $end = $position + strlen($root);
-                if ($root === self::captureRoot()) {
-                    // 配下つきのときだけ旧 URL (裸は正規の分岐入口)
-                    if (! self::hasSubPathAfter($chunk, $end)) {
-                        continue;
-                    }
-                } elseif (! self::isPathBoundaryAfter($chunk, $end)) {
+                if (! self::isPathBoundaryAfter($chunk, $position + strlen($root))) {
                     continue;
                 }
-                $matches[] = $root;
+                $matches[] = ['root' => $root, 'path' => self::pathAt($chunk, $position)];
             }
         }
 
         return $matches;
     }
 
+    /**
+     * 根の位置から**終端まで**の path 全体を切り出す。
+     *
+     * ★終端は `PLAIN_TEXT_TERMINATORS` と query (`?`) / hash (`#`) / バックスラッシュである。
+     *   query 以降は path ではないので含めない。
+     */
+    private static function pathAt(string $chunk, int $position): string
+    {
+        $length = strlen($chunk);
+        for ($end = $position; $end < $length; $end++) {
+            $rest = substr($chunk, $end);
+            if ($chunk[$end] === '?' || $chunk[$end] === '#' || $chunk[$end] === '\\') {
+                break;
+            }
+            foreach (self::PLAIN_TEXT_TERMINATORS as $terminator) {
+                if (str_starts_with($rest, $terminator)) {
+                    break 2;
+                }
+            }
+        }
+
+        return substr($chunk, $position, $end - $position);
+    }
+
     /**
      * 根の位置に現れているか (規則 1 と規則 2)。
      *
@@ -293,28 +417,6 @@ private static function isRootPosition(string $chunk, int $position, string $org
      * ★それ以外は `PLAIN_TEXT_TERMINATORS` の列挙だけを終端と認める
      *   (`/appx` `/app-old` `/myapp` を拾わない = 走査器共通規約 (e) の 3 形)。
      */
-    /**
-     * 根の直後に**配下のセグメントがあるか** (`/app` 専用)。
-     *
-     * ★`/` に続いて終端でない文字が 1 つ以上あることを求める。
-     *   裸の `/app` と末尾スラッシュだけの `/app/` は正規入口とみなして拾わない。
-     */
-    private static function hasSubPathAfter(string $chunk, int $end): bool
-    {
-        if ($end + 1 >= strlen($chunk) || $chunk[$end] !== '/') {
-            return false;
-        }
-
-        $next = substr($chunk, $end + 1);
-        foreach (self::PLAIN_TEXT_TERMINATORS as $terminator) {
-            if (str_starts_with($next, $terminator)) {
-                return false;
-            }
-        }
-
-        return true;
-    }
-
     private static function isPathBoundaryAfter(string $chunk, int $end): bool
     {
         if ($end >= strlen($chunk)) {
diff --git a/tests/Support/SourceLiterals.php b/tests/Support/SourceLiterals.php
index 2a309c49..a3a45a58 100644
--- a/tests/Support/SourceLiterals.php
+++ b/tests/Support/SourceLiterals.php
@@ -20,19 +20,32 @@
  *     `'` / `"` / `` ` `` に挟まれた範囲を採り、`//` 行コメント・ブロックコメント (`/`+`*` から `*`+`/` まで)・
  *     `#` 行コメント (Python) は読み飛ばす。**`//` の直前が `:` のときはコメントにしない**
  *     (`https://` を行コメントと誤読して行の残りを落とさないため)。
+ *     正規表現リテラル (`/…/`) は**直前の意味のある文字が値の終わりでないとき**に限り
+ *     読み飛ばす (`= /…/` `( /…/` `, /…/` `: /…/` `[ /…/` `return /…/`)。
  *
  * ★**保証しないもの (誇張しない)**:
  *   - 実行時に組み立てる形 (`'/dash'.$suffix` / `'/' + name`) は 1 つのリテラルに見えないので
  *     連結後の値では判定できない。連結前の断片だけを見る。
- *   - script 側は言語の構文解析ではない。正規表現リテラル (`/…/g`) の中の引用符、
- *     Svelte の `<!-- -->` コメント、JSX/HTML 属性の引用符なし記法は
- *     **文字列として採られる / 採られないのどちらかに倒れる**。倒れる方向は
- *     「採る (過検出)」であり、見逃す方向ではない。
+ *   - **script 側は言語の構文解析ではない**。正規表現リテラルの判定は上記の発見的規則であり、
+ *     割り算との区別を完全には行わない。判定を誤ると引用符の対応がずれ、
+ *     **見逃す方向にも倒れうる**。同様に Svelte の `<!-- -->` コメント・
+ *     引用符なし HTML 属性・テンプレートリテラル内の入れ子も保証しない。
+ *     利用側 gate はこの限界を**自分の検出力の主張から明示的に除く**こと。
  *   - Python の三重引用符は、同じ引用符 3 つの連なりとして 2 つの空文字列 + 本体に割れる
  *     (本体は採られるので見逃さない)。
  */
 final class SourceLiterals
 {
+    /**
+     * この語の直後の `/` は正規表現リテラルの開始である (値の終わりではない語)。
+     *
+     * @var list<string>
+     */
+    private const array REGEX_PRECEDING_KEYWORDS = [
+        'return', 'typeof', 'instanceof', 'in', 'of', 'new', 'delete', 'void',
+        'do', 'else', 'yield', 'await', 'case', 'throw',
+    ];
+
     /** インスタンス化しない (純関数の置き場)。 */
     private function __construct() {}
 
@@ -107,12 +120,65 @@ public static function php(string $source): array
         return $literals;
     }
 
+    /**
+     * その `/` が正規表現リテラルの開始か (発見的規則)。
+     *
+     * ★直前の意味のある文字が「値の終わり」(英数字 / `_` / `$` / `)` / `]` / `}` / 引用符)
+     *   でなければ正規表現リテラルとみなす。JavaScript の字句規則の近似であり、
+     *   `}` の後の正規表現などは割り算側へ倒れる (docblock に明記した限界)。
+     */
+    private static function opensRegexLiteral(string $source, int $index): bool
+    {
+        for ($i = $index - 1; $i >= 0; $i--) {
+            $char = $source[$i];
+            if ($char === ' ' || $char === "\t" || $char === "\r" || $char === "\n") {
+                continue;
+            }
+
+            if (ctype_alnum($char) || $char === '_' || $char === '$') {
+                // 直前が識別子。**キーワードなら値ではない**ので正規表現の開始になりうる
+                $start = $i;
+                while ($start >= 0 && (ctype_alnum($source[$start]) || $source[$start] === '_' || $source[$start] === '$')) {
+                    $start--;
+                }
+                $word = substr($source, $start + 1, $i - $start);
+
+                return in_array($word, self::REGEX_PRECEDING_KEYWORDS, true);
+            }
+
+            return ! in_array($char, [')', ']', '}', '"', "'", '`'], true);
+        }
+
+        return true;
+    }
+
     /** バイト位置から 1 起点の行番号を求める (ヒアドキュメント開始などの補助)。 */
     private static function lineAt(string $source, int $offset): int
     {
         return substr_count(substr($source, 0, $offset), "\n") + 1;
     }
 
+    /**
+     * script 系ソースの**コメントを空白へ潰した写し** (長さと位置は元と同一)。
+     *
+     * ★リテラルの前後関係を生ソースで見る判定 (「この呼び出しの引数か」等) は、
+     *   コメントの中の文字列に騙されてはならない。位置を保ったまま潰すことで、
+     *   呼び出し側は offset をそのまま使える。
+     */
+    public static function maskComments(string $source, bool $hashComments = false): string
+    {
+        $masked = $source;
+        foreach (self::commentSpans($source, $hashComments) as [$start, $end]) {
+            for ($i = $start; $i < $end; $i++) {
+                if ($masked[$i] !== "\n") {
+                    $masked[$i] = ' ';
+                }
+            }
+        }
+
+        return $masked;
+    }
+
     /**
      * script 系ソースの文字列リテラル。
      *
@@ -120,8 +186,32 @@ private static function lineAt(string $source, int $offset): int
      * @return list<array{line: int, offset: int, value: string}>
      */
     public static function script(string $source, bool $hashComments = false): array
+    {
+        return self::walk($source, $hashComments)['literals'];
+    }
+
+    /**
+     * script 系ソースのコメントの範囲 (開始 offset, 終了 offset)。
+     *
+     * @return list<array{int, int}>
+     */
+    private static function commentSpans(string $source, bool $hashComments): array
+    {
+        return self::walk($source, $hashComments)['comments'];
+    }
+
+    /**
+     * script 系ソースを 1 度だけ走査し、**文字列リテラルとコメントの範囲を同時に**返す。
+     *
+     * ★同じ字句規則を 2 本持たないための単一の走査である
+     *   (2 本あると「片方だけ直して食い違う」経路が生まれる)。
+     *
+     * @return array{literals: list<array{line: int, offset: int, value: string}>, comments: list<array{int, int}>}
+     */
+    private static function walk(string $source, bool $hashComments): array
     {
         $literals = [];
+        $comments = [];
         $length = strlen($source);
         $line = 1;
         $index = 0;
@@ -139,15 +229,18 @@ public static function script(string $source, bool $hashComments = false): array
             // 行コメント (`//`)。直前が `:` なら URL の一部なのでコメントにしない
             if ($char === '/' && $index + 1 < $length && $source[$index + 1] === '/'
                 && ! ($index > 0 && $source[$index - 1] === ':')) {
+                $commentStart = $index;
                 while ($index < $length && $source[$index] !== "\n") {
                     $index++;
                 }
+                $comments[] = [$commentStart, $index];
 
                 continue;
             }
 
             // ブロックコメント
             if ($char === '/' && $index + 1 < $length && $source[$index + 1] === '*') {
+                $commentStart = $index;
                 $index += 2;
                 while ($index + 1 < $length && ! ($source[$index] === '*' && $source[$index + 1] === '/')) {
                     if ($source[$index] === "\n") {
@@ -156,14 +249,46 @@ public static function script(string $source, bool $hashComments = false): array
                     $index++;
                 }
                 $index = min($index + 2, $length);
+                $comments[] = [$commentStart, $index];
 
                 continue;
             }
 
             if ($hashComments && $char === '#') {
+                $commentStart = $index;
                 while ($index < $length && $source[$index] !== "\n") {
                     $index++;
                 }
+                $comments[] = [$commentStart, $index];
+
+                continue;
+            }
+
+            // 正規表現リテラル。直前の意味のある文字が「値の終わり」でないときだけそう読む
+            // (発見的規則。割り算との区別を完全には行わない = docblock に明記済み)
+            if (! $hashComments && $char === '/' && self::opensRegexLiteral($source, $index)) {
+                $index++;
+                $inClass = false;
+                while ($index < $length) {
+                    $current = $source[$index];
+                    if ($current === '\\') {
+                        $index += 2;
+
+                        continue;
+                    }
+                    if ($current === "\n") {
+                        break; // 正規表現リテラルは改行を跨げない = 読み違いだった
+                    }
+                    if ($current === '[') {
+                        $inClass = true;
+                    } elseif ($current === ']') {
+                        $inClass = false;
+                    } elseif ($current === '/' && ! $inClass) {
+                        $index++;
+                        break;
+                    }
+                    $index++;
+                }
 
                 continue;
             }
@@ -209,6 +334,6 @@ public static function script(string $source, bool $hashComments = false): array
             $index++;
         }
 
-        return $literals;
+        return ['literals' => $literals, 'comments' => $comments];
     }
 }
```

## 3. 検証結果 (再実行)

- `composer test`: 6844 tests / 6842 passed / 0 failed (exit 0。残り 2 は skipped)
- `composer phpstan` (level 10, 1050 files): No errors
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm build`: green
- `pnpm test`: 178 files / 2393 tests passed
- `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` (106 tests): green
- 旧 URL 走査: 違反 0 件 / 未分類 0 件 / 未解決 0 件。許可目録は 32 件で、
  いずれも件数完全一致かつ**出現ごとの path**で区分の前提を満たす。

## 4. 明示的に残した限界 (すべて docblock に明記済み)

1. 検出は**相対 path として 1 リテラル (1 行) に収まる形**だけを見る。
   実行時連結・絶対 URL・query/hash の中は主張から除いた。
2. script の抽出は言語の構文解析ではなく発見的規則であり、**見逃す方向にも倒れうる**。
3. `OrganizationRelativePath` の記号一致は**データフローの証明ではない**
   (値が builder へ渡ることは利用側のテストが担う)。
4. `StorageObjectKey` / `AbsenceAssertion` はファイル単位の印であり、出現ごとの path では表せない。

これらを「本 PR で直すべき」と判断するなら、その根拠と実現方法を示してほしい。
