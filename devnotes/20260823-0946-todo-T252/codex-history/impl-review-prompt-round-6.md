Round 5 の指摘に対する対応を報告し、あわせて**本 gate の保証境界そのものの妥当性**を
判定に付す。Round 6 である。

出力形式はこれまでと同じ (ファイルごとの判定 / [Critical] [Warning] [Suggestion] 分類 /
最後に `APPROVED` または `CHANGES_REQUESTED` の 1 語)。

対象コードは `/workspace/.claude/worktrees/tasks/T252/` 配下にある (読み込み可)。
Round 5 以降の変更は本書の差分がすべてである。

本ラウンドで判定してほしいのは**次の 2 点**であり、両方に答えてほしい。

- (1) 後述する**保証境界の設定そのものが妥当か** (境界の引き方が正しいか。
  「ここから先は機械で塞がず git のレビューに委ねる」という線の位置が適切か)。
  **この境界設定は監督者が下したものだが、判定の対象であって前提ではない**。
  不適切だと考えるなら、そう指摘してよい。
- (2) **境界の内側に未修正の欠陥があるか**。

なお本書の §3 は**測定手法の訂正**、§4 は**こちらが自分で見つけて報告する未閉塞の経路**である。
どちらもこちらに不利になり得る材料だが、隠さずに出す。

---

# §1. 対応マトリクス: impl-review Round 5

Round 5 の全体判定は `CHANGES_REQUESTED`。指摘は [Critical] 2 件 + [Warning] 2 件。
**すべて対応した。反論は 1 件も無い。**

## [Critical] ファイル単位の `beforeEach` で全テストを skip する経路が残る

- 判断: **対応する** (指摘は正しい。実際に無力化できた)
- 対応内容: 「**宿主ファイルの最上位は、テストを宣言する以外のことをしない**」という契約を
  外から固定した。
  1. `ArchSurfaceScanner::topLevelCallNames()` を新設。波括弧の深さ 0 で呼ばれている
     **素の関数名**を重複なし・昇順で返す純関数。深さの計算は既存の `braceDepths()` を、
     呼び出し判定は既存の `calledNameAt()` を共有する (数え方の写しを増やさない)。
     `A::b()` / `$x->b()` は `calledNameAt()` が直前トークンで除外する。
  2. `ArchBaseline::EXPECTED_TOP_LEVEL_CALL_NAMES = ['test']` を新設。
  3. **テスト 38** (別ファイルの外部自己検査) が両者を exact-fit で照合する。
     **禁止する名前の一覧を持たない deny-by-default** なので、`beforeEach` だけでなく
     `uses()` / `pest()` / `describe()` / `covers()` など、ファイル単位で挙動を変える入口が
     **まとめて 1 つの契約で閉じる** (Pest が入口を増やしても効く)。
  4. 宿主ファイルの docblock と S4-3c のコメントに、**S4-3c が保証するのは個々の factory の
     状態だけで、factory の外から実行を止める形はテスト 38 の担当**であることを明記した。

## [Critical] file-scoped `beforeEach` の負例が走査器テストに無い

- 判断: **対応する**
- 対応内容: 負例 **13h** を追加した。合成した期待形チェーンへ `beforeEach(…)` を注入し、
  最上位の呼び出しが `[]` から `['beforeeach']` へ変わる (= これが唯一の差である) ことを固定。
  静的メソッド呼び出し・メソッド呼び出し・**クロージャ本体の中の呼び出し**は
  1 件も数えないことも固定した。

## [Warning] D43 の「実行可能な状態」が file-scoped hook を含んでいない

- 判断: **対応する**
- 対応内容: D43 の保証機構の記述へ「宿主ファイルの最上位の呼び出しを test だけに限って
  (factory の外から実行を止めるファイル単位の hook・uses 等の入口を deny-by-default で閉じる)」
  を追記した。実装 (テスト 38) と記述が一致した。

## [Warning] `get_object_vars()` は公開プロパティしか見えないので主張が広すぎる

