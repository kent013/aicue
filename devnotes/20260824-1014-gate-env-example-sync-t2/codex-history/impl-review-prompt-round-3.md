Round 2 の指摘 3 点への対応が終わったので再レビューを依頼する。

## 対応マトリクス

# 対応マトリクス: impl-review Round 2 (T256)

Codex の全体判定は **CHANGES_REQUESTED**。Critical 0 件 / Warning 2 件 (小項目 3 点)。すべて対応した。

## [Warning] `$withEntry` の `$index` が任意の `int` なので、戻り値の `list` 宣言が破れうる
- 判断: **対応する**
- 根拠: 指摘のとおり。範囲外の添字を渡すと非連続配列になり、`@return list<…>` の宣言と実態が食い違う
  (将来 tests/ を PHPStan の解析対象へ入れたときに落ちる)
- 対応内容: 戻り値を `array_values($entries)` で list へ正規化し、その理由を docblock に明記した

## [Warning] `envExampleLedgerViolations()` の docblock が「V1〜V21」のまま
- 判断: **対応する**
- 根拠: 規則 1 と規則 4 の検出力は V22〜V24 が無いと成立しない。保証機構の正本は docblock なので
  古い範囲が残ると「何が裏取りされているか」を誤読させる
- 対応内容: 「各規則の判定分岐を負のコントロール **V1〜V24** が対応表で押さえる」へ更新した
  (規則⇔ケースの対応表自体は Round 1 の修正で既に V22〜V24 を含む形にしてある)

## [Warning] 反証データセットの「複製に 1 か所だけ手を入れる」が V22 では字義どおりでない
- 判断: **対応する**
- 根拠: V22 は entry を 1 件足すのに加えて `kinds` / `classifications` の申告も揃えている。
  「1 か所」ではなく「導入する欠陥が 1 種類」が実態である
- 対応内容: 「各負例が導入する**欠陥は 1 種類だけ**である。申告件数は実件数へ合わせておき
  (V22 のように entry を足す負例では `kinds` / `classifications` も同じ数へ揃える)、
  狙った規則以外が発火しないようにする」へ書き換えた。同時発火が避けられないケースの列挙にも
  V23 を足した

## 確認結果

```
$ composer test -- --filter='EnvExampleInvariantTest'  → 61 passed / 94 assertions
$ vendor/bin/pint --test → passed
$ composer phpstan → No errors
```

## 修正差分 (Round 2 の返答以降の追加変更のみ)

```diff
diff --git a/tests/Architecture/EnvExampleInvariantTest.php b/tests/Architecture/EnvExampleInvariantTest.php
index 515d840c..5eb24659 100644
--- a/tests/Architecture/EnvExampleInvariantTest.php
+++ b/tests/Architecture/EnvExampleInvariantTest.php
@@ -2,65 +2,178 @@
 
 declare(strict_types=1);
 
+use Webmozart\Assert\Assert;
+
 /*
- * `.env.example` の不変条件 (家系の裁定 AG-007 が定めた統合形)。
+ * `.env.example` の不変条件 (家系の機能台帳 lctl の feature gate-env-example-sync の**正典 t2**)。
  *
  * このファイルは「読み物」ではなく**生きた既定値**である。3 つの経路が見本を
  * そのまま実環境にする — `composer setup` / composer.json の post-root-package-install /
  * scripts/setup-worktree.sh の復旧案内。よって見本の欠落・危険な値は
  * 「文書の不備」ではなく**実環境の不備**になる。
  *
- * 検査は 4 部品 + 2 つ:
- *   (a)   値の固定    — 行の完全一致で固定する (部分一致・コメント偽装を封鎖)
- *   (b)   キー網羅    — 必須キーを分類つきの台帳に持ち、存在を要求する (値は見ない)
+ * 検査は 4 部品 + 5 つ:
+ *   (a)   値の固定    — 実代入ちょうど 1 件 + 値の完全一致 (部分一致・コメント偽装を封鎖)
+ *   (b)   キー網羅    — 必須キーを台帳に持ち、存在を要求する (値は見ない)
  *   (c-1) 行の形式    — 非空・非コメント行は素の `KEY=` 形式のみ受理する
+ *                       (制御文字・TAB・不正 UTF-8 を含む行は形式違反)
  *   (c-2) 重複        — 代入キーが全キー一意であることを要求する
- *   + 台帳の誠実性 (二重登録・台帳内の重複の禁止)
- *   + 反証の検査 (壊した入力を合成して解析器へ食わせる)
+ *   + 台帳の誠実性 (種別・分類・由来・件数の申告の整合。**9 規則**)
+ *   + 反証の検査 (壊した入力を合成して解析器へ食わせる。識別子 `R<n>`)
+ *   + 負のコントロール (壊した台帳を合成して誠実性の検査へ食わせる。識別子 `V<n>`)
+ *   + 床の検査 (データ駆動の**駆動元が痩せたら落ちる** — 識別子の並びの完全一致と両方向の存在、
+ *              および現物の走査の母集団が空でないこと)
+ *   + 前提の固定 (テスト実行時に読まれている env が見本でないこと)
+ *
+ * ★**走査対象**: 本 gate の**主対象**は `.env.example` 1 枚である (正典 i1)。
+ *   ただし末尾の `${VAR}` の検査だけは commit 済みの見本 3 枚
+ *   (`.env.example` / `.env.bughunt.local.example` / `.env.testing`) を見る。これは正典 s11 が
+ *   「他の commit 済み env ファイルへ広げるかは、それぞれを担当する feature の判断」とした
+ *   **裁量の行使**であり、帰属のはみ出しであって過剰ではない (正典 s1 は同型のはみ出しについて
+ *   「検査能力を失わせないため撤去は求めない」と定めている)。**狭めると穴が開く** —
+ *   `tests/Architecture/BughuntEnvExampleContractTest.php` は自身の docblock で
+ *   「`${VAR}` 未解決参照の一般則は EnvExampleInvariantTest が本ファイルも含めて検査済み」と
+ *   宣言して `APP_NAME` 以外を見ていない。受け皿になる feature (正典の未決論点 q1) が
+ *   台帳に立ったらそちらへ渡す。
  *
  * ★本ファイルには**受理規則が逆向きの解析器が 2 つ同居する**。統合しない
  *   (統合すると片方の意図が壊れる):
  *
- *   |                      | envExampleParseContents (下) | collectUnresolvedEnvRefs (末尾) |
- *   |----------------------|------------------------------|---------------------------------|
- *   | 対象                 | `.env.example` の 1 枚だけ   | 見本 3 枚                       |
- *   | `export` つきの行     | **違反にする**               | 意図的に許容する                |
- *   | 先頭に空白のある代入 | **違反にする**               | 意図的に許容する                |
- *   | 見るもの             | キーと値・重複・行の形       | 値の中の `${VAR}` の解決可能性  |
+ *   |                        | envExampleParseContents (下)   | collectUnresolvedEnvRefs (末尾) |
+ *   |------------------------|--------------------------------|---------------------------------|
+ *   | 対象                   | `.env.example` の 1 枚だけ     | 見本 3 枚                       |
+ *   | `export` つきの行       | **違反にする**                 | 意図的に許容する                |
+ *   | 先頭に空白のある代入   | **違反にする**                 | 意図的に許容する                |
+ *   | 制御文字 / 不正 UTF-8   | **違反にする**                 | 見ない                          |
+ *   | 見るもの               | キーと値・重複・行の形         | 値の中の `${VAR}` の解決可能性  |
  *
  *   `.env.example` については厳しい方 (行の形式の検査) が先に赤くなるので、
  *   緩い側の許容は残り 2 枚にしか意味を持たない。
  *
- * ★保証しないもの (誇張しない): 見るのは `.env.example` の中身だけで、実行中の `.env`・
- *   プロセスの環境変数・設定キャッシュには**無言で効かない**。キー網羅は存在だけを見る
- *   (空の値も通る)。`SECURITY_HSTS_ENABLED` / `SECURITY_CSP_ENABLED` は本番起動時に
- *   要求されるが見本に 1 行も無いため**欠落を検出しない**。config の既定値と見本の値が
- *   食い違っていても検出しない (同期の検査ではなく**提示の検査**である)。
+ * ★保証しないもの (誇張しない):
+ *   1. 値の固定・キー網羅・行の形式・重複の主対象は `.env.example` の中身**だけ**であり、
+ *      実行中の `.env`・プロセスの環境変数・設定キャッシュには**無言で効かない**
+ *   2. キー網羅は**存在だけ**を見る (空の値も通る)
+ *   3. config の既定値と見本の値の一致は見ない (**同期の検査ではなく提示の検査**である)
+ *   4. 台帳に載せていない要求の欠落は検出しない。`SECURITY_HSTS_ENABLED` /
+ *      `SECURITY_CSP_ENABLED` は本番起動時に要求されるが見本に 1 行も無く、
+ *      **この 2 件の欠落は検出しない**
+ *   5. **見本をそのまま本番へ写す運用は検出しない** (`APP_ENV` ごと写るため。
+ *      そこは本番起動時の検査 = `ProductionEnvGuard` の担当である)
+ *   6. **TAB は「空白だけの行」と「コメントの字下げ」でのみ許容する**。
+ *      値になりうる行の TAB は形式違反である
+ *   7. **コメント行の中身は一切見ない** (制御文字も不正 UTF-8 も沈黙する)
+ *   8. **不可視文字 (U+200B / U+FEFF 等) は対象外**である (正典が求めるのは制御文字。
+ *      不可視文字の無害化は prompt 防御の窓口の責務)
+ *   9. 前提の固定が主張するのは「**この見本を env として選んでいない**」ことだけで、
+ *      許可する env 名の集合は固定しない。また `environmentFilePath()` は
+ *      「読む場所の指定」なので**そのファイルが実在したこと**は主張しない
+ *  10. 由来 (origin) の**長さと内容**は見ない (trim 後に非空であることだけを見る)
+ *  11. `${VAR}` の検査が見る 3 枚のうち `.env.example` 以外の 2 枚は**本 feature の対象外**である
+ *      (正典 i1)。この 2 枚については「値の中の `${VAR}` の解決可能性」しか見ておらず、
+ *      値の固定・キー網羅・行の形式・重複・制御文字は**1 つも見ていない**
+ *
+ * ★負例の置き場: 本ファイル内の合成入力 2 系統 —
+ *   `envExampleParseCounterexamples()` (解析器) と `envExampleLedgerCounterexamples()` (台帳)。
  *
- * 設計: devnotes/20260817-1309-todo-t213-env-example-gate-t1/
+ * 正典: lctl feature gate-env-example-sync / canonical_version t2 (2026-08-22 確定)
+ * 設計: devnotes/20260824-1014-gate-env-example-sync-t2/
+ *       (t1 化は devnotes/20260817-1309-todo-t213-env-example-gate-t1/)
  */
 
+/** 本 gate の対象 (正典 i1 = そのリポジトリが配るローカル開発用の見本 1 枚)。 */
+const ENV_EXAMPLE_PATH = '.env.example';
+
 /**
- * 見本ファイルの本文を行単位で解析する (**純粋関数**。ファイルを読まない)。
+ * 値になりうる行 (空行でもコメントでもない行) に現れてはならない文字。
  *
- * 行の分類:
- *   - 空白だけの行 → 実効値に影響しないので飛ばす
- *   - `^\s*#` の行 → コメント。同上
- *   - それ以外     → 素の代入行 `^[A-Z][A-Z0-9_]*=` **のみ**受理する
+ * C0 制御文字 (`\x00`-`\x08`) + **TAB (`\x09`)** + VT / FF (`\x0B` / `\x0C`) +
+ * 残りの C0 (`\x0E`-`\x1F`) + DEL (`\x7F`) + **C1 域 (U+0080-U+009F)**。
+ * `\n` / `\r` は行分割で除去済みなので含めない。
  *
