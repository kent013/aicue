# Round 2: 指摘への対応

Round 1 の指摘に対する対応を下に示す。**対応マトリクス**と**修正差分**を読み、
残っている問題があれば再度 [Critical] / [Warning] / [Suggestion] で指摘し、
最後に **全体判定: APPROVED または CHANGES_REQUESTED** を 1 行で書いてほしい。

## 1. 対応マトリクス

# 対応マトリクス: impl-review Round 1

## [Critical] `orgUrl()` / `currentOrgUrl()` の許可判定が生ソースへの正規表現だけで抜け道になる
- 判断: 対応する
- 根拠: 指摘のとおり。コメントの `// currentOrgUrl(` が次の行のリテラルまで届き、
  `notCurrentOrgUrl(` のような接尾辞一致でも免除されていた。
- 対応内容: 3 点で締めた。
  1. `SourceLiterals::maskComments()` を新設し、**コメントを空白へ潰した写し**の上で
     前後関係を判定する (位置は元と同一。字句規則は `walk()` 1 本に集約して 2 本持たない)。
  2. 呼び出し名の直前が識別子の文字なら一致しない lookbehind を足した
     (`notCurrentOrgUrl(` / `x.orgUrl(` を弾く)。
  3. **ファイルが `@/lib/org-url` を取り込んでいること**を前提にした
     (同名関数の自前定義では免除が効かない)。
  負例 fixture (`legacy-script-source.txt` / `legacy-shadowed-builder.txt`) で 4 形を裏取りした。

## [Critical] `/app` の「配下つきのみ」規則は設計の許可目録を弱めている
- 判断: 対応する
- 根拠: 指摘のとおり。規則にしたことで「どこにでも直書きしてよい」状態になり、
  設計が守ろうとした「入口への導線は route helper 経由だけ」が消えていた。
- 対応内容: 「配下つきのみ」規則を撤去し、裸の出現も検出する形へ戻した。
  正規入口としての出現は許可目録へ **パス + 規則 + 語 + 件数** で exact-fit 登録し
  (区分 `CanonicalCaptureEntry`)、区分の前提として
  **route 表の `capture.entry` の URI が語と一致すること**を機械検査する。

## [Critical] 「正規化済み path」を実装しておらず、絶対 URL・query/hash を見ていない
- 判断: 対応する (保証の主張を狭める形で)
- 根拠: 実装が主張に追いついていないのは (b) 違反。ただし絶対 URL は
  外部サービスの URL と字面で区別できず、host 一覧を作ると別の嘘を増やす。
- 対応内容: gate の docblock に「検出力の主張は次の範囲に**狭める**」節を新設し、
  相対 path / 1 リテラル (1 行) に収まる形だけを見ること、絶対 URL・query/hash の中・
  実行時連結・script 抽出の発見的規則は**主張から除く**ことを明記した。

## [Critical] 撤去 route 名が 1 行 1 件しか数えない
- 判断: 対応する
- 根拠: exact-fit を件数で迂回できる。
- 対応内容: `substr_count()` による**出現数**の計上へ変えた。
  自己検査の件数 pin 側も同じく出現数へ揃え、1 行 2 個の負例 (`legacy-data-source.txt`) を足した。

## [Warning] `/app/?query` と `/app/#fragment` を「配下あり」と誤判定する
- 判断: 対応する (規則ごと撤去したので消滅)
- 根拠: 「配下つきのみ」規則を撤去したため、この分岐自体が無くなった。

## [Critical] script 抽出器の「見逃し側には倒れない」という保証が事実と違う
- 判断: 対応する
- 根拠: 正規表現リテラル中の引用符で対応がずれ、指摘のコードで実際に見逃していた。
- 対応内容: (1) 正規表現リテラルを読み飛ばす発見的規則を実装 (直前の意味のある文字が
  値の終わりでないときだけ。`return` 等のキーワードも扱う)。(2) docblock の
  「倒れる方向は過検出であり見逃さない」という主張を撤回し、
  **見逃す方向にも倒れうる**と明記して利用側 gate の主張から除いた。
  指摘のコード片を fixture へ入れて回帰を固定した。

## [Critical] 許可キーが `path + rule ID` だけで対象パターンを固定できない
- 判断: 対応する
- 根拠: 同じ件数で別の旧 URL へ置き換えると通る。設計は「対象パターン完全一致」を要求している。
- 対応内容: キーを **パス + 規則 ID + 一致した語** にした (`LegacyUrlAllowance::keyOf()`)。
  語は目録に文字列で書かず、走査器が組み立てた根から選ぶ (`legacyRootEndingWith()`)。

## [Critical] `kind` が判定に使われていない (規約 (d))
- 判断: 対応する
- 根拠: 指摘のとおり説明ラベルだった。
- 対応内容: 区分を 5 つに整理し、**区分ごとの前提を `preconditionViolation()` が機械検査**する:
  `CanonicalCaptureEntry` = 語が撮影 PWA の根かつ route 表の入口 URI と一致 /
  `FilesystemPath` = 語が実在するディレクトリ / `StorageObjectKey` = 鍵を扱う印が同じファイルにある /
  `AbsenceAssertion` = 撤去の語が同じファイルにある /
  `OrganizationRelativePath` = **名指しした利用側**が実在し組織 URL を組み立てている。

