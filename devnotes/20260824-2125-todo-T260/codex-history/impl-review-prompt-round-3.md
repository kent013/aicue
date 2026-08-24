Round 2 の指摘 (Critical 2 件 / Warning 1 件) すべてに対応した。

# 対応マトリクス: impl-review Round 2

## [Critical] `nameResolver()` がファイル中の全 `T_USE` を 1 つの取り込み表へ集約している

- 判断: **対応する**
- 根拠: 指摘のとおり、別名前空間の import とクラス本体の trait `use` が同じ短名を上書きでき、
  別クラスを期待クラスと誤解決できる。構造検査は動的に作れない性質の唯一の代替保証なので、
  誤解決は保証を空洞にする。
- 対応内容:
  - 取り込み解析を**純関数** `classImports(string $phpSource, string $namespace): array` へ切り出した
    (これにより自己検査が合成入力で全分岐を突ける)。
  - 見るのは**その名前空間の領域のトップレベルの `use` だけ**にした
    (波括弧の深さを数え、深さ 0 の `use` に限る)。クラス本体の中の trait `use` は数えない。
  - `use function` / `use const` / 閉包の `use (...)` を除く判定はそのまま維持した。

## [Critical] `collectClassImports()` が解けない形を無言で読み飛ばす

- 判断: **対応する**
- 根拠: AGENTS.md 走査器共通規約 (b) の「未解決を解決済みと同じ値へ混ぜない」に反する。
- 対応内容:
  - `collectClassImports()` を `bool` 返しにし、解けない綴りに当たったら false を返すようにした。
  - **同じ短名が別の完全修飾名へ 2 度取り込まれている**形も false にした (解決先が決まらないため)。
  - `classImports()` は false を受けたら `RuntimeException` を送出する。加えて
    名前空間宣言が 2 つ以上 / 波括弧つき名前空間 / 宣言された名前空間が引数と食い違う場合も例外にした。
  - 負例を 5 形 (2 つの名前空間 / 波括弧つき / 名前空間の食い違い / group use に `function` が混ざる /
    同じ短名の二重取り込み) と、正例 (trait `use` を数えないこと) を追加した。

## [Warning] `aliasKeys` が全 `use function` 別名を集めており、無関係な別名まで unresolved になる

- 判断: **対応する**
- 根拠: 指摘のとおり。`unresolved` が立ったファイルで無関係な別名関数の呼び出しまで
  違反になるのは、拾いすぎの側とはいえ開発を止める誤検出である。
- 対応内容:
  - 候補の母集団を `putenvAliasKeys` (**どこかの領域で `\putenv` を指した**別名。上書きされたものも残す)
    へ限定した。
  - ただし `use function` の取り込み自体が解けなかったときは、どの別名が `putenv` を指したか
    分からないので、そのときだけ全別名 (`aliasKeys`) へ広げる (fail-closed)。
  - 負例「2 つの名前空間があり、別名は `putenv` を 1 度も指さない」を追加した (検出 0 件)。

## [情報] `conditionEquals()` の脆さについて

- Round 2 のレビューで「Pint 整形では赤くならない / 構造変更でだけ赤くなる」と確認された。
  これは設計が意図した脆さであり、docblock に「赤くなったら判定を緩めるのではなく
  構造が本当に変わってよいのかを確認する」と書いてある。変更しない。


## 修正差分 (Round 2 でレビューした状態からの delta)