- 判断: **対応する** (指摘のとおり。主張を実態へ狭めた)
- 対応内容: S4-3c のコメントを「Pest が**公開プロパティに載る**修飾を増やしても勝手に効く」へ直し、
  **保証しないもの**として「vendor が将来**非公開**の修飾状態を足したら本条は見ない
  (見るなら Reflection か `(array)` キャストが要る)。現行の Pest は修飾状態を公開プロパティと
  公開コレクションに置いているのでこの差は出ない」を明記した。
  Reflection / `(array)` キャストへの切り替えは**行っていない** — 現行 vendor では観測できる差が無く、
  今必要でないものを作らない (AGENTS.md 思考原則 2)。vendor 更新で修飾が非公開化されたら読み直す。

---

# §2. 修正差分 (Round 5 レビュー時点 → 現在)

```diff
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 92e0b7bf..12222edf 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -2527,7 +2527,7 @@ ## D43 Pest arch のベースラインを、正典の 9 規則ではなく本ア
 |---|---|
 | 対象パス | `tests/Architecture/ArchBaselineTest.php` / `tests/Support/Architecture/ArchBaseline.php` / `tests/Support/Architecture/ArchSurfaceScanner.php` / `tests/Support/Architecture/ArchTokenStream.php` / `tests/Support/Architecture/GlobalFunctionCallScanner.php` / `tests/Support/Architecture/VendorArchPresetReader.php` / `tests/Unit/Architecture/ArchBaselineScannerTest.php` |
 | 業務要件起因の説明 | 家系の正典 v1 は禁止シンボルを規則ごとに分解して例外の波及半径を 1 シンボルに閉じることを求めるが、正典の 9 規則 102 シンボルという分解はテンプレート側の例外クラス構成から出た数である。本アプリの走査域 (App と Database\Factories と Database\Seeders) で禁止語彙を実使用しているのは sha1 と tempnam と var_export の 3 語彙 5 クラスだけであり、母集団に対する正しい分解は例外なし 4 束 + 単独シンボル 3 本の 7 規則になる。正典の本数をそのまま写すと実体の無い規則が生まれる |
-| 揃え続ける不変条件と保証機構 | 例外を持つ規則の対象シンボルがちょうど 1 個であること (`ArchBaselineTest` の S3) / 7 規則の語彙の和集合が vendor preset の禁止語彙集合と一致すること (S5。移植漏れと vendor 更新の両方を検出) / 例外の置き場が `ArchBaseline` 1 クラスに限られ禁止表明を作るチェーンが 1 本であること (S4 が tests 配下の追跡 PHP 全数を母集団に完全一致で照合し、7 本が実際に Pest へ**実行可能な状態で**登録されたことまで実行時に確かめ (登録の有無に加えて、新品の factory との差分比較で skip / todo 等の実行修飾が 1 つも付いていないことを見る)、生成文の後置トークンまで exact-fit で閉じ、宿主ファイルが最上位の短絡 — 実行を打ち切る return 等と、宣言を条件付きにする最上位の制御構造で丸ごと囲む形の 2 つ — を受けていないことは別ファイルの外部自己検査が見張る) |
+| 揃え続ける不変条件と保証機構 | 例外を持つ規則の対象シンボルがちょうど 1 個であること (`ArchBaselineTest` の S3) / 7 規則の語彙の和集合が vendor preset の禁止語彙集合と一致すること (S5。移植漏れと vendor 更新の両方を検出) / 例外の置き場が `ArchBaseline` 1 クラスに限られ禁止表明を作るチェーンが 1 本であること (S4 が tests 配下の追跡 PHP 全数を母集団に完全一致で照合し、7 本が実際に Pest へ**実行可能な状態で**登録されたことまで実行時に確かめ (登録の有無に加えて、新品の factory との差分比較で skip / todo 等の実行修飾が 1 つも付いていないことを見る)、生成文の後置トークンまで exact-fit で閉じ、宿主ファイルの最上位の呼び出しを test だけに限って (factory の外から実行を止めるファイル単位の hook・uses 等の入口を deny-by-default で閉じる)、宿主ファイルが最上位の短絡 — 実行を打ち切る return 等と、宣言を条件付きにする最上位の制御構造で丸ごと囲む形の 2 つ — を受けていないことは別ファイルの外部自己検査が見張る) |
 | 再判定の条件 | 正典が per-rule 分解の規約そのものを変えたとき / Pest の preset 構成が変わり集合一致が取れなくなったとき / 本アプリで層分離規則 (toOnlyBeUsedIn 等) を導入するとき |
 | 決めた日 | 2026-08-23 |
 | 決めた人 | 開発者 |
diff --git a/tests/Architecture/ArchBaselineTest.php b/tests/Architecture/ArchBaselineTest.php
index 85e2a6d2..b7f7b9a1 100644
--- a/tests/Architecture/ArchBaselineTest.php
+++ b/tests/Architecture/ArchBaselineTest.php
@@ -135,6 +135,15 @@
  *   **生成文の後置トークンを exact-fit で閉じる** (`EXPECTED_CHAIN_FOOTER_TOKENS`。
  *   S4-3 と外部のテスト 38 の両方が照合する) ことと、S4-3c が
  *   **新品の factory との差分比較**で実行修飾の不在を実行時に確かめることの 2 つで塞ぐ。
+ *   ★**ファイル単位で実行を止める形**もある: 最上位に
+ *   `beforeEach(fn () => $this->markTestSkipped())` を 1 つ置くと、
+ *   **本ファイルの全テストが登録も生成もされたまま skip される**
+ *   (実測: 41 本が skip され、**失敗 0 件**になった = Pest の出力に赤が 1 つも出ない)。
+ *   この形は綴り・波括弧の深さ・後置・各テストの登録内容の
+ *   **どれ 1 つも変えない**ので、上記のどの層にも現れない。
+ *   外部自己検査 (テスト 38) の「**最上位の呼び出しは `test` だけ**」
+ *   (`EXPECTED_TOP_LEVEL_CALL_NAMES`) がこれを閉じる。`uses()` / `pest()` / `describe()` など
+ *   他のファイル単位の入口も同じ 1 つの契約に含まれる (禁止名の一覧を持たない)。
  *   **その外部検査自身が同じ手口で短絡された場合は検出しない** (検査を見張る検査は
  *   無限に続くので置かない)。最後の砦は git のレビューである。
  *
@@ -592,12 +601,20 @@ function archBaselineChainStartIndex(string $source): int
     //   静的には後置トークンの exact-fit (S4-3 の `EXPECTED_CHAIN_FOOTER_TOKENS`) で塞いだが、
     //   ここでは**実行時にも**「7 本の登録内容が生まれたままの状態か」を見る。
     //   比較は**新品の factory との差分**で行う (deny-by-default)。修飾の名前を並べた
-    //   許可・拒否一覧を持たないので、Pest が修飾を増やしても勝手に効く。
+    //   許可・拒否一覧を持たないので、Pest が**公開プロパティに載る**修飾を増やしても勝手に効く。
+    //   ★**保証しないもの**: `get_object_vars()` が呼び出し位置から見えるのは**公開プロパティだけ**
+    //   である。vendor が将来**非公開**の修飾状態を足したら、本条はそれを見ない
+    //   (見るなら Reflection か `(array)` キャストが要る)。現行の Pest は
+    //   修飾状態を公開プロパティと公開コレクションに置いているのでこの差は出ない。
     //   `description` / `closure` / `filename` は 7 本ごとに違って当然なので比べない。
     //   ★配列の比較は `==` (緩い) を使う。`chains` 等は毎回別インスタンスの
     //   `HigherOrderMessageCollection` なので `===` では必ず不一致になるが、
     //   `==` はクラスと **private を含む全プロパティ**を再帰比較するため、
     //   「空のまま」かどうかを正しく判定できる。
+    //   ★**本条が保証するのは個々の `TestCaseMethodFactory` の状態だけ**である。
+    //   ファイル単位の hook (`beforeEach`) や `uses()` のように**factory の外から**
+    //   実行を止める形はここに現れない (登録内容は新品のままになる)。
+    //   そちらは外部自己検査 (テスト 38) の「最上位の呼び出しは `test` だけ」が閉じる。
     //   ★`attributes` だけは新品と比べられない。Pest は description から
     //   `#[Test]` と `#[TestDox(description)]` を必ず 2 個作るからである。
     //   そこで**その 2 個ちょうど**を期待形として持つ (`->group()` / `->depends()` /
