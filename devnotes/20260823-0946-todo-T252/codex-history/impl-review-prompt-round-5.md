Round 4 の指摘に対する対応を報告する。以下の対応マトリクスと修正差分を確認し、再判定せよ。

**これが延長後の上限 (Round 5) である**。Round 4 の指摘 ([Critical] 2 件 + [Warning] 1 件 +
[Suggestion] 1 件) は**すべて対応した**。反論は 1 件も無い。

出力形式は同じ (ファイルごとの判定 / [Critical] [Warning] [Suggestion] 分類 /
最後に `APPROVED` または `CHANGES_REQUESTED` の 1 語)。

対象コードは `/workspace/.claude/worktrees/tasks/T252/` 配下にある (読み込み可)。
Round 4 以降の変更は下記の差分がすべてである。

---

# 対応マトリクス: impl-review Round 4

> Round 4 の全体判定は `CHANGES_REQUESTED`。指摘は [Critical] 2 件 (同じ事象の別側面) +
> [Warning] 1 件 + [Suggestion] 1 件。**すべて対応した**。

## [Critical] `test(…)->skip()` の後置で、登録したまま 7 本を評価させない経路が残る

- 判断: **対応する** (指摘は正しい。実際に無力化できた)
- 根拠 (実測): 禁止表明の `});` を `})->skip();` に変えると、
  `composer test -- --filter=ArchBaseline` は **82 tests / 75 passed / 7 skipped で全緑**だった。
  指摘のとおり、ヘッダー (`EXPECTED_CHAIN_HEADER_TOKENS`)・表明の文 (`EXPECTED_CHAIN_TOKENS`)・
  最上位の制御構造 1 件・打ち切り 0 件・7 規則の description の登録 (S4-3c の `missing` が空) の
  **すべてが一致したまま**、closure だけが実行されない。
- 対応内容: 指摘が挙げた 3 案のうち **1 案目 (生成文を後置まで閉じる) を主層**に据え、
  **2 案目 (登録簿の状態検査) を実行時の第 2 層**として併せて入れた。
  1. `ArchBaseline::EXPECTED_CHAIN_FOOTER_TOKENS = ['}', ')', ';', '}']` を新設
     (closure を閉じる `}` / `test(` を閉じる `)` / 文末 `;` / `foreach` を閉じる `}`)。
  2. `ArchSurfaceScanner::tokensAfter()` を新設 (`tokensBefore()` の対。範囲外は例外)。
  3. **S4-3** (宿主ファイル内) と **テスト 38** (別ファイルの外部自己検査) の**両方**が
     表明の文の直後 4 トークンを footer と exact-fit で照合する。
     後置が 1 つでも付けば `['}', ')', '->', 'skip']` になり両方が赤くなる。
  4. **S4-3c を「登録されている」から「実行修飾を 1 つも持たない」へ強めた**。
     `new TestCaseMethodFactory(__FILE__, null)` (新品) と登録済み 7 本の
     **公開プロパティ全体を `==` で差分比較**する deny-by-default にした
     (修飾名の一覧を持たないので、Pest が修飾を増やしても自動で効く)。
     `chains` 等は別インスタンスなので `===` ではなく `==` を使う
     (`==` は private を含む全プロパティを再帰比較する)。
     `attributes` だけは Pest が description から `#[Test]` + `#[TestDox]` を必ず 2 個作るので
     新品と比べられず、**その 2 個ちょうど**を期待形にした
     (`->group()` / `->depends()` / `->with()` / `->only()` はここへ追加するので増えれば赤)。
- 実測 (注入 3 形すべてで**3 つの検査が同時に赤**になる):

  | 注入 | 赤くなる検査 |
  |---|---|
  | `})->skip();` | テスト 38 / S4-3 / S4-3c |
  | `})->todo();` | テスト 38 / S4-3 / S4-3c |
  | `})->group('x');` | テスト 38 / S4-3 / S4-3c |

  `->group('x')` は**属性しか変えない**ので、S4-3c が赤くなることが
  「属性の期待形 2 個」の分岐が現に効いている証拠である。

## [Critical] 後置の実行修飾の負例が走査器テストに無い