```diff
diff --git a/tests/Support/RawEnv/RawEnvDirectWriteScanner.php b/tests/Support/RawEnv/RawEnvDirectWriteScanner.php
index 67d4a421..4ff6ae9b 100644
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
 
@@ -129,15 +131,33 @@ public static function scan(string $phpSource): array
     }
 
     /**
-     * ファイル全体の名前解決の文脈 (名前空間 / `use function` の対応表 / 解決不能の宣言)。
+     * 名前解決の文脈を**名前空間の領域ごとに**組み立てる。
+     *
+     * ★取り込み対応表はファイルに 1 つではなく**領域ごと**に持つ。ファイル全体で 1 つにすると、
+     *   同じ別名を別の完全修飾名へ向ける 2 つ目の名前空間が 1 つ目の対応表を上書きし、
+     *   1 つ目の `putenv` 別名呼び出しが候補から外れて**黙って見逃される** (fail-open)。
+     * ★`putenvAliasKeys` は**どこかの領域で `\putenv` を指した**別名の集合である
+     *   (上書きされて最終的に別の関数を指すものも残す)。解決不能なファイルで
+     *   「`putenv` 相当の出現」を数える母集団に使う — 最終的な対応表だけを見ると上と同じ穴が開く。
+     *   **`putenv` を 1 度も指さなかった別名は入れない** (入れると、無関係な別名関数の呼び出しまで
+     *   未解決として違反になる)。
+     * ★ただし `use function` の取り込み自体が解けなかったときは、どの別名が `putenv` を指したかが
+     *   分からない。そのときだけ `aliasKeys` (全別名) を母集団に使う (fail-closed)。
      *
      * @param  list<array{id: int|null, text: string, line: int}>  $tokens
-     * @return array{namespace: string, aliases: array<string, string>, unresolved: bool}
+     * @param  array<int, int>  $pairs
+     * @return array{
+     *     regions: list<array{start: int, end: int, namespace: string, aliases: array<string, string>}>,
+     *     putenvAliasKeys: array<string, true>,
+     *     aliasKeys: array<string, true>,
+     *     importParseFailed: bool,
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
@@ -153,36 +173,75 @@ private static function analyseFileContext(array $tokens): array
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
+        $importParseFailed = false;
+        $regions = [];
+        $aliasKeys = [];
+        $putenvAliasKeys = [];
 
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
+                    $importParseFailed = true;
+                }
+
+                // 上書きされる前に「`\putenv` を指した別名」を控える。
+                foreach ($aliases as $alias => $fullyQualified) {
+                    $aliasKeys[$alias] = true;
+
+                    if (strtolower($fullyQualified) === 'putenv') {
+                        $putenvAliasKeys[$alias] = true;
+                    }
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
@@ -201,7 +260,13 @@ private static function analyseFileContext(array $tokens): array
             }
         }
 
-        return ['namespace' => $namespace, 'aliases' => $aliases, 'unresolved' => $unresolved];
+        return [
+            'regions' => $regions,
+            'putenvAliasKeys' => $putenvAliasKeys,
+            'aliasKeys' => $aliasKeys,
+            'importParseFailed' => $importParseFailed,
+            'unresolved' => $unresolved,
+        ];
     }
 
     /**
@@ -297,7 +362,13 @@ private static function collectFunctionImports(array $statement, array &$aliases
      * 関数呼び出しの位置が `\putenv` を指すか (指せないなら未解決)。
      *
      * @param  list<array{id: int|null, text: string, line: int}>  $tokens
-     * @param  array{namespace: string, aliases: array<string, string>, unresolved: bool}  $context
+     * @param  array{
+     *     regions: list<array{start: int, end: int, namespace: string, aliases: array<string, string>}>,
+     *     putenvAliasKeys: array<string, true>,
+     *     aliasKeys: array<string, true>,
+     *     importParseFailed: bool,
+     *     unresolved: bool,
+     * }  $context
      */
     private static function classifyFunctionCall(array $tokens, array $context, int $index): ?RawEnvWriteKind
     {
@@ -319,17 +390,18 @@ private static function classifyFunctionCall(array $tokens, array $context, int
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
+        // 別名は「**どこかの領域で `\putenv` を指した**もの」で数える (最終的な対応表だけを見ると、
+        // 2 つ目の名前空間の取り込みが 1 つ目を隠して見逃しになる)。
+        // 取り込みそのものが解けなかったときだけ、全別名へ広げる (fail-closed)。
+        $aliasPopulation = $context['importParseFailed'] ? $context['aliasKeys'] : $context['putenvAliasKeys'];
+
+        $isCandidate = $lastSegment === 'putenv'
+            || ($token['id'] === T_STRING && isset($aliasPopulation[$lowered]));
 
         if (! $isCandidate) {
             return null;
@@ -339,23 +411,46 @@ private static function classifyFunctionCall(array $tokens, array $context, int
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
      *
-     * @param  array{namespace: string, aliases: array<string, string>, unresolved: bool}  $context
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
+     *
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
@@ -381,13 +476,10 @@ private static function classifySurface(
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
@@ -395,17 +487,14 @@ private static function classifySurface(
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
 
@@ -461,6 +550,67 @@ private static function classifySurface(
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
index 1c7ed708..09112810 100644
--- a/tests/Support/RawEnv/RawEnvGuardStructure.php
+++ b/tests/Support/RawEnv/RawEnvGuardStructure.php
@@ -5,6 +5,7 @@
 namespace Tests\Support\RawEnv;
 
 use InvalidArgumentException;
+use ReflectionClass;
 use ReflectionMethod;
 use RuntimeException;
 use Tests\Support\PhpTokenScan;
@@ -321,11 +322,23 @@ public static function staticCalls(array $tokens, string $method): array
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
+     * ★宣言元ファイルの取り込みを解けない場合は**例外**になる (`classImports()` を参照)。
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
 
@@ -334,9 +347,13 @@ public static function constructions(array $tokens, string $class): array
                 continue;
             }
 
-            $name = $tokens[$i + 1]['text'];
+            if (! self::isNamePart($tokens[$i + 1])) {
+                continue;
+            }
 
-            if ($name !== $class && ! str_ends_with($name, '\\'.$class)) {
+            $resolved = self::resolveClassName($resolver, $tokens[$i + 1]['text']);
+
+            if (strtolower($resolved) !== strtolower(ltrim($expected, '\\'))) {
                 continue;
             }
 
@@ -348,6 +365,263 @@ public static function constructions(array $tokens, string $class): array
         return $found;
     }
 
+    /**
+     * 宣言元ファイルのクラス取り込み表と名前空間 (完全修飾名の解決に使う)。
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
+        return [
+            'namespace' => $reflection->getNamespaceName(),
+            'imports' => self::classImports($source, $reflection->getNamespaceName()),
+        ];
+    }
+
+    /**
+     * ソースと名前空間から「短名 => 完全修飾名」の取り込み表を作る (純関数)。
+     *
+     * ★見るのは**その名前空間の領域のトップレベルの `use` だけ**である。
+     *   クラス本体の中の `use` (trait の取り込み) は波括弧の内側なので数えない —
+     *   数えると trait 名がクラスの短名を上書きし、別クラスを期待クラスと誤解決できる。
+     * ★**解決できない形は落とす (fail-closed)**。次のいずれも例外にする:
+     *   名前空間宣言が 2 つ以上ある / 波括弧つきの名前空間である /
+     *   宣言された名前空間が引数と食い違う / `use` の綴りを完全修飾名へ解けない
+     *   (group use の中に `function` / `const` が混ざる形を含む) /
+     *   同じ短名が別の完全修飾名へ 2 度取り込まれている。
+     *   **無言で読み飛ばさない** (読み飛ばすと未解決が「取り込み無し」と同じ値に混ざる)。
+     *
+     * @return array<string, string>
+     */
+    public static function classImports(string $phpSource, string $namespace): array
+    {
+        $tokens = PhpTokenScan::normalize($phpSource);
+        $count = count($tokens);
+        $declared = [];
+        $braced = false;
+
+        foreach ($tokens as $index => $token) {
+            if ($token['id'] !== T_NAMESPACE) {
+                continue;
+            }
+
+            $cursor = $index + 1;
+            $name = '';
+
+            while ($cursor < $count && self::isNamePart($tokens[$cursor])) {
+                $name .= $tokens[$cursor]['text'];
+                $cursor++;
+            }
+
+            if ($cursor < $count && $tokens[$cursor]['id'] === null && $tokens[$cursor]['text'] === '{') {
+                $braced = true;
+            }
+
+            $declared[] = trim($name, '\\');
+        }
+
+        if ($braced || count($declared) >= 2) {
+            throw new RuntimeException(
+                'class import resolution is not supported for files with braced or multiple namespaces (fail-closed).'
+            );
+        }
+
+        $current = $declared === [] ? '' : $declared[0];
+
+        if (strtolower($current) !== strtolower(trim($namespace, '\\'))) {
+            throw new RuntimeException(
+                "declared namespace [{$current}] does not match the expected namespace [{$namespace}]."
+            );
+        }
+
+        $imports = [];
+        $depth = 0;
+
+        for ($i = 0; $i < $count; $i++) {
+            if (self::isBraceOpen($tokens[$i])) {
+                $depth++;
+
+                continue;
+            }
+
+            if ($tokens[$i]['id'] === null && $tokens[$i]['text'] === '}') {
+                $depth--;
+
+                continue;
+            }
+
+            if ($depth !== 0 || $tokens[$i]['id'] !== T_USE || ! isset($tokens[$i + 1])) {
+                continue;
+            }
+
+            // `use function` / `use const` / 閉包の `use (...)` は取り込みではない。
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
+            if (! self::collectClassImports($statement, $imports)) {
+                throw new RuntimeException('unresolvable use statement in class source (fail-closed).');
+            }
+        }
+
+        return $imports;
+    }
+
+    /**
+     * `use …;` 1 文を短名 => 完全修飾名の対応表へ展開する (解けなければ false)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $statement
+     * @param  array<string, string>  $imports
+     */
+    private static function collectClassImports(array $statement, array &$imports): bool
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
+                    return false;
+                }
+
+                $name .= $nameToken['text'];
+            }
+
+            $fullyQualified = trim($prefix.$name, '\\');
+
+            if ($fullyQualified === '') {
+                return false;
+            }
+
+            $segments = explode('\\', $fullyQualified);
+            $alias ??= $segments[count($segments) - 1];
+            $key = strtolower($alias);
+
+            // 同じ短名が別の完全修飾名へ 2 度取り込まれている = 解決先が決まらない。
+            if (isset($imports[$key]) && strtolower($imports[$key]) !== strtolower($fullyQualified)) {
+                return false;
+            }
+
+            $imports[$key] = $fullyQualified;
+        }
+
+        return true;
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
@@ -568,6 +842,7 @@ public static function applyLoopIsGuarded(array $tokens, array $loopExpression,
      *  1. 復元のループの本体に `throw` / `return` / `break` / `continue` が 1 件も無い
      *  2. `$accumulator[] = …` がループ本体にちょうど 1 件ある
      *  3. その追加が `$flagVariable === false` の条件分岐の**本体**にある
+     *     (条件は綴りの列の**完全一致**で見る。包含だと結合していない条件を誤認する)
      *  4. ループの**後**の `$accumulator !== []` の条件分岐の本体に、**メソッド唯一の `throw`** がある
      *  5. その `throw` 以外に、メソッドを途中終了させるトークン (`return` / `throw`) が無い
      *
@@ -602,7 +877,7 @@ public static function restoreStructureIsDeferred(array $tokens, array $loopExpr
         $blocks = self::ifBlocks($tokens);
         $failureBranches = array_values(array_filter(
             $blocks,
-            fn (array $block): bool => self::conditionMatches($tokens, $block['condition'], $flagVariable, T_IS_IDENTICAL, 'false'),
+            fn (array $block): bool => self::conditionEquals($tokens, $block['condition'], [$flagVariable, '===', 'false']),
         ));
 
         if (count($failureBranches) !== 1 || ! self::isWithin($failureBranches[0]['body'], $appends[0])) {
@@ -618,7 +893,7 @@ public static function restoreStructureIsDeferred(array $tokens, array $loopExpr
 
         $reportBranches = array_values(array_filter(
             $blocks,
-            fn (array $block): bool => self::conditionMatches($tokens, $block['condition'], $accumulator, T_IS_NOT_IDENTICAL, '['),
+            fn (array $block): bool => self::conditionEquals($tokens, $block['condition'], [$accumulator, '!==', '[', ']']),
         ));
 
         if (count($reportBranches) !== 1 || ! self::isWithin($reportBranches[0]['body'], $throws[0])) {
@@ -696,11 +971,18 @@ public static function soleThrowMatches(array $tokens, array $blockRange, array
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
@@ -710,32 +992,26 @@ public static function constructionArgumentMatches(array $tokens, string $class,
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
index e28a798b..1344f9eb 100644
--- a/tests/Unit/Architecture/RawEnvDirectWriteScannerTest.php
+++ b/tests/Unit/Architecture/RawEnvDirectWriteScannerTest.php
@@ -228,6 +228,60 @@ function rawEnvScannerKinds(string $source): array
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
+test('負例 10: putenv を 1 度も指さない別名は、名前空間をまたいでも候補にしない', function (): void {
+    // ★`unresolved` が立つファイルで**全別名**を候補にすると、無関係な別名関数の呼び出しまで
+    //   違反になる。候補にするのは「どこかの領域で putenv を指した別名」だけである。
+    $source = <<<'PHP'
+    <?php
+    namespace A;
+
+    use function Acme\noop as helper;
+
+    helper();
+
+    namespace B;
+    PHP;
+
+    expect(rawEnvScannerKinds($source))->toBe([]);
+});
+
 // ── 解決できない形は落とす (fail-closed) ──
 
 test('未解決 1: 自前で putenv を宣言したファイルの非修飾呼び出しは unresolved', function (): void {
@@ -265,8 +319,27 @@ function putenv(string $assignment): bool
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
index e410bef0..11a54af5 100644
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
@@ -414,6 +476,70 @@ public function run(): void
         ->toThrow(RuntimeException::class);
 });
 
+test('正例 6: 名前空間のトップレベルの取り込みだけを解く', function (): void {
+    $imports = RawEnvGuardStructure::classImports(<<<'PHP'
+    <?php
+
+    namespace Tests\Probe;
+
+    use RuntimeException;
+    use Vendor\Thing as Alias;
+    use function Vendor\helper;
+    use const Vendor\LIMIT;
+
+    class Probe
+    {
+        use SomeTrait;
+
+        public function run(): void
+        {
+            $f = function () use ($x): void {};
+        }
+    }
+    PHP, 'Tests\Probe');
+
+    // trait の取り込み (`use SomeTrait;`) はクラス本体の中なので数えない。
+    expect($imports)->toBe([
+        'runtimeexception' => 'RuntimeException',
+        'alias' => 'Vendor\Thing',
+    ]);
+});
+
+test('fail-closed 7: 取り込みを解けない形はすべて例外になる', function (string $source, string $namespace): void {
+    expect(fn (): array => RawEnvGuardStructure::classImports($source, $namespace))
+        ->toThrow(RuntimeException::class);
+})->with([
+    'two namespaces' => [<<<'PHP'
+    <?php
+    namespace A;
+    use Vendor\RuntimeException;
+    namespace B;
+    use RuntimeException;
+    PHP, 'A'],
+    'braced namespace' => [<<<'PHP'
+    <?php
+    namespace A {
+        use RuntimeException;
+    }
+    PHP, 'A'],
+    'namespace mismatch' => [<<<'PHP'
+    <?php
+    namespace A;
+    use RuntimeException;
+    PHP, 'B'],
+    'group use with a function entry' => [<<<'PHP'
+    <?php
+    namespace A;
+    use Vendor\{Thing, function helper};
+    PHP, 'A'],
+    'same short name imported twice' => [<<<'PHP'
+    <?php
+    namespace A;
+    use Vendor\RuntimeException;
+    use Other\RuntimeException;
+    PHP, 'A'],
+]);
+
 // ── 母集団 ──
 
 test('母集団: foreach が 1 件も無い入力は例外にせず空を返す', function (): void {

```