- * ★これは dotenv の構文検査ではない。dotenv は `export FOO=1` も小文字のキーも読むが、
- *   本リポジトリの見本ファイルではそれらを許さない (存在検査・重複検査の母集合から
- *   外れたまま実効値だけを変えられる迂回になるため)。「見本に許す最小の書式」である。
+ * ★C1 は UTF-8 では必ず `\xC2` + `\x80`-`\x9F` の 2 バイトで表される。この判定は
+ *   **行が妥当な UTF-8 だと確かめた後にだけ**適用するので、多バイト文字の継続バイトと
+ *   衝突しない (先に検査すると `日本語` のような正当な値を誤検出する)。
+ * ★TAB が許されるのは「空白だけの行」と「コメントの字下げ」だけである
+ *   (正典 i3 が「空白だけの行は飛ばす」「コメント行の字下げは各リポジトリの裁量」と定めるため)。
+ *   **値になりうる行に TAB が現れたら形式違反**である。
+ */
+const ENV_EXAMPLE_FORBIDDEN_CHARS = '/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]|\xC2[\x80-\x9F]/';
+
+/** 素の代入行の受理規則 (キーは `[A-Z][A-Z0-9_]*`、等号の直後から行末までが値)。 */
+const ENV_EXAMPLE_ASSIGNMENT = '/^([A-Z][A-Z0-9_]*)=(.*)$/';
+
+/** コメント行 (字下げは半角空白と TAB のみ許す。`\s` は `\f` を含むため使わない)。 */
+const ENV_EXAMPLE_COMMENT = '/^[ \t]*#/';
+
+/**
+ * 正規表現の一致判定 (失敗を「一致しなかった」へ**畳まない**)。
  *
- * ★重複キーの値は**最初に現れた方**を記録する。dotenv は同一ファイル内の重複を
- *   **後に現れた方**で解決する。両者は食い違うので、重複が 1 件でもあると値の固定の検査は
- *   「実効値ではない値」を見ることになる。だから重複そのものを違反にする
- *   (どちらの解決順に合わせるかを選ばない)。
+ * `preg_match()` は失敗時に `false` を返す。`!== 1` で書くと失敗が「違反なし」になり、
+ * 走査器規約 (b) の fail-closed を破るため、失敗は例外にする。
+ */
+function envExampleMatches(string $pattern, string $line, int $lineNumber): bool
+{
+    $matched = preg_match($pattern, $line);
+    Assert::notFalse($matched, "行の判定に失敗した (L{$lineNumber}): {$pattern}");
+
+    return $matched === 1;
+}
+
+/**
+ * 行が妥当な UTF-8 か (不正 UTF-8 は **形式違反**として扱うため、判定は真偽で返す)。
  *
- * 改行は CRLF / CR / LF のいずれでも行に割る (行末に CR を残さない)。
- * ★ただし**反証の表に CR 単独の行は無い** — 分割の規則が将来 CR 単独を落とすように弱っても
- *   赤くならない (保証範囲を誇張しないための注記)。
- * 値は前後の空白を落とさない (見本に書いてあるとおりを返す = 等号の後ろの空白は値の一部)。
+ * ★`mb_check_encoding()` を使わない — `composer.json` は `ext-mbstring` を明示宣言して
+ *   いないので、宣言の無い拡張に検査の根幹を依存させない。`preg_match('//u', …)` は
+ *   不正 UTF-8 のときだけ `false` + `PREG_BAD_UTF8_ERROR` を返すので、これで代替する。
+ *   **それ以外の失敗 (バックトラック上限など) は例外にする** (fail-closed)。
+ * ★`preg_last_error()` はプロセス全体の直近のエラーを返すので、判定と読み出しの間に
+ *   他の `preg_*` を挟まない (この関数の中で連続させる)。
+ */
+function envExampleIsValidUtf8(string $line, int $lineNumber): bool
+{
+    $matched = preg_match('//u', $line);
+    if ($matched === false) {
+        Assert::same(
+            preg_last_error(),
+            PREG_BAD_UTF8_ERROR,
+            "UTF-8 妥当性の判定に失敗した (L{$lineNumber})",
+        );
+
+        return false;
+    }
+
+    return true;
+}
+
+/**
+ * 見本ファイルの本文を行単位で解析する (**純粋関数**。ファイルも `env()` も `config()` も読まない)。
+ *
+ * 行の分類 (**この順序が正典 i3 の文面そのもの**である):
+ *   1. 空白だけの行 (半角空白と TAB のみ) → 実効値を作らないので飛ばす
+ *   2. コメント行 (`^[ \t]*#`)             → 同上 (**中身は一切見ない**)
+ *   3. 不正 UTF-8 を含む行                 → 形式違反 (fail-closed)
+ *   4. 禁止文字を含む行                    → 形式違反 (ENV_EXAMPLE_FORBIDDEN_CHARS)
+ *   5. 素の代入行 (`^[A-Z][A-Z0-9_]*=`)     → 受理
+ *   6. それ以外                            → 形式違反
+ *
+ * ★3〜4 を 5 より**前**に置く。後に置くと `A=x\x01` が「正常な代入」として受理され、
+ *   値の固定の検査が制御文字入りの値を通してしまう。
+ * ★空行判定に `trim()` の既定 charlist を使わない (`\0` と `\x0B` を含むため、
+ *   制御文字だけの行が空行として検査の外へ逃げる)。コメント判定に `\s` を使わない
+ *   (`\f` を含むため、`\f#` で始まる行がコメントとして逃げる)。
+ * ★これは dotenv の構文検査ではない (dotenv は `export FOO=1` も小文字のキーも読む)。
+ *   「見本に許す最小の書式」である。存在検査と重複検査の母集合から外れたまま実効値だけを
+ *   変えられる迂回を、行の形ごと禁じる。
+ * ★重複キーの値は**最初に現れた方**を記録し、2 回目以降は `duplicateKeys` に載せる。
+ *   dotenv は同一ファイル内の重複を**後に現れた方**で解決するので両者は食い違う。だから
+ *   重複そのものを違反にする (どちらの解決順に合わせるかを選ばない)。
+ *   したがって「`values` にキーが在り、かつ `duplicateKeys` に無い」⟺「実代入がちょうど 1 件」
+ *   であり、値の固定の検査 (i5) はこの同値性を使う。
+ *
+ * 改行は CRLF / CR / LF のいずれでも行に割る (行末に CR を残さない)。**3 通りとも反証で裏取り済み**
+ * (LF は R3 / CRLF は R13 / CR 単独は R29)。値は前後の空白を落とさない
+ * (等号の**後ろ**の空白は値の一部)。
  *
  * @return array{
  *   values: array<string, string>,
@@ -71,24 +184,44 @@
 function envExampleParseContents(string $contents): array
 {
     $lines = preg_split('/\r\n|\r|\n/', $contents);
-    expect($lines)->toBeArray();
+    Assert::isArray($lines, '見本の本文を行に分割できなかった');
     /** @var list<string> $lines */
     $values = [];
     $duplicateKeys = [];
     $malformedLineNumbers = [];
 
     foreach ($lines as $index => $line) {
-        if (trim($line) === '') {
+        $lineNumber = $index + 1;
+
+        // 1. 空白だけの行 (既定 charlist を使わない — `\0` と `\x0B` を空白として飛ばさないため)
+        if (trim($line, " \t") === '') {
+            continue;
+        }
+        // 2. コメント行 (中身は見ない)
+        if (envExampleMatches(ENV_EXAMPLE_COMMENT, $line, $lineNumber)) {
             continue;
         }
-        if (preg_match('/^\s*#/', $line) === 1) {
+        // 3. 不正 UTF-8
+        if (! envExampleIsValidUtf8($line, $lineNumber)) {
+            $malformedLineNumbers[] = $lineNumber;
+
+            continue;
+        }
+        // 4. 禁止文字 (C0 + TAB + DEL + C1)
+        if (envExampleMatches(ENV_EXAMPLE_FORBIDDEN_CHARS, $line, $lineNumber)) {
+            $malformedLineNumbers[] = $lineNumber;
+
             continue;
         }
-        if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $matches) !== 1) {
-            $malformedLineNumbers[] = $index + 1;
+        // 5. 素の代入行
+        $matched = preg_match(ENV_EXAMPLE_ASSIGNMENT, $line, $matches);
+        Assert::notFalse($matched, "代入行の判定に失敗した (L{$lineNumber})");
+        if ($matched !== 1) {
+            $malformedLineNumbers[] = $lineNumber;
 
             continue;
         }
+
         $key = $matches[1];
         if (array_key_exists($key, $values)) {
             // 同じキーが 3 回以上でも、重複の一覧にはキー名を 1 度だけ載せる (診断の安定)。
@@ -119,154 +252,400 @@ function envExampleParseContents(string $contents): array
  */
 function envExampleParse(): array
 {
-    $contents = file_get_contents(base_path('.env.example'));
-    expect($contents)->toBeString();
-    /** @var string $contents */
+    $contents = file_get_contents(base_path(ENV_EXAMPLE_PATH));
+    Assert::string($contents, '見本ファイルを読めなかった: '.ENV_EXAMPLE_PATH);
 
     return envExampleParseContents($contents);
 }
 
+/** 台帳の種別 (i7 / i8 / i9 でいう「種別」)。 */
+const ENV_EXAMPLE_KIND_VALUE_PIN = 'value_pin';
+
+/** 台帳の種別 (存在だけを要求する側)。 */
+const ENV_EXAMPLE_KIND_REQUIRED_KEY = 'required_key';
+
 /**
- * 値の固定: 裁定 AG-007 が名指しする 2 件。
+ * 値の固定 (種別 `value_pin`) の分類 `ag007_core`: 家系の裁定 AG-007 が名指しする 2 件。
  * 緩めるには家系の機能台帳側の裁定変更が要る (本リポジトリ単独では動かせない)。
  *
- * ★形式はキーと値の組の**リスト**にする (キー付きの連想配列にしない)。
+ * ★形式はキー・値・由来の組の**リスト**にする (キー付きの連想配列にしない)。
  *   連想配列のリテラルは同じ定数の中の重複キーをコンパイル時に後勝ちで無音に潰すため、
  *   「行を足しただけに見える差分」で既存の固定を反転できてしまう。
- *   リストなら重複がそのまま残り、下の誠実性の検査が同じ機構で捕まえられる。
+ *   リストなら重複がそのまま残り、誠実性の検査が同じ機構で捕まえられる。
  */
 const ENV_EXAMPLE_VALUE_PINS_AG007_CORE = [
-    ['key' => 'SESSION_SECURE_COOKIE', 'value' => 'true'],
-    ['key' => 'SESSION_ENCRYPT', 'value' => 'true'],
+    ['key' => 'SESSION_SECURE_COOKIE', 'value' => 'true', 'origin' => '裁定 AG-007。false だとセッション Cookie が平文 HTTP でも送られる (見本を写した環境が無防備になる)'],
+    ['key' => 'SESSION_ENCRYPT', 'value' => 'true', 'origin' => '裁定 AG-007。false だとセッション本体が平文で保管され、撮影 PWA の履歴秘匿の前提が崩れる'],
 ];
 
 /**
- * 値の固定: 本リポジトリ固有の追加 (裁定で必須とされたものではない純増。個別に理由を書く)。
- * - ADMIN_MFA_REQUIRED=true: false にすると管理画面の二要素が実質無効になる。
- *   local の値が本番へ写る事故の側が危険なので、見本は安全側で固定する。
- * - MCP_STRICT_TRANSPORT=true: false にすると Origin を送らないクライアントを受け入れる
- *   (DNS 再バインドの面が広がる)。
+ * 分類 `canonical_t2`: 正典 t2 の i6 (見本の**用途宣言**) が足した 1 件。
  */
-const ENV_EXAMPLE_VALUE_PINS_AICUE = [
-    ['key' => 'ADMIN_MFA_REQUIRED', 'value' => 'true'],
-    ['key' => 'MCP_STRICT_TRANSPORT', 'value' => 'true'],
+const ENV_EXAMPLE_VALUE_PINS_CANONICAL_T2 = [
+    ['key' => 'APP_ENV', 'value' => 'local', 'origin' => '見本の用途宣言 (正典 t2 の i6 / s4)。「見本は APP_ENV=local の開発シードだから APP_DEBUG=true を許す」という論拠の根拠側であり、固定しないと論拠が黙って失効する'],
 ];
 
 /**
- * 値の固定の台帳の合成 (重複した組を保持したまま連結する)。
- *
- * @return list<array{key: string, value: string}>
+ * 分類 `aicue`: 本リポジトリ固有の追加 (裁定で必須とされたものではない純増)。
  */
-function envExampleValuePinEntries(): array
-{
-    return array_merge(ENV_EXAMPLE_VALUE_PINS_AG007_CORE, ENV_EXAMPLE_VALUE_PINS_AICUE);
-}
+const ENV_EXAMPLE_VALUE_PINS_AICUE = [
+    ['key' => 'ADMIN_MFA_REQUIRED', 'value' => 'true', 'origin' => 'false にすると管理画面の二要素が実質無効になる。local の値が本番へ写る事故の側が危険なので見本は安全側で固定する'],
+    ['key' => 'MCP_STRICT_TRANSPORT', 'value' => 'true', 'origin' => 'false にすると Origin を送らないクライアントを受け入れる (DNS 再バインドの面が広がる)'],
+];
 
 /**
- * キー網羅の台帳。分類ごとに定数を分ける (平らな 1 本の配列にしない)。
- * 削るときに「どの根拠を外すのか」がレビューで見えるようにするためである。
+ * 必須キー (種別 `required_key`) の分類 `setup`:
+ * 新しい環境を立てるときに要る座標。`composer setup` と `scripts/setup-worktree.sh` の案内が
+ * `.env.example` をそのまま `.env` にするため、ここが欠けると「動かない .env」が出来上がる。
  *
  * ★台帳は**床**であって天井ではない。`.env.example` に任意のキーを足すことは責務外で、
  *   完全一致の集合にはしない。
- *
- * (i) 新しい環境を立てるときに要る座標。`composer setup` と
- *     `scripts/setup-worktree.sh` の案内が `.env.example` をそのまま `.env` にするため、
- *     ここが欠けると「動かない .env」が出来上がる。
+ * ★`APP_ENV` は**値の固定側へ移した** (正典 i6)。存在確認は値の固定が含むので、
+ *   両方に載せると誠実性の検査 (規則 4 = キーは台帳全体で一意) が赤くなる。
  */
 const ENV_EXAMPLE_REQUIRED_KEYS_SETUP = [
-    'APP_NAME',
-    'APP_ENV',
-    'APP_KEY',
-    'APP_URL',
-    'APP_LOCALE',
-    'DB_CONNECTION',
-    'SESSION_DRIVER',
-    'QUEUE_CONNECTION',
-    'CACHE_STORE',
+    ['key' => 'APP_NAME', 'origin' => 'アプリ名。config/app.php の app.name の入口で、欠けると画面タイトルとメール差出人名が既定名になる'],
+    ['key' => 'APP_KEY', 'origin' => '暗号鍵の座標。空でも提示が要る (composer setup が key:generate で埋める前提の枠)'],
+    ['key' => 'APP_URL', 'origin' => '絶対 URL の生成元。presigned URL と SSO の戻り先の組み立てに要る'],
+    ['key' => 'APP_LOCALE', 'origin' => '既定ロケール。日本語の現場向け既定を見本で提示する'],
+    ['key' => 'DB_CONNECTION', 'origin' => '接続ドライバの選択。pgsql 前提の環境で既定が sqlite に落ちると初回 migrate が別 DB へ走る'],
+    ['key' => 'SESSION_DRIVER', 'origin' => 'セッション保管先。撮影 PWA は同一オリジンのセッション認証なので既定の提示が要る'],
+    ['key' => 'QUEUE_CONNECTION', 'origin' => 'キュー接続。AI 解析と ffmpeg 合成は非同期ジョブなので既定が sync だと画面が待ち続ける'],
+    ['key' => 'CACHE_STORE', 'origin' => 'キャッシュ保管先。FxRateService 等の素データキャッシュの前提'],
 ];
 
 /**
- * (ii) 本番の起動時に検査される座標のうち、**現在 `.env.example` に素の代入行として
- *      提示済みのもの**。正本は app/Support/ProductionEnvGuard.php で、依存は一方向である
- *      (guard が変われば本台帳が古くなる。機械では結線しない — guard が読むのは config の
- *      キーであって環境変数名ではないため、結ぶには config の構文解析が要る)。
+ * 分類 `production_guard`: 本番の起動時に検査される座標のうち、**現在 `.env.example` に
+ * 素の代入行として提示済みのもの**。正本は app/Support/ProductionEnvGuard.php で、
+ * 依存は一方向である (guard が変われば本台帳が古くなる。機械では結線しない —
+ * guard が読むのは config のキーであって環境変数名ではないため、結ぶには config の構文解析が要る)。
  *
  * ★これは guard の要求の**写しではない**。guard は SECURITY_HSTS_ENABLED /
  *   SECURITY_CSP_ENABLED も本番で true と要求するが、この 2 つは `.env.example` に
  *   1 行も無く、載せるには見本の書き方の判断が要るため本台帳には入れない
  *   (**この 2 件の欠落は検出しない**)。
- *
  * ★SESSION_SECURE_COOKIE / ADMIN_MFA_REQUIRED 等は値の固定の台帳が値ごと押さえるため
- *   ここには載せない (台帳をまたぐ二重登録は下の誠実性の検査が禁じる)。
+ *   ここには載せない (台帳をまたぐ二重登録は誠実性の検査の規則 4 が禁じる)。
  */
 const ENV_EXAMPLE_REQUIRED_KEYS_PRODUCTION_GUARD = [
-    'CIPHERSWEET_KEY',
-    'STRIPE_WEBHOOK_SECRET',
-    'DEBUG_LOGIN_USER',
-    'DEBUG_LOGIN_PASSWORD',
-    'PRIMARY_HOST',
-    'TRUSTED_HOSTS_ADDITIONAL',
-    'TRUSTED_HOSTS_WILDCARD_SUFFIXES',
-    'TRUSTED_PROXIES',
-    'PASSKEYS_USER_HANDLE_SECRET',
+    ['key' => 'CIPHERSWEET_KEY', 'origin' => 'ProductionEnvGuard が ciphersweet.providers.string.key の宣言を本番で要求する (PII の暗号化鍵)'],
+    ['key' => 'STRIPE_WEBHOOK_SECRET', 'origin' => 'ProductionEnvGuard が cashier.webhook.secret の宣言を本番で要求する (webhook の署名検証)'],
+    ['key' => 'DEBUG_LOGIN_USER', 'origin' => 'ProductionEnvGuard が debug.login.user の本番不在を要求する。座標を見本で提示しないと環境ごとに別名が発明される'],
+    ['key' => 'DEBUG_LOGIN_PASSWORD', 'origin' => '同上 (debug.login.password)。提示しておくことで「本番では空である」ことがレビューで見える'],
+    ['key' => 'PRIMARY_HOST', 'origin' => 'TrustHosts の許可リストの起点。未宣言のまま本番へ出ると ProductionEnvGuard が起動時に落とす'],
+    ['key' => 'TRUSTED_HOSTS_ADDITIONAL', 'origin' => 'trusted_hosts.exact_hosts の入口。追加ホストの座標を見本で提示する'],
+    ['key' => 'TRUSTED_HOSTS_WILDCARD_SUFFIXES', 'origin' => 'trusted_hosts.wildcard_suffixes の入口。書式不正は起動時 fail-fast なので提示が要る'],
+    ['key' => 'TRUSTED_PROXIES', 'origin' => 'AGENTS.md の運用要件 T108。未宣言 / `*` / REMOTE_ADDR は起動時 fail-fast する (初回デプロイ前に設定が要る)'],
+    ['key' => 'PASSKEYS_USER_HANDLE_SECRET', 'origin' => 'AGENTS.md の運用要件 (パスキー)。未宣言だと利用者ハンドルが APP_KEY 由来になり、鍵ローテートで登録済みパスキーが全件無効になる'],
 ];
 
 /**
- * (iii) 提示が無いと環境ごとに別の名前が発明されて食い違う座標
- *       (外部との統合の秘密と、アプリ固有の座標)。
+ * 分類 `integration`: 提示が無いと環境ごとに別の名前が発明されて食い違う座標
+ * (外部との統合の秘密と、アプリ固有の座標)。
  */
 const ENV_EXAMPLE_REQUIRED_KEYS_INTEGRATION = [
-    'STRIPE_KEY',
-    'STRIPE_SECRET',
-    'OPENAI_API_KEY',
-    'ANTHROPIC_API_KEY',
-    'GEMINI_API_KEY',
-    'GOOGLE_CLIENT_ID',
-    'GOOGLE_CLIENT_SECRET',
-    'RECAPTCHA_SITE_KEY',
-    'RECAPTCHA_SECRET_KEY',
-    'MCP_ALLOWED_ORIGINS',
-    'PASSPORT_PRIVATE_KEY',
-    'PASSPORT_PUBLIC_KEY',
-    'TEMPLATE_APP_SLUG',
-    'LEGAL_CONSENT_VERSION',
+    ['key' => 'STRIPE_KEY', 'origin' => '課金の公開鍵。Cashier の設定の入口'],
+    ['key' => 'STRIPE_SECRET', 'origin' => '課金の秘密鍵。座標を提示しないと環境ごとに別名になる'],
+    ['key' => 'OPENAI_API_KEY', 'origin' => 'SOP 解析のプロバイダ鍵 (Prism 経由)。使命の中核である AI 解析の入口'],
+    ['key' => 'ANTHROPIC_API_KEY', 'origin' => '同上 (プロバイダ切り替えの座標)'],
+    ['key' => 'GEMINI_API_KEY', 'origin' => '同上 (プロバイダ切り替えの座標)'],
+    ['key' => 'GOOGLE_CLIENT_ID', 'origin' => 'SSO の client id。Socialite の設定の入口'],
+    ['key' => 'GOOGLE_CLIENT_SECRET', 'origin' => '同上 (SSO の client secret)'],
+    ['key' => 'RECAPTCHA_SITE_KEY', 'origin' => '登録フォームの bot 対策の公開鍵'],
+    ['key' => 'RECAPTCHA_SECRET_KEY', 'origin' => '同上 (検証側の秘密)'],
+    ['key' => 'MCP_ALLOWED_ORIGINS', 'origin' => 'MCP の Origin 許可リスト。MCP_STRICT_TRANSPORT=true の相方で、空だと厳格輸送が実質使えない'],
+    ['key' => 'PASSPORT_PRIVATE_KEY', 'origin' => 'OAuth 鍵を env 注入で運用する規約 (storage の鍵ファイルを配らない)'],
+    ['key' => 'PASSPORT_PUBLIC_KEY', 'origin' => '同上 (検証側)'],
+    ['key' => 'TEMPLATE_APP_SLUG', 'origin' => 'テンプレート由来のアプリ識別子。config/template.php の入口で、欠けると生成物の名前空間が既定値に落ちる'],
+    ['key' => 'LEGAL_CONSENT_VERSION', 'origin' => '同意の版。上げ忘れると再同意が要求されないため座標の提示が要る'],
 ];
 
 /**
- * (iv) 撮影テイクとレンダ成果物の保管先。本リポジトリ固有の分類である。
- *      撮影 PWA は presigned URL で直接アップロードし、合成した動画も同じ保管先へ置く。
- *      ここが欠けた環境では**撮った映像を保存できない** = 使命の中核が動かない。
+ * 分類 `object_storage`: 撮影テイクとレンダ成果物の保管先。本リポジトリ固有の分類である。
+ * 撮影 PWA は presigned URL で直接アップロードし、合成した動画も同じ保管先へ置く。
+ * ここが欠けた環境では**撮った映像を保存できない** = 使命の中核が動かない。
  */
 const ENV_EXAMPLE_REQUIRED_KEYS_OBJECT_STORAGE = [
-    'AWS_ACCESS_KEY_ID',
-    'AWS_SECRET_ACCESS_KEY',
-    'AWS_DEFAULT_REGION',
-    'AWS_BUCKET',
+    ['key' => 'AWS_ACCESS_KEY_ID', 'origin' => 'S3 互換ストレージの資格情報。presigned PUT の署名に要る'],
+    ['key' => 'AWS_SECRET_ACCESS_KEY', 'origin' => '同上 (秘密側)'],
+    ['key' => 'AWS_DEFAULT_REGION', 'origin' => '署名のリージョン。誤ると presigned URL が 403 になる'],
+    ['key' => 'AWS_BUCKET', 'origin' => 'テイクと成果物の保管先バケット。未宣言だと撮影の保存先が無い'],
 ];
 
 /**
- * キー網羅の台帳の合成 (4 分類の連結)。
+ * 台帳の申告件数 (i9)。**種別ごと**と**分類ごと**の 2 段で申告する。
+ *
+ * ★摩擦は意図したものである。「見本からキーを消す変更は台帳の entry と申告件数の
+ *   両方の更新を要求する」ための設計で、種別の合計だけを申告する形にはしない
+ *   (分類をまたいで 1 件ずつ入れ替える差分が合計値のまま緑になり、由来の入れ替えが無音になる)。
+ * ★**宣言順は仕様にしない** (誠実性の検査が `ksort()` してから比べる。読みやすさのため昇順で書く)。
+ */
+const ENV_EXAMPLE_LEDGER_DECLARED_COUNTS = [
+    'kinds' => [
+        ENV_EXAMPLE_KIND_VALUE_PIN => 5,
+        ENV_EXAMPLE_KIND_REQUIRED_KEY => 35,
+    ],
+    'classifications' => [
+        ENV_EXAMPLE_KIND_VALUE_PIN => [
+            'ag007_core' => 2,
+            'aicue' => 2,
+            'canonical_t2' => 1,
+        ],
+        ENV_EXAMPLE_KIND_REQUIRED_KEY => [
+            'integration' => 14,
+            'object_storage' => 4,
+            'production_guard' => 9,
+            'setup' => 8,
+        ],
+    ],
+];
+
+/**
+ * 台帳を 1 本のリストへ正規化する (種別・分類・由来・固定値を entry ごとに持つ形)。
+ *
+ * ★分類名は**定数の割り方から付ける**。entry 側に分類名を書かせると、定数の中身と
+ *   分類名が食い違う差分 (「別の定数へ移したのに分類名を直し忘れる」) を作れてしまう。
+ * ★`value` は**常に存在するキー**である (必須キーは `null`)。任意キーにすると
+ *   「値の固定なのに value の項目が無い」形と「必須キーで null」の形を型で区別できない。
+ *
+ * @return list<array{key: string, kind: string, classification: string, origin: string, value: string|null}>
+ */
+function envExampleLedgerEntries(): array
+{
+    $entries = [];
+
+    /** @var array<string, list<array{key: string, value: string, origin: string}>> $pinGroups */
+    $pinGroups = [
+        'ag007_core' => ENV_EXAMPLE_VALUE_PINS_AG007_CORE,
+        'aicue' => ENV_EXAMPLE_VALUE_PINS_AICUE,
+        'canonical_t2' => ENV_EXAMPLE_VALUE_PINS_CANONICAL_T2,
+    ];
+    foreach ($pinGroups as $classification => $rows) {
+        foreach ($rows as $row) {
+            $entries[] = [
+                'key' => $row['key'],
+                'kind' => ENV_EXAMPLE_KIND_VALUE_PIN,
+                'classification' => $classification,
+                'origin' => $row['origin'],
+                'value' => $row['value'],
+            ];
+        }
+    }
+
+    /** @var array<string, list<array{key: string, origin: string}>> $requiredGroups */
+    $requiredGroups = [
+        'integration' => ENV_EXAMPLE_REQUIRED_KEYS_INTEGRATION,
+        'object_storage' => ENV_EXAMPLE_REQUIRED_KEYS_OBJECT_STORAGE,
+        'production_guard' => ENV_EXAMPLE_REQUIRED_KEYS_PRODUCTION_GUARD,
+        'setup' => ENV_EXAMPLE_REQUIRED_KEYS_SETUP,
+    ];
+    foreach ($requiredGroups as $classification => $rows) {
+        foreach ($rows as $row) {
+            $entries[] = [
+                'key' => $row['key'],
+                'kind' => ENV_EXAMPLE_KIND_REQUIRED_KEY,
+                'classification' => $classification,
+                'origin' => $row['origin'],
+                'value' => null,
+            ];
+        }
+    }
+
+    return $entries;
+}
+
+/**
+ * 種別で絞った entry の一覧 (検査 a / b の入力)。
+ *
+ * @return list<array{key: string, kind: string, classification: string, origin: string, value: string|null}>
+ */
+function envExampleLedgerEntriesOfKind(string $kind): array
+{
+    return array_values(array_filter(
+        envExampleLedgerEntries(),
+        static fn (array $entry): bool => $entry['kind'] === $kind,
+    ));
+}
+
+/**
+ * 台帳自身の誠実性違反 (空なら健全)。**純粋関数**である (ファイルも見本も読まない)。
  *
+ * **9 規則**である (各規則の判定分岐を負のコントロール V1〜V24 が対応表で押さえる):
+ *
+ * | # | 規則 | 塞ぐ穴 | 正典 |
+ * |---|---|---|---|
+ * | 1 | 申告 map 自身が健全 (キー集合が既知の 2 種別と完全一致 / 申告値が 1 以上 / 分類名が空白のみでない) | 申告を空にして件数の照合を無効化する迂回 | i9 |
+ * | 2 | entry が 1 件以上ある | 台帳を空にすると全検査が緑になる無言の失効 | i8 (1) |
+ * | 3 | キーが `/^[A-Z][A-Z0-9_]*$/` に一致する | 検査対象にならない綴りの登録 | i8 (2) |
+ * | 4 | キーが台帳全体で一意 (種別をまたいでも) | 台帳内の重複 / 値の固定と必須キーの二重登録 | i8 (3) |
+ * | 5 | 種別が既知の 2 つのいずれかである | 綴り違いの種別が数え上げから漏れる | i8 (4) |
+ * | 6 | `value_pin` は非空の固定値を持ち改行も禁止文字も含まない。`required_key` は値を持たない | 種別と値の取り違え | i8 (4) |
+ * | 7 | 分類が申告 map に在る名前である | 未申告の分類の混入 (件数の照合をすり抜ける) | i7 / i9 |
+ * | 8 | 由来が trim 後に非空である | 由来不明の entry の堆積 | i7 |
+ * | 9 | 種別ごとの実件数が申告と一致し、分類ごとの実 map が申告 map と完全一致し、分類 map の合計が種別の申告と一致する | 静かな削除 / 分類の増減・改名 / 申告の片側だけの修正 | i9 |
+ *
+ * ★申告 map の**宣言順は仕様にしない** (規則 1 も規則 9 も `ksort()` 済みで比べる)。
+ * ★**保証しないもの**: 由来の**長さと内容**は見ない (trim 後に非空であることだけを見る)。
+ *   台帳の内容が見本と一致するかは本関数の担当ではない (検査 a / b が見る)。
+ *
+ * @param  list<array{key: string, kind: string, classification: string, origin: string, value: string|null}>  $entries
+ * @param  array{kinds: array<string, int>, classifications: array<string, array<string, int>>}  $declared
  * @return list<string>
  */
-function envExampleRequiredKeys(): array
+function envExampleLedgerViolations(array $entries, array $declared): array
 {
-    return array_merge(
-        ENV_EXAMPLE_REQUIRED_KEYS_SETUP,
-        ENV_EXAMPLE_REQUIRED_KEYS_PRODUCTION_GUARD,
-        ENV_EXAMPLE_REQUIRED_KEYS_INTEGRATION,
-        ENV_EXAMPLE_REQUIRED_KEYS_OBJECT_STORAGE,
-    );
+    $kinds = [ENV_EXAMPLE_KIND_VALUE_PIN, ENV_EXAMPLE_KIND_REQUIRED_KEY];
+    $violations = [];
+
+    // 規則 2
+    if ($entries === []) {
+        $violations[] = '台帳に entry が 1 件も無い';
+    }
+
+    // 規則 1: 申告 map 自身の健全性 (**宣言順に依存させないため両方 ksort する**)
+    $declaredKinds = $declared['kinds'];
+    $declaredClassifications = $declared['classifications'];
+    ksort($declaredKinds);
+    ksort($declaredClassifications);
+    $expectedKinds = $kinds;
+    sort($expectedKinds);
+    if (array_keys($declaredKinds) !== $expectedKinds) {
+        $violations[] = '種別の申告のキー集合が既知の種別と一致しない';
+    }
+    if (array_keys($declaredClassifications) !== $expectedKinds) {
+        $violations[] = '分類の申告のキー集合が既知の種別と一致しない';
+    }
+    foreach ($declaredKinds as $kind => $count) {
+        if ($count < 1) {
+            $violations[] = "種別 {$kind} の申告件数が 1 未満である";
+        }
+    }
+    foreach ($declaredClassifications as $kind => $map) {
+        foreach ($map as $classification => $count) {
+            if (trim((string) $classification) === '') {
+                $violations[] = "種別 {$kind} に空白のみの分類名の申告がある";
+            }
+            if ($count < 1) {
+                $violations[] = "分類 {$kind}/{$classification} の申告件数が 1 未満である";
+            }
+        }
+    }
+
+    $keyOccurrences = [];
+    $actualKindCounts = array_fill_keys($kinds, 0);
+    $actualClassificationCounts = array_fill_keys($kinds, []);
+
+    foreach ($entries as $entry) {
+        $key = $entry['key'];
+        $kind = $entry['kind'];
+
+        // 規則 3 (`false` を「一致しなかった」へ畳まない = fail-closed)
+        $spelling = preg_match('/^[A-Z][A-Z0-9_]*$/', $key);
+        Assert::notFalse($spelling, "{$key}: キーの綴りの判定に失敗した");
+        if ($spelling !== 1) {
+            $violations[] = "{$key}: キーの綴りが env の代入行として成立しない";
+        }
+        // 規則 4 (件数は後でまとめて判定する)
+        $keyOccurrences[$key] = ($keyOccurrences[$key] ?? 0) + 1;
+
+        // 規則 5
+        if (! in_array($kind, $kinds, true)) {
+            $violations[] = "{$key}: 未知の種別 {$kind} である";
+
+            continue;
+        }
+        $actualKindCounts[$kind]++;
+
+        // 規則 6
+        if ($kind === ENV_EXAMPLE_KIND_VALUE_PIN) {
+            $value = $entry['value'];
+            if ($value === null || $value === '') {
+                $violations[] = "{$key}: 値の固定なのに固定値が無い";
+            } else {
+                // `false` を畳まない (解析器と同じ fail-closed 方針)
+                $forbidden = preg_match(ENV_EXAMPLE_FORBIDDEN_CHARS, $value);
+                Assert::notFalse($forbidden, "{$key}: 固定値の禁止文字の判定に失敗した");
+                if (str_contains($value, "\n") || str_contains($value, "\r") || $forbidden === 1) {
+                    $violations[] = "{$key}: 固定値に改行または禁止文字が含まれている";
+                }
+            }
+        } elseif ($entry['value'] !== null) {
+            $violations[] = "{$key}: 値を持てない種別 ({$kind}) に固定値がある";
+        }
+
+        // 規則 7
+        $classification = $entry['classification'];
+        if (! array_key_exists($classification, $declaredClassifications[$kind] ?? [])) {
+            $violations[] = "{$key}: 分類 {$classification} が種別 {$kind} の申告に無い";
+        }
+        $actualClassificationCounts[$kind][$classification]
+            = ($actualClassificationCounts[$kind][$classification] ?? 0) + 1;
+
+        // 規則 8
+        if (trim($entry['origin']) === '') {
+            $violations[] = "{$key}: 由来 (origin) が空である";
+        }
+    }
+
+    // 規則 4
+    foreach ($keyOccurrences as $key => $occurrences) {
+        if ($occurrences > 1) {
+            $violations[] = "{$key} が台帳に {$occurrences} 回現れる (種別をまたいだ重複も禁止)";
+        }
+    }
+
+    // 規則 9
+    foreach ($kinds as $kind) {
+        $declaredCount = $declaredKinds[$kind] ?? null;
+        if ($declaredCount !== $actualKindCounts[$kind]) {
+            $violations[] = sprintf(
+                '種別 %s の申告件数 %s と実件数 %d が一致しない',
+                $kind,
+                var_export($declaredCount, true),
+                $actualKindCounts[$kind],
+            );
+        }
+
+        $declaredMap = $declaredClassifications[$kind] ?? [];
+        $actualMap = $actualClassificationCounts[$kind];
+        ksort($declaredMap);
+        ksort($actualMap);
+        if ($declaredMap !== $actualMap) {
+            $violations[] = sprintf(
+                '種別 %s の分類ごとの件数が申告と一致しない (申告 %s / 実測 %s)',
+                $kind,
+                json_encode($declaredMap, JSON_UNESCAPED_UNICODE) ?: '?',
+                json_encode($actualMap, JSON_UNESCAPED_UNICODE) ?: '?',
+            );
+        }
+        if (array_sum($declaredMap) !== $declaredCount) {
+            $violations[] = sprintf(
+                '種別 %s の分類ごとの件数の合計 %d が種別の申告 %s と一致しない',
+                $kind,
+                array_sum($declaredMap),
+                var_export($declaredCount, true),
+            );
+        }
+    }
+
+    return $violations;
 }
 
-test('a: .env.example は安全側の既定値を行の完全一致で満たす', function (): void {
+test('a: .env.example は安全側の既定値を実代入ちょうど 1 件 + 値の完全一致で満たす', function (): void {
     $parsed = envExampleParse();
 
     // 失敗時に出すのは**キー名だけ**である (見本の実値を出力しない)。
     $violations = [];
-    foreach (envExampleValuePinEntries() as $entry) {
+    foreach (envExampleLedgerEntriesOfKind(ENV_EXAMPLE_KIND_VALUE_PIN) as $entry) {
+        // 「values に在り、かつ duplicateKeys に無い」= 実代入ちょうど 1 件 (解析器の docblock 参照)。
+        // 重複そのものは c-2 も落とすが、i5 は**単独で**成立させる (i4 が将来消えても効くようにする)。
+        if (in_array($entry['key'], $parsed['duplicateKeys'], true)) {
+            $violations[] = $entry['key'].' (実代入が 2 件以上)';
+
+            continue;
+        }
         if (($parsed['values'][$entry['key']] ?? null) !== $entry['value']) {
-            $violations[] = $entry['key'];
+            $violations[] = $entry['key'].' (値が固定と一致しない、または不在)';
         }
     }
 
@@ -276,7 +655,11 @@ function envExampleRequiredKeys(): array
 test('b: .env.example は必須キーの台帳を網羅する', function (): void {
     $parsed = envExampleParse();
 
-    $missing = array_values(array_diff(envExampleRequiredKeys(), array_keys($parsed['values'])));
+    $required = array_map(
+        static fn (array $entry): string => $entry['key'],
+        envExampleLedgerEntriesOfKind(ENV_EXAMPLE_KIND_REQUIRED_KEY),
+    );
+    $missing = array_values(array_diff($required, array_keys($parsed['values'])));
 
     expect($missing)->toBe([]);
 });
@@ -298,21 +681,12 @@ function envExampleRequiredKeys(): array
     expect($parsed['duplicateKeys'])->toBe([]);
 });
 
-test('台帳の誠実性: 値の固定とキー網羅の二重登録・台帳の中の重複が無い', function (): void {
-    // 値の固定は存在の検査を含むので、キー網羅への二重登録は台帳の腐敗になる
-    // (どちらを緩めたのか追えなくなる)。機械的に禁じる。
-    $required = envExampleRequiredKeys();
-
-    $pinKeys = [];
-    foreach (envExampleValuePinEntries() as $entry) {
-        $pinKeys[] = $entry['key'];
-    }
-
-    // 組のリスト形式は重複を保持するので、この一意性の検査 1 本で
-    // 台帳の中 (同じ定数の中) と台帳の間 (2 つの定数にまたがる重複) の両方を捕まえられる。
-    expect(array_values(array_unique($pinKeys)))->toBe($pinKeys);
-    expect(array_values(array_intersect($required, $pinKeys)))->toBe([]);
-    expect(array_values(array_unique($required)))->toBe($required);
+test('台帳の誠実性: 種別・分類・由来・件数の申告が整合する', function (): void {
+    // t1 の 4 観点 (値の固定とキー網羅の二重登録の禁止 / 台帳の中の重複の禁止 /
+    // キーの綴り / 台帳が空でないこと) は 9 規則へ読み替えて温存してある
+    // (詳細は envExampleLedgerViolations() の docblock の対応表)。
+    expect(envExampleLedgerViolations(envExampleLedgerEntries(), ENV_EXAMPLE_LEDGER_DECLARED_COUNTS))
+        ->toBe([]);
 });
 
 /*
@@ -324,19 +698,21 @@ function envExampleRequiredKeys(): array
  * ★これは dotenv の構文検査ではない。本リポジトリの見本ファイルに許す最小の書式である。
  */
 
-test('反証: 解析器は合成した本文を仕様どおりに分解する', /**
- * 型注記は closure に直接付ける (将来 tests/ を PHPStan の解析対象へ入れても
- * iterable の値の型が欠けないようにするため)。
+/**
+ * 反証データセット (i10)。**見本を壊さずに「壊れたら赤くなる」ことを示す**ための合成入力。
  *
- * @param array{
+ * ラベルの先頭の `R<n>` は**恒久の識別子**である (床の検査が順序込みで突き合わせるので、
+ * 番号を詰めたり付け替えたりしない。廃止するときは床の期待値も同じ変更で直す)。
+ *
+ * @return array<string, array{0: string, 1: array{
  *   values: array<string, string>,
  *   duplicateKeys: list<string>,
  *   malformedLineNumbers: list<int>,
- * } $expected
+ * }}>
  */
-    function (string $contents, array $expected): void {
-        expect(envExampleParseContents($contents))->toBe($expected);
-    })->with([
+function envExampleParseCounterexamples(): array
+{
+    return [
         // R1: コメント偽装。t0 の部分一致 (toContain) はこれを通していた = 偽グリーンの本体。
         'R1 コメント偽装した代入行は実効値にならない' => [
             '# SESSION_SECURE_COOKIE=true',
@@ -412,7 +788,462 @@ function (string $contents, array $expected): void {
             '',
             ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => []],
         ],
-    ]);
+        // ---- ここから t2 (i3) の追加分 ----
+        // R17〜R24 は負例 (値になりうる行の禁止文字と不正 UTF-8)。
+        'R17 値の中の NUL は形式違反' => [
+            "A=1\x00",
+            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
+        ],
+        'R18 キーの側の SOH は形式違反' => [
+            "A\x01=1",
+            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
+        ],
+        'R19 値の中の DEL は形式違反' => [
+            "A=1\x7F",
+            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
+        ],
+        'R20 値の中の TAB は形式違反 (TAB も C0 制御文字である)' => [
+            "A=1\t2",
+            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
+        ],
+        'R21 制御文字だけの行 (VT) は空行として飛ばさない' => [
+            "\x0B",
+            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
+        ],
+        'R22 FF で始まる行はコメントとして飛ばさない' => [
+            "\x0C# コメント",
+            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
+        ],
+        'R23 C1 (U+0085) を含む値は形式違反' => [
+            "A=1\u{0085}",
+            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
+        ],
+        'R24 不正 UTF-8 を含む行は形式違反 (fail-closed)' => [
+            "A=\xC3",
+            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
+        ],
+        // R25〜R28 は正例 (厳しくした側が正当な書き方を巻き込んでいないことの裏取り)。
+        'R25 TAB だけの行は空白行として飛ばす' => [
+            "\t",
+            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => []],
+        ],
+        'R26 TAB で字下げしたコメント行は違反ではない' => [
+            "\t# コメント",
+            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => []],
+        ],
+        'R27 コメント行の中の制御文字は沈黙する (保証しない範囲の明示)' => [
+            "# a\x00b",
+            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => []],
+        ],
+        'R28 妥当な多バイト値を誤検出しない' => [
+            'A=日本語',
+            ['values' => ['A' => '日本語'], 'duplicateKeys' => [], 'malformedLineNumbers' => []],
+        ],
+        // R29: CR 単独の改行でも行に割る (t1 の docblock が「反証に無い」と自ら注記していた穴)。
+        'R29 CR 単独の改行でも行に割る' => [
+            "A=1\rB=2",
+            ['values' => ['A' => '1', 'B' => '2'], 'duplicateKeys' => [], 'malformedLineNumbers' => []],
+        ],
+    ];
+}
+
+test('反証: 解析器は合成した本文を仕様どおりに分解する', /**
+ * 型注記は closure に直接付ける (将来 tests/ を PHPStan の解析対象へ入れても
+ * iterable の値の型が欠けないようにするため)。
+ *
+ * @param array{
+ *   values: array<string, string>,
+ *   duplicateKeys: list<string>,
+ *   malformedLineNumbers: list<int>,
+ * } $expected
+ */
+    function (string $contents, array $expected): void {
+        expect(envExampleParseContents($contents))->toBe($expected);
+    })->with(envExampleParseCounterexamples());
+
+/**
+ * 反証データセットの識別子 (床の検査の期待値。データ駆動の空振りを落とす)。
+ *
+ * ★**順序込みの並びが不変条件である** (床の検査は `toBe()` で比べる)。番号を詰めない・
+ *   付け替えない規約と対にしてあるので、並べ替えも赤くなる = 意図した挙動である。
+ */
+const ENV_EXAMPLE_COUNTEREXAMPLE_IDS = [
+    'R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7', 'R8', 'R9', 'R10',
+    'R11', 'R12', 'R13', 'R14', 'R15', 'R16', 'R17', 'R18', 'R19', 'R20',
+    'R21', 'R22', 'R23', 'R24', 'R25', 'R26', 'R27', 'R28', 'R29',
+];
+
+/**
+ * 誠実性の検査の負/正のコントロールの識別子 (床の検査の期待値)。
+ *
+ * ★`R<n>` 側と同じく**順序込みの並びが不変条件である**。
+ */
+const ENV_EXAMPLE_LEDGER_COUNTEREXAMPLE_IDS = [
+    'V1', 'V2', 'V3', 'V4', 'V5', 'V6', 'V7', 'V8', 'V9', 'V10',
+    'V11', 'V12', 'V13', 'V14', 'V15', 'V16', 'V17', 'V18', 'V19', 'V20', 'V21',
+    'V22', 'V23', 'V24',
+];
+
+/**
+ * データ駆動の検査のラベルから識別子を取り出す (`R<n> ` / `V<n> ` の接頭辞)。
+ *
+ * ★ラベルが規約どおりでない場合は**例外で落とす** (無言で候補から外さない = 走査器規約 (b))。
+ *
+ * @param  array<string, mixed>  $cases
+ * @return list<string>
+ */
+function envExampleCounterexampleIds(array $cases, string $prefix): array
+{
+    $ids = [];
+    foreach (array_keys($cases) as $label) {
+        $matched = preg_match('/^('.$prefix.'\d+) /', (string) $label, $m);
+        Assert::notFalse($matched, "データ駆動のラベルの判定に失敗した: {$label}");
+        Assert::same($matched, 1, "ラベルが `{$prefix}<n> ` で始まっていない: {$label}");
+        $ids[] = $m[1];
+    }
+
+    return $ids;
+}
+
+test('床: データ駆動の駆動元と解析の母集団が空でない', function (): void {
+    $parseCases = envExampleParseCounterexamples();
+    $ledgerCases = envExampleLedgerCounterexamples();
+
+    // 1. 識別子の**並び**が期待と完全一致する (1 件消しても増やしても並べ替えても赤くなる。2 系統とも見る)
+    expect(envExampleCounterexampleIds($parseCases, 'R'))->toBe(ENV_EXAMPLE_COUNTEREXAMPLE_IDS)
+        ->and(envExampleCounterexampleIds($ledgerCases, 'V'))->toBe(ENV_EXAMPLE_LEDGER_COUNTEREXAMPLE_IDS);
+
+    // 2. 解析器の反証は両方向 (違反を出すケースと出さないケース) がどちらも在る
+    $withViolation = 0;
+    $withoutViolation = 0;
+    foreach ($parseCases as $case) {
+        if ($case[1]['malformedLineNumbers'] === [] && $case[1]['duplicateKeys'] === []) {
+            $withoutViolation++;
+
+            continue;
+        }
+        $withViolation++;
+    }
+    expect($withViolation)->toBeGreaterThan(0)
+        ->and($withoutViolation)->toBeGreaterThan(0);
+
+    // 3. 台帳の負/正のコントロールも両方向が在る (期待メッセージが null = 正のコントロール)
+    $negative = 0;
+    $positive = 0;
+    foreach ($ledgerCases as $case) {
+        if ($case[2] === null) {
+            $positive++;
+
+            continue;
+        }
+        $negative++;
+    }
+    expect($negative)->toBeGreaterThan(0)
+        ->and($positive)->toBeGreaterThan(0);
+
+    // 4. 現物の走査の母集団が空でない (走査根の改名・空ファイル化で緑にならない)
+    expect(envExampleParse()['values'])->not->toBeEmpty();
+});
+
+/**
+ * 誠実性の検査の負のコントロール (V1〜V10 / V12〜V24) と正のコントロール (V11)。
+ *
+ * 合成した台帳を `envExampleLedgerViolations()` へ直接食わせる (現物の台帳を壊さずに
+ * 「壊れたら赤くなる」ことを示す)。期待値は**違反メッセージの部分一致**で持つ
+ * (件数だけを見ると、別の規則が偶然発火しても緑になるため)。
+ *
+ * ★**各規則の判定分岐を対応表で固定する** (規則 1 本につき 1 ケースではなく、分岐ごとにケースを持つ) —
+ *   規則 1 は V12 (種別の申告の余分) / V23 (種別の申告の不足) / V24 (分類の申告の余分) /
+ *   V13 (分類の申告の不足) / V14 (種別の申告が 0) / V15 (空白のみの分類名) /
+ *   V16 (分類の申告が 0)、規則 2 は V1、規則 3 は V2、
+ *   規則 4 は V3 (種別をまたいだ重複) / V22 (同一種別の中の重複)、
+ *   規則 5 は V17、規則 6 は V4 (null) / V19 (空文字) / V18 (禁止文字) / V20 (LF) / V21 (CR) /
+ *   V5 (値を持てない種別)、規則 7 は V7、規則 8 は V6、規則 9 は V8 (種別の件数) /
+ *   V9 (分類ごとの map) / V10 (分類 map の合計) が押さえる。
+ * ★キー集合の一致は**両方向**を持つ (片方向の `array_diff()` へ弱体化しても赤くなる)。
+ *   重複も**種別をまたぐ形と同一種別の中の形の両方**を持つ (旧方式の「値の固定と必須キーの
+ *   交差だけを見る」実装へ退行したら赤くなる)。
+ * ★各負例が導入する**欠陥は 1 種類だけ**である。申告件数は実件数へ合わせておき
+ *   (V22 のように entry を足す負例では `kinds` / `classifications` も同じ数へ揃える)、
+ *   狙った規則以外が発火しないようにする。それでも複数の規則が同時に発火するのを完全には
+ *   避けられないケース (V7 / V9 / V10 / V13 / V23 等) は、**期待値をその分岐固有の
+ *   メッセージで固定する**ので判定は成立する。
+ * ★ラベルの先頭の `V<n>` は**恒久の識別子**である (床の検査が順序込みで突き合わせる)。
+ *
+ * @return array<string, array{0: list<array{key: string, kind: string, classification: string, origin: string, value: string|null}>, 1: array{kinds: array<string, int>, classifications: array<string, array<string, int>>}, 2: string|null}>
+ */
+function envExampleLedgerCounterexamples(): array
+{
+    // 最小の健全な台帳 (V11 の正のコントロールと、各負例の素材)
+    $soundEntries = [
+        ['key' => 'A_PIN', 'kind' => ENV_EXAMPLE_KIND_VALUE_PIN, 'classification' => 'pins', 'origin' => '由来', 'value' => 'true'],
+        ['key' => 'B_REQUIRED', 'kind' => ENV_EXAMPLE_KIND_REQUIRED_KEY, 'classification' => 'keys', 'origin' => '由来', 'value' => null],
+    ];
+    $soundDeclared = [
+        'kinds' => [ENV_EXAMPLE_KIND_VALUE_PIN => 1, ENV_EXAMPLE_KIND_REQUIRED_KEY => 1],
+        'classifications' => [
+            ENV_EXAMPLE_KIND_VALUE_PIN => ['pins' => 1],
+            ENV_EXAMPLE_KIND_REQUIRED_KEY => ['keys' => 1],
+        ],
+    ];
+
+    /**
+     * 健全な素材の 1 entry を**同じ shape の entry で**差し替える (負例は 1 か所だけ壊す)。
+     *
+     * ★項目名と値を別々に受ける形にしない — 未知の項目の追加や `key` / `kind` / `origin` への
+     *   `null` 代入が型の上で通ってしまい、戻り値の shape の宣言と食い違うためである
+     *   (将来 tests/ を PHPStan の解析対象へ入れたときに破綻する)。呼び出し側は
+     *   `[...$soundEntries[N], '項目' => 値]` と書くので「1 か所だけ壊した」ことは差分で見える。
+     * ★`$index` は任意の `int` を取れるので、**戻り値は `array_values()` で list へ正規化する**
+     *   (範囲外の添字を渡した書き間違いが、`list` と宣言した戻り値を非連続配列にすることを防ぐ)。
+     *
+     * @param  list<array{key: string, kind: string, classification: string, origin: string, value: string|null}>  $entries
+     * @param  array{key: string, kind: string, classification: string, origin: string, value: string|null}  $entry
+     * @return list<array{key: string, kind: string, classification: string, origin: string, value: string|null}>
+     */
+    $withEntry = static function (array $entries, int $index, array $entry): array {
+        $entries[$index] = $entry;
+
+        return array_values($entries);
+    };
+
+    return [
+        'V1 空の台帳' => [
+            [],
+            [
+                'kinds' => [ENV_EXAMPLE_KIND_VALUE_PIN => 0, ENV_EXAMPLE_KIND_REQUIRED_KEY => 0],
+                'classifications' => [ENV_EXAMPLE_KIND_VALUE_PIN => [], ENV_EXAMPLE_KIND_REQUIRED_KEY => []],
+            ],
+            '台帳に entry が 1 件も無い',
+        ],
+        'V2 代入行として成立しない綴りのキー' => [
+            $withEntry($soundEntries, 0, [...$soundEntries[0], 'key' => 'a_pin']),
+            $soundDeclared,
+            'キーの綴りが env の代入行として成立しない',
+        ],
+        'V3 種別をまたいだ二重登録' => [
+            $withEntry($soundEntries, 1, [...$soundEntries[1], 'key' => 'A_PIN']),
+            $soundDeclared,
+            '台帳に 2 回現れる',
+        ],
+        'V4 値の固定に固定値が無い' => [
+            $withEntry($soundEntries, 0, [...$soundEntries[0], 'value' => null]),
+            $soundDeclared,
+            '値の固定なのに固定値が無い',
+        ],
+        'V5 必須キーに固定値がある' => [
+            $withEntry($soundEntries, 1, [...$soundEntries[1], 'value' => 'true']),
+            $soundDeclared,
+            '値を持てない種別',
+        ],
+        'V6 由来が空白のみ' => [
+            $withEntry($soundEntries, 0, [...$soundEntries[0], 'origin' => '  ']),
+            $soundDeclared,
+            '由来 (origin) が空である',
+        ],
+        'V7 未申告の分類' => [
+            $withEntry($soundEntries, 0, [...$soundEntries[0], 'classification' => 'unknown']),
+            $soundDeclared,
+            'の申告に無い',
+        ],
+        'V8 種別の申告件数が実件数と違う' => [
+            $soundEntries,
+            [
+                'kinds' => [ENV_EXAMPLE_KIND_VALUE_PIN => 2, ENV_EXAMPLE_KIND_REQUIRED_KEY => 1],
+                'classifications' => [
+                    ENV_EXAMPLE_KIND_VALUE_PIN => ['pins' => 2],
+                    ENV_EXAMPLE_KIND_REQUIRED_KEY => ['keys' => 1],
+                ],
+            ],
+            'の申告件数',
+        ],
+        'V9 分類の申告 map が実測と違う' => [
+            $soundEntries,
+            [
+                'kinds' => [ENV_EXAMPLE_KIND_VALUE_PIN => 1, ENV_EXAMPLE_KIND_REQUIRED_KEY => 1],
+                'classifications' => [
+                    ENV_EXAMPLE_KIND_VALUE_PIN => ['other' => 1],
+                    ENV_EXAMPLE_KIND_REQUIRED_KEY => ['keys' => 1],
+                ],
+            ],
+            '分類ごとの件数が申告と一致しない',
+        ],
+        'V10 分類 map の合計が種別の申告と違う' => [
+            $soundEntries,
+            [
+                'kinds' => [ENV_EXAMPLE_KIND_VALUE_PIN => 1, ENV_EXAMPLE_KIND_REQUIRED_KEY => 1],
+                'classifications' => [
+                    ENV_EXAMPLE_KIND_VALUE_PIN => ['pins' => 1, 'extra' => 1],
+                    ENV_EXAMPLE_KIND_REQUIRED_KEY => ['keys' => 1],
+                ],
+            ],
+            '合計',
+        ],
+        'V11 健全な台帳 (正のコントロール)' => [$soundEntries, $soundDeclared, null],
+        'V12 種別の申告のキー集合が既知の種別と違う' => [
+            $soundEntries,
+            [
+                'kinds' => [ENV_EXAMPLE_KIND_VALUE_PIN => 1, ENV_EXAMPLE_KIND_REQUIRED_KEY => 1, 'extra_kind' => 1],
+                'classifications' => [
+                    ENV_EXAMPLE_KIND_VALUE_PIN => ['pins' => 1],
+                    ENV_EXAMPLE_KIND_REQUIRED_KEY => ['keys' => 1],
+                ],
+            ],
+            '種別の申告のキー集合',
+        ],
+        'V13 分類の申告のキー集合が既知の種別と違う' => [
+            $soundEntries,
+            [
+                'kinds' => [ENV_EXAMPLE_KIND_VALUE_PIN => 1, ENV_EXAMPLE_KIND_REQUIRED_KEY => 1],
+                'classifications' => [ENV_EXAMPLE_KIND_VALUE_PIN => ['pins' => 1]],
+            ],
+            '分類の申告のキー集合',
+        ],
+        'V14 種別の申告件数が 0' => [
+            [$soundEntries[1]],
+            [
+                'kinds' => [ENV_EXAMPLE_KIND_VALUE_PIN => 0, ENV_EXAMPLE_KIND_REQUIRED_KEY => 1],
+                'classifications' => [
+                    ENV_EXAMPLE_KIND_VALUE_PIN => [],
+                    ENV_EXAMPLE_KIND_REQUIRED_KEY => ['keys' => 1],
+                ],
+            ],
+            '種別 value_pin の申告件数が 1 未満',
+        ],
+        'V15 分類名が空白のみ' => [
+            $soundEntries,
+            [
+                'kinds' => [ENV_EXAMPLE_KIND_VALUE_PIN => 1, ENV_EXAMPLE_KIND_REQUIRED_KEY => 1],
+                'classifications' => [
+                    ENV_EXAMPLE_KIND_VALUE_PIN => ['pins' => 1, '  ' => 1],
+                    ENV_EXAMPLE_KIND_REQUIRED_KEY => ['keys' => 1],
+                ],
+            ],
+            '空白のみの分類名',
+        ],
+        'V16 分類の申告件数が 0' => [
+            $soundEntries,
+            [
+                'kinds' => [ENV_EXAMPLE_KIND_VALUE_PIN => 1, ENV_EXAMPLE_KIND_REQUIRED_KEY => 1],
+                'classifications' => [
+                    ENV_EXAMPLE_KIND_VALUE_PIN => ['pins' => 0],
+                    ENV_EXAMPLE_KIND_REQUIRED_KEY => ['keys' => 1],
+                ],
+            ],
+            '申告件数が 1 未満',
+        ],
+        'V17 未知の種別' => [
+            $withEntry($soundEntries, 0, [...$soundEntries[0], 'kind' => 'unknown_kind']),
+            $soundDeclared,
+            '未知の種別',
+        ],
+        'V18 固定値に禁止文字が含まれる' => [
+            $withEntry($soundEntries, 0, [...$soundEntries[0], 'value' => "true\x01"]),
+            $soundDeclared,
+            '固定値に改行または禁止文字',
+        ],
+        'V19 固定値が空文字' => [
+            $withEntry($soundEntries, 0, [...$soundEntries[0], 'value' => '']),
+            $soundDeclared,
+            '値の固定なのに固定値が無い',
+        ],
+        'V20 固定値に LF が含まれる' => [
+            $withEntry($soundEntries, 0, [...$soundEntries[0], 'value' => "true\ntrue"]),
+            $soundDeclared,
+            '固定値に改行または禁止文字',
+        ],
+        'V21 固定値に CR が含まれる' => [
+            $withEntry($soundEntries, 0, [...$soundEntries[0], 'value' => "true\rtrue"]),
+            $soundDeclared,
+            '固定値に改行または禁止文字',
+        ],
+        // ---- 規則 4 / 規則 1 の**反対方向**の分岐 (V3 / V12 / V13 だけでは片方向しか押さえられない) ----
+        'V22 同一種別の中の重複' => [
+            [$soundEntries[0], $soundEntries[0], $soundEntries[1]],
+            [
+                'kinds' => [ENV_EXAMPLE_KIND_VALUE_PIN => 2, ENV_EXAMPLE_KIND_REQUIRED_KEY => 1],
+                'classifications' => [
+                    ENV_EXAMPLE_KIND_VALUE_PIN => ['pins' => 2],
+                    ENV_EXAMPLE_KIND_REQUIRED_KEY => ['keys' => 1],
+                ],
+            ],
+            '台帳に 2 回現れる',
+        ],
+        'V23 種別の申告のキーが不足している' => [
+            $soundEntries,
+            [
+                'kinds' => [ENV_EXAMPLE_KIND_VALUE_PIN => 1],
+                'classifications' => [
+                    ENV_EXAMPLE_KIND_VALUE_PIN => ['pins' => 1],
+                    ENV_EXAMPLE_KIND_REQUIRED_KEY => ['keys' => 1],
+                ],
+            ],
+            '種別の申告のキー集合',
+        ],
+        'V24 分類の申告に余分な種別がある' => [
+            $soundEntries,
+            [
+                'kinds' => [ENV_EXAMPLE_KIND_VALUE_PIN => 1, ENV_EXAMPLE_KIND_REQUIRED_KEY => 1],
+                'classifications' => [
+                    ENV_EXAMPLE_KIND_VALUE_PIN => ['pins' => 1],
+                    ENV_EXAMPLE_KIND_REQUIRED_KEY => ['keys' => 1],
+                    'extra_kind' => ['x' => 1],
+                ],
+            ],
+            '分類の申告のキー集合',
+        ],
+    ];
+}
+
+test('負のコントロール: 誠実性の検査は壊れた台帳を検出し、健全な台帳を誤検出しない', /**
+ * @param  list<array{key: string, kind: string, classification: string, origin: string, value: string|null}>  $entries
+ * @param  array{kinds: array<string, int>, classifications: array<string, array<string, int>>}  $declared
+ */
+    function (array $entries, array $declared, ?string $expected): void {
+        $violations = envExampleLedgerViolations($entries, $declared);
+
+        if ($expected === null) {
+            expect($violations)->toBe([]);
+
+            return;
+        }
+
+        expect($violations)->not->toBe([]);
+        $matched = array_filter(
+            $violations,
+            static fn (string $violation): bool => str_contains($violation, $expected),
+        );
+        expect($matched)->not->toBe([], "期待した違反が出ていない: {$expected} / 実際: ".implode(' | ', $violations));
+    })->with(envExampleLedgerCounterexamples());
+
+/*
+ * 検査の前提そのものの固定 (正典 i12)。見本の値がテスト実行時の env として効いていたら、
+ * 「見本を検査している」という主張が反転しうる (見本を書き換えれば実行時の設定も動く)。
+ *
+ * ★主張は「**このリポジトリの見本 1 枚を env として選んでいない**」ことに限る。
+ *   許可する env 名の集合までは固定しない (`.env.ci` のような正当な env 名を足しただけで
+ *   落ちるのは過剰である)。
+ * ★2 段で見る。(1) 解決済みの絶対パスの一致、(2) ファイル名の一致。(2) は別ディレクトリの
+ *   同名の見本を経由する形まで拒む「拾いすぎる側」の検査で、走査器規約 (b) の
+ *   「見逃す方向へ倒すのは不可」に従って併置する。
+ * ★見本の `realpath()` が解決できないことは合格にせず**不合格**にする (fail-closed)。
+ *   symlink は `realpath()` が解決した先で比べる (リンク越しに見本を env にする形も落ちる)。
+ * ★`environmentFilePath()` は「読む場所の指定」であって「実際に読んだ結果」ではない。
+ *   `.env` が存在しない場合も同じ値を返すので、**そのファイルが実在したこと**は主張しない。
+ */
+test('前提: テスト実行時に読み込まれている env ファイルが見本ではない', function (): void {
+    $samplePath = realpath(base_path(ENV_EXAMPLE_PATH));
+    Assert::string($samplePath, '見本ファイルの実パスを解決できない: '.ENV_EXAMPLE_PATH);
+
+    $loadedPath = app()->environmentFilePath();
+    $loadedReal = realpath($loadedPath);
+
+    // (1) 絶対パスの一致 (解決できない env は「まだ存在しない env」なので生の文字列で比べる)
+    expect(is_string($loadedReal) ? $loadedReal : $loadedPath)->not->toBe($samplePath);
+
+    // (2) ファイル名の一致 (別ディレクトリの同名見本も拒む)
+    expect(basename($loadedPath))->not->toBe(ENV_EXAMPLE_PATH);
+});
 
 /*
  * env ファイルの `${VAR}` nested variable は「同一ファイル内の先行定義 or 実行環境変数」しか
```

## 依頼

Round 2 の Warning 2 件 (小項目 3 点) が解消されているかを確認し、
全体判定を APPROVED または CHANGES_REQUESTED で明記せよ。
