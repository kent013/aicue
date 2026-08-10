# Round 2: Round 1 の Warning への対応結果

Round 1 は APPROVED だったが、Warning 3 件 + Suggestion 1 件はいずれも検査ファイル内で
完結しスコープを広げないため、この PR 内で対応した。**対応後の検査ファイル全文の diff**
(Round 1 で見せた版からの差分ではなく、HEAD からの累積 diff) を示す。

## 対応マトリクス

# 対応マトリクス: impl-review Round 1

Codex 全体判定は **APPROVED** (Critical 0)。ただし Warning 3 件は「gate が exact-fit を
名乗る以上ここは塞ぐべき」という筋の通った指摘であり、いずれも**検査ファイル内で完結**して
極小 PR のスコープを広げないため、この PR 内で対応した。

## [Warning] `BillingRetention` の alias import を検出できない (目録の迂回)

- 判断: **対応する**
- 根拠: `use ... as Retention;` で書けば呼び出し元目録を素通りできる。「exact-fit の目録」を
  名乗っている以上、この穴は主張と実態の食い違いそのものである (誇張しない原則)。
- 対応内容: `billingRetentionAliasNames()` を追加し、token 列から
  `use ...\BillingRetention as X;` の X を集めて呼び出し検出に含めた。
  - 負のコントロールに alias fixture を追加 (`ssotCall === 2` / alias 名 `['Retention']`)。
  - **mutation 実測**: 既存 caller を alias 形に書き換えても目録は緑のまま (消えない)。
    alias 経由で `years()` を呼ぶ新ファイルを足すと**検査 6 が赤** (未登録の呼び出し元)。

## [Warning] 自己参照コントロールが弱い (prose detector を gate 自身に当てていない)

- 判断: **対応する**
- 根拠: 本リポジトリの gate 書式は「自己参照コントロール」を必須要件として挙げている。
  prose detector は生ソース regex なので、gate 自身を母集団に入れると**自分の fixture で
  偽赤になる** — これは「母集団を privacy blade 1 本に限る」判断の裏返しであり、
  暗黙にせず検査として明示するのが正しい。
- 対応内容: 検査 7 の末尾で `BILLING_RETENTION_GATE_FILE` に prose detector を当て、
  **必ず点灯すること**を assert した。これで (1) 検出器が実ファイル相手に生きている
  (2) 母集団を 1 本に限った理由、が同時に固定される。

## [Warning] 年数検査が部分一致 (`17年` / `70年` を通す)

- 判断: **対応する**
- 根拠: 三者一致 gate の核心が「宣言された年数」の照合である以上、`17年` を `7年` と
  読む検出器では役目を果たさない。**偽緑 (誤表示を通す)** と **偽赤 (無関係な数字で落ちる)**
  の両方を生む。
- 対応内容: Architecture 側 (`billingRetentionProseYearLiterals`) と Feature 側
  (`privacyRetentionDeclaresYears`) の双方に**数字境界** `(?<![0-9０-９])…(?![0-9０-９])` を入れた。
  負のコントロールに `17年` / `70年` / `１７年` を追加。

## [Suggestion] `id="retention"` が見出し要素であることを見ていない

- 判断: **対応する** (コストがゼロに近く、SoT の「節見出し」という語に忠実になる)
- 対応内容: `privacyRetentionHeading()` が `h1`〜`h6` に限定して返すようにし、
  `<p id="retention">` を通さないことを負のコントロールで固定した。
  **`h2` 固定にはしない** — 見出しレベルの変更は文面の意味を変えないため、そこまで縛ると
  偽赤の元になる (「番号ではなく属性で照合する」という設計方針と同じ理由)。

## [Suggestion] docs/architecture.md・blade の記述は過大主張なし

- 判断: **対応不要** (指摘は肯定的評価)

## スコープ外として持ち帰らなかったもの

なし。Codex はスコープ拡大の提案を出していない。


## 再検証の実測

- `composer phpstan` … OK (871 files, no errors)
- `vendor/bin/pint --test` … passed
- 対象 2 ファイル … 14 tests / 60 assertions passed
- **mutation 再実測**:
  - M14 (blade を literal `7` に) … 検査 6 / 検査 7 / Feature (e) の **3 本が赤** (数字境界を入れた後も維持)
  - alias 迂回 … 既存 caller を alias 形に書き換えても目録は**緑のまま** (消えない)。
    alias 経由で `years()` を呼ぶ新ファイルを足すと **検査 6 が赤**。probe は削除済み