## 検証結果

- `tests/Unit/Architecture/RawEnvGuardStructureTest.php`: 30 passed (正例 1 本・fail-closed 5 データセット追加)
- `tests/Unit/Architecture/RawEnvDirectWriteScannerTest.php`: 40 passed (負例 1 本追加)
- `tests/Architecture/RawEnvDirectWriteGateTest.php`: 13 passed
- `tests/Unit/Support/RawEnv/RawEnvSnapshotContractTest.php`: 34 passed
- `composer phpstan` (level 10): No errors
- `vendor/bin/pint --test`: passed
- `composer test` (全数): 7834 tests。**1 件だけ落ちたが本差分と無関係な既存の不安定テスト**である
  (`tests/Architecture/BughuntSelfTestExecutionTest.php`。bug-hunt harness が別プロセスの
   プロセスグループを刈り取る自己検査で、失敗メッセージは
   `echo: write error: Broken pipe` と `pid=... は存在するが所有確認できない — kill せず pidfile 保持`。
   同一コンテナで他プロセスが走っていると PID の所有確認が揺れる)。
  単体で走らせると緑 (3 passed)。直前の全数実行 (同じ変更の 1 つ前の状態) では
  この検査を含めて全数緑 (7827 passed) だったので、本差分が原因ではないと判断している。
  最終確認としてもう一度全数を走らせている。

## 確認してほしいこと

1. `classImports()` の fail-closed が**未解決を解決済みと同じ値へ混ぜていない**か。
   とくに「深さ 0 の `use` だけを見る」判定が、想定外の形 (`if` の中の `use`、
   波括弧つき名前空間) を無言で読み飛ばしていないか。
2. `putenvAliasKeys` / `aliasKeys` の使い分けに、まだ fail-open が残っていないか。
3. 他に Critical / Warning が残っていないか。

全体判定を **APPROVED** または **CHANGES_REQUESTED** の 1 語で書くこと。