- 判断: **対応する**
- 対応内容: 負例 **13g** を追加した。合成した期待形チェーンの `});` を `})->skip();` に置換し、
  - 置換が実際に起きたこと (負例が負例になっていること) を先に確認
  - **表明の文とヘッダーは両者で完全に一致する**こと (= 後置を見ないと区別できない) を固定
  - 後置だけが `['}', ')', ';', '}']` と `['}', ')', '->', 'skip']` で分かれることを固定
  - 範囲外は例外 (fail-closed) であることを両方向で固定

## [Warning] 台帳の保証記述を「実行可能な状態で登録されている」まで上げる

- 判断: **対応する**
- 対応内容: D43 の「揃え続ける不変条件と保証機構」を
  「7 本が実際に Pest へ**実行可能な状態で**登録されたことまで実行時に確かめ
  (登録の有無に加えて、新品の factory との差分比較で skip / todo 等の実行修飾が
  1 つも付いていないことを見る)、**生成文の後置トークンまで exact-fit で閉じ**、…」へ更新した。

## [Suggestion] arrow function の式内部は波括弧を持たないので「本体は数えない」が厳密でない

- 判断: **対応する** (指摘のとおり。保証範囲の記述を正確にした)
- 対応内容: `topLevelControlStructureSites()` の docblock に
  「**arrow function (`fn () => …`) の式の中は区別しない**。最上位の
  `fn () => match ($x) { … }` の `match` は 1 件として現れる。**拾いすぎる側の誤差**であり
  見逃しではない」と明記し、同じことが `topLevelAbortSites()` の `throw` にも当てはまる
  (`fn () => throw new …`) ことを併記した。宿主ファイルの「ちょうど 1 件」契約は
  この形を持たないので実害はない。

## 修正後の実測

- `composer test -- --filter=ArchBaseline`: **83 tests / 83 passed / 207 assertions**
- `composer test` (全数) / `composer phpstan` / `vendor/bin/pint --test`: 報告本文の数値のとおり
- `vendor/bin/phpstan analyse --level=10` (設計固有の 3 パス): **0 errors**
- 抑止コメント・baseline 追加・型の widen・設定の緩和はいずれも使っていない

---

# 修正差分 (Round 4 の返答以降のすべて)

