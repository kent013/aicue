Round 3 の指摘に対する対応を報告する。以下の対応マトリクスと修正差分を確認し、再判定せよ。

**ラウンド上限について**: Round 3 は当初の上限 (3 ラウンド) だったが、**監督者の裁量で上限を
Round 5 まで +2 延長した**。したがって本ラウンド (Round 4) は最終ではない。
Round 3 の指摘 ([Critical] 1 件 + [Warning] 1 件) は**両方とも対応済み**であり、
加えて**こちらの自主監査で同じ欠陥クラスの残穴を 1 つ自分で見つけて塞いだ**ので、その報告も含む。

出力形式は同じ (ファイルごとの判定 / [Critical] [Warning] [Suggestion] 分類 /
最後に `APPROVED` または `CHANGES_REQUESTED` の 1 語)。

対象コードは `/workspace/.claude/worktrees/tasks/T252/` 配下にある (読み込み可)。
Round 3 以降の変更は下記の差分がすべてである。

---

# 対応マトリクス: impl-review Round 3

## [Critical] S4-3c は先行する `return;` による無力化を検出できない

- 判断: **対応する** (指摘は正しい。実際に無力化できた)
- 根拠: Pest のテストファイルは素のスクリプトなので、`ArchBaselineTest.php` の最上位に
  `return;` を 1 行足すと**禁止表明 7 本も S1〜S5 も丸ごと登録されない**。
  そのとき **S4-3c 自身も登録されない**ので、自己検査では原理的に検出できない。
  自己参照の穴であり、Round 2 の「brace-less `if`」とは別経路である。
- 対応内容: 指摘のとおり**外部自己検査**を足した。
  - `ArchSurfaceScanner::topLevelAbortSites()` を新設。
    波括弧の深さ 0 に現れる `return` / `exit` / `die` / `throw` / `goto` / `__halt_compiler`
    の位置を返す純関数である。深さの計算は `braceDepthAt()` と**同じ表** (`braceDepths()`) を
    使う (数え方の写しを 2 つ持たない)。
  - `tests/Unit/Architecture/ArchBaselineScannerTest.php` に**テスト 38** を追加。
    **別ファイルから** `ArchBaselineTest.php` を読み、
    (a) 最上位に実行を打ち切る文が 0 件、(b) チェーンが `EXPECTED_CHAIN_TOKENS` と完全一致、
    (c) 囲みが `EXPECTED_CHAIN_HEADER_TOKENS` と完全一致、(d) その囲みの外に波括弧が無い、
    の 4 点を確かめる。
  - 走査器の負例 **13e** を追加。最上位の `return;` を拾い、
    **関数・クロージャ・制御構造の本体にある `return` / `throw` は拾わない**ことを固定した
    (`exit` / `die` / `throw` の 3 形も最上位なら拾うことを併せて固定)。
  - **注入で実測**: `ArchBaselineTest.php` の最上位へ `return;` を入れると
    **同ファイルの 41 本が全滅**し、テスト 38 が赤になった。取り除くと全緑に戻る。
  - `ArchBaselineTest` の docblock と S4-3c のコメントに
    「本ファイルの中からは原理的に検出できない穴があり、外部自己検査 (テスト 38) が見張る」ことと、
    **その外部検査自身が同じ手口で短絡されたら検出しない** (検査を見張る検査は無限に続くので
    置かない。最後の砦は git のレビュー) ことを明記した。

## [Warning] 乖離台帳の「実行時に確かめる」という記述が先行 `return` に対して成立しない

- 判断: **対応する** (上と同じ修正で成立するようにし、記述も揃えた)
- 対応内容: D43 の「揃え続ける不変条件と保証機構」行に、
  **宿主ファイルが最上位の短絡を受けていないことは別ファイルの外部自己検査が見張る**旨を書いた。
  さらに下記の自主監査の結果を受けて、短絡の形が **2 つある**ことまで書き下した (差分参照)。

---

# 自主監査で見つけた残穴 (Round 3 の [Critical] と同じ欠陥クラス)

