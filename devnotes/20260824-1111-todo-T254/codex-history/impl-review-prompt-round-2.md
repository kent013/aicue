## Round 2: Round 1 の [Warning] への対応差分

### 対応内容

`bughuntNamingOffsetsOf()` の 2 つの挙動 (重なり合う出現も別の出現として数える / 空 needle は例外) に
**永続的な正例・負例**を N-4 の先頭 `(0)` として追加した。検査の本数は 5 本のまま (詳細設計の方針を維持)。

```php
    // (0) 位置の取り出しそのものの正例・負例。docblock が明示している 2 つの挙動
    //     (重なり合う出現も別の出現として数える / 空文字は例外) をここで固定する。
    //     `$from = $at + 1` が非重複走査 (`$at + strlen($needle)`) へ退化しても
    //     (a)〜(l) は緑のままなので、この 2 行が無いと退化に沈黙する。
    expect(bughuntNamingOffsetsOf('aaa', 'aa'))->toBe([0, 1]);
    expect(fn () => bughuntNamingOffsetsOf('aaa', ''))->toThrow(RuntimeException::class);
```

### 実測

```
{"tool":"pest","result":"passed","tests":5,"passed":5,"assertions":70,"duration_ms":1910}
{"tool":"pint","result":"passed"}
composer phpstan → [OK] No errors (level 10)
```

### 対応後の全差分 (git diff HEAD -- tests/)