```diff
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 963d87bc..92e0b7bf 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -2527,7 +2527,7 @@ ## D43 Pest arch のベースラインを、正典の 9 規則ではなく本ア
 |---|---|
 | 対象パス | `tests/Architecture/ArchBaselineTest.php` / `tests/Support/Architecture/ArchBaseline.php` / `tests/Support/Architecture/ArchSurfaceScanner.php` / `tests/Support/Architecture/ArchTokenStream.php` / `tests/Support/Architecture/GlobalFunctionCallScanner.php` / `tests/Support/Architecture/VendorArchPresetReader.php` / `tests/Unit/Architecture/ArchBaselineScannerTest.php` |
 | 業務要件起因の説明 | 家系の正典 v1 は禁止シンボルを規則ごとに分解して例外の波及半径を 1 シンボルに閉じることを求めるが、正典の 9 規則 102 シンボルという分解はテンプレート側の例外クラス構成から出た数である。本アプリの走査域 (App と Database\Factories と Database\Seeders) で禁止語彙を実使用しているのは sha1 と tempnam と var_export の 3 語彙 5 クラスだけであり、母集団に対する正しい分解は例外なし 4 束 + 単独シンボル 3 本の 7 規則になる。正典の本数をそのまま写すと実体の無い規則が生まれる |
-| 揃え続ける不変条件と保証機構 | 例外を持つ規則の対象シンボルがちょうど 1 個であること (`ArchBaselineTest` の S3) / 7 規則の語彙の和集合が vendor preset の禁止語彙集合と一致すること (S5。移植漏れと vendor 更新の両方を検出) / 例外の置き場が `ArchBaseline` 1 クラスに限られ禁止表明を作るチェーンが 1 本であること (S4 が tests 配下の追跡 PHP 全数を母集団に完全一致で照合し、7 本が実際に Pest へ登録されたことまで実行時に確かめ、宿主ファイルが最上位の短絡 — 実行を打ち切る return 等と、宣言を条件付きにする最上位の制御構造で丸ごと囲む形の 2 つ — を受けていないことは別ファイルの外部自己検査が見張る) |
+| 揃え続ける不変条件と保証機構 | 例外を持つ規則の対象シンボルがちょうど 1 個であること (`ArchBaselineTest` の S3) / 7 規則の語彙の和集合が vendor preset の禁止語彙集合と一致すること (S5。移植漏れと vendor 更新の両方を検出) / 例外の置き場が `ArchBaseline` 1 クラスに限られ禁止表明を作るチェーンが 1 本であること (S4 が tests 配下の追跡 PHP 全数を母集団に完全一致で照合し、7 本が実際に Pest へ**実行可能な状態で**登録されたことまで実行時に確かめ (登録の有無に加えて、新品の factory との差分比較で skip / todo 等の実行修飾が 1 つも付いていないことを見る)、生成文の後置トークンまで exact-fit で閉じ、宿主ファイルが最上位の短絡 — 実行を打ち切る return 等と、宣言を条件付きにする最上位の制御構造で丸ごと囲む形の 2 つ — を受けていないことは別ファイルの外部自己検査が見張る) |
 | 再判定の条件 | 正典が per-rule 分解の規約そのものを変えたとき / Pest の preset 構成が変わり集合一致が取れなくなったとき / 本アプリで層分離規則 (toOnlyBeUsedIn 等) を導入するとき |
 | 決めた日 | 2026-08-23 |
 | 決めた人 | 開発者 |
diff --git a/tests/Architecture/ArchBaselineTest.php b/tests/Architecture/ArchBaselineTest.php
index 1bd78b2e..85e2a6d2 100644
--- a/tests/Architecture/ArchBaselineTest.php
+++ b/tests/Architecture/ArchBaselineTest.php
@@ -12,7 +12,11 @@
 use Pest\ArchPresets\Laravel;
 use Pest\ArchPresets\Php;
 use Pest\ArchPresets\Security;
+use Pest\Factories\Attribute;
+use Pest\Factories\TestCaseMethodFactory;
 use Pest\TestSuite;
+use PHPUnit\Framework\Attributes\Test;
+use PHPUnit\Framework\Attributes\TestDox;
 use Tests\Support\Architecture\ArchBaseline;
 use Tests\Support\Architecture\ArchSurfaceScanner;
 use Tests\Support\Architecture\GlobalFunctionCallScanner;
@@ -125,6 +129,12 @@
  *         **ちょうど 1 件 (チェーン自身の `foreach`)** を要求することでだけ捕まる
  *   実測: (a) を注入すると本ファイルのテストが全滅し (41 → 0) テスト 38 が赤、
  *   (b) を注入しても同じく全滅し、テスト 38 が「最上位の制御構造が 2 件」で赤になる。
+ *   ★**登録は残したまま評価だけ止める形**もある: `test(…)` を閉じたあとに
+ *   `->skip()` / `->todo()` を後置すると、ヘッダーも表明も最上位の構造も 1 文字も変わらず、
+ *   description は登録されるので S4-3c の missing も空になる。この形は
+ *   **生成文の後置トークンを exact-fit で閉じる** (`EXPECTED_CHAIN_FOOTER_TOKENS`。
+ *   S4-3 と外部のテスト 38 の両方が照合する) ことと、S4-3c が
+ *   **新品の factory との差分比較**で実行修飾の不在を実行時に確かめることの 2 つで塞ぐ。
  *   **その外部検査自身が同じ手口で短絡された場合は検出しない** (検査を見張る検査は
  *   無限に続くので置かない)。最後の砦は git のレビューである。
  *
@@ -511,8 +521,16 @@ function archBaselineChainStartIndex(string $source): int
     $source = archBaselineReadSource($host);
 
     // 行番号ではなく有意トークンの添字を使う (同じ行に複数の呼び出しがあると一意にならない)。
-    expect(ArchSurfaceScanner::statementTokens($source, archBaselineChainStartIndex($source)))
-        ->toBe(ArchBaseline::EXPECTED_CHAIN_TOKENS);
+    $start = archBaselineChainStartIndex($source);
+
+    // ★**後置まで閉じる**。表明の文だけを pin すると `})->skip();` の後置で
+    //   「登録はされるが評価されない」状態を作れる (綴りも登録簿も一致したまま)。
+    $afterStatement = $start + count(ArchBaseline::EXPECTED_CHAIN_TOKENS);
+
+    expect(ArchSurfaceScanner::statementTokens($source, $start))
+        ->toBe(ArchBaseline::EXPECTED_CHAIN_TOKENS)
+        ->and(ArchSurfaceScanner::tokensAfter($source, $afterStatement, count(ArchBaseline::EXPECTED_CHAIN_FOOTER_TOKENS)))
+        ->toBe(ArchBaseline::EXPECTED_CHAIN_FOOTER_TOKENS);
 });
 
 test('S4-3b: 唯一のチェーンが波括弧の外側を持たない foreach + test の直下にある', function (): void {
@@ -539,7 +557,7 @@ function archBaselineChainStartIndex(string $source): int
         ->and(ArchSurfaceScanner::braceDepthAt($source, $start))->toBe(2);
 });
 
-test('S4-3c: 7 規則の禁止表明が実際に Pest へ登録されている', function (): void {
+test('S4-3c: 7 規則の禁止表明が実際に Pest へ登録され、実行修飾を 1 つも持たない', function (): void {
     // ★**これが「7 本が実際に効いている」ことの本体の保証**である。
     //   S4-3b (綴りと波括弧の深さ) は生成点が 1 つであることを固定するだけで、
     //   **到達可能性は証明しない** — 波括弧を持たない制御構文
@@ -569,10 +587,52 @@ function archBaselineChainStartIndex(string $source): int
         static fn (string $ruleId): bool => ! in_array(ArchBaseline::descriptionOf($ruleId), $descriptions, true),
     ));
 
+    // ★**「登録されている」だけでは足りない**。`test(…)` を閉じたあとに `->skip()` /
+    //   `->todo()` を後置すると、description は登録されたまま closure が実行されなくなる。
+    //   静的には後置トークンの exact-fit (S4-3 の `EXPECTED_CHAIN_FOOTER_TOKENS`) で塞いだが、
+    //   ここでは**実行時にも**「7 本の登録内容が生まれたままの状態か」を見る。
+    //   比較は**新品の factory との差分**で行う (deny-by-default)。修飾の名前を並べた
+    //   許可・拒否一覧を持たないので、Pest が修飾を増やしても勝手に効く。
+    //   `description` / `closure` / `filename` は 7 本ごとに違って当然なので比べない。
+    //   ★配列の比較は `==` (緩い) を使う。`chains` 等は毎回別インスタンスの
+    //   `HigherOrderMessageCollection` なので `===` では必ず不一致になるが、
+    //   `==` はクラスと **private を含む全プロパティ**を再帰比較するため、
+    //   「空のまま」かどうかを正しく判定できる。
+    //   ★`attributes` だけは新品と比べられない。Pest は description から
+    //   `#[Test]` と `#[TestDox(description)]` を必ず 2 個作るからである。
+    //   そこで**その 2 個ちょうど**を期待形として持つ (`->group()` / `->depends()` /
+    //   `->with()` / `->only()` はいずれもここへ追加するので、増えれば赤くなる)。
+    $pristine = new TestCaseMethodFactory(__FILE__, null);
+    $ignoredFields = ['description' => true, 'closure' => true, 'filename' => true, 'attributes' => true];
+    $pristineFields = array_diff_key(get_object_vars($pristine), $ignoredFields);
+
+    $modified = [];
+    foreach (ArchBaseline::ruleIds() as $ruleId) {
+        $description = ArchBaseline::descriptionOf($ruleId);
+        $method = $factory->methods[$description] ?? null;
+        if ($method === null) {
+            continue; // 未登録は $missing 側が報告する
+        }
+
+        $expectedAttributes = [
+            new Attribute(Test::class, []),
+            new Attribute(TestDox::class, [$description]),
+        ];
+
+        if (array_diff_key(get_object_vars($method), $ignoredFields) != $pristineFields) {
+            $modified[] = $ruleId.' (登録内容が新品と違う)';
+        }
+
+        if ($method->attributes != $expectedAttributes) {
+            $modified[] = $ruleId.' (属性が Test + TestDox の 2 個ではない)';
+        }
+    }
+
     // 空振り検査: 本テスト自身の description が取れていること
     // (取れないなら「7 本が無い」ではなく「問い合わせ方が壊れている」)。
