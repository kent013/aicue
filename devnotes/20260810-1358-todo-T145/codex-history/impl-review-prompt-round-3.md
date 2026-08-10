# Round 3: Round 2 の Critical / Warning への対応結果

## 対応マトリクス

# 対応マトリクス: impl-review Round 2

Codex 判定 **CHANGES_REQUESTED** (Critical 1 / Warning 1)。両方とも指摘が正しく、
いずれも Architecture テスト内で完結するため対応した。

## [Critical] 自己参照コントロールが SoT と逆 (hit 0 件であるべきところを「必ず点灯」で固定していた)

- 判断: **対応する** (指摘のとおり。こちらの実装が共通 SoT の要件と逆だった)
- 根拠: 共通要件は「gate ファイル自身を走査して hit 0 件 (説明コメントで偽赤にならない)」である。
  「必ず点灯する」を固定すると、
  (a) SoT の求める性質を検査していない
  (b) fixture 以外の説明文でも点灯するので、対象 fixture を壊しても緑のままになりうる (循環)
  (c) そもそも gate が自分の記述で偽赤になりうる状態を**固定して**しまう
  という 3 重に悪い形だった。
- 対応内容:
  1. gate ファイルの**生ソースから年数の表記を一掃**した。
     - 検査 8 の fixture は連結で組み立てる (`$y = (string) 7; $era = '年';` → `'最長 '.$y.' '.$era.'間'`)。
     - docblock / コメント / 失敗メッセージからも生の表記を除去
       (例: 検査 2 のメッセージは `.BILLING_RETENTION_OWNER_DECIDED_YEARS.` で組み立てる)。
  2. 検査 7 の自己参照コントロールを **`->toBe([])`** に反転した。
  3. 「検出器が生きていること」の担保は**検査 8 の負のコントロール**へ役割分担した
     (自己参照コントロールと正の自己検証を同じ assert で兼ねない)。
- **mutation 実測**: gate ファイルへ `// 説明コメント: 取引関係書類等は最長 7 年間保有する。` を
  1 行足すと **検査 7 が赤**。循環していないことを確認済み (変異は戻した)。

## [Warning] `billingRetentionAliasNames()` が class import の文脈を見ていない

- 判断: **対応する**
- 根拠: `use function ... as X` はシンボル表が別であり、別 namespace の同名クラスの alias を
  SSOT の alias として登録するのは端的に誤検出である。exact-fit を名乗る以上、
  alias 解決は FQCN で厳密に行うべき。
- 対応内容: `use` 文をきちんと構文解析する形へ書き直した。
  - `use function` / `use const` は head トークンで除外。
  - closure の `use (` は head が名前トークンでないことで除外。
  - **group use** (`use App\Support\Legal\{BillingRetention as X};`) は prefix を結合して FQCN を復元。
  - **FQCN が `App\Support\Legal\BillingRetention` と厳密一致**したときだけ alias を登録。
  - **trait adaptation** (`use T { Foo as X; }`) は bare name の直後が `,`/`;` でないため entry 解析を打ち切る
    (brace 深度の追跡は不要になった)。
  - 負のコントロールを **検査 9** として独立させ、Codex が挙げた 5 形をすべて固定した
    (class import 1 件 / 完全修飾 1 件 / group 1 件 / `use function`・`use const` 0 件 /
    別 namespace 0 件 / trait adaptation 0 件 / closure 0 件 / alias 無し 0 件)。
    さらに「その alias が呼び出しとして数えられないこと」まで `ssotCall === 0` で見る。

## 併せて明示した「保証しないもの」

呼び出し検出は最終セグメント一致も併用するため、**別 namespace の同名クラスの呼び出しを
呼び出し元として数える**(過検出)。これは deny-by-default の目録として意図的に過検出側へ
倒した判断であり、docblock に明記した (alias 解決側は逆に FQCN 厳密一致)。


## 再検証の実測

- `composer phpstan` … OK (871 files, no errors)
- `vendor/bin/pint --test` … passed
- `composer test` (全体) … tests=4308 passed=4306 skipped=2 assertions=18554
- 対象 2 ファイル … 15 tests passed
- **自己参照 mutation** … gate へ生の年数表記を 1 行足すと **検査 7 が赤** (循環していない)
- **M14 mutation** … blade を literal にすると 検査 6 / 検査 7 / Feature (e) が赤 (維持)

## Architecture gate の HEAD からの累積 diff