```diff
diff --git a/tests/Architecture/BughuntNamingResidualTest.php b/tests/Architecture/BughuntNamingResidualTest.php
index f10c6973..7d71ab7e 100644
--- a/tests/Architecture/BughuntNamingResidualTest.php
+++ b/tests/Architecture/BughuntNamingResidualTest.php
@@ -5,20 +5,56 @@
 use Symfony\Component\Process\Process;
 
 /*
- * 家系 (機能台帳 lctl の機能 bughunt-runtime) で 1 つに決まっている名前が、
- * 旧名へ戻らないことの固定。
+ * 家系 (機能台帳 lctl の feature `rename-residual-name-gate` / 正典 v1
+ * 「出現特定式許可台帳 — 空縮退可」) の追従。
+ * 改名で退いた旧名が、リポジトリの**現役の資産**に 1 件も残っていないことを機械で見張る。
  *
  * 裁定 AG-085 は「同じ関心事に名前が 2 つある状態」を、追従判断のたびに
  * 「欠落か別名か」の実読が発生することを理由に禁じている。2026-08-10 の裁定で
  * ファイル数の統合は撤回され、残る要件はこの名前の一意性だけである。
  *
+ * 判定の骨子 (正典 v1):
+ * - 母集団は **git 追跡下の全ファイル**。**内容とパス名の両方**を照合する
+ *   (中身を直しただけのファイルが旧名のパスで復活する経路は内容走査では塞げない)。
+ * - 旧名が現れてよいのは「いつ・誰が・何をしたかの記録」だけで、
+ *   **出現を 1 つずつ特定する申告** (対象ファイル / 旧名 / その出現を一意に特定する
+ *   周辺文字列 / 残す理由) を台帳に並べる。**行番号は使わない** (無関係な編集で動くため)。
+ *   **件数は申告の本数から導く** (件数の pin を別に持たない = 二重管理を作らない)。
+ * - 突き合わせは 3 方向で落ちる — 申告外の出現がある / 申告があるのに実物から消えた
+ *   (周辺文字列が 1 回に特定できない) / 申告が同じ出現を二重に指している。
+ *   この 3 つが「申告数と実出現数の不一致」を含意する。
+ * - **パス名に申告の口は無い** (記録としてファイル名に旧名が要る事案は無いため 0 件固定)。
+ *
  * ★保証範囲を誇張しない:
  *   - 見るのは**字面**である。旧名を分割して連結する書き方・別名の定数経由・
  *     動的に組み立てた文字列には**沈黙する**。
- *   - **丸ごと除外した分類 (c) の中では沈黙する**。分類 (b) は登録済みの件数だけを許容し、
- *     増減も旧名ごとの内訳の入れ替えも検出する (沈黙しない)。
+ *   - **丸ごと除外した 2 つ (`devnotes/` 配下と本ファイル自身) の中では沈黙する**。
+ *     そこに旧名を書いても本検査は検出しない (パス名の照合も除外が先に効く)。
+ *   - 申告について保証するのは「周辺文字列が実物にちょうど 1 回あり、それが指す出現の集合が
+ *     実出現の集合と一致する」ことまでである。**その記録が意味として妥当かは人のレビュー**が見る。
  *   - 家系名が「正しい名前であること」は検査できない。正本は機能台帳であり、
  *     本検査が固定するのは「旧名が現役の資産に残っていないこと」だけである。
+ *   - git 未追跡のファイルは母集団に入らない (境界は commit / CI であり、そこでは追跡下にある)。
+ *
+ * ★走査器共通規約 (AGENTS.md) との関係:
+ *   - (a) は対象外 — クラス名を**連続した字面**として探す走査で、名前参照の解決を行わない。
+ *   - (b) fail-closed — `git ls-files` の失敗と読めないファイルは**例外**にする (空集合にしない)。
+ *   - (c) 検出力は N-4 の負のコントロールが**同じ純関数**を通して裏取りする。
+ *   - (d) 集めた走査結果はすべて判定に使う (数えるだけの目録を持たない)。
+ *   - (e) は対象外 — 区切り文字でトークン化した完全一致にすると、実在する接尾辞つきの出現
+ *     (`docs/TODO-closed.md` の `FakeExternalsServiceProviderTest`) を**見逃す**方向へ倒れる。
+ *     本検査は許可語の除去や否定形の語彙判定を持たないため (e) の母集団に入らない。
+ *
+ * ★`Tests\Support\TrackedPhpSourceFiles` / `Tests\Support\SurfaceRemoval\RemovedSurfaceScanTargets`
+ *   との関係: 3 者は同じ作法 (`git ls-files`) を使うが**母集団の定義が違う**兄弟である。
+ *   前者は `.php` 全数、後者は走査根 8 本 (`docs/` と `.claude/` を見ない)、本検査は
+ *   **追跡下の全ファイル**である。本 feature の主敵は規約文書・スキル・手順書に残る旧名なので
+ *   `docs/` と `.claude/` を母集団から外せない。列挙を 2 本持つのではなく対象の定義が違う。
+ *   関心事の境界 (撤去物の不在 = surface-removal-absence-gate / 旧名の残留 = 本検査) は
+ *   機能台帳が名指しで分けている。
+ *
+ * ★申し送り: 裁定 AG-085 の 3 件目 (並列枠数上限の検査の名前) は feature `bughunt-runtime` の
+ *   管轄で、本検査の写像には**入っていない**。将来その改名を行うときは写像へ 1 件足すこと。
  *
  * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
  */
@@ -37,42 +73,53 @@
 ];
 
 /**
- * (b) 旧名を持つことが確認済みの**過去と現在の記録**と、**旧名ごとの**件数。
+ * 旧名の出現を許す場所の申告台帳 (**出現を 1 つずつ特定する**)。
  *
- * 件数は**完全一致**で、増えても減っても赤になる (`ForbiddenStatementExemption` /
- * `ROUTE_CACHE_PREMISE_KNOWN_MENTIONS` と同じ作法)。丸ごと除外にしないのは、
- * 除外したファイルの中で将来 旧名が再流入しても沈黙してしまうためである。
+ * `needle` = その出現を一意に特定する周辺文字列。実ファイルに**ちょうど 1 回**現れ、
+ * 対象の旧名を**ちょうど 1 回**含むこと。`reason` = 残す理由 (30 文字以上)。
+ * **件数は申告の本数**であり、別に pin しない。
  *
- * ★合計ではなく**旧名ごと**に固定する。合計だけを見ると「片方を 1 件減らして
- *   もう片方を 1 件増やす」書き換えが緑のまま通る。
+ * ★**記録を動かすときは同じ変更で申告も動かす** (意図的な摩擦)。作業台帳の行が
+ *   `docs/TODO.md` から `docs/TODO-closed.md` へ移ったら、正しい直し方は
+ *   「記録を書き換える」のではなく**申告を足す・移す・外す**である。
+ * ★申告 0 件の登録は書かない (実物から消えた申告と区別できないため)。`docs/TODO.md` は
+ *   現在旧名 0 件なので**登録そのものを持たない** — deny-by-default なので 1 つ現れれば赤になる。
  *
- * - `docs/TODO-closed.md`: 完了した TODO の記録。T015 / T119 が当時作ったクラスの
- *   名前は当時の事実であり、書き換えると記録が嘘になる。
- * - `docs/TODO.md`: 本件 (T214) の登録行そのものが「どの名前をどの名前へ改名するか」を
- *   書いているため旧名を含む。これも記録であって現役の資産ではない。
- *
- * ★**TODO 台帳を動かすときは本 pin も同じ変更の中で更新する** (意図的な摩擦)。
- *   T214 をクローズすると登録行が `docs/TODO.md` から `docs/TODO-closed.md` へ移り、
- *   両ファイルの件数が同時に動く。そのときは「記録を書き換える」のではなく
- *   **pin の数を直す**のが正しい直し方である。
- *
- * @var array<string, array<string, int>>
+ * @var array<string, array<string, list<array{needle: string, reason: string}>>>
  */
-const BUGHUNT_NAMING_KNOWN_MENTIONS = [
+const BUGHUNT_NAMING_DECLARED_OCCURRENCES = [
     'docs/TODO-closed.md' => [
-        'BughuntBillingSeeder' => 2,
-        'FakeExternalsServiceProvider' => 3,
-    ],
-    'docs/TODO.md' => [
-        'BughuntBillingSeeder' => 0,
-        'FakeExternalsServiceProvider' => 0,
+        'BughuntBillingSeeder' => [
+            [
+                'needle' => '・BughuntBillingSeeder (有料プラン組織のみ active subscription',
+                'reason' => 'T015 (bug-hunt 基盤整備) の完了行。当時作った seeder の名前は当時の事実であり、書き換えると記録が嘘になる',
+            ],
+            [
+                'needle' => '`database/seeders/BughuntBillingSeeder` → `BughuntStripeSyncSeeder`',
+                'reason' => 'T214 (家系名への改名) の完了行が持つ改名の対応表。旧名側を消すと何を何へ改名したのかが読めなくなる',
+            ],
+        ],
+        'FakeExternalsServiceProvider' => [
+            [
+                'needle' => '・FakeExternalsServiceProvider (flag + 環境 allowlist',
+                'reason' => 'T015 (bug-hunt 基盤整備) の完了行。当時作った provider の名前は当時の事実であり、書き換えると記録が嘘になる',
+            ],
+            [
+                'needle' => '`FakeExternalsServiceProviderTest` は 6 test ではなく 8 test',
+                'reason' => 'T119 (fake 配線の実証検査) の完了行が持つ台帳との食い違いの記録。当時のテストクラス名を指しており改名できない',
+            ],
+            [
+                'needle' => '`app/Providers/FakeExternalsServiceProvider` → `BughuntFakesServiceProvider`',
+                'reason' => 'T214 (家系名への改名) の完了行が持つ改名の対応表。旧名側を消すと何を何へ改名したのかが読めなくなる',
+            ],
+        ],
     ],
 ];
 
 /**
- * (c) 丸ごと走査から外す置き場所 (repo 相対の接頭辞)。
+ * 丸ごと走査から外す置き場所 (repo 相対の接頭辞)。
  *
- * `devnotes/` の設計・レビュー・走行記録は 190 ファイル規模で旧名を含み、件数 pin が
+ * `devnotes/` の設計・レビュー・走行記録は 190 ファイル規模で旧名を含み、出現ごとの申告が
  * 実務にならない (`ForbiddenStatementTokenInvariantTest` が devnotes を理由付きで
  * 除外するのと同じ扱い)。
  *
@@ -83,34 +130,79 @@
 const BUGHUNT_NAMING_EXCLUDED_PREFIXES = ['devnotes/'];
 
 /**
- * (c) 丸ごと走査から外す唯一のファイル = 本テスト自身。
+ * 丸ごと走査から外す唯一のファイル = 本テスト自身。
  *
- * 検出したい語を負のコントロールの入力として持つため、自分を走査すると必ず自分で赤くなる。
+ * 申告の needle と負のコントロールの入力として旧名を持つため、自分を走査すると必ず自分で赤くなる。
  * **保証の穴として明記する**: 本ファイルの中に旧名を書いても本検査は沈黙する。
+ * 骨抜きにならないことは (1) 申告が実出現と一致すること (N-3) と
+ * (2) 家系名を実際に見つける正の対照 (N-2) の 2 つで担保する。
  */
 const BUGHUNT_NAMING_SELF_PATH = 'tests/Architecture/BughuntNamingResidualTest.php';
 
 /**
- * 走査の母集団が空振りでないことを確かめる代表パス (改名後に実在するもの)。
+ * 置き換え先 (家系名) の番兵。家系名 => その名前が実在するファイル。
+ *
+ * 正典の要求「置き換え先が実在しかつ git 追跡下にあること」を満たす
+ * (未追跡だと母集団に入らず走査が空振りする)。N-2 は**同じ読み取り機構**で
+ * この名前を実際に見つけることまで確かめる (正の対照)。
+ *
+ * @var array<string, string>
+ */
+const BUGHUNT_NAMING_CANONICAL_SENTINELS = [
+    'BughuntStripeSyncSeeder' => 'database/seeders/BughuntStripeSyncSeeder.php',
+    'BughuntFakesServiceProvider' => 'app/Providers/BughuntFakesServiceProvider.php',
+];
+
+/**
+ * 走査の母集団が空振りでないことを確かめる参照側の代表パス。
  *
  * @var list<string>
  */
 const BUGHUNT_NAMING_SENTINEL_PATHS = [
     'bootstrap/providers.php',
     'scripts/bug-hunt-shard.sh',
-    'database/seeders/BughuntStripeSyncSeeder.php',
-    'app/Providers/BughuntFakesServiceProvider.php',
 ];
 
 /** 母集団の下限 (これを下回ったら列挙そのものを疑う) */
 const BUGHUNT_NAMING_MINIMUM_TRACKED_FILES = 500;
 
+/** 申告の理由に求める最小文字数 (本リポジトリの目録の作法に合わせる)。 */
+const BUGHUNT_NAMING_MINIMUM_REASON_LENGTH = 30;
+
+/**
+ * `$haystack` 内の `$needle` の出現位置 (バイト位置、昇順)。
+ *
+ * ★重なり合う出現も**別の出現として数える** (見逃さない側へ倒す)。
+ * ★空文字は出現位置を持たないので例外にする (申告の書き方の誤り)。
+ *
+ * @return list<int>
+ */
+function bughuntNamingOffsetsOf(string $haystack, string $needle): array
+{
+    if ($needle === '') {
+        throw new RuntimeException('空文字は出現位置を持たない (旧名の残留検査の申告の書き方の誤り)');
+    }
+
+    $offsets = [];
+    $from = 0;
+
+    while (($at = strpos($haystack, $needle, $from)) !== false) {
+        $offsets[] = $at;
+        $from = $at + 1;
+    }
+
+    return $offsets;
+}
+
 /**
  * 1 ファイル分の違反 (純関数 = 負のコントロールが**同じ述語**を通せる)。
  *
+ * 申告台帳は**引数で受ける** — 負のコントロールが実ファイルの内容に依存しないため。
+ *
+ * @param  array<string, array<string, list<array{needle: string, reason: string}>>>  $declarations
  * @return list<string>
  */
-function bughuntNamingViolationsIn(string $relative, string $content): array
+function bughuntNamingViolationsIn(string $relative, string $content, array $declarations): array
 {
     if ($relative === BUGHUNT_NAMING_SELF_PATH) {
         return [];
@@ -122,21 +214,90 @@ function bughuntNamingViolationsIn(string $relative, string $content): array
         }
     }
 
-    // 記録は「0 件」ではなく「pin した件数ちょうど」を旧名ごとに要求する。
-    $pinned = BUGHUNT_NAMING_KNOWN_MENTIONS[$relative] ?? [];
-
     $violations = [];
+
     foreach (BUGHUNT_RETIRED_NAMES as $retired => $canonical) {
-        $count = substr_count($content, $retired);
-        $allowed = $pinned[$retired] ?? 0;
+        // (1) パス名の照合 — 申告の口は無い (0 件固定)。
+        if (str_contains($relative, $retired)) {
+            $violations[] = sprintf(
+                'パス名に旧名が復活している: %s (旧名 %s / 家系名 %s) — パスごと家系名へ改名すること'
+                .' (パス名には申告の口が無い)',
+                $relative,
+                $retired,
+                $canonical
+            );
+        }
 
-        if ($count === $allowed) {
-            continue;
+        // (2) 内容の照合 — 実出現の位置集合と、申告が指す位置集合を突き合わせる。
+        $actual = bughuntNamingOffsetsOf($content, $retired);
+        $declared = [];
+
+        foreach ($declarations[$relative][$retired] ?? [] as $entry) {
+            $inner = bughuntNamingOffsetsOf($entry['needle'], $retired);
+
+            if (count($inner) !== 1) {
+                $violations[] = sprintf(
+                    '申告の周辺文字列が旧名をちょうど 1 回含まない: %s / 旧名 %s / 周辺文字列 "%s" (含む回数 %d)'
+                    .' / 理由: %s — 出現を 1 つだけ指す文字列に書き直すこと',
+                    $relative,
+                    $retired,
+                    $entry['needle'],
+                    count($inner),
+                    $entry['reason']
+                );
+
+                continue;
+            }
+
+            $hits = bughuntNamingOffsetsOf($content, $entry['needle']);
+
+            if (count($hits) !== 1) {
+                $violations[] = sprintf(
+                    '申告が出現を特定できない: %s / 旧名 %s / 周辺文字列 "%s" が %d 回 (ちょうど 1 回であること)'
+                    .' / 理由: %s — 記録を書き換えるのではなく、申告を足す・移す・外すこと',
+                    $relative,
+                    $retired,
+                    $entry['needle'],
+                    count($hits),
+                    $entry['reason']
+                );
+
+                continue;
+            }
+
+            $declared[] = $hits[0] + $inner[0];
+        }
+
+        sort($declared);
+
+        // 有効な申告位置は構築上必ず実出現位置に含まれる (周辺文字列は本文にちょうど 1 回・
+        // 旧名をちょうど 1 回) ため、逆向きの差分は常に空になる。だから片方向だけを見る。
+        $undeclared = array_values(array_diff($actual, $declared));
+
+        if ($undeclared !== []) {
+            $violations[] = sprintf(
+                '申告外の出現がある: %s / 旧名 %s (家系名 %s) / 実出現 %d 件・申告 %d 件'
+                .' / 未申告の位置 %s — 改名の取りこぼしなら家系名へ直すこと。記録として残すなら、'
+                .'記録を書き換えるのではなく、申告を足す・移す・外すこと',
+                $relative,
+                $retired,
+                $canonical,
+                count($actual),
+                count($declared),
+                implode(', ', array_map('strval', $undeclared))
+            );
         }
 
-        $violations[] = $allowed === 0
-            ? "{$relative}: {$retired} が {$count} 箇所残っている (家系名は {$canonical})"
-            : "{$relative}: {$retired} の出現が {$count} 箇所 (pin は {$allowed} 箇所)";
+        if (count($declared) !== count(array_unique($declared))) {
+            $violations[] = sprintf(
+                '申告が同じ出現を二重に指している: %s / 旧名 %s / 実出現 %d 件・申告 %d 件'
+                .' — 記録を書き換えるのではなく、申告を足す・移す・外すこと',
+                $relative,
+                $retired,
+                count($actual),
+                count($declared)
+            );
+        }
     }
 
     return $violations;
@@ -145,8 +306,7 @@ function bughuntNamingViolationsIn(string $relative, string $content): array
 /**
  * git 追跡下の全ファイル (repo 相対パス、昇順)。
  *
- * ★対象は拡張子を問わない (シェル / 文書 / 環境ひな型も見る) ため
- *   `Tests\Support\TrackedPhpSourceFiles` は使えない。共用クラスを新設せず本テスト内に閉じる。
+ * ★対象は拡張子を問わない (シェル / 文書 / 環境ひな型も見る)。
  * ★git が使えない環境では**空を返さず例外**にする (fail-open の防止)。
  *
  * @return list<string>
@@ -195,11 +355,17 @@ function bughuntNamingSourceOf(string $relative): string
     return $content;
 }
 
-test('N-1 追跡下の現役資産に旧名が 1 つも残っておらず、記録は pin した件数ちょうどである', function (): void {
+test('N-1 追跡下の内容とパス名に旧名の残留が無く、記録は申告と厳密に一致する', function (): void {
     $violations = [];
 
     foreach (bughuntNamingTrackedFiles() as $relative) {
-        foreach (bughuntNamingViolationsIn($relative, bughuntNamingSourceOf($relative)) as $violation) {
+        $found = bughuntNamingViolationsIn(
+            $relative,
+            bughuntNamingSourceOf($relative),
+            BUGHUNT_NAMING_DECLARED_OCCURRENCES
+        );
+
+        foreach ($found as $violation) {
             $violations[] = $violation;
         }
     }
@@ -207,7 +373,7 @@ function bughuntNamingSourceOf(string $relative): string
     expect($violations)->toBe([]);
 });
 
-test('N-2 fail-closed: 走査の母集団が空振りしていない', function (): void {
+test('N-2 fail-closed: 走査が空振りしていない (母集団の下限・番兵・家系名の正の対照)', function (): void {
     $files = bughuntNamingTrackedFiles();
 
     expect(count($files))->toBeGreaterThanOrEqual(
@@ -218,54 +384,186 @@ function bughuntNamingSourceOf(string $relative): string
     foreach (BUGHUNT_NAMING_SENTINEL_PATHS as $sentinel) {
         expect($files)->toContain($sentinel);
     }
+
+    // 正の対照: 「旧名 0 件」が走査の故障による偽の緑でないことを、在るはずの家系名で確かめる。
+    // 置き換え先が実在しかつ git 追跡下にあること (正典の要求) もここで満たす。
+    foreach (BUGHUNT_NAMING_CANONICAL_SENTINELS as $canonical => $path) {
+        expect($files)->toContain($path);
+
+        expect(bughuntNamingOffsetsOf(bughuntNamingSourceOf($path), $canonical))->not->toBe(
+            [],
+            "家系名 {$canonical} が {$path} の内容で見つからない — 走査条件の陳腐化を疑う",
+        );
+        expect(str_contains($path, $canonical))->toBeTrue(
+            "家系名 {$canonical} がパス名 {$path} で見つからない — 番兵の陳腐化を疑う",
+        );
+    }
 });
 
-test('N-3 走査の外し方が意図どおり (ファイル数ではなく**定義の数**を固定する)', function (): void {
+test('N-3 申告台帳と除外の構造が意図どおり (台帳から実物への逆方向も見る)', function (): void {
     // 丸ごと除外の定義は 2 つちょうど (接頭辞 devnotes/ が 1 件 + 本テスト自身が 1 件)。
     expect(BUGHUNT_NAMING_EXCLUDED_PREFIXES)->toBe(['devnotes/'])
         ->and(BUGHUNT_NAMING_SELF_PATH)->toBe('tests/Architecture/BughuntNamingResidualTest.php');
 
-    // 件数 pin の定義は 2 ファイル分ちょうど (TODO 台帳の 2 冊)。旧名は 2 種とも書く。
-    expect(array_keys(BUGHUNT_NAMING_KNOWN_MENTIONS))->toBe(['docs/TODO-closed.md', 'docs/TODO.md']);
-
-    foreach (BUGHUNT_NAMING_KNOWN_MENTIONS as $pinned) {
-        expect(array_keys($pinned))->toBe(array_keys(BUGHUNT_RETIRED_NAMES));
-    }
-
     // 退役した名前は 2 つで、家系名と 1:1 に対応する。
     expect(BUGHUNT_RETIRED_NAMES)->toBe([
         'BughuntBillingSeeder' => 'BughuntStripeSyncSeeder',
         'FakeExternalsServiceProvider' => 'BughuntFakesServiceProvider',
     ]);
-});
 
-test('N-4 負のコントロール: 同じ述語が検出する / しないの境界', function (): void {
-    $retired = array_keys(BUGHUNT_RETIRED_NAMES);
-    $seeder = $retired[0];
-    $provider = $retired[1];
+    // 置き換え先には 1 つずつ番兵がある (写像の値と番兵のキーが完全一致)。
+    expect(array_keys(BUGHUNT_NAMING_CANONICAL_SENTINELS))->toBe(array_values(BUGHUNT_RETIRED_NAMES));
 
-    // (a) 現役資産に旧名があれば検出する
-    expect(bughuntNamingViolationsIn('app/Foo.php', "class Foo extends {$seeder} {}"))->toHaveCount(1);
+    // 申告台帳のキーは記録 1 冊ちょうど (docs/TODO.md は旧名 0 件なので登録を持たない)。
+    expect(array_keys(BUGHUNT_NAMING_DECLARED_OCCURRENCES))->toBe(['docs/TODO-closed.md']);
 
-    // (b) devnotes/ は丸ごと外れている (沈黙する)
-    expect(bughuntNamingViolationsIn('devnotes/x/y.md', "{$seeder} {$provider}"))->toBe([]);
+    $files = bughuntNamingTrackedFiles();
 
-    // (c) 本テスト自身も丸ごと外れている (沈黙する)
-    expect(bughuntNamingViolationsIn(BUGHUNT_NAMING_SELF_PATH, "{$seeder} {$provider}"))->toBe([]);
+    foreach (BUGHUNT_NAMING_DECLARED_OCCURRENCES as $path => $perRetiredName) {
+        // ★`toContain` は可変長の needle を取るので**メッセージを渡さない** (第 2 引数は
+        //   もう 1 つの needle として解釈される)。理由文を添える判定は真偽値へ落として書く。
+        expect(in_array($path, $files, true))->toBeTrue(
+            "申告した記録が追跡下に無い: {$path} — ファイルごと消えたなら申告も外すこと",
+        );
 
-    // (d) pin したファイルで件数がずれたら検出する (少なくても多くても)
-    // docs/TODO-closed.md の pin は T214 クローズ後の値 (seeder=2 / provider=3)。
-    expect(bughuntNamingViolationsIn('docs/TODO-closed.md', "{$seeder} {$seeder} {$provider} {$provider} {$provider}"))->toBe([]);
-    expect(bughuntNamingViolationsIn('docs/TODO-closed.md', "{$provider} {$provider} {$provider}"))->toHaveCount(1);
-    expect(bughuntNamingViolationsIn('docs/TODO-closed.md', "{$seeder} {$seeder} {$seeder} {$provider} {$provider} {$provider}"))->toHaveCount(1);
+        $content = bughuntNamingSourceOf($path);
+
+        expect($perRetiredName)->not->toBe([], "旧名の項目を 1 つも持たない登録: {$path} — 行ごと外すこと");
+
+        foreach ($perRetiredName as $retired => $entries) {
+            expect(BUGHUNT_RETIRED_NAMES)->toHaveKey($retired);
+            expect($entries)->not->toBe([], "申告 0 件の登録は意味を持たない: {$path} / {$retired} — 行ごと外すこと");
+
+            foreach ($entries as $entry) {
+                expect(mb_strlen($entry['reason']))->toBeGreaterThanOrEqual(
+                    BUGHUNT_NAMING_MINIMUM_REASON_LENGTH,
+                    "申告の理由が短すぎる: {$path} / {$retired}",
+                );
+                expect(bughuntNamingOffsetsOf($entry['needle'], $retired))->toHaveCount(
+                    1,
+                    "申告の周辺文字列が旧名をちょうど 1 回含まない: {$path} / {$retired} — {$entry['needle']}",
+                );
+                expect(bughuntNamingOffsetsOf($content, $entry['needle']))->toHaveCount(
+                    1,
+                    "申告の周辺文字列が実物にちょうど 1 回現れない: {$path} / {$retired} — {$entry['needle']}",
+                );
+            }
+
+            // 件数は申告の本数から導く (別に pin を持たない)。
+            expect(count($entries))->toBe(
+                count(bughuntNamingOffsetsOf($content, $retired)),
+                "申告の本数が実出現数と合わない: {$path} / {$retired}",
+            );
+        }
+    }
+});
 
-    // (e) 合計は同じだが内訳が違う入力も検出する (旧名ごとに固定しているため)
-    expect(bughuntNamingViolationsIn('docs/TODO-closed.md', "{$seeder} {$seeder} {$seeder} {$provider} {$provider}"))->toHaveCount(2);
+test('N-4 負のコントロール: 同じ述語が検出する / しないの境界', function (): void {
+    $retired = array_keys(BUGHUNT_RETIRED_NAMES);
+    $canonical = array_values(BUGHUNT_RETIRED_NAMES);
+    $seeder = $retired[0];
+    $provider = $retired[1];
 
-    // (f) もう 1 冊の TODO 台帳 (docs/TODO.md は T214 クローズ後、旧名ともに 0 件) でも同じ境界が働く
-    expect(bughuntNamingViolationsIn('docs/TODO.md', ''))->toBe([]);
-    expect(bughuntNamingViolationsIn('docs/TODO.md', "{$seeder}"))->toHaveCount(1);
-    expect(bughuntNamingViolationsIn('docs/TODO.md', "{$seeder} {$provider}"))->toHaveCount(2);
+    // (0) 位置の取り出しそのものの正例・負例。docblock が明示している 2 つの挙動
+    //     (重なり合う出現も別の出現として数える / 空文字は例外) をここで固定する。
+    //     `$from = $at + 1` が非重複走査 (`$at + strlen($needle)`) へ退化しても
+    //     (a)〜(l) は緑のままなので、この 2 行が無いと退化に沈黙する。
+    expect(bughuntNamingOffsetsOf('aaa', 'aa'))->toBe([0, 1]);
+    expect(fn () => bughuntNamingOffsetsOf('aaa', ''))->toThrow(RuntimeException::class);
+
+    // 合成の申告台帳と合成の本文 (実ファイルの内容に依存させない)。
+    $reason = '負のコントロール用の合成理由 (30 文字以上であることを N-3 と同じ規則で満たす)';
+    $ledger = [
+        'docs/record.md' => [
+            $seeder => [
+                ['needle' => "T001 で {$seeder} を作った", 'reason' => $reason],
+            ],
+        ],
+    ];
+    $body = "行 1: T001 で {$seeder} を作った\n行 2: ふつうの文\n";
+
+    // (a) 申告どおりなら緑
+    expect(bughuntNamingViolationsIn('docs/record.md', $body, $ledger))->toBe([]);
+
+    // (b) ★v1 の主眼: 件数は同じだが出現箇所をすり替えた入力は赤になる
+    //     (申告の周辺文字列が消え、別の位置に未申告の出現が生まれる = 2 件)
+    $swapped = "行 1: ふつうの文\n行 2: T002 で {$seeder} を消した\n";
+    $swappedViolations = bughuntNamingViolationsIn('docs/record.md', $swapped, $ledger);
+    expect($swappedViolations)->toHaveCount(2);
+    expect(implode("\n", $swappedViolations))->toContain('申告を足す・移す・外す');
+
+    // (c) 申告外の出現が増えたら赤
+    expect(bughuntNamingViolationsIn('docs/record.md', $body."後から {$seeder}\n", $ledger))->toHaveCount(1);
+
+    // (d) 申告があるのに実物から消えたら赤
+    expect(bughuntNamingViolationsIn('docs/record.md', "行 1: ふつうの文\n", $ledger))->toHaveCount(1);
+
+    // (e) 申告の無いファイルの内容に旧名があれば赤 (deny-by-default)
+    expect(bughuntNamingViolationsIn('app/Foo.php', "class Foo extends {$seeder} {}", $ledger))->toHaveCount(1);
+
+    // (f) ★パス名に旧名を持つファイルは、内容が空でも赤
+    expect(bughuntNamingViolationsIn("app/Providers/{$provider}.php", '', $ledger))->toHaveCount(1);
+
+    // (g) 置き換え先 (家系名) は内容もパス名も誤検出しない
+    expect(bughuntNamingViolationsIn("database/seeders/{$canonical[0]}.php", "class {$canonical[0]} {}", $ledger))->toBe([]);
+    expect(bughuntNamingViolationsIn("app/Providers/{$canonical[1]}.php", "class {$canonical[1]} {}", $ledger))->toBe([]);
+
+    // (h) 丸ごと除外した 2 つは沈黙する (保証の穴の実測)
+    expect(bughuntNamingViolationsIn('devnotes/x/y.md', "{$seeder} {$provider}", $ledger))->toBe([]);
+    expect(bughuntNamingViolationsIn(BUGHUNT_NAMING_SELF_PATH, "{$seeder} {$provider}", $ledger))->toBe([]);
+
+    // (i) 周辺文字列が 2 回現れる (出現を特定できない) 場合も赤
+    $twice = "行 1: T001 で {$seeder} を作った\n行 2: T001 で {$seeder} を作った\n";
+    expect(bughuntNamingViolationsIn('docs/record.md', $twice, $ledger))->toHaveCount(2);
+
+    // (j) 同じ出現を二重に申告したら赤
+    $duplicated = [
+        'docs/record.md' => [
+            $seeder => [
+                ['needle' => "T001 で {$seeder} を作った", 'reason' => $reason],
+                ['needle' => "T001 で {$seeder} を作った", 'reason' => $reason],
+            ],
+        ],
+    ];
+    $duplicateViolations = bughuntNamingViolationsIn('docs/record.md', $body, $duplicated);
+    expect($duplicateViolations)->toHaveCount(1);
+    expect(implode("\n", $duplicateViolations))->toContain('二重に指している');
+
+    // (k) 周辺文字列が旧名を 2 回含む (出現を 1 つに絞れていない) 申告は赤
+    $ambiguous = [
+        'docs/record.md' => [
+            $seeder => [
+                ['needle' => "T001 で {$seeder} と {$seeder}", 'reason' => $reason],
+            ],
+        ],
+    ];
+    $ambiguousViolations = bughuntNamingViolationsIn('docs/record.md', "T001 で {$seeder} と {$seeder}\n", $ambiguous);
+    // 2 件は別の情報である — (1) 申告そのものが不正 / (2) その申告を採用できなかった結果として
+    // 実出現が未申告になる。両方出す**診断方針を契約として固定する**。将来「原因の申告エラーが
+    // あれば派生を抑制する」方針へ変えるなら、それは診断方針の変更なので期待件数も同じ変更で直す。
+    expect($ambiguousViolations)->toHaveCount(2);
+    expect(implode("\n", $ambiguousViolations))->toContain('ちょうど 1 回含まない');
+
+    // (l) ★件数は一致するが 2 つの申告が同じ出現を指し、別の 1 件が未申告になる入力。
+    //     件数の比較だけなら緑になるため、**出現位置の集合一致でなければ捕まらない**。
+    //     この 1 ケースが「位置集合まで強める価値」の実測である。
+    $twoOccurrences = "行 1: T001 で {$seeder} を作った\n行 2: T002 で {$seeder} を消した\n";
+    $sameSpotTwice = [
+        'docs/record.md' => [
+            $seeder => [
+                ['needle' => "T001 で {$seeder}", 'reason' => $reason],
+                ['needle' => "で {$seeder} を作った", 'reason' => $reason],
+            ],
+        ],
+    ];
+    // 申告 2 件・実出現 2 件 = 件数は一致する (前提の確認)。
+    expect(count($sameSpotTwice['docs/record.md'][$seeder]))
+        ->toBe(count(bughuntNamingOffsetsOf($twoOccurrences, $seeder)));
+
+    $sameSpotViolations = bughuntNamingViolationsIn('docs/record.md', $twoOccurrences, $sameSpotTwice);
+    expect($sameSpotViolations)->toHaveCount(2);
+    expect(implode("\n", $sameSpotViolations))->toContain('申告外の出現がある');
+    expect(implode("\n", $sameSpotViolations))->toContain('二重に指している');
 });
 
 test('N-5 旧名のクラスは存在せず、家系名のクラスが存在する', function (): void {
```

この対応で全体判定を再度出してほしい (`APPROVED` または `CHANGES_REQUESTED` の 1 語)。
