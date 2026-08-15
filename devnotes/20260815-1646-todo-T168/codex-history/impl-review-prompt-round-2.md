## Round 1 の指摘への対応

Round 1 の全体判定は APPROVED だったが、[Warning] 2 件はどちらも実害のある指摘だったため
そのまま受け入れて修正した。対応マトリクスの全文は下記のとおり。

# 対応マトリクス: impl-review Round 1

Round 1 の全体判定は **APPROVED** だったが、[Warning] 2 件は実害のある指摘だったため
そのまま受け入れて修正した (設計側も同じコミットで直した)。

## [Warning] 走査器: PHP 8.4 で合法な名前位置に false positive が残る (typed class constants / 参照返しメソッド)

- 判断: **対応する**
- 根拠: 指摘どおりか実測した。**どちらも `php -l` が通る合法な書き方でありながら、
  設計時の読み飛ばし規則では違反として検出されていた** (実測):
  - `class A { public const string echo = 'x'; }` → 検出 1 件 (誤検出)
  - `class A { public const ?string goto = null; }` → 検出 1 件 (誤検出)
  - `class A { public const A|string global = 'g'; }` → 検出 1 件 (誤検出)
  - `class A { public function &echo(): mixed { $x = 1; return $x; } }` → 検出 1 件 (誤検出)
  原因は、綴りの直前トークンが期待した `T_CONST` / `T_FUNCTION` ではなく、
  **型の綴り (`T_STRING`) / 参照の記号 (`T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG`)**
  になるためである (実測)。設計 S2 が「実在する書き方をすべて洗った」と書いていたので、
  洗い漏れは設計の誤りである。
- 対応内容:
  1. **R3 を一般化**した。`T_CONST` からセミコロンまでの区間で「直後が `=`」を名前位置とする
     (直前では狭めない)。素の宣言・型つき宣言・読点で繋いだ 2 つ目以降を 1 つの規則で覆う。
     設計の R3 / R3b は 1 本に統合された。取りこぼしを増やさないのは、
     定数の初期化式に文を書けない (PHP の定数式の制限) ためである
  2. **R2b を新設**した。直前が参照の記号で、その 1 つ前が `T_FUNCTION`、直後が `(` のときだけ
     名前位置とする (3 条件で狭めてある)
  3. 負の対照 N15 (型つきクラス定数 3 形) / N16 (参照を返すメソッドの宣言) と、
     取りこぼし対照 F8 (型つきクラス定数の直後の違反) / F9 (参照を返すメソッドの本体の違反) を
     足した。S3 は 30 本 → **34 本**
  4. `detailed-design.md` の S2 実測表・規則表・説明文・コード、S3 の検体表と本数を同じコミットで更新した
- なお別途実測した合法な書き方 — 属性つき列挙の場合分け (`#[Attr] case Echo = 'e';`) /
  属性つきクラス定数 / 属性つきメソッド宣言 / `final public const echo = 1;` /
  `abstract public function echo(): void;` / `public static function echo(): void {}` /
  `use T { T::m as echo; }` / インターフェース定数 / 列挙の定数 — は
  **もともと誤検出しない**ことを確認した (規則を足す必要はなかった)

## [Warning] gate: `file_get_contents()` 失敗を `continue` で握り潰しており fail-closed でない

- 判断: **対応する**
- 根拠: 指摘のとおり。git 追跡下のファイルが読めないのは環境異常であり、
  黙って走査から落とすと「走査していないのに緑」になる。本 gate は
  git 不在を silent skip にしない方針で書いてあるので、読み取り失敗だけ緩いのは一貫しない
- 対応内容: `RuntimeException` を投げるようにした。設計 S5 の PHPStan 適合チェックの
  該当行も同じコミットで直した

## [Suggestion] `label()` の表示名が設計案より短い

- 判断: **見送る**
- 根拠: 設計案は `開始タグ付きの出力記法 (<?=)` だったが、開始タグの綴りをそのまま書くと
  **本ファイル自身が走査対象 (`tests/`) にあるため読み手が混乱する**
  (実際にはコメント・文字列は除去されるので違反にはならない)。
  AGENTS.md 側で「`<?` に `=` を続ける書き方」と明記してあり、意味は伝わる

## [Suggestion] R6 が前文脈なしで「直後が単独のコロン」を読み飛ばすのは将来広すぎる規則になりうる

- 判断: **見送る**
- 根拠: 現行 PHP では名前つき引数以外にこの形が無いことを実測済みで、
  今必要のない先回りをしない (思考原則 2)。将来 PHP の文法が増えたときは
  取りこぼし対照 (F6) が赤くならないまま穴が開きうるが、それは言語追従の作業として扱う

## [Suggestion] 目録の空行 / 検体の `php -l` 済みコメント

- 判断: **見送る** (空行) / **対応済み** (コメント)
- 根拠: 空行は目録の読みやすさの問題で、実害が無い


## 修正後の実測

### 修正前 (Round 1 のコード) の誤検出 — 指摘どおり再現した

```
typedconst   lint=OK count=1 [echo@2]     class A { public const string echo = 'x'; }
typedconst2  lint=OK count=1 [goto@2]     class A { public const ?string goto = null; }
typedconst3  lint=OK count=1 [global@2]   class A { public const A|string global = 'x'; }
byref        lint=OK count=1 [echo@2]     class A { public function &echo(): mixed { ... } }
```

### 誤検出しないことを確認した合法な書き方 (規則を足す必要が無かったもの)

```
attrcase   0 件   enum E: string { #[Attr] case Echo = 'e'; }
attrconst  0 件   class A { #[Attr] public const echo = 1; }
attrmethod 0 件   class A { #[Attr] public function echo(): void {} }
finalconst 0 件   class A { final public const echo = 1; }
abstractfn 0 件   abstract class A { abstract public function echo(): void; }
staticfn   0 件   class A { public static function echo(): void {} }
traitas    0 件   class A { use T { T::m as echo; } }
interfaceconst 0 件   interface I { const echo = 1; }
enumconst  0 件   enum E { const echo = 1; case A; }
```

### 修正後のテスト結果

- 新規分 46 本 (gate 12 本 + 走査器の自己検査 34 本) すべて green
- `composer test`: 4847 tests / 4845 passed / **0 failed** / 2 skipped (assertions 20469, 416s)
- `composer phpstan` (level 10): No errors
- `vendor/bin/pint --test`: passed
- フロント側 (`pnpm lint` / `typecheck` / `test` 1501 本 / `build` / packages 3 種) は
  本差分が `resources/js` を 1 行も触らないため Round 1 と同じく green (再実行済み)

## 追加でレビューしてほしい点

1. R3 を「定数宣言の区間で直後が代入記号」へ一般化したことで、**取りこぼし (false negative) が
   生まれていないか**。判断の根拠は「定数の初期化式に文は書けない (PHP の定数式の制限)」である。
   この前提が崩れる書き方を知っていれば指摘してほしい
2. R2b の 3 条件 (直前が参照の記号 / その 1 つ前が `function` / 直後が開き括弧) で
   十分に狭いか
3. 読み取り失敗を例外にしたことで、gate が意図せず脆くなっていないか

## 修正差分 (tests/ と詳細設計書。AGENTS.md は Round 1 から無変更)