## [Critical] `OrganizationRelativePath` が利用側を機械検査していない
- 判断: 対応する
- 根拠: 指摘のとおり「なんとなく直せない」の口になっていた。
- 対応内容: 登録に `consumer` (利用側のファイル) を必須にし、実在と
  「組織 URL を組み立てていること」を検査する。書かない登録は前提違反で赤になる。

## [Warning] 同一キーの重複登録が後勝ちで潰れる
- 判断: 対応する
- 対応内容: `counts()` が重複キーで例外を投げるようにした。

## [Warning] 検証結果の「許可目録は 7 件」が実装と食い違う
- 判断: 対応する
- 根拠: 報告の誤り (提示時点で 9 件)。
- 対応内容: 目録の作り直しで 32 件になったので、以降の報告は実数で書く。

## [Critical] `LegacyUrlOccurrence` の docblock が実装と乖離している
- 判断: 対応する
- 対応内容: 「rule ID が識別するのは抽出方式まで」と書き直し、
  構文の入れ替わりは**語と件数**でキーを作ることで塞いでいると明記した。

## [Critical] `routes/` 全体の除外は穴になる
- 判断: 対応する
- 根拠: closure 内の `redirect()` と撤去 route 名は route 表の検査では代替できない。
- 対応内容: 除外を **route 定義の URI 引数 1 つだけ**へ狭めた
  (`withoutRouteDefinitionUris()`)。他のリテラルと撤去 route 名は `routes/` の中でも検出する。
  合成入力の負例 (定義の URI は外れ、`redirect()` の直書きは残る) を足した。

## [Warning] `patch` / `err` の除外理由が実装 (拡張子だけ) と食い違う
- 判断: 対応する
- 対応内容: 拡張子の除外から削除した (`devnotes/` の接頭辞除外で足りており、
  他所に現れたら未分類として赤くなる)。

## [Warning] symlink / NUL / 不正 UTF-8 の fail-closed 分岐に負例が無い
- 判断: 見送る
- 根拠: これらの分岐は `Tests\Support\SurfaceRemoval\RemovedSurfaceScanTargets` と同じ形で、
  発火させるには追跡下に壊れた symlink や NUL を含むファイルを置く必要がある。
  母集団の `unresolved` が 0 件であることは gate が毎回見ているので「集めて使っていない」
  状態ではない。負例の追加は別 TODO の候補として棚卸しに残す。

## [Critical] gate が保証範囲を狭めていない
- 判断: 対応する (上の「正規化済み path」と同じ対応)

## [Warning] Blade / JSON の検出正例・非検出正例が無い
- 判断: 対応する
- 対応内容: `legacy-blade-source.txt` / `legacy-data-source.txt` を足し、
  種別ごとの検出力をデータセットで裏取りした (7 種別)。

## [Critical] 自己検査の件数が本体と同じ数え方で独立していない
- 判断: 対応する
- 対応内容: 自己検査側は**全文の出現数**で数える (本体の抽出方式を通さない) ことを明記し、
  撤去 route 名も出現数で数えるようにした。

## [Critical] `OrganizationRouteHandlerParameterTest` が位置を見ていない / closure を除外している
- 判断: 対応する
- 根拠: 指摘のとおり。名前の有無だけでは位置ずれを防げず、closure も同じ resolution を通る。
- 対応内容: handler の引数のうち route parameter と同名のものを**宣言順**に取り出し、
  route parameter の並びと**同じ順序**であることを検査する形にした。closure も
  `ReflectionFunction` で同じ検査に掛ける。負例は欠落と順序違いの 2 形を置いた。
  この検査で `capture.csrf-cookie` の closure が `{organization}` を受けていないことが
  実際に見つかったので、同じ変更で直した。

## [Critical] `allowed-paths.md` が裸の `/app` を無条件の許可例にしている
- 判断: 対応する
- 対応内容: 裸の `/app` を負例から外した (検出される側へ戻したため)。

## [Warning] `legacy-php-source.txt` に複雑な補間・重複 route 名が無い
- 判断: 一部対応する
- 対応内容: 1 行 2 個の撤去 route 名は `legacy-data-source.txt` で裏取りした。
  複雑な補間の追加は見送り、`SourceLiterals` の docblock に
  「連結・複雑な補間は保証しない」と明記済みであることを確認した。

## [Warning] `NotificationController` の TicketBalanceLow が組織不一致でも現在の URL の課金画面へ送る
- 判断: 対応する
- 根拠: 通知の対象と操作先が食い違う。組織を URL 以外から読み替えない裁定とも整合しない。
- 対応内容: manual 系と同じく、**通知の org と URL の組織が一致するときだけ**その組織の
  購入画面へ送り、一致しなければ一覧へ 303 + 案内へ倒すようにした。

## 2. 修正差分 (Round 1 提示分からの差分)

