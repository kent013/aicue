# Round 9: Round 8 の指摘への対応

Round 8 の Critical 1 件を捌きました。

## 対応マトリクス

# 対応マトリクス: impl-review Round 8

## [Critical] 複数 namespace の取り込み表が混ざり、後続 namespace から上書きできる

- 判断: **対応する (示された 2 案のうち「複数 namespace を未対応として fail-closed で落とす」を選んだ)**
- 根拠: 指摘のとおり、両 gate の取り込み表はファイル全体で 1 つであり、
  後続の名前空間の同名の別名で上書きできる。
  一方、本リポジトリは PSR-4 で **1 ファイル 1 名前空間**であり、複数名前空間のファイルは
  1 件も無い。区間ごとに表を持ち分ける機構を先回りして作るのは
  AGENTS.md 思考原則 2 (今必要なものだけ作る) に反する。
  「解決できない形は落とす」(走査規約 (b)) 側で閉じるのが本件では正しい。
- 対応内容:
  - 静的 gate: `cachePayloadNamespaceDeclarationCount()` を新設し、
    宣言が 2 つ以上なら**未分類**として落とす (検査 1 が赤くなる)
  - 結線 gate: 同じ判定を `cacheGuardCachedStateTraitUses()` に入れ、
    `UNRESOLVED_NAMESPACES(...)` を返して W4 を落とす
  - 負例を収集関数へ通す形で追加した — 静的 gate はセミコロン形と波括弧形の 2 形
    (どちらも指摘の合成入力そのもの) + 正例 (単一名前空間は落とさない)、
    結線 gate は別名を上書きする形 1 件
  - 両 gate の冒頭 docblock の「保証しないもの」へ
    「1 ファイルに複数の名前空間がある形は解決せず落とす」と明記した


## 修正後の差分 (Round 8 時点から)