Round 3 の修正を入れたあと、**同じ欠陥クラスに他の形が残っていないか**を負例注入で総当たりした。
**1 つ残っていた**ので、指摘を待たずに塞いだ。以下は実測にもとづく報告である。

## [自己申告 Critical] ファイル全体を `if (false): … endif;` で囲む形が、どの検査にも現れなかった

- **事象**: `ArchBaselineTest.php` の禁止表明の直前に `if (false):` を 1 行入れ、
  ファイル末尾に `endif;` を足すと、**同ファイルの 41 本が全滅する**。
  にもかかわらず **Round 3 修正後の状態でも全緑だった**。
- **なぜ既存の 3 検査をすり抜けるか**:
  - **S4-3b (波括弧の深さ)**: 代替構文 (`if (…): … endif;`) は `{` を 1 つも使わないので、
    `foreach` の位置の深さは 0 のままで一致する。
  - **S4-3c (実行時の登録簿問い合わせ)**: 囲みが**ファイル全体**に及ぶと
    **S4-3c 自身が登録されない**ので走らない。Round 3 の `return;` とまったく同じ自己参照の穴である。
  - **テスト 38 (Round 3 で足した外部自己検査)**: 見ているのは
    「実行を**打ち切る**トークンが 0 件か」だけで、代替構文は打ち切りトークンを 1 つも持たない。
    チェーンの綴りも囲みのヘッダーも 1 文字も変わらないので、他の 3 検査も通る。
- **重要な補足 (偽の安心を潰した実測)**: 注入時に `composer test -- --filter=ArchBaseline` の
  終了コードが 1 になったので「何かが検出している」ように見えた。**これは検出ではない**。
  clean な状態で `--filter=ArchBaselineScanner` (宿主ファイルに一致テストが無い) を回しても
  同じく終了コード 1 になる。**`--filter` で 0 件になったファイルがあるときの副作用**であり、
  全数実行では出ない。したがって**素の状態ではこの無力化は完全に無検出**だった。
- **Round 3 の指摘との関係**: 指摘そのものは正しく、修正も正しかった。
  ただし Round 3 時点の docblock は
  「`if (false): … endif;` は S4-3c が保証する」と書いており、
  **囲みがファイル全体に及ぶ場合には成立しない**主張になっていた。そこも直した。

- **対応内容**:
  1. `ArchSurfaceScanner::topLevelControlStructureSites()` を新設した。
     波括弧の深さ 0 に現れる制御構造の**開始**トークン 8 語
     (`if` / `while` / `do` / `for` / `foreach` / `switch` / `try` / `match`) の位置を返す純関数で、
     深さの計算は既存の `braceDepths()` を共有する (数え方の写しを増やさない)。
     - `declare` は**入れない** (`declare(strict_types=1);` は宣言を囲まないので、
       入れると全ファイルが 1 件を持ち「ちょうど 1 件」の契約が壊れる)。
     - `catch` / `finally` / `else` 系の**継続節も入れない** (必ず開始トークンを伴うので
       開始側だけ見れば取りこぼさない)。
  2. **テスト 38 を拡張**し、宿主ファイルの最上位の制御構造が
     **ちょうど 1 件で、それがチェーン自身の `foreach` (位置まで一致)** であることを要求した。
     位置は `$start - $headerLength` (= `EXPECTED_CHAIN_HEADER_TOKENS` の先頭 `foreach` の添字) で、
     **期待形の正本は増やしていない**。
     これは deny-by-default である — 宿主に最上位の制御構造を足すと、囲む意図が無くても赤くなる。
  3. 走査器の負例 **13f** を追加した。代替構文・波括弧なし `if` のどちらでも
     `['if', 'foreach']` の 2 件になること、関数・クロージャ・制御構造の**本体**にある
     `if` / `foreach` / `while` / `try` / `match` は 1 件も数えないこと、
     `declare` を数えないことを固定した。
  4. `ArchBaselineTest` の docblock・S4-3b・S4-3c のコメントと D43 の記述を、
     **短絡の形が 2 つある** (打ち切る形 / 条件付きにして飛ばす形) 前提へ書き換えた。