-    expect($descriptions)->toContain('S4-3c: 7 規則の禁止表明が実際に Pest へ登録されている')
-        ->and($missing)->toBe([]);
+    expect($descriptions)->toContain('S4-3c: 7 規則の禁止表明が実際に Pest へ登録され、実行修飾を 1 つも持たない')
+        ->and($missing)->toBe([])
+        ->and($modified)->toBe([]);
 });
 
 test('S4-4: 動的メンバ参照が目録とファイル別件数まで exact-fit である', function (): void {
diff --git a/tests/Support/Architecture/ArchBaseline.php b/tests/Support/Architecture/ArchBaseline.php
index e01f151b..d5c7740d 100644
--- a/tests/Support/Architecture/ArchBaseline.php
+++ b/tests/Support/Architecture/ArchBaseline.php
@@ -281,6 +281,22 @@ private function __construct() {}
         '->', 'ignoring', '(', 'ArchBaseline', '::', 'exceptionsOf', '(', '$ruleId', ')', ')', ';',
     ];
 
+    /**
+     * S4 が照合する arch チェーンの**後置**の期待トークン列 (表明の文の直後 4 個)。
+     *
+     * 綴りは closure を閉じる `}` / `test(` を閉じる `)` / 文末の `;` / `foreach` を閉じる `}`。
+     *
+     * ★ヘッダーと表明の文だけを pin すると、**登録したあとに実行修飾を後置する**形
+     *   (`})->skip();` / `})->todo();`) が**どの検査にも現れない**。
+     *   ヘッダーも表明も 1 文字も変わらず、最上位の制御構造も打ち切りも増えず、
+     *   7 本の description は**登録されている**ので S4-3c の missing も空になる。
+     *   それでも closure は実行されず、禁止表明は 1 本も評価されない。
+     *   **後置を exact-fit で閉じる**ことが、この形を塞ぐ唯一の静的手段である。
+     *
+     * @var list<string>
+     */
+    public const array EXPECTED_CHAIN_FOOTER_TOKENS = ['}', ')', ';', '}'];
+
     /** @return list<string> */
     public static function ruleIds(): array
     {
diff --git a/tests/Support/Architecture/ArchSurfaceScanner.php b/tests/Support/Architecture/ArchSurfaceScanner.php
index 6634cacb..c910c416 100644
--- a/tests/Support/Architecture/ArchSurfaceScanner.php
+++ b/tests/Support/Architecture/ArchSurfaceScanner.php
@@ -199,6 +199,35 @@ public static function tokensBefore(string $source, int $index, int $length): ar
         return $texts;
     }
 
+    /**
+     * 指定位置から**後ろ** `$length` 個の有意トークンの綴り列を返す (チェーンの**後置**の照合用)。
+     *
+     * ★{@see self::tokensBefore()} と対になる。前 (囲みのヘッダー) と中 (表明の文) だけを
+     *   固定すると、**閉じたあとに実行修飾を後置する**形 (`})->skip();` / `})->todo();`) が
+     *   1 つも検査に現れない。7 本は**登録されたまま評価されなくなる**ので、
+     *   登録簿への問い合わせ (`ArchBaselineTest` の S4-3c) でも捕まらない。
+     *   後置の綴りまで exact-fit で固定して初めて、生成文が閉じていることを言える。
+     *
+     * ★範囲外は**黙って短い列を返さず例外**にする (fail-closed)。
+     *
+     * @return list<string>
+     */
+    public static function tokensAfter(string $source, int $index, int $length): array
+    {
+        $tokens = ArchTokenStream::significantTokens($source, self::class);
+
+        Assert::greaterThanEq($length, 0, "取り出す長さが負である: {$length}");
+        Assert::greaterThanEq($index, 0, "走査開始位置が範囲外である: {$index}");
+        Assert::lessThanEq($index + $length, count($tokens), "走査開始位置が範囲外である: {$index} + {$length}");
+
+        $texts = [];
+        for ($cursor = $index; $cursor < $index + $length; $cursor++) {
+            $texts[] = $tokens[$cursor]['text'];
+        }
+
+        return $texts;
+    }
+
     /**
      * 指定位置の時点で**開いたままの波括弧の深さ**を返す。
      *
@@ -288,6 +317,12 @@ public static function topLevelAbortSites(string $source): array
      *   数えない。波括弧つきで囲む形はここではなく**深さの検査**が受け持つ
      *   (囲まれた側の深さが 1 以上になる)。`goto` による飛び越しは
      *   {@see self::topLevelAbortSites()} 側の語彙にある。
+     * ★**arrow function (`fn () => …`) の式の中は区別しない**。波括弧を持たないので、
+     *   最上位に書かれた `fn () => match ($x) { … }` の `match` はここに 1 件として現れる。
+     *   **拾いすぎる側の誤差**であり (見逃しではない)、宿主ファイルの
+     *   「ちょうど 1 件」契約はこの形を持たないので実害がない。
+     *   同じことが {@see self::topLevelAbortSites()} の `throw` にも当てはまる
+     *   (`fn () => throw new …` は最上位の打ち切りとして数える)。
      *
      * @return list<array{name: string, line: int, index: int}>
      */
diff --git a/tests/Unit/Architecture/ArchBaselineScannerTest.php b/tests/Unit/Architecture/ArchBaselineScannerTest.php
index 9c633f0e..3eafbd8b 100644
--- a/tests/Unit/Architecture/ArchBaselineScannerTest.php
+++ b/tests/Unit/Architecture/ArchBaselineScannerTest.php
@@ -357,6 +357,43 @@ public function run(): void {}
         ->toThrow(InvalidArgumentException::class);
 });
 
