Round 1 の指摘への対応を報告する。Critical 3 件・Warning 5 件すべてに対応した。

# 対応マトリクス: impl-review Round 1

## [Critical] `analyseFileContext()` の取り込み対応表がファイルに 1 つしか無い (名前空間をまたぐ fail-open)

- 判断: **対応する**
- 根拠: 指摘のとおり実際に見逃す。`classifyFunctionCall()` は `unresolved` の判定より**先に**
  別名表で候補を絞っていたため、2 つ目の名前空間の `use function` が同じ別名を別の完全修飾名へ
  上書きすると、1 つ目の `putenv` 別名呼び出しが候補から外れて `null` を返していた
  (unresolved にすらならない = i12 の直接の穴)。
- 対応内容:
  - `analyseFileContext()` を**名前空間の領域ごと**の文脈 (`regions`) へ書き換えた。
    各領域が自分の `namespace` と取り込み対応表を持ち、`classifyFunctionCall()` は
    呼び出し位置を含む領域 (`regionAt()`) で解決する。
  - 候補判定 (`$isCandidate`) を**上書きされたものも含む全別名の集合** (`aliasKeys`) で行うようにした。
    最終的な対応表だけを見ると同じ穴が残るため。
  - 負例を 2 本追加: 「同じ別名が 2 つ目の名前空間で別の完全修飾関数へ上書きされる形」
    (両方の呼び出しが `unresolved` になること) と
    「別名を `putenv` 以外へ向けた取り込みは名前空間をまたいでも誤検出しないこと」。

## [Critical] 分割代入の lvalue 判定が連想の値の側と参照 target を見逃す

- 判断: **対応する**
- 根拠: `['key' => $_SERVER['K']] = $value;` は直前が `=>`、`[&$_ENV['K']] = $value;` は直前が `&` で、
  どちらも旧実装の要素先頭判定 (`[` / `(` / `,`) に当たらず `null` を返していた。列挙対象の直接書き込みである。
- 対応内容:
  - 判定を `isDestructuringTargetRoot()` へ切り出し、3 条件で見るようにした —
    (1) 要素の先頭 (`[` / `(` / `,` / `=>` の直後。**参照記号を挟んだ直後**も含む)、
    (2) 範囲の根との間に添字の括弧が無い、
    (3) 添字の連鎖の直後が `=>` **でない** (`=>` なら連想の**鍵** = 読み出し)。
  - 参照記号つきの target は `reference_taken` として報告する (書き込みの分類としてはこちらが正しい)。
  - 正例 (連想の値の側 / 参照つき) と負例 (連想の鍵の側) を追加した。

## [Critical] `conditionMatches()` が包含判定で結合関係を見ていない

- 判断: **対応する**
- 根拠: 指摘のとおり `if (! $applied && $other === false)` を `$applied === false` と誤認する。
  動的に検査できない性質 (適用途中の巻き戻り / 復元が最初の失敗で止まらないこと) の
  **唯一の代替保証**なので、緩い判定は保証そのものを空洞にする。
- 対応内容: `conditionEquals()` (条件のトークンの綴りの列を**完全一致**で見る) へ置き換え、
  `restoreStructureIsDeferred()` が `['$applied', '===', 'false']` /
  `['$failed', '!==', '[', ']']` と完全一致で突き合わせるようにした。
  負例 (`! $applied && $other === false`) と正例 (条件の綴りの列の取り出し) を追加した。

## [Warning] `constructions()` の短名末尾一致が AGENTS.md 走査器共通規約 (a) に反する

- 判断: **対応する**
- 根拠: `str_ends_with($name, '\RuntimeException')` は `Vendor\RuntimeException` を誤認する。
  規約 (a) は「クラス参照は完全修飾名で突き合わせる」と定めている。
- 対応内容: `constructions(array $tokens, class-string $declaringClass, class-string $expected)` へ変更し、
  **宣言元ファイルの `use` (クラスの取り込み。group use / 別名を含む) と名前空間を解いた完全修飾名**で
  突き合わせるようにした (`nameResolver()` / `resolveClassName()`)。
  実行時に決まるクラス (`new $class(`) と `new self/static(` は候補に入らないので、
  「ちょうど 1 件」を要求する利用側が偽を返して赤くなる (fail-closed)。この限界は docblock に明記した。
  負例 (`new \Vendor\RuntimeException(...)` が期待クラスと一致しないこと) を追加した。

## [Warning] 走査器の自己検査に上記 2 件を捕捉する負例が無い

- 判断: **対応する**
- 対応内容: 上の 3 つの Critical それぞれに対応する負例を
  `RawEnvDirectWriteScannerTest` (3 本) と `RawEnvGuardStructureTest` (3 本) へ追加した。

## [Warning] `callArguments()` の括弧不整合を自己検査で固定していない (Suggestion 相当)

- 判断: **対応する**
- 対応内容: `fail-closed 6` を追加し、丸括弧が閉じない入力で例外になることを固定した。

## [Warning] h-3 が不完全な `restoreStructureIsDeferred()` を使っている

- 判断: **対応する** (上の Critical 3 の修正で解消)
- 対応内容: `conditionEquals()` 化により、h-3 は「`$applied === false` のときだけ蓄積する」を
  完全一致で固定するようになった。`constructionArgumentMatches()` の署名変更にも追随した。

## [Warning] G4 が自己参照的である

- 判断: **対応する**
- 根拠: 走査側も検査側も同じ定数を使うため、定数を書き換えると除外が黙って広がりうる。
- 対応内容: G4 の先頭で `expect(RAW_ENV_DIRECT_WRITE_EXCLUDED_PREFIX)->toBe('devnotes/')` を
  独立した期待値として突き合わせるようにした。

## [Warning] D53 の「3 面を触る経路は 1 本だけ」が許可 3 か所と矛盾する

- 判断: **対応する**
- 根拠: 契約テストと `tests/bootstrap.php` は意図的に直接触るし、間接呼び出しは走査の対象外である。
  誇張した主張は AGENTS.md の「保証範囲を誇張しない」に反する。