## 累積 diff (検査 2 ファイル)

```diff
diff --git a/tests/Architecture/BillingRetentionConfigSingleSourceTest.php b/tests/Architecture/BillingRetentionConfigSingleSourceTest.php
index 2eb4377..0427537 100644
--- a/tests/Architecture/BillingRetentionConfigSingleSourceTest.php
+++ b/tests/Architecture/BillingRetentionConfigSingleSourceTest.php
@@ -3,37 +3,59 @@
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
+ * PR-C1 (C1a) / PR-C3 (C3b) とオーナー決定 (課金取引記録の保持 = 7 年)。
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
+ *     SSOT 呼び出しをちょうど 1 回持つ
+ *   - 検査 8: 検査 6/7 の検出器の負のコントロール (fixture で点灯すること)
  *
  * ★この gate が保証しないもの (誇張しない):
- *   - **tests/ は走査しない**。保持年数の fail-fast (0 以下) を検証するテストは
- *     config を書き換える必要があり、そこを禁止すると検査そのものが書けなくなる
- *   - **規約文面 (privacy blade) との一致は見ない**。文面はまだ存在せず (PR-C3 の担当)、
- *     三者一致 (config / SSOT / 文面) の gate は PR-C3 で本 gate の上に積む
- *   - 動的キー組み立て (`config('legal.'.$key)`) には沈黙する (実測 0 件)
+ *   - **文面の日本語が法的に正しいか / 7 年が法令上妥当か**は見ない。現在の文面は
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
@@ -45,39 +67,178 @@
 /** 単一出典クラス (repo ルート相対)。 */
 const BILLING_RETENTION_SOURCE_FILE = 'app/Support/Legal/BillingRetention.php';
 
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
+ * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
+ * @return list<string>
+ */
+function billingRetentionAliasNames(array $tokens): array
+{
+    $aliases = [];
+    $count = count($tokens);
+
+    for ($i = 0; $i < $count; $i++) {
+        $token = $tokens[$i];
+        if (! is_array($token)) {
+            continue;
+        }
+        [$id, $value] = $token;
+        if ($id !== T_STRING && $id !== T_NAME_QUALIFIED && $id !== T_NAME_FULLY_QUALIFIED) {
+            continue;
+        }
+        $segments = explode('\\', $value);
+        if (end($segments) !== 'BillingRetention') {
+            continue;
+        }
+
+        $as = billingRetentionNextMeaningful($tokens, $i);
+        if ($as === null || ! is_array($tokens[$as]) || $tokens[$as][0] !== T_AS) {
+            continue;
+        }
+        $alias = billingRetentionNextMeaningful($tokens, $as);
+        if ($alias !== null && is_array($tokens[$alias]) && $tokens[$alias][0] === T_STRING) {
+            $aliases[] = $tokens[$alias][1];
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
             continue;
         }
-        if (trim($token[1], "'\"") === BILLING_RETENTION_CONFIG_KEY) {
-            $result['configKey']++;
+
+        // BillingRetention::years() / ::threshold()
+        // (部分修飾・完全修飾・`use ... as` の alias を問わない)
+        if ($id !== T_STRING && $id !== T_NAME_QUALIFIED && $id !== T_NAME_FULLY_QUALIFIED) {
+            continue;
+        }
+        $segments = explode('\\', $value);
+        if (end($segments) !== 'BillingRetention' && ! in_array($value, $aliases, true)) {
+            continue;
+        }
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
@@ -96,8 +257,8 @@ function billingRetentionScanTree(array $dirs): array
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
@@ -155,9 +316,80 @@ function billingRetentionConfigLiteral(): ?int
     return null;
 }
 
+/**
+ * 整数を漢数字へ変換する (1〜99 のみ。範囲外は null)。
+ *
+ * 「七年」のような表記の literal を検出するために使う。
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
@@ -186,13 +418,17 @@ function billingRetentionConfigLiteral(): ?int
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
@@ -221,3 +457,113 @@ public function run(): void {}
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
+    // 散文側の literal ("7年" / "７ 年" / "七年")
+    expect(billingRetentionProseYearLiterals($raw, $years))->toBe([],
+        '/privacy の文面に保持年数の literal を検出しました。config / SSOT / 文面の'
+        .'三者一致が壊れるため、必ず BillingRetention::years() から描画してください。');
+
+    // コード側の literal (@php ブロック等の迂回)
+    expect(billingRetentionCodeYearLiteralCount($compiled, $years))->toBe(0,
+        '/privacy の blade コード側に保持年数と同じ整数リテラルを検出しました。');
+
+    // 自己参照コントロール: 本 gate ファイル自身は負のコントロール fixture として
+    // 「最長 7 年間」を持つため、検出器を当てると**必ず点灯する**。これで
+    // (1) 検出器が実ファイル相手に生きていること (2) literal 検査の母集団を
+    // privacy blade 1 本に限っている理由 (gate 自身を入れると自分の fixture で偽赤になる)
+    // の 2 つが同時に固定される。
+    $self = file_get_contents(base_path(BILLING_RETENTION_GATE_FILE));
+    expect($self)->toBeString();
+    expect(billingRetentionProseYearLiterals((string) $self, $years))->not->toBe([]);
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
+    // 散文 literal: 3 表記すべてを検出し、SSOT 呼び出しの形は検出しない
+    expect(billingRetentionProseYearLiterals('最長 7 年間', 7))->toBe(['7年']);
+    expect(billingRetentionProseYearLiterals('最長７年間', 7))->toBe(['７年']);
+    expect(billingRetentionProseYearLiterals('最長七年間', 7))->toBe(['七年']);
+    expect(billingRetentionProseYearLiterals('最長{{ Foo::years() }}年間', 7))->toBe([]);
+    // 年数と無関係な数字は拾わない (見出し番号の繰り下げで偽赤にしない)
+    expect(billingRetentionProseYearLiterals('<h2>7. その他</h2>', 7))->toBe([]);
+    // 数字境界: 別の数値の一部を年数と誤認しない (17 年 / 70 年で偽赤にしない)
+    expect(billingRetentionProseYearLiterals('最長 17 年間', 7))->toBe([]);
+    expect(billingRetentionProseYearLiterals('最長 70 年間', 7))->toBe([]);
+    expect(billingRetentionProseYearLiterals('最長１７年間', 7))->toBe([]);
+
+    // 漢数字変換 (1〜99 と範囲外)
+    expect(billingRetentionKanjiNumeral(7))->toBe('七');
+    expect(billingRetentionKanjiNumeral(10))->toBe('十');
+    expect(billingRetentionKanjiNumeral(25))->toBe('二十五');
+    expect(billingRetentionKanjiNumeral(100))->toBeNull();
+
+    // コード側 literal: @php ブロックの迂回を検出する
+    // (`@endphp` の直後に `{{` を置くと Blade の `@{{` エスケープ記法と衝突するため改行を挟む)
+    $bladeWithPhp = Blade::compileString("@php\n\$years = 7;\n@endphp\n{{ \$years }}年間");
+    expect(billingRetentionCodeYearLiteralCount($bladeWithPhp, 7))->toBe(1);
+    expect(billingRetentionCodeYearLiteralCount(Blade::compileString('{{ Foo::years() }}年間'), 7))->toBe(0);
+});
diff --git a/tests/Feature/Legal/PrivacyRetentionDeclarationTest.php b/tests/Feature/Legal/PrivacyRetentionDeclarationTest.php
new file mode 100644
index 0000000..36c7ec8
--- /dev/null
+++ b/tests/Feature/Legal/PrivacyRetentionDeclarationTest.php
@@ -0,0 +1,182 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Support\Legal\BillingRetention;
+use Dom\HTMLDocument;
+
+/*
+ * /privacy が宣言する「課金取引記録の保有期間」の **behavioral** 検査
+ * (SoT = devnotes/20260809-0908-account-deletion-grace/detailed-design.md の PR-C3 / C3b)。
+ *
+ * 背景: 保持年数は config/legal.php -> App\Support\Legal\BillingRetention -> 規約文面 の
+ * 三者が一致していなければならない。静的 gate
+ * (tests/Architecture/BillingRetentionConfigSingleSourceTest.php) は「blade が literal を
+ * 持たないこと」までしか見られないので、**実際に描画された HTML** の側からもう一度
+ * 固定する。節ごと消えた場合も、数字だけ別の文脈に残った場合も、ここで赤くなる。
+ *
+ * ★このテストが保証するもの:
+ *   (a) data-legal-retention="billing-records" のマーカー要素が**ちょうど 1 つ**実在する
+ *   (b) 保有期間の**節見出し** (id="retention" かつ h1〜h6) が実在する
+ *   (c) 家系の先例由来の固定文言「取引関係書類等」がページ内に実在する
+ *   (d) **マーカー要素の内側に** config 由来の年数が「N 年」の形 (数字境界つき) で現れる
+ *   (e) config の値を変えると描画も追随する (= literal ではなく SSOT 由来である)
+ *
+ * ★このテストが保証しないもの (誇張しない):
+ *   - 文面の日本語が法的に正しいか / 年数が法令上妥当か (**法務レビューの仕事**。
+ *     現在の文面は法務レビュー前の**草案**である)
+ *   - 散文の意味と実処理 (purge バッチ) の一致。機械が見るのは数値 1 つ・マーカーの
+ *     存在・固定文言 1 語だけである
+ *   - purge 対象テーブルの網羅性 (BillingRetentionTargetInventoryTest の担当)
+ *   - 「文面が変わったのに consent_version が上がっていない」こと
+ *     (本タスクでは版を draft-1 から動かさないため、そもそも検査対象にしない)
+ *
+ * **見出し番号 (「4.」等) では照合しない**。節の並べ替え・番号の繰り下げは文面の意味を
+ * 変えないため、属性 (data-legal-retention / id) と固定文言で照合する。
+ */
+
+/** 保有期間を宣言するマーカー要素の属性値。 */
+const PRIVACY_RETENTION_MARKER_VALUE = 'billing-records';
+
+/** 節見出しの id (番号ではなくこれで照合する)。 */
+const PRIVACY_RETENTION_HEADING_ID = 'retention';
+
+/** 家系の先例 (spirux の /privacy) 由来の固定文言。 */
+const PRIVACY_RETENTION_FIXED_PHRASE = '取引関係書類等';
+
+/** マーカー要素のテキスト内容を取り出す (無ければ null)。 */
+function privacyRetentionMarkerText(string $html): ?string
+{
+    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
+    $nodes = $document->querySelectorAll('[data-legal-retention="'.PRIVACY_RETENTION_MARKER_VALUE.'"]');
+
+    if ($nodes->length !== 1) {
+        return null;
+    }
+
+    $node = $nodes->item(0);
+
+    return $node?->textContent;
+}
+
+/**
+ * 保有期間の**節見出し**を取り出す (無い / 見出し要素でないなら null)。
+ *
+ * `<p id="retention">` のような「見出しに見えるだけの要素」を通さないため、
+ * 要素名が h1〜h6 であることまで見る。
+ *
+ * @return array{name: string, text: string}|null
+ */
+function privacyRetentionHeading(string $html): ?array
+{
+    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
+    $node = $document->getElementById(PRIVACY_RETENTION_HEADING_ID);
+
+    if ($node === null) {
+        return null;
+    }
+
+    $name = strtolower($node->nodeName);
+    if (! in_array($name, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
+        return null;
+    }
+
+    return ['name' => $name, 'text' => $node->textContent];
+}
+
+/**
+ * 「N 年」が**独立した数値として**現れるかを見る。
+ *
+ * 素の部分一致だと years=7 のとき「17 年間」「70 年間」の誤表示を通してしまうため、
+ * 数字境界 (前後に別の数字が無いこと) を要求する。
+ */
+function privacyRetentionDeclaresYears(string $text, int $years): bool
+{
+    return preg_match('/(?<![0-9０-９])'.$years.'(?![0-9０-９])\s*年/u', $text) === 1;
+}
+
+it('(a) /privacy が保有期間のマーカー要素をちょうど 1 つ持つ', function (): void {
+    $response = $this->get('/privacy');
+    $response->assertOk();
+
+    expect(privacyRetentionMarkerText((string) $response->getContent()))->not->toBeNull(
+        'data-legal-retention="'.PRIVACY_RETENTION_MARKER_VALUE.'" の要素が /privacy に '
+        .'ちょうど 1 つ存在しません。保有期間の宣言はこのマーカーで機械照合しています '
+        .'(見出し番号では照合しない)。resources/views/legal/privacy.blade.php を確認してください。');
+});
+
+it('(b) /privacy が保有期間の節見出しを持つ', function (): void {
+    $response = $this->get('/privacy');
+    $response->assertOk();
+
+    $heading = privacyRetentionHeading((string) $response->getContent());
+
+    expect($heading)->not->toBeNull(
+        'id="'.PRIVACY_RETENTION_HEADING_ID.'" の**見出し要素** (h1〜h6) が /privacy にありません。');
+    expect($heading['text'] ?? '')->toContain('保有期間');
+});
+
+it('(c) /privacy が先例由来の固定文言「取引関係書類等」を持つ', function (): void {
+    $response = $this->get('/privacy');
+    $response->assertOk();
+
+    // Pest の toContain は可変長 needle を取るため、説明文は toBeTrue 側へ渡す。
+    expect(str_contains((string) $response->getContent(), PRIVACY_RETENTION_FIXED_PHRASE))->toBeTrue(
+        '固定文言「'.PRIVACY_RETENTION_FIXED_PHRASE.'」が /privacy から消えました。'
+        .'この語は家系の先例 (spirux の /privacy) に揃えた文面の要であり、'
+        .'保持年数が「何に対する期間なのか」を特定しています。');
+});
+
+it('(d) マーカー要素の内側に config 由来の年数が現れる', function (): void {
+    $response = $this->get('/privacy');
+    $response->assertOk();
+
+    $marker = privacyRetentionMarkerText((string) $response->getContent());
+
+    expect($marker)->not->toBeNull();
+    expect(privacyRetentionDeclaresYears((string) $marker, BillingRetention::years()))->toBeTrue(
+        '保持年数が「N 年」の形でマーカー要素の内側にありません。数字だけ別の文脈に移ると '
+        .'「規約が宣言する年数」が機械照合できなくなります。');
+    // 数字が「何の期間なのか」まで含めて 1 要素に収まっていること
+    expect((string) $marker)->toContain(PRIVACY_RETENTION_FIXED_PHRASE);
+});
+
+it('(e) config の保持年数を変えると /privacy の描画も追随する', function (): void {
+    // literal で書かれていたらここが赤くなる (SSOT 由来であることの behavioral 証明)。
+    $mutated = BillingRetention::years() + 3;
+    config()->set('legal.billing_retention_years', $mutated);
+
+    $response = $this->get('/privacy');
+    $response->assertOk();
+
+    $marker = privacyRetentionMarkerText((string) $response->getContent());
+
+    expect($marker)->not->toBeNull();
+    expect(privacyRetentionDeclaresYears((string) $marker, $mutated))->toBeTrue(
+        'config/legal.php の billing_retention_years を変えても /privacy の表示が変わりません。'
+        .'blade に年数の literal が書かれている疑いがあります '
+        .'(App\Support\Legal\BillingRetention::years() から描画してください)。');
+});
+
+it('負のコントロール: 検出ヘルパが実際に効いている', function (): void {
+    // 年数判定は数字境界を要求する (17 年 / 70 年を「7 年」と読まない)
+    expect(privacyRetentionDeclaresYears('最長7年間', 7))->toBeTrue();
+    expect(privacyRetentionDeclaresYears('最長 7 年間', 7))->toBeTrue();
+    expect(privacyRetentionDeclaresYears('最長17年間', 7))->toBeFalse();
+    expect(privacyRetentionDeclaresYears('最長70年間', 7))->toBeFalse();
+    // 数字が「年」に係っていない (見出し番号など) 場合も年数の宣言とは読まない
+    expect(privacyRetentionDeclaresYears('7. その他', 7))->toBeFalse();
+
+    // 節見出しは h1〜h6 に限る (見出しに見えるだけの要素を通さない)
+    $heading = '<html><body><h2 id="retention">4. 保有期間</h2></body></html>';
+    $notHeading = '<html><body><p id="retention">4. 保有期間</p></body></html>';
+    expect(privacyRetentionHeading($heading)['name'] ?? null)->toBe('h2');
+    expect(privacyRetentionHeading($notHeading))->toBeNull();
+
+    // マーカーは「ちょうど 1 つ」を要求する (重複すると照合先が決まらない)
+    $one = '<html><body><p data-legal-retention="billing-records">x</p></body></html>';
+    $two = '<html><body><p data-legal-retention="billing-records">x</p>'
+        .'<p data-legal-retention="billing-records">y</p></body></html>';
+    expect(privacyRetentionMarkerText($one))->toBe('x');
+    expect(privacyRetentionMarkerText($two))->toBeNull();
+});

```

## 確認してほしいこと

1. alias 解決 (`billingRetentionAliasNames`) に**新たな穴 / 誤検出**が無いか
   (特に `use function ... as`、group use、`class X as Y` 以外の `T_AS` 出現)
2. 数字境界の lookbehind/lookahead が **UTF-8 (全角数字) で意図どおり**か
3. 自己参照コントロールが**循環的に緑になる形**になっていないか
   (gate 自身の fixture を書き換えたら赤くなるべきか / なるか)
4. Round 1 の Warning が実際に解消しているか

全体判定を APPROVED / CHANGES_REQUESTED で明示してください。