+test('13g: tokensAfter が後置の実行修飾を見分け、範囲外は例外にする', function (): void {
+    // ★**綴りも登録簿も一致したまま 7 本を評価させない形**。
+    //   `test(…)` を閉じたあとに `->skip()` を後置すると、
+    //   ヘッダー・表明の文・最上位の制御構造・打ち切りのどれも 1 文字も変わらず、
+    //   description は Pest に登録される (= S4-3c の missing も空)。後置だけが違う。
+    $expected = archBaselineExpectedChainSource();
+    $skipped = str_replace('    });', '    })->skip();', $expected);
+
+    // 置換が実際に起きたこと (負例が負例になっていること) を先に確かめる
+    expect($skipped)->not->toBe($expected);
+
+    $footerLength = count(ArchBaseline::EXPECTED_CHAIN_FOOTER_TOKENS);
+    $afterOf = static fn (string $source): array => ArchSurfaceScanner::tokensAfter(
+        $source,
+        archBaselineChainIndex($source) + count(ArchBaseline::EXPECTED_CHAIN_TOKENS),
+        $footerLength,
+    );
+
+    // 表明の文とヘッダーは**両者で完全に一致する** = 後置を見ないと区別できない
+    $skippedIndex = archBaselineChainIndex($skipped);
+    $headerLength = count(ArchBaseline::EXPECTED_CHAIN_HEADER_TOKENS);
+
+    expect(ArchSurfaceScanner::statementTokens($skipped, $skippedIndex))
+        ->toBe(ArchBaseline::EXPECTED_CHAIN_TOKENS)
+        ->and(ArchSurfaceScanner::tokensBefore($skipped, $skippedIndex, $headerLength))
+        ->toBe(ArchBaseline::EXPECTED_CHAIN_HEADER_TOKENS)
+        // 後置だけが違う: 期待形は `} ) ; }` / 後置つきは `} ) -> skip`
+        ->and($afterOf($expected))->toBe(ArchBaseline::EXPECTED_CHAIN_FOOTER_TOKENS)
+        ->and($afterOf($skipped))->not->toBe(ArchBaseline::EXPECTED_CHAIN_FOOTER_TOKENS)
+        ->and($afterOf($skipped))->toBe(['}', ')', '->', 'skip'])
+        // 範囲外は黙って短い列を返さず例外にする (fail-closed)
+        ->and(fn (): array => ArchSurfaceScanner::tokensAfter($expected, 100_000, 1))
+        ->toThrow(InvalidArgumentException::class)
+        ->and(fn (): array => ArchSurfaceScanner::tokensAfter($expected, 0, 100_000))
+        ->toThrow(InvalidArgumentException::class);
+});
+
 test('13c: 波括弧つきの囲みは深さで見抜けるが、波括弧なしの制御構文は見抜けない', function (): void {
     // ★チェーンの綴りは 1 文字も変えずに 7 本の表明を無効化する形。
     //   綴り列の照合だけでは見抜けないので braceDepthAt が要る。
@@ -546,6 +583,10 @@ public function run(): int
     //     (b) 宣言を条件付きにして飛ばす形 (`if (false): … endif;` で丸ごと囲む)
     //         → 打ち切りトークンも波括弧も増えないので (a) にも深さの検査にも現れない。
     //           `topLevelControlStructureSites()` の**ちょうど 1 件**でだけ捕まる
+    //   さらに、**登録はされるが評価されない**形 (`})->skip();` の後置) も外から閉じる:
+    //     (c) 生成文の**後置**を `EXPECTED_CHAIN_FOOTER_TOKENS` と exact-fit で照合する。
+    //         この形はヘッダーも表明も最上位の構造も 1 文字も変えないので、
+    //         (a)(b) にも S4-3c の登録簿問い合わせにも現れない
     //
     // ★**保証しないもの**: 本ファイル自身が同じ手口で短絡された場合は検出できない
     //   (検査を外から見張る検査は無限に続くので置かない)。最後の砦は git のレビューである。
@@ -570,7 +611,12 @@ public function run(): int
         ->toBe(ArchBaseline::EXPECTED_CHAIN_TOKENS)
         ->and(ArchSurfaceScanner::tokensBefore($source, $start, $headerLength))
         ->toBe(ArchBaseline::EXPECTED_CHAIN_HEADER_TOKENS)
-        ->and(ArchSurfaceScanner::braceDepthAt($source, $start - $headerLength))->toBe(0);
+        ->and(ArchSurfaceScanner::braceDepthAt($source, $start - $headerLength))->toBe(0)
+        ->and(ArchSurfaceScanner::tokensAfter(
+            $source,
+            $start + count(ArchBaseline::EXPECTED_CHAIN_TOKENS),
+            count(ArchBaseline::EXPECTED_CHAIN_FOOTER_TOKENS),
+        ))->toBe(ArchBaseline::EXPECTED_CHAIN_FOOTER_TOKENS);
 });
 
 // ---------------------------------------------------------------------------