```diff
diff --git a/app/Http/Controllers/NotificationController.php b/app/Http/Controllers/NotificationController.php
index 7d888975..c288e41f 100644
--- a/app/Http/Controllers/NotificationController.php
+++ b/app/Http/Controllers/NotificationController.php
@@ -100,8 +100,15 @@ public function open(Request $request, Organization $organization, string $notif
             $item->isManualJob() => redirect()
                 ->route('notifications.index', ['organization' => $organization->slug], 303)
                 ->with('info', '対象の動画マニュアルは削除されています。'),
+            // 残高通知も**通知の org と URL の組織が一致するときだけ**その組織の購入画面へ送る。
+            // 一致しないまま送ると「別組織の残高通知を開いたのに、いま見ている組織の購入画面が出る」
+            // = 通知の対象と操作先が食い違う (組織を URL 以外から読み替えないという裁定とも矛盾する)。
+            $item->type === NotificationType::TicketBalanceLow
+                && $this->belongsToCurrentOrg($organization, $item) => redirect()
+                    ->route('billing.tickets.show', ['organization' => $organization->slug], 303),
             $item->type === NotificationType::TicketBalanceLow => redirect()
-                ->route('billing.tickets.show', ['organization' => $organization->slug], 303),
+                ->route('notifications.index', ['organization' => $organization->slug], 303)
+                ->with('info', 'この通知は別の組織のものです。その組織の画面から開いてください。'),
             // 招待通知: 受諾可能な一覧が出る通知センターへ戻す。
             // ★通知 payload は招待 id を持たないため「この招待」を特定できない。
             //   したがって flash は**集合表現**にする (件数 0 のときだけ説明を出す)。
diff --git a/routes/web.php b/routes/web.php
index 05a04db1..00d705e0 100644
--- a/routes/web.php
+++ b/routes/web.php
@@ -57,6 +57,7 @@
 use App\Http\Middleware\HandleInertiaRequests;
 use App\Http\Middleware\LocalOnly;
 use App\Http\Middleware\NoIndex;
+use App\Models\Organization;
 use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
 use Illuminate\Cookie\Middleware\EncryptCookies;
 use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
@@ -640,8 +641,13 @@
                 Route::get('/', [CaptureManualController::class, 'home'])->name('home');
                 // CSRF cookie 再発行 (419 リトライ用の軽量 GET。web group を通るだけで
                 // XSRF-TOKEN cookie が更新される。204 = 仕様固定 endpoint、body なし)
-                Route::get('/csrf-cookie', fn (): Response => response()->noContent())
-                    ->name('csrf-cookie');
+                // ★closure も route parameter を**位置で**受けるので `{organization}` を受ける
+                //   (受けないと後続の引数がずれる。OrganizationRouteHandlerParameterTest が固定)。
+                //   値は使わない (cookie の再発行に組織は関係しない)。
+                Route::get(
+                    '/csrf-cookie',
+                    fn (Organization $organization): Response => response()->noContent(),
+                )->name('csrf-cookie');
                 /*
                 | 撮影 PWA のアカウント確認画面 (doc/05 §5.1 / §5.2)。表示名・ログイン ID
                 | (= メールアドレス)・所属組織を省略なく読み、ログアウトするためだけの面。
diff --git a/tests/Architecture/LegacyOrganizationlessUrlAbsenceTest.php b/tests/Architecture/LegacyOrganizationlessUrlAbsenceTest.php
index 3b4b08e4..c34d0d0b 100644
--- a/tests/Architecture/LegacyOrganizationlessUrlAbsenceTest.php
+++ b/tests/Architecture/LegacyOrganizationlessUrlAbsenceTest.php
@@ -2,6 +2,7 @@
 
 declare(strict_types=1);
 
+use Illuminate\Support\Facades\Route;
 use Tests\Support\LegacyUrl\LegacyUrlAllowance;
 use Tests\Support\LegacyUrl\LegacyUrlExtractionMode;
 use Tests\Support\LegacyUrl\LegacyUrlScannedFile;
@@ -9,7 +10,7 @@
 use Tests\Support\LegacyUrl\LegacyUrlScanRoots;
 
 /*
- * 組織を持たない**旧 URL** と**撤去した route 名**がリポジトリに 1 件も残っていない
+ * 組織を持たない**旧 URL** と**撤去した route 名**が、走査できた範囲に 1 件も残っていない
  * (家系裁定 AG-037 / 施策 10)。
  *
  * ## なぜ必要か
@@ -36,10 +37,22 @@
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
@@ -94,6 +107,22 @@
     expect($short)->toBe([]);
 });
 
+test('許可目録の区分ごとの前提がすべて満たされている (区分を判定に使う)', function (): void {
+    $captureEntryUri = '/'.ltrim((string) Route::getRoutes()->getByName('capture.entry')?->uri(), '/');
+    $repositoryRoot = LegacyUrlScanRoots::repositoryRoot();
+
+    $violations = [];
+    foreach (LegacyUrlAllowance::entries() as $entry) {
+        $violation = LegacyUrlAllowance::preconditionViolation($entry, $repositoryRoot, $captureEntryUri);
+        if ($violation !== null) {
+            $violations[] = "{$entry['path']} [{$entry['kind']->value}]: {$violation}";
+        }
+    }
+
+    sort($violations);
+    expect($violations)->toBe([]);
+});
+
 test('旧 URL と撤去 route 名は許可目録に登録したものを除いて 0 件', function (): void {
     $allowed = LegacyUrlAllowance::counts();
     $observed = [];
@@ -101,7 +130,7 @@
 
     foreach (LegacyUrlScanRoots::population()->scanned as $file) {
         foreach (LegacyUrlScanner::scanFile($file) as $occurrence) {
-            $key = $occurrence->relative."\0".$occurrence->ruleId;
+            $key = LegacyUrlAllowance::keyOf($occurrence->relative, $occurrence->ruleId, $occurrence->matched);
             $observed[$key] = ($observed[$key] ?? 0) + 1;
             if (! array_key_exists($key, $allowed)) {
                 $violations[] = $occurrence->describe();
@@ -115,58 +144,47 @@
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
+    'markdown' => ['legacy-paths.md', LegacyUrlExtractionMode::PlainText, LegacyUrlScanner::RULE_MARKDOWN_TEXT, 'fixture.md', 12],
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
+    // script: 入口の引数・組織 prefix・コメント・正規表現リテラルを除いた 4 件
+    // (直書き 1 / 接尾辞つき偽入口 1 / コメントの偽入口の次行 1 / メンバ呼びの偽入口 1)
+    'script' => ['legacy-script-source.txt', LegacyUrlExtractionMode::SourceLiteral, LegacyUrlScanner::RULE_SCRIPT_LITERAL, 'fixture.ts', 4],
+    // script: 入口の module を取り込まずに同名関数を自前定義しても免除にならない
+    'script-shadowed' => ['legacy-shadowed-builder.txt', LegacyUrlExtractionMode::SourceLiteral, LegacyUrlScanner::RULE_SCRIPT_LITERAL, 'fixture.ts', 1],
+    // JSON: 値の旧パス 1 件 + 1 行に 2 個の撤去 route 名 (件数で数える)
+    'data' => ['legacy-data-source.txt', LegacyUrlExtractionMode::PlainText, LegacyUrlScanner::RULE_DATA_TEXT, 'fixture.json', 3],
+    // Blade: 属性値の旧パス 1 件 (route helper 経由と組織 prefix つきは数えない)
+    'blade' => ['legacy-blade-source.txt', LegacyUrlExtractionMode::PlainText, LegacyUrlScanner::RULE_BLADE_TEXT, 'fixture.blade.php', 1],
+]);
 
 test('種別ごとの割り当て: 拡張子は宣言した抽出方式と規則 ID へ 1:1 で写る', function (): void {
     // ★「どの種別をどう抽出するか」は分類表が唯一の正本である。ここが壊れると
@@ -193,3 +211,31 @@
     expect(LegacyUrlScanRoots::classify('resources/js/app.unknownext'))->toBeNull();
     expect(LegacyUrlScanRoots::classify('app/Models/User.php'))->not->toBeNull();
 });
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
index 2b9801b0..0e99abe5 100644
--- a/tests/Architecture/LegacyUrlSelfCheckPopulationTest.php
+++ b/tests/Architecture/LegacyUrlSelfCheckPopulationTest.php
@@ -17,6 +17,13 @@
  * 増えても減っても赤になるので、見本を黙って増やすことも、
  * 実装のついでに旧 URL を見本ファイルへ退避することもできない。
  *
+ * ## 数え方 (本体とは別の、より素朴な数え方を使う)
+ *
+ * **全文を 1 行ずつ**見て、根の一致と撤去 route 名の**出現数**を数える。
+ * 本体の抽出方式 (コメントを外す / 入口の引数を外す) は通さない — ここで数えたいのは
+ * 「見本が検出語を何個持っているか」であって、本体が何件検出するかではない。
+ * 本体と同じ数え方にすると、本体が見逃す形を見本へ足しても件数が動かなくなる。
+ *
  * ## この gate 自身は旧 URL 文字列を持たない
  *
  * 持つのは**パスと件数**だけである (旧 URL を書くと、この gate 自身が検出対象になる)。
@@ -25,23 +32,23 @@
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
+    'tests/Architecture/fixtures/legacy-url/legacy-blade-source.txt' => 1,
+    'tests/Architecture/fixtures/legacy-url/legacy-data-source.txt' => 3,
     'tests/Architecture/fixtures/legacy-url/legacy-paths.md' => 12,
     'tests/Architecture/fixtures/legacy-url/legacy-php-source.txt' => 5,
-    'tests/Architecture/fixtures/legacy-url/legacy-script-source.txt' => 5,
+    'tests/Architecture/fixtures/legacy-url/legacy-script-source.txt' => 8,
+    'tests/Architecture/fixtures/legacy-url/legacy-shadowed-builder.txt' => 1,
 ];
 
 test('自己検査専用の分類は目録と完全一致する', function (): void {
@@ -60,9 +67,8 @@
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
index 6038e6e4..0571927e 100644
--- a/tests/Architecture/OrganizationRouteHandlerParameterTest.php
+++ b/tests/Architecture/OrganizationRouteHandlerParameterTest.php
@@ -2,39 +2,60 @@
 
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
+ * それが route の parameter の並びと**同じ順序の部分列**であることを求める。
+ *  - `{organization}` を受けていなければ不一致になる (欠落は部分列にならない)
+ *  - 受けていても順序が違えば不一致になる (位置ずれそのもの)
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
 
@@ -43,35 +64,42 @@
 
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
-
-        if (! in_array('organization', $names, true)) {
+        $declared = organizationRouteHandlerParameterNames($handler, $parameters);
+        // route parameter の並びから、handler が受けたものだけを同じ順序で取り出す
+        $expected = array_values(array_filter(
+            $parameters,
+            static fn (string $name): bool => in_array($name, $declared, true),
+        ));
+
+        if ($declared !== $expected || ! in_array('organization', $declared, true)) {
             $violations[] = ($route->getName() ?? $route->uri())
-                ." -> {$class}::{$method}(".implode(', ', $names).')';
+                .' -> handler の引数 ['.implode(', ', $declared).'] が route parameter ['
+                .implode(', ', $parameters).'] の並びと一致しません';
         }
     }
 
@@ -82,27 +110,40 @@
     expect($violations)->toBe([]);
 });
 
-test('負例: organization 引数を持たない合成 handler を検出できる', function (): void {
-    $withOrganization = new class
-    {
-        public function show(Organization $organization, string $notification): string
-        {
-            return $organization->slug.$notification;
-        }
-    };
-    $withoutOrganization = new class
-    {
-        public function show(string $notification): string
-        {
-            return $notification;
-        }
-    };
+test('負例: 欠落と順序違いのどちらも検出できる (検出力の裏取り)', function (): void {
+    $parameters = ['organization', 'project', 'manual'];
 
-    $names = static fn (object $handler): array => array_map(
-        static fn (ReflectionParameter $parameter): string => $parameter->getName(),
-        (new ReflectionMethod($handler, 'show'))->getParameters(),
+    $correct = new ReflectionFunction(
+        static function (Request $request, string $organization, int $project, int $manual): string {
+            return $organization.$project.$manual;
+        },
+    );
+    $missing = new ReflectionFunction(
+        static function (Request $request, int $project, int $manual): string {
+            return $project.$manual;
+        },
     );
+    $reordered = new ReflectionFunction(
+        static function (Request $request, int $project, string $organization): string {
+            return $organization.$project;
+        },
+    );
+
+    $declaredOf = static fn (ReflectionFunction $handler): array => organizationRouteHandlerParameterNames($handler, $parameters);
+    $expectedOf = static fn (array $declared): array => array_values(array_filter(
+        $parameters,
+        static fn (string $name): bool => in_array($name, $declared, true),
+    ));
+
+    // 正例: 宣言順が route parameter の並びと一致し organization を受けている
+    $declared = $declaredOf($correct);
+    expect($declared)->toBe($expectedOf($declared));
+    expect(in_array('organization', $declared, true))->toBeTrue();
+
+    // 負例 1: organization が無い (欠落)
+    expect(in_array('organization', $declaredOf($missing), true))->toBeFalse();
 
-    expect(in_array('organization', $names($withOrganization), true))->toBeTrue();
-    expect(in_array('organization', $names($withoutOrganization), true))->toBeFalse();
+    // 負例 2: 受けてはいるが順序が違う (位置ずれそのもの)
+    $declared = $declaredOf($reordered);
+    expect($declared)->not->toBe($expectedOf($declared));
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
diff --git a/tests/Architecture/fixtures/legacy-url/legacy-script-source.txt b/tests/Architecture/fixtures/legacy-url/legacy-script-source.txt
index bd4303a9..ee99bcff 100644
--- a/tests/Architecture/fixtures/legacy-url/legacy-script-source.txt
+++ b/tests/Architecture/fixtures/legacy-url/legacy-script-source.txt
@@ -1,7 +1,13 @@
 // コメントの /dashboard は参照ではない
 /* ブロックコメントの /projects も参照ではない */
+import { orgUrl, currentOrgUrl } from "@/lib/org-url";
+const quotePattern = /["]/;
 const a = "/billing";
 const b = orgUrl(slug, "/projects");
 const c = currentOrgUrl(`/manage/users`);
 const d = `/organizations/${slug}/notifications`;
 const e = "https://example.com/dashboard";
+const f = notCurrentOrgUrl("/purchase-tickets");
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
index 4b1fc45d..af232f57 100644
--- a/tests/Support/LegacyUrl/LegacyUrlAllowance.php
+++ b/tests/Support/LegacyUrl/LegacyUrlAllowance.php
@@ -4,106 +4,364 @@
 
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
+     * @return list<array{path: string, rule: string, matched: string, count: int, kind: LegacyUrlAllowanceKind, consumer: ?string, reason: string}>
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
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 2,
                 'kind' => LegacyUrlAllowanceKind::FilesystemPath,
-                'count' => 1,
-                'reason' => 'スタックトレースの絶対パスをリポジトリ相対へ畳む処理の説明文であり、'
-                    .'指しているのは app/ ディレクトリのファイルパスで、画面の URL ではない。',
+                'consumer' => null,
+                'reason' => 'スタックトレースの絶対パスをリポジトリ相対へ畳む処理の説明文と実装であり、指しているのはアプリ実装のディレクトリで画面の URL ではない',
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
+                'reason' => 'テイク動画を置くオブジェクトストレージの鍵の書式であり、画面の URL ではない。鍵は組織 id で始まる別の体系である',
+            ],
+            [
+                'path' => 'doc/10_実装仕様.md',
+                'rule' => LegacyUrlScanner::RULE_MARKDOWN_TEXT,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
+                'consumer' => null,
+                'reason' => '撮影 PWA の prefix が「PWA 専用」を意味しないことの説明で、正規の分岐入口そのものを指している',
+            ],
+            [
+                'path' => 'docs/architecture.md',
+                'rule' => LegacyUrlScanner::RULE_MARKDOWN_TEXT,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 3,
+                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
+                'consumer' => null,
+                'reason' => 'テイク API の prefix の由来と、組織文脈を持たない入口 2 本の説明であり、いずれも正規の分岐入口そのものを指している',
+            ],
+            [
+                'path' => 'docs/architecture.md',
+                'rule' => LegacyUrlScanner::RULE_REMOVED_ROUTE_NAME,
+                'matched' => LegacyUrlScanner::removedRouteName(),
+                'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::AbsenceAssertion,
+                'consumer' => null,
+                'reason' => '撤去した切替 endpoint の route 名を「撤去済みである」と説明する 1 行であり、撤去の記録としてこの名前が書けないと何を撤去したのかが文書から読めなくなる',
+            ],
+            [
+                'path' => 'docs/supported-browsers.md',
+                'rule' => LegacyUrlScanner::RULE_MARKDOWN_TEXT,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
+                'consumer' => null,
+                'reason' => 'PWA の manifest が持つ start_url の値の説明であり、正規の分岐入口そのものを指している',
+            ],
+            [
+                'path' => 'public/manifest.webmanifest',
+                'rule' => LegacyUrlScanner::RULE_DATA_TEXT,
+                'matched' => LegacyUrlScanner::captureRoot(),
                 'count' => 1,
-                'reason' => 'オブジェクトストレージに置くテイク動画の鍵の書式であり、画面の URL ではない。'
-                    .'鍵は組織 id で始まる別の体系で、URL の組織セグメントとは無関係である。',
+                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
+                'consumer' => null,
+                'reason' => 'ホーム画面追加の start_url であり、正規の分岐入口そのものである (組織を持たない入口であることが仕様)',
             ],
             [
                 'path' => 'resources/js/types/dashboard.ts',
                 'rule' => LegacyUrlScanner::RULE_SCRIPT_LITERAL,
+                'matched' => self::legacyRootEndingWith('illing'),
+                'count' => 1,
                 'kind' => LegacyUrlAllowanceKind::OrganizationRelativePath,
+                'consumer' => 'resources/js/pages/Dashboard.svelte',
+                'reason' => '課金 callout の CTA を持つ静的な表であり、画面から識別名を受け取れない。値は組織相対パスで利用側が組織 URL へ写す',
+            ],
+            [
+                'path' => 'resources/js/types/dashboard.ts',
+                'rule' => LegacyUrlScanner::RULE_SCRIPT_LITERAL,
+                'matched' => self::legacyRootEndingWith('arding'),
+                'count' => 2,
+                'kind' => LegacyUrlAllowanceKind::OrganizationRelativePath,
+                'consumer' => 'resources/js/pages/Dashboard.svelte',
+                'reason' => '課金 callout の CTA を持つ静的な表であり、画面から識別名を受け取れない。値は組織相対パスで利用側が組織 URL へ写す',
+            ],
+            [
+                'path' => 'resources/views/app.blade.php',
+                'rule' => LegacyUrlScanner::RULE_BLADE_TEXT,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
+                'consumer' => null,
+                'reason' => 'PWA 専用 manifest を出し分ける条件の説明コメントであり、正規の分岐入口そのものを指している',
+            ],
+            [
+                'path' => 'tests/Architecture/ExternalClientTimeoutInventoryTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
+                'consumer' => null,
+                'reason' => '到達境界の走査根をリポジトリルートからの相対ディレクトリとして組み立てている箇所であり、URL ではなくファイルシステムのパスである',
+            ],
+            [
+                'path' => 'tests/Architecture/ExternalSeamInventoryTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
+                'consumer' => null,
+                'reason' => '外部到達点の走査根をリポジトリルートからの相対ディレクトリとして組み立てている箇所であり、URL ではなくファイルシステムのパスである',
+            ],
+            [
+                'path' => 'tests/Architecture/FlashNotificationRelayDriftTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
+                'consumer' => null,
+                'reason' => '通知中継の走査根をリポジトリルートからの相対ディレクトリとして組み立てている箇所であり、URL ではなくファイルシステムのパスである',
+            ],
+            [
+                'path' => 'tests/Architecture/InvitationResolutionInventoryTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
+                'consumer' => null,
+                'reason' => '招待解決の走査根をリポジトリルートからの相対ディレクトリとして組み立てている箇所であり、URL ではなくファイルシステムのパスである',
+            ],
+            [
+                'path' => 'tests/Architecture/LlmDefenseConfigGateTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
+                'consumer' => null,
+                'reason' => 'LLM 防御設定の走査根をリポジトリルートからの相対ディレクトリとして組み立てている箇所であり、URL ではなくファイルシステムのパスである',
+            ],
+            [
+                'path' => 'tests/Architecture/MembershipWriteLockInventoryTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
+                'consumer' => null,
+                'reason' => '所属書き込みの走査根をリポジトリルートからの相対ディレクトリとして組み立てている箇所であり、URL ではなくファイルシステムのパスである',
+            ],
+            [
+                'path' => 'tests/Architecture/PostBootRouteMutationInventoryTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
+                'consumer' => null,
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
+                'reason' => 'prompt factory の走査根をリポジトリルートからの相対ディレクトリとして組み立てている箇所であり、URL ではなくファイルシステムのパスである',
+            ],
+            [
+                'path' => 'tests/Browser/CaptureAppBoundaryTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 7,
+                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
+                'consumer' => null,
+                'reason' => '撮影 PWA が入口の配下から自動で離脱しないことを実ブラウザで確かめる検査であり、正規の分岐入口そのものを叩く',
+            ],
+            [
+                'path' => 'tests/Feature/Billing/GateInversionF07RegressionTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
+                'consumer' => null,
+                'reason' => '未契約の組織でも撮影の入口へ到達できることの回帰であり、正規の分岐入口そのものを叩く',
+            ],
+            [
+                'path' => 'tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
+                'consumer' => null,
+                'reason' => '課金ゲートが撮影の入口を遮らないことの検査であり、正規の分岐入口そのものを叩く',
+            ],
+            [
+                'path' => 'tests/Feature/Capture/CaptureManualBrowsingTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 2,
+                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
+                'consumer' => null,
+                'reason' => '撮影 PWA の一覧へ入る導線の検査であり、正規の分岐入口そのものを叩く',
+            ],
+            [
+                'path' => 'tests/Feature/Capture/CapturePwaScopeTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 3,
+                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
+                'consumer' => null,
+                'reason' => 'PWA の scope と start_url の契約を固定する検査であり、正規の分岐入口そのものを名指しする',
+            ],
+            [
+                'path' => 'tests/Feature/Organization/OrganizationEntryTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 7,
+                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
+                'consumer' => null,
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
+                'reason' => 'データセットが渡すのは組織 URL の後ろに継ぐ suffix であり、同じテストの中で組織 URL と連結してから要求している',
+            ],
+            [
+                'path' => 'tests/Feature/Organizations/TwoFactorEnforcementTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => self::legacyRootEndingWith('ojects'),
+                'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::OrganizationRelativePath,
+                'consumer' => 'tests/Feature/Organizations/TwoFactorEnforcementTest.php',
+                'reason' => 'データセットが渡すのは組織 URL の後ろに継ぐ suffix であり、同じテストの中で組織 URL と連結してから要求している',
+            ],
+            [
+                'path' => 'tests/Support/ExternalSeam/ExternalSeamInventory.php',
                 'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => LegacyUrlScanner::captureRoot(),
+                'count' => 1,
                 'kind' => LegacyUrlAllowanceKind::FilesystemPath,
+                'consumer' => null,
+                'reason' => '外部到達点の目録が持つ走査根の相対ディレクトリであり、URL ではなくファイルシステムのパスである',
+            ],
+            [
+                'path' => 'tests/Unit/Services/Storage/FakeStorageKeyTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'matched' => self::legacyRootEndingWith('ojects'),
                 'count' => 1,
+                'kind' => LegacyUrlAllowanceKind::StorageObjectKey,
+                'consumer' => null,
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
@@ -111,9 +369,95 @@ public static function counts(): array
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
+     * @param  array{path: string, rule: string, matched: string, count: int, kind: LegacyUrlAllowanceKind, consumer: ?string, reason: string}  $entry
+     * @param  string  $captureEntryUri  route 表の `capture.entry` の URI (先頭スラッシュつき)
+     */
+    public static function preconditionViolation(array $entry, string $repositoryRoot, string $captureEntryUri): ?string
+    {
+        $contents = @file_get_contents($repositoryRoot.'/'.$entry['path']);
+        if ($contents === false) {
+            return "登録したパスが読めません: {$entry['path']}";
+        }
+
+        return match ($entry['kind']) {
+            LegacyUrlAllowanceKind::CanonicalCaptureEntry => $entry['matched'] === LegacyUrlScanner::captureRoot()
+                && $captureEntryUri === LegacyUrlScanner::captureRoot()
+                    ? null
+                    : "正規の分岐入口ではありません (語={$entry['matched']} / 入口の URI={$captureEntryUri})",
+            LegacyUrlAllowanceKind::FilesystemPath => is_dir($repositoryRoot.'/'.ltrim($entry['matched'], '/'))
+                ? null
+                : "実在するディレクトリではありません: {$entry['matched']}",
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
+        if ($consumer === null) {
+            return '組織相対パスの登録は利用側のファイルを名指しすること';
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
+
+        return $buildsOrganizationUrl
+            ? null
+            : "利用側が組織 URL を組み立てていません: {$consumer}";
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
index 60124deb..ea521d51 100644
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
diff --git a/tests/Support/LegacyUrl/LegacyUrlScanRoots.php b/tests/Support/LegacyUrl/LegacyUrlScanRoots.php
index 63d8681f..df938bac 100644
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
diff --git a/tests/Support/LegacyUrl/LegacyUrlScanner.php b/tests/Support/LegacyUrl/LegacyUrlScanner.php
index 024acc88..841f02c6 100644
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
@@ -156,12 +161,15 @@ public static function scanFile(LegacyUrlScannedFile $file): array
                     matched: $matched,
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
                 );
             }
         }
@@ -186,12 +194,50 @@ public static function extract(LegacyUrlScannedFile $file): array
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
+            $isRouteUri = preg_match(
+                '/(?:Route::(?:get|post|put|patch|delete|options|any|view|redirect|permanentRedirect|match|prefix)'
+                .'|->(?:prefix|as|name|domain))\(\s*(?:\[[^\]]*\]\s*,\s*)?$/',
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
@@ -202,9 +248,16 @@ public static function extract(LegacyUrlScannedFile $file): array
      */
     private static function scriptLiteralsWithOrgUrlAllowance(string $source, bool $hashComments): array
     {
+        // ★入口を**この 1 本の import で識別する**。取り込んでいないファイルでは
+        //   同名の関数を自前で定義しても規則 3 は効かない (shadowing で免除を作らせない)
+        $importsBuilder = str_contains($source, self::ORGANIZATION_URL_MODULE);
+        // ★前後関係の判定は**コメントを潰した写し**で行う (位置は元と同じ)。
+        //   生ソースを見ると `// currentOrgUrl(` の 1 行が次のリテラルまで届いて免除になる
+        $masked = SourceLiterals::maskComments($source, $hashComments);
+
         $kept = [];
         foreach (SourceLiterals::script($source, $hashComments) as $literal) {
-            if (self::isOrganizationUrlBuilderArgument($source, $literal['offset'])) {
+            if ($importsBuilder && self::isOrganizationUrlBuilderArgument($masked, $literal['offset'])) {
                 continue;
             }
             $kept[] = $literal;
@@ -214,16 +267,21 @@ private static function scriptLiteralsWithOrgUrlAllowance(string $source, bool $
     }
 
     /**
-     * その位置のリテラルが `orgUrl(...)` / `currentOrgUrl(...)` の引数として現れているか。
+     * その位置のリテラルが `orgUrl(...)` / `currentOrgUrl(...)` の引数として現れているか (規則 3)。
      *
-     * ★「開き括弧を閉じないまま入口名まで遡れるか」で判定する。`[^()]*` が括弧を跨がせないので、
-     *   入口を呼んだ後の別の呼び出し (`foo(bar(), '/x')`) は一致しない。
+     * ★条件は 3 つとも満たすこと:
+     *   1. 呼び出し名の直前が**識別子の文字でない** (`notOrgUrl(` / `x.orgUrl(` を弾く)
+     *   2. 開き括弧から遡る間に**括弧を跨がない** (別の呼び出しの引数を免除しない)
+     *   3. ファイルが組織 URL 組み立ての module を取り込んでいる (呼び出し側で判定済み)
+     *
+     * ★入力は**コメントを潰した写し**である (呼び出し側が渡す)。生ソースを渡すと
+     *   コメントの中の `orgUrl(` が次のリテラルまで届いて免除を作れてしまう。
      */
-    private static function isOrganizationUrlBuilderArgument(string $source, int $literalOffset): bool
+    private static function isOrganizationUrlBuilderArgument(string $maskedSource, int $literalOffset): bool
     {
-        $before = substr($source, 0, $literalOffset);
+        $before = substr($maskedSource, 0, $literalOffset);
 
-        return preg_match('/(?:currentOrgUrl|orgUrl)\(\s*[^()]*$/', $before) === 1;
+        return preg_match('/(?<![A-Za-z0-9_$.])(?:currentOrgUrl|orgUrl)\(\s*[^()]*$/', $before) === 1;
     }
 
     /**
@@ -244,13 +302,7 @@ public static function matchesIn(string $chunk): array
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
                 $matches[] = $root;
@@ -293,28 +345,6 @@ private static function isRootPosition(string $chunk, int $position, string $org
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

- `composer test`: 6840 tests / 6838 passed / 0 failed (exit 0。残り 2 は skipped)
- `composer phpstan` (level 10, 1050 files): No errors
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm build`: green
- `pnpm test`: 178 files / 2393 tests passed
- `pnpm test:packages`: 10 files / 106 tests passed
- 旧 URL 走査: 違反 0 件 / 未分類 0 件 / 未解決 0 件。
  **許可目録は 32 件**で、いずれも件数完全一致かつ区分ごとの前提を満たす。

## 4. 見送った指摘

- symlink / NUL / 不正 UTF-8 の fail-closed 分岐の負例追加は見送った。
  理由は対応マトリクスに書いたとおりで、母集団の `unresolved` を gate が毎回 0 件で見ているため
  「集めて使っていない」状態ではない。ここに異論があれば指摘してほしい。