diff --git a/tests/Support/Architecture/ArchBaseline.php b/tests/Support/Architecture/ArchBaseline.php
index d5c7740d..c125da94 100644
--- a/tests/Support/Architecture/ArchBaseline.php
+++ b/tests/Support/Architecture/ArchBaseline.php
@@ -297,6 +297,21 @@ private function __construct() {}
      */
     public const array EXPECTED_CHAIN_FOOTER_TOKENS = ['}', ')', ';', '}'];
 
+    /**
+     * 宿主ファイルの**最上位で呼んでよい関数名**の全体 (重複なし・昇順)。
+     *
+     * ★宿主ファイルの最上位は**テストを宣言する以外のことをしない**。
+     *   Pest には宣言を残したまま実行だけ止めるファイル単位の入口があり
+     *   (`beforeEach(fn () => $this->markTestSkipped())` を 1 つ置くと
+     *   **そのファイルの全テストが skip される**)、この形は綴りにも波括弧の深さにも
+     *   登録内容にも現れない。**最上位の呼び出しを `test` だけに限る**契約だけが捕まえる。
+     *   `uses()` / `pest()` / `describe()` / `covers()` のような他の入口も同じ 1 つの契約で閉じる
+     *   (禁止する名前の一覧を持たない = deny-by-default)。
+     *
+     * @var list<string>
+     */
+    public const array EXPECTED_TOP_LEVEL_CALL_NAMES = ['test'];
+
     /** @return list<string> */
     public static function ruleIds(): array
     {
diff --git a/tests/Support/Architecture/ArchSurfaceScanner.php b/tests/Support/Architecture/ArchSurfaceScanner.php
index c910c416..4c3b75e6 100644
--- a/tests/Support/Architecture/ArchSurfaceScanner.php
+++ b/tests/Support/Architecture/ArchSurfaceScanner.php
@@ -347,6 +347,58 @@ public static function topLevelControlStructureSites(string $source): array
         return $sites;
     }
 