- 対応内容: D53 の「揃えている不変条件」を
  「走査器が字句として列挙した直接書き込みの形は、許可 3 か所以外に 1 件も現れない」へ改め、
  「3 面を触る経路が 1 本だけ、とは書かない」理由と、正本が走査器の docblock であることを明記した。

## [Warning] `pnpm test` / `pnpm test:packages` の結果が未提示

- 判断: **対応する**
- 対応内容: Round 2 のプロンプトで全 10 コマンドの結果を提示する。


## 修正差分 (Round 1 でレビューした状態からの delta)

```diff
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index a663cf7f..3c0b7727 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -3079,8 +3079,12 @@ ### なぜ正当な差分か (logic-driven)
 
 ### 揃えている不変条件 (これは保証し続ける)
 
-> 「テストが生の環境変数 3 面を触る経路は `Tests\Support\RawEnv\RawEnvSnapshot` の 1 本だけであり、
-> 走査器が列挙した字句の書き込み形は許可 3 か所以外に 1 件も現れない」
+> 「走査器が字句として列挙した直接書き込みの形は、許可 3 か所
+> (部品自身 / 部品の契約テスト / `tests/bootstrap.php`) 以外に 1 件も現れない」
+
+**「3 面を触る経路が 1 本だけ」とは書かない** — 契約テストと `tests/bootstrap.php` は
+意図的に直接触るし、間接呼び出し (可変関数 / `call_user_func` / 値渡しの先) は走査の対象外である。
+検出しない構文の一覧は `RawEnvDirectWriteScanner` の docblock が正本である。
 
 - 部品の契約 (往復 / 3 相 / 拒否 / 読み出し順 / 読み出し口の作り直し) は
   `RawEnvSnapshotContractTest` が実行時に固定する
diff --git a/tests/Architecture/RawEnvDirectWriteGateTest.php b/tests/Architecture/RawEnvDirectWriteGateTest.php
index 5b5d8bbe..c496494f 100644
--- a/tests/Architecture/RawEnvDirectWriteGateTest.php
+++ b/tests/Architecture/RawEnvDirectWriteGateTest.php
@@ -198,6 +198,10 @@ function rawEnvDirectWriteFailureMessage(array $violations): string
 });
 
 test('G4: 除外集合が devnotes/ 配下と完全一致する', function (): void {
+    // ★定数値そのものを独立した期待値と突き合わせる。走査側も検査側も同じ定数を使うので、
+    //   定数を書き換えると (床値を満たす限り) G3〜G5 が緑のまま除外が広がりうる。
+    expect(RAW_ENV_DIRECT_WRITE_EXCLUDED_PREFIX)->toBe('devnotes/');
+
     $population = rawEnvDirectWritePopulation();
 
     foreach ($population['excluded'] as $relative) {
diff --git a/tests/Support/RawEnv/RawEnvDirectWriteScanner.php b/tests/Support/RawEnv/RawEnvDirectWriteScanner.php
index 67d4a421..18b93c93 100644
--- a/tests/Support/RawEnv/RawEnvDirectWriteScanner.php
+++ b/tests/Support/RawEnv/RawEnvDirectWriteScanner.php
@@ -21,15 +21,17 @@
  *  | `element_unset` | 面の要素の削除 (`unset()` の引数の**根**にある面) |
  *  | `whole_assign` | 面そのものへの代入 (複合代入を含む) |
  *  | `reference_taken` | 面 / 面の要素への参照の取得 |
- *  | `destructuring_target` | 分割代入の左辺の**根**に面が現れる形 |
+ *  | `destructuring_target` | 分割代入の左辺の**根**に面が現れる形 (連想の値の側を含む。鍵の側は読み出し) |
  *  | `putenv` | プロセス面への書き込み (両形 / 完全修飾 / 別名つき取り込み) |
  *  | `unresolved` | 上のどれにも分類できなかった出現 (**必ず違反**) |
  *
  * ── 関数名の解決 (AGENTS.md 走査器共通規約 (a)) ──────────────────────────
  *
  *  `putenv` は**完全修飾名で突き合わせる**。短名一致は使わない (別名つき取り込み 1 つで
- *  検査が黙るため)。ファイルごとに名前空間宣言と `use function` の取り込み対応表
- *  (別名・group use を含む) を先に組み立て、
+ *  検査が黙るため)。取り込み対応表 (別名・group use を含む) は**名前空間の領域ごと**に持ち、
+ *  呼び出しの位置に対応する領域で解決する (ファイルに 1 つにすると、同じ別名を別の完全修飾名へ
+ *  向ける 2 つ目の名前空間が 1 つ目の対応表を上書きし、1 つ目の呼び出しが黙って見逃される)。
+ *  そのうえで、
  *  裸の呼び出し (名前空間の中でもグローバルへ fallback する) / 完全修飾 /
  *  別名を解いた結果が `\putenv` になる呼び出しを検出する。
  *  `T_NAME_RELATIVE` (`namespace\putenv`) は**グローバル名前空間のときだけ**一致する。
@@ -98,7 +100,7 @@ public static function scan(string $phpSource): array
 
         $pairs = self::bracketPairs($tokens);
         $enclosingParen = self::enclosingParens($tokens);
-        $context = self::analyseFileContext($tokens);
+        $context = self::analyseFileContext($tokens, $pairs);
         $destructuring = self::destructuringRanges($tokens, $pairs);
         $unsetRanges = self::unsetRanges($tokens, $pairs);
 
@@ -129,15 +131,26 @@ public static function scan(string $phpSource): array
     }
 
     /**
-     * ファイル全体の名前解決の文脈 (名前空間 / `use function` の対応表 / 解決不能の宣言)。
+     * 名前解決の文脈を**名前空間の領域ごとに**組み立てる。
+     *
+     * ★取り込み対応表はファイルに 1 つではなく**領域ごと**に持つ。ファイル全体で 1 つにすると、
+     *   同じ別名を別の完全修飾名へ向ける 2 つ目の名前空間が 1 つ目の対応表を上書きし、
+     *   1 つ目の `putenv` 別名呼び出しが候補から外れて**黙って見逃される** (fail-open)。
+     * ★`aliasKeys` は**上書きされたものも含む**全別名の集合である。解決不能なファイルで
+     *   「`putenv` 相当の出現」を数える母集団に使う (最終的な対応表だけを見ると上と同じ穴が開く)。
      *
      * @param  list<array{id: int|null, text: string, line: int}>  $tokens
-     * @return array{namespace: string, aliases: array<string, string>, unresolved: bool}
+     * @param  array<int, int>  $pairs
+     * @return array{
+     *     regions: list<array{start: int, end: int, namespace: string, aliases: array<string, string>}>,
+     *     aliasKeys: array<string, true>,
+     *     unresolved: bool,
+     * }
      */
-    private static function analyseFileContext(array $tokens): array
+    private static function analyseFileContext(array $tokens, array $pairs): array
     {
         $count = count($tokens);
-        $namespaces = [];
+        $declarations = [];
         $braced = false;
 
         foreach ($tokens as $index => $token) {
@@ -153,36 +166,63 @@ private static function analyseFileContext(array $tokens): array
                 $cursor++;
             }
 
-            $namespaces[] = trim($name, '\\');
+            $end = $count - 1;
 
             if ($cursor < $count && $tokens[$cursor]['id'] === null && $tokens[$cursor]['text'] === '{') {
                 $braced = true;
             }
+
+            $declarations[] = ['start' => $index, 'end' => $end, 'namespace' => trim($name, '\\')];
         }
 
-        $unresolved = count($namespaces) >= 2 || $braced;
-        $namespace = $namespaces === [] ? '' : $namespaces[0];
+        // 領域の終端は次の宣言の直前 (波括弧つきでも、走査の目的では同じ区切りで足りる)。
+        foreach ($declarations as $position => $declaration) {
+            if (isset($declarations[$position + 1])) {
+                $declarations[$position]['end'] = $declarations[$position + 1]['start'] - 1;
+            }
+        }
 
-        $aliases = [];
+        if ($declarations === []) {
+            $declarations[] = ['start' => 0, 'end' => $count - 1, 'namespace' => ''];
+        }
 
-        for ($i = 0; $i + 1 < $count; $i++) {
-            if ($tokens[$i]['id'] !== T_USE || $tokens[$i + 1]['id'] !== T_FUNCTION) {
-                continue;
-            }
+        $unresolved = count($declarations) >= 2 || $braced;
+        $regions = [];
+        $aliasKeys = [];
 
-            $statement = [];
+        foreach ($declarations as $declaration) {
+            $aliases = [];
 
-            for ($j = $i + 2; $j < $count; $j++) {
-                if ($tokens[$j]['id'] === null && $tokens[$j]['text'] === ';') {
-                    break;
+            for ($i = $declaration['start']; $i + 1 <= $declaration['end']; $i++) {
+                if ($tokens[$i]['id'] !== T_USE || $tokens[$i + 1]['id'] !== T_FUNCTION) {
+                    continue;
                 }
 
-                $statement[] = $tokens[$j];
+                $statement = [];
+
+                for ($j = $i + 2; $j <= $declaration['end']; $j++) {
+                    if ($tokens[$j]['id'] === null && $tokens[$j]['text'] === ';') {
+                        break;
+                    }
+
+                    $statement[] = $tokens[$j];
+                }
+
+                if (! self::collectFunctionImports($statement, $aliases)) {
+                    $unresolved = true;
+                }
             }
 
-            if (! self::collectFunctionImports($statement, $aliases)) {
-                $unresolved = true;
+            foreach (array_keys($aliases) as $alias) {
+                $aliasKeys[$alias] = true;
             }
+
+            $regions[] = [
+                'start' => $declaration['start'],
+                'end' => $declaration['end'],
+                'namespace' => $declaration['namespace'],
+                'aliases' => $aliases,
+            ];
         }
 
         // そのファイル自身が `putenv` という名前の関数を宣言していたら、非修飾の呼び出しは
@@ -201,7 +241,7 @@ private static function analyseFileContext(array $tokens): array
             }
         }
 
-        return ['namespace' => $namespace, 'aliases' => $aliases, 'unresolved' => $unresolved];
+        return ['regions' => $regions, 'aliasKeys' => $aliasKeys, 'unresolved' => $unresolved];
     }
 
     /**
@@ -297,7 +337,11 @@ private static function collectFunctionImports(array $statement, array &$aliases
      * 関数呼び出しの位置が `\putenv` を指すか (指せないなら未解決)。
      *
      * @param  list<array{id: int|null, text: string, line: int}>  $tokens
-     * @param  array{namespace: string, aliases: array<string, string>, unresolved: bool}  $context
+     * @param  array{
+     *     regions: list<array{start: int, end: int, namespace: string, aliases: array<string, string>}>,
+     *     aliasKeys: array<string, true>,
+     *     unresolved: bool,
+     * }  $context
      */
     private static function classifyFunctionCall(array $tokens, array $context, int $index): ?RawEnvWriteKind
     {
@@ -319,17 +363,15 @@ private static function classifyFunctionCall(array $tokens, array $context, int
             return null;
         }
 
-        $text = $token['text'];
-        $lowered = strtolower($text);
+        $lowered = strtolower($token['text']);
         $segments = explode('\\', trim($lowered, '\\'));
         $lastSegment = $segments[count($segments) - 1];
 
-        $isAliasOfPutenv = $token['id'] === T_STRING
-            && isset($context['aliases'][$lowered])
-            && strtolower($context['aliases'][$lowered]) === 'putenv';
-
         // `putenv` 相当の綴りを持つ呼び出しかどうか (未解決の判定にも使う母集団)。
-        $isCandidate = $lastSegment === 'putenv' || $isAliasOfPutenv;
+        // 別名は**上書きされたものも含む全別名**で数える (最終的な対応表だけを見ると、
+        // 2 つ目の名前空間の取り込みが 1 つ目を隠して見逃しになる)。
+        $isCandidate = $lastSegment === 'putenv'
+            || ($token['id'] === T_STRING && isset($context['aliasKeys'][$lowered]));
 
         if (! $isCandidate) {
             return null;
@@ -339,23 +381,46 @@ private static function classifyFunctionCall(array $tokens, array $context, int
             return RawEnvWriteKind::Unresolved;
         }
 
+        $region = self::regionAt($context['regions'], $index);
+
+        if ($region === null) {
+            return RawEnvWriteKind::Unresolved;
+        }
+
         return match ($token['id']) {
             T_NAME_FULLY_QUALIFIED => trim($lowered, '\\') === 'putenv' ? RawEnvWriteKind::Putenv : null,
-            T_NAME_RELATIVE => $context['namespace'] === '' ? RawEnvWriteKind::Putenv : null,
+            T_NAME_RELATIVE => $region['namespace'] === '' ? RawEnvWriteKind::Putenv : null,
             T_NAME_QUALIFIED => null,
-            default => self::classifyUnqualifiedCall($context, $lowered),
+            default => self::classifyUnqualifiedCall($region['aliases'], $lowered),
         };
     }
 
     /**
-     * 非修飾の呼び出しを取り込み対応表とグローバル fallback で解決する。
+     * その添字を含む名前空間の領域。
+     *
+     * @param  list<array{start: int, end: int, namespace: string, aliases: array<string, string>}>  $regions
+     * @return array{start: int, end: int, namespace: string, aliases: array<string, string>}|null
+     */
+    private static function regionAt(array $regions, int $index): ?array
+    {
+        foreach ($regions as $region) {
+            if ($index >= $region['start'] && $index <= $region['end']) {
+                return $region;
+            }
+        }
+
+        return null;
+    }
+
+    /**
+     * 非修飾の呼び出しを、**その領域の**取り込み対応表とグローバル fallback で解決する。
      *
-     * @param  array{namespace: string, aliases: array<string, string>, unresolved: bool}  $context
+     * @param  array<string, string>  $aliases
      */
-    private static function classifyUnqualifiedCall(array $context, string $lowered): ?RawEnvWriteKind
+    private static function classifyUnqualifiedCall(array $aliases, string $lowered): ?RawEnvWriteKind
     {
-        if (isset($context['aliases'][$lowered])) {
-            return strtolower($context['aliases'][$lowered]) === 'putenv' ? RawEnvWriteKind::Putenv : null;
+        if (isset($aliases[$lowered])) {
+            return strtolower($aliases[$lowered]) === 'putenv' ? RawEnvWriteKind::Putenv : null;
         }
 
         // 名前空間の中でも、非修飾の関数呼び出しはグローバルへ fallback する。
@@ -381,13 +446,10 @@ private static function classifySurface(
     ): ?RawEnvWriteKind {
         $previous = $index > 0 ? $tokens[$index - 1] : null;
         $next = $tokens[$index + 1] ?? null;
-        // 分割代入のパターンでは `[` も要素の先頭になるが、引数リストでは `(` / `,` だけである。
-        $atElementHead = $previous !== null
-            && $previous['id'] === null
-            && in_array($previous['text'], ['[', '(', ','], true);
         $atArgumentHead = $previous !== null
             && $previous['id'] === null
             && in_array($previous['text'], ['(', ','], true);
+        $byReference = $previous !== null && self::isReferenceSign($previous);
 
         foreach ($destructuring as $range) {
             if ($index <= $range[0] || $index >= $range[1]) {
@@ -395,17 +457,14 @@ private static function classifySurface(
             }
 
             // 範囲に入っただけでは書き込みにしない。lvalue の根にあるときだけ対象にする。
-            if ($atElementHead && ! self::isInsideIndexBracket($tokens, $pairs, $range, $index)) {
-                return RawEnvWriteKind::DestructuringTarget;
+            if (! self::isDestructuringTargetRoot($tokens, $pairs, $range, $index)) {
+                return null;
             }
 
-            return null;
+            return $byReference ? RawEnvWriteKind::ReferenceTaken : RawEnvWriteKind::DestructuringTarget;
         }
 
-        if ($previous !== null
-            && ($previous['id'] === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG
-                || ($previous['id'] === null && $previous['text'] === '&'))
-        ) {
+        if ($byReference) {
             return RawEnvWriteKind::ReferenceTaken;
         }
 
@@ -461,6 +520,67 @@ private static function classifySurface(
         return RawEnvWriteKind::Unresolved;
     }
 
+    /**
+     * 分割代入の範囲の中で、その面が**代入先の根**にあるか。
+     *
+     * 満たすべきは 3 つである:
+     *
+     *  1. 要素の先頭位置にあること。先頭とは `[` / `(` / `,` / `=>` の直後、または
+     *     **参照記号を挟んだその直後** (`[&$_ENV['K']] = $v;`) である
+     *  2. 範囲の根との間に**添字の括弧が 1 つも無い**こと
+     *     (`[$other[$_SERVER['K']]] = $v;` の `$_SERVER` は添字を求める読み出しである)
+     *  3. 添字の連鎖の**直後が `=>` でない**こと
+     *     (`[$_SERVER['K'] => $v] = $x;` の `$_SERVER` は連想の**鍵**であって代入先ではない)
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  array<int, int>  $pairs
+     * @param  array{int, int}  $range
+     */
+    private static function isDestructuringTargetRoot(array $tokens, array $pairs, array $range, int $index): bool
+    {
+        $head = $index - 1;
+
+        if ($head >= 0 && self::isReferenceSign($tokens[$head])) {
+            $head--;
+        }
+
+        if ($head < 0 || $tokens[$head]['id'] !== null || ! in_array($tokens[$head]['text'], ['[', '(', ','], true)) {
+            if ($head < 0 || $tokens[$head]['id'] !== T_DOUBLE_ARROW) {
+                return false;
+            }
+        }
+
+        if (self::isInsideIndexBracket($tokens, $pairs, $range, $index)) {
+            return false;
+        }
+
+        $cursor = $index + 1;
+
+        while (isset($tokens[$cursor]) && $tokens[$cursor]['id'] === null && $tokens[$cursor]['text'] === '[') {
+            if (! isset($pairs[$cursor])) {
+                return false;
+            }
+
+            $cursor = $pairs[$cursor] + 1;
+        }
+
+        return ! isset($tokens[$cursor]) || $tokens[$cursor]['id'] !== T_DOUBLE_ARROW;
+    }
+
+    /**
+     * 参照記号か (PHP 8.1 以降は変数の前の `&` が専用トークンになる)。
+     *
+     * @param  array{id: int|null, text: string, line: int}  $token
+     */
+    private static function isReferenceSign(array $token): bool
+    {
+        if ($token['id'] === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG) {
+            return true;
+        }
+
+        return $token['id'] === null && $token['text'] === '&';
+    }
+
     /**
      * 面が分割代入の範囲の中で「添字の括弧」に囲まれているか (囲まれていれば読み出し)。
      *
diff --git a/tests/Support/RawEnv/RawEnvGuardStructure.php b/tests/Support/RawEnv/RawEnvGuardStructure.php
index 1c7ed708..42144ffb 100644
--- a/tests/Support/RawEnv/RawEnvGuardStructure.php
+++ b/tests/Support/RawEnv/RawEnvGuardStructure.php
@@ -5,6 +5,7 @@
 namespace Tests\Support\RawEnv;
 
 use InvalidArgumentException;
+use ReflectionClass;
 use ReflectionMethod;
 use RuntimeException;
 use Tests\Support\PhpTokenScan;
@@ -321,11 +322,22 @@ public static function staticCalls(array $tokens, string $method): array
     /**
      * `new <クラス名>(` の形の生成の**開き丸括弧**の位置。
      *
+     * ★クラス名は**宣言元ファイルの取り込みと名前空間を解いた完全修飾名**で突き合わせる
+     *   (AGENTS.md 走査器共通規約 (a))。短名の末尾一致は使わない —
+     *   `Vendor\RuntimeException` を `RuntimeException` と誤認するためである。
+     * ★**保証しないもの**: 実行時に決まるクラス (`new $class(`) と
+     *   `new static(` / `new self(` は候補に入らない。母集団が空になるので、
+     *   「ちょうど 1 件」を要求する利用側 (`constructionArgumentMatches()`) は
+     *   偽を返して赤くなる (fail-closed)。
+     *
      * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  class-string  $declaringClass  そのメソッドを宣言しているクラス (取り込み表と名前空間の出所)
+     * @param  class-string  $expected  期待する完全修飾クラス名
      * @return list<int>
      */
-    public static function constructions(array $tokens, string $class): array
+    public static function constructions(array $tokens, string $declaringClass, string $expected): array
     {
+        $resolver = self::nameResolver($declaringClass);
         $count = count($tokens);
         $found = [];
 
@@ -334,9 +346,13 @@ public static function constructions(array $tokens, string $class): array
                 continue;
             }
 
-            $name = $tokens[$i + 1]['text'];
+            if (! self::isNamePart($tokens[$i + 1])) {
+                continue;
+            }
+
+            $resolved = self::resolveClassName($resolver, $tokens[$i + 1]['text']);
 
-            if ($name !== $class && ! str_ends_with($name, '\\'.$class)) {
+            if (strtolower($resolved) !== strtolower(ltrim($expected, '\\'))) {
                 continue;
             }
 
@@ -348,6 +364,182 @@ public static function constructions(array $tokens, string $class): array
         return $found;
     }
 
+    /**
+     * 宣言元ファイルのクラス取り込み表と名前空間 (完全修飾名の解決に使う)。
+     *
+     * `use` の対象は**クラスの取り込みだけ**である (`use function` / `use const` /
+     * 閉包の `use` は対象外)。group use (`use A\{B, C as D};`) も解く。
+     *
+     * @param  class-string  $class
+     * @return array{namespace: string, imports: array<string, string>}
+     */
+    private static function nameResolver(string $class): array
+    {
+        $reflection = new ReflectionClass($class);
+        $file = $reflection->getFileName();
+
+        if ($file === false) {
+            throw new RuntimeException("class source is not available: {$class}");
+        }
+
+        $source = file_get_contents($file);
+
+        if ($source === false) {
+            throw new RuntimeException("class source file is not readable: {$file}");
+        }
+
+        $tokens = PhpTokenScan::normalize($source);
+        $count = count($tokens);
+        $imports = [];
+
+        for ($i = 0; $i + 1 < $count; $i++) {
+            if ($tokens[$i]['id'] !== T_USE) {
+                continue;
+            }
+
+            // `use function` / `use const` / 閉包の `use (...)` は対象外。
+            if (in_array($tokens[$i + 1]['id'], [T_FUNCTION, T_CONST], true)) {
+                continue;
+            }
+
+            if ($tokens[$i + 1]['id'] === null && $tokens[$i + 1]['text'] === '(') {
+                continue;
+            }
+
+            $statement = [];
+
+            for ($j = $i + 1; $j < $count; $j++) {
+                if ($tokens[$j]['id'] === null && $tokens[$j]['text'] === ';') {
+                    break;
+                }
+
+                $statement[] = $tokens[$j];
+            }
+
+            self::collectClassImports($statement, $imports);
+        }
+
+        return ['namespace' => $reflection->getNamespaceName(), 'imports' => $imports];
+    }
+
+    /**
+     * `use …;` 1 文を短名 => 完全修飾名の対応表へ展開する。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $statement
+     * @param  array<string, string>  $imports
+     */
+    private static function collectClassImports(array $statement, array &$imports): void
+    {
+        $prefix = '';
+        $body = $statement;
+
+        foreach ($statement as $position => $token) {
+            if ($token['id'] === null && $token['text'] === '{') {
+                $prefix = '';
+
+                foreach (array_slice($statement, 0, $position) as $prefixToken) {
+                    $prefix .= $prefixToken['text'];
+                }
+
+                $body = [];
+
+                foreach (array_slice($statement, $position + 1) as $bodyToken) {
+                    if ($bodyToken['id'] === null && $bodyToken['text'] === '}') {
+                        break;
+                    }
+
+                    $body[] = $bodyToken;
+                }
+
+                break;
+            }
+        }
+
+        $entries = [[]];
+
+        foreach ($body as $token) {
+            if ($token['id'] === null && $token['text'] === ',') {
+                $entries[] = [];
+
+                continue;
+            }
+
+            $entries[count($entries) - 1][] = $token;
+        }
+
+        foreach ($entries as $entry) {
+            if ($entry === []) {
+                continue;
+            }
+
+            $alias = null;
+            $nameTokens = $entry;
+            $entryCount = count($entry);
+
+            if ($entryCount >= 3 && $entry[$entryCount - 2]['id'] === T_AS) {
+                $alias = $entry[$entryCount - 1]['text'];
+                $nameTokens = array_slice($entry, 0, $entryCount - 2);
+            }
+
+            $name = '';
+
+            foreach ($nameTokens as $nameToken) {
+                if (! self::isNamePart($nameToken)) {
+                    return;
+                }
+
+                $name .= $nameToken['text'];
+            }
+
+            $fullyQualified = trim($prefix.$name, '\\');
+
+            if ($fullyQualified === '') {
+                continue;
+            }
+
+            $segments = explode('\\', $fullyQualified);
+            $alias ??= $segments[count($segments) - 1];
+            $imports[strtolower($alias)] = $fullyQualified;
+        }
+    }
+
+    /**
+     * ソースに書かれた綴りを完全修飾名へ解く。
+     *
+     * @param  array{namespace: string, imports: array<string, string>}  $resolver
+     */
+    private static function resolveClassName(array $resolver, string $spelling): string
+    {
+        if (str_starts_with($spelling, '\\')) {
+            return ltrim($spelling, '\\');
+        }
+
+        $segments = explode('\\', $spelling);
+        $first = strtolower($segments[0]);
+
+        if (isset($resolver['imports'][$first])) {
+            $segments[0] = $resolver['imports'][$first];
+
+            return implode('\\', $segments);
+        }
+
+        return $resolver['namespace'] === '' ? $spelling : $resolver['namespace'].'\\'.$spelling;
+    }
+
+    /**
+     * 名前の一部として扱うトークンか。
+     *
+     * @param  array{id: int|null, text: string, line: int}  $token
+     */
+    private static function isNamePart(array $token): bool
+    {
+        return in_array(
+            $token['id'],
+            [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_NS_SEPARATOR],
+            true,
+        );
+    }
+
     /**
      * 制御フローのトークンの出現位置。
      *
@@ -568,6 +760,7 @@ public static function applyLoopIsGuarded(array $tokens, array $loopExpression,
      *  1. 復元のループの本体に `throw` / `return` / `break` / `continue` が 1 件も無い
      *  2. `$accumulator[] = …` がループ本体にちょうど 1 件ある
      *  3. その追加が `$flagVariable === false` の条件分岐の**本体**にある
+     *     (条件は綴りの列の**完全一致**で見る。包含だと結合していない条件を誤認する)
      *  4. ループの**後**の `$accumulator !== []` の条件分岐の本体に、**メソッド唯一の `throw`** がある
      *  5. その `throw` 以外に、メソッドを途中終了させるトークン (`return` / `throw`) が無い
      *
@@ -602,7 +795,7 @@ public static function restoreStructureIsDeferred(array $tokens, array $loopExpr
         $blocks = self::ifBlocks($tokens);
         $failureBranches = array_values(array_filter(
             $blocks,
-            fn (array $block): bool => self::conditionMatches($tokens, $block['condition'], $flagVariable, T_IS_IDENTICAL, 'false'),
+            fn (array $block): bool => self::conditionEquals($tokens, $block['condition'], [$flagVariable, '===', 'false']),
         ));
 
         if (count($failureBranches) !== 1 || ! self::isWithin($failureBranches[0]['body'], $appends[0])) {
@@ -618,7 +811,7 @@ public static function restoreStructureIsDeferred(array $tokens, array $loopExpr
 
         $reportBranches = array_values(array_filter(
             $blocks,
-            fn (array $block): bool => self::conditionMatches($tokens, $block['condition'], $accumulator, T_IS_NOT_IDENTICAL, '['),
+            fn (array $block): bool => self::conditionEquals($tokens, $block['condition'], [$accumulator, '!==', '[', ']']),
         ));
 
         if (count($reportBranches) !== 1 || ! self::isWithin($reportBranches[0]['body'], $throws[0])) {
@@ -696,11 +889,18 @@ public static function soleThrowMatches(array $tokens, array $blockRange, array
      * `new <クラス名>(…)` がちょうど 1 件あり、指定位置の引数が期待の綴り列か。
      *
      * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  class-string  $declaringClass
+     * @param  class-string  $expectedClass
      * @param  list<string>  $expected
      */
-    public static function constructionArgumentMatches(array $tokens, string $class, int $argumentIndex, array $expected): bool
-    {
-        $constructions = self::constructions($tokens, $class);
+    public static function constructionArgumentMatches(
+        array $tokens,
+        string $declaringClass,
+        string $expectedClass,
+        int $argumentIndex,
+        array $expected,
+    ): bool {
+        $constructions = self::constructions($tokens, $declaringClass, $expectedClass);
 
         if (count($constructions) !== 1) {
             return false;
@@ -710,32 +910,26 @@ public static function constructionArgumentMatches(array $tokens, string $class,
     }
 
     /**
-     * 条件のトークン範囲が「変数 + 比較演算子 + 綴り」を含むか。
+     * 条件のトークン範囲の綴りの列が、期待の列と**完全一致**するか。
+     *
+     * ★包含 (「変数と演算子と右辺らしい綴りがどこかに在る」) では判定にならない —
+     *   `if (! $applied && $other === false)` にも 3 つとも現れるため、
+     *   結合していない条件を `$applied === false` と誤認する。
+     *   動的に検査できない性質の唯一の代替保証なので、**対応関係ごと**固定する。
      *
      * @param  list<array{id: int|null, text: string, line: int}>  $tokens
      * @param  array{int, int}  $condition
+     * @param  list<string>  $expectedTexts
      */
-    private static function conditionMatches(array $tokens, array $condition, string $variable, int $operatorId, string $rightText): bool
+    public static function conditionEquals(array $tokens, array $condition, array $expectedTexts): bool
     {
-        $hasVariable = false;
-        $hasOperator = false;
-        $hasRight = false;
+        $texts = [];
 
         for ($i = $condition[0]; $i <= $condition[1]; $i++) {
-            if ($tokens[$i]['id'] === T_VARIABLE && $tokens[$i]['text'] === $variable) {
-                $hasVariable = true;
-            }
-
-            if ($tokens[$i]['id'] === $operatorId) {
-                $hasOperator = true;
-            }
-
-            if ($tokens[$i]['text'] === $rightText) {
-                $hasRight = true;
-            }
+            $texts[] = $tokens[$i]['text'];
         }
 
-        return $hasVariable && $hasOperator && $hasRight;
+        return $texts === $expectedTexts;
     }
 
     /**
diff --git a/tests/Unit/Architecture/RawEnvDirectWriteScannerTest.php b/tests/Unit/Architecture/RawEnvDirectWriteScannerTest.php
index e28a798b..b4cfeec4 100644
--- a/tests/Unit/Architecture/RawEnvDirectWriteScannerTest.php
+++ b/tests/Unit/Architecture/RawEnvDirectWriteScannerTest.php
@@ -228,6 +228,43 @@ function rawEnvScannerKinds(string $source): array
     expect(rawEnvScannerKinds($source))->toBe([]);
 });
 
+test('正例 11: 連想の分割代入と参照つきの分割代入も検出される', function (): void {
+    $keyed = <<<'PHP'
+    <?php
+    ['key' => $_SERVER['K']] = $value;
+    PHP;
+
+    $byReference = <<<'PHP'
+    <?php
+    [&$_ENV['K']] = $value;
+    PHP;
+
+    expect(rawEnvScannerKinds($keyed))->toBe(['destructuring_target'])
+        ->and(rawEnvScannerKinds($byReference))->toBe(['reference_taken']);
+});
+
+test('負例 8: 分割代入の鍵の側にある面は読み出しなので検出しない', function (): void {
+    $source = <<<'PHP'
+    <?php
+    [$_SERVER['K'] => $v] = $value;
+    PHP;
+
+    expect(rawEnvScannerKinds($source))->toBe([]);
+});
+
+test('負例 9: 別名を putenv 以外へ向けた取り込みは名前空間をまたいでも誤検出しない', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Probe;
+
+    use function Acme\noop as setRawEnv;
+
+    setRawEnv('K=V');
+    PHP;
+
+    expect(rawEnvScannerKinds($source))->toBe([]);
+});
+
 // ── 解決できない形は落とす (fail-closed) ──
 
 test('未解決 1: 自前で putenv を宣言したファイルの非修飾呼び出しは unresolved', function (): void {
@@ -265,8 +302,27 @@ function putenv(string $assignment): bool
     }
     PHP;
 
+    // ★ここが load-bearing である。取り込み対応表をファイルに 1 つしか持たないと、
+    //   2 つ目の名前空間の `setRawEnv` が 1 つ目を上書きし、1 つ目の呼び出しが
+    //   「putenv 相当ではない」と判定されて**黙って見逃される** (fail-open)。
+    $shadowedAlias = <<<'PHP'
+    <?php
+    namespace A;
+
+    use function putenv as setRawEnv;
+
+    setRawEnv('K=V');
+
+    namespace B;
+
+    use function Acme\noop as setRawEnv;
+
+    setRawEnv('K=V');
+    PHP;
+
     expect(rawEnvScannerKinds($twoDeclarations))->toBe(['unresolved', 'unresolved'])
-        ->and(rawEnvScannerKinds($braced))->toBe(['unresolved']);
+        ->and(rawEnvScannerKinds($braced))->toBe(['unresolved'])
+        ->and(rawEnvScannerKinds($shadowedAlias))->toBe(['unresolved', 'unresolved']);
 });
 
 test('未解決 3: 読み出しとも書き込みとも決まらない単独の出現は unresolved', function (): void {
diff --git a/tests/Unit/Architecture/RawEnvGuardStructureTest.php b/tests/Unit/Architecture/RawEnvGuardStructureTest.php
index e410bef0..a80dc76a 100644
--- a/tests/Unit/Architecture/RawEnvGuardStructureTest.php
+++ b/tests/Unit/Architecture/RawEnvGuardStructureTest.php
@@ -3,6 +3,7 @@
 declare(strict_types=1);
 
 use Tests\Support\RawEnv\RawEnvGuardStructure;
+use Tests\Support\RawEnv\RawEnvSnapshot;
 
 /*
  * `Tests\Support\RawEnv\RawEnvGuardStructure` の自己検査 (走査器の検出力の裏取り)。
@@ -121,7 +122,7 @@ public function restore(?Throwable $previous = null): void
     $tokens = RawEnvGuardStructure::tokenize(RAW_ENV_STRUCTURE_RESTORE_SHAPE);
 
     expect(RawEnvGuardStructure::restoreStructureIsDeferred($tokens, ['$this', '->', 'state'], '$failed', '$applied'))->toBeTrue()
-        ->and(RawEnvGuardStructure::constructionArgumentMatches($tokens, 'RuntimeException', 2, ['$previous']))->toBeTrue();
+        ->and(RawEnvGuardStructure::constructionArgumentMatches($tokens, RawEnvSnapshot::class, RuntimeException::class, 2, ['$previous']))->toBeTrue();
 });
 
 // ── 負例 ──
@@ -315,7 +316,7 @@ public static function with(array $changes, Closure $body): mixed
 
     $finally = RawEnvGuardStructure::soleBlockRange($noArgument, T_FINALLY);
 
-    expect(RawEnvGuardStructure::constructionArgumentMatches($noPrevious, 'RuntimeException', 2, ['$previous']))->toBeFalse()
+    expect(RawEnvGuardStructure::constructionArgumentMatches($noPrevious, RawEnvSnapshot::class, RuntimeException::class, 2, ['$previous']))->toBeFalse()
         ->and(RawEnvGuardStructure::methodCallArgumentMatches($noArgument, $finally, '$snapshot', 'restore', 0, ['$bodyError']))->toBeFalse();
 });
 
@@ -356,6 +357,67 @@ public static function with(array $changes, Closure $body): mixed
         ->and(RawEnvGuardStructure::soleThrowMatches($dropped, $droppedCatch, ['$e']))->toBeFalse();
 });
 
+test('負例 11: 対象変数に結び付いていない条件は誤認しない', function (): void {
+    $tokens = RawEnvGuardStructure::tokenize(<<<'PHP'
+    public function restore(?Throwable $previous = null): void
+    {
+        $failed = [];
+
+        foreach ($this->state as $saved) {
+            $applied = putenv($saved['key']);
+
+            if (! $applied && $other === false) {
+                $failed[] = $saved['key'];
+            }
+        }
+
+        if ($failed !== []) {
+            throw new RuntimeException('boom', 0, $previous);
+        }
+    }
+    PHP);
+
+    // ★包含で見ると `$applied` / `===` / `false` の 3 つとも条件に在るので通ってしまう。
+    //   完全一致で見れば偽になる (これが load-bearing である)。
+    expect(RawEnvGuardStructure::restoreStructureIsDeferred($tokens, ['$this', '->', 'state'], '$failed', '$applied'))->toBeFalse();
+});
+
+test('負例 12: 同じ短名の別クラスを生成しても期待クラスとは一致しない', function (): void {
+    $tokens = RawEnvGuardStructure::tokenize(<<<'PHP'
+    public function restore(?Throwable $previous = null): void
+    {
+        throw new \Vendor\RuntimeException('boom', 0, $previous);
+    }
+    PHP);
+
+    expect(RawEnvGuardStructure::constructions($tokens, RawEnvSnapshot::class, RuntimeException::class))->toBe([])
+        ->and(RawEnvGuardStructure::constructionArgumentMatches($tokens, RawEnvSnapshot::class, RuntimeException::class, 2, ['$previous']))->toBeFalse();
+});
+
+test('正例 5: 条件の綴りの列を完全一致で取り出せる', function (): void {
+    $tokens = RawEnvGuardStructure::tokenize(RAW_ENV_STRUCTURE_RESTORE_SHAPE);
+    $blocks = RawEnvGuardStructure::ifBlocks($tokens);
+
+    expect($blocks)->toHaveCount(2)
+        ->and(RawEnvGuardStructure::conditionEquals($tokens, $blocks[0]['condition'], ['$applied', '===', 'false']))->toBeTrue()
+        ->and(RawEnvGuardStructure::conditionEquals($tokens, $blocks[1]['condition'], ['$failed', '!==', '[', ']']))->toBeTrue()
+        ->and(RawEnvGuardStructure::conditionEquals($tokens, $blocks[0]['condition'], ['$applied', '===', 'true']))->toBeFalse();
+});
+
+test('fail-closed 6: 丸括弧の対応が取れない引数リストは例外になる', function (): void {
+    $tokens = RawEnvGuardStructure::tokenize(<<<'PHP'
+    public function run(): void
+    {
+        $snapshot->restore($bodyError
+    PHP);
+
+    $calls = RawEnvGuardStructure::methodCalls($tokens, '$snapshot', 'restore');
+
+    expect($calls)->toHaveCount(1)
+        ->and(fn (): array => RawEnvGuardStructure::callArguments($tokens, $calls[0]))
+        ->toThrow(RuntimeException::class);
+});
+
 // ── 解決できない形は落とす (fail-closed) ──
 
 test('fail-closed 1: try が 2 件ある入力は例外になる', function (): void {
diff --git a/tests/Unit/Support/RawEnv/RawEnvSnapshotContractTest.php b/tests/Unit/Support/RawEnv/RawEnvSnapshotContractTest.php
index 4c4ce644..478fbeb8 100644
--- a/tests/Unit/Support/RawEnv/RawEnvSnapshotContractTest.php
+++ b/tests/Unit/Support/RawEnv/RawEnvSnapshotContractTest.php
@@ -580,5 +580,5 @@ function (): void {
     $tokens = RawEnvGuardStructure::methodTokens(RawEnvSnapshot::class, 'restore');
 
     expect(RawEnvGuardStructure::restoreStructureIsDeferred($tokens, ['$this', '->', 'state'], '$failed', '$applied'))->toBeTrue()
-        ->and(RawEnvGuardStructure::constructionArgumentMatches($tokens, 'RuntimeException', 2, ['$previous']))->toBeTrue();
+        ->and(RawEnvGuardStructure::constructionArgumentMatches($tokens, RawEnvSnapshot::class, RuntimeException::class, 2, ['$previous']))->toBeTrue();
 });

```

## 検証コマンド全数の結果 (AGENTS.md の 10 本)

| コマンド | 結果 |
|---|---|
| `composer test` | passed — 7827 tests / 7825 passed / 2 skipped / 5 risky / 0 failed |
| `composer phpstan` (level 10) | OK — No errors |
| `vendor/bin/pint --test` | passed |
| `pnpm lint` | passed (eslint resources/js) |
| `pnpm typecheck` | passed (tsc --noEmit) |
| `pnpm test` | passed — 179 files / 2398 tests |
| `pnpm build` | passed (built in 8.84s) |
| `pnpm typecheck:packages` | passed |
| `pnpm build:packages` | passed |
| `pnpm test:packages` | passed — 10 files / 106 tests |

個別の新規・変更テスト:

- `tests/Unit/Support/RawEnv/RawEnvSnapshotContractTest.php`: 34 passed
- `tests/Unit/Architecture/RawEnvGuardStructureTest.php`: 24 passed (負例 4 本追加)
- `tests/Unit/Architecture/RawEnvDirectWriteScannerTest.php`: 39 passed (正例 1 本・負例 2 本追加)
- `tests/Architecture/RawEnvDirectWriteGateTest.php`: 13 passed

## 確認してほしいこと

1. Critical 3 件の修正が**指摘した見逃し経路を実際に塞いでいるか** (とくに
   名前空間ごとの取り込み対応表と `aliasKeys` の組み合わせで、別名の上書きによる
   fail-open が残っていないか)。
2. 分割代入の 3 条件 (要素の先頭 / 添字の括弧の不在 / 直後が `=>` でない) に、
   まだ見逃す形が残っていないか。
3. `constructions()` の完全修飾名解決で、`use` の解けない形を黙って読み飛ばしていないか
   (`collectClassImports()` は解けない綴りに当たったら `return` して以降を捨てるので、
   その挙動が fail-open になっていないか特に見てほしい)。
4. `conditionEquals()` の完全一致で、意図した構造まで過度に脆くなっていないか
   (脆いこと自体は設計の意図だが、整形 (Pint) で赤くなる形になっていないか)。

全体判定を **APPROVED** または **CHANGES_REQUESTED** の 1 語で書くこと。