```diff
diff --git a/tests/Architecture/CacheGuardWiringGateTest.php b/tests/Architecture/CacheGuardWiringGateTest.php
new file mode 100644
index 00000000..a55cd147
--- /dev/null
+++ b/tests/Architecture/CacheGuardWiringGateTest.php
@@ -0,0 +1,1501 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Foundation\Testing\TestCase as VendorTestCase;
+use Tests\Support\Cache\IsolatedApplicationProbe;
+use Tests\TestCase;
+
+/*
+ * Architecture invariant: **キャッシュ素データ規約の実行時層が、アプリ起動の前に結線され、
+ * 全レーンで後始末されている**こと (家系の裁定 AG-151 = 正典 v2 の要素 2)。
+ *
+ * 実行時層そのもの (値の検査・境界迂回の hard fail) の振る舞いは
+ * tests/Feature/Cache/CachePayloadPlainDataGuardTest.php が固定する。
+ * 本 gate が固定するのは**結線**である — 結線が beforeEach へ後退したり、
+ * どこかのレーンから flush が抜けたりすると、検査は緑のまま**検出だけが消える**。
+ *
+ * ★この gate が保証するもの:
+ *   - W1: Tests\TestCase::createApplication() が bootstrap() より**前**に
+ *     PlainDataCacheGuard::registerBeforeBootstrap() を呼ぶ。
+ *     判定は**メソッド本体**の token 位置で行う (ファイル全体を見る形だと
+ *     「別メソッドで結線し別メソッドで bootstrap」を正常扱いする)
+ *   - W2/W3: tests/Pest.php の**期待するレーン集合ちょうど** ({Feature, Unit} / {Architecture} /
+ *     {Browser}) について、`assertInstalled` が **beforeEach のクロージャの中**、
+ *     `flushAndFailIfStray` が **afterEach の try ブロックの中**、
+ *     `reset` が **afterEach の finally ブロックの中**にある。
+ *     いずれも**対応する波括弧を解決した範囲の直下の文**であることまで見る
+ *     (位置の前後比較ではクロージャやブロックの外へ出した形を素通しし、
+ *      範囲の内側かどうかだけでは条件分岐の中へ入れて実行させない形を素通しする)。
+ *     try と finally が**同じ try 文に属する**ことも確認する
+ *   - W4: WithCachedConfig / WithCachedRoutes を**クラス本体の `use` 文**または
+ *     **字句として書かれた `uses(...)`** で適用しているテストが 0 件である
+ *     (使い始めると override が vendor と食い違う前提が崩れる)。
+ *     短名・別名・完全修飾名・カンマ区切り・グループ use を取り込み表で解決して突き合わせ、
+ *     `uses()` の引数に静的に解決できない値があれば未解決として落とす。
+ *     **主張はこの 2 形に限る** — 下の「保証しないもの」を参照
+ *   - W5: vendor の Illuminate\Foundation\Testing\TestCase::createApplication() の
+ *     正規化 token 列が期待値と**完全一致**する (Laravel 更新で写しが静かに古くならない)
+ *   - W5b: ローカルの写しが「vendor 期待列 + 許可差分 3 つ」と**完全一致**する。
+ *     許可差分は (1) 戻り値の fail-closed 確認 (2) 結線 1 行 (3) 戻り値型と #[\Override] だけ
+ *   - W6: 起動中の負例 (IsolatedApplicationProbe::run) が **同じ関数**を bootstrap より前に呼ぶ
+ *   - W7: 空振り検知 (走査ファイルが実在 / token 数が 0 でない / 許可差分がすべて位置ごと一致 /
+ *     検出器が合成入力の負例に反応する)
+ *   - W8: 負のコントロール (flush が無い / flush が try の外 / reset が finally の外 /
+ *     try-finally の形でない / assertInstalled が beforeEach の外 / bootstrap の後で結線 /
+ *     結線が無い / 結線が別メソッドにある / レーン集合違い /
+ *     vendor 本体の token 増減・順序入れ替え / ローカルから既知の文を削除)。
+ *     **いずれも本 gate が実際に使う判定関数へ通す**
+ *     (加工した配列を素の比較で確かめるだけだと、判定側が壊れても負例が緑のままになる)
+ *
+ * ★この gate が保証しないもの (誇張しない):
+ *   - vendor 側の `setUp()` / `refreshApplication()` の変更や bootstrapper の増減は見ない。
+ *     見るのは `createApplication()` の**本体だけ**である
+ *   - tests/Pest.php の**実行時の**挙動は見ない (字句として書かれていることだけを見る)。
+ *     実際に flush が発火することは CachePayloadPlainDataGuardTest の負例が示す
+ *   - レーンを新設したことは W2/W3 のレーン集合 exact-fit で赤くなるが、
+ *     phpunit.xml の testsuite 構成そのものは見ない
+ *   - **W4 の主張は「クラス本体の `use` 文」と「字句として書かれた `uses(...)`」の 2 形に限る**。
+ *     関数名ごと動的にする形 (`call_user_func('uses', …)` / 変数関数) には沈黙するので、
+ *     「対象 trait を適用する経路が 1 つも無い」とは読めない。
+ *     `uses()` と**書いた**うえで引数を変数にした形は未解決として落とす
+ *
+ * 解析は PhpToken::tokenize (コメント・文字列リテラルは code token ではないので拾わない)。
+ * regex にすると**この説明コメント自身**で偽赤になる。
+ */
+
+/**
+ * vendor の `Illuminate\Foundation\Testing\TestCase::createApplication()` の正規化 token 列。
+ * Laravel 更新で 1 token でも変わったら W5 が赤くなる。**それが目的**である。
+ *
+ * @var list<string>
+ */
+const CACHE_GUARD_VENDOR_CREATE_APPLICATION_TOKENS = [
+    'public', 'function', 'createApplication', '(', ')', '{', '$app', '=', 'require', 'Application',
+    '::', 'inferBasePath', '(', ')', '.', '\'/bootstrap/app.php\'', ';', '$this', '->', 'traitsUsedByTest',
+    '=', 'class_uses_recursive', '(', 'static', '::', 'class', ')', ';', 'if', '(',
+    'isset', '(', 'CachedState', '::', '$cachedConfig', ',', '$this', '->', 'traitsUsedByTest', '[',
+    'WithCachedConfig', '::', 'class', ']', ')', ')', '{', '$this', '->', 'markConfigCached',
+    '(', '$app', ')', ';', '}', 'if', '(', 'isset', '(', 'CachedState',
+    '::', '$cachedRoutes', ',', '$this', '->', 'traitsUsedByTest', '[', 'WithCachedRoutes', '::', 'class',
+    ']', ')', ')', '{', '$app', '->', 'booting', '(', 'fn', '(',
+    ')', '=>', '$this', '->', 'markRoutesCached', '(', '$app', ')', ')', ';',
+    '}', '$app', '->', 'make', '(', 'Kernel', '::', 'class', ')', '->',
+    'bootstrap', '(', ')', ';', 'return', '$app', ';', '}',
+];
+
+/**
+ * ローカルの `Tests\TestCase::createApplication()` の正規化 token 列。
+ *
+ * ★W5 は vendor 側の変更しか見ず、W1 は「結線が bootstrap より前にある」ことしか見ない。
+ *   その 2 つだけだと、ローカルの写しから `$this->traitsUsedByTest` の代入・cached config 分岐・
+ *   cached routes 分岐・`return $app` を消しても**両方とも緑のまま**になる。
+ *
+ * @var list<string>
+ */
+const CACHE_GUARD_LOCAL_CREATE_APPLICATION_TOKENS = [
+    'public', 'function', 'createApplication', '(', ')', ':', 'Application', '{', '$app', '=',
+    'require', 'Application', '::', 'inferBasePath', '(', ')', '.', '\'/bootstrap/app.php\'', ';', 'if',
+    '(', '!', '$app', 'instanceof', 'Application', ')', '{', 'throw', 'new', 'RuntimeException',
+    '(', '\'bootstrap/app.php が Application を返しませんでした\'', ')', ';', '}', 'PlainDataCacheGuard', '::', 'registerBeforeBootstrap', '(', '$app',
+    ')', ';', '$this', '->', 'traitsUsedByTest', '=', 'class_uses_recursive', '(', 'static', '::',
+    'class', ')', ';', 'if', '(', 'isset', '(', 'CachedState', '::', '$cachedConfig',
+    ',', '$this', '->', 'traitsUsedByTest', '[', 'WithCachedConfig', '::', 'class', ']', ')',
+    ')', '{', '$this', '->', 'markConfigCached', '(', '$app', ')', ';', '}',
+    'if', '(', 'isset', '(', 'CachedState', '::', '$cachedRoutes', ',', '$this', '->',
+    'traitsUsedByTest', '[', 'WithCachedRoutes', '::', 'class', ']', ')', ')', '{', '$app',
+    '->', 'booting', '(', 'fn', '(', ')', '=>', '$this', '->', 'markRoutesCached',
+    '(', '$app', ')', ')', ';', '}', '$app', '->', 'make', '(',
+    'Kernel', '::', 'class', ')', '->', 'bootstrap', '(', ')', ';', 'return',
+    '$app', ';', '}',
+];
+
+/**
+ * ローカルの写しに足してよい差分 (offset は**ローカル列の index**、tokens は挿入された列)。
+ *
+ * ここから挿入を取り除くと vendor 期待列に**完全一致**しなければならない。
+ * 部分列の除去だけだと別の位置に同じ列を置いても通るため、**位置まで固定する**。
+ *
+ * @var list<array{reason: string, offset: int, tokens: list<string>}>
+ */
+const CACHE_GUARD_LOCAL_ALLOWED_INSERTIONS = [
+    [
+        'reason' => '戻り値型の宣言 (vendor は docblock だけなので狭めていない)',
+        'offset' => 5,
+        'tokens' => [':', 'Application'],
+    ],
+    [
+        'reason' => '戻り値の fail-closed 確認と、bootstrap 直前の結線 1 行',
+        'offset' => 19,
+        'tokens' => [
+            'if', '(', '!', '$app', 'instanceof', 'Application', ')', '{', 'throw', 'new',
+            'RuntimeException', '(', '\'bootstrap/app.php が Application を返しませんでした\'', ')', ';', '}',
+            'PlainDataCacheGuard', '::', 'registerBeforeBootstrap', '(', '$app', ')', ';',
+        ],
+    ],
+];
+
+/**
+ * tests/Pest.php で期待するレーン集合 (`->in(...)` の引数集合)。
+ *
+ * @var list<list<string>>
+ */
+const CACHE_GUARD_EXPECTED_LANES = [
+    ['Architecture'],
+    ['Browser'],
+    ['Feature', 'Unit'],
+];
+
+/**
+ * 使い始めたら override の前提が崩れる vendor の trait (完全修飾名)。
+ *
+ * @var list<string>
+ */
+const CACHE_GUARD_CACHED_STATE_TRAITS = [
+    'Illuminate\Foundation\Testing\WithCachedConfig',
+    'Illuminate\Foundation\Testing\WithCachedRoutes',
+];
+
+/**
+ * 空白・コメント・開始タグを落とした token の文字列列。
+ *
+ * @return list<string>
+ */
+function cacheGuardNormalizedTokens(string $source): array
+{
+    /** @var list<PhpToken> $tokens */
+    $tokens = PhpToken::tokenize($source);
+
+    return array_values(array_map(
+        static fn (PhpToken $token): string => $token->text,
+        array_filter(
+            $tokens,
+            static fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG]),
+        ),
+    ));
+}
+
+/**
+ * メソッド本体の正規化 token 列を反射で取り出す (fail-closed)。
+ *
+ * @return list<string>
+ */
+function cacheGuardMethodTokens(string $class, string $method): array
+{
+    $reflection = new ReflectionMethod($class, $method);
+
+    $file = $reflection->getFileName();
+    $start = $reflection->getStartLine();
+    $end = $reflection->getEndLine();
+    if ($file === false || $start === false || $end === false) {
+        throw new RuntimeException("{$class}::{$method}() の定義位置を解決できません (内部関数か eval)");
+    }
+
+    $lines = file($file);
+    if ($lines === false) {
+        throw new RuntimeException("{$file} を読めません");
+    }
+
+    return cacheGuardNormalizedTokens(
+        '<?php '.implode('', array_slice($lines, $start - 1, $end - $start + 1))
+    );
+}
+
+/**
+ * 合成入力から 1 メソッドの本体 token 列を切り出す (負例を「メソッド抽出 + 順序判定」の
+ * 組で通すために要る。反射は実在クラスにしか使えない)。
+ *
+ * @return list<string> 見つからなければ空
+ */
+function cacheGuardMethodTokensFromSource(string $source, string $method): array
+{
+    $tokens = cacheGuardNormalizedTokens($source);
+
+    $signature = cacheGuardSequencePosition($tokens, ['function', $method, '(']);
+    if ($signature === null) {
+        return [];
+    }
+
+    $open = null;
+    for ($i = $signature; $i < count($tokens); $i++) {
+        if ($tokens[$i] === '{') {
+            $open = $i;
+            break;
+        }
+    }
+    if ($open === null) {
+        return [];
+    }
+
+    $close = cacheGuardMatchingBrace($tokens, $open);
+    if ($close === null) {
+        return [];
+    }
+
+    return array_slice($tokens, $signature, $close - $signature + 1);
+}
+
+/**
+ * `{` の対応する `}` の index。
+ *
+ * @param  list<string>  $tokens
+ */
+function cacheGuardMatchingBrace(array $tokens, int $open): ?int
+{
+    $depth = 0;
+    $count = count($tokens);
+    for ($i = $open; $i < $count; $i++) {
+        if ($tokens[$i] === '{') {
+            $depth++;
+        } elseif ($tokens[$i] === '}') {
+            $depth--;
+            if ($depth === 0) {
+                return $i;
+            }
+        }
+    }
+
+    return null;
+}
+
+/**
+ * token 列 $needle が最初に現れる位置。無ければ null。
+ *
+ * @param  list<string>  $tokens
+ * @param  list<string>  $needle
+ */
+function cacheGuardSequencePosition(array $tokens, array $needle, int $from = 0): ?int
+{
+    $limit = count($tokens) - count($needle);
+    for ($i = $from; $i <= $limit; $i++) {
+        if (array_slice($tokens, $i, count($needle)) === $needle) {
+            return $i;
+        }
+    }
+
+    return null;
+}
+
+/**
+ * `->name(` に続くクロージャ / ブロックの `{ … }` の範囲 (両端の index)。
+ *
+ * @param  list<string>  $tokens
+ * @return array{int, int}|null
+ */
+function cacheGuardBlockRange(array $tokens, array $needle, int $from = 0): ?array
+{
+    $position = cacheGuardSequencePosition($tokens, $needle, $from);
+    if ($position === null) {
+        return null;
+    }
+
+    $count = count($tokens);
+    for ($i = $position; $i < $count; $i++) {
+        if ($tokens[$i] === '{') {
+            $close = cacheGuardMatchingBrace($tokens, $i);
+
+            return $close === null ? null : [$i, $close];
+        }
+    }
+
+    return null;
+}
+
+/**
+ * `$from` 以降で**最初に現れる try 文**を解析し、**それ自身が finally を持つ場合だけ**返す。
+ *
+ * `try { … } catch (…) { … } finally { … }` の catch 群を読み飛ばし、
+ * 直後が `finally {` である場合だけ組にして返す。最初の try が finally を持たなければ
+ * その場で null を返す (後続の別の try-finally を借りてこないため = fail-closed)。
+ *
+ * @param  list<string>  $tokens
+ * @return array{try: array{int, int}, finally: array{int, int}}|null
+ */
+function cacheGuardTryStatement(array $tokens, int $from): ?array
+{
+    $count = count($tokens);
+    for ($i = $from; $i < $count; $i++) {
+        if ($tokens[$i] !== 'try') {
+            continue;
+        }
+        $tryOpen = $i + 1;
+        if (($tokens[$tryOpen] ?? '') !== '{') {
+            continue;
+        }
+        $tryClose = cacheGuardMatchingBrace($tokens, $tryOpen);
+        if ($tryClose === null) {
+            return null;
+        }
+
+        $cursor = $tryClose + 1;
+        while (($tokens[$cursor] ?? '') === 'catch') {
+            $parenOpen = $cursor + 1;
+            if (($tokens[$parenOpen] ?? '') !== '(') {
+                return null;
+            }
+            $depth = 0;
+            $parenClose = null;
+            for ($j = $parenOpen; $j < $count; $j++) {
+                if ($tokens[$j] === '(') {
+                    $depth++;
+                } elseif ($tokens[$j] === ')') {
+                    $depth--;
+                    if ($depth === 0) {
+                        $parenClose = $j;
+                        break;
+                    }
+                }
+            }
+            if ($parenClose === null || ($tokens[$parenClose + 1] ?? '') !== '{') {
+                return null;
+            }
+            $catchClose = cacheGuardMatchingBrace($tokens, $parenClose + 1);
+            if ($catchClose === null) {
+                return null;
+            }
+            $cursor = $catchClose + 1;
+        }
+
+        if (($tokens[$cursor] ?? '') !== 'finally' || ($tokens[$cursor + 1] ?? '') !== '{') {
+            return null; // この try 文は finally を持たない
+        }
+        $finallyClose = cacheGuardMatchingBrace($tokens, $cursor + 1);
+        if ($finallyClose === null) {
+            return null;
+        }
+
+        return ['try' => [$tryOpen, $tryClose], 'finally' => [$cursor + 1, $finallyClose]];
+    }
+
+    return null;
+}
+
+/**
+ * 位置が範囲の**直下の文の先頭**か。
+ *
+ * 2 つを同時に満たすことを要求する。
+ *  (1) 範囲の `{` から数えて入れ子の波括弧の中に無い (深さ 0)
+ *  (2) 直前の token が**文の境界** (`{` / `}` / `;`) である
+ *
+ * ★(2) が無いと、波括弧を使わない制御構文 (`if (false) flush();`)・代替構文
+ *   (`if (false): flush(); endif;`)・三項演算子・短絡評価の右辺がすべて深さ 0 で通ってしまう。
+ *   どれも「無条件に実行される」ことを保証しない置き方である。
+ *
+ * @param  list<string>  $tokens
+ * @param  array{int, int}|null  $range
+ */
+function cacheGuardIsDirectStatement(array $tokens, ?int $position, ?array $range): bool
+{
+    if (! cacheGuardIsInside($position, $range)) {
+        return false;
+    }
+    /** @var array{int, int} $range */
+    /** @var int $position */
+    $depth = 0;
+    for ($i = $range[0] + 1; $i < $position; $i++) {
+        if ($tokens[$i] === '{') {
+            $depth++;
+        } elseif ($tokens[$i] === '}') {
+            $depth--;
+        }
+    }
+    if ($depth !== 0) {
+        return false;
+    }
+
+    return in_array($tokens[$position - 1] ?? '', ['{', '}', ';'], true);
+}
+
+/**
+ * `Guard::method(...)` が範囲の**直下の独立した式文**として置かれているか。
+ *
+ * 文の先頭であること (`cacheGuardIsDirectStatement`) に加えて、引数リストの閉じ括弧の
+ * 直後が `;` であることまで見る (代入・連鎖・条件式の一部として書かれた形を除く)。
+ *
+ * @param  list<string>  $tokens
+ * @param  array{int, int}|null  $range
+ */
+function cacheGuardIsStandaloneCall(array $tokens, ?int $position, ?array $range): bool
+{
+    if (! cacheGuardIsDirectStatement($tokens, $position, $range)) {
+        return false;
+    }
+    /** @var int $position */
+    $open = $position + 3; // Guard :: method ( の `(`
+    if (($tokens[$open] ?? '') !== '(') {
+        return false;
+    }
+
+    $depth = 0;
+    $count = count($tokens);
+    for ($i = $open; $i < $count; $i++) {
+        if ($tokens[$i] === '(') {
+            $depth++;
+        } elseif ($tokens[$i] === ')') {
+            $depth--;
+            if ($depth === 0) {
+                return ($tokens[$i + 1] ?? '') === ';';
+            }
+        }
+    }
+
+    return false;
+}
+
+/** 位置が範囲の**内側**にあるか。 */
+function cacheGuardIsInside(?int $position, ?array $range): bool
+{
+    return $position !== null && $range !== null && $position > $range[0] && $position < $range[1];
+}
+
+/**
+ * 「結線が bootstrap より**前**にある」ことの違反理由 (純関数。合成入力にも当てられる)。
+ *
+ * ★引数は**メソッド本体の token 列**である。ファイル全体を渡すと「別のメソッドで結線し、
+ *   別のメソッドで bootstrap する」形を正常扱いしてしまう。
+ *
+ * @param  list<string>  $tokens
+ * @return list<string>
+ */
+function cacheGuardBootstrapOrderViolations(array $tokens, string $label): array
+{
+    $wiring = cacheGuardSequencePosition($tokens, ['PlainDataCacheGuard', '::', 'registerBeforeBootstrap', '(']);
+    $bootstrap = cacheGuardSequencePosition($tokens, ['->', 'bootstrap', '(', ')']);
+
+    $violations = [];
+    if ($wiring === null) {
+        $violations[] = "{$label}: PlainDataCacheGuard::registerBeforeBootstrap() の呼び出しがありません";
+    }
+    if ($bootstrap === null) {
+        $violations[] = "{$label}: ->bootstrap() の呼び出しがありません (走査対象を取り違えている)";
+    }
+    if ($wiring !== null && $bootstrap !== null && $wiring > $bootstrap) {
+        $violations[] = "{$label}: 結線が bootstrap() より後にあります (起動中の書き込みを見逃す)";
+    }
+
+    return $violations;
+}
+
+/**
+ * tests/Pest.php を `pest()->extend(TestCase::class)` 単位のレーンブロックへ割る。
+ *
+ * @return list<array{lanes: list<string>, tokens: list<string>}>
+ */
+function cacheGuardLaneBlocks(string $source): array
+{
+    $tokens = cacheGuardNormalizedTokens($source);
+    $starts = [];
+    $from = 0;
+    while (($position = cacheGuardSequencePosition($tokens, ['pest', '(', ')', '->', 'extend'], $from)) !== null) {
+        $starts[] = $position;
+        $from = $position + 1;
+    }
+
+    $blocks = [];
+    foreach ($starts as $index => $start) {
+        $end = $starts[$index + 1] ?? count($tokens);
+        $block = array_slice($tokens, $start, $end - $start);
+
+        $lanes = [];
+        $inPosition = cacheGuardSequencePosition($block, ['->', 'in', '(']);
+        if ($inPosition !== null) {
+            for ($i = $inPosition + 3; $i < count($block); $i++) {
+                if ($block[$i] === ')') {
+                    break;
+                }
+                if ($block[$i] === ',') {
+                    continue;
+                }
+                $lanes[] = trim($block[$i], "'\"");
+            }
+        }
+        sort($lanes);
+
+        $blocks[] = ['lanes' => $lanes, 'tokens' => $block];
+    }
+
+    return $blocks;
+}
+
+/**
+ * 1 レーンブロックの結線と後始末の違反理由 (純関数。合成入力にも当てられる)。
+ *
+ * ★**対応する波括弧を解決した範囲**で判定する。位置の前後比較だけだと、
+ *   クロージャや try / finally の外へ出した形を素通しする。
+ *
+ * @param  list<string>  $block
+ * @return list<string>
+ */
+function cacheGuardLaneWiringViolations(array $block, string $label): array
+{
+    $violations = [];
+
+    $beforeEach = cacheGuardBlockRange($block, ['->', 'beforeEach', '(']);
+    $afterEach = cacheGuardBlockRange($block, ['->', 'afterEach', '(']);
+    if ($beforeEach === null) {
+        $violations[] = "{$label}: beforeEach のクロージャを解決できません";
+    }
+    if ($afterEach === null) {
+        $violations[] = "{$label}: afterEach のクロージャを解決できません";
+
+        return $violations;
+    }
+
+    $assertInstalled = cacheGuardSequencePosition($block, ['PlainDataCacheGuard', '::', 'assertInstalled', '(']);
+    if (! cacheGuardIsStandaloneCall($block, $assertInstalled, $beforeEach)) {
+        $violations[] = "{$label}: beforeEach のクロージャの**直下**で PlainDataCacheGuard::assertInstalled() を呼んでいません"
+            .' (条件分岐の中に入れると実行されない場合がある)';
+    }
+
+    // ★try と finally が**同じ try 文に属する**ことまで確認する。独立に探すと、
+    //   「flush を持つ try (finally 無し)」と「reset を持つ別の try-finally」が
+    //   別々にある形を通してしまい、flush が投げたときに reset へ到達しない。
+    $statement = cacheGuardTryStatement($block, $afterEach[0]);
+    // ★try 文そのものが afterEach クロージャの**直下**にあること。条件分岐の中へ入れると
+    //   範囲としては内側でも 1 度も実行されない。
+    // ★判定するのは `try` **キーワード**の位置である (`{` の位置だと直前が常に `try` になる)。
+    if ($statement === null || ! cacheGuardIsDirectStatement($block, $statement['try'][0] - 1, $afterEach)) {
+        $violations[] = "{$label}: afterEach の直下が try … finally の形になっていません"
+            .' (同じ try 文の finally が要る / 条件分岐の中へ入れない)';
+
+        return $violations;
+    }
+    $try = $statement['try'];
+    $finally = $statement['finally'];
+
+    $flush = cacheGuardSequencePosition($block, ['PlainDataCacheGuard', '::', 'flushAndFailIfStray', '(']);
+    if (! cacheGuardIsStandaloneCall($block, $flush, $try)) {
+        $violations[] = "{$label}: afterEach の try ブロックの直下で PlainDataCacheGuard::flushAndFailIfStray() を呼んでいません";
+    }
+
+    // ★flush が throw しても次テストへ accumulator を漏らさないために、reset は
+    //   **finally ブロックの直下**でなければならない。
+    $reset = cacheGuardSequencePosition($block, ['PlainDataCacheGuard', '::', 'reset', '(']);
+    if (! cacheGuardIsStandaloneCall($block, $reset, $finally)) {
+        $violations[] = "{$label}: afterEach の finally ブロックの直下で PlainDataCacheGuard::reset() を呼んでいません";
+    }
+
+    return $violations;
+}
+
+/**
+ * 期待 token 列との完全一致を判定する (負例をこの関数に通すため純関数にしてある)。
+ *
+ * @param  list<string>  $actual
+ * @param  list<string>  $expected
+ * @return list<string>
+ */
+function cacheGuardTokenListViolations(array $actual, array $expected, string $label): array
+{
+    if ($actual === $expected) {
+        return [];
+    }
+
+    return ["{$label}: token 列が期待値と一致しません (実測 "
+        .count($actual).' token / 期待 '.count($expected).' token)'];
+}
+
+/**
+ * ローカルの写しが「vendor 期待列 + 許可差分」であることの違反理由 (純関数)。
+ *
+ * @param  list<string>  $local
+ * @return list<string>
+ */
+function cacheGuardLocalCopyViolations(array $local): array
+{
+    $violations = cacheGuardTokenListViolations(
+        $local,
+        CACHE_GUARD_LOCAL_CREATE_APPLICATION_TOKENS,
+        'ローカルの写し',
+    );
+
+    $stripped = $local;
+    foreach (array_reverse(CACHE_GUARD_LOCAL_ALLOWED_INSERTIONS) as $insertion) {
+        if (array_slice($local, $insertion['offset'], count($insertion['tokens'])) !== $insertion['tokens']) {
+            $violations[] = "許可差分「{$insertion['reason']}」が期待位置にありません";
+
+            continue;
+        }
+        array_splice($stripped, $insertion['offset'], count($insertion['tokens']));
+    }
+
+    return array_merge($violations, cacheGuardTokenListViolations(
+        $stripped,
+        CACHE_GUARD_VENDOR_CREATE_APPLICATION_TOKENS,
+        '許可差分を除いたローカルの写し',
+    ));
+}
+
+/**
+ * 名前空間の**本体の波括弧の深さ**。`namespace A;` なら 0、`namespace A { … }` なら 1。
+ *
+ * @param  list<PhpToken>  $tokens
+ */
+function cacheGuardNamespaceBodyDepth(array $tokens): int
+{
+    $count = count($tokens);
+    for ($i = 0; $i < $count; $i++) {
+        if (! $tokens[$i]->is(T_NAMESPACE)) {
+            continue;
+        }
+        for ($j = $i + 1; $j < $count; $j++) {
+            if ($tokens[$j]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR])) {
+                continue;
+            }
+
+            return $tokens[$j]->text === '{' ? 1 : 0;
+        }
+    }
+
+    return 0;
+}
+
+/**
+ * `use A\B\C;` / `use A\B\C as D;` / `use A\B\{C, D as E};` から alias => FQCN の表を作る。
+ *
+ * ★読むのは**名前空間スコープの取り込みだけ**である (波括弧の深さで判定する)。
+ *   型宣言の本体に入った後の `use` は trait の取り込みで、混ぜると
+ *   `use WithCachedConfig;` が自分自身へ解決して短名の負例が黙る。
+ *   **「最初の型宣言で打ち切る」形は誤り**である — PHP は型宣言の**後ろ**にも
+ *   名前空間スコープの取り込みを置けるため、後置の別名を丸ごと落としてしまう。
+ *
+ * @param  list<PhpToken>  $tokens
+ * @return array<string, string>
+ */
+function cacheGuardUseMap(array $tokens): array
+{
+    $map = [];
+    $count = count($tokens);
+    $baseDepth = cacheGuardNamespaceBodyDepth($tokens);
+    $depth = 0;
+
+    for ($i = 0; $i < $count; $i++) {
+        if ($tokens[$i]->text === '{') {
+            $depth++;
+
+            continue;
+        }
+        if ($tokens[$i]->text === '}') {
+            $depth--;
+
+            continue;
+        }
+        if (! $tokens[$i]->is(T_USE) || $depth !== $baseDepth) {
+            continue;
+        }
+
+        $prefix = '';
+        $pending = null;
+        $isGroup = false;
+
+        for ($j = $i + 1; $j < $count; $j++) {
+            $token = $tokens[$j];
+            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
+                continue;
+            }
+            if ($token->text === ';') {
+                break;
+            }
+            if ($token->text === '{') {
+                $isGroup = true;
+                $prefix = $pending === null ? '' : rtrim($pending, '\\').'\\';
+                $pending = null;
+
+                continue;
+            }
+            if ($token->text === '}' || $token->text === ',') {
+                if ($pending !== null) {
+                    $map[cacheGuardShortName($pending)] = $prefix.$pending;
+                    $pending = null;
+                }
+                if ($token->text === '}') {
+                    break;
+                }
+
+                continue;
+            }
+            if ($token->is(T_AS)) {
+                for ($k = $j + 1; $k < $count; $k++) {
+                    if ($tokens[$k]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
+                        continue;
+                    }
+                    if ($tokens[$k]->is(T_STRING) && $pending !== null) {
+                        $map[$tokens[$k]->text] = $prefix.$pending;
+                        $pending = null;
+                    }
+                    $j = $k;
+                    break;
+                }
+
+                continue;
+            }
+            if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
+                $pending = ltrim($token->text, '\\');
+
+                continue;
+            }
+            if ($token->is(T_NS_SEPARATOR)) {
+                continue; // グループ use の `Foo\{` の区切り
+            }
+        }
+
+        if ($pending !== null && ! $isGroup) {
+            $map[cacheGuardShortName($pending)] = $pending;
+        }
+    }
+
+    return $map;
+}
+
+/** 完全修飾名の末尾要素。 */
+function cacheGuardShortName(string $fqcn): string
+{
+    return str_contains($fqcn, '\\') ? substr((string) strrchr($fqcn, '\\'), 1) : $fqcn;
+}
+
+/**
+ * 1 ファイルが cached config / cached routes の trait を**適用している**か。
+ *
+ * 見るのは 2 形である。
+ *  (1) 型宣言より後の `use ...;` (trait の取り込み)
+ *  (2) Pest の `uses(...::class)` (生成される TestCase が trait を使う)。
+ *      **静的に解決できない引数 (`uses($trait)`) は `UNRESOLVED_USES(...)` として返す**
+ *      = 呼び出し側の gate が落ちる (見逃さない)
+ *
+ * ★1 ファイルに複数の名前空間がある形は `UNRESOLVED_NAMESPACES(...)` として落とす。
+ *   取り込み表を名前空間ごとに持ち分けない限り、別の名前空間の同名の別名で上書きできるためである。
+ *
+ * ★namespace 直下の取り込み (`use Illuminate\Foundation\Testing\WithCachedConfig;`) は
+ *   対象にしない — tests/TestCase.php は override のために取り込む必要があるためである。
+ *
+ * @param  list<PhpToken>  $tokens
+ * @param  array<string, string>  $useMap  alias => FQCN
+ * @return list<string> 見つかった trait の完全修飾名
+ */
+function cacheGuardCachedStateTraitUses(array $tokens, array $useMap): array
+{
+    $found = [];
+    $count = count($tokens);
+
+    $resolve = static function (string $raw) use ($useMap): string {
+        $name = ltrim($raw, '\\');
+
+        return $useMap[$name] ?? $name;
+    };
+
+    // ★1 ファイルに複数の名前空間があると、取り込み表を持ち分けない限り
+    //   別の名前空間の同名の別名で上書きできる。**解決できない形として落とす**。
+    $namespaceDeclarations = 0;
+    for ($i = 0; $i < $count; $i++) {
+        if ($tokens[$i]->is(T_NAMESPACE)) {
+            $namespaceDeclarations++;
+        }
+    }
+    if ($namespaceDeclarations > 1) {
+        $found[] = "UNRESOLVED_NAMESPACES({$namespaceDeclarations})";
+    }
+
+    // (2) Pest の uses(...::class)
+    for ($i = 0; $i < $count; $i++) {
+        if (! $tokens[$i]->is(T_STRING) || strtolower($tokens[$i]->text) !== 'uses') {
+            continue;
+        }
+        for ($j = $i + 1; $j < $count; $j++) {
+            $token = $tokens[$j];
+            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
+                continue;
+            }
+            if ($token->text !== '(') {
+                break;
+            }
+            for ($k = $j + 1; $k < $count; $k++) {
+                if ($tokens[$k]->text === ')') {
+                    break;
+                }
+                if ($tokens[$k]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
+                    continue;
+                }
+                if ($tokens[$k]->text === ',' || $tokens[$k]->is([T_DOUBLE_COLON, T_CLASS, T_NS_SEPARATOR])) {
+                    continue;
+                }
+                if ($tokens[$k]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
+                    if (in_array($resolve($tokens[$k]->text), CACHE_GUARD_CACHED_STATE_TRAITS, true)) {
+                        $found[] = $resolve($tokens[$k]->text);
+                    }
+
+                    continue;
+                }
+
+                // ★`uses($trait)` のように静的に決まらない引数は**未解決として落とす**
+                //   (AGENTS.md 走査規約 (b))。通常の `uses(X::class, Y::class)` は
+                //   すべて名前として書かれるので誤検出にならない。
+                $found[] = 'UNRESOLVED_USES('.$tokens[$k]->text.')';
+            }
+            break;
+        }
+    }
+
+    // (1) 型宣言の**本体の中**にある use (trait の取り込み)。
+    //     名前空間スコープの取り込みと区別するため、波括弧の深さで判定する
+    //     (型宣言の後ろにも名前空間スコープの取り込みを置けるため、位置では区別できない)。
+    $baseDepth = cacheGuardNamespaceBodyDepth($tokens);
+    $depth = 0;
+
+    for ($i = 0; $i < $count; $i++) {
+        if ($tokens[$i]->text === '{') {
+            $depth++;
+
+            continue;
+        }
+        if ($tokens[$i]->text === '}') {
+            $depth--;
+
+            continue;
+        }
+        if (! $tokens[$i]->is(T_USE) || $depth <= $baseDepth) {
+            continue;
+        }
+        for ($j = $i + 1; $j < $count; $j++) {
+            $token = $tokens[$j];
+            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
+                continue;
+            }
+            if ($token->text === ';' || $token->text === '{' || $token->text === '(') {
+                break; // `use (...)` の closure 形もここで抜ける
+            }
+            if ($token->text === ',') {
+                continue;
+            }
+            if (! $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
+                continue;
+            }
+            if (in_array($resolve($token->text), CACHE_GUARD_CACHED_STATE_TRAITS, true)) {
+                $found[] = $resolve($token->text);
+            }
+        }
+    }
+
+    return $found;
+}
+
+/** 走査対象を fail-closed で読む。 */
+function cacheGuardReadSource(string $relative): string
+{
+    $absolute = base_path($relative);
+    expect(is_file($absolute))->toBeTrue("{$relative} が実在しません (走査根の改名を疑う)");
+
+    $source = file_get_contents($absolute);
+    expect($source)->toBeString("{$relative} を読めません");
+
+    return (string) $source;
+}
+
+// ---------------------------------------------------------------------------
+// W1 / W6: 結線が bootstrap より前にある
+// ---------------------------------------------------------------------------
+
+test('W1: Tests\TestCase::createApplication() は bootstrap() より前に結線する', function (): void {
+    expect(cacheGuardBootstrapOrderViolations(
+        cacheGuardMethodTokens(TestCase::class, 'createApplication'),
+        'Tests\TestCase::createApplication()',
+    ))->toBe([]);
+});
+
+test('W6: 起動中の負例も同じ関数を同じメソッド内で bootstrap より前に呼ぶ', function (): void {
+    // ★負例が別経路で結線していたら「同じ結線を通った」ことの証明にならない。
+    //   ファイル全体ではなく**メソッド本体**を反射で切り出して見る
+    //   (別メソッドで結線し別メソッドで bootstrap する形を正常扱いしないため)。
+    expect(method_exists(IsolatedApplicationProbe::class, 'run'))->toBeTrue();
+
+    expect(cacheGuardBootstrapOrderViolations(
+        cacheGuardMethodTokens(IsolatedApplicationProbe::class, 'run'),
+        'IsolatedApplicationProbe::run()',
+    ))->toBe([]);
+});
+
+// ---------------------------------------------------------------------------
+// W2 / W3: 全レーンの結線と後始末
+// ---------------------------------------------------------------------------
+
+test('W2/W3: tests/Pest.php の期待レーン集合ちょうどが結線と後始末を持つ', function (): void {
+    $blocks = cacheGuardLaneBlocks(cacheGuardReadSource('tests/Pest.php'));
+
+    $lanes = array_map(static fn (array $block): array => $block['lanes'], $blocks);
+    $expected = CACHE_GUARD_EXPECTED_LANES;
+    usort($lanes, static fn (array $a, array $b): int => implode(',', $a) <=> implode(',', $b));
+
+    expect($lanes)->toBe($expected,
+        'tests/Pest.php のレーン構成が期待と一致しません。レーンを増減したなら '
+        .'CACHE_GUARD_EXPECTED_LANES も同じ変更で直し、新レーンにも guard の結線と後始末を入れてください。');
+
+    foreach ($blocks as $block) {
+        expect(cacheGuardLaneWiringViolations($block['tokens'], implode('+', $block['lanes'])))->toBe([]);
+    }
+});
+
+// ---------------------------------------------------------------------------
+// W4: vendor 追随の前提 (cached config / cached routes を使っていない)
+// ---------------------------------------------------------------------------
+
+test('W4: クラス本体の use 文と字句の uses() で WithCachedConfig / WithCachedRoutes を適用するテストが 0 件である', function (): void {
+    // ★使い始めると createApplication() の写しが vendor と食い違い、
+    //   cached 分岐の意味が変わる。使うときは override を写し直すこと。
+    $root = base_path('tests');
+    $iterator = new RecursiveIteratorIterator(
+        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
+    );
+
+    $users = [];
+    $files = 0;
+    foreach ($iterator as $file) {
+        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
+            continue;
+        }
+        $absolute = $file->getRealPath();
+        // ★解決できないパスを黙って除外しない (fail-closed)。
+        expect($absolute)->toBeString('走査対象のパスを解決できません: '.$file->getPathname());
+        if ($absolute === __FILE__) {
+            continue; // 本 gate 自身 (検出したい語を負例の入力として持つ)
+        }
+        $files++;
+
+        $source = file_get_contents((string) $absolute);
+        expect($source)->toBeString('走査対象を読めません: '.$absolute);
+        /** @var list<PhpToken> $tokens */
+        $tokens = PhpToken::tokenize((string) $source);
+
+        foreach (cacheGuardCachedStateTraitUses($tokens, cacheGuardUseMap($tokens)) as $trait) {
+            $users[] = ltrim(str_replace(base_path(), '', (string) $absolute), '/').' → '.$trait;
+        }
+    }
+
+    expect($files)->toBeGreaterThan(0, 'tests/ の走査が空振りしている');
+    expect($users)->toBe([]);
+});
+
+test('W4 の正負コントロール: trait の適用を 5 形とも検出し、取り込みだけは検出しない', function (): void {
+    $negatives = [
+        '短名' => <<<'PHP'
+        <?php
+        use Illuminate\Foundation\Testing\WithCachedConfig;
+        class P { use WithCachedConfig; }
+        PHP,
+        '別名' => <<<'PHP'
+        <?php
+        use Illuminate\Foundation\Testing\WithCachedRoutes as R;
+        class P { use R; }
+        PHP,
+        '完全修飾名' => <<<'PHP'
+        <?php
+        class P { use \Illuminate\Foundation\Testing\WithCachedConfig; }
+        PHP,
+        'カンマ区切り' => <<<'PHP'
+        <?php
+        use Illuminate\Foundation\Testing\WithCachedConfig;
+        class P { use Countable, WithCachedConfig; }
+        PHP,
+        'グループ use' => <<<'PHP'
+        <?php
+        use Illuminate\Foundation\Testing\{WithCachedConfig, WithCachedRoutes as R};
+        class P { use R; }
+        PHP,
+        'Pest の uses()' => <<<'PHP'
+        <?php
+        use Illuminate\Foundation\Testing\WithCachedConfig;
+        uses(WithCachedConfig::class);
+        PHP,
+    ];
+
+    foreach ($negatives as $label => $fixture) {
+        /** @var list<PhpToken> $probe */
+        $probe = PhpToken::tokenize($fixture);
+        expect(cacheGuardCachedStateTraitUses($probe, cacheGuardUseMap($probe)))
+            ->toHaveCount(1, "{$label}: 負例を検出できていません");
+    }
+
+    // 正のコントロール: namespace 直下の取り込みだけなら検出しない (tests/TestCase.php が該当)。
+    $importOnly = <<<'PHP'
+    <?php
+    use Illuminate\Foundation\Testing\WithCachedConfig;
+    class P {
+        public function run(): void {
+            $used = WithCachedConfig::class;
+        }
+    }
+    PHP;
+    /** @var list<PhpToken> $probe */
+    $probe = PhpToken::tokenize($importOnly);
+    expect(cacheGuardCachedStateTraitUses($probe, cacheGuardUseMap($probe)))->toBe([]);
+
+    // ★1 ファイルに複数の名前空間がある形も未解決として落とす
+    $multipleNamespaces = <<<'PHP'
+    <?php
+    namespace First;
+    use Illuminate\Foundation\Testing\WithCachedConfig as C;
+    class P { use C; }
+    namespace Second;
+    use Vendor\Package\Unrelated as C;
+    PHP;
+    /** @var list<PhpToken> $multiple */
+    $multiple = PhpToken::tokenize($multipleNamespaces);
+    $multipleDetected = cacheGuardCachedStateTraitUses($multiple, cacheGuardUseMap($multiple));
+    expect(implode(' / ', $multipleDetected))->toContain('UNRESOLVED_NAMESPACES');
+
+    // ★静的に解決できない `uses($trait)` は未解決として落とす (見逃さない)
+    $dynamicUses = <<<'PHP'
+    <?php
+    use Illuminate\Foundation\Testing\WithCachedConfig;
+    $trait = WithCachedConfig::class;
+    uses($trait);
+    PHP;
+    /** @var list<PhpToken> $dynamic */
+    $dynamic = PhpToken::tokenize($dynamicUses);
+    $detected = cacheGuardCachedStateTraitUses($dynamic, cacheGuardUseMap($dynamic));
+    expect($detected)->toHaveCount(1);
+    expect($detected[0])->toContain('UNRESOLVED_USES');
+
+    // 正のコントロール: 名前で書かれた uses() は未解決にしない
+    $staticUses = <<<'PHP'
+    <?php
+    use Illuminate\Foundation\Testing\RefreshDatabase;
+    use Tests\TestCase;
+    uses(TestCase::class, RefreshDatabase::class);
+    PHP;
+    /** @var list<PhpToken> $static */
+    $static = PhpToken::tokenize($staticUses);
+    expect(cacheGuardCachedStateTraitUses($static, cacheGuardUseMap($static)))->toBe([]);
+
+    // ★型宣言の**後ろ**に置いた名前空間スコープの取り込みも読むこと
+    //   (「最初の型宣言で打ち切る」形だと後置の別名を落として負例が黙る)
+    $lateImport = <<<'PHP'
+    <?php
+    namespace Tests\Late;
+    class Marker {}
+    use Illuminate\Foundation\Testing\WithCachedRoutes as R;
+    class P { use R; }
+    PHP;
+    /** @var list<PhpToken> $late */
+    $late = PhpToken::tokenize($lateImport);
+    expect(cacheGuardCachedStateTraitUses($late, cacheGuardUseMap($late)))->toHaveCount(1);
+
+    // 取り込み表がグループ use と別名を解決できていること
+    /** @var list<PhpToken> $groupUse */
+    $groupUse = PhpToken::tokenize(<<<'PHP'
+    <?php
+    use Illuminate\Foundation\Testing\{WithCachedConfig, WithCachedRoutes as R};
+    PHP);
+    expect(cacheGuardUseMap($groupUse))->toBe([
+        'WithCachedConfig' => 'Illuminate\Foundation\Testing\WithCachedConfig',
+        'R' => 'Illuminate\Foundation\Testing\WithCachedRoutes',
+    ]);
+});
+
+// ---------------------------------------------------------------------------
+// W5 / W5b: vendor 本体とローカルの写しの token 完全一致
+// ---------------------------------------------------------------------------
+
+test('W5: vendor の createApplication() の token 列が期待値と完全一致する', function (): void {
+    expect(cacheGuardTokenListViolations(
+        cacheGuardMethodTokens(VendorTestCase::class, 'createApplication'),
+        CACHE_GUARD_VENDOR_CREATE_APPLICATION_TOKENS,
+        'vendor の createApplication()',
+    ))->toBe([],
+        'Laravel の createApplication() が変わりました。tests/TestCase.php の写しを'
+        .'読み直して更新し、本 gate の期待 token 列も同じ変更で直してください。');
+});
+
+test('W5b: ローカルの写しが vendor 期待列 + 許可差分と完全一致する', function (): void {
+    expect(cacheGuardLocalCopyViolations(cacheGuardMethodTokens(TestCase::class, 'createApplication')))
+        ->toBe([],
+            'tests/TestCase.php の createApplication() が期待と一致しません。'
+            .'許可差分 (戻り値型 / fail-closed 確認 / 結線 1 行) 以外の変更を入れていないか、'
+            .'vendor の写しから文を消していないか確認してください。');
+
+    // #[\Override] は反射で別途見る (getStartLine から切り出したソースに属性行が入る保証が無い)。
+    expect((new ReflectionMethod(TestCase::class, 'createApplication'))->getAttributes(Override::class))
+        ->toHaveCount(1);
+});
+
+// ---------------------------------------------------------------------------
+// W7: 空振り検知
+// ---------------------------------------------------------------------------
+
+test('W7: 走査と検出器が空振りしていない', function (): void {
+    expect(CACHE_GUARD_VENDOR_CREATE_APPLICATION_TOKENS)->not->toBe([]);
+    expect(CACHE_GUARD_LOCAL_CREATE_APPLICATION_TOKENS)->not->toBe([]);
+    expect(cacheGuardMethodTokens(VendorTestCase::class, 'createApplication'))->not->toBe([]);
+    expect(cacheGuardMethodTokens(TestCase::class, 'createApplication'))->not->toBe([]);
+    expect(cacheGuardLaneBlocks(cacheGuardReadSource('tests/Pest.php')))->toHaveCount(3);
+
+    // 許可差分の合計が token 数の差と一致する (取りこぼした差分が無い)
+    $inserted = array_sum(array_map(
+        static fn (array $insertion): int => count($insertion['tokens']),
+        CACHE_GUARD_LOCAL_ALLOWED_INSERTIONS,
+    ));
+    expect(count(CACHE_GUARD_LOCAL_CREATE_APPLICATION_TOKENS) - count(CACHE_GUARD_VENDOR_CREATE_APPLICATION_TOKENS))
+        ->toBe($inserted);
+
+    // 検出器が負例に反応する (実在ファイルの構成に依存させない)
+    expect(cacheGuardBootstrapOrderViolations(
+        cacheGuardNormalizedTokens('<?php $app->make(Kernel::class)->bootstrap();'), 'probe'
+    ))->not->toBe([]);
+    expect(cacheGuardLaneWiringViolations(cacheGuardNormalizedTokens('<?php pest()->extend(TestCase::class);'), 'probe'))
+        ->not->toBe([]);
+    expect(cacheGuardTokenListViolations(['a'], ['b'], 'probe'))->not->toBe([]);
+    expect(cacheGuardLocalCopyViolations([]))->not->toBe([]);
+
+    // メソッド抽出器そのものが生きている
+    $extracted = cacheGuardMethodTokensFromSource(
+        '<?php class P { public function run(): void { $a = 1; } }', 'run'
+    );
+    expect($extracted)->not->toBe([]);
+    expect($extracted[0])->toBe('function');
+    expect(cacheGuardMethodTokensFromSource('<?php class P {}', 'missing'))->toBe([]);
+});
+
+// ---------------------------------------------------------------------------
+// W8: 負のコントロール
+// ---------------------------------------------------------------------------
+
+test('W8: 結線が bootstrap の後 / 無い / 別メソッドにある形を検出する', function (): void {
+    $afterBootstrap = <<<'PHP'
+    <?php
+    class Probe {
+        public function createApplication() {
+            $app = require 'bootstrap/app.php';
+            $app->make(Kernel::class)->bootstrap();
+            PlainDataCacheGuard::registerBeforeBootstrap($app);
+            return $app;
+        }
+    }
+    PHP;
+    expect(cacheGuardBootstrapOrderViolations(
+        cacheGuardMethodTokensFromSource($afterBootstrap, 'createApplication'), 'fixture'
+    ))->toHaveCount(1);
+
+    $missing = <<<'PHP'
+    <?php
+    class Probe {
+        public function createApplication() {
+            $app = require 'bootstrap/app.php';
+            $app->make(Kernel::class)->bootstrap();
+            return $app;
+        }
+    }
+    PHP;
+    expect(cacheGuardBootstrapOrderViolations(
+        cacheGuardMethodTokensFromSource($missing, 'createApplication'), 'fixture'
+    ))->toHaveCount(1);
+
+    // ★別メソッドで結線し別メソッドで bootstrap する形。**メソッド抽出 + 順序判定の組**で落ちる
+    //   (ファイル全体を渡すと 0 件になってしまう形であり、それが W1/W6 が本体を切り出す理由である)。
+    $splitWiring = <<<'PHP'
+    <?php
+    class Probe {
+        public function wire($app) {
+            PlainDataCacheGuard::registerBeforeBootstrap($app);
+        }
+        public function createApplication() {
+            $app = require 'bootstrap/app.php';
+            $app->make(Kernel::class)->bootstrap();
+            return $app;
+        }
+    }
+    PHP;
+    expect(cacheGuardBootstrapOrderViolations(
+        cacheGuardMethodTokensFromSource($splitWiring, 'createApplication'), 'method-scope'
+    ))->toHaveCount(1);
+    expect(cacheGuardBootstrapOrderViolations(
+        cacheGuardNormalizedTokens($splitWiring), 'file-scope'
+    ))->toBe([]);
+});
+
+test('W8: レーンの結線・後始末が崩れた 4 形を検出する', function (): void {
+    $complete = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)
+        ->beforeEach(function (): void {
+            PlainDataCacheGuard::assertInstalled($this->app);
+        })
+        ->afterEach(function (): void {
+            try {
+                PlainDataCacheGuard::flushAndFailIfStray();
+            } finally {
+                PlainDataCacheGuard::reset();
+            }
+        })
+        ->in('Feature', 'Unit');
+    PHP;
+
+    $blocks = cacheGuardLaneBlocks($complete);
+    expect($blocks)->toHaveCount(1);
+    expect($blocks[0]['lanes'])->toBe(['Feature', 'Unit']);
+    expect(cacheGuardLaneWiringViolations($blocks[0]['tokens'], 'fixture'))->toBe([]);
+
+    $missingFlush = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)
+        ->beforeEach(function (): void {
+            PlainDataCacheGuard::assertInstalled($this->app);
+        })
+        ->afterEach(function (): void {
+            try {
+                StrayHttpRequestGuard::flushAndFailIfStray();
+            } finally {
+                PlainDataCacheGuard::reset();
+            }
+        })
+        ->in('Feature', 'Unit');
+    PHP;
+
+    // flush が try ブロックの**外** (afterEach の先頭) にある形
+    $flushOutsideTry = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)
+        ->beforeEach(function (): void {
+            PlainDataCacheGuard::assertInstalled($this->app);
+        })
+        ->afterEach(function (): void {
+            PlainDataCacheGuard::flushAndFailIfStray();
+            try {
+                StrayHttpRequestGuard::flushAndFailIfStray();
+            } finally {
+                StrayHttpRequestGuard::reset();
+                PlainDataCacheGuard::reset();
+            }
+        })
+        ->in('Feature', 'Unit');
+    PHP;
+
+    // reset が finally ブロックの**外** (try の中) にある形
+    $resetOutsideFinally = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)
+        ->beforeEach(function (): void {
+            PlainDataCacheGuard::assertInstalled($this->app);
+        })
+        ->afterEach(function (): void {
+            try {
+                PlainDataCacheGuard::flushAndFailIfStray();
+                PlainDataCacheGuard::reset();
+            } finally {
+                StrayHttpRequestGuard::reset();
+            }
+        })
+        ->in('Feature', 'Unit');
+    PHP;
+
+    // assertInstalled が beforeEach クロージャの**外**にある形
+    $assertOutsideBeforeEach = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)
+        ->beforeEach(function (): void {
+            $this->withoutVite();
+        })
+        ->afterEach(function (): void {
+            PlainDataCacheGuard::assertInstalled($this->app);
+            try {
+                PlainDataCacheGuard::flushAndFailIfStray();
+            } finally {
+                PlainDataCacheGuard::reset();
+            }
+        })
+        ->in('Feature', 'Unit');
+    PHP;
+
+    $noTryFinally = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)
+        ->beforeEach(function (): void {
+            PlainDataCacheGuard::assertInstalled($this->app);
+        })
+        ->afterEach(function (): void {
+            PlainDataCacheGuard::flushAndFailIfStray();
+            PlainDataCacheGuard::reset();
+        })
+        ->in('Feature', 'Unit');
+    PHP;
+
+    // ★flush を持つ try に finally が無く、reset は**別の** try-finally の中にある形。
+    //   try と finally を独立に探すと通ってしまうが、flush が投げると catch の return で
+    //   reset へ到達しない。
+    $unrelatedFinally = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)
+        ->beforeEach(function (): void {
+            PlainDataCacheGuard::assertInstalled($this->app);
+        })
+        ->afterEach(function (): void {
+            try {
+                PlainDataCacheGuard::flushAndFailIfStray();
+            } catch (Throwable) {
+                return;
+            }
+
+            try {
+                StrayHttpRequestGuard::flushAndFailIfStray();
+            } finally {
+                PlainDataCacheGuard::reset();
+            }
+        })
+        ->in('Feature', 'Unit');
+    PHP;
+
+    // ★try 文が条件分岐の中にある形。範囲としては afterEach の内側だが 1 度も実行されない。
+    $tryInsideBranch = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)
+        ->beforeEach(function (): void {
+            PlainDataCacheGuard::assertInstalled($this->app);
+        })
+        ->afterEach(function (): void {
+            if (false) {
+                try {
+                    PlainDataCacheGuard::flushAndFailIfStray();
+                } finally {
+                    PlainDataCacheGuard::reset();
+                }
+            }
+        })
+        ->in('Feature', 'Unit');
+    PHP;
+
+    // ★assertInstalled が条件分岐の中にある形。同上。
+    $assertInsideBranch = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)
+        ->beforeEach(function (): void {
+            if (false) {
+                PlainDataCacheGuard::assertInstalled($this->app);
+            }
+        })
+        ->afterEach(function (): void {
+            try {
+                PlainDataCacheGuard::flushAndFailIfStray();
+            } finally {
+                PlainDataCacheGuard::reset();
+            }
+        })
+        ->in('Feature', 'Unit');
+    PHP;
+
+    // ★波括弧を使わない制御構文 / 代替構文 / 短絡評価。いずれも波括弧の深さは 0 だが
+    //   「無条件に実行される」ことを保証しない。
+    $bracelessIf = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)
+        ->beforeEach(function (): void {
+            if (false)
+                PlainDataCacheGuard::assertInstalled($this->app);
+        })
+        ->afterEach(function (): void {
+            try {
+                PlainDataCacheGuard::flushAndFailIfStray();
+            } finally {
+                PlainDataCacheGuard::reset();
+            }
+        })
+        ->in('Feature', 'Unit');
+    PHP;
+
+    $alternativeSyntax = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)
+        ->beforeEach(function (): void {
+            PlainDataCacheGuard::assertInstalled($this->app);
+        })
+        ->afterEach(function (): void {
+            try {
+                if (false):
+                    PlainDataCacheGuard::flushAndFailIfStray();
+                endif;
+            } finally {
+                PlainDataCacheGuard::reset();
+            }
+        })
+        ->in('Feature', 'Unit');
+    PHP;
+
+    $shortCircuit = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)
+        ->beforeEach(function (): void {
+            PlainDataCacheGuard::assertInstalled($this->app);
+        })
+        ->afterEach(function (): void {
+            try {
+                PlainDataCacheGuard::flushAndFailIfStray();
+            } finally {
+                $shouldReset && PlainDataCacheGuard::reset();
+            }
+        })
+        ->in('Feature', 'Unit');
+    PHP;
+
+    foreach ([
+        '波括弧なしの if' => $bracelessIf,
+        '代替構文の if' => $alternativeSyntax,
+        '短絡評価の右辺' => $shortCircuit,
+        'flush が無い' => $missingFlush,
+        'flush が try の外' => $flushOutsideTry,
+        'reset が finally の外' => $resetOutsideFinally,
+        'assertInstalled が beforeEach の外' => $assertOutsideBeforeEach,
+        'try / finally の形でない' => $noTryFinally,
+        'reset が別の try 文の finally にある' => $unrelatedFinally,
+        'try が条件分岐の中にある' => $tryInsideBranch,
+        'assertInstalled が条件分岐の中にある' => $assertInsideBranch,
+    ] as $label => $damaged) {
+        expect($damaged)->not->toBe($complete, "{$label}: 合成入力が完全形と同じになっている");
+
+        $blocks = cacheGuardLaneBlocks($damaged);
+        expect(cacheGuardLaneWiringViolations($blocks[0]['tokens'], 'fixture'))
+            ->not->toBe([], "{$label}: 検出できていません");
+    }
+});
+
+test('W8: レーン集合が違う形を検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)->in('Feature');
+    pest()->extend(TestCase::class)->in('Unit');
+    PHP;
+
+    $lanes = array_map(static fn (array $block): array => $block['lanes'], cacheGuardLaneBlocks($fixture));
+    expect($lanes)->not->toBe(CACHE_GUARD_EXPECTED_LANES);
+});
+
+test('W8: vendor 本体の token 増減・順序入れ替えを判定関数が検出する', function (): void {
+    $expected = CACHE_GUARD_VENDOR_CREATE_APPLICATION_TOKENS;
+
+    $added = $expected;
+    $added[] = ';';
+    expect(cacheGuardTokenListViolations($added, $expected, 'fixture'))->not->toBe([]);
+
+    $swapped = $expected;
+    [$swapped[6], $swapped[7]] = [$swapped[7], $swapped[6]];
+    expect(count($swapped))->toBe(count($expected)); // 数だけでは検出できないことの明示
+    expect(cacheGuardTokenListViolations($swapped, $expected, 'fixture'))->not->toBe([]);
+
+    expect(cacheGuardTokenListViolations($expected, $expected, 'fixture'))->toBe([]);
+});
+
+test('W8: ローカルの写しから既知の文を消した形を判定関数が検出する', function (): void {
+    // ★W5 (vendor 側) と W1 (順序) だけでは緑のまま通ってしまう改変を、W5b が捕まえる。
+    expect(cacheGuardLocalCopyViolations(CACHE_GUARD_LOCAL_CREATE_APPLICATION_TOKENS))->toBe([]);
+
+    foreach ([
+        'traitsUsedByTest の代入' => ['$this', '->', 'traitsUsedByTest', '=', 'class_uses_recursive'],
+        'cached config 分岐' => ['WithCachedConfig', '::', 'class'],
+        'cached routes 分岐' => ['WithCachedRoutes', '::', 'class'],
+        'return $app' => ['return', '$app', ';'],
+        '結線 1 行' => ['PlainDataCacheGuard', '::', 'registerBeforeBootstrap'],
+    ] as $label => $needle) {
+        $position = cacheGuardSequencePosition(CACHE_GUARD_LOCAL_CREATE_APPLICATION_TOKENS, $needle);
+        expect($position)->not->toBeNull("{$label} が期待列にありません");
+
+        $damaged = CACHE_GUARD_LOCAL_CREATE_APPLICATION_TOKENS;
+        array_splice($damaged, (int) $position, count($needle));
+
+        expect(cacheGuardLocalCopyViolations($damaged))->not->toBe([], "{$label}: 検出できていません");
+    }
+});
diff --git a/tests/Architecture/CachePayloadPlainDataGateTest.php b/tests/Architecture/CachePayloadPlainDataGateTest.php
index bc74a21e..dc8d4638 100644
--- a/tests/Architecture/CachePayloadPlainDataGateTest.php
+++ b/tests/Architecture/CachePayloadPlainDataGateTest.php
@@ -1,19 +1,34 @@
 <?php
 
 declare(strict_types=1);
+use Tests\Support\Cache\PlainDataGuardedRepository;
 
 /*
- * Architecture invariant: **キャッシュに入れてよいのは素のデータだけ** (配列 / 文字列 / 数値 / 真偽値)。
+ * Architecture invariant: **キャッシュに入れてよいのは素のデータだけ**
+ * (配列 / 文字列 / 数値 / 真偽値 / null)。
  *
  * SoT = lctl 台帳 feature `cache-payload-plain-data` の標準形 v1 (裁定 2026-08-06) と
  * AGENTS.md セキュリティ不変条件 11 / docs/app-integration-guide.md §7 不変条件 6。
  *
- * ★なぜ静的検査か (実行時検出では捕まらない):
- *   テストレーンは phpunit.xml で CACHE_STORE=array、config/cache.php の array store は
- *   'serialize' => false。**オブジェクトを put してもそのまま返る = テストは緑になる**。
- *   本番は database store で serialize され、serializable_classes => false のため
- *   読み戻しは __PHP_Incomplete_Class になる。つまり「テストで再現しない本番専用の壊れ方」であり、
- *   実行時 detector (KeyWritten 購読等) は原理的にこの穴を塞げない。
+ * ★2 層構成のうち**静的層**がこのファイルである (家系の裁定 AG-151 = 正典 v2)。
+ *   - 静的層 (ここ) が保証するのは「**申告なしに書き込み経路を増やせない**」ことである。
+ *     目録の payload 欄は**人間の申告**なので、書いた値が実際に素データかは保証しない
+ *   - 実行時層 (tests/Support/Cache/PlainDataCacheGuard.php) が保証するのは
+ *     「**テストが実行した書き込みの値が実際に素データである**」ことである。
+ *     受け皿 (Illuminate\Cache\Repository) を包んで保管先へ渡す前の値を再帰検査するので、
+ *     **直列化を一度も経由しない = テストレーンの array store でも同じように発火する**
+ *   - どちらも他方を包含しない。vendor 由来の書き込みは静的層の走査根に入らず (実行時層だけが見る)、
+ *     テストが 1 度も踏まない経路は実行時層に見えない (静的層だけが見る)
+ *
+ *   ※ 旧版のこの位置には「実行時 detector は原理的にこの穴を塞げない」という記述があったが、
+ *     これは**書き込みイベントを購読する型の検出器にだけ当てはまる主張**で、
+ *     受け皿を包んで値を見る型には当てはまらない。裁定 AG-151 が誤りとして棄却したので削除した。
+ *
+ * ★L4 (境界迂回) を**静的層だけで塞ぐ**ものがある。とくに `getStore()` は
+ *   vendor 自身が正常系で呼ぶため実行時には落とせない (RateLimiter の hit/increment 経路、
+ *   Repository::flushLocks() の自己呼び出し、スケジューラの排他など)。
+ *   よって「保管先を直接取得して書く」形を塞ぐのは**このファイルだけ**であり、
+ *   vendor が getStore() 経由で書く値は 2 層とも見えない (保証しないもの)。
  *
  * ★serializable_classes は **false 固定**であって「キーを消してよい」ではない:
  *   CacheManager は `config['cache.serializable_classes'] ?? null` を読み、各 store は
@@ -33,7 +48,14 @@
  *     (規則自体も検査 5b で固定)
  *   - 検査 6: 実行時 config('cache.serializable_classes') === false、store 単位の上書きなし
  *   - 検査 5b: role 判定規則そのものの正負コントロール (実在ファイルの構成に依存させない)
- *   - 検査 6b: 語彙表の健全性 (4 分類が互いに素 / 除外型が受け手型に混ざっていない)
+ *   - 検査 6b: 語彙表の健全性 (5 分類が互いに素 / 除外型が受け手型に混ざっていない)
+ *   - 検査 L4a-L4f (境界迂回): 受け皿を跨いで保管先へ届く / 受け皿の生成に割り込む書き方
+ *     (`extend` / `getStore` / `setStore` / `tags` / `macro` / `mixin` / `flushMacros` /
+ *     受け手型・保管先型・実行時層の実装クラスの直接生成 / 継承・実装の宣言) が、
+ *     **通常経路 0 件 + 実行時層の自己テストの exact-fit** に収まっている
+ *   - 検査 L4h: `new $class` のように**生成対象が静的に決まらない形**を走査根の全体で
+ *     deny-by-default にし、キャッシュの保管先ではない既知の用途を理由付きの目録へ
+ *     exact-fit で登録している (fail-closed)
  *   - 検査 7: 空振り検知 (走査ファイル数 / メソッド呼び出し数 / 解決できたキャッシュ式が 0 でない)
  *   - 検査 8: 自己参照コントロール (本ファイル自身を走査して書き込み 0 件・面 hit なし)
  *   - 検査 9 以降: 正負コントロール fixture (facade / チェーン / ヘルパ / DI / コンテナ /
@@ -51,13 +73,29 @@
  *     この形は実測 0 件で、通常のレビューで自明に不自然な書き方である
  *   ※ 受け手が cache と分かっている上での**動的メソッド名** (`->{$m}(...)` / `->$m(...)`) は
  *     素通りさせず `unclassified` として fail させる。literal 形 (`->{'put'}(...)`) は通常形と同じに分類する
+ *   - **走査根の外で宣言され、完全修飾名が組み込み Store の命名規則に一致しない第三者の
+ *     `Store` 実装**の直接生成・コンテナ束縛経由の取得 (`cachePayloadIsStoreType()` の限界)
+ *   - **`new` を経由しない取得** (コンテナ束縛・factory・vendor 内部からの受け取り)。
+ *     L4h が塞ぐのは `new` で生成する形だけである
+ *   - **継承・実装の宣言のうち、名前として書かれていない形**。PHP は extends / implements に
+ *     名前しか書けないため合法な未解決形は無いが、走査は字句判定なので
+ *     名前以外の token が現れたら**未分類として落とす** (負例は合成入力で固定する)。
+ *     名前の解決は取り込み表 → 現在の名前空間の順で行い、完全修飾名で突き合わせる
+ *     (`namespace\Foo` の相対参照も解決する)
+ *   - **動的生成の目録 (L4h) の `rationale` が正しいこと**。「何を生成しているか」は
+ *     人間の申告であり、機械は件数の exact-fit しか見ない (L2 の `payload` 欄と同じ扱い)。
+ *     同じファイルの中で許可済みの生成をキャッシュの保管先の生成へ置き換えると、
+ *     件数が変わらない限り検出できない
+ *   - **受け手名として解決できない変数**への添字代入 (`$c['k'] = $v` の `$c` が型宣言を持たない形)。
+ *     既存の受け手解決の限界と同じ
  *   - **docblock だけで型付けされた受け手** (`@var Repository $c` の docblock を書いた直後に
  *     `$c->put(...)` する形)。型宣言 (引数 / プロパティ / promoted ctor param) のみを見る。
  *     ※同じファイルに対応する型の `use` があれば **L3 (面) には現れる**が、
  *       完全修飾 docblock だけで import も型宣言も無い形は **L3 でも捕まらない**。
  *       docblock 解析は行わない (実測 0 件)
- *   - `use A\{B, C};` のグループ use 構文は扱わない (実測 0 件。`NoNonCompoundGlobalUseTest` が
- *     別途 use の書き方を縛っている)
+ *   - **`use function` / `use const` の取り込み**は名前解決の表に入れない (クラス参照ではない)
+ *   - **1 ファイルに複数の名前空間がある形**は解決せず**未分類として落とす**
+ *     (取り込み表を名前空間ごとに持ち分けない限り、別の名前空間の同名の別名で上書きできるため)
  *
  * 解析は PhpToken::tokenize (コメント・文字列リテラルは code token ではないので拾わない)。
  * regex にすると**この説明コメント自身**で偽赤になる。DB 不使用 (Architecture lane は TestCase のみ)。
@@ -110,30 +148,55 @@
  */
 const CACHE_PAYLOAD_WRITE_METHODS = [
     'put', 'add', 'forever', 'remember', 'rememberforever', 'sear',
-    'flexible', 'putmany', 'set', 'setmultiple',
+    'flexible', 'putmany', 'set', 'setmultiple', 'rememberwithwarmth', 'offsetset',
 ];
 
 /**
  * payload を書き込まない API (increment/decrement は整数のみ書けるため素データが構造的に保証される)。
  *
+ * `hasmacro` は macro 登録簿の**読み出し**であり、登録も呼び出しもしない
+ * (登録側の `macro` / `mixin` / `flushmacros` は BYPASS)。
+ *
  * @var list<string>
  */
 const CACHE_PAYLOAD_NON_WRITE_METHODS = [
     'get', 'many', 'getmultiple', 'has', 'missing', 'pull', 'forget', 'delete',
     'deletemultiple', 'flush', 'clear', 'increment', 'decrement',
     'supportstags', 'getprefix', 'getdefaultdriver', 'setdefaultdriver',
-    'forgetdriver', 'purge', 'extend', 'itemkey', 'refresheventdispatcher',
+    'forgetdriver', 'purge', 'itemkey', 'refresheventdispatcher', 'hasmacro',
 ];
 
 /**
  * 受け手を保ったまま連鎖する API。
  *
- * `getStore()` は `Illuminate\Contracts\Cache\Store` を返し **put / forever を持つ**ので
- * NON_WRITE ではなく CHAIN (`Cache::getStore()->put(...)` の抜けを塞ぐ)。
+ * `getStore()` / `tags()` はここに**置かない** — どちらも受け皿 (Repository) を跨いで
+ * 保管先へ届くので BYPASS である (L4)。辿って書き込みを数えるのではなく、
+ * 書き方そのものを 0 件で pin する。
+ *
+ * @var list<string>
+ */
+const CACHE_PAYLOAD_CHAIN_METHODS = ['store', 'driver', 'resolve', 'getfacaderoot'];
+
+/**
+ * 受け皿 (Repository) を跨いで保管先 (Store) へ届く / 受け皿の生成そのものに割り込む API。
+ * **通常経路は 0 件**で、実行時層の自己テストだけを名指しの目録へ exact-fit で登録する
+ * (家系の裁定 AG-151 の v2 要素 4「境界迂回の hard fail」)。
+ *
+ * - extend    独自 creator は CacheManager::repository() を通らないので実行時層の被覆から抜ける
+ *             (通らないことは tests/Feature/Cache/CachePayloadPlainDataGuardTest.php が実証する)。
+ *             判定は**通常経路 0 件 + GuardedBoundaryProbe の自己テストの exact-fit**である
+ * - getStore / setStore  保管先を直接触る = 受け皿を跨ぐ。`getStore()` は vendor 自身が
+ *             正常系で呼ぶため**実行時には落とせない** = ここが唯一の防壁である
+ * - tags      vendor の tags() は new TaggedCache(...) を素で生成するので guard が効かない。
+ *             加えて本番の database store は supportsTags() が false でタグ非対応
+ * - macro / mixin / flushMacros  Repository は Macroable を use しており、
+ *             macro 内から $this->store へ直接到達できる (末端 4 メソッドを通らない)
  *
  * @var list<string>
  */
-const CACHE_PAYLOAD_CHAIN_METHODS = ['store', 'driver', 'tags', 'resolve', 'getstore', 'getfacaderoot'];
+const CACHE_PAYLOAD_BYPASS_METHODS = [
+    'extend', 'getstore', 'setstore', 'tags', 'macro', 'mixin', 'flushmacros',
+];
 
 /**
  * 受け手がキャッシュでなくなる terminal (以降の連鎖を辿らない)。
@@ -149,6 +212,156 @@
     'expects', 'shouldhavereceived', 'shouldnothavereceived',
 ];
 
+/**
+ * L4: 境界迂回の**自己テスト**の目録 (exact-fit)。
+ *
+ * key   = `{相対パス}::{メソッド名 (全小文字)}` / `{相対パス}::new {完全修飾名}`
+ *         ★**完全修飾名で突き合わせる** (AGENTS.md 走査規約 (a))。短名では別名つき取り込みや
+ *           同名の別クラスを区別できない
+ * count = 出現回数 (完全一致。1 件増えたら必ず落ちる)
+ * rationale = 30 文字以上の具体的根拠
+ *
+ * ★登録できるのは **tests/Support/Cache/GuardedBoundaryProbe.php の 1 ファイルだけ**である
+ *   (検査 L4f が名指しで固定する)。「tests/Support/Cache/ 配下すべて」にはしない —
+ *   将来足した任意の補助ファイルが自己テストを名乗れてしまうため。
+ * ★**動的呼び出しで走査を避ける形は採らない** (検出力の裏取りが弱くなるため)。
+ * ★本目録に載せた呼び出しは**検査 1 (未分類 API の deny-by-default) の母集団からも除く**。
+ *   実行時層は保管先への素通し (`__call`) を落とすので、その自己テストは
+ *   「4 分類のどれでもない API 名」を意図的に呼ぶことになるためである。
+ *   目録に載っていない未知 API は従来どおり落ちる。
+ *
+ * @var array<string, array{count: int, rationale: string}>
+ */
+const CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY = [
+    'tests/Support/Cache/GuardedBoundaryProbe.php::extend' => [
+        'count' => 1,
+        'rationale' => '独自 driver の creator が CacheManager::repository() を通らないことを実証する trip-wire。通らなくなったら L4 の根拠が変わる',
+    ],
+    'tests/Support/Cache/GuardedBoundaryProbe.php::flushmacros' => [
+        'count' => 1,
+        'rationale' => 'callMacro の finally で必ず登録を消すための 1 件。消さないと global afterEach の macro pin が二重に落ちる',
+    ],
+    'tests/Support/Cache/GuardedBoundaryProbe.php::guardprobemacro' => [
+        'count' => 1,
+        'rationale' => '登録した macro を実際に呼ぶ 1 件。実行時層の __call() が macro を使用時点で落とすことの負例になる',
+    ],
+    'tests/Support/Cache/GuardedBoundaryProbe.php::guardprobeunknownmethod' => [
+        'count' => 1,
+        'rationale' => 'macro でない未知メソッド (保管先への素通し) を呼ぶ 1 件。名指しで分類していない素通しが落ちることの負例になる',
+    ],
+    'tests/Support/Cache/GuardedBoundaryProbe.php::macro' => [
+        'count' => 2,
+        'rationale' => 'macro 経由の到達が使用時点で落ちること (callMacro) と、残存 macro を flush が検出すること (registerMacroWithoutUsing) の 2 件',
+    ],
+    'tests/Support/Cache/GuardedBoundaryProbe.php::new Illuminate\Cache\ArrayStore' => [
+        'count' => 2,
+        'rationale' => 'setStore の引数と独自 creator の保管先として使う。保管先の直接生成が検出されることの自己確認も兼ねる',
+    ],
+    'tests/Support/Cache/GuardedBoundaryProbe.php::new Illuminate\Cache\Repository' => [
+        'count' => 1,
+        'rationale' => '独自 creator が返す素の受け皿。guard を通らない受け皿が実際に作れてしまうことを実証するために必要な 1 件',
+    ],
+    'tests/Support/Cache/GuardedBoundaryProbe.php::setstore' => [
+        'count' => 1,
+        'rationale' => '受け皿の保管先を差し替える口が境界迂回として落ちることを固定する。落ちなくなると guard 付き受け皿の中身を入れ替えられる',
+    ],
+    'tests/Support/Cache/GuardedBoundaryProbe.php::tags' => [
+        'count' => 1,
+        'rationale' => 'guard 付き受け皿の tags() が境界迂回として落ちることを固定する。落ちなくなると TaggedCache 経由の書き込みが素通りする',
+    ],
+];
+
+/** L4 の自己テストを置いてよい唯一のファイル (相対パス)。 */
+const CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE = 'tests/Support/Cache/GuardedBoundaryProbe.php';
+
+/**
+ * 実行時層の実装クラス。**生成は許すが継承は許さない**。
+ *
+ * ★これらを継承すると、末端 4 メソッドを override し直して `getStore()` 経由で
+ *   保管先へ直接書ける (`class X extends PlainDataGuardedRepository { public function put(…) {
+ *   return $this->getStore()->put(…); } }`)。受け手型にも保管先型の命名規則にも一致しないので、
+ *   継承検査に足さないと L4d をすり抜ける。
+ *
+ * @var list<string>
+ */
+const CACHE_PAYLOAD_GUARD_IMPLEMENTATION_TYPES = [
+    'Tests\Support\Cache\PlainDataGuardedRepository',
+    'Tests\Support\Cache\PlainDataGuardedCacheManager',
+];
+
+/**
+ * L4b の fail-closed: **キャッシュの保管先ではない**と申告する動的生成の目録 (exact-fit)。
+ *
+ * `new $class` / `new ($expr)` は生成されるクラスが静的に決まらないため、
+ * 受け皿・保管先の直接生成を隠せてしまう
+ * (`$c = ArrayStore::class; $s = new $c; $s->put(…)` は受け手型の宣言も持たないので L2 にも現れない)。
+ * よって走査根の全体で deny-by-default にし、既知の非キャッシュ用途をここへ登録する。
+ *
+ * count = 出現回数 (完全一致。1 件増えたら必ず落ちる)
+ * rationale = 30 文字以上の具体的根拠 (**何を生成しているか**を書く)
+ *
+ * ★`Factory::new()` / `->new()` は**メソッド名**であって生成ではないので母集団に入れない。
+ *
+ * @var array<string, array{count: int, rationale: string}>
+ */
+const CACHE_PAYLOAD_DYNAMIC_NEW_INVENTORY = [
+    'app/Enums/Billing/BillingRetentionTarget.php' => [
+        'count' => 1,
+        'rationale' => '保持期限の対象 Eloquent モデルを生成して getTable() で表名を得るだけ。生成するのはモデルであって保管先ではない',
+    ],
+    'tests/Architecture/MassAssignmentSafetyTest.php' => [
+        'count' => 2,
+        'rationale' => '全 Eloquent モデルを順に生成して getFillable() / getGuarded() を読む走査。生成するのはモデルであって保管先ではない',
+    ],
+    'tests/Architecture/RouteBindingTypeConstraintInventoryTest.php' => [
+        'count' => 1,
+        'rationale' => 'route binding が宣言した型を生成して Eloquent Model かどうかと主キーの型区分を確かめる。生成するのはモデルであって保管先ではない',
+    ],
+    'tests/Feature/InitialState/NullInitialStateColumnClassificationTest.php' => [
+        'count' => 1,
+        'rationale' => '実スキーマと突き合わせるため Eloquent モデルを生成して cast 宣言を読む。生成するのはモデルであって保管先ではない',
+    ],
+];
+
+/**
+ * L4d: 受け手型 / 保管先型の**継承・実装の宣言**を許す名指しの目録 (exact-fit)。
+ *
+ * key = `{相対パス}::{extends|implements} {完全修飾名}`。
+ * 任意の Repository サブクラスを作れば `new` の検出を逃れられるので、**宣言側で塞ぐ**。
+ *
+ * @var array<string, string>
+ */
+const CACHE_PAYLOAD_SUBCLASS_INVENTORY = [
+    'tests/Support/Cache/PlainDataGuardedRepository.php::extends Illuminate\Cache\Repository' => '実行時層の受け皿そのもの。値の末端 4 メソッドを override するには継承以外の手段が無い',
+    'tests/Support/Cache/PlainDataGuardedCacheManager.php::extends Illuminate\Cache\CacheManager' => '実行時層の manager そのもの。repository() を override して guard 付き受け皿を返すために継承する',
+];
+
+/**
+ * 保管先 (Store) の型かどうかの判定規則。
+ *
+ * 解決した完全修飾名が
+ *   (a) `Illuminate\Contracts\Cache\Store` である、または
+ *   (b) `Illuminate\Cache\` で始まり `Store` で終わる (ArrayStore / DatabaseStore / FileStore /
+ *       RedisStore / NullStore / MemoizedStore / StorageStore / FailoverStore …)
+ * のとき保管先の型とみなす。
+ *
+ * ★**保証しないもの**: **走査根の外で宣言され、完全修飾名が組み込み Store の命名規則に
+ *   一致しない第三者の Store 実装**の直接生成・解決は検出しない
+ *   (例: `new Vendor\Package\CacheBackend()` が vendor 内で Store を実装している形)。
+ *   `Cache::extend()` の pin は **CacheManager 経由で第三者 Store の面を増やす経路**を閉じるが、
+ *   **走査根の外の第三者 Store を直接生成する / 独自のコンテナ束縛で取得する経路までは
+ *   保証しない** (「唯一の登録口」とは書かない)。
+ *   規則そのものの正負は検査 L4e が固定する。
+ */
+function cachePayloadIsStoreType(string $fqcn): bool
+{
+    if ($fqcn === 'Illuminate\Contracts\Cache\Store') {
+        return true;
+    }
+
+    return str_starts_with($fqcn, 'Illuminate\Cache\\') && str_ends_with($fqcn, 'Store');
+}
+
 /**
  * L2: キャッシュ **書き込み経路**の目録 (deny-by-default / exact-fit)。
  *
@@ -159,18 +372,114 @@
  * proof   = 往復を固定している単体テストのパス (**実在を検査する**)
  * rationale = 30 文字以上の具体的根拠
  *
+ * kind  = 'plain'          …素データを入れる本来の経路。proof は**配列往復を固定する単体テスト**
+ *         'guard-selftest' …実行時層が違反を検出することを固定するための意図的な違反。
+ *                            proof は**その検出を固定する振る舞い検査**
+ *
  * 経路が 1 本しかない現状では専用 enum (app/Enums/Security/) + inventory クラス
  * (tests/Support/Security/) へ昇格させない (AGENTS.md 思考原則 2「今必要なものだけ作る」)。
  *
- * @var array<string, array{count: int, payload: string, proof: string, rationale: string}>
+ * @var array<string, array{kind: string, count: int, payload: string, proof: string, rationale: string}>
  */
 const CACHE_PAYLOAD_WRITE_INVENTORY = [
     'app/Services/FxRateService.php::put' => [
+        'kind' => 'plain',
         'count' => 1,
         'payload' => 'FxSnapshotDto::toArray() の連想配列 (float 1 / string 3)。オブジェクトは渡さない',
         'proof' => 'tests/Unit/DataTransferObjects/FxSnapshotDtoTest.php',
         'rationale' => '当日の USD/JPY レートを 1 日 cache する。読み戻しは is_array 検査 + FxSnapshotDto::fromArray() + 失敗時 Cache::forget() で標準形どおり',
     ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::add' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass) と素データの両方。add() が末端として検査されることを固定する',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '値の末端 4 メソッドのうち add が保管前に検査されることを実 API 経由で固定する。ここが無いと申告の裏取りが機械化されない',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::flexible' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass)。flexible が putMany へ合流することの実証に使う',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '糖衣 API の合流が将来変わったら guard の被覆が静かに減るため、実 API 経由で合流を固定する 1 件',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::forever' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass) と素データの両方。forever が末端として検査されることを固定する',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '値の末端 4 メソッドのうち forever が保管前に検査されることを実 API 経由で固定する 1 件',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::offsetset' => [
+        'kind' => 'guard-selftest',
+        'count' => 2,
+        'payload' => '意図的な違反値 (stdClass) と素データの両方。$cache[$k] = $v と $cache[$k] ??= $v の 2 形',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => 'ArrayAccess 書き込みが put へ合流することを実 API 経由で固定する 2 件。静的層の添字代入検出とも対応する',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::put' => [
+        'kind' => 'guard-selftest',
+        'count' => 2,
+        'payload' => '意図的な違反値 (stdClass / Closure 等) と素データの両方。通常形と配列キー形 (putMany 相当) の 2 件',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '実行時層が「保管前の値を再帰検査して落とす」ことを実 API 経由で固定する唯一の場所。ここが無いと申告の裏取りが機械化されない',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::putmany' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass) を含む連想配列。putMany が末端として検査されることを固定する',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '値の末端 4 メソッドのうち putMany が保管前に検査されることを実 API 経由で固定する 1 件',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::remember' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass)。remember が rememberWithWarmth 経由で put へ合流することの実証に使う',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '糖衣 API の合流が将来変わったら guard の被覆が静かに減るため、実 API 経由で合流を固定する 1 件',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::rememberforever' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass)。rememberForever が forever へ合流することの実証に使う',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '糖衣 API の合流が将来変わったら guard の被覆が静かに減るため、実 API 経由で合流を固定する 1 件',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::rememberwithwarmth' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass)。rememberWithWarmth が put へ合流することの実証に使う',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '糖衣 API の合流が将来変わったら guard の被覆が静かに減るため、実 API 経由で合流を固定する 1 件',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::sear' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass)。sear が rememberForever 経由で forever へ合流することの実証に使う',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '糖衣 API の合流が将来変わったら guard の被覆が静かに減るため、実 API 経由で合流を固定する 1 件',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::set' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass)。PSR-16 の set が put へ合流することの実証に使う',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '糖衣 API の合流が将来変わったら guard の被覆が静かに減るため、実 API 経由で合流を固定する 1 件',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::setmultiple' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass)。PSR-16 の setMultiple が putMany へ合流することの実証に使う',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '糖衣 API の合流が将来変わったら guard の被覆が静かに減るため、実 API 経由で合流を固定する 1 件',
+    ],
+    'tests/Support/Cache/BootTimeCacheWriteProbeProvider.php::put' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '起動中に意図的に入れるオブジェクト (stdClass)。provider 自身が例外を握り潰す',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '起動 (bootstrap) 中の書き込みも guard が捕まえることを固定するための見本。結線点が beforeEach へ後退したら赤くなる',
+    ],
 ];
 
 /**
@@ -183,7 +492,11 @@
  *       no-payload-write = キャッシュに触れるが任意 payload を書く API を呼ばない (読み出し / 削除 / flush 等) /
  *       lock-only = 排他だけ /
  *       driver-handoff = 受け手 (driver/store) を解決するだけで、読み出し・書き込み・削除の
- *       いずれも行わず他のコンポーネントへそのまま渡す (T215: キューワーカーへの cache 注入が該当)
+ *       いずれも行わず他のコンポーネントへそのまま渡す (T215: キューワーカーへの cache 注入が該当) /
+ *       guard-implementation = 実行時層の実装そのもの。受け手型を**参照するだけ**で
+ *       キャッシュ API は 1 件も呼ばない (tests/Support/Cache/ 配下でだけ名乗れる) /
+ *       boundary-selftest = 境界迂回が hard fail することを固定する唯一の呼び出し元
+ *       (CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE ちょうどでだけ名乗れる)
  * ※「read-only」ではなく no-payload-write と呼ぶ。forget / flush を含む実態と名前を一致させるため
  *
  * @var array<string, array{role: string, rationale: string}>
@@ -217,12 +530,87 @@
         'role' => 'lock-only',
         'rationale' => '突き合わせコマンドの多重起動を再現するため Cache::lock を先取するのみ。payload は書かない',
     ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php' => [
+        'role' => 'write',
+        'rationale' => '実行時層の振る舞い検査。意図的に違反する値を書いて guard が落とすことを固定する唯一のファイル',
+    ],
     'tests/Feature/Queue/DeferredRetryHorizonTest.php' => [
         'role' => 'driver-handoff',
         'rationale' => 'Worker::setCache() へ渡すため app(\'cache\')->driver() で driver を解決するだけで、読み出し・書き込み・削除のいずれも行わない。未処理例外の計数は framework 側が整数で行う',
     ],
+    'tests/Support/Cache/BootTimeCacheWriteProbeProvider.php' => [
+        'role' => 'write',
+        'rationale' => '起動中の書き込みを guard が捕まえることを固定する見本 provider。boot() で意図的にオブジェクトを入れる',
+    ],
+    'tests/Support/Cache/GuardedBoundaryProbe.php' => [
+        'role' => 'boundary-selftest',
+        'rationale' => '境界迂回が hard fail することを固定する唯一の呼び出し元。L4 の自己テスト目録に登録できるのはこのファイルだけ',
+    ],
+    'tests/Support/Cache/PlainDataCacheGuard.php' => [
+        'role' => 'guard-implementation',
+        'rationale' => '実行時層の結線と accumulator。Repository::$macros の pin のために Repository を参照するだけで API は呼ばない',
+    ],
+    'tests/Support/Cache/PlainDataGuardedCacheManager.php' => [
+        'role' => 'guard-implementation',
+        'rationale' => '実行時層の manager。Store 型を参照してよい唯一のサイトで、repository() を override して受け皿を差し替える',
+    ],
+    'tests/Support/Cache/PlainDataGuardedRepository.php' => [
+        'role' => 'guard-implementation',
+        'rationale' => '実行時層の受け皿。Illuminate\Cache\Repository を継承して末端 4 メソッドを検査する。キャッシュ API 呼び出しは持たない',
+    ],
 ];
 
+/**
+ * L4c: guard 付き manager が保管先 (`$store`) を受け皿の第 1 引数以外へ流していないか。
+ *
+ * `$store` の出現は次の 2 か所ちょうどでなければならない (純関数。合成入力にも当てられる)。
+ *   (1) `Store $store` の型宣言の直後
+ *   (2) `new PlainDataGuardedRepository($store, …)` の**第 1 引数**
+ *
+ * ★(2) は「直前が `(`」だけでは足りない — 任意の関数呼び出しの第 1 引数でも通ってしまう。
+ *   `new` + 受け皿クラス名 + `(` の直後であることまで確認する。
+ *
+ * @return list<string> 違反理由。空なら整合
+ */
+function cachePayloadStoreLeakViolations(string $source): array
+{
+    /** @var list<PhpToken> $tokens */
+    $tokens = PhpToken::tokenize($source);
+
+    $occurrences = [];
+    $count = count($tokens);
+    for ($i = 0; $i < $count; $i++) {
+        if (! $tokens[$i]->is(T_VARIABLE) || $tokens[$i]->text !== '$store') {
+            continue;
+        }
+
+        $prev = cachePayloadPrev($tokens, $i - 1);
+        if ($prev !== null && $tokens[$prev]->text === 'Store') {
+            $occurrences[] = 'declaration';
+
+            continue;
+        }
+
+        // `new PlainDataGuardedRepository(` の直後 = 第 1 引数
+        $open = $prev;
+        $class = $open === null ? null : cachePayloadPrev($tokens, $open - 1);
+        $new = $class === null ? null : cachePayloadPrev($tokens, $class - 1);
+        $isFirstConstructorArgument = $open !== null && $tokens[$open]->text === '('
+            && $class !== null && $tokens[$class]->text === 'PlainDataGuardedRepository'
+            && $new !== null && $tokens[$new]->is(T_NEW);
+
+        $occurrences[] = $isFirstConstructorArgument
+            ? 'repository-first-argument'
+            : "leak@line{$tokens[$i]->line}";
+    }
+
+    if ($occurrences !== ['declaration', 'repository-first-argument']) {
+        return ['$store の出現が期待と一致しません: '.implode(' / ', $occurrences)];
+    }
+
+    return [];
+}
+
 /**
  * 走査対象の PHP ファイル一覧。
  *
@@ -316,8 +704,93 @@ function cachePayloadMatchingParen(array $tokens, int $open): ?int
 }
 
 /**
- * `use A\B\C;` / `use A\B\C as D;` から alias => FQCN の表を作る。
- * グループ use (`use A\{B, C};`) は本リポジトリに存在しないため扱わない (限界として冒頭に明記)。
+ * `[` の対応する `]` の index。
+ *
+ * @param  list<PhpToken>  $tokens
+ */
+function cachePayloadMatchingBracket(array $tokens, int $open): ?int
+{
+    $depth = 0;
+    $count = count($tokens);
+    for ($i = $open; $i < $count; $i++) {
+        if ($tokens[$i]->text === '[') {
+            $depth++;
+        } elseif ($tokens[$i]->text === ']') {
+            $depth--;
+            if ($depth === 0) {
+                return $i;
+            }
+        }
+    }
+
+    return null;
+}
+
+/**
+ * `extends A` / `implements A, B` の宣言句を読み、カンマ区切りの各名前を解決して返す。
+ *
+ * ★直前 token だけを見る形では不十分 — `class X implements SomeInterface, Store {}` の
+ *   `Store` の直前は `,` である。そこで T_EXTENDS / T_IMPLEMENTS を見つけたら
+ *   **宣言句全体 (`{` まで)** を読む。**解決できない名前は候補から外さず `null` で返す**
+ *   (未解決を落とす = AGENTS.md 走査規約 (b))。
+ *
+ * @param  list<PhpToken>  $tokens
+ * @param  array<string, string>  $useMap
+ * @return list<array{keyword: string, resolved: string|null, line: int}>
+ */
+function cachePayloadInheritanceClause(array $tokens, int $keywordIndex, array $useMap, string $namespace = ''): array
+{
+    $keyword = strtolower($tokens[$keywordIndex]->text);
+    $declared = [];
+    $count = count($tokens);
+
+    for ($i = $keywordIndex + 1; $i < $count; $i++) {
+        $token = $tokens[$i];
+        if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
+            continue;
+        }
+        if ($token->text === '{' || $token->text === ';') {
+            break;
+        }
+        if ($token->text === ',') {
+            continue;
+        }
+        if ($token->is(T_IMPLEMENTS)) {
+            // `class X extends A implements B` の切り替え。implements 側は
+            // T_IMPLEMENTS を起点とする別の呼び出しが読むので、ここでは打ち切る (二重記録の防止)。
+            break;
+        }
+        if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])) {
+            $declared[] = [
+                'keyword' => $keyword,
+                'resolved' => cachePayloadResolveName($token->text, $useMap, $namespace),
+                'line' => $token->line,
+            ];
+
+            continue;
+        }
+
+        // 予期しない token (可変長の型構文など)。解決できない形として落とす。
+        $declared[] = ['keyword' => $keyword, 'resolved' => null, 'line' => $token->line];
+    }
+
+    return $declared;
+}
+
+/**
+ * `use A\B\C;` / `use A\B\C as D;` / `use A\B\{C, D as E};` から alias => FQCN の表を作る。
+ *
+ * ★グループ use も解決する。**この走査器自身が完全修飾名へ解決できること**が要る
+ *   (AGENTS.md 走査規約 (a))。「別の gate がグループ use を禁じているから」に依存すると、
+ *   本 gate 単体では fail-closed にならない。
+ *
+ * ★読むのは**名前空間スコープの取り込みだけ**である。波括弧の深さを追い、
+ *   名前空間の直下にある `use` だけを集める。型宣言の本体に入った後の `use` は
+ *   trait の取り込みであり、同名の取り込みを上書きすると名前解決が壊れる
+ *   (`use X as Guarded;` の後で `class T { use \Other\Guarded; }` があると、
+ *   `class Bypass extends Guarded {}` が別クラスへ解決されて継承禁止をすり抜ける)。
+ *   **「最初の型宣言で打ち切る」形は誤り**である — PHP は型宣言の**後ろ**にも
+ *   名前空間スコープの取り込みを置けるので、後置の取り込みを丸ごと落としてしまう。
  *
  * @param  list<PhpToken>  $tokens
  * @return array<string, string>
@@ -326,25 +799,101 @@ function cachePayloadUseMap(array $tokens): array
 {
     $map = [];
     $count = count($tokens);
+    $baseDepth = cachePayloadNamespaceBodyDepth($tokens);
+    $depth = 0;
+
     for ($i = 0; $i < $count; $i++) {
-        if (! $tokens[$i]->is(T_USE)) {
+        if ($tokens[$i]->text === '{') {
+            $depth++;
+
             continue;
         }
-        $nameIndex = cachePayloadNext($tokens, $i + 1);
-        if ($nameIndex === null || ! $tokens[$nameIndex]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
-            continue; // closure の use(...) など
+        if ($tokens[$i]->text === '}') {
+            $depth--;
+
+            continue;
         }
-        $fqcn = ltrim($tokens[$nameIndex]->text, '\\');
-        $alias = str_contains($fqcn, '\\') ? substr((string) strrchr($fqcn, '\\'), 1) : $fqcn;
+        if (! $tokens[$i]->is(T_USE) || $depth !== $baseDepth) {
+            continue;
+        }
+
+        // ★`use function Foo\bar;` / `use const Foo\BAZ;` は**クラスの取り込みではない**。
+        //   文ごと読み飛ばす (末尾の名前を alias として登録すると、同名のクラス取り込みを
+        //   上書きして名前解決を壊す)。
+        $head = cachePayloadNext($tokens, $i + 1);
+        if ($head !== null && $tokens[$head]->is([T_FUNCTION, T_CONST])) {
+            continue;
+        }
+
+        $prefix = '';
+        $pending = null;
+        $skipMember = false;
+
+        for ($j = $i + 1; $j < $count; $j++) {
+            $token = $tokens[$j];
+            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
+                continue;
+            }
+            if ($token->text === ';' || $token->text === '(') {
+                break; // 文の終わり / closure の use(...)
+            }
+            if ($token->is([T_FUNCTION, T_CONST])) {
+                // グループ use の中の `function foo` / `const BAR` (混在指定)
+                $skipMember = true;
+                $pending = null;
+
+                continue;
+            }
+            if ($skipMember && $token->text !== ',' && $token->text !== '}') {
+                continue;
+            }
+            if ($token->text === '{') {
+                // グループ use の開始。直前の名前が接頭辞になる
+                $prefix = $pending === null ? '' : rtrim($pending, '\\').'\\';
+                $pending = null;
+
+                continue;
+            }
+            if ($token->text === '}' || $token->text === ',') {
+                if ($pending !== null && ! $skipMember) {
+                    $fqcn = $prefix.$pending;
+                    $map[str_contains($fqcn, '\\') ? substr((string) strrchr($fqcn, '\\'), 1) : $fqcn] = $fqcn;
+                }
+                $pending = null;
+                $skipMember = false;
+                if ($token->text === '}') {
+                    break;
+                }
+
+                continue;
+            }
+            if ($token->is(T_AS)) {
+                $aliasIndex = cachePayloadNext($tokens, $j + 1);
+                if ($aliasIndex !== null && $tokens[$aliasIndex]->is(T_STRING) && $pending !== null) {
+                    $map[$tokens[$aliasIndex]->text] = $prefix.$pending;
+                    $pending = null;
+                    $j = $aliasIndex;
+                }
 
-        $asIndex = cachePayloadNext($tokens, $nameIndex + 1);
-        if ($asIndex !== null && $tokens[$asIndex]->is(T_AS)) {
-            $aliasIndex = cachePayloadNext($tokens, $asIndex + 1);
-            if ($aliasIndex !== null && $tokens[$aliasIndex]->is(T_STRING)) {
-                $alias = $tokens[$aliasIndex]->text;
+                continue;
+            }
+            if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
+                $pending = ltrim($token->text, '\\');
+
+                continue;
+            }
+            if ($token->is(T_NS_SEPARATOR)) {
+                continue; // グループ use の `A\B\{` の区切り
             }
+
+            // `use function foo;` / `use const BAR;` など。名前として扱わない
+            $pending = null;
+        }
+
+        if ($pending !== null && ! $skipMember) {
+            $fqcn = $prefix.$pending;
+            $map[str_contains($fqcn, '\\') ? substr((string) strrchr($fqcn, '\\'), 1) : $fqcn] = $fqcn;
         }
-        $map[$alias] = $fqcn;
     }
 
     return $map;
@@ -364,26 +913,123 @@ function cachePayloadUseMap(array $tokens): array
  *
  * @param  array<string, string>  $useMap
  */
-function cachePayloadResolveName(string $raw, array $useMap): string
+function cachePayloadResolveName(string $raw, array $useMap, string $namespace = ''): string
 {
+    $isFullyQualified = str_starts_with($raw, '\\');
     $name = ltrim($raw, '\\');
+
+    // `namespace\Foo` (T_NAME_RELATIVE) は現在の名前空間からの相対指定である
+    if (! $isFullyQualified && str_starts_with(strtolower($name), 'namespace\\')) {
+        $rest = substr($name, strlen('namespace\\'));
+
+        return $namespace === '' ? $rest : $namespace.'\\'.$rest;
+    }
+
     if (isset($useMap[$name])) {
-        $name = $useMap[$name];
-    } elseif (str_contains($name, '\\')) {
+        $resolved = $useMap[$name];
+
+        // `use Cache;` (root 名前空間の class alias の取り込み) も facade とみなす
+        return strtolower($resolved) === 'cache' ? 'Illuminate\Support\Facades\Cache' : $resolved;
+    }
+
+    if (str_contains($name, '\\')) {
         $head = strstr($name, '\\', true);
         if (is_string($head) && isset($useMap[$head])) {
-            $name = $useMap[$head].substr($name, strlen($head));
+            return $useMap[$head].substr($name, strlen($head));
         }
     }
 
-    // 名前空間を持たない `Cache` は class alias 経由の facade (`use Cache;` を含む)
+    // 名前空間を持たない `Cache` は class alias 経由の facade (`use Cache;` を含む)。
+    // ★これは**安全側への過剰検出**である (PHP はクラス名を global へ落とさないので、
+    //   名前空間の中の裸の `Cache` は本来 `<現在の名前空間>\Cache` を指す)。
     if (! str_contains($name, '\\') && strtolower($name) === 'cache') {
         return 'Illuminate\Support\Facades\Cache';
     }
 
+    // ★取り込みにも無い非完全修飾名は**現在の名前空間からの相対**である。
+    //   ここを飛ばすと `namespace Tests\Support\Cache; class X extends PlainDataGuardedRepository {}`
+    //   のような**同一名前空間の短名**が完全修飾名へ解決できず、継承禁止をすり抜ける
+    //   (AGENTS.md 走査規約 (a): クラス参照は完全修飾名で突き合わせる)。
+    if (! $isFullyQualified && $namespace !== '') {
+        return $namespace.'\\'.$name;
+    }
+
     return $name;
 }
 
+/**
+ * ファイルが宣言している名前空間の数。
+ *
+ * ★1 ファイルに複数の名前空間を置くと、取り込み表を名前空間ごとに持ち分けない限り
+ *   別の名前空間の同名の別名で上書きできてしまう (継承先を誤解して母集団から外れる)。
+ *   本走査器は**単一の名前空間だけを解決対象**とし、複数宣言は未分類として落とす。
+ *
+ * @param  list<PhpToken>  $tokens
+ */
+function cachePayloadNamespaceDeclarationCount(array $tokens): int
+{
+    $count = count($tokens);
+    $declarations = 0;
+    for ($i = 0; $i < $count; $i++) {
+        if ($tokens[$i]->is(T_NAMESPACE)) {
+            $declarations++;
+        }
+    }
+
+    return $declarations;
+}
+
+/**
+ * 名前空間の**本体の波括弧の深さ**。
+ *
+ * `namespace A\B;` (セミコロン形) なら 0、`namespace A\B { … }` (波括弧形) なら 1 である。
+ * 取り込み表はこの深さにある `use` だけを読む。
+ *
+ * @param  list<PhpToken>  $tokens
+ */
+function cachePayloadNamespaceBodyDepth(array $tokens): int
+{
+    $count = count($tokens);
+    for ($i = 0; $i < $count; $i++) {
+        if (! $tokens[$i]->is(T_NAMESPACE)) {
+            continue;
+        }
+        for ($j = $i + 1; $j < $count; $j++) {
+            if ($tokens[$j]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR])) {
+                continue;
+            }
+
+            return $tokens[$j]->text === '{' ? 1 : 0;
+        }
+    }
+
+    return 0;
+}
+
+/**
+ * ファイル先頭の `namespace A\B;` を取り出す (無ければ空文字)。
+ *
+ * @param  list<PhpToken>  $tokens
+ */
+function cachePayloadNamespace(array $tokens): string
+{
+    $count = count($tokens);
+    for ($i = 0; $i < $count; $i++) {
+        if (! $tokens[$i]->is(T_NAMESPACE)) {
+            continue;
+        }
+        $nameIndex = cachePayloadNext($tokens, $i + 1);
+        if ($nameIndex === null || ! $tokens[$nameIndex]->is([T_STRING, T_NAME_QUALIFIED])) {
+            // `namespace\Foo` (相対参照) や無名前空間ブロック。名前空間の宣言ではない
+            continue;
+        }
+
+        return ltrim($tokens[$nameIndex]->text, '\\');
+    }
+
+    return '';
+}
+
 /**
  * 同一ファイル内で「キャッシュ型として宣言された名前」を集める。
  *
@@ -395,15 +1041,15 @@ function cachePayloadResolveName(string $raw, array $useMap): string
  * @param  array<string, string>  $useMap
  * @return list<string> `$` を除いた名前
  */
-function cachePayloadReceiverNames(array $tokens, array $useMap): array
+function cachePayloadReceiverNames(array $tokens, array $useMap, string $namespace = ''): array
 {
     $names = [];
     $count = count($tokens);
     for ($i = 0; $i < $count; $i++) {
-        if (! $tokens[$i]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
+        if (! $tokens[$i]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])) {
             continue;
         }
-        if (! in_array(cachePayloadResolveName($tokens[$i]->text, $useMap), CACHE_PAYLOAD_RECEIVER_TYPES, true)) {
+        if (! in_array(cachePayloadResolveName($tokens[$i]->text, $useMap, $namespace), CACHE_PAYLOAD_RECEIVER_TYPES, true)) {
             continue;
         }
         // 型宣言の直後 (union / nullable / intersection / DNF の括弧を跨いで) 最初に現れる変数
@@ -449,7 +1095,7 @@ function cachePayloadLiteralValue(string $raw): ?string
  *     素通りさせる理由が無いので `unclassified` として fail させる (実測 0 件)
  *
  * @param  list<PhpToken>  $tokens
- * @return list<array{method: string, line: int, kind: string}> kind: write|non_write|chain|terminal|unclassified
+ * @return list<array{method: string, line: int, kind: string}> kind: write|non_write|chain|terminal|bypass|unclassified
  */
 function cachePayloadFollowChain(array $tokens, int $operatorIndex): array
 {
@@ -506,6 +1152,7 @@ function cachePayloadFollowChain(array $tokens, int $operatorIndex): array
             in_array($method, CACHE_PAYLOAD_WRITE_METHODS, true) => 'write',
             in_array($method, CACHE_PAYLOAD_NON_WRITE_METHODS, true) => 'non_write',
             in_array($method, CACHE_PAYLOAD_CHAIN_METHODS, true) => 'chain',
+            in_array($method, CACHE_PAYLOAD_BYPASS_METHODS, true) => 'bypass',
             in_array($method, CACHE_PAYLOAD_TERMINAL_METHODS, true) => 'terminal',
             default => 'unclassified',
         };
@@ -536,7 +1183,7 @@ function cachePayloadFollowChain(array $tokens, int $operatorIndex): array
  * @param  list<PhpToken>  $tokens
  * @param  array<string, string>  $useMap
  */
-function cachePayloadIsCacheBindingArg(array $tokens, ?int $firstArg, array $useMap): bool
+function cachePayloadIsCacheBindingArg(array $tokens, ?int $firstArg, array $useMap, string $namespace = ''): bool
 {
     if ($firstArg === null) {
         return false;
@@ -555,7 +1202,7 @@ function cachePayloadIsCacheBindingArg(array $tokens, ?int $firstArg, array $use
         && $classToken !== null && strtolower($tokens[$classToken]->text) === 'class';
 
     return $isClassConst && in_array(
-        cachePayloadResolveName($tokens[$firstArg]->text, $useMap),
+        cachePayloadResolveName($tokens[$firstArg]->text, $useMap, $namespace),
         CACHE_PAYLOAD_RECEIVER_TYPES,
         true
     );
@@ -573,7 +1220,7 @@ function cachePayloadIsCacheBindingArg(array $tokens, ?int $firstArg, array $use
  * @param  array<string, string>  $useMap
  * @param  int  $closeIndex  `app()` の閉じ括弧 index
  */
-function cachePayloadContainerMakeChain(array $tokens, int $closeIndex, array $useMap): ?int
+function cachePayloadContainerMakeChain(array $tokens, int $closeIndex, array $useMap, string $namespace = ''): ?int
 {
     $arrow = cachePayloadNext($tokens, $closeIndex + 1);
     if ($arrow === null || ! $tokens[$arrow]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
@@ -588,7 +1235,7 @@ function cachePayloadContainerMakeChain(array $tokens, int $closeIndex, array $u
     if ($open === null || $tokens[$open]->text !== '(') {
         return null;
     }
-    if (! cachePayloadIsCacheBindingArg($tokens, cachePayloadNext($tokens, $open + 1), $useMap)) {
+    if (! cachePayloadIsCacheBindingArg($tokens, cachePayloadNext($tokens, $open + 1), $useMap, $namespace)) {
         return null;
     }
     $close = cachePayloadMatchingParen($tokens, $open);
@@ -606,23 +1253,41 @@ function cachePayloadContainerMakeChain(array $tokens, int $closeIndex, array $u
  * `writes` は **構造体**で返す (文字列に畳んでから再パースすると `strrchr` 等で壊れるため)。
  * ヘルパの配列形 `cache([...], $ttl)` は method 名 `cache` として記録する。
  *
- * @return array{writes: list<array{relative: string, line: int, method: string}>, unclassified: list<string>, methods: list<string>, cacheCalls: int, methodCalls: int, surface: bool}
+ * @return array{writes: list<array{relative: string, line: int, method: string}>, unclassified: list<string>, methods: list<string>, bypasses: list<string>, bypassCounts: array<string, int>, subclassDeclarations: list<string>, dynamicNewSites: list<string>, dynamicNewCounts: array<string, int>, cacheCalls: int, methodCalls: int, surface: bool}
  */
 function cachePayloadCollectFromSource(string $source, string $relative): array
 {
     /** @var list<PhpToken> $tokens */
     $tokens = PhpToken::tokenize($source);
     $useMap = cachePayloadUseMap($tokens);
-    $receiverNames = cachePayloadReceiverNames($tokens, $useMap);
+    $namespace = cachePayloadNamespace($tokens);
+    $receiverNames = cachePayloadReceiverNames($tokens, $useMap, $namespace);
+    $namespaceDeclarations = cachePayloadNamespaceDeclarationCount($tokens);
 
     $writes = [];
     $unclassified = [];
     $methods = [];
+    /** @var list<string> $bypasses */
+    $bypasses = [];
+    /** @var array<string, int> $bypassCounts */
+    $bypassCounts = [];
+    /** @var list<string> $subclassDeclarations */
+    $subclassDeclarations = [];
+    /** @var list<string> $dynamicNewSites */
+    $dynamicNewSites = [];
+    /** @var array<string, int> $dynamicNewCounts */
+    $dynamicNewCounts = [];
     $cacheCalls = 0;
     $methodCalls = 0;
     $surface = false;
     $count = count($tokens);
 
+    /** 迂回 1 件を記録する (目録の key は解決済みの完全修飾名で作る)。 */
+    $recordBypass = function (string $key, string $site) use (&$bypasses, &$bypassCounts): void {
+        $bypasses[] = $site;
+        $bypassCounts[$key] = ($bypassCounts[$key] ?? 0) + 1;
+    };
+
     for ($i = 0; $i < $count; $i++) {
         $token = $tokens[$i];
 
@@ -636,13 +1301,51 @@ function cachePayloadCollectFromSource(string $source, string $relative): array
             }
         }
 
+        // L4b の fail-closed: `new` の対象が**名前として解決できない**形を落とす。
+        // `new $class` / `new ($expr)` は生成されるクラスが静的に決まらないため、
+        // 保管先の直接生成を隠せてしまう (`$store = new $class; $store->put(...)` は
+        // 受け手型の宣言も持たないので L2 にも現れない)。
+        // ★走査根の全体で deny-by-default にし、キャッシュと無関係な既知の用途は
+        //   CACHE_PAYLOAD_DYNAMIC_NEW_INVENTORY へ理由付きで exact-fit 登録する
+        //   (「この動的生成はキャッシュの保管先ではない」という申告になる)。
+        // 無名クラス (`new class extends Repository {}`) は T_EXTENDS の分岐が受け持つ。
+        if ($token->is(T_NEW)) {
+            $beforeNew = cachePayloadPrev($tokens, $i - 1);
+            $isMethodNamedNew = $beforeNew !== null
+                && $tokens[$beforeNew]->is([T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]);
+            $target = cachePayloadNext($tokens, $i + 1);
+            $isResolvableTarget = $target !== null && $tokens[$target]->is([
+                T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_CLASS, T_STATIC,
+            ]);
+            if (! $isMethodNamedNew && ! $isResolvableTarget) {
+                $dynamicNewSites[] = "{$relative}:{$token->line} → new <静的に解決できないクラス名>";
+                $dynamicNewCounts[$relative] = ($dynamicNewCounts[$relative] ?? 0) + 1;
+            }
+        }
+
+        // L4d: 受け手型 / 保管先型の継承・実装の宣言 (宣言側で塞ぐ)。
+        if ($token->is([T_EXTENDS, T_IMPLEMENTS])) {
+            foreach (cachePayloadInheritanceClause($tokens, $i, $useMap, $namespace) as $declared) {
+                if ($declared['resolved'] === null) {
+                    $unclassified[] = "{$relative}:{$declared['line']} → extends/implements <解決できない名前>";
+
+                    continue;
+                }
+                if (in_array($declared['resolved'], CACHE_PAYLOAD_RECEIVER_TYPES, true)
+                    || in_array($declared['resolved'], CACHE_PAYLOAD_GUARD_IMPLEMENTATION_TYPES, true)
+                    || cachePayloadIsStoreType($declared['resolved'])) {
+                    $subclassDeclarations[] = "{$relative}::{$declared['keyword']} {$declared['resolved']}";
+                }
+            }
+        }
+
         $operatorIndex = null;
 
-        if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
+        if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])) {
             $prev = cachePayloadPrev($tokens, $i - 1);
             $isMemberName = $prev !== null
                 && $tokens[$prev]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST]);
-            $resolved = cachePayloadResolveName($token->text, $useMap);
+            $resolved = cachePayloadResolveName($token->text, $useMap, $namespace);
             // ★グローバル関数の呼び出し名は先頭 `\` を落として比較する。
             //   `\cache([...], 60)` は T_NAME_FULLY_QUALIFIED (text = '\cache') なので、
             //   素の text 比較だと**ヘルパ書き込みの完全修飾形が丸ごと素通り**する。
@@ -651,7 +1354,9 @@ function cachePayloadCollectFromSource(string $source, string $relative): array
             $isRootCallable = ! str_contains($callable, '\\');
             $lower = $isRootCallable ? $callable : '';
 
-            if (! $isMemberName && in_array($resolved, CACHE_PAYLOAD_RECEIVER_TYPES, true)) {
+            $isReceiverType = ! $isMemberName && in_array($resolved, CACHE_PAYLOAD_RECEIVER_TYPES, true);
+
+            if ($isReceiverType) {
                 $surface = true; // use 文・型宣言・::class 参照でも「面」としては hit する
                 $next = cachePayloadNext($tokens, $i + 1);
                 if ($next !== null && $tokens[$next]->is(T_DOUBLE_COLON)) {
@@ -661,6 +1366,23 @@ function cachePayloadCollectFromSource(string $source, string $relative): array
                 }
             }
 
+            $isStoreType = ! $isMemberName && cachePayloadIsStoreType($resolved);
+            if ($isStoreType) {
+                // 具体 store の名前に触れているファイルも「面」に数える
+                // (受け皿を自前で組み立てる材料に触れている、という事実は目録へ出す)。
+                $surface = true;
+            }
+
+            // L4b: 受け手型 / 保管先型の**直接生成**。受け皿を自前で作られると
+            //      guard 付き manager を通らない受け皿が生まれる。
+            if (($isReceiverType || $isStoreType)
+                && $prev !== null && $tokens[$prev]->is(T_NEW)) {
+                $recordBypass(
+                    "{$relative}::new {$resolved}",
+                    "{$relative}:{$token->line} → new {$resolved}",
+                );
+            }
+
             if (! $isMemberName && $lower === 'cache') {
                 $open = cachePayloadNext($tokens, $i + 1);
                 if ($open !== null && $tokens[$open]->text === '(') {
@@ -696,7 +1418,7 @@ function cachePayloadCollectFromSource(string $source, string $relative): array
                 $firstArg = $hasParen ? cachePayloadNext($tokens, $open + 1) : null;
                 $close = $hasParen && $open !== null ? cachePayloadMatchingParen($tokens, $open) : null;
 
-                if ($hasParen && cachePayloadIsCacheBindingArg($tokens, $firstArg, $useMap)) {
+                if ($hasParen && cachePayloadIsCacheBindingArg($tokens, $firstArg, $useMap, $namespace)) {
                     // app('cache')->put(...) / app(Repository::class)->put(...)
                     $surface = true;
                     $next = $close === null ? null : cachePayloadNext($tokens, $close + 1);
@@ -705,7 +1427,7 @@ function cachePayloadCollectFromSource(string $source, string $relative): array
                     }
                 } elseif ($hasParen && $close !== null && $firstArg === $close && $lower === 'app') {
                     // app()->make('cache')->put(...) 形。コンテナ経由の 1 段追加
-                    $chained = cachePayloadContainerMakeChain($tokens, $close, $useMap);
+                    $chained = cachePayloadContainerMakeChain($tokens, $close, $useMap, $namespace);
                     if ($chained !== null) {
                         $surface = true;
                         $operatorIndex = $chained;
@@ -734,6 +1456,20 @@ function cachePayloadCollectFromSource(string $source, string $relative): array
                 if ($arrow !== null && $tokens[$arrow]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                     $operatorIndex = $arrow; // $cache->put(...)
                     $surface = true;
+                } elseif ($arrow !== null && $tokens[$arrow]->text === '[') {
+                    // ArrayAccess 書き込み (`$cache['k'] = $v` / `$cache['k'] ??= $v`)。
+                    // メソッド呼び出し走査では検出できないので専用の分岐を持つ。
+                    $closeBracket = cachePayloadMatchingBracket($tokens, $arrow);
+                    $assign = $closeBracket === null ? null : cachePayloadNext($tokens, $closeBracket + 1);
+                    if ($assign !== null && in_array($tokens[$assign]->text, ['=', '??='], true)) {
+                        $surface = true;
+                        $cacheCalls++;
+                        $writes[] = ['relative' => $relative, 'line' => $token->line, 'method' => 'offsetSet'];
+                        $methods[] = 'offsetset';
+                    } elseif ($closeBracket === null) {
+                        // ★対応する `]` を見つけられない = 解決できない形。見逃さずに落とす。
+                        $unclassified[] = "{$relative}:{$token->line} → \${$name}[…] (対応する ] を解決できない)";
+                    }
                 }
             }
         }
@@ -745,18 +1481,47 @@ function cachePayloadCollectFromSource(string $source, string $relative): array
         foreach (cachePayloadFollowChain($tokens, $operatorIndex) as $call) {
             $cacheCalls++;
             $methods[] = $call['method'];
+            $key = $relative.'::'.strtolower($call['method']);
+
             if ($call['kind'] === 'write') {
                 $writes[] = ['relative' => $relative, 'line' => $call['line'], 'method' => $call['method']];
+            } elseif ($call['kind'] === 'bypass') {
+                $recordBypass($key, "{$relative}:{$call['line']} → ->{$call['method']}()");
             } elseif ($call['kind'] === 'unclassified') {
-                $unclassified[] = "{$relative}:{$call['line']} → ->{$call['method']}()";
+                // ★実行時層は保管先への素通し (__call) を落とすため、その自己テストは
+                //   「4 分類のどれでもない API 名」を意図的に呼ぶ。自己テスト目録に
+                //   登録済みの呼び出しだけを迂回として数え、それ以外は従来どおり落とす。
+                if (array_key_exists($key, CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY)) {
+                    $recordBypass($key, "{$relative}:{$call['line']} → ->{$call['method']}()");
+                } else {
+                    $unclassified[] = "{$relative}:{$call['line']} → ->{$call['method']}()";
+                }
             }
         }
     }
 
+    // ★1 ファイルに複数の名前空間があると、取り込み表を持ち分けない限り
+    //   別の名前空間の同名の別名で上書きできる。**解決できない形として落とす**。
+    if ($namespaceDeclarations > 1) {
+        $unclassified[] = "{$relative} → 1 ファイルに名前空間が {$namespaceDeclarations} 個あり、"
+            .'取り込み表を名前空間ごとに解決できません (1 ファイル 1 名前空間にしてください)';
+    }
+
+    sort($bypasses);
+    ksort($bypassCounts);
+    sort($subclassDeclarations);
+    sort($dynamicNewSites);
+    ksort($dynamicNewCounts);
+
     return [
         'writes' => $writes,
         'unclassified' => $unclassified,
         'methods' => $methods,
+        'bypasses' => $bypasses,
+        'bypassCounts' => $bypassCounts,
+        'subclassDeclarations' => $subclassDeclarations,
+        'dynamicNewSites' => $dynamicNewSites,
+        'dynamicNewCounts' => $dynamicNewCounts,
         'cacheCalls' => $cacheCalls,
         'methodCalls' => $methodCalls,
         'surface' => $surface,
@@ -777,16 +1542,57 @@ function cachePayloadCollectFromSource(string $source, string $relative): array
  *                      T215: `Worker::setCache()` へ渡すためだけに `app('cache')->driver()` を呼ぶ形が該当)
  *
  * @param  list<string>  $methods  実測メソッド (全小文字)
+ * @param  string  $path  宣言されたファイル (役割を任意のファイルが名乗れないようにするため)
  * @return list<string> 違反理由。空なら整合
  */
-function cachePayloadRoleViolations(string $role, array $methods, bool $hasWriteEntry): array
+function cachePayloadRoleViolations(string $role, array $methods, bool $hasWriteEntry, string $path = ''): array
 {
-    if (! in_array($role, ['write', 'no-payload-write', 'lock-only', 'driver-handoff'], true)) {
-        return ["role は write / no-payload-write / lock-only / driver-handoff のいずれか (宣言値: {$role})"];
+    $known = ['write', 'no-payload-write', 'lock-only', 'driver-handoff', 'guard-implementation', 'boundary-selftest'];
+    if (! in_array($role, $known, true)) {
+        return ['role は '.implode(' / ', $known)." のいずれか (宣言値: {$role})"];
     }
 
-    if ($role === 'write') {
-        return $hasWriteEntry ? [] : ['role=write なのに書き込み目録に entry がありません'];
+    if ($role === 'write') {
+        return $hasWriteEntry ? [] : ['role=write なのに書き込み目録に entry がありません'];
+    }
+
+    if ($role === 'guard-implementation') {
+        // 実行時層の実装そのもの。受け手型を**参照するだけ**で API は呼ばない、という申告である。
+        $violations = [];
+        if ($hasWriteEntry) {
+            $violations[] = 'role=guard-implementation なのに書き込み目録に entry があります';
+        }
+        if ($methods !== []) {
+            $violations[] = 'role=guard-implementation なのにキャッシュ API を呼んでいます: '.implode(', ', $methods);
+        }
+        if (! str_starts_with($path, 'tests/Support/Cache/')) {
+            $violations[] = 'role=guard-implementation は tests/Support/Cache/ 配下でだけ名乗れます: '.$path;
+        }
+
+        return $violations;
+    }
+
+    if ($role === 'boundary-selftest') {
+        // 境界迂回が hard fail することを固定する唯一の呼び出し元。
+        $violations = [];
+        if ($hasWriteEntry) {
+            $violations[] = 'role=boundary-selftest なのに書き込み目録に entry があります (payload は書かない)';
+        }
+        if ($path !== CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE) {
+            $violations[] = 'role=boundary-selftest を名乗れるのは '.CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE." だけです: {$path}";
+        }
+        $registered = false;
+        foreach (array_keys(CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY) as $key) {
+            if (str_starts_with($key, $path.'::')) {
+                $registered = true;
+                break;
+            }
+        }
+        if (! $registered) {
+            $violations[] = 'role=boundary-selftest なのに L4 の自己テスト目録に entry がありません';
+        }
+
+        return $violations;
     }
 
     $violations = [];
@@ -838,11 +1644,11 @@ function cachePayloadRoleViolations(string $role, array $methods, bool $hasWrite
 /**
  * 走査対象全体の収集結果 (同一プロセス内で 1 度だけ計算する)。
  *
- * @return array{writeCounts: array<string, int>, writeSites: list<string>, unclassified: list<string>, surfaces: array<string, list<string>>, cacheCalls: int, methodCalls: int, files: int}
+ * @return array{writeCounts: array<string, int>, writeSites: list<string>, unclassified: list<string>, surfaces: array<string, list<string>>, bypassSites: list<string>, bypassCounts: array<string, int>, subclassDeclarations: list<string>, dynamicNewSites: list<string>, dynamicNewCounts: array<string, int>, cacheCalls: int, methodCalls: int, files: int}
  */
 function cachePayloadCollectAll(): array
 {
-    /** @var array{writeCounts: array<string, int>, writeSites: list<string>, unclassified: list<string>, surfaces: array<string, list<string>>, cacheCalls: int, methodCalls: int, files: int}|null $cached */
+    /** @var array{writeCounts: array<string, int>, writeSites: list<string>, unclassified: list<string>, surfaces: array<string, list<string>>, bypassSites: list<string>, bypassCounts: array<string, int>, subclassDeclarations: list<string>, dynamicNewSites: list<string>, dynamicNewCounts: array<string, int>, cacheCalls: int, methodCalls: int, files: int}|null $cached */
     static $cached = null;
     if ($cached !== null) {
         return $cached;
@@ -852,6 +1658,16 @@ function cachePayloadCollectAll(): array
     $writeSites = [];
     $unclassified = [];
     $surfaces = [];
+    /** @var list<string> $bypassSites */
+    $bypassSites = [];
+    /** @var array<string, int> $bypassCounts */
+    $bypassCounts = [];
+    /** @var list<string> $subclassDeclarations */
+    $subclassDeclarations = [];
+    /** @var list<string> $dynamicNewSites */
+    $dynamicNewSites = [];
+    /** @var array<string, int> $dynamicNewCounts */
+    $dynamicNewCounts = [];
     $cacheCalls = 0;
     $methodCalls = 0;
     $files = 0;
@@ -872,6 +1688,15 @@ function cachePayloadCollectAll(): array
             $writeCounts[$key] = ($writeCounts[$key] ?? 0) + 1;
         }
         $unclassified = array_merge($unclassified, $collected['unclassified']);
+        $bypassSites = array_merge($bypassSites, $collected['bypasses']);
+        $subclassDeclarations = array_merge($subclassDeclarations, $collected['subclassDeclarations']);
+        $dynamicNewSites = array_merge($dynamicNewSites, $collected['dynamicNewSites']);
+        foreach ($collected['bypassCounts'] as $key => $bypassCount) {
+            $bypassCounts[$key] = ($bypassCounts[$key] ?? 0) + $bypassCount;
+        }
+        foreach ($collected['dynamicNewCounts'] as $key => $dynamicCount) {
+            $dynamicNewCounts[$key] = ($dynamicNewCounts[$key] ?? 0) + $dynamicCount;
+        }
 
         if ($collected['surface']) {
             $surfaces[$target['relative']] = $collected['methods'];
@@ -880,13 +1705,23 @@ function cachePayloadCollectAll(): array
 
     ksort($writeCounts);
     ksort($surfaces);
+    ksort($bypassCounts);
+    ksort($dynamicNewCounts);
     sort($writeSites);
+    sort($bypassSites);
+    sort($subclassDeclarations);
+    sort($dynamicNewSites);
 
     $cached = [
         'writeCounts' => $writeCounts,
         'writeSites' => $writeSites,
         'unclassified' => $unclassified,
         'surfaces' => $surfaces,
+        'bypassSites' => $bypassSites,
+        'bypassCounts' => $bypassCounts,
+        'subclassDeclarations' => $subclassDeclarations,
+        'dynamicNewSites' => $dynamicNewSites,
+        'dynamicNewCounts' => $dynamicNewCounts,
         'cacheCalls' => $cacheCalls,
         'methodCalls' => $methodCalls,
         'files' => $files,
@@ -940,10 +1775,13 @@ function cachePayloadCollectAll(): array
         // key のメソッド名は全小文字。'cache' はヘルパの配列形 cache([...], $ttl) 専用の名前。
         expect(in_array($method, CACHE_PAYLOAD_WRITE_METHODS, true) || $method === 'cache')
             ->toBeTrue("{$key}: key のメソッドが WRITE 語彙にありません");
+        expect(in_array($entry['kind'], ['plain', 'guard-selftest'], true))
+            ->toBeTrue("{$key}: kind は plain / guard-selftest のいずれか (宣言値: {$entry['kind']})");
         expect(is_file(base_path($path)))->toBeTrue("{$key}: 対象ファイルが実在しません");
         expect(is_file(base_path($entry['proof'])))->toBeTrue(
-            "{$key}: proof に指定した単体テスト {$entry['proof']} が実在しません。"
-            .'キャッシュへ入れる配列は「往復が壊れないこと」を単体テストで固定してください');
+            "{$key}: proof に指定した検査 {$entry['proof']} が実在しません。"
+            .'kind=plain はキャッシュへ入れる配列の「往復が壊れないこと」を単体テストで、'
+            .'kind=guard-selftest は「実行時層が落とすこと」を振る舞い検査で固定してください');
         expect(mb_strlen($entry['rationale']))->toBeGreaterThanOrEqual(30, "{$key}: rationale が短すぎます");
         expect(mb_strlen($entry['payload']))->toBeGreaterThanOrEqual(10, "{$key}: payload の説明が短すぎます");
     }
@@ -984,7 +1822,7 @@ function cachePayloadCollectAll(): array
             }
         }
 
-        expect(cachePayloadRoleViolations($entry['role'], $methods, $hasWrite))
+        expect(cachePayloadRoleViolations($entry['role'], $methods, $hasWrite, $path))
             ->toBe([], "{$path}: 宣言した role が実測と整合しません");
     }
 });
@@ -1021,6 +1859,25 @@ function cachePayloadCollectAll(): array
     expect(cachePayloadRoleViolations('driver-handoff', ['driver'], true))->not->toBe([]);
 
     expect(cachePayloadRoleViolations('unknown-role', ['get'], false))->not->toBe([]);
+
+    // guard-implementation (T228): 受け手型を参照するだけ。API を 1 件でも呼んだら違反、
+    // 許可パス外で名乗っても違反 (任意のファイルが迂回実装の免除に使えないようにする)。
+    $guardPath = 'tests/Support/Cache/PlainDataGuardedRepository.php';
+    expect(cachePayloadRoleViolations('guard-implementation', [], false, $guardPath))->toBe([]);
+    expect(cachePayloadRoleViolations('guard-implementation', ['put'], false, $guardPath))->not->toBe([]);
+    expect(cachePayloadRoleViolations('guard-implementation', ['get'], false, $guardPath))->not->toBe([]);
+    expect(cachePayloadRoleViolations('guard-implementation', [], true, $guardPath))->not->toBe([]);
+    expect(cachePayloadRoleViolations('guard-implementation', [], false, 'app/Services/FxRateService.php'))
+        ->not->toBe([]);
+
+    // boundary-selftest (T228): 名指しの 1 ファイルだけが名乗れ、L4 の自己テスト目録に
+    // entry を持ち、L2 の書き込み目録には entry を持たない。
+    expect(cachePayloadRoleViolations('boundary-selftest', ['tags'], false, CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE))
+        ->toBe([]);
+    expect(cachePayloadRoleViolations('boundary-selftest', ['tags'], true, CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE))
+        ->not->toBe([]);
+    expect(cachePayloadRoleViolations('boundary-selftest', ['tags'], false, 'tests/Support/Cache/OtherProbe.php'))
+        ->not->toBe([]);
 });
 
 // ---------------------------------------------------------------------------
@@ -1040,13 +1897,14 @@ function cachePayloadCollectAll(): array
     }
 });
 
-test('検査 6b: 語彙表が健全 (4 分類は互いに素 / 除外型は受け手型と重ならない)', function (): void {
+test('検査 6b: 語彙表が健全 (5 分類は互いに素 / 除外型は受け手型と重ならない)', function (): void {
     // ★同じメソッドが 2 つの分類に入ると match の順序で暗黙に勝敗が決まり、
     //   「WRITE のつもりが NON_WRITE として素通り」が静かに起きる。互いに素であることを固定する。
     $groups = [
         'WRITE' => CACHE_PAYLOAD_WRITE_METHODS,
         'NON_WRITE' => CACHE_PAYLOAD_NON_WRITE_METHODS,
         'CHAIN' => CACHE_PAYLOAD_CHAIN_METHODS,
+        'BYPASS' => CACHE_PAYLOAD_BYPASS_METHODS,
         'TERMINAL' => CACHE_PAYLOAD_TERMINAL_METHODS,
     ];
     $all = array_merge(...array_values($groups));
@@ -1064,6 +1922,182 @@ function cachePayloadCollectAll(): array
     }
 });
 
+// ---------------------------------------------------------------------------
+// 検査 L4: 境界迂回の hard fail (正典 v2 の要素 4)
+// ---------------------------------------------------------------------------
+
+test('検査 L4a: 受け皿の境界を迂回する API 呼び出しと直接生成が自己テスト目録と exact-fit で一致する', function (): void {
+    $result = cachePayloadCollectAll();
+
+    $declared = [];
+    foreach (CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY as $key => $entry) {
+        $declared[$key] = $entry['count'];
+    }
+    ksort($declared);
+
+    expect($result['bypassCounts'])->toBe($declared,
+        '受け皿 (Illuminate\Cache\Repository) を跨いで保管先へ届く / 受け皿の生成に割り込む書き方は'
+        .'**通常経路 0 件**です (家系の裁定 AG-151 の境界迂回の hard fail)。'
+        .'Cache::extend / getStore / setStore / tags / macro / mixin / flushMacros / '
+        .'受け手型・保管先型の直接生成は、実行時層が値を見られない経路を作ります。'
+        .'実行時層の自己テストだけが CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY へ登録できます。'
+        .PHP_EOL.'検出: '.implode(PHP_EOL, $result['bypassSites']));
+});
+
+test('検査 L4b: 自己テスト目録の各 entry が形式要件を満たし実測で非空である', function (): void {
+    expect(CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY)->not->toBe([]);
+    $result = cachePayloadCollectAll();
+
+    foreach (CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY as $key => $entry) {
+        expect($entry['count'])->toBeGreaterThanOrEqual(1, "{$key}: count は 1 以上");
+        expect(mb_strlen($entry['rationale']))->toBeGreaterThanOrEqual(30, "{$key}: rationale が短すぎます");
+        expect($result['bypassCounts'][$key] ?? 0)->toBe($entry['count'],
+            "{$key}: 目録の件数と実測が一致しません (実在しない登録も、件数のズレも落とす)");
+    }
+});
+
+test('検査 L4c: guard 付き manager は $store を受け皿の第 1 引数以外へ流さない', function (): void {
+    // ★保管先を外へ流出させると、受け皿を迂回して書ける経路が生まれる。
+    $relative = 'tests/Support/Cache/PlainDataGuardedCacheManager.php';
+    $source = file_get_contents(base_path($relative));
+    expect($source)->toBeString();
+
+    expect(cachePayloadStoreLeakViolations((string) $source))->toBe([],
+        "{$relative}: \$store は (1) `Store \$store` の型宣言 (2) "
+        .'`new PlainDataGuardedRepository($store, …)` の第 1 引数 の 2 か所ちょうどでなければなりません');
+});
+
+test('検査 L4c の正負コントロール: $store の流出を検出する', function (): void {
+    $ok = <<<'PHP'
+    <?php
+    final class Fixture {
+        public function repository(Store $store, array $config = [])
+        {
+            return new PlainDataGuardedRepository($store, Arr::only($config, ['store']));
+        }
+    }
+    PHP;
+    expect(cachePayloadStoreLeakViolations($ok))->toBe([]);
+
+    // 第 1 引数が別の変数へすり替わっている (受け皿が包む保管先が変わる)
+    $swapped = <<<'PHP'
+    <?php
+    final class Fixture {
+        public function repository(Store $store, array $config = [])
+        {
+            $copy = leak($store);
+            return new PlainDataGuardedRepository($copy, []);
+        }
+    }
+    PHP;
+    expect(cachePayloadStoreLeakViolations($swapped))->not->toBe([]);
+
+    // 第 2 引数へ回すと、受け皿の外へ保管先が漏れる
+    $leaked = <<<'PHP'
+    <?php
+    final class Fixture {
+        public function repository(Store $store, array $config = [])
+        {
+            return new PlainDataGuardedRepository(new ArrayStore, $store);
+        }
+    }
+    PHP;
+    expect(cachePayloadStoreLeakViolations($leaked))->not->toBe([]);
+
+    // 受け皿へ渡さずどこかへ渡す形
+    $handedOff = <<<'PHP'
+    <?php
+    final class Fixture {
+        public function repository(Store $store, array $config = [])
+        {
+            Registry::remember($store);
+            return new PlainDataGuardedRepository($store, []);
+        }
+    }
+    PHP;
+    expect(cachePayloadStoreLeakViolations($handedOff))->not->toBe([]);
+});
+
+test('検査 L4d: 受け手型 / 保管先型の継承・実装が名指しの目録と exact-fit で一致する', function (): void {
+    $result = cachePayloadCollectAll();
+
+    $declared = array_keys(CACHE_PAYLOAD_SUBCLASS_INVENTORY);
+    sort($declared);
+
+    expect($result['subclassDeclarations'])->toBe($declared,
+        '受け手型 / 保管先型を継承・実装すると `new` の検出を逃れて受け皿を自作できます。'
+        .'宣言側で塞ぐため CACHE_PAYLOAD_SUBCLASS_INVENTORY と exact-fit で一致させてください。');
+
+    foreach (CACHE_PAYLOAD_SUBCLASS_INVENTORY as $key => $rationale) {
+        expect(mb_strlen($rationale))->toBeGreaterThanOrEqual(30, "{$key}: rationale が短すぎます");
+        expect(is_file(base_path(explode('::', $key, 2)[0])))->toBeTrue("{$key}: 対象ファイルが実在しません");
+    }
+});
+
+test('検査 L4h: 静的に解決できない new が非キャッシュ用途の目録と exact-fit で一致する', function (): void {
+    $result = cachePayloadCollectAll();
+
+    $declared = [];
+    foreach (CACHE_PAYLOAD_DYNAMIC_NEW_INVENTORY as $path => $entry) {
+        $declared[$path] = $entry['count'];
+    }
+    ksort($declared);
+
+    expect($result['dynamicNewCounts'])->toBe($declared,
+        '`new $class` / `new ($expr)` は生成されるクラスが静的に決まらないため、'
+        .'受け皿・保管先の直接生成を隠せてしまいます (受け手型の宣言も持たないので L2 にも現れません)。'
+        .'キャッシュの保管先ではないなら CACHE_PAYLOAD_DYNAMIC_NEW_INVENTORY へ'
+        .'count と 30 文字以上の rationale を添えて登録してください。'
+        .PHP_EOL.'検出: '.implode(PHP_EOL, $result['dynamicNewSites']));
+
+    foreach (CACHE_PAYLOAD_DYNAMIC_NEW_INVENTORY as $path => $entry) {
+        expect($entry['count'])->toBeGreaterThanOrEqual(1, "{$path}: count は 1 以上");
+        expect(mb_strlen($entry['rationale']))->toBeGreaterThanOrEqual(30, "{$path}: rationale が短すぎます");
+        expect(is_file(base_path($path)))->toBeTrue("{$path}: 対象ファイルが実在しません");
+    }
+
+    // 空振り検知: 目録が空でなく、実測も空でない (走査が死んでいたら気付けるようにする)
+    expect(CACHE_PAYLOAD_DYNAMIC_NEW_INVENTORY)->not->toBe([]);
+    expect($result['dynamicNewSites'])->not->toBe([]);
+});
+
+test('検査 L4e: 保管先型の判定規則の正負コントロール', function (): void {
+    expect(cachePayloadIsStoreType('Illuminate\Contracts\Cache\Store'))->toBeTrue();
+    expect(cachePayloadIsStoreType('Illuminate\Cache\ArrayStore'))->toBeTrue();
+    expect(cachePayloadIsStoreType('Illuminate\Cache\DatabaseStore'))->toBeTrue();
+    expect(cachePayloadIsStoreType('Illuminate\Cache\MemoizedStore'))->toBeTrue();
+
+    expect(cachePayloadIsStoreType('Illuminate\Cache\Repository'))->toBeFalse();
+    expect(cachePayloadIsStoreType('App\Support\Storage\ObjectStore'))->toBeFalse();
+    expect(cachePayloadIsStoreType('Illuminate\Session\Store'))->toBeFalse();
+    expect(cachePayloadIsStoreType('Illuminate\Cache\StoreFactory'))->toBeFalse();
+});
+
+test('検査 L4f: 自己テスト目録の key は GuardedBoundaryProbe.php ちょうどにしか無い', function (): void {
+    // ★「tests/Support/Cache/ 配下すべて」にはしない — 将来足した任意の補助ファイルが
+    //   自己テストを名乗れてしまうため。
+    expect(is_file(base_path(CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE)))->toBeTrue();
+
+    foreach (array_keys(CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY) as $key) {
+        expect(explode('::', $key, 2)[0])->toBe(CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE,
+            "{$key}: 自己テスト目録に登録できるのは ".CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE.' だけです');
+    }
+});
+
+test('検査 L4g: 実行時層の素通し許可が静的層の排他語彙の部分集合である', function (): void {
+    // ★実行時層は `Repository::__call()` の素通しのうち排他 2 語彙だけを通す。
+    //   その許可が静的層の TERMINAL 語彙 (payload を運ばないと分類した語彙) の
+    //   **部分集合**であることを固定し、2 か所で別々に育てられないようにする
+    //   (TERMINAL には mock 系も含むので一致ではなく部分集合である)。
+    expect(PlainDataGuardedRepository::STORE_PASSTHROUGH_METHODS)->toBe(['lock', 'restorelock']);
+    expect(array_values(array_intersect(
+        CACHE_PAYLOAD_TERMINAL_METHODS,
+        PlainDataGuardedRepository::STORE_PASSTHROUGH_METHODS
+    )))->toBe(PlainDataGuardedRepository::STORE_PASSTHROUGH_METHODS,
+        '実行時層が素通しを許した語彙は、静的層が TERMINAL (payload を運ばない) と分類した語彙の'
+        .'部分集合でなければなりません');
+});
+
 // ---------------------------------------------------------------------------
 // 検査 7-8: 空振り検知と自己参照コントロール
 // ---------------------------------------------------------------------------
@@ -1075,6 +2109,24 @@ function cachePayloadCollectAll(): array
     expect($result['methodCalls'])->toBeGreaterThan(0, 'メソッド呼び出しを 1 件も見ていない (token 走査が死んでいる)');
     expect($result['cacheCalls'])->toBeGreaterThan(0, 'キャッシュ受け手を 1 件も解決できていない (受け手解決が死んでいる)');
     expect($result['surfaces'])->not->toBe([], 'キャッシュに触れるファイルを 1 件も検出できていない');
+    expect($result['bypassSites'])->not->toBe([], '境界迂回の検出器が 1 件も反応していない (L4 の走査が死んでいる)');
+    expect($result['subclassDeclarations'])->not->toBe([], '継承・実装の検出器が 1 件も反応していない (L4d の走査が死んでいる)');
+
+    // 検出器そのものが負例で反応することを合成入力で確かめる (実在ファイルの構成に依存させない)。
+    $probe = cachePayloadCollectFromSource(<<<'PHP'
+    <?php
+    namespace App\Demo;
+    use Illuminate\Support\Facades\Cache;
+    use Illuminate\Contracts\Cache\Repository;
+    class Fixture {
+        public function run(Repository $cache, $obj): void {
+            Cache::getStore()->put('a', [1], 60);
+            $cache['k'] = $obj;
+        }
+    }
+    PHP, 'probe.php');
+    expect($probe['bypassCounts'])->toBe(['probe.php::getstore' => 1]);
+    expect($probe['writes'])->toHaveCount(1);
 });
 
 test('検査 8: 自己参照コントロール (本 gate 自身は書き込み経路にも面にも現れない)', function (): void {
@@ -1085,6 +2137,8 @@ function cachePayloadCollectAll(): array
     // 将来ここに code としてキャッシュ呼び出しを書いたら落ちる = 正しい挙動。
     expect(array_key_exists($self, $result['surfaces']))->toBeFalse();
     expect(array_filter($result['writeSites'], fn (string $s): bool => str_starts_with($s, $self)))->toBe([]);
+    expect(array_filter($result['bypassSites'], fn (string $s): bool => str_starts_with($s, $self)))->toBe([]);
+    expect(array_filter($result['subclassDeclarations'], fn (string $s): bool => str_starts_with($s, $self)))->toBe([]);
 });
 
 // ---------------------------------------------------------------------------
@@ -1115,7 +2169,10 @@ public function run(Repository $other, $dto): void {
     PHP;
 
     $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
-    expect($result['writes'])->toHaveCount(10);
+    // ★`Cache::tags(['t'])->forever(...)` は L4 で**迂回**になったので書き込みには数えない
+    //   (辿って数えるのではなく、書き方そのものを 0 件で pin する側へ移した)。
+    expect($result['writes'])->toHaveCount(9);
+    expect($result['bypassCounts'])->toBe(['fixture.php::tags' => 1]);
     expect($result['unclassified'])->toBe([]);
     expect($result['surface'])->toBeTrue();
 });
@@ -1138,7 +2195,10 @@ public function run(): void {
     PHP;
 
     $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
-    expect($result['writes'])->toHaveCount(5);
+    // ★`Cache::getStore()->put(...)` は L4 で**迂回**になった。書き込み検出は消えるが
+    //   保護は弱くならない (迂回として 0 件 pin されるため)。
+    expect($result['writes'])->toHaveCount(4);
+    expect($result['bypassCounts'])->toBe(['fixture.php::getstore' => 1]);
     expect($result['unclassified'])->toBe([]);
     expect($result['surface'])->toBeTrue();
 });
@@ -1211,6 +2271,8 @@ public function run(array $values, $store): void {
     $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
     expect($result['writes'])->toBe([]);
     expect($result['unclassified'])->toHaveCount(1); // cache($values, 60) だけ
+    // 受け手型の直接生成そのものは L4b の迂回として検出される
+    expect($result['bypassCounts'])->toBe(['fixture.php::new Illuminate\Cache\Repository' => 1]);
 });
 
 test('負のコントロール: app()->make(...) 経由のコンテナ解決も検出する', function (): void {
@@ -1433,6 +2495,413 @@ public function run(): void {
     expect($result['writes'])->toBe([]);
 });
 
+test('負のコントロール: 境界迂回の 7 語彙をすべて検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    use Illuminate\Support\Facades\Cache;
+    use Illuminate\Cache\Repository;
+    use Illuminate\Cache\CacheManager;
+    class Fixture {
+        public function run(Repository $cache, CacheManager $manager): void {
+            Cache::extend('x', fn () => null);
+            $cache->getStore();
+            $cache->setStore(null);
+            $cache->tags(['t']);
+            $manager->macro('m', fn () => null);
+            $manager->mixin(null);
+            $manager->flushMacros();
+        }
+    }
+    PHP;
+
+    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
+    expect($result['bypassCounts'])->toBe([
+        'fixture.php::extend' => 1,
+        'fixture.php::flushmacros' => 1,
+        'fixture.php::getstore' => 1,
+        'fixture.php::macro' => 1,
+        'fixture.php::mixin' => 1,
+        'fixture.php::setstore' => 1,
+        'fixture.php::tags' => 1,
+    ]);
+    expect($result['writes'])->toBe([]);
+    expect($result['unclassified'])->toBe([]);
+});
+
+test('負のコントロール: 受け手型 / 保管先型の直接生成を検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    use Illuminate\Cache\ArrayStore;
+    use Illuminate\Cache\Repository;
+    use Illuminate\Contracts\Cache\Store as CacheStore;
+    class Fixture {
+        public function run(): void {
+            $a = new Repository(new ArrayStore);
+            $b = new \Illuminate\Cache\DatabaseStore(null, 'cache', '');
+            $c = new \Illuminate\Cache\CacheManager(null);
+        }
+    }
+    PHP;
+
+    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
+    expect($result['bypassCounts'])->toBe([
+        'fixture.php::new Illuminate\Cache\ArrayStore' => 1,
+        'fixture.php::new Illuminate\Cache\CacheManager' => 1,
+        'fixture.php::new Illuminate\Cache\DatabaseStore' => 1,
+        'fixture.php::new Illuminate\Cache\Repository' => 1,
+    ]);
+});
+
+test('負のコントロール: 受け手型 / 保管先型の継承・実装を 4 形すべて検出する', function (): void {
+    // ★直前 token だけを見る形では 2 番目の interface を落とす。宣言句全体を読む。
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    use Countable;
+    use Illuminate\Cache\Repository;
+    use Illuminate\Contracts\Cache\Store as CacheStore;
+    class Second implements Countable, \Illuminate\Contracts\Cache\Store {}
+    class Aliased implements CacheStore {}
+    class Fully implements \Illuminate\Contracts\Cache\Store {}
+    class Multiline implements
+        Countable,
+        CacheStore {}
+    class Inherited extends Repository {}
+    PHP;
+
+    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
+    expect($result['subclassDeclarations'])->toBe([
+        'fixture.php::extends Illuminate\Cache\Repository',
+        'fixture.php::implements Illuminate\Contracts\Cache\Store',
+        'fixture.php::implements Illuminate\Contracts\Cache\Store',
+        'fixture.php::implements Illuminate\Contracts\Cache\Store',
+        'fixture.php::implements Illuminate\Contracts\Cache\Store',
+    ]);
+});
+
+test('負のコントロール: 継承句に解決できない名前があれば未分類として落とす', function (): void {
+    // ★fail-closed 分岐の裏取り (AGENTS.md 走査規約 (b))。名前として読めない形を
+    //   黙って候補から外すと、宣言側で塞ぐ L4d をすり抜けられる。
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    class Fixture implements $dynamicInterface {}
+    PHP;
+
+    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
+    expect($result['unclassified'])->toHaveCount(1);
+    expect($result['unclassified'][0])->toContain('extends/implements');
+    expect($result['subclassDeclarations'])->toBe([]);
+});
+
+test('負のコントロール: 静的に解決できない new を 2 形とも検出する', function (): void {
+    // ★`$store = new $class;` は生成されるクラスが静的に決まらず、受け手型の宣言も持たないので
+    //   L4b にも L2 にも現れない。**走査根の全体で見逃さずに落とす** (L4h の目録が受け皿)。
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    class Fixture {
+        public function run(): void {
+            $class = 'Illuminate\Cache\ArrayStore';
+            $store = new $class;
+            $store->put('key', new \stdClass(), 60);
+            $other = new ($this->resolver())();
+        }
+    }
+    PHP;
+
+    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
+    expect($result['dynamicNewCounts'])->toBe(['fixture.php' => 2]);
+    foreach ($result['dynamicNewSites'] as $entry) {
+        expect($entry)->toContain('new <静的に解決できないクラス名>');
+    }
+});
+
+test('正のコントロール: 名前で書かれた new と new というメソッド名は動的生成に数えない', function (): void {
+    // ★`Factory::new()` / `->new()` は**メソッド名**であって生成ではない。
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    class Fixture {
+        public function run(): void {
+            $factory = PasskeyFactory::new();
+            $chained = $this->factory()->new();
+            $a = new \DateTimeImmutable();
+            $b = new static();
+            $c = new class {};
+        }
+    }
+    PHP;
+
+    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
+    expect($result['dynamicNewCounts'])->toBe([]);
+    expect($result['dynamicNewSites'])->toBe([]);
+    expect($result['unclassified'])->toBe([]);
+});
+
+test('負のコントロール: guard 実装クラスの継承を 4 形とも迂回として検出する', function (): void {
+    // ★受け手型にも保管先型の命名規則にも一致しないが、継承すれば末端 4 メソッドを
+    //   override し直して getStore() 経由で保管先へ直接書ける。**宣言側で塞ぐ**。
+    // ★4 形のうち**同一名前空間の短名**が load-bearing である。現在の名前空間を
+    //   考慮しないと完全修飾名へ解決できず、継承禁止をすり抜ける (走査規約 (a))。
+    $imported = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    use Tests\Support\Cache\PlainDataGuardedRepository;
+    final class BypassRepository extends PlainDataGuardedRepository {}
+    final class BypassManager extends \Tests\Support\Cache\PlainDataGuardedCacheManager {}
+    PHP;
+
+    expect(cachePayloadCollectFromSource($imported, 'fixture.php')['subclassDeclarations'])->toBe([
+        'fixture.php::extends Tests\Support\Cache\PlainDataGuardedCacheManager',
+        'fixture.php::extends Tests\Support\Cache\PlainDataGuardedRepository',
+    ]);
+
+    $sameNamespace = <<<'PHP'
+    <?php
+    namespace Tests\Support\Cache;
+    final class BypassRepository extends PlainDataGuardedRepository {}
+    final class RelativeBypass extends namespace\PlainDataGuardedCacheManager {}
+    PHP;
+
+    expect(cachePayloadCollectFromSource($sameNamespace, 'fixture.php')['subclassDeclarations'])->toBe([
+        'fixture.php::extends Tests\Support\Cache\PlainDataGuardedCacheManager',
+        'fixture.php::extends Tests\Support\Cache\PlainDataGuardedRepository',
+    ]);
+
+    // グループ use + 別名。走査器自身がグループ use を解決できないと素通しになる
+    $groupUse = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    use Tests\Support\Cache\{PlainDataGuardedRepository as GuardedRepository};
+    final class Bypass extends GuardedRepository {}
+    PHP;
+
+    expect(cachePayloadCollectFromSource($groupUse, 'fixture.php')['subclassDeclarations'])->toBe([
+        'fixture.php::extends Tests\Support\Cache\PlainDataGuardedRepository',
+    ]);
+
+    // ★クラス本体の trait 取り込みが名前空間スコープの取り込みを上書きしないこと。
+    //   上書きされると `extends Guarded` が別クラスへ解決されて母集団から外れる。
+    $traitShadowing = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    use Tests\Support\Cache\PlainDataGuardedRepository as Guarded;
+    class TraitUser {
+        use \Vendor\Package\Guarded;
+    }
+    class Bypass extends Guarded {}
+    PHP;
+
+    expect(cachePayloadCollectFromSource($traitShadowing, 'fixture.php')['subclassDeclarations'])->toBe([
+        'fixture.php::extends Tests\Support\Cache\PlainDataGuardedRepository',
+    ]);
+
+    // ★型宣言の**後ろ**に置いた名前空間スコープの取り込みも読むこと。
+    //   「最初の型宣言で打ち切る」形だと取り込み表が空のまま確定して母集団から外れる。
+    $lateImport = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    class Marker {}
+    use Tests\Support\Cache\PlainDataGuardedRepository as Guarded;
+    class Bypass extends Guarded {}
+    PHP;
+
+    expect(cachePayloadCollectFromSource($lateImport, 'fixture.php')['subclassDeclarations'])->toBe([
+        'fixture.php::extends Tests\Support\Cache\PlainDataGuardedRepository',
+    ]);
+
+    // ★波括弧形の名前空間でも同じこと (取り込みは名前空間本体の直下にある)
+    $bracedNamespace = <<<'PHP'
+    <?php
+    namespace App\Demo {
+        use Tests\Support\Cache\PlainDataGuardedRepository as Guarded;
+        class Bypass extends Guarded {}
+    }
+    PHP;
+
+    expect(cachePayloadCollectFromSource($bracedNamespace, 'fixture.php')['subclassDeclarations'])->toBe([
+        'fixture.php::extends Tests\Support\Cache\PlainDataGuardedRepository',
+    ]);
+});
+
+test('負のコントロール: 1 ファイルに複数の名前空間がある形は解決できないとして落とす', function (): void {
+    // ★取り込み表を名前空間ごとに持ち分けないと、後続の名前空間の同名の別名で上書きされ、
+    //   継承先を誤解して母集団から外れる。**解決できない形として落とす** (走査規約 (b))。
+    $semicolonForm = <<<'PHP'
+    <?php
+    namespace First;
+    use Tests\Support\Cache\PlainDataGuardedRepository as Guarded;
+    class Bypass extends Guarded {}
+    namespace Second;
+    use Vendor\Package\Unrelated as Guarded;
+    PHP;
+
+    $result = cachePayloadCollectFromSource($semicolonForm, 'fixture.php');
+    expect($result['unclassified'])->toHaveCount(1);
+    expect($result['unclassified'][0])->toContain('名前空間が 2 個');
+
+    $bracedForm = <<<'PHP'
+    <?php
+    namespace First {
+        use Tests\Support\Cache\PlainDataGuardedRepository as Guarded;
+        class Bypass extends Guarded {}
+    }
+    namespace Second {
+        use Vendor\Package\Unrelated as Guarded;
+    }
+    PHP;
+
+    $result = cachePayloadCollectFromSource($bracedForm, 'fixture.php');
+    expect($result['unclassified'])->toHaveCount(1);
+    expect($result['unclassified'][0])->toContain('名前空間が 2 個');
+
+    // 正のコントロール: 名前空間が 1 つなら落とさない
+    $single = <<<'PHP'
+    <?php
+    namespace First;
+    use Tests\Support\Cache\PlainDataGuardedRepository as Guarded;
+    class Bypass extends Guarded {}
+    PHP;
+    expect(cachePayloadCollectFromSource($single, 'fixture.php')['unclassified'])->toBe([]);
+});
+
+test('正のコントロール: 完全修飾名 / 別名 / 同一名前空間の短名が同じ完全修飾名へ解決する', function (): void {
+    // ★AGENTS.md 走査規約 (a) の裏取り。3 経路が同じ完全修飾名になることを固定する。
+    $useMap = ['Aliased' => 'Tests\Support\Cache\PlainDataGuardedRepository'];
+
+    expect(cachePayloadResolveName('\Tests\Support\Cache\PlainDataGuardedRepository', [], 'App\Demo'))
+        ->toBe('Tests\Support\Cache\PlainDataGuardedRepository');
+    expect(cachePayloadResolveName('Aliased', $useMap, 'App\Demo'))
+        ->toBe('Tests\Support\Cache\PlainDataGuardedRepository');
+    expect(cachePayloadResolveName('PlainDataGuardedRepository', [], 'Tests\Support\Cache'))
+        ->toBe('Tests\Support\Cache\PlainDataGuardedRepository');
+    expect(cachePayloadResolveName('namespace\PlainDataGuardedRepository', [], 'Tests\Support\Cache'))
+        ->toBe('Tests\Support\Cache\PlainDataGuardedRepository');
+
+    // 別名つき取り込みは名前空間より優先する (取り込みが勝つ)
+    expect(cachePayloadResolveName('Aliased', $useMap, 'Tests\Support\Cache'))
+        ->toBe('Tests\Support\Cache\PlainDataGuardedRepository');
+
+    // 名前空間の中の裸の名前は**その名前空間**へ解決する (global へ落とさない)
+    expect(cachePayloadResolveName('Repository', [], 'App\Demo'))->toBe('App\Demo\Repository');
+
+    // グループ use も完全修飾名へ解決する (本走査器自身が fail-closed であるため)
+    /** @var list<PhpToken> $group */
+    $group = PhpToken::tokenize(<<<'PHP'
+    <?php
+    use Tests\Support\Cache\{PlainDataGuardedRepository as GuardedRepository, PlainDataGuardedCacheManager};
+    use Illuminate\Cache\Repository;
+    PHP);
+    expect(cachePayloadUseMap($group))->toBe([
+        'GuardedRepository' => 'Tests\Support\Cache\PlainDataGuardedRepository',
+        'PlainDataGuardedCacheManager' => 'Tests\Support\Cache\PlainDataGuardedCacheManager',
+        'Repository' => 'Illuminate\Cache\Repository',
+    ]);
+
+    // 関数・定数の取り込みは**クラスの取り込みではない**ので表に入れない
+    // (末尾の名前を alias として登録すると、同名のクラス取り込みを上書きして解決を壊す)
+    /** @var list<PhpToken> $functionImports */
+    $functionImports = PhpToken::tokenize(<<<'PHP'
+    <?php
+    use Illuminate\Cache\Repository;
+    use function Vendor\Tools\Repository;
+    use const Vendor\Tools\Store;
+    use Vendor\Tools\{function helper, const LIMIT, ArrayStore};
+    PHP);
+    expect(cachePayloadUseMap($functionImports))->toBe([
+        'Repository' => 'Illuminate\Cache\Repository',
+        'ArrayStore' => 'Vendor\Tools\ArrayStore',
+    ]);
+
+    // 名前空間の宣言そのものの抽出
+    /** @var list<PhpToken> $tokens */
+    $tokens = PhpToken::tokenize("<?php\nnamespace Tests\\Support\\Cache;\nclass X {}");
+    expect(cachePayloadNamespace($tokens))->toBe('Tests\Support\Cache');
+    /** @var list<PhpToken> $global */
+    $global = PhpToken::tokenize('<?php class X {}');
+    expect(cachePayloadNamespace($global))->toBe('');
+});
+
+test('正のコントロール: 無関係な interface の implements は迂回にしない', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    use Countable;
+    use JsonSerializable;
+    class Fixture implements Countable, JsonSerializable {}
+    PHP;
+
+    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
+    expect($result['subclassDeclarations'])->toBe([]);
+    expect($result['unclassified'])->toBe([]);
+});
+
+test('負のコントロール: ArrayAccess 書き込みを 2 形とも検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    use Illuminate\Cache\Repository;
+    class Fixture {
+        public function run(Repository $cache, $dto): void {
+            $cache['a'] = $dto;
+            $cache['b'] ??= $dto;
+            $read = $cache['c'];
+        }
+    }
+    PHP;
+
+    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
+    expect($result['writes'])->toHaveCount(2);
+    expect(array_map(fn (array $w): string => $w['method'], $result['writes']))
+        ->toBe(['offsetSet', 'offsetSet']);
+    expect($result['unclassified'])->toBe([]);
+});
+
+test('正のコントロール: 自己テスト目録に登録された未知 API だけを未分類から外す', function (): void {
+    // ★実行時層の自己テストは「4 分類のどれでもない API 名」を意図的に呼ぶ。
+    //   目録に載っている呼び出しだけを迂回として数え、載っていないものは従来どおり落とす。
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    use Illuminate\Cache\Repository;
+    class Fixture {
+        public function run(Repository $cache): void {
+            $cache->guardProbeUnknownMethod();
+        }
+    }
+    PHP;
+
+    $registered = cachePayloadCollectFromSource($fixture, CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE);
+    expect($registered['unclassified'])->toBe([]);
+    expect($registered['bypassCounts'])
+        ->toBe([CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE.'::guardprobeunknownmethod' => 1]);
+
+    $unregistered = cachePayloadCollectFromSource($fixture, 'app/Demo/Fixture.php');
+    expect($unregistered['unclassified'])->toHaveCount(1);
+    expect($unregistered['bypassCounts'])->toBe([]);
+});
+
+test('正のコントロール: guard 付き受け皿の生成そのものは迂回にしない', function (): void {
+    // ★L4d が宣言側 (extends) で塞いでいるので、自前クラスの生成は通ってよい。
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    use Tests\Support\Cache\PlainDataGuardedRepository;
+    class Fixture {
+        public function run($store): void {
+            $repository = new PlainDataGuardedRepository($store, []);
+        }
+    }
+    PHP;
+
+    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
+    expect($result['bypassCounts'])->toBe([]);
+    expect($result['surface'])->toBeFalse();
+});
+
 test('正のコントロール: 排他・レート制限の型は受け手にしない', function (): void {
     $fixture = <<<'PHP'
     <?php
diff --git a/tests/Architecture/TemplateDivergenceLedgerFormatTest.php b/tests/Architecture/TemplateDivergenceLedgerFormatTest.php
index 272fc3df..9483a142 100644
--- a/tests/Architecture/TemplateDivergenceLedgerFormatTest.php
+++ b/tests/Architecture/TemplateDivergenceLedgerFormatTest.php
@@ -34,7 +34,7 @@
  * **明示件数との同期検査であって、例外を許す一覧ではない**。個別の D 番号を名指しして
  * 規則を免除する仕組みは持たない。登録を足した / 消したら同じ変更でこの値も直す。
  */
-const TEMPLATE_DIVERGENCE_ENTRY_COUNT = 28;
+const TEMPLATE_DIVERGENCE_ENTRY_COUNT = 29;
 
 /** 逸脱の登録簿の本文 (読めないことは不合格)。 */
 function templateDivergenceMarkdown(): string

```

## テスト結果 (Round 9 時点)

```
composer test            : 5902 tests / 5900 passed / 0 failed / 2 skipped
composer phpstan         : No errors
vendor/bin/pint --test   : passed
```