+    /**
+     * **ファイル最上位 (波括弧の深さ 0) で呼ばれている関数名**の重複なしの一覧 (小文字・昇順)。
+     *
+     * ★これは {@see self::topLevelAbortSites()} / {@see self::topLevelControlStructureSites()} に
+     *   続く**3 つ目の短絡経路**を塞ぐための走査である。前 2 つは「宣言そのものを消す」形を見るが、
+     *   Pest には**宣言を残したまま実行だけ止める**ファイル単位の仕掛けがある —
+     *   最上位に `beforeEach(fn () => $this->markTestSkipped())` を 1 つ置くと、
+     *   **そのファイルの全テストが登録も生成もされたまま skip される**。
+     *   このとき綴り (ヘッダー・表明・後置)・波括弧の深さ・打ち切り・最上位の制御構造・
+     *   各テストの登録内容 (`TestCaseMethodFactory` の状態) の**どれ 1 つも変わらない**。
+     *   捕まえられるのは「宿主ファイルの最上位が**テストの宣言以外の呼び出しを持たない**」
+     *   という契約だけである。`uses()` / `pest()` / `describe()` / `covers()` など、
+     *   ファイル単位で挙動を変える入口はすべてこの 1 つの契約に含まれる (deny-by-default。
+     *   禁止する名前の一覧を持たないので、Pest が入口を増やしても勝手に効く)。
+     *
+     * ★**数えるのは素の関数呼び出しだけ**である。`A::b()` と `$x->b()` は
+     *   {@see self::calledNameAt()} が直前トークンで除外する (メンバ呼び出しは
+     *   ファイル単位の仕掛けにならない)。`foreach (ArchBaseline::ruleIds() as …)` が
+     *   最上位にあっても数に入らないのはこのためである。
+     *
+     * ★**保証しないもの**: 名前が静的に決まらない呼び出し (可変関数・反射) は数えない
+     *   (走査器全体の共通の限界。クラス docblock の「保証しないもの」を参照)。
+     *   arrow function (`fn () => beforeEach(…)`) の式の中は波括弧を持たないので
+     *   **最上位として数える** — 拾いすぎる側の誤差である。
+     *
+     * @return list<string>
+     */
+    public static function topLevelCallNames(string $source): array
+    {
+        $tokens = ArchTokenStream::significantTokens($source, self::class);
+        $depths = self::braceDepths($tokens);
+
+        $names = [];
+        for ($index = 0, $total = count($tokens); $index < $total; $index++) {
+            if ($depths[$index] !== 0) {
+                continue;
+            }
+
+            $name = self::calledNameAt($tokens, $index);
+            if ($name === null) {
+                continue;
+            }
+
+            $names[$name] = true;
+        }
+
+        $unique = array_keys($names);
+        sort($unique);
+
+        return $unique;
+    }
+
     /**
      * 各トークン位置の**直前までに開いたままの波括弧の深さ**の表 (要素数はトークン数 + 1)。
      *
diff --git a/tests/Unit/Architecture/ArchBaselineScannerTest.php b/tests/Unit/Architecture/ArchBaselineScannerTest.php
index 3eafbd8b..00f13d71 100644
--- a/tests/Unit/Architecture/ArchBaselineScannerTest.php
+++ b/tests/Unit/Architecture/ArchBaselineScannerTest.php
@@ -568,6 +568,44 @@ public function run(): int
         ->and($names($inBodies))->toBe([]);
 });
 
+test('13h: 最上位の関数呼び出しを拾い、メンバ呼び出しと本体の中は拾わない', function (): void {
+    // ★**宣言を残したまま実行だけ止める形**。ファイル単位の `beforeEach` を 1 つ置くと
+    //   そのファイルの全テストが skip されるが、綴りも波括弧の深さも登録内容も変わらない。
+    //   最上位の呼び出しの一覧だけがここで変わる。
+    $expected = archBaselineExpectedChainSource();
+    $hooked = str_replace(
+        'foreach (',
+        "beforeEach(function (): void {\n    \$this->markTestSkipped('probe');\n});\n\nforeach (",
+        $expected,
+    );
+
+    // 置換が実際に起きたこと (負例が負例になっていること) を先に確かめる
+    expect($hooked)->not->toBe($expected);
+
+    $memberCallsOnly = <<<'PHP'
+        <?php
+
+        use Tests\Support\Architecture\ArchBaseline;
+
+        // 静的メソッド呼び出し・メソッド呼び出しはファイル単位の仕掛けにならないので数えない
+        $ids = ArchBaseline::ruleIds();
+        $first = $collection->first();
+
+        test('x', function (): void {
+            // 本体の中の素の関数呼び出しも数えない
+            sprintf('%s', 'a');
+            beforeEach(function (): void {});
+        });
+        PHP;
+
+    // 合成した期待形は `test(` が `foreach` の**中** (深さ 1) にあるので最上位の呼び出しは 0 件。
+    // hook を足すと最上位に 1 件現れる — これが唯一の差である。
+    expect(ArchSurfaceScanner::topLevelCallNames($expected))->toBe([])
+        ->and(ArchSurfaceScanner::topLevelCallNames($hooked))->toBe(['beforeeach'])
+        // 静的メソッド呼び出し・メソッド呼び出し・本体の中の呼び出しは数えない
+        ->and(ArchSurfaceScanner::topLevelCallNames($memberCallsOnly))->toBe(['test']);
+});
+
 // ---------------------------------------------------------------------------
 // チェーン宿主ファイルの**外部**自己検査
 // ---------------------------------------------------------------------------
@@ -583,10 +621,15 @@ public function run(): int
     //     (b) 宣言を条件付きにして飛ばす形 (`if (false): … endif;` で丸ごと囲む)
     //         → 打ち切りトークンも波括弧も増えないので (a) にも深さの検査にも現れない。
     //           `topLevelControlStructureSites()` の**ちょうど 1 件**でだけ捕まる
-    //   さらに、**登録はされるが評価されない**形 (`})->skip();` の後置) も外から閉じる:
-    //     (c) 生成文の**後置**を `EXPECTED_CHAIN_FOOTER_TOKENS` と exact-fit で照合する。
+    //   さらに、**登録はされるが評価されない**形も 2 つあり、外から閉じる:
+    //     (c) 生成文の**後置** (`})->skip();`) → `EXPECTED_CHAIN_FOOTER_TOKENS` と exact-fit。
     //         この形はヘッダーも表明も最上位の構造も 1 文字も変えないので、
     //         (a)(b) にも S4-3c の登録簿問い合わせにも現れない
+    //     (d) **ファイル単位の hook** (`beforeEach(fn () => $this->markTestSkipped())`) →
+    //         最上位の呼び出しを `EXPECTED_TOP_LEVEL_CALL_NAMES` (= `test` だけ) に限る。
+    //         この形は (a)(b)(c) のどれにも現れず、各テストの**登録内容も新品のまま**なので
+    //         S4-3c の factory 差分にも出ない
+    //         (実測: 41 本が skip され、**失敗 0 件** = Pest の出力に赤が 1 つも出ない)
     //
     // ★**保証しないもの**: 本ファイル自身が同じ手口で短絡された場合は検出できない
     //   (検査を外から見張る検査は無限に続くので置かない)。最後の砦は git のレビューである。
@@ -616,7 +659,12 @@ public function run(): int
             $source,
             $start + count(ArchBaseline::EXPECTED_CHAIN_TOKENS),
             count(ArchBaseline::EXPECTED_CHAIN_FOOTER_TOKENS),
-        ))->toBe(ArchBaseline::EXPECTED_CHAIN_FOOTER_TOKENS);
+        ))->toBe(ArchBaseline::EXPECTED_CHAIN_FOOTER_TOKENS)
+        // 宿主ファイルの最上位は**テストを宣言する以外のことをしない** (deny-by-default)。
+        // ファイル単位の hook / 設定の入口 (`beforeEach` / `uses` / `pest` / `describe` …) は
+        // すべてこの 1 件の契約で閉じる。
+        ->and(ArchSurfaceScanner::topLevelCallNames($source))
+        ->toBe(ArchBaseline::EXPECTED_TOP_LEVEL_CALL_NAMES);
 });
 
 // ---------------------------------------------------------------------------
```

## 注入実測 (本ラウンドで再測定した。`bash scripts/run-test.sh --filter=ArchBaseline`)

宿主ファイルの禁止表明の直前へ

```php
beforeEach(function (): void {
    $this->markTestSkipped('probe');
});
```

を注入すると、**テスト 38 が赤**になる。実際の失敗出力は次のとおり:

```
Failed asserting that two arrays are identical.
--- Expected
+++ Actual
@@ @@
 Array &0 [
-    0 => 'test',
+    0 => 'beforeeach',
+    1 => 'test',
 ]
```

無力化の既知 8 形はすべて塞ぎ、実測済みである:

| 注入 | 赤くなる検査 |
|---|---|
| `if (false) { foreach … }` (波括弧つき) | S4-3b (深さ 1) と S4-3c |
| `if (false)` + 改行 + `foreach …` (波括弧なし) | S4-3c と テスト 38 |
| 最上位の `return;` | テスト 38 (打ち切り 1 件) |
| ファイル全体を `if (false): … endif;` | テスト 38 (最上位の制御構造 2 件) |
| `})->skip();` | テスト 38 / S4-3 / S4-3c |
| `})->todo();` | テスト 38 / S4-3 / S4-3c |
| `})->group('x');` | テスト 38 / S4-3 / S4-3c |
| 最上位の `beforeEach(… markTestSkipped …)` | テスト 38 (最上位の呼び出しが `test` だけでない) |

品質チェック: `composer test` 6768/6768 有効・PHPStan level 10 は 0 エラー・Pint passed。
抑止・baseline・型 widen は 1 件も無い。

---

# §3. 測定手法の訂正 (「全緑」の判定に終了コードを使ってはいけない)

Round 3〜5 でこちらは、skip 系の注入について「**全緑になった**」と書いてきた。
本ラウンドでその主張を終了コードまで含めて検証し直したので、方法と結論を報告する。

**結論から言うと、主張の実質は維持される** (skip 系の注入は suite を赤くしない)。
ただし**終了コードを根拠に使うことはできない**ことが分かった。以下が実測である。

このリポジトリの唯一のテスト入口は `composer test` → `bash scripts/run-test.sh` →
`php artisan test --parallel --processes=4` である。この経路の終了コードは、
**`--filter` を付けた実行では信用できない**。

| 実行 | 結果 | 終了コード |
|---|---|---|
| `--filter=ArchBaseline` (無注入) | 84 passed / 0 skipped | **0** |
| `--filter=ArchBaseline` (レーン hook 注入) | 43 passed / 41 skipped / 失敗 0 | **1** |
| `--filter=ArchBaselineScannerTest` (無注入) | 43 passed / **0 skipped** / 失敗 0 | **1** |
| 使い捨ての 2 本だけのテスト (`--filter`、**skip 無し**) | 2 passed / 失敗 0 | **1** |
| **フィルタ無しの全実行** | 6768 tests / 6766 passed / **2 skipped** / 失敗 0 | **0** |

3 行目と 4 行目が決定的である。**skip が 1 件も無くても `--filter` 実行は 1 を返す**。
つまり終了コード 1 は skip とは無関係で、`--filter` で 0 件になった testsuite が出ることに
由来する paratest 層の副作用と見られる (`phpunit.xml` に `failOnSkipped` の記述は無い)。
そして 5 行目のとおり、**正当に skip されている 2 本を含む全実行は 0 を返す**。

したがって:

- **「skip されると suite が赤くなる」わけではない。** skip 系の無力化は、
  終了コードでは露見しない。静的 gate とテスト 38 が要るという Round 3〜5 の前提は正しい。
- 一方で、こちらが過去ラウンドで根拠に使っていた `--filter` 実行の**緑/赤の読み**は、
  `laravel/pao` の JSON (`{"result":"passed", … ,"skipped":41}`) を見ていたものであり、
  終了コードとは食い違う。以後、注入実測の判定は
  **「失敗が 0 件か」「意図した検査が赤くなったか」**で述べる (終了コードでは述べない)。

コード中の「全緑になった」という記述 2 箇所は、
「**失敗 0 件になった (Pest の出力に赤が 1 つも出ない)**」へ訂正した (差分に含まれる) —
`--filter` の終了コードに依存しない、正確な言い方に直す趣旨である。

**判定を仰ぎたい点**: この「`--filter` 実行の終了コードが当てにならない」という性質は
本変更の対象外だが、**将来この gate の注入実測を再現する人が同じ罠にはまる**。
D43 か docblock に注記として残すべきか、それとも本変更の範囲外として何も書かないべきかを
判断してほしい。こちらの現時点の考えは「本変更には書かない」である
(gate の契約とは無関係な runner の性質であり、ここに書くと乖離台帳の主題がぼやける)。

---

# §4. こちらから報告する未閉塞の経路 (境界の外側にあると考えるもの)

本ラウンドで、宿主ファイルを 1 文字も触らずに gate を黙らせる経路を 1 つ自分で見つけた。
**報告する。**

`tests/Pest.php` は Pest のレーン設定ファイルで、次の形で `Architecture` レーン全体に
`beforeEach` を適用している。

```php
pest()->extend(TestCase::class)
    ->beforeEach(function (): void {
        $this->withoutVite();
        StrayHttpRequestGuard::install($this->app);
        PlainDataCacheGuard::assertInstalled($this->app);
    })
    // …
    ->in('Architecture');
```

この `beforeEach` の先頭へ `$this->markTestSkipped('probe');` を 1 行入れると、次が起きる。

- `tests/Architecture/ArchBaselineTest.php` の禁止表明 7 本と S1〜S5 が**全部 skip される**
- **テスト 38 は赤くならない**。テスト 38 は `tests/Unit/Architecture/` にあって
  `Unit` レーンに属するので走り続けるうえ、その中身は**宿主ファイルのソースの静的検査**であり、
  宿主ファイルは 1 文字も変わっていないためである
**実測 (フィルタ無しの全実行。§3 のとおり `--filter` の終了コードは当てにならないので全実行で測った)**:

```
Tests:    1405 skipped, 5363 passed (19497 assertions)
Duration: 481.34s
→ EXIT=0
```

対照 (無注入の全実行): 6766 passed / 2 skipped / **EXIT=0**。

つまり **1405 本が黙って skip されたまま、suite 全体が緑 (終了コード 0) になる**。
失敗は 1 件も出ない。**これは本ラウンドで塞いだ 8 形のどれよりも影響が大きい**
(gate の 7 本だけでなく `Architecture` レーン全部 — AGENTS.md が挙げるセキュリティ不変条件の
Architecture テスト群を含む — が一度に黙る)。**隠さずに報告する。**

これは「gate の宿主ファイル自体を編集する」形ではなく、**別ファイル (レーン設定) を編集する**形である。
Round 3 以降の系列とは経路が違うので、こちらの判断だけで「境界の外」と決めずに提示する。
**この経路を境界の内と外のどちらに置くべきかの判定を求める。**

こちらの現時点の考えは「外」である。論拠は 2 つ:

1. `tests/Pest.php` を編集できる者は**リポジトリのあらゆるテストを同じ 1 行で黙らせられる**。
   影響範囲が本 gate に限られないことは、まさに「これは本 gate 固有の欠陥ではない」ことを示す。
   本 gate だけがこの一般問題を自前で解こうとすると、レーン設定・`phpunit.xml`・`composer.json`・
   `scripts/run-test.sh` と、見張る対象が際限なく増える (そして最後は §5 の無限後退に戻る)。
2. T252 の主題は「vendor preset 一括採用による ignoring の波及半径を規則ごとに閉じる」ことであり、
   「テスト基盤ファイルの改竄検出」ではない。ここで基盤全体の防御を始めるのは主題の取り違えである。

ただしこれは判定に付す論拠であって結論ではない。とくに、
**この経路が T252 の範囲外だとしても、リポジトリの課題として別 TODO を立てるべきか**については
意見がほしい (こちらは「立てる価値はある」と考えているが、本ラウンドの判定とは分けたい)。

---

# §5. 保証境界 (監督者の裁定。**これ自体を判定の対象とする**)

Round 3 以降の指摘は、毎回「gate の宿主ファイル自体を編集して gate を無力化する」
攻撃者モデルの**新しい変種**だった (`return;` → `->skip()` → file-scoped `beforeEach`)。
これを受けて監督者が次の境界を定めた。

**機械 (gate) で塞ぐ範囲**:

- 誤編集・事故的な緩み (規則を足したのにチェーンから外れた、例外が増えた、語彙が vendor とずれた 等)
- **既知の無力化 8 形** (§2 の表。すべて注入実測で塞ぎを確認済み)
- vendor 更新による前提の崩れ (preset の語彙集合の変化、factory の公開状態の変化)

**機械で塞がず git のレビューに委ねる範囲**:

- **宿主ファイルを意図的に編集できる攻撃者に対する完全な自己防御**。
  具体的には、外部自己検査 (`tests/Unit/Architecture/ArchBaselineScannerTest.php`) 自身が
  同じ手口で短絡された場合を、本変更は検出しない。

この線を引いた根拠は 3 つ:

1. **無限後退する**。検査 A を見張る検査 B を置けば、B を見張る C が要る。
   どこかで打ち切らねばならず、打ち切った先は人間のレビューになる。
2. **家系の正典 v1 もこの自己検査の無限後退を要求していない**。
3. **git のレビューが最後の砦として実在する** (このリポジトリの変更は全て差分レビューを通る)。

この境界は**設計・D43・宿主ファイルの docblock・テスト 38 のコメントに明文化済み**であり、
「保証しないもの」として読み手に見える形になっている (隠していない)。

---

# §6. 求める判定

1. **§5 の境界設定は妥当か。** 線の位置は適切か。
   不適切なら、どこに引き直すべきか (そしてそれは有限の手数で実装できるか) を示してほしい。
2. **境界の内側に未修正の欠陥があるか。**
   ある場合は [Critical] / [Warning] / [Suggestion] のいずれかで示してほしい。
3. **§3 の測定手法の注記を本変更に残すべきか否か。**
4. **§4 のレーン設定経由の経路は、境界の内と外のどちらか。**

境界の外側に属する新変種 (宿主ファイル編集モデルの別の形) を見つけた場合は、
**それが外側であることを明示したうえで**挙げてほしい — 境界の内外の切り分けが
判定の主眼であり、外側の変種の存在自体は §5 が既に受容しているものだからである。
逆に「これは外側に見えるが実は有限の手数で閉じられる」というものがあれば、
それは境界の引き直しを要する指摘なので (1) として扱ってほしい。