```diff
diff --git a/devnotes/20260815-1537-forbidden-statement-token-gate/detailed-design.md b/devnotes/20260815-1537-forbidden-statement-token-gate/detailed-design.md
index 479692e..4ea0750 100644
--- a/devnotes/20260815-1537-forbidden-statement-token-gate/detailed-design.md
+++ b/devnotes/20260815-1537-forbidden-statement-token-gate/detailed-design.md
@@ -216,6 +216,8 @@ ### 設計の核: なぜ読み飛ばし規則が要るのか (実測)
 | `$f = Foo::echo(...);` | `T_ECHO` | `T_DOUBLE_COLON` | 違反でない |
 | `class Foo { public function echo(): void {} }` | `T_ECHO` | `T_FUNCTION` | 違反でない |
 | `class Foo { const echo = 1; const ECHO = 2; }` | `T_ECHO` | `T_CONST` | 違反でない |
+| `class A { public const string echo = 'x'; }` | `T_ECHO` | **`T_STRING`** (型の綴り) | 違反でない |
+| `class A { public function &echo(): mixed {} }` | `T_ECHO` | **`T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG`** | 違反でない |
 | `enum E: string { case Echo = 'e'; }` | `T_ECHO` | `T_CASE` | 違反でない |
 | `enum E { case Echo; }` | `T_ECHO` | `T_CASE` | 違反でない |
 | `class Foo { const echo = 1, goto = 2; }` | `T_ECHO` / `T_GOTO` | `T_CONST` / `,` | 違反でない |
@@ -250,8 +252,8 @@ ### 読み飛ばし規則 (直前と直後の組で狭める)
 |---|---|---|---|
 | R1 | `T_DOUBLE_COLON` (`::`) | (条件なし) | `Foo::goto();` / `$c = Foo::echo;` / `Foo::echo(...)` / トレイト取り込みの元メソッド指定 `T::echo as m;` / `case A::ECHO:` |
 | R2 | `T_FUNCTION` | `(` | `class A { public function echo(): void {} }` / `interface I { public function goto(): void; }` |
-| R3 | `T_CONST` | `=` | `class A { const echo = 1; }` |
-| R3b | `,` (**定数宣言の区間に限る**) | `=` | `class A { const echo = 1, goto = 2; }` |
+| R2b | 参照の記号 + **その 1 つ前が `T_FUNCTION`** | `(` | 参照を返すメソッドの宣言 `class A { public function &echo(): mixed {} }` |
+| R3 | (**定数宣言の区間に限る**。直前は問わない) | `=` | `class A { const echo = 1; }` / `class A { const echo = 1, goto = 2; }` / 型つきクラス定数 `class A { public const string echo = 'x'; }` |
 | R4 | `T_CASE` | `=` / `;` | `enum E: string { case Echo = 'e'; }` / `enum E { case Echo; }` |
 | R6 | (条件なし) | 単独の `:` | 名前つき引数 `f(global: 2, goto: 3)` / 属性の名前つき引数 `#[Attr(echo: 1)]` |
 | R7 | `T_AS` / `T_PUBLIC` / `T_PROTECTED` / `T_PRIVATE` | `;` | トレイト取り込みの別名 `class A { use T { m as echo; } }` / `class A { use T { m as protected global; } }` |
@@ -266,19 +268,28 @@ ### 読み飛ばし規則 (直前と直後の組で狭める)
 (実測: `define("echo", 1); switch ($x) { case echo: }` は Parse error)。
 `case A::ECHO:` の形は R1 が扱う。
 
-R3b だけ状態を持つ。**`T_CONST` から `;` までの区間**を真偽値 1 つで覚え、
-その区間の中で「直前が `,` かつ直後が `=`」の綴りを名前位置とする。
+R3 だけ状態を持つ。**`T_CONST` から `;` までの区間**を真偽値 1 つで覚え、
+その区間の中で「直後が `=`」の綴りを名前位置とする。**直前のトークンでは狭めない。**
+型つきクラス定数 (`const string echo = 'x';` / `const ?string goto = null;` /
+`const A|string global = 'g';`) では綴りの直前が `T_CONST` ではなく**型の綴り**になり、
+読点で繋いだ 2 つ目以降では `,` になるため、直前で狭めるとどちらも誤検出になる
+(**実装時に実測して判明した。いずれも `php -l` が通る合法な書き方である**)。
 定数の初期化式に文は書けない (PHP の定数式の制限) ので、
 この区間を名前位置扱いしても本物の文を取りこぼさない。
 配列リテラルの読点 (`const X = [1, 2], Y = 3;`) は直後が `=` にならないため一致しない
 (実測で確認済み)。
 
+R2b も**実装時に実測して足した規則**である。参照を返すメソッドの宣言
+(`public function &echo(): mixed`) では綴りの直前が `T_FUNCTION` ではなく参照の記号
+(`T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG`) になるため、R2 だけでは誤検出になる。
+1 つ前が `T_FUNCTION` であること・直後が `(` であることの両方を課して狭めてある。
+
 R7 の可視性修飾子は**トレイト取り込みの別名指定でしか現れない**
 (通常の宣言では `public function echo` のように間に `function` が入り R2 になる)。
 直後を `;` に限ることで、修飾子の直後に文が立つ余地を消している。
 
-**この 7 規則が取りこぼしを作らないこと**は、規則の近傍に本物の違反を置いた
-検体 (S3 の F1〜F7 + F4b の 8 本) が検出されることで固定する。実測では、規則の近傍にある
+**この規則群が取りこぼしを作らないこと**は、規則の近傍に本物の違反を置いた
+検体 (S3 の F1〜F9 + F4b の 10 本) が検出されることで固定する。実測では、規則の近傍にある
 本物の `echo` の直前トークンは `{` / `}` / `:` / `;` のいずれかになり、
 どの規則にも一致しない。
 
@@ -307,11 +318,11 @@ ### 変更後コード
  *   gate が持つ**。この走査器はどちらも知らない。
  *
  * ★**保証しないもの (誇張しない)**:
- *   - 名前の解決が要る出力 (`printf` / `var_dump` / `fwrite(STDOUT, …)` /
- *     `$out = 'echo'; $out(…)`) には**沈黙する**。この検査は完全性を主張しない
- *   - Blade の `@php … @endphp` と `{{ }}` の中は `token_get_all()` からは
+ *   - 名前の解決が要る出力 (書式つき出力 / 変数の内容の表示 / 標準出力への書き込み /
+ *     文字列に入れた綴りを変数経由で呼ぶ形) には**沈黙する**。この検査は完全性を主張しない
+ *   - Blade の `@php … @endphp` と二重波括弧の中は `token_get_all()` からは
  *     地の文 (`T_INLINE_HTML`) に見えるため届かない。
- *     **PHP 開始タグで開いた区間 (`<?= …` / `<?php …`) は見える** (実測)
+ *     **PHP 開始タグで開いた区間は見える** (実測)
  *   - ヒアドキュメント / ナウドキュメントの本文は 1 つの
  *     `T_ENCAPSED_AND_WHITESPACE` になり、中の綴りは見えない (実測)。
  *     これは本走査器の自己検査ファイルが自分自身を違反にしない理由でもある
@@ -319,41 +330,42 @@ ### 変更後コード
 final class ForbiddenStatementScanner
 {
     /**
-     * 直前が `::` なら無条件に名前位置とみなす (R1)。
+     * 直前がこれなら無条件に名前位置とみなす (R1)。
      *
-     * ★`::` は「直後に名前しか置けない」ことが PHP の文法から言えるので、
+     * ★二重コロンは「直後に名前しか置けない」ことが PHP の文法から言えるので、
      *   直後の条件を課さなくても十分に狭い。逆に直後に来られるトークンの種類が
-     *   多い (`(` `;` `,` `)` `=` `\` …) ため、列挙するとかえって穴を作る。
-     * ★**属性 (`#[`) のための規則は持たない**。属性名に予約語は書けず
-     *   (実測: `#[Echo] class A {}` は Parse error)、属性の中で綴りが現れうるのは
-     *   名前つき引数 (`#[Attr(echo: 1)]`) だけで、それは R6 が扱うためである。
+     *   多い (`(` `;` `,` `)` `=` 名前空間の区切り …) ため、列挙するとかえって穴を作る。
+     * ★**属性のための規則は持たない**。属性名に予約語は書けず
+     *   (実測: 属性名に出力する文の綴りを置くと Parse error)、属性の中で綴りが現れうるのは
+     *   名前つき引数だけで、それは R6 が扱うためである。
      *   成立しない書き方のために規則を置くと検出力を無償で捨てることになる。
+     *
+     * @var list<int>
      */
     private const array NAME_ONLY_PREDECESSORS = [
-        T_DOUBLE_COLON,   // Foo::goto() / $c = Foo::echo; / Foo::echo(...) / T::echo as m; / case A::ECHO:
+        T_DOUBLE_COLON,   // 静的呼び出し / クラス定数の取得 / 第一級呼び出し可能 / トレイト取り込みの元メソッド指定 / 場合分けの値
     ];
 
     /**
      * 直前がこれらなら、**直後が指定のトークンのときに限り**名前位置とみなす
-     * (R2 / R3 / R4 / R7)。
+     * (R2 / R4 / R7)。
      *
      * ★字句走査は構文の妥当性を保証しないので、規則は狭いほどよい。
      *   直前だけで判定すると「構文として成立しない断片」でも黙ることになる。
      * ★可視性修飾子が現れるのは**トレイト取り込みの別名指定だけ**である
      *   (通常の宣言では間に `function` が入るので R2 になる)。
-     * ★`T_CASE` の直後に `:` を許さない。素の予約語は場合分けの値に書けず
-     *   (実測: `define("echo", 1); switch ($x) { case echo: }` は Parse error)、
-     *   `case A::ECHO:` の形は R1 が扱うためである。
+     * ★`T_CASE` の直後に単独のコロンを許さない。素の予約語は場合分けの値に書けず
+     *   (実測: 定数として定義しても場合分けの値に素の綴りは置けず Parse error)、
+     *   クラス定数経由の形は R1 が扱うためである。
      *
      * @var array<int, list<string>> トークン ID => 直後に許す単一文字トークン
      */
     private const array NAME_POSITION_PREDECESSORS = [
-        T_FUNCTION => ['('],      // class A { public function echo(): void {} }
-        T_CONST => ['='],         // class A { const echo = 1; }
-        T_CASE => ['=', ';'],     // enum E: string { case Echo = 'e'; } / enum E { case Echo; }
-        T_AS => [';'],            // class A { use T { m as echo; } }
-        T_PUBLIC => [';'],        // class A { use T { m as public echo; } }
-        T_PROTECTED => [';'],     // class A { use T { m as protected global; } }
+        T_FUNCTION => ['('],      // クラス / インターフェースのメソッド宣言
+        T_CASE => ['=', ';'],     // 列挙の場合分け (値つき / 値なし)
+        T_AS => [';'],            // トレイト取り込みの別名指定
+        T_PUBLIC => [';'],        // トレイト取り込みの別名指定 (可視性つき)
+        T_PROTECTED => [';'],     // 同上
         T_PRIVATE => [';'],       // 同上
     ];
 
@@ -365,8 +377,8 @@ ### 変更後コード
         $tokens = PhpTokenScan::normalize($phpSource);
         $count = count($tokens);
 
-        // R3b 用。`T_CONST` から `;` までの定数宣言区間だけ、
-        // 読点区切りの 2 つ目以降を名前位置とみなす。
+        // R3 用。`T_CONST` からセミコロンまでの定数宣言区間だけ、
+        // 直後が代入記号の綴りを名前位置とみなす。
         $inConstDeclaration = false;
 
         $sites = [];
@@ -410,21 +422,35 @@ ### 変更後コード
             return true;
         }
 
-        // R2 / R3 / R4 / R7: 直前と直後の組で狭める
+        // R2 / R4 / R7: 直前と直後の組で狭める
         $allowedNext = $previousId === null ? null : (self::NAME_POSITION_PREDECESSORS[$previousId] ?? null);
         if ($allowedNext !== null && $nextChar !== null && in_array($nextChar, $allowedNext, true)) {
             return true;
         }
 
-        // R3b: `const echo = 1, goto = 2;` の 2 つ目以降。
-        //      定数の初期化式に文は書けないので、この区間を名前位置扱いしても取りこぼさない。
-        //      配列リテラルの読点は直後が `=` にならないため一致しない。
-        if ($inConstDeclaration && $previousId === null && ($previous['text'] ?? null) === ',' && $nextChar === '=') {
+        // R2b: 参照を返すメソッドの宣言 (`function &echo(): mixed`)。
+        //      直前が参照の記号で、その 1 つ前が `function`、直後が開き括弧のときだけ。
+        //      ★`&` は `T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG` になる (実測)。
+        if ($previousId === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG
+            && ($tokens[$index - 2]['id'] ?? null) === T_FUNCTION
+            && $nextChar === '(') {
+            return true;
+        }
+
+        // R3: 定数宣言の区間 (`const` からセミコロンまで) で直後が代入記号なら定数名。
+        //     ★直前のトークンで狭めない。型つきクラス定数 (`const string echo = 'x';` /
+        //       `const ?string goto = null;` / `const A|string global = 'x';`) では
+        //       直前が `T_CONST` ではなく型の綴りになるため (実測)。
+        //     ★読点で繋いだ 2 つ目以降 (`const echo = 1, goto = 2;`) も同じ規則で覆う。
+        //     ★定数の初期化式に文は書けない (PHP の定数式の制限) ので、この区間を
+        //       名前位置扱いしても本物の文を取りこぼさない。配列リテラルの読点
+        //       (`const X = [1, 2], Y = 3;`) は直後が代入記号にならないため一致しない。
+        if ($inConstDeclaration && $nextChar === '=') {
             return true;
         }
 
-        // R6: 名前つき引数 (`f(global: 2)`) は直後が単独の `:` になる。
-        //     `::` は 1 つの `T_DOUBLE_COLON` トークンなので、ここには一致しない。
+        // R6: 名前つき引数は直後が単独のコロンになる。
+        //     二重コロンは 1 つの `T_DOUBLE_COLON` トークンなので、ここには一致しない。
         return $nextChar === ':';
     }
 }
@@ -494,9 +520,11 @@ ### テスト一覧 (すべて Pest の `test()`)
 | N11 | 複数の定数を読点で繋いだ宣言 | `<?php class A { const echo = 1, goto = 2, global = 3; }` |
 | N12 | トレイト取り込みの別名指定 | `<?php trait T { public function m(): void {} } class A { use T { m as echo; } }` |
 | N13 | トレイト取り込みの別名指定 (可視性つき) | `<?php trait T { public function m(): void {} } class A { use T { m as protected global; } }` |
-| N14 | 定数の初期化式の配列の読点は名前位置にならない | `<?php class A { const X = [1, 2], Y = 3; }` (0 件。R3b が広がりすぎていないことの確認) |
+| N14 | 定数の初期化式の配列の読点は名前位置にならない | `<?php class A { const X = [1, 2], Y = 3; }` (0 件。R3 が広がりすぎていないことの確認) |
+| N15 | 型つきクラス定数の宣言 | `<?php class A { public const string echo = 'x'; public const ?string goto = null; public const A\|string global = 'g'; }` |
+| N16 | 参照を返すメソッドの宣言 | `<?php class A { public function &echo(): mixed { $x = 1; return $x; } }` |
 
-N1〜N14 を 1 つに連結した検体は**作らない**。各断片は `<?php` を持ち、
+N1〜N16 を 1 つに連結した検体は**作らない**。各断片は `<?php` を持ち、
 `trait T` / `class A` を重複して宣言するため、単純に繋ぐと構文・宣言が衝突して
 「全検体は構文として成立する PHP である」という約束を破る。
 連結しても各断片が個別に 0 件であること以上の保証は得られない。
@@ -518,6 +546,8 @@ ### テスト一覧 (すべて Pest の `test()`)
 | F5 | 静的呼び出しの直後の違反を検出する | `<?php Foo::bar(); echo "x";` | 1 件 |
 | F6 | 名前つき引数の直後の違反を検出する | `<?php f(a: 1); global $x;` | 1 件 |
 | F7 | 括弧付きの `echo` も検出する | `<?php echo("x");` | 1 件 |
+| F8 | 型つきクラス定数の直後の違反を検出する | `<?php class A { public const string X = 'x'; } echo "y";` | 1 件 |
+| F9 | 参照を返すメソッドの本体の違反を検出する | `<?php class A { public function &m(): mixed { echo "x"; $x = 1; return $x; } }` | 1 件 |
 
 **写像の網羅**
 
@@ -558,7 +588,7 @@ ### PHPStan 適合チェック
 
 ### テスト計画
 
-本施策そのものがテストである。green の条件は上表 **30 本** (P 6 本 + N 14 本 + F 8 本 + M 2 本) すべて。
+本施策そのものがテストである。green の条件は上表 **34 本** (P 6 本 + N 16 本 + F 10 本 + M 2 本) すべて。
 
 ### リスク
 
@@ -815,6 +845,11 @@ ### テスト一覧
 | G11 | 例外を登録できるのは例外可の置き場所だけ | 登録キーの最上位ディレクトリの分類が `ScannedWithExemption` | `app/` 等へ例外を書こうとした |
 | G12 | 例外の登録内容そのものが正しい | `counts` が空でない / 全キーが `ForbiddenStatementKind::cases()` の値に含まれる / 全ての値が **1 以上の整数** | 綴り間違いのキーや `0` 件登録が黙って通るのを止める |
 
+**実装上の注意 (G5 / G12)**: Pest の `toContain()` は**可変長で「すべて含む」を検査する**ため、
+第 2 引数に説明文を渡すと**説明文そのものが needle になり常に赤くなる** (実装時に実測)。
+「含むこと」を説明文つきで検査するときは `in_array()` を真偽値へ落として
+`toBeTrue($message)` にする。
+
 **G12 が要る理由**: `counts` の型は `array<string, int>` なので、
 未知の語彙キー (`'ehco' => 23`) や `0` / 負数を PHPStan は止められない。
 未知のキーは「差し引かれない = G1 が落ちる」ので静かには壊れないが、
@@ -840,13 +875,16 @@ ### G1 の仕様 (実装者が読み違えないように明記する)
 ### G2 の失敗メッセージ (床値割れの原因を判別できるようにする)
 
 床値を割った理由が「分類漏れで除外が増えた」のか「単にファイルが減った」のかを
-メッセージだけで判別できるようにする。
+メッセージだけで判別できるようにする。**「分類の上で除外した置き場所」と
+「分類漏れで走査外になった置き場所」は言い分ける** (前者は `(除外)`、後者は
+`(未分類→走査外!)`)。前者は正常な状態であり、感嘆符を付けると毎回の失敗表示で
+狼少年になるためである。
 
 ```
 走査対象が床値 (1400) を下回りました: 走査 812 件
   追跡 PHP 総数: 1567 件
   除外された数: 755 件
-  置き場所ごとの内訳: app=760(走査) tests=601(除外!) devnotes=15(除外) …
+  置き場所ごとの内訳: app=760(未分類→走査外!) tests=601(走査) devnotes=15(除外) …
 分類 (forbiddenStatementRootPolicies) が意図せず除外側へ倒れていないか確認してください。
 ```
 
@@ -869,7 +907,9 @@ ### 失敗メッセージの書式
 ### PHPStan 適合チェック
 
 - [x] 戻り値の型が明示されている (目録関数は phpdoc の array shape 付き)
-- [x] null 安全 (`file_get_contents()` の `false` を `is_string()` で弾く)
+- [x] null 安全 (`file_get_contents()` の `false` を `is_string()` で弾き、**skip ではなく例外で落とす**。
+      git 追跡下のファイルが読めないのは環境異常であり、黙って走査から落とすと
+      「走査していないのに緑」になる = fail-closed にする)
 - [x] 配列 shape を phpdoc で固定 (テスト専用の目録なので readonly class にはしない。
       既存 `strayHttpEgressOptOutExemptions()` と同じ作法)
 - [x] `ForbiddenStatementRootPolicy` を扱う分岐は網羅 `match`
@@ -987,16 +1027,21 @@ ## 設計時の実測 (実装前に走査器を試作して確かめた結果)
 **リポジトリには 1 ファイルも残していない** (設計の裏取りが目的で、
 繰り返し使う道具ではないため)。実装で使う本体は S2 / S5 のコードである。
 
-### 検体 31 通りの結果
+### 検体 31 通りの結果 (設計時。実装時に 4 通り増えた)
 
 正例 (P1a〜P6 の 9 通り) と取りこぼし対照 (F1〜F7 + F4b の 8 通り) は
 **すべて期待どおりの件数を検出**し、負の対照 (N1〜N14 の 14 通り) は
 **すべて 0 件**だった。**31 通りすべて期待どおり。**
 
-> **数の読み方**: S3 の「30 本」は Pest の `test()` の本数で、
-> ここでいう「31 通り」は 1 本の `test()` が複数の検体を持つ場合を展開した検体の数である
+> **数の読み方**: S3 の本数は Pest の `test()` の本数で、
+> ここでいう「通り」は 1 本の `test()` が複数の検体を持つ場合を展開した検体の数である
 > (P1 は 1 本の test で 4 語彙 4 検体を扱う)。食い違いではない。
 
+**実装時の追加**: 実装レビューの指摘を受けて、型つきクラス定数 (3 形) と
+参照を返すメソッドの宣言を実測したところ、**どちらも `php -l` が通る合法な書き方でありながら
+設計時の規則では誤検出になる**ことが分かった。R2b の新設と R3 の一般化で塞ぎ、
+負の対照 N15 / N16 と取りこぼし対照 F8 / F9 を足した (S3 は 34 本になった)。
+
 ### HEAD 全体の結果
 
 ```
@@ -1009,6 +1054,16 @@ ### HEAD 全体の結果
 blade ファイル数 = 24
 ```
 
+**実装時の実測 (本施策の新規 6 ファイルを含む worktree の HEAD)**:
+
+```
+追跡 PHP 総数 = 1573 / 走査対象 = 1558 (除外 devnotes 15)
+  app: 760 / bootstrap: 2 / config: 38 / database: 114 / devnotes: 15 (除外)
+  lang: 5 / public: 1 / resources: 24 / routes: 4 / scripts: 3 / tests: 607
+検出: scripts/ci/drop-test-db.php の echo × 23 (設計時と同一)
+走査対象の blade = 24
+```
+
 すなわち **例外の登録は 1 件で足り、本体コードの書き換えは要らない**。
 これは家系の機能台帳が事前に見積もっていた値
 (「echo 23 件が `scripts/ci/drop-test-db.php` の 1 ファイルへ集中。
diff --git a/tests/Architecture/ForbiddenStatementTokenInvariantTest.php b/tests/Architecture/ForbiddenStatementTokenInvariantTest.php
new file mode 100644
index 0000000..de5dc8f
--- /dev/null
+++ b/tests/Architecture/ForbiddenStatementTokenInvariantTest.php
@@ -0,0 +1,463 @@
+<?php
+
+declare(strict_types=1);
+
+use Symfony\Component\Process\Process;
+use Tests\Support\ForbiddenStatement\ForbiddenStatementExemption;
+use Tests\Support\ForbiddenStatement\ForbiddenStatementKind;
+use Tests\Support\ForbiddenStatement\ForbiddenStatementRootPolicy;
+use Tests\Support\ForbiddenStatement\ForbiddenStatementScanner;
+use Tests\Support\ForbiddenStatement\ForbiddenStatementSite;
+
+/*
+ * Architecture invariant: 禁止する文 (出力する文 / 飛び越す文 / 大域を持ち込む文 /
+ * 開始タグ付きの出力記法) を書かない。
+ *
+ * 設計は devnotes/20260815-1537-forbidden-statement-token-gate/ が正本。
+ * 家系の機能台帳 (lctl feature: forbidden-statement-token-gate) の移植である。
+ *
+ * なぜ字句 (トークン) 走査なのか: pest-plugin-arch はクラス / 関数の参照しか見えず、
+ * これらは「文」なので原理的に拾えない。既製 preset の同名規則は構文木の扱い上ほぼ働かない。
+ *
+ * ★**隣接 gate との関係 (統合しない)**: `NoNonCompoundGlobalUseTest` は
+ *   「namespace 宣言の無いファイルの非複合 use」という別の不変条件を、
+ *   `*.blade.php` を除いた母集団に対して見る。本 gate は blade を**含めて**走査する
+ *   (開始タグで開いた区間が見えるため。除外すると開始タグ付き出力記法の禁止に穴が残る)。
+ *   母集団が違うので `Tests\Support\TrackedPhpSourceFiles` は共用しない —
+ *   同クラスの docblock は blade を「免除ではなく規則の段階で対象外」と宣言しており、
+ *   そこを広げると既存 2 gate の走査域が黙って変わる。列挙の**作法**だけを揃える。
+ *
+ * ★**保証範囲を誇張しない**: 効くのは字句として現れる 4 語彙だけである。
+ *   名前の解決が要る出力 (書式つき出力 / 変数の内容の表示 / 標準出力への書き込み) や、
+ *   テンプレートの地の文に埋め込まれた区間には**無言で効かない**
+ *   (限界の完全な記述は `ForbiddenStatementScanner` の docblock が正本)。
+ *
+ * ★**この gate は「素の main では赤にならない」種類のテストである。**
+ *   空振りしていないことは (a) `tests/Unit/Architecture/ForbiddenStatementScannerTest.php` の
+ *   正例 / 取りこぼし対照と、(b) 実装時に踏んだ fail-first 手順 (設計 S5 §実装時に必ず踏む手順) の
+ *   2 本で担保する。加えて G2 が走査ファイル数の床値を機械的に固定する。
+ */
+
+/** 例外・除外の根拠の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
+const FORBIDDEN_STATEMENT_REASON_MIN_LENGTH = 30;
+
+/**
+ * 例外の登録件数。**現在値ちょうど** (exact fit。`<=` ではなく `===` で照合する)。
+ * ★余裕を 1 でも持たせると、その 1 本は「個別の根拠も再レビューも無しに書ける枠」になる。
+ * ★減った場合も赤にする (登録を消したなら、この値を変える差分が要る)。
+ */
+const FORBIDDEN_STATEMENT_EXEMPTION_COUNT = 1;
+
+/**
+ * 走査対象ファイル数の床値。
+ * ★走査が空振り (0 件) でも「違反 0 件」で緑になってしまうのを止める。
+ *   実測 1552 (追跡 PHP 1567 − 除外 devnotes 15) に対し余裕を持たせて 1400 を置く。
+ */
+const FORBIDDEN_STATEMENT_SCANNED_FILE_FLOOR = 1400;
+
+/**
+ * 置き場所の分類 (単一の出典)。
+ *
+ * ★どれにも分類していない置き場所が現れたら G4 が赤になる。走査根を列挙するだけにすると、
+ *   新しいディレクトリを足したときに**黙って走査対象から外れる**。
+ *
+ * @return array<string, array{ForbiddenStatementRootPolicy, string}>
+ *                                                                    キーは最上位ディレクトリ名 (リポジトリ直下は空文字列)。
+ *                                                                    第 2 要素は理由 (ScannedNoExemption は空文字列でよい)。
+ */
+function forbiddenStatementRootPolicies(): array
+{
+    return [
+        '' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
+        'app' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
+        'bootstrap' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
+        'config' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
+        'database' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
+        'lang' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
+        'public' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
+        'resources' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
+
+        'routes' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
+        'scripts' => [
+            ForbiddenStatementRootPolicy::ScannedWithExemption,
+            'artisan を通さず別プロセスで起動される運用スクリプトが置かれる。'
+            .'標準出力が人間への唯一の伝達手段になる場合がある。',
+        ],
+        'tests' => [
+            ForbiddenStatementRootPolicy::ScannedWithExemption,
+            '別プロセスで起動される検体が置かれる。親プロセスへ結果を返す手段が'
+            .'標準出力しかない場合がある。',
+        ],
+        'devnotes' => [
+            ForbiddenStatementRootPolicy::Excluded,
+            '設計時の調査に使う一時スクリプトの置き場所であり (AGENTS.md「一時スクリプトは '
+            .'devnotes へ」)、アプリの実行経路にも CI にも載らない。恒久化するときは '
+            .'scripts/ へ移すので、そこで本 gate の対象になる。',
+        ],
+    ];
+}
+
+/**
+ * 禁止する文を書くことが正しいと裁定したファイルの目録
+ * (型付き + 具体的根拠必須 + 件数の完全一致、単一の出典)。
+ *
+ * ★**例外に登録されたファイルも全語彙を走査する** (skip しない)。差し引けるのは
+ *   ここに登録した (パス, 語彙) の組だけで、登録の無い語彙が現れたら 1 件残らず違反になる。
+ *
+ * @return array<string, array{
+ *     exemption: ForbiddenStatementExemption,
+ *     counts: array<string, int>,
+ *     reason: non-empty-string,
+ * }> counts のキーは ForbiddenStatementKind の値
+ */
+function forbiddenStatementExemptions(): array
+{
+    return [
+        'scripts/ci/drop-test-db.php' => [
+            'exemption' => ForbiddenStatementExemption::StandaloneCliStdout,
+            'counts' => [ForbiddenStatementKind::EchoStatement->value => 23],
+            'reason' => 'worktree のテスト DB を回収する運用スクリプト。artisan を通さない素の PHP '
+                .'として php scripts/ci/drop-test-db.php で起動され、Laravel の Console 出力機構を'
+                .'持たない。既定 dry-run の分類結果を人間へ提示することがこのスクリプトの機能そのもの'
+                .'であり、HTTP 応答の組み立て経路には載らない。',
+        ],
+    ];
+}
+
+/**
+ * 相対パスの最上位ディレクトリ名 (リポジトリ直下は空文字列)。
+ */
+function forbiddenStatementRootOf(string $relative): string
+{
+    $slash = strpos($relative, '/');
+
+    return $slash === false ? '' : substr($relative, 0, $slash);
+}
+
+/**
+ * git 追跡下の `*.php` を列挙する (`*.blade.php` を含む)。
+ *
+ * ★git が無い / 失敗した場合は**例外で落とす**。silent skip にすると
+ *   「走査していないのに緑」になる (`NoNonCompoundGlobalUseTest` と同じ判断)。
+ * ★既知の限界: 未追跡 (git add 前) のファイルは走査されない。gate が守る境界は
+ *   commit / CI であり、そこでは必ず追跡下にある。
+ *
+ * @return list<array{absolute: string, relative: string}> relative の昇順
+ */
+function forbiddenStatementTrackedFiles(): array
+{
+    $root = base_path();
+    $process = new Process(['git', 'ls-files', '-z', '--', '*.php'], $root);
+    $process->run();
+
+    if (! $process->isSuccessful()) {
+        throw new RuntimeException(
+            'git ls-files の実行に失敗しました (git worktree 前提の architecture invariant): '
+            .$process->getErrorOutput()
+        );
+    }
+
+    $files = [];
+    foreach (explode("\0", $process->getOutput()) as $relative) {
+        if ($relative === '') {
+            continue;
+        }
+        $absolute = $root.'/'.$relative;
+        if (! is_file($absolute)) {
+            continue; // 削除済みだが index に残っている等
+        }
+        $files[] = ['absolute' => $absolute, 'relative' => $relative];
+    }
+
+    usort($files, fn (array $a, array $b): int => strcmp($a['relative'], $b['relative']));
+
+    return $files;
+}
+
+/**
+ * 走査の実行結果 (12 本のテストで共有するため 1 度だけ計算する)。
+ *
+ * @return array{
+ *     trackedTotal: int,
+ *     scannedTotal: int,
+ *     scannedBladeTotal: int,
+ *     trackedRoots: list<string>,
+ *     perRoot: array<string, array{tracked: int, scanned: bool, classified: bool}>,
+ *     sites: list<ForbiddenStatementSite>,
+ * }
+ */
+function forbiddenStatementScanResult(): array
+{
+    /** @var array{trackedTotal: int, scannedTotal: int, scannedBladeTotal: int, trackedRoots: list<string>, perRoot: array<string, array{tracked: int, scanned: bool, classified: bool}>, sites: list<ForbiddenStatementSite>}|null $cache */
+    static $cache = null;
+    if ($cache !== null) {
+        return $cache;
+    }
+
+    $policies = forbiddenStatementRootPolicies();
+
+    $trackedTotal = 0;
+    $scannedTotal = 0;
+    $scannedBladeTotal = 0;
+    /** @var array<string, array{tracked: int, scanned: bool, classified: bool}> $perRoot */
+    $perRoot = [];
+    /** @var list<ForbiddenStatementSite> $sites */
+    $sites = [];
+
+    foreach (forbiddenStatementTrackedFiles() as $file) {
+        $trackedTotal++;
+        $root = forbiddenStatementRootOf($file['relative']);
+
+        // 未分類の置き場所は走査しない (G4 が別途赤にする)。ここで走査してしまうと
+        // 「分類漏れなのに緑」という状態が作れてしまう。
+        $policy = $policies[$root][0] ?? null;
+        $scanned = $policy === null ? false : match ($policy) {
+            ForbiddenStatementRootPolicy::ScannedNoExemption,
+            ForbiddenStatementRootPolicy::ScannedWithExemption => true,
+            ForbiddenStatementRootPolicy::Excluded => false,
+        };
+
+        $perRoot[$root] ??= ['tracked' => 0, 'scanned' => $scanned, 'classified' => $policy !== null];
+        $perRoot[$root]['tracked']++;
+
+        if (! $scanned) {
+            continue;
+        }
+
+        // ★読み取り失敗を skip にしない。git 追跡下のファイルが読めないのは環境異常であり、
+        //   黙って走査から落とすと「走査していないのに緑」になる (fail-closed)。
+        $source = file_get_contents($file['absolute']);
+        if (! is_string($source)) {
+            throw new RuntimeException(
+                '走査対象の読み取りに失敗しました: '.$file['relative']
+                .' (環境異常。silent skip にすると走査していないのに緑になる)'
+            );
+        }
+
+        $scannedTotal++;
+        if (str_ends_with($file['relative'], '.blade.php')) {
+            $scannedBladeTotal++;
+        }
+
+        $sites = array_merge($sites, ForbiddenStatementScanner::sites($file['relative'], $source));
+    }
+
+    ksort($perRoot);
+
+    $cache = [
+        'trackedTotal' => $trackedTotal,
+        'scannedTotal' => $scannedTotal,
+        'scannedBladeTotal' => $scannedBladeTotal,
+        'trackedRoots' => array_keys($perRoot),
+        'perRoot' => $perRoot,
+        'sites' => $sites,
+    ];
+
+    return $cache;
+}
+
+/**
+ * 検出された site を `パス|語彙` で数える。
+ *
+ * @param  list<ForbiddenStatementSite>  $sites
+ * @return array<string, int>
+ */
+function forbiddenStatementCountByPathAndKind(array $sites): array
+{
+    $counts = [];
+    foreach ($sites as $site) {
+        $key = $site->path.'|'.$site->kind->value;
+        $counts[$key] = ($counts[$key] ?? 0) + 1;
+    }
+
+    return $counts;
+}
+
+// ---------------------------------------------------------------------------
+// G1: 違反そのもの
+// ---------------------------------------------------------------------------
+
+test('走査対象に禁止する文が存在しない (目録の登録分を除く)', function (): void {
+    $result = forbiddenStatementScanResult();
+
+    // 目録の (パス, 語彙) ごとに、登録件数を上限として差し引く。
+    // ★実測と登録が食い違う場合は G8 が落とす。ここでは負にならないよう
+    //   小さいほうを引いて「残り」を見せる。
+    $remaining = [];
+    foreach (forbiddenStatementExemptions() as $path => $entry) {
+        foreach ($entry['counts'] as $kindValue => $count) {
+            $remaining[$path.'|'.$kindValue] = $count;
+        }
+    }
+
+    $violations = [];
+    foreach ($result['sites'] as $site) {
+        $key = $site->path.'|'.$site->kind->value;
+        if (($remaining[$key] ?? 0) > 0) {
+            $remaining[$key]--;
+
+            continue;
+        }
+        $violations[] = $site->describe();
+    }
+
+    expect($violations)->toBe([],
+        '禁止する文を検出しました。'.PHP_EOL
+        .implode(PHP_EOL, $violations).PHP_EOL
+        .'応答の組み立ては Inertia / JsonResource / Response で行ってください。'
+        .'どうしても必要なら forbiddenStatementExemptions() へ理由付きで登録してください '
+        .'(登録できるのは scripts / tests のみ)。');
+});
+
+// ---------------------------------------------------------------------------
+// G2 / G3: 走査が空振りしていないこと
+// ---------------------------------------------------------------------------
+
+test('走査が空振りしていない (走査ファイル数が床値以上)', function (): void {
+    $result = forbiddenStatementScanResult();
+
+    $breakdown = [];
+    foreach ($result['perRoot'] as $root => $info) {
+        $label = $root === '' ? '(直下)' : $root;
+        // ★「分類の上で除外した」のと「分類漏れで走査外になった」のを言い分ける。
+        //   後者は G4 が別途赤にするが、床値割れの原因を 1 目で読めるようにする。
+        $state = match (true) {
+            $info['scanned'] => '(走査)',
+            $info['classified'] => '(除外)',
+            default => '(未分類→走査外!)',
+        };
+        $breakdown[] = $label.'='.$info['tracked'].$state;
+    }
+
+    $message = '走査対象が床値 ('.FORBIDDEN_STATEMENT_SCANNED_FILE_FLOOR.') を下回りました: '
+        .'走査 '.$result['scannedTotal'].' 件'.PHP_EOL
+        .'  追跡 PHP 総数: '.$result['trackedTotal'].' 件'.PHP_EOL
+        .'  除外された数: '.($result['trackedTotal'] - $result['scannedTotal']).' 件'.PHP_EOL
+        .'  置き場所ごとの内訳: '.implode(' ', $breakdown).PHP_EOL
+        .'分類 (forbiddenStatementRootPolicies) が意図せず除外側へ倒れていないか確認してください。';
+
+    expect($result['scannedTotal'])->toBeGreaterThan(0, $message);
+    expect($result['scannedTotal'])->toBeGreaterThanOrEqual(FORBIDDEN_STATEMENT_SCANNED_FILE_FLOOR, $message);
+});
+
+test('テンプレート (blade) も走査している', function (): void {
+    $result = forbiddenStatementScanResult();
+
+    expect($result['scannedBladeTotal'])->toBeGreaterThan(0,
+        'blade が 1 件も走査されていません。開始タグで開いた区間を見るために '
+        .'resources を走査対象から外さないでください。');
+});
+
+// ---------------------------------------------------------------------------
+// G4 / G5 / G6: 置き場所の分類
+// ---------------------------------------------------------------------------
+
+test('追跡 PHP の置き場所がすべて分類済みである', function (): void {
+    $result = forbiddenStatementScanResult();
+    $classified = array_keys(forbiddenStatementRootPolicies());
+
+    $unclassified = array_values(array_diff($result['trackedRoots'], $classified));
+
+    expect($unclassified)->toBe([],
+        '分類されていない置き場所が見つかりました: '.implode(', ', $unclassified).PHP_EOL
+        .'forbiddenStatementRootPolicies() へ「走査する / 例外可 / 除外 (理由必須)」の'
+        .'いずれかで登録してください (未分類は黙って走査対象から外れます)。');
+});
+
+test('除外の登録が形骸化していない (除外した置き場所に追跡 PHP が実在する)', function (): void {
+    $result = forbiddenStatementScanResult();
+
+    foreach (forbiddenStatementRootPolicies() as $root => [$policy, $reason]) {
+        if ($policy !== ForbiddenStatementRootPolicy::Excluded) {
+            continue;
+        }
+
+        // ★`toContain()` は可変長で「すべて含む」を検査するため、第 2 引数へ説明文を渡すと
+        //   説明文そのものが needle になる。真偽値へ落としてから検査する。
+        expect(in_array($root, $result['trackedRoots'], true))->toBeTrue(
+            "除外に登録した置き場所 {$root} に追跡 PHP がありません。登録を外してください。");
+    }
+});
+
+test('除外と例外可の置き場所に 30 文字以上の理由がある', function (): void {
+    foreach (forbiddenStatementRootPolicies() as $root => [$policy, $reason]) {
+        $needsReason = match ($policy) {
+            ForbiddenStatementRootPolicy::ScannedWithExemption,
+            ForbiddenStatementRootPolicy::Excluded => true,
+            ForbiddenStatementRootPolicy::ScannedNoExemption => false,
+        };
+        if (! $needsReason) {
+            continue;
+        }
+
+        expect(mb_strlen($reason))->toBeGreaterThanOrEqual(FORBIDDEN_STATEMENT_REASON_MIN_LENGTH,
+            "置き場所 {$root} の理由が短すぎます (".FORBIDDEN_STATEMENT_REASON_MIN_LENGTH.' 文字以上)。');
+    }
+});
+
+// ---------------------------------------------------------------------------
+// G7〜G12: 例外の目録
+// ---------------------------------------------------------------------------
+
+test('例外の登録先ファイルが実在する', function (): void {
+    foreach (forbiddenStatementExemptions() as $path => $entry) {
+        expect(file_exists(base_path($path)))->toBeTrue(
+            "例外に登録されたファイルが存在しません: {$path} (登録を外してください)");
+    }
+});
+
+test('例外の実測件数が登録件数と完全一致する', function (): void {
+    $measured = forbiddenStatementCountByPathAndKind(forbiddenStatementScanResult()['sites']);
+
+    foreach (forbiddenStatementExemptions() as $path => $entry) {
+        foreach ($entry['counts'] as $kindValue => $count) {
+            expect($measured[$path.'|'.$kindValue] ?? 0)->toBe($count,
+                "例外の実測件数が登録と一致しません: {$path} の {$kindValue} は登録 {$count} 件、"
+                .'実測 '.($measured[$path.'|'.$kindValue] ?? 0).' 件です。'
+                .'増減はどちらも再レビューが要ります (目録の件数を更新するか、文を消してください)。');
+        }
+    }
+});
+
+test('例外の根拠が 30 文字以上である', function (): void {
+    foreach (forbiddenStatementExemptions() as $path => $entry) {
+        expect(mb_strlen($entry['reason']))->toBeGreaterThanOrEqual(FORBIDDEN_STATEMENT_REASON_MIN_LENGTH,
+            "例外 {$path} の根拠が短すぎます (".FORBIDDEN_STATEMENT_REASON_MIN_LENGTH.' 文字以上)。');
+    }
+});
+
+test('例外の登録件数が現在値と完全一致する', function (): void {
+    expect(count(forbiddenStatementExemptions()))->toBe(FORBIDDEN_STATEMENT_EXEMPTION_COUNT,
+        '例外を増やす / 減らすには FORBIDDEN_STATEMENT_EXEMPTION_COUNT を変える差分が要ります '
+        .'(再レビューの強制)。');
+});
+
+test('例外を登録できるのは例外可の置き場所だけである', function (): void {
+    $policies = forbiddenStatementRootPolicies();
+
+    foreach (forbiddenStatementExemptions() as $path => $entry) {
+        $root = forbiddenStatementRootOf($path);
+
+        expect($policies[$root][0] ?? null)->toBe(ForbiddenStatementRootPolicy::ScannedWithExemption,
+            "例外を登録できない置き場所です: {$path} (例外可は scripts / tests のみ)。");
+    }
+});
+
+test('例外の登録内容そのものが正しい', function (): void {
+    $kindValues = array_map(
+        static fn (ForbiddenStatementKind $kind): string => $kind->value,
+        ForbiddenStatementKind::cases()
+    );
+
+    foreach (forbiddenStatementExemptions() as $path => $entry) {
+        expect($entry['counts'])->not->toBe([], "例外 {$path} の件数が空です。");
+
+        foreach ($entry['counts'] as $kindValue => $count) {
+            // ★`toContain()` の第 2 引数は説明文ではなく追加の needle になるため使わない。
+            expect(in_array($kindValue, $kindValues, true))->toBeTrue(
+                "例外 {$path} に未知の語彙キーがあります: {$kindValue}");
+            expect($count)->toBeGreaterThanOrEqual(1,
+                "例外 {$path} の {$kindValue} が 1 件未満です (0 件の登録は痕跡なので外してください)。");
+        }
+    }
+});
diff --git a/tests/Support/ForbiddenStatement/ForbiddenStatementExemption.php b/tests/Support/ForbiddenStatement/ForbiddenStatementExemption.php
new file mode 100644
index 0000000..a0a4506
--- /dev/null
+++ b/tests/Support/ForbiddenStatement/ForbiddenStatementExemption.php
@@ -0,0 +1,33 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\ForbiddenStatement;
+
+/**
+ * 「禁止する文をそこに書くことが正しい」と裁定した理由の分類。
+ *
+ * `tests/Architecture/ForbiddenStatementTokenInvariantTest.php` が deny-by-default で
+ * 「禁止する文を持つファイルは本 enum + 30 文字以上の具体的根拠 + 件数付きで
+ *  目録に登録済みであること」を機械強制する。
+ *
+ * ★case は「汎用に見えるものほど適用条件を狭く」定義する。
+ *   当てはまる case が無ければ、それは「書いてはいけない箇所」である。
+ * ★case を 1 つしか持たないのは意図的 (今必要なものだけ作る)。
+ *   2 つ目が現れたときに「新しい case を足す差分」として必ず表面化し、
+ *   その場で「そもそも書くべきか」を再検討させるのが狙い
+ *   (`Tests\Support\Security\StrayHttpEgressExemption` と同じ作法)。
+ */
+enum ForbiddenStatementExemption: string
+{
+    /**
+     * artisan を通さない素の PHP として起動される CLI の、人間向け標準出力。
+     *
+     * 適用条件 (すべて満たすこと):
+     *  - `php <path>` として**別プロセスで直接**起動される (HTTP 応答の経路に載らない)
+     *  - Laravel の Console 出力機構 (`$this->line()` 等) を持たない
+     *    (持てるなら `Command` にすべきで、例外にはしない)
+     *  - 標準出力への提示がそのスクリプトの機能そのものである
+     */
+    case StandaloneCliStdout = 'standalone_cli_stdout';
+}
diff --git a/tests/Support/ForbiddenStatement/ForbiddenStatementKind.php b/tests/Support/ForbiddenStatement/ForbiddenStatementKind.php
new file mode 100644
index 0000000..d11128a
--- /dev/null
+++ b/tests/Support/ForbiddenStatement/ForbiddenStatementKind.php
@@ -0,0 +1,67 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\ForbiddenStatement;
+
+/**
+ * 字句 (トークン) として禁止する文の語彙。
+ *
+ * ★正典 (lctl feature: forbidden-statement-token-gate) の v1 が定める 3 つ
+ *   (出力する文 / 飛び越す文 / 大域を持ち込む文) に、テンプレート実装が唯一の拡張として持つ
+ *   開始タグ付きの出力記法を加えた **4 つに限る**。
+ * ★`print` は正典が明示的に対象外としており、**禁止語彙の拡張は台帳の議題として
+ *   起こす決まり**になっている。ここで勝手に足さない。
+ * ★case 名に半予約語 (`Echo` 等) を使わないのは意図的である。
+ *   本 enum 自身が走査対象 (`tests/`) に置かれるため、case 名を `Echo` にすると
+ *   本ファイルが該当トークンを含むことになり、読み飛ばし規則に依存して緑になる。
+ *   検査の正しさを検査対象自身の書き方に依存させない。
+ */
+enum ForbiddenStatementKind: string
+{
+    /** 応答の組み立て経路を迂回して直接出力へ書き出す文。 */
+    case EchoStatement = 'echo';
+
+    /** 開始タグ付きの出力記法。上と同じことを別の綴りで行う。 */
+    case ShortEchoTag = 'short_echo_tag';
+
+    /** 任意の位置へ飛び、構造から制御フローが読めなくなる文。 */
+    case GotoStatement = 'goto';
+
+    /** DI コンテナ経由の依存解決を迂回し、差し替えられない結合を作る文。 */
+    case GlobalStatement = 'global';
+
+    /**
+     * トークン ID から語彙を引く (該当しなければ null)。
+     *
+     * ★**網羅 `match` で書き、到達不能な分岐を作らない**。
+     *   写像が全 case を覆っていることは走査器の自己検査が固定する。
+     */
+    public static function fromTokenId(?int $tokenId): ?self
+    {
+        return match ($tokenId) {
+            T_ECHO => self::EchoStatement,
+            T_OPEN_TAG_WITH_ECHO => self::ShortEchoTag,
+            T_GOTO => self::GotoStatement,
+            T_GLOBAL => self::GlobalStatement,
+            default => null,
+        };
+    }
+
+    /** 読み飛ばし規則の適用対象か (開始タグ付き出力記法は文脈を持たないので対象外)。 */
+    public function needsContextCheck(): bool
+    {
+        return $this !== self::ShortEchoTag;
+    }
+
+    /** 失敗メッセージ用の表示名。 */
+    public function label(): string
+    {
+        return match ($this) {
+            self::EchoStatement => 'echo 文',
+            self::ShortEchoTag => '開始タグ付きの出力記法',
+            self::GotoStatement => 'goto 文',
+            self::GlobalStatement => 'global 文',
+        };
+    }
+}
diff --git a/tests/Support/ForbiddenStatement/ForbiddenStatementRootPolicy.php b/tests/Support/ForbiddenStatement/ForbiddenStatementRootPolicy.php
new file mode 100644
index 0000000..ea58847
--- /dev/null
+++ b/tests/Support/ForbiddenStatement/ForbiddenStatementRootPolicy.php
@@ -0,0 +1,24 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\ForbiddenStatement;
+
+/**
+ * 追跡されている PHP の置き場所を、禁止する文の検査に対してどう扱うかの分類。
+ *
+ * ★**3 つは排他**であり、どれにも分類していない置き場所が現れたら gate は赤になる。
+ *   走査根を検査ファイルへ列挙するだけにすると、新しいディレクトリを足したときに
+ *   **黙って走査対象から外れる**。
+ */
+enum ForbiddenStatementRootPolicy: string
+{
+    /** 走査する。例外の登録を一切許さない (アプリの実行経路そのもの)。 */
+    case ScannedNoExemption = 'scanned_no_exemption';
+
+    /** 走査する。理由付きの例外登録を許す (別プロセスで走る CLI と検体)。 */
+    case ScannedWithExemption = 'scanned_with_exemption';
+
+    /** 走査しない。理由の記載が必須。 */
+    case Excluded = 'excluded';
+}
diff --git a/tests/Support/ForbiddenStatement/ForbiddenStatementScanner.php b/tests/Support/ForbiddenStatement/ForbiddenStatementScanner.php
new file mode 100644
index 0000000..157bd78
--- /dev/null
+++ b/tests/Support/ForbiddenStatement/ForbiddenStatementScanner.php
@@ -0,0 +1,154 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\ForbiddenStatement;
+
+use Tests\Support\PhpTokenScan;
+
+/**
+ * PHP ソースから「禁止する文」の出現位置を列挙する純関数。
+ *
+ * ★走査は既存の `Tests\Support\PhpTokenScan::normalize()`
+ *   (空白 / コメント / DocComment を除いた添字連番のリスト) の上で行う。
+ *   **同じ正規化を 2 本持たない**。
+ * ★**何を禁止と呼ぶかは `ForbiddenStatementKind` が持ち、どこを走査するかは
+ *   gate が持つ**。この走査器はどちらも知らない。
+ *
+ * ★**保証しないもの (誇張しない)**:
+ *   - 名前の解決が要る出力 (書式つき出力 / 変数の内容の表示 / 標準出力への書き込み /
+ *     文字列に入れた綴りを変数経由で呼ぶ形) には**沈黙する**。この検査は完全性を主張しない
+ *   - Blade の `@php … @endphp` と二重波括弧の中は `token_get_all()` からは
+ *     地の文 (`T_INLINE_HTML`) に見えるため届かない。
+ *     **PHP 開始タグで開いた区間は見える** (実測)
+ *   - ヒアドキュメント / ナウドキュメントの本文は 1 つの
+ *     `T_ENCAPSED_AND_WHITESPACE` になり、中の綴りは見えない (実測)。
+ *     これは本走査器の自己検査ファイルが自分自身を違反にしない理由でもある
+ */
+final class ForbiddenStatementScanner
+{
+    /**
+     * 直前がこれなら無条件に名前位置とみなす (R1)。
+     *
+     * ★二重コロンは「直後に名前しか置けない」ことが PHP の文法から言えるので、
+     *   直後の条件を課さなくても十分に狭い。逆に直後に来られるトークンの種類が
+     *   多い (`(` `;` `,` `)` `=` 名前空間の区切り …) ため、列挙するとかえって穴を作る。
+     * ★**属性のための規則は持たない**。属性名に予約語は書けず
+     *   (実測: 属性名に出力する文の綴りを置くと Parse error)、属性の中で綴りが現れうるのは
+     *   名前つき引数だけで、それは R6 が扱うためである。
+     *   成立しない書き方のために規則を置くと検出力を無償で捨てることになる。
+     *
+     * @var list<int>
+     */
+    private const array NAME_ONLY_PREDECESSORS = [
+        T_DOUBLE_COLON,   // 静的呼び出し / クラス定数の取得 / 第一級呼び出し可能 / トレイト取り込みの元メソッド指定 / 場合分けの値
+    ];
+
+    /**
+     * 直前がこれらなら、**直後が指定のトークンのときに限り**名前位置とみなす
+     * (R2 / R4 / R7)。
+     *
+     * ★字句走査は構文の妥当性を保証しないので、規則は狭いほどよい。
+     *   直前だけで判定すると「構文として成立しない断片」でも黙ることになる。
+     * ★可視性修飾子が現れるのは**トレイト取り込みの別名指定だけ**である
+     *   (通常の宣言では間に `function` が入るので R2 になる)。
+     * ★`T_CASE` の直後に単独のコロンを許さない。素の予約語は場合分けの値に書けず
+     *   (実測: 定数として定義しても場合分けの値に素の綴りは置けず Parse error)、
+     *   クラス定数経由の形は R1 が扱うためである。
+     *
+     * @var array<int, list<string>> トークン ID => 直後に許す単一文字トークン
+     */
+    private const array NAME_POSITION_PREDECESSORS = [
+        T_FUNCTION => ['('],      // クラス / インターフェースのメソッド宣言
+        T_CASE => ['=', ';'],     // 列挙の場合分け (値つき / 値なし)
+        T_AS => [';'],            // トレイト取り込みの別名指定
+        T_PUBLIC => [';'],        // トレイト取り込みの別名指定 (可視性つき)
+        T_PROTECTED => [';'],     // 同上
+        T_PRIVATE => [';'],       // 同上
+    ];
+
+    /**
+     * @return list<ForbiddenStatementSite>
+     */
+    public static function sites(string $relativePath, string $phpSource): array
+    {
+        $tokens = PhpTokenScan::normalize($phpSource);
+        $count = count($tokens);
+
+        // R3 用。`T_CONST` からセミコロンまでの定数宣言区間だけ、
+        // 直後が代入記号の綴りを名前位置とみなす。
+        $inConstDeclaration = false;
+
+        $sites = [];
+        for ($i = 0; $i < $count; $i++) {
+            if ($tokens[$i]['id'] === T_CONST) {
+                $inConstDeclaration = true;
+            } elseif ($tokens[$i]['id'] === null && $tokens[$i]['text'] === ';') {
+                $inConstDeclaration = false;
+            }
+
+            $kind = ForbiddenStatementKind::fromTokenId($tokens[$i]['id']);
+            if ($kind === null) {
+                continue;
+            }
+
+            if ($kind->needsContextCheck() && self::isNamePosition($tokens, $i, $inConstDeclaration)) {
+                continue;
+            }
+
+            $sites[] = new ForbiddenStatementSite($relativePath, $tokens[$i]['line'], $kind);
+        }
+
+        return $sites;
+    }
+
+    /**
+     * 綴りが「文」ではなく「名前」として置かれている位置か。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    private static function isNamePosition(array $tokens, int $index, bool $inConstDeclaration): bool
+    {
+        $previous = $tokens[$index - 1] ?? null;
+        $previousId = $previous['id'] ?? null;
+        $next = $tokens[$index + 1] ?? null;
+        // 単一文字トークンは `id === null` で表現される (PhpTokenScan::normalize の契約)
+        $nextChar = $next !== null && $next['id'] === null ? $next['text'] : null;
+
+        // R1: 直後に名前しか置けない位置
+        if ($previousId !== null && in_array($previousId, self::NAME_ONLY_PREDECESSORS, true)) {
+            return true;
+        }
+
+        // R2 / R4 / R7: 直前と直後の組で狭める
+        $allowedNext = $previousId === null ? null : (self::NAME_POSITION_PREDECESSORS[$previousId] ?? null);
+        if ($allowedNext !== null && $nextChar !== null && in_array($nextChar, $allowedNext, true)) {
+            return true;
+        }
+
+        // R2b: 参照を返すメソッドの宣言 (`function &echo(): mixed`)。
+        //      直前が参照の記号で、その 1 つ前が `function`、直後が開き括弧のときだけ。
+        //      ★`&` は `T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG` になる (実測)。
+        if ($previousId === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG
+            && ($tokens[$index - 2]['id'] ?? null) === T_FUNCTION
+            && $nextChar === '(') {
+            return true;
+        }
+
+        // R3: 定数宣言の区間 (`const` からセミコロンまで) で直後が代入記号なら定数名。
+        //     ★直前のトークンで狭めない。型つきクラス定数 (`const string echo = 'x';` /
+        //       `const ?string goto = null;` / `const A|string global = 'x';`) では
+        //       直前が `T_CONST` ではなく型の綴りになるため (実測)。
+        //     ★読点で繋いだ 2 つ目以降 (`const echo = 1, goto = 2;`) も同じ規則で覆う。
+        //     ★定数の初期化式に文は書けない (PHP の定数式の制限) ので、この区間を
+        //       名前位置扱いしても本物の文を取りこぼさない。配列リテラルの読点
+        //       (`const X = [1, 2], Y = 3;`) は直後が代入記号にならないため一致しない。
+        if ($inConstDeclaration && $nextChar === '=') {
+            return true;
+        }
+
+        // R6: 名前つき引数は直後が単独のコロンになる。
+        //     二重コロンは 1 つの `T_DOUBLE_COLON` トークンなので、ここには一致しない。
+        return $nextChar === ':';
+    }
+}
diff --git a/tests/Support/ForbiddenStatement/ForbiddenStatementSite.php b/tests/Support/ForbiddenStatement/ForbiddenStatementSite.php
new file mode 100644
index 0000000..b3df661
--- /dev/null
+++ b/tests/Support/ForbiddenStatement/ForbiddenStatementSite.php
@@ -0,0 +1,27 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\ForbiddenStatement;
+
+/**
+ * 禁止する文が 1 つ見つかった位置 (走査器に依存しない中立表現)。
+ *
+ * ★既存の `Tests\Support\ReferenceSite` と同じ作法 (readonly の値オブジェクト)。
+ */
+final readonly class ForbiddenStatementSite
+{
+    public function __construct(
+        /** リポジトリルートからの相対パス */
+        public string $path,
+        /** 1 起点の行番号 */
+        public int $line,
+        public ForbiddenStatementKind $kind,
+    ) {}
+
+    /** 失敗メッセージ用の 1 行表現。 */
+    public function describe(): string
+    {
+        return "{$this->path}:{$this->line} → {$this->kind->label()}";
+    }
+}
diff --git a/tests/Unit/Architecture/ForbiddenStatementScannerTest.php b/tests/Unit/Architecture/ForbiddenStatementScannerTest.php
new file mode 100644
index 0000000..9ebfd1b
--- /dev/null
+++ b/tests/Unit/Architecture/ForbiddenStatementScannerTest.php
@@ -0,0 +1,424 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\ForbiddenStatement\ForbiddenStatementKind;
+use Tests\Support\ForbiddenStatement\ForbiddenStatementScanner;
+
+/*
+ * `ForbiddenStatementScanner` の自己検査 (正例 / 負の対照 / 取りこぼし対照)。
+ *
+ * ★gate 本体 (`tests/Architecture/ForbiddenStatementTokenInvariantTest.php`) は
+ *   「素の main では赤にならない」種類のテストである。空振りしていないことは
+ *   本ファイルの正例と取りこぼし対照が担保する。
+ *
+ * ★**検体はすべてナウドキュメント文字列で書く**。ナウドキュメント本文は
+ *   `T_START_HEREDOC` / `T_ENCAPSED_AND_WHITESPACE` / `T_END_HEREDOC` になり、
+ *   本文中の綴りにトークン種別が割り当てられない (下の N10 が実測として固定する)。
+ *   したがって**本ファイル自身は gate の走査対象でありながら違反にならず、
+ *   例外へ登録する必要が無い**。逆に検体を「実行される PHP コード」として書くと
+ *   自分が違反になる。
+ *
+ * ★**全検体は構文として成立する PHP である** (実装時に `php -l` で 1 件ずつ確認済み)。
+ *   半予約語をメンバ名に使えるのはクラス / 列挙の中だけなので、文脈を切り落とした断片は
+ *   実在しない書き方になり「その規則が現実の誤検出を防いでいる」ことの証明にならない。
+ *   検体ごとに `php -l` を起動する自動検査は作らない (本題である走査器の検出力と
+ *   関係のないコストになるため)。
+ */
+
+// ---------------------------------------------------------------------------
+// 正例 — 検出できること
+// ---------------------------------------------------------------------------
+
+test('4 つの語彙をそれぞれ単独で検出する', function (): void {
+    $specimens = [
+        ForbiddenStatementKind::EchoStatement->value => <<<'PHP'
+        <?php echo "x";
+        PHP,
+        ForbiddenStatementKind::GotoStatement->value => <<<'PHP'
+        <?php goto end; end: $x = 1;
+        PHP,
+        ForbiddenStatementKind::GlobalStatement->value => <<<'PHP'
+        <?php global $x;
+        PHP,
+        ForbiddenStatementKind::ShortEchoTag->value => <<<'PHP'
+        <?= $x ?>
+        PHP,
+    ];
+
+    foreach ($specimens as $expected => $specimen) {
+        $sites = ForbiddenStatementScanner::sites('fixture.php', $specimen);
+
+        expect($sites)->toHaveCount(1, "検体 {$expected} が 1 件で検出されていません");
+        expect($sites[0]->kind->value)->toBe($expected);
+    }
+});
+
+test('1 つの断片に複数あればすべて検出する', function (): void {
+    $specimen = <<<'PHP'
+    <?php
+    function f(): void { echo "a"; echo "b"; }
+    function g(): void { global $x; }
+    PHP;
+
+    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toHaveCount(3);
+});
+
+test('大文字小文字を区別しない', function (): void {
+    $specimen = <<<'PHP'
+    <?php ECHO "x"; GLOBAL $y;
+    PHP;
+
+    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toHaveCount(2);
+});
+
+test('ファイル先頭の開始タグ付き出力記法を検出する', function (): void {
+    $specimen = <<<'PHP'
+    <?= $x ?>
+    PHP;
+
+    $sites = ForbiddenStatementScanner::sites('fixture.php', $specimen);
+
+    expect($sites)->toHaveCount(1);
+    expect($sites[0]->kind)->toBe(ForbiddenStatementKind::ShortEchoTag);
+    expect($sites[0]->line)->toBe(1);
+});
+
+test('行番号が正しい', function (): void {
+    $specimen = <<<'PHP'
+    <?php
+    $x = 1;
+    echo "x";
+    PHP;
+
+    $sites = ForbiddenStatementScanner::sites('fixture.php', $specimen);
+
+    expect($sites)->toHaveCount(1);
+    expect($sites[0]->line)->toBe(3);
+    expect($sites[0]->describe())->toBe('fixture.php:3 → echo 文');
+});
+
+test('テンプレート風の断片でも開始タグで開いた区間は検出する', function (): void {
+    $specimen = <<<'PHP'
+    @section('body')
+    <?= $x ?>
+    <?php echo "y"; ?>
+    @endsection
+    PHP;
+
+    $sites = ForbiddenStatementScanner::sites('fixture.blade.php', $specimen);
+
+    expect($sites)->toHaveCount(2);
+    expect($sites[0]->kind)->toBe(ForbiddenStatementKind::ShortEchoTag);
+    expect($sites[1]->kind)->toBe(ForbiddenStatementKind::EchoStatement);
+});
+
+// ---------------------------------------------------------------------------
+// 負の対照 — 検出してはいけないこと (誤検出の回避)
+// ---------------------------------------------------------------------------
+
+test('静的呼び出し / クラス定数の取得 / 第一級呼び出し可能を誤検出しない', function (): void {
+    $specimen = <<<'PHP'
+    <?php
+    Foo::goto();
+    $c = Foo::echo;
+    $f = Foo::echo(...);
+    PHP;
+
+    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
+});
+
+test('メソッド宣言を誤検出しない', function (): void {
+    $specimen = <<<'PHP'
+    <?php
+    class Foo { public function echo(): void {} public function global(): void {} }
+    PHP;
+
+    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
+});
+
+test('クラス定数の宣言を誤検出しない', function (): void {
+    $specimen = <<<'PHP'
+    <?php
+    class Foo { const echo = 1; const ECHO = 2; }
+    PHP;
+
+    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
+});
+
+test('列挙の場合分けを誤検出しない', function (): void {
+    $specimen = <<<'PHP'
+    <?php
+    enum E: string { case Echo = 'e'; case Global = 'g'; }
+    PHP;
+
+    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
+});
+
+test('クラス定数経由の場合分けの値を誤検出しない', function (): void {
+    $specimen = <<<'PHP'
+    <?php
+    class A { const ECHO = 1; }
+    switch ($x) { case A::ECHO: break; }
+    PHP;
+
+    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
+});
+
+test('属性の名前つき引数を誤検出しない', function (): void {
+    $specimen = <<<'PHP'
+    <?php
+    #[Attr(echo: 1)]
+    class Foo {}
+    PHP;
+
+    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
+});
+
+test('名前つき引数を誤検出しない', function (): void {
+    $specimen = <<<'PHP'
+    <?php
+    function f(int $global, int $goto): void {}
+    f(global: 2, goto: 3);
+    PHP;
+
+    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
+});
+
+test('オブジェクトのメソッド呼び出しを誤検出しない', function (): void {
+    $specimen = <<<'PHP'
+    <?php
+    class A { public function echo(): void {} public function global(): void {} }
+    $o = new A();
+    $o->echo();
+    $o?->global();
+    PHP;
+
+    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
+});
+
+test('コメント / DocComment / 文字列リテラルの中の綴りを誤検出しない', function (): void {
+    $specimen = <<<'PHP'
+    <?php
+    // echo "x";
+    /** goto */
+    $s = "echo global goto";
+    PHP;
+
+    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
+});
+
+/*
+ * ★この検体は「本ファイル自身が gate の走査対象でありながら違反にならない」ことの根拠でもある。
+ */
+test('ナウドキュメントの本文の綴りを誤検出しない', function (): void {
+    $specimen = <<<'PHP'
+    <?php
+    $body = <<<'INNER'
+    echo "x"; global $y;
+    INNER;
+    PHP;
+
+    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
+});
+
+test('読点で繋いだ複数のクラス定数の宣言を誤検出しない', function (): void {
+    $specimen = <<<'PHP'
+    <?php
+    class A { const echo = 1, goto = 2, global = 3; }
+    PHP;
+
+    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
+});
+
+test('トレイト取り込みの別名指定を誤検出しない', function (): void {
+    $specimen = <<<'PHP'
+    <?php
+    trait T { public function m(): void {} }
+    class A { use T { m as echo; } }
+    PHP;
+
+    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
+});
+
+test('トレイト取り込みの別名指定 (可視性つき) を誤検出しない', function (): void {
+    $specimen = <<<'PHP'
+    <?php
+    trait T { public function m(): void {} }
+    class A { use T { m as protected global; } }
+    PHP;
+
+    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
+});
+
+/*
+ * ★定数の初期化式の配列の読点を名前位置扱いしていないこと (R3b が広がりすぎていない証明)。
+ */
+test('定数の初期化式の配列の読点は名前位置にならない', function (): void {
+    $specimen = <<<'PHP'
+    <?php
+    class A { const X = [1, 2], Y = 3; }
+    PHP;
+
+    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
+});
+
+/*
+ * ★型つきクラス定数では綴りの直前が `const` ではなく**型の綴り**になる (実測)。
+ *   直前トークンだけで狭めると誤検出になるため、定数宣言の区間で判定している。
+ */
+test('型つきクラス定数の宣言を誤検出しない', function (): void {
+    $specimen = <<<'PHP'
+    <?php
+    class A {
+        public const string echo = 'x';
+        public const ?string goto = null;
+        public const A|string global = 'g';
+    }
+    PHP;
+
+    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
+});
+
+/*
+ * ★参照を返すメソッドの宣言では綴りの直前が `function` ではなく**参照の記号**になる (実測)。
+ */
+test('参照を返すメソッドの宣言を誤検出しない', function (): void {
+    $specimen = <<<'PHP'
+    <?php
+    class A { public function &echo(): mixed { $x = 1; return $x; } }
+    PHP;
+
+    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
+});
+
+// ---------------------------------------------------------------------------
+// 取りこぼし対照 — 読み飛ばし規則の近傍でも検出できること
+// ---------------------------------------------------------------------------
+
+test('無名関数の中の違反を検出する', function (): void {
+    $specimen = <<<'PHP'
+    <?php
+    $fn = function (): void { echo "x"; };
+    PHP;
+
+    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toHaveCount(1);
+});
+
+test('定数宣言の直後の違反を検出する', function (): void {
+    $specimen = <<<'PHP'
+    <?php
+    class Foo { const A = 1; }
+    echo "x";
+    PHP;
+
+    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toHaveCount(1);
+});
+
+test('場合分けの本体の違反を検出する', function (): void {
+    $specimen = <<<'PHP'
+    <?php
+    switch ($x) { case 1: echo "x"; }
+    PHP;
+
+    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toHaveCount(1);
+});
+
+test('属性付き宣言の直後の違反を検出する', function (): void {
+    $specimen = <<<'PHP'
+    <?php
+    #[Attr]
+    class Foo {}
+    echo "x";
+    PHP;
+
+    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toHaveCount(1);
+});
+
+test('属性の名前つき引数の直後の違反を検出する', function (): void {
+    $specimen = <<<'PHP'
+    <?php
+    #[Attr(echo: 1)]
+    class Foo {}
+    global $x;
+    PHP;
+
+    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toHaveCount(1);
+});
+
+test('静的呼び出しの直後の違反を検出する', function (): void {
+    $specimen = <<<'PHP'
+    <?php
+    Foo::bar();
+    echo "x";
+    PHP;
+
+    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toHaveCount(1);
+});
+
+test('名前つき引数の直後の違反を検出する', function (): void {
+    $specimen = <<<'PHP'
+    <?php
+    f(a: 1);
+    global $x;
+    PHP;
+
+    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toHaveCount(1);
+});
+
+test('型つきクラス定数の直後の違反を検出する', function (): void {
+    $specimen = <<<'PHP'
+    <?php
+    class A { public const string X = 'x'; }
+    echo "y";
+    PHP;
+
+    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toHaveCount(1);
+});
+
+test('参照を返すメソッドの本体の違反を検出する', function (): void {
+    $specimen = <<<'PHP'
+    <?php
+    class A { public function &m(): mixed { echo "x"; $x = 1; return $x; } }
+    PHP;
+
+    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toHaveCount(1);
+});
+
+test('括弧付きの出力する文も検出する', function (): void {
+    $specimen = <<<'PHP'
+    <?php
+    echo("x");
+    PHP;
+
+    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toHaveCount(1);
+});
+
+// ---------------------------------------------------------------------------
+// 写像の網羅
+// ---------------------------------------------------------------------------
+
+test('禁止する語彙は 4 件ちょうどである', function (): void {
+    // ★増やすときは家系の機能台帳の議題を先に起こすこと (正典が `print` を対象外と定めている)。
+    expect(ForbiddenStatementKind::cases())->toHaveCount(4);
+});
+
+test('トークン ID の写像が全 case を覆う', function (): void {
+    $tokenIds = [
+        ForbiddenStatementKind::EchoStatement->value => T_ECHO,
+        ForbiddenStatementKind::ShortEchoTag->value => T_OPEN_TAG_WITH_ECHO,
+        ForbiddenStatementKind::GotoStatement->value => T_GOTO,
+        ForbiddenStatementKind::GlobalStatement->value => T_GLOBAL,
+    ];
+
+    expect(array_keys($tokenIds))
+        ->toBe(array_map(static fn (ForbiddenStatementKind $kind): string => $kind->value, ForbiddenStatementKind::cases()));
+
+    foreach ($tokenIds as $value => $tokenId) {
+        expect(ForbiddenStatementKind::fromTokenId($tokenId)?->value)->toBe($value);
+    }
+
+    expect(ForbiddenStatementKind::fromTokenId(T_STRING))->toBeNull();
+    expect(ForbiddenStatementKind::fromTokenId(T_PRINT))->toBeNull();
+    expect(ForbiddenStatementKind::fromTokenId(null))->toBeNull();
+});

```

上記を踏まえて、全体判定を **APPROVED** または **CHANGES_REQUESTED** の 1 語で示してほしい。