```diff
diff --git a/tests/Architecture/BillingRetentionConfigSingleSourceTest.php b/tests/Architecture/BillingRetentionConfigSingleSourceTest.php
index 2eb4377..42d8cf8 100644
--- a/tests/Architecture/BillingRetentionConfigSingleSourceTest.php
+++ b/tests/Architecture/BillingRetentionConfigSingleSourceTest.php
@@ -3,37 +3,66 @@
 declare(strict_types=1);
 
 use App\Support\Legal\BillingRetention;
+use Illuminate\Support\Facades\Blade;
 
 /*
  * Architecture invariant: 課金取引記録の保持年数 (legal.billing_retention_years) の
- * **解決点は App\Support\Legal\BillingRetention の 1 箇所だけ**である。
+ * **解決点は App\Support\Legal\BillingRetention の 1 箇所だけ**であり、
+ * **規約文面 (/privacy) はその 1 箇所から描画される**。
  *
- * SoT = devnotes/20260809-0908-account-deletion-grace/detailed-design.md の PR-C1 (C1a)
- * とオーナー決定 (課金取引記録の保持 = 7 年)。
+ * SoT = devnotes/20260809-0908-account-deletion-grace/detailed-design.md の
+ * PR-C1 (C1a) / PR-C3 (C3b) とオーナー決定 (課金取引記録の保持年数)。
  *
  * 背景: この数値は「環境ごとに変えてよい運用値」ではなく、**法務文書 (/privacy) が
  * 宣言する値そのもの**である。読む場所が分岐すると「規約が宣言した年数」と
  * 「実際に消える年数」が静かにズレる — 利用者から見て検証不能な形で規約違反が起きる。
- * よって (a) env を使わない (b) config を読むのは SSOT クラス 1 箇所だけ、を機械固定する。
+ * よって (a) env を使わない (b) config を読むのは SSOT クラス 1 箇所だけ
+ * (c) 文面も literal を持たず SSOT から描画する、を機械固定する。
  *
  * ★この gate が保証するもの:
- *   - 検査 1: `'legal.billing_retention_years'` を読むのは BillingRetention だけ (app/ 走査)
+ *   - 検査 1: `'legal.billing_retention_years'` を読むのは BillingRetention だけ
+ *     (app/ config/ database/ routes/ **resources/views/** 走査。blade も直読しない)
  *   - 検査 2: config/legal.php の値が **整数リテラル**である (env() 経由で環境依存にしない)
  *     かつ**オーナー決定の 7** である
  *   - 検査 3: 実行時の `BillingRetention::years()` が config リテラルと一致する
  *   - 検査 4: 空振り検知 (走査ファイル数 / token 数が 0 でない) と
  *     正の自己検証 (SSOT ファイルで検出器が実際に点灯する)
  *   - 検査 5: 負のコントロール (fixture ソースで点灯 / コメント中の表記は点灯しない)
+ *   - 検査 6: `BillingRetention::years()` / `::threshold()` の呼び出し元が
+ *     **exact-fit の目録**と一致する (privacy blade もここに載る)
+ *   - 検査 7: privacy blade が保持年数の **literal を 1 つも持たない**
+ *     (散文の「N 年」も `@php` 内の整数リテラルも両方見る) かつ
+ *     SSOT 呼び出しをちょうど 1 回持つ。あわせて**自己参照コントロール**
+ *     (本 gate ファイル自身を走査して hit 0 件 = 説明・fixture で偽赤にならない)
+ *   - 検査 8: 検査 6/7 の検出器の負のコントロール (fixture で点灯すること)
+ *   - 検査 9: alias 解決の負のコントロール (class import の文脈だけを見ていること)
  *
  * ★この gate が保証しないもの (誇張しない):
- *   - **tests/ は走査しない**。保持年数の fail-fast (0 以下) を検証するテストは
- *     config を書き換える必要があり、そこを禁止すると検査そのものが書けなくなる
- *   - **規約文面 (privacy blade) との一致は見ない**。文面はまだ存在せず (PR-C3 の担当)、
- *     三者一致 (config / SSOT / 文面) の gate は PR-C3 で本 gate の上に積む
- *   - 動的キー組み立て (`config('legal.'.$key)`) には沈黙する (実測 0 件)
+ *   - **文面の日本語が法的に正しいか / その年数が法令上妥当か**は見ない。現在の文面は
+ *     **法務レビュー前の草案**であり、「実装が宣言する年数」と「法務が確定する年数」が
+ *     一致することの確認は**人間の仕事**である
+ *   - **散文の意味と実処理の一致**は見ない (機械が見るのは数値 1 つとマーカーの存在だけ)。
+ *     描画結果の側からの照合は tests/Feature/Legal/PrivacyRetentionDeclarationTest.php
+ *   - **「文面が変わったのに版が上がっていない」ことは見ない**
+ *     (本タスクでは `consent_version` を draft-1 から動かさないため)
+ *   - 検査 1 の走査に **tests/ は含めない**。保持年数の fail-fast (0 以下) を検証する
+ *     テストは config を書き換える必要があり、そこを禁止すると検査そのものが書けなくなる
+ *     (検査 6 の呼び出し元目録だけは tests/ も母集団に含む)
+ *   - 動的キー組み立て (`config('legal.'.$key)`) / 変数経由の呼び出しには沈黙する
+ *   - 呼び出し検出は **class 名の最終セグメント一致**も併用する。よって別 namespace の
+ *     同名クラス (`Other\Domain\BillingRetention::years()`) を**呼び出し元として数える**。
+ *     これは意図的に**過検出側**へ倒してある (deny-by-default の目録なので、余分に赤くなる分は
+ *     人間が目録で判断できるが、取りこぼしは静かに素通りする)。alias 解決の側は逆に
+ *     FQCN 厳密一致で、別 namespace の alias を登録しない (検査 9)
+ *   - 検査 7 の漢数字判定は **1〜99** のみ対応する (それを超える保持年数は ASCII /
+ *     全角数字の形しか検出しない)
+ *   - privacy blade **以外**の blade に年数の literal が書かれても検査 7 は沈黙する
+ *     (規約文面の所在は 1 ファイルに固定されている前提)
  *
  * 検出方式は LegalConsentVersionSingleSourceTest と同じ token 走査
- * (regex にすると本ファイルの説明コメント自身で偽赤になる)。DB 不使用。
+ * (regex にすると本ファイルの説明コメント自身で偽赤になる)。blade は
+ * `Blade::compileString()` で PHP へ落としてから token 走査する
+ * (`{{ ... }}` は素の PHP ではないため token_get_all では見えない)。DB 不使用。
  */
 
 /** 設定キー: SSOT だけが読んでよい。 */
@@ -45,39 +74,233 @@
 /** 単一出典クラス (repo ルート相対)。 */
 const BILLING_RETENTION_SOURCE_FILE = 'app/Support/Legal/BillingRetention.php';
 
+/** 単一出典クラスの FQCN。alias 解決は**この完全名との一致**だけを認める。 */
+const BILLING_RETENTION_FQCN = 'App\Support\Legal\BillingRetention';
+
+/** 規約文面 (repo ルート相対)。保持年数を宣言する唯一の view。 */
+const BILLING_RETENTION_PRIVACY_VIEW = 'resources/views/legal/privacy.blade.php';
+
+/** 本 gate 自身 (自己参照コントロールの対象)。 */
+const BILLING_RETENTION_GATE_FILE = 'tests/Architecture/BillingRetentionConfigSingleSourceTest.php';
+
 /** オーナー決定の保持年数 (逸脱不可。変更は規約文面の変更と同義)。 */
 const BILLING_RETENTION_OWNER_DECIDED_YEARS = 7;
 
+/** 検査 1 (config 直読) の走査対象。tests/ は含めない (docblock の「保証しないもの」参照)。 */
+const BILLING_RETENTION_CONFIG_SCAN_DIRS = ['app', 'config', 'database', 'routes', 'resources/views'];
+
+/** 検査 6 (呼び出し元目録) の走査対象。目録は tests/ も母集団に含む。 */
+const BILLING_RETENTION_CALLER_SCAN_DIRS = ['app', 'config', 'database', 'routes', 'resources/views', 'tests'];
+
+/**
+ * 検査 6 の exact-fit inventory: BillingRetention::years() / ::threshold() を
+ * 呼んでよい repo ルート相対パス。**allowlist ではない** — 増えても減っても fail する。
+ * 保持年数に新しく依存する経路を足すときはここへ登録すること (= レビューの目に必ず入る)。
+ *
+ * 本 gate ファイル自身も検査 3 で years() を呼ぶため目録に載せている
+ * (隠れた除外を作らず、exact-fit を文字通りにするため)。
+ *
+ * @var list<string>
+ */
+const BILLING_RETENTION_CALLERS = [
+    'app/Console/Commands/Billing/PurgeBillingRetentionCommand.php',
+    'resources/views/legal/privacy.blade.php',
+    'tests/Architecture/BillingRetentionConfigSingleSourceTest.php',
+    'tests/Feature/Billing/BillingRetentionHorizonTest.php',
+    'tests/Feature/Billing/BillingRetentionPurgeTest.php',
+    'tests/Feature/Billing/TicketLedgerCarryForwardTest.php',
+    'tests/Feature/Legal/PrivacyRetentionDeclarationTest.php',
+];
+
+/**
+ * 空白・コメントを飛ばして次の意味のあるトークン位置を返す。
+ *
+ * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
+ */
+function billingRetentionNextMeaningful(array $tokens, int $index): ?int
+{
+    $count = count($tokens);
+    for ($i = $index + 1; $i < $count; $i++) {
+        $token = $tokens[$i];
+        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
+            continue;
+        }
+
+        return $i;
+    }
+
+    return null;
+}
+
+/**
+ * `use App\Support\Legal\BillingRetention as Retention;` の alias 名を集める。
+ *
+ * alias を解決しないと `Retention::years()` が呼び出し元目録を素通りしてしまう
+ * (exact-fit を名乗る以上、この抜け道は塞ぐ)。
+ *
+ * **class import だけを見る**。以下は alias として登録しない:
+ * - `use function ... as X;` / `use const ... as X;` (シンボル表が別)
+ * - **別 namespace の** `BillingRetention as X` (FQCN で厳密比較する)
+ * - trait adaptation の `use T { Foo as X; }` (bare name の直後が `{` なので entry 解析を打ち切る)
+ * - closure の `use ($x)` (直後が `(` なので import ではない)
+ *
+ * group use (`use App\Support\Legal\{BillingRetention as X};`) は prefix を結合して解決する。
+ *
+ * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
+ * @return list<string>
+ */
+function billingRetentionAliasNames(array $tokens): array
+{
+    /** @var list<string> $aliases */
+    $aliases = [];
+    $count = count($tokens);
+    $nameTokenIds = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];
+
+    for ($i = 0; $i < $count; $i++) {
+        $token = $tokens[$i];
+        if (! is_array($token) || $token[0] !== T_USE) {
+            continue;
+        }
+
+        $head = billingRetentionNextMeaningful($tokens, $i);
+        if ($head === null || ! is_array($tokens[$head])) {
+            continue; // closure の `use (` 等
+        }
+        if (! in_array($tokens[$head][0], $nameTokenIds, true)) {
+            continue; // `use function` / `use const`
+        }
+
+        // group use かどうか: `Prefix` `\` `{`
+        $prefix = '';
+        $cursor = $head - 1;
+        $separator = billingRetentionNextMeaningful($tokens, $head);
+        if ($separator !== null
+            && is_array($tokens[$separator])
+            && $tokens[$separator][0] === T_NS_SEPARATOR) {
+            $brace = billingRetentionNextMeaningful($tokens, $separator);
+            if ($brace === null || $tokens[$brace] !== '{') {
+                continue;
+            }
+            $prefix = $tokens[$head][1];
+            $cursor = $brace;
+        }
+
+        // entry を `,` 区切りで読む (想定外のトークンが来たらその use 文は打ち切る)
+        while (true) {
+            $nameIndex = billingRetentionNextMeaningful($tokens, $cursor);
+            if ($nameIndex === null
+                || ! is_array($tokens[$nameIndex])
+                || ! in_array($tokens[$nameIndex][0], $nameTokenIds, true)) {
+                break;
+            }
+            $name = $tokens[$nameIndex][1];
+            $alias = null;
+            $cursor = $nameIndex;
+
+            $next = billingRetentionNextMeaningful($tokens, $cursor);
+            if ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_AS) {
+                $aliasIndex = billingRetentionNextMeaningful($tokens, $next);
+                if ($aliasIndex === null
+                    || ! is_array($tokens[$aliasIndex])
+                    || $tokens[$aliasIndex][0] !== T_STRING) {
+                    break;
+                }
+                $alias = $tokens[$aliasIndex][1];
+                $cursor = $aliasIndex;
+                $next = billingRetentionNextMeaningful($tokens, $cursor);
+            }
+
+            $fqcn = ltrim($prefix === '' ? $name : $prefix.'\\'.$name, '\\');
+            if ($alias !== null && $fqcn === BILLING_RETENTION_FQCN) {
+                $aliases[] = $alias;
+            }
+
+            if ($next === null || $tokens[$next] !== ',') {
+                break; // `;` / `}` / trait adaptation の `{` など
+            }
+            $cursor = $next;
+        }
+    }
+
+    return array_values(array_unique($aliases));
+}
+
 /**
- * 1 ソースを走査して出現数を返す (純関数 = 負のコントロールから直接呼べる)。
+ * 1 ソース (素の PHP) を走査して出現数を返す (純関数 = 負のコントロールから直接呼べる)。
  *
- * @return array{configKey: int, tokens: int}
+ * @return array{configKey: int, ssotCall: int, tokens: int}
  */
 function billingRetentionScanSource(string $source): array
 {
-    $result = ['configKey' => 0, 'tokens' => 0];
+    $tokens = token_get_all($source);
+    $count = count($tokens);
+    $aliases = billingRetentionAliasNames($tokens);
+    $result = ['configKey' => 0, 'ssotCall' => 0, 'tokens' => 0];
 
-    foreach (token_get_all($source) as $token) {
+    for ($i = 0; $i < $count; $i++) {
+        $token = $tokens[$i];
         if (! is_array($token)) {
             continue;
         }
         $result['tokens']++;
-        if ($token[0] !== T_CONSTANT_ENCAPSED_STRING) {
+        [$id, $value] = $token;
+
+        if ($id === T_CONSTANT_ENCAPSED_STRING) {
+            if (trim($value, "'\"") === BILLING_RETENTION_CONFIG_KEY) {
+                $result['configKey']++;
+            }
+
+            continue;
+        }
+
+        // BillingRetention::years() / ::threshold()
+        // (部分修飾・完全修飾・`use ... as` の alias を問わない)
+        if ($id !== T_STRING && $id !== T_NAME_QUALIFIED && $id !== T_NAME_FULLY_QUALIFIED) {
+            continue;
+        }
+        $segments = explode('\\', $value);
+        if (end($segments) !== 'BillingRetention' && ! in_array($value, $aliases, true)) {
             continue;
         }
-        if (trim($token[1], "'\"") === BILLING_RETENTION_CONFIG_KEY) {
-            $result['configKey']++;
+        $doubleColon = billingRetentionNextMeaningful($tokens, $i);
+        if ($doubleColon === null
+            || ! is_array($tokens[$doubleColon])
+            || $tokens[$doubleColon][0] !== T_DOUBLE_COLON) {
+            continue; // `use App\Support\Legal\BillingRetention;` 等は呼び出しではない
+        }
+        $method = billingRetentionNextMeaningful($tokens, $doubleColon);
+        if ($method !== null
+            && is_array($tokens[$method])
+            && $tokens[$method][0] === T_STRING
+            && in_array($tokens[$method][1], ['years', 'threshold'], true)) {
+            $result['ssotCall']++;
         }
     }
 
     return $result;
 }
 
+/**
+ * 走査用に PHP ソースを取り出す。blade は `{{ ... }}` が素の PHP ではないため
+ * `Blade::compileString()` で PHP へ落としてから走査する。
+ */
+function billingRetentionSourceForScan(string $absolutePath): ?string
+{
+    $source = file_get_contents($absolutePath);
+    if (! is_string($source)) {
+        return null;
+    }
+
+    return str_ends_with($absolutePath, '.blade.php')
+        ? Blade::compileString($source)
+        : $source;
+}
+
 /**
  * repo ルート相対パス => 走査結果。
  *
  * @param  list<string>  $dirs
- * @return array<string, array{configKey: int, tokens: int}>
+ * @return array<string, array{configKey: int, ssotCall: int, tokens: int}>
  */
 function billingRetentionScanTree(array $dirs): array
 {
@@ -96,8 +319,8 @@ function billingRetentionScanTree(array $dirs): array
             if (! is_string($absolute)) {
                 continue;
             }
-            $source = file_get_contents($absolute);
-            if (! is_string($source)) {
+            $source = billingRetentionSourceForScan($absolute);
+            if ($source === null) {
                 continue;
             }
             $scanned[substr($absolute, strlen($root) + 1)] = billingRetentionScanSource($source);
@@ -155,9 +378,80 @@ function billingRetentionConfigLiteral(): ?int
     return null;
 }
 
+/**
+ * 整数を漢数字へ変換する (1〜99 のみ。範囲外は null)。
+ *
+ * 漢数字で書かれた年数の literal を検出するために使う。
+ */
+function billingRetentionKanjiNumeral(int $value): ?string
+{
+    if ($value < 1 || $value > 99) {
+        return null;
+    }
+
+    $digits = ['', '一', '二', '三', '四', '五', '六', '七', '八', '九'];
+
+    if ($value < 10) {
+        return $digits[$value];
+    }
+
+    $tens = intdiv($value, 10);
+    $ones = $value % 10;
+
+    return ($tens > 1 ? $digits[$tens] : '').'十'.($ones > 0 ? $digits[$ones] : '');
+}
+
+/**
+ * 年数を「N 年」の形で書いた散文 literal を blade の**生ソース**から探す。
+ *
+ * ASCII 数字 / 全角数字 / 漢数字の 3 表記に対応し、数字と「年」の間の空白は許容する。
+ * 生ソースを見るのは、`{{ ... }}` の中身 (= SSOT 呼び出し) には数字が現れないためで、
+ * 逆に literal を書けば必ず「N 年」の形で現れるという文面側の性質を利用している。
+ *
+ * @return list<string> 検出した表記 (空なら違反なし)
+ */
+function billingRetentionProseYearLiterals(string $rawSource, int $years): array
+{
+    $needles = [
+        (string) $years,
+        mb_convert_kana((string) $years, 'N'),
+    ];
+    $kanji = billingRetentionKanjiNumeral($years);
+    if ($kanji !== null) {
+        $needles[] = $kanji;
+    }
+
+    $hits = [];
+    foreach (array_unique($needles) as $needle) {
+        // 数字境界を要求する。これが無いと years=7 のとき「17 年」「70 年」で偽赤になる。
+        $pattern = '/(?<![0-9０-９])'.preg_quote($needle, '/').'(?![0-9０-９])\s*年/u';
+        if (preg_match($pattern, $rawSource) === 1) {
+            $hits[] = $needle.'年';
+        }
+    }
+
+    return $hits;
+}
+
+/**
+ * compile 済み blade の PHP コード側に年数の整数リテラルが現れるかを見る
+ * (`@php $y = 7; @endphp` のような迂回を塞ぐ)。
+ */
+function billingRetentionCodeYearLiteralCount(string $compiledSource, int $years): int
+{
+    $count = 0;
+    foreach (token_get_all($compiledSource) as $token) {
+        if (is_array($token) && $token[0] === T_LNUMBER && (int) $token[1] === $years) {
+            $count++;
+        }
+    }
+
+    return $count;
+}
+
 test('検査 1: 保持年数の config キーを読むのは BillingRetention だけである', function (): void {
     $violations = [];
-    foreach (billingRetentionScanTree(['app', 'config', 'database', 'routes']) as $relative => $scan) {
+    foreach (billingRetentionScanTree(BILLING_RETENTION_CONFIG_SCAN_DIRS) as $relative => $scan) {
         if ($scan['configKey'] > 0 && $relative !== BILLING_RETENTION_SOURCE_FILE) {
             $violations[] = $relative;
         }
@@ -177,7 +471,8 @@ function billingRetentionConfigLiteral(): ?int
         'config/legal.php の billing_retention_years が整数リテラルではありません。'
         .'env() を挟むと環境ごとに保持年数が変わり、規約の宣言が環境依存の嘘になります。');
     expect($literal)->toBe(BILLING_RETENTION_OWNER_DECIDED_YEARS,
-        '保持年数はオーナー決定 (7 年) です。変更は規約文面の変更と同義であり、'
+        '保持年数はオーナー決定の '.BILLING_RETENTION_OWNER_DECIDED_YEARS.' です。'
+        .'変更は規約文面の変更と同義であり、'
         .'このテストと /privacy の文面を同じ PR で更新すること。');
 });
 
@@ -186,13 +481,17 @@ function billingRetentionConfigLiteral(): ?int
 });
 
 test('検査 4: 空振り検知と正の自己検証', function (): void {
-    $scanned = billingRetentionScanTree(['app', 'config', 'database', 'routes']);
+    $scanned = billingRetentionScanTree(BILLING_RETENTION_CONFIG_SCAN_DIRS);
 
     expect(count($scanned))->toBeGreaterThan(0);
     expect(array_sum(array_column($scanned, 'tokens')))->toBeGreaterThan(0);
 
     // 検出器が死んでいたら検査 1 は vacuous green になる。SSOT では必ず 1 件点灯する。
     expect($scanned[BILLING_RETENTION_SOURCE_FILE]['configKey'])->toBe(1);
+
+    // blade も母集団に入っている (compile が空振りして走査対象から落ちていない)
+    expect($scanned)->toHaveKey(BILLING_RETENTION_PRIVACY_VIEW);
+    expect($scanned[BILLING_RETENTION_PRIVACY_VIEW]['tokens'])->toBeGreaterThan(0);
 });
 
 test('検査 5: 負のコントロール (リテラルは検出し、コメント中の表記は検出しない)', function (): void {
@@ -221,3 +520,175 @@ public function run(): void {}
     expect(billingRetentionScanSource($comment)['configKey'])->toBe(0);
     expect(billingRetentionScanSource($comment)['tokens'])->toBeGreaterThan(0);
 });
+
+test('検査 6: BillingRetention::years()/::threshold() の呼び出し元が目録と exact-fit である', function (): void {
+    $callers = [];
+    foreach (billingRetentionScanTree(BILLING_RETENTION_CALLER_SCAN_DIRS) as $relative => $scan) {
+        if ($scan['ssotCall'] > 0 && $relative !== BILLING_RETENTION_SOURCE_FILE) {
+            $callers[] = $relative;
+        }
+    }
+    sort($callers);
+
+    expect($callers)->toBe(BILLING_RETENTION_CALLERS,
+        '保持年数 (BillingRetention::years() / ::threshold()) の依存元が増減しました。'
+        .'新しい経路なら BILLING_RETENTION_CALLERS へ登録し、消えたなら目録から外してください '
+        .'(allowlist ではなく exact-fit の目録です)。実測: '.PHP_EOL.implode(PHP_EOL, $callers));
+});
+
+test('検査 7: privacy blade が年数の literal を持たず SSOT から描画している', function (): void {
+    $raw = file_get_contents(base_path(BILLING_RETENTION_PRIVACY_VIEW));
+    expect($raw)->toBeString();
+    $raw = (string) $raw;
+
+    $years = BillingRetention::years();
+
+    // 正の自己検証: SSOT 呼び出しがちょうど 1 回ある (0 なら文面が数値を失っている)
+    $compiled = Blade::compileString($raw);
+    expect(billingRetentionScanSource($compiled)['ssotCall'])->toBe(1,
+        '/privacy の保持年数は App\Support\Legal\BillingRetention::years() から'
+        .'ちょうど 1 回描画してください。');
+
+    // 散文側の literal (ASCII / 全角 / 漢数字の 3 表記)
+    expect(billingRetentionProseYearLiterals($raw, $years))->toBe([],
+        '/privacy の文面に保持年数の literal を検出しました。config / SSOT / 文面の'
+        .'三者一致が壊れるため、必ず BillingRetention::years() から描画してください。');
+
+    // コード側の literal (@php ブロック等の迂回)
+    expect(billingRetentionCodeYearLiteralCount($compiled, $years))->toBe(0,
+        '/privacy の blade コード側に保持年数と同じ整数リテラルを検出しました。');
+
+    // **自己参照コントロール**: 本 gate ファイル自身を走査して **hit 0 件**であること。
+    // 説明コメントや fixture に年数の表記を生ソースのまま書くと、gate は自分の記述で
+    // 偽赤になる (= 検査を弱める方向の圧力が生まれる)。そうならないよう、
+    // 検査 8 の fixture は連結で組み立て、コメントにも生の表記を残していない。
+    // 「検出器が生きていること」は検査 8 の負のコントロールが担う (役割を分ける)。
+    $self = file_get_contents(base_path(BILLING_RETENTION_GATE_FILE));
+    expect($self)->toBeString();
+    expect(billingRetentionProseYearLiterals((string) $self, $years))->toBe([],
+        '本 gate ファイル自身に保持年数の表記が生ソースで現れています。'
+        .'fixture は連結で組み立て、説明は表記そのものを書かずに述べてください '
+        .'(自己参照で偽赤になる gate は、検査を弱める圧力になります)。');
+});
+
+test('検査 8: 負のコントロール (呼び出し / 年数 literal の検出器が実際に点灯する)', function (): void {
+    // 呼び出しは検出し、use 文だけは呼び出しに数えない
+    $called = <<<'PHP'
+    <?php
+    use App\Support\Legal\BillingRetention;
+    class Fixture {
+        public function run(): void {
+            $a = BillingRetention::years();
+            $b = \App\Support\Legal\BillingRetention::threshold();
+        }
+    }
+    PHP;
+
+    $importOnly = <<<'PHP'
+    <?php
+    use App\Support\Legal\BillingRetention;
+    class Fixture {
+        public function run(BillingRetention $retention): void {}
+    }
+    PHP;
+
+    // `use ... as` の alias で目録を迂回できないこと
+    $aliased = <<<'PHP'
+    <?php
+    use App\Support\Legal\BillingRetention as Retention;
+    class Fixture {
+        public function run(): void {
+            $a = Retention::years();
+            $b = Retention::threshold();
+        }
+    }
+    PHP;
+
+    expect(billingRetentionScanSource($called)['ssotCall'])->toBe(2);
+    expect(billingRetentionScanSource($importOnly)['ssotCall'])->toBe(0);
+    expect(billingRetentionScanSource($aliased)['ssotCall'])->toBe(2);
+    expect(billingRetentionAliasNames(token_get_all($aliased)))->toBe(['Retention']);
+
+    // ★年数の表記は **必ず連結で組み立てる**。生ソースに書くと検査 7 の
+    //   自己参照コントロール (gate 自身の hit 0) が落ちる。
+    $y = (string) 7;
+    $wide = mb_convert_kana($y, 'N');
+    $kanji = (string) billingRetentionKanjiNumeral(7);
+    $era = '年';
+
+    // 散文 literal: 3 表記すべてを検出し、SSOT 呼び出しの形は検出しない
+    expect(billingRetentionProseYearLiterals('最長 '.$y.' '.$era.'間', 7))->toBe([$y.$era]);
+    expect(billingRetentionProseYearLiterals('最長'.$wide.$era.'間', 7))->toBe([$wide.$era]);
+    expect(billingRetentionProseYearLiterals('最長'.$kanji.$era.'間', 7))->toBe([$kanji.$era]);
+    expect(billingRetentionProseYearLiterals('最長{{ Foo::years() }}'.$era.'間', 7))->toBe([]);
+    // 年数と無関係な数字は拾わない (見出し番号の繰り下げで偽赤にしない)
+    expect(billingRetentionProseYearLiterals('<h2>'.$y.'. その他</h2>', 7))->toBe([]);
+    // 数字境界: 別の数値の一部を年数と誤認しない (17 / 70 のような並びで偽赤にしない)
+    expect(billingRetentionProseYearLiterals('最長 1'.$y.' '.$era.'間', 7))->toBe([]);
+    expect(billingRetentionProseYearLiterals('最長 '.$y.'0 '.$era.'間', 7))->toBe([]);
+    expect(billingRetentionProseYearLiterals('最長１'.$wide.$era.'間', 7))->toBe([]);
+
+    // 漢数字変換 (1〜99 と範囲外)
+    expect(billingRetentionKanjiNumeral(7))->toBe('七');
+    expect(billingRetentionKanjiNumeral(10))->toBe('十');
+    expect(billingRetentionKanjiNumeral(25))->toBe('二十五');
+    expect(billingRetentionKanjiNumeral(100))->toBeNull();
+
+    // コード側 literal: @php ブロックの迂回を検出する
+    // (`@endphp` の直後に `{{` を置くと Blade の `@{{` エスケープ記法と衝突するため改行を挟む)
+    $bladeWithPhp = Blade::compileString("@php\n\$years = 7;\n@endphp\n{{ \$years }}".$era.'間');
+    expect(billingRetentionCodeYearLiteralCount($bladeWithPhp, 7))->toBe(1);
+    expect(billingRetentionCodeYearLiteralCount(
+        Blade::compileString('{{ Foo::years() }}'.$era.'間'), 7))->toBe(0);
+});
+
+test('検査 9: alias 解決の負のコントロール (class import の文脈だけを見る)', function (): void {
+    /** @var callable(string): list<string> $aliasesOf */
+    $aliasesOf = static fn (string $source): array => billingRetentionAliasNames(token_get_all($source));
+
+    // (1) 素の class import + alias … 1 件
+    expect($aliasesOf('<?php use App\Support\Legal\BillingRetention as R;'))->toBe(['R']);
+
+    // (2) 完全修飾の class import + alias … 1 件
+    expect($aliasesOf('<?php use \App\Support\Legal\BillingRetention as R;'))->toBe(['R']);
+
+    // (3) group use (prefix を結合して FQCN を復元する) … 1 件。別クラスは巻き込まない
+    expect($aliasesOf('<?php use App\Support\Legal\{BillingRetention as R, LegalConsent as C};'))
+        ->toBe(['R']);
+
+    // (4) 関数 import は別のシンボル表 … 0 件
+    expect($aliasesOf('<?php use function Vendor\BillingRetention as R;'))->toBe([]);
+    expect($aliasesOf('<?php use const Vendor\BillingRetention as R;'))->toBe([]);
+
+    // (5) 別 namespace の同名クラス … 0 件 (FQCN で厳密比較している)
+    expect($aliasesOf('<?php use Other\Domain\BillingRetention as R;'))->toBe([]);
+    expect($aliasesOf('<?php use Other\{BillingRetention as R};'))->toBe([]);
+
+    // (6) trait adaptation の as … 0 件 (import ではない)
+    expect($aliasesOf('<?php class C { use T { BillingRetention as R; } }'))->toBe([]);
+
+    // (7) closure の変数束縛 … 0 件 (use ( で始まる)
+    expect($aliasesOf('<?php $f = function () use ($x) { return $x; };'))->toBe([]);
+
+    // (8) alias 無しの import は alias を生まない (素の名前は最終セグメント一致で拾う)
+    expect($aliasesOf('<?php use App\Support\Legal\BillingRetention;'))->toBe([]);
+
+    // (4)(5)(6) の alias が **呼び出しとして数えられない**ことまで見る
+    $functionImport = <<<'PHP'
+    <?php
+    use function Vendor\BillingRetention as Retention;
+    class Fixture {
+        public function run(): void { $a = Retention::years(); }
+    }
+    PHP;
+    $foreignNamespace = <<<'PHP'
+    <?php
+    use Other\Domain\BillingRetention as Retention;
+    class Fixture {
+        public function run(): void { $a = Retention::years(); }
+    }
+    PHP;
+
+    expect(billingRetentionScanSource($functionImport)['ssotCall'])->toBe(0);
+    expect(billingRetentionScanSource($foreignNamespace)['ssotCall'])->toBe(0);
+});

```

## 確認してほしいこと

1. 自己参照コントロールが SoT (hit 0 件) の形になっているか。循環が残っていないか
2. `billingRetentionAliasNames()` の use 文解析に**残る誤検出 / 取りこぼし**が無いか
   (group use の入れ子、深い group prefix、複数行にまたがる use 文、alias の重複等)
3. 「最終セグメント一致による過検出を意図的に残す」という判断と、その docblock 記述が妥当か

全体判定を APPROVED / CHANGES_REQUESTED で明示してください。