```

---

# 修正後の実測 (すべて再取得した)

- `composer test -- --filter=ArchBaseline`: **83 tests / 83 passed / 207 assertions**
- `composer test` (全数): **6767 tests / 6765 passed / 2 skipped / 5 risky / 0 failed** (終了コード 0)
- `vendor/bin/phpstan analyse --level=10 tests/Support/Architecture tests/Architecture/ArchBaselineTest.php tests/Unit/Architecture/ArchBaselineScannerTest.php`: **0 errors**
- `composer phpstan`: **No errors**
- `vendor/bin/pint --test`: **passed**
- 抑止コメント・baseline 追加・型の widen・設定の緩和は**いずれも使っていない**。

## 無力化の注入 6 形の実測 (すべて `composer test -- --filter=ArchBaseline`)

| 注入 | 赤くなる検査 |
|---|---|
| `if (false) { foreach … }` (波括弧つき) | S4-3b (深さ 1) と S4-3c |
| `if (false)` + 改行 + `foreach …` (波括弧なし) | S4-3c と テスト 38 (最上位の制御構造 2 件) |
| 最上位の `return;` | テスト 38 (`topLevelAbortSites` が 1 件返す。42 tests / 1 failed) |
| ファイル全体を `if (false): … endif;` | テスト 38 (最上位の制御構造 2 件。42 tests / 1 failed) |
| `})->skip();` | **テスト 38 / S4-3 / S4-3c の 3 本同時** |
| `})->todo();` | **テスト 38 / S4-3 / S4-3c の 3 本同時** |
| `})->group('x');` | **テスト 38 / S4-3 / S4-3c の 3 本同時** |

`->group('x')` は**属性しか変えない**ので、S4-3c が赤くなることが
「属性の期待形 2 個」の分岐が現に効いている証拠である
(`->skip()` は `chains` を、`->todo()` は `todo` を変えるので、3 形で
S4-3c の 2 つの分岐と factory 差分の両方に火が入っている)。

# 判定してほしい点

1. Round 4 の [Critical] 2 件 / [Warning] / [Suggestion] は解消したか。
2. 後置を `EXPECTED_CHAIN_FOOTER_TOKENS` の 4 トークンで exact-fit する契約は、
   狙った欠陥クラス (登録されるが評価されない形) に対して過不足がないか。
3. S4-3c の「新品 factory との `==` 差分 + 属性 2 個の期待形」という実行時層は、
   deny-by-default として妥当か。`attributes` を差分比較から外して別扱いにした判断も含めて。
4. まだ残っている経路があるなら、**具体的な注入形**を示せ
   (こちらで注入して実測し、赤くならなければ塞ぐ)。