- **無力化の注入 4 形すべてで、必ずどれかの検査が赤くなることを実測した**
  (すべて `composer test -- --filter=ArchBaseline` で確認。数値は実際の出力):

  | 注入 | 修正前 | 修正後に赤くなる検査 |
  |---|---|---|
  | `if (false) { foreach … }` (波括弧つき) | 検出済 | S4-3b (深さ 1) と S4-3c |
  | `if (false)` + 改行 + `foreach …` (波括弧なし) | 検出済 | S4-3c (7 規則 ID を missing と報告) **と テスト 38** (制御構造 2 件) |
  | 最上位の `return;` | 検出済 (Round 3 の修正) | テスト 38 (`topLevelAbortSites` が `return` を 1 件返す) |
  | **ファイル全体を `if (false): … endif;`** | **無検出 (全緑)** | **テスト 38** (`actual size 2 matches expected size 1` で赤) |

  代替構文の注入時、修正前は `81 tests / 81 passed` → `40 tests / 40 passed` (全緑) だったものが、
  修正後は `41 tests / 40 passed / 1 failed` になり、落ちるのはテスト 38 である。

---

# 修正差分 (Round 3 の返答以降のすべて)

```diff
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index e6941b9e..963d87bc 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -2527,7 +2527,7 @@ ## D43 Pest arch のベースラインを、正典の 9 規則ではなく本ア
 |---|---|
 | 対象パス | `tests/Architecture/ArchBaselineTest.php` / `tests/Support/Architecture/ArchBaseline.php` / `tests/Support/Architecture/ArchSurfaceScanner.php` / `tests/Support/Architecture/ArchTokenStream.php` / `tests/Support/Architecture/GlobalFunctionCallScanner.php` / `tests/Support/Architecture/VendorArchPresetReader.php` / `tests/Unit/Architecture/ArchBaselineScannerTest.php` |
 | 業務要件起因の説明 | 家系の正典 v1 は禁止シンボルを規則ごとに分解して例外の波及半径を 1 シンボルに閉じることを求めるが、正典の 9 規則 102 シンボルという分解はテンプレート側の例外クラス構成から出た数である。本アプリの走査域 (App と Database\Factories と Database\Seeders) で禁止語彙を実使用しているのは sha1 と tempnam と var_export の 3 語彙 5 クラスだけであり、母集団に対する正しい分解は例外なし 4 束 + 単独シンボル 3 本の 7 規則になる。正典の本数をそのまま写すと実体の無い規則が生まれる |
-| 揃え続ける不変条件と保証機構 | 例外を持つ規則の対象シンボルがちょうど 1 個であること (`ArchBaselineTest` の S3) / 7 規則の語彙の和集合が vendor preset の禁止語彙集合と一致すること (S5。移植漏れと vendor 更新の両方を検出) / 例外の置き場が `ArchBaseline` 1 クラスに限られ禁止表明を作るチェーンが 1 本であること (S4 が tests 配下の追跡 PHP 全数を母集団に完全一致で照合し、7 本が実際に Pest へ登録されたことまで実行時に確かめ、宿主ファイルが最上位の return 等で短絡されていないことは別ファイルの外部自己検査が見張る) |
+| 揃え続ける不変条件と保証機構 | 例外を持つ規則の対象シンボルがちょうど 1 個であること (`ArchBaselineTest` の S3) / 7 規則の語彙の和集合が vendor preset の禁止語彙集合と一致すること (S5。移植漏れと vendor 更新の両方を検出) / 例外の置き場が `ArchBaseline` 1 クラスに限られ禁止表明を作るチェーンが 1 本であること (S4 が tests 配下の追跡 PHP 全数を母集団に完全一致で照合し、7 本が実際に Pest へ登録されたことまで実行時に確かめ、宿主ファイルが最上位の短絡 — 実行を打ち切る return 等と、宣言を条件付きにする最上位の制御構造で丸ごと囲む形の 2 つ — を受けていないことは別ファイルの外部自己検査が見張る) |
 | 再判定の条件 | 正典が per-rule 分解の規約そのものを変えたとき / Pest の preset 構成が変わり集合一致が取れなくなったとき / 本アプリで層分離規則 (toOnlyBeUsedIn 等) を導入するとき |
 | 決めた日 | 2026-08-23 |
 | 決めた人 | 開発者 |
diff --git a/tests/Architecture/ArchBaselineTest.php b/tests/Architecture/ArchBaselineTest.php
index f07c3136..1bd78b2e 100644
--- a/tests/Architecture/ArchBaselineTest.php
+++ b/tests/Architecture/ArchBaselineTest.php
@@ -111,14 +111,20 @@
  *   ★副産物として `arch` は **`tests/` 全数で 0 件**の禁止名になった (S4-2)。
  *   「ちょうど 1 件」を数えるより強い契約である。
  *
- * ★**本ファイルの自己検査だけでは守れない穴が 1 つある**。Pest のテストファイルは
- *   素のスクリプトなので、最上位に `return;` を 1 行足すと**禁止表明 7 本も S1〜S5 も
+ * ★**本ファイルの自己検査だけでは守れない穴がある**。Pest のテストファイルは
+ *   素のスクリプトなので、最上位を短絡させると**禁止表明 7 本も S1〜S5 も
  *   丸ごと登録されなくなる**。そのとき自己検査 (S4-3c を含む) も一緒に消えるため、
- *   **本ファイルの中からは原理的に検出できない**。
- *   これは `tests/Unit/Architecture/ArchBaselineScannerTest.php` の
- *   **外部自己検査 (テスト 38)** が `ArchSurfaceScanner::topLevelAbortSites()` で見張る。
- *   実測: 本ファイルの最上位へ `return;` を注入すると本ファイルのテストが全滅し (41 → 0)、
- *   テスト 38 が赤になる。
+ *   **本ファイルの中からは原理的に検出できない**。短絡の形は 2 つあり、
+ *   どちらも `tests/Unit/Architecture/ArchBaselineScannerTest.php` の
+ *   **外部自己検査 (テスト 38)** が見張る:
+ *     (a) **実行を打ち切る形** (最上位に `return;` を 1 行) →
+ *         `ArchSurfaceScanner::topLevelAbortSites()` が **0 件**を要求する
+ *     (b) **宣言を条件付きにして飛ばす形** (ファイル全体を `if (false): … endif;` で囲む) →
+ *         打ち切りトークンも波括弧も 1 つも増えないので (a) にも波括弧の深さの検査にも
+ *         現れない。`ArchSurfaceScanner::topLevelControlStructureSites()` が
+ *         **ちょうど 1 件 (チェーン自身の `foreach`)** を要求することでだけ捕まる
+ *   実測: (a) を注入すると本ファイルのテストが全滅し (41 → 0) テスト 38 が赤、
+ *   (b) を注入しても同じく全滅し、テスト 38 が「最上位の制御構造が 2 件」で赤になる。
  *   **その外部検査自身が同じ手口で短絡された場合は検出しない** (検査を見張る検査は
  *   無限に続くので置かない)。最後の砦は git のレビューである。
  *
@@ -516,6 +522,9 @@ function archBaselineChainStartIndex(string $source): int
     //   先行する `return` は波括弧の深さに現れない (負例 13c がこの限界を固定している)。
     //   「7 本が実際に登録されたこと」は **S4-3c が実行時に Pest の登録簿へ問い合わせて**保証する。
     //   2 つは役割が違うので両方置く。
+    //   ★**S4-3c にも届かない形が 1 つある**: 囲みが**本ファイル全体**に及ぶと S4-3c 自身が
+    //   登録されず走らない。そこは外部自己検査 (テスト 38) の
+    //   「最上位の制御構造はちょうど 1 件」が受け持つ。
     $host = dirname(__DIR__, 2).'/'.ArchBaseline::CHAIN_HOST_FILE;
     $source = archBaselineReadSource($host);
 
@@ -542,10 +551,12 @@ function archBaselineChainStartIndex(string $source): int
     //   へ結合する。`composer update` で赤くなり得るのは仕様であり、
     //   そのときは問い合わせ方を更新する (検査を緩めるのは選択肢に入れない)。
     //
-    // ★**本条が検出できない形が 1 つある**: 本ファイルの最上位に `return;` を置くと
-    //   本条自身が登録されないので走らない。そこは
-    //   `tests/Unit/Architecture/ArchBaselineScannerTest.php` の**外部自己検査 (テスト 38)** が
-    //   `topLevelAbortSites()` で見張る。役割が違うので両方要る。
+    // ★**本条が検出できない形がある**: 本ファイルの最上位に `return;` を置く / 本ファイル全体を
+    //   `if (false): … endif;` で囲む、のどちらでも**本条自身が登録されない**ので走らない。
+    //   そこは `tests/Unit/Architecture/ArchBaselineScannerTest.php` の
+    //   **外部自己検査 (テスト 38)** が `topLevelAbortSites()` (打ち切り 0 件) と
+    //   `topLevelControlStructureSites()` (最上位の制御構造ちょうど 1 件) の 2 本で見張る。
+    //   役割が違うので両方要る。
     $factory = TestSuite::getInstance()->tests->get(__FILE__);
 
     // 登録簿にファイルが無い = 本ファイルのテストが 1 つも登録されていない (実行中なのでありえない)。
diff --git a/tests/Support/Architecture/ArchSurfaceScanner.php b/tests/Support/Architecture/ArchSurfaceScanner.php
index 0f1b8e71..6634cacb 100644
--- a/tests/Support/Architecture/ArchSurfaceScanner.php
+++ b/tests/Support/Architecture/ArchSurfaceScanner.php
@@ -73,6 +73,27 @@ private function __construct() {}
         T_HALT_COMPILER,
     ];
 
+    /**
+     * ファイル最上位に置かれると**以降の宣言を条件付きにできる**制御構造の開始トークン。
+     *
+     * ★`declare` は入れない。`declare(strict_types=1);` は宣言を囲まないので、
+     *   入れると全ファイルが 1 件を持つことになり「ちょうど 1 件」の契約が成り立たない。
+     * ★`catch` / `finally` / `else` 系の**継続節**も入れない。継続節は必ず対応する
+     *   開始トークン (`try` / `if`) を伴うので、開始側だけ見れば取りこぼさない。
+     *
+     * @var list<int>
+     */
+    private const array CONTROL_STRUCTURE_TOKENS = [
+        T_IF,
+        T_WHILE,
+        T_DO,
+        T_FOR,
+        T_FOREACH,
+        T_SWITCH,
+        T_TRY,
+        T_MATCH,
+    ];
+
     /**
      * 直前に来ると「関数呼び出しではない (メンバ名・宣言)」と判定するトークン。
      *
@@ -223,6 +244,9 @@ public static function braceDepthAt(string $source, int $index): int
      *   プロセスを終了させる形は検出しない。**この構文について検出力を主張しない**。
      *   閉じ括弧の内側 (関数・クロージャ・制御構造の本体) にある同じ語は**数えない** —
      *   そこの `return` はファイルの実行を打ち切らないからである。
+     * ★**「打ち切る」形しか見ない**。宣言を**条件付きにして飛ばす**形
+     *   (`if (false): … endif;` で丸ごと囲む) はここに現れないので、
+     *   そちらは {@see self::topLevelControlStructureSites()} が受け持つ。
      *
      * @return list<array{name: string, line: int, index: int}>
      */
@@ -247,6 +271,47 @@ public static function topLevelAbortSites(string $source): array
         return $sites;
     }
 
+    /**
+     * **ファイル最上位 (波括弧の深さ 0) に置かれた制御構造の開始位置**を返す。
+     *
+     * ★これは {@see self::topLevelAbortSites()} の対になる走査である。あちらが見るのは
+     *   `return;` のように**実行を打ち切る**形だけで、宣言を**条件付きにして飛ばす**形
+     *   — ファイル全体を `if (false): … endif;` で囲む — は打ち切りトークンを 1 つも
+     *   持たないため現れない。**波括弧の深さにも現れない** (代替構文は `{` を使わない)。
+     *   その形は「宿主ファイルの最上位に制御構造がちょうど 1 つ (チェーン自身の `foreach`)
+     *   しかない」という**件数の契約**でしか捕まえられない
+     *   (`ArchBaselineScannerTest` の外部自己検査がその契約を持つ)。
+     *
+     * 数えるのは {@see self::CONTROL_STRUCTURE_TOKENS} の 8 語である。
+     *
+     * ★**保証しないもの**: 深さ 0 に無い制御構造 (関数・クロージャ・制御構造の本体) は
+     *   数えない。波括弧つきで囲む形はここではなく**深さの検査**が受け持つ
+     *   (囲まれた側の深さが 1 以上になる)。`goto` による飛び越しは
+     *   {@see self::topLevelAbortSites()} 側の語彙にある。
+     *
+     * @return list<array{name: string, line: int, index: int}>
+     */
+    public static function topLevelControlStructureSites(string $source): array
+    {
+        $tokens = ArchTokenStream::significantTokens($source, self::class);
+        $depths = self::braceDepths($tokens);
+
+        $sites = [];
+        foreach ($tokens as $index => $token) {
+            if ($token['id'] === null || ! in_array($token['id'], self::CONTROL_STRUCTURE_TOKENS, true)) {
+                continue;
+            }
+
+            if ($depths[$index] !== 0) {
+                continue; // 関数・クロージャ・制御構造の本体。最上位の宣言を条件付きにはできない
+            }
+
+            $sites[] = ['name' => $token['text'], 'line' => $token['line'], 'index' => $index];
+        }
+
+        return $sites;
+    }
+
     /**
      * 各トークン位置の**直前までに開いたままの波括弧の深さ**の表 (要素数はトークン数 + 1)。
      *
diff --git a/tests/Unit/Architecture/ArchBaselineScannerTest.php b/tests/Unit/Architecture/ArchBaselineScannerTest.php
index 19e1cd5b..9c633f0e 100644
--- a/tests/Unit/Architecture/ArchBaselineScannerTest.php
+++ b/tests/Unit/Architecture/ArchBaselineScannerTest.php
@@ -475,16 +475,77 @@ public function run(): int
         ->and(ArchSurfaceScanner::topLevelAbortSites($otherAborts))->toHaveCount(3);
 });
 
+test('13f: 最上位の制御構造を拾い、本体の中の同じ語は拾わない', function (): void {
+    // ★代替構文 (`if (…): … endif;`) は**波括弧を 1 つも増やさない**ので、
+    //   深さの検査でも打ち切りトークンの検査でも現れない。ここだけが見える。
+    $alternativeSyntax = <<<'PHP'
+        <?php
+
+        if (false):
+
+        foreach ([1] as $i) {
+            test('x', function (): void {});
+        }
+
+        endif;
+        PHP;
+
+    $braceLess = <<<'PHP'
+        <?php
+
+        if (false)
+        foreach ([1] as $i) {
+            test('x', function (): void {});
+        }
+        PHP;
+
+    $inBodies = <<<'PHP'
+        <?php
+
+        declare(strict_types=1);
+
+        test('x', function (): void {
+            if (true) {
+                foreach ([1] as $i) {
+                    while (false) {
+                    }
+                }
+            }
+
+            try {
+                $v = match (1) { default => 0 };
+            } catch (RuntimeException $e) {
+            }
+        });
+        PHP;
+
+    $names = static fn (string $source): array => array_column(
+        ArchSurfaceScanner::topLevelControlStructureSites($source),
+        'name',
+    );
+
+    // 代替構文・波括弧なしのどちらでも、最上位の `if` と本来の `foreach` の 2 件になる
+    expect($names($alternativeSyntax))->toBe(['if', 'foreach'])
+        ->and($names($braceLess))->toBe(['if', 'foreach'])
+        // 本体の中の制御構造は数えない。`declare` も制御構造ではないので数えない
+        ->and($names($inBodies))->toBe([]);
+});
+
 // ---------------------------------------------------------------------------
 // チェーン宿主ファイルの**外部**自己検査
 // ---------------------------------------------------------------------------
 
 test('38: チェーン宿主ファイルが外部から見て短絡されていない', function (): void {
     // ★**これは別ファイルに置くことが load-bearing である**。
-    //   Pest のテストファイルは素のスクリプトなので、`ArchBaselineTest.php` の最上位に
-    //   `return;` を 1 行足すだけで禁止表明 7 本も S1〜S5 も**丸ごと登録されなくなる**。
+    //   Pest のテストファイルは素のスクリプトなので、`ArchBaselineTest.php` の最上位を
+    //   短絡させると禁止表明 7 本も S1〜S5 も**丸ごと登録されなくなる**。
     //   そのとき同ファイルの自己検査 (S4-3c を含む) も一緒に消えるので、
     //   **自己検査では原理的に検出できない**。ここが外側から見張る。
+    //   短絡の形は 2 つあり、**片方だけでは塞がらない**:
+    //     (a) 実行を打ち切る形 (`return;` を 1 行置く) → `topLevelAbortSites()` が 0 件を要求
+    //     (b) 宣言を条件付きにして飛ばす形 (`if (false): … endif;` で丸ごと囲む)
+    //         → 打ち切りトークンも波括弧も増えないので (a) にも深さの検査にも現れない。
+    //           `topLevelControlStructureSites()` の**ちょうど 1 件**でだけ捕まる
     //
     // ★**保証しないもの**: 本ファイル自身が同じ手口で短絡された場合は検出できない
     //   (検査を外から見張る検査は無限に続くので置かない)。最後の砦は git のレビューである。
@@ -497,7 +558,14 @@ public function run(): int
     $headerLength = count(ArchBaseline::EXPECTED_CHAIN_HEADER_TOKENS);
     $start = archBaselineChainIndex($source);
 
-    expect(ArchSurfaceScanner::topLevelAbortSites($source))->toBe([])
+    // 宿主ファイルの最上位にある制御構造は**チェーン自身の `foreach` ちょうど 1 つ**である。
+    // 宿主へ最上位の制御構造を足すと (囲む意図が無くても) ここが赤くなる = deny-by-default。
+    $controlSites = ArchSurfaceScanner::topLevelControlStructureSites($source);
+
+    expect($controlSites)->toHaveCount(1)
+        ->and($controlSites[0]['name'])->toBe('foreach')
+        ->and($controlSites[0]['index'])->toBe($start - $headerLength)
+        ->and(ArchSurfaceScanner::topLevelAbortSites($source))->toBe([])
         ->and(ArchSurfaceScanner::statementTokens($source, $start))
         ->toBe(ArchBaseline::EXPECTED_CHAIN_TOKENS)
         ->and(ArchSurfaceScanner::tokensBefore($source, $start, $headerLength))
```

---

# 修正後の実測

- `composer test -- --filter=ArchBaseline`: **82 tests / 82 passed / 188 assertions**
  (Round 3 時点の 81 本 + 負例 13f)
- `composer test` (全数): **6766 tests / 6764 passed / 2 skipped / 5 risky / 0 failed** (終了コード 0)
- `vendor/bin/phpstan analyse --level=10 tests/Support/Architecture tests/Architecture/ArchBaselineTest.php tests/Unit/Architecture/ArchBaselineScannerTest.php`: **0 errors**
- `composer phpstan`: **No errors** (1024 ファイル)
- `vendor/bin/pint --test`: **passed**
- 抑止コメント・baseline 追加・型の widen・設定ファイルの緩和は**いずれも使っていない**。

# 判定してほしい点

1. Round 3 の [Critical] / [Warning] は解消したか。
2. 自主監査で足した `topLevelControlStructureSites()` + テスト 38 の拡張 + 負例 13f は、
   **狙った欠陥クラスに対して過不足がないか** (数えるトークン 8 語の選び方、
   `declare` と継続節を外した判断、「ちょうど 1 件 + 位置一致」という契約の強さ)。
3. この欠陥クラス (宿主ファイルの最上位を短絡させて宣言ごと消す形) に、
   **まだ残っている経路があるか**。あるなら具体的な注入形を示せ
   (こちらで注入して実測し、赤くならなければ塞ぐ)。
