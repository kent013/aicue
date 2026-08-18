<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\TestCase as VendorTestCase;
use Tests\Support\Cache\IsolatedApplicationProbe;
use Tests\TestCase;

/*
 * Architecture invariant: **キャッシュ素データ規約の実行時層が、アプリ起動の前に結線され、
 * 全レーンで後始末されている**こと (家系の裁定 AG-151 = 正典 v2 の要素 2)。
 *
 * 実行時層そのもの (値の検査・境界迂回の hard fail) の振る舞いは
 * tests/Feature/Cache/CachePayloadPlainDataGuardTest.php が固定する。
 * 本 gate が固定するのは**結線**である — 結線が beforeEach へ後退したり、
 * どこかのレーンから flush が抜けたりすると、検査は緑のまま**検出だけが消える**。
 *
 * ★この gate が保証するもの:
 *   - W1: Tests\TestCase::createApplication() が bootstrap() より**前**に
 *     PlainDataCacheGuard::registerBeforeBootstrap() を呼ぶ。
 *     判定は**メソッド本体**の token 位置で行う (ファイル全体を見る形だと
 *     「別メソッドで結線し別メソッドで bootstrap」を正常扱いする)
 *   - W2/W3: tests/Pest.php の**期待するレーン集合ちょうど** ({Feature, Unit} / {Architecture} /
 *     {Browser}) について、`assertInstalled` が **beforeEach のクロージャの中**、
 *     `flushAndFailIfStray` が **afterEach の try ブロックの中**、
 *     `reset` が **afterEach の finally ブロックの中**にある。
 *     いずれも**対応する波括弧を解決した範囲の直下の文**であることまで見る
 *     (位置の前後比較ではクロージャやブロックの外へ出した形を素通しし、
 *      範囲の内側かどうかだけでは条件分岐の中へ入れて実行させない形を素通しする)。
 *     try と finally が**同じ try 文に属する**ことも確認する
 *   - W4: WithCachedConfig / WithCachedRoutes を**クラス本体の `use` 文**または
 *     **字句として書かれた `uses(...)`** で適用しているテストが 0 件である
 *     (使い始めると override が vendor と食い違う前提が崩れる)。
 *     短名・別名・完全修飾名・カンマ区切り・グループ use を取り込み表で解決して突き合わせ、
 *     `uses()` の引数に静的に解決できない値があれば未解決として落とす。
 *     **主張はこの 2 形に限る** — 下の「保証しないもの」を参照
 *   - W5: vendor の Illuminate\Foundation\Testing\TestCase::createApplication() の
 *     正規化 token 列が期待値と**完全一致**する (Laravel 更新で写しが静かに古くならない)
 *   - W5b: ローカルの写しが「vendor 期待列 + 許可差分 3 つ」と**完全一致**する。
 *     許可差分は (1) 戻り値の fail-closed 確認 (2) 結線 1 行 (3) 戻り値型と #[\Override] だけ
 *   - W6: 起動中の負例 (IsolatedApplicationProbe::run) が **同じ関数**を bootstrap より前に呼ぶ
 *   - W7: 空振り検知 (走査ファイルが実在 / token 数が 0 でない / 許可差分がすべて位置ごと一致 /
 *     検出器が合成入力の負例に反応する)
 *   - W8: 負のコントロール (flush が無い / flush が try の外 / reset が finally の外 /
 *     try-finally の形でない / assertInstalled が beforeEach の外 / bootstrap の後で結線 /
 *     結線が無い / 結線が別メソッドにある / レーン集合違い /
 *     vendor 本体の token 増減・順序入れ替え / ローカルから既知の文を削除)。
 *     **いずれも本 gate が実際に使う判定関数へ通す**
 *     (加工した配列を素の比較で確かめるだけだと、判定側が壊れても負例が緑のままになる)
 *
 * ★この gate が保証しないもの (誇張しない):
 *   - vendor 側の `setUp()` / `refreshApplication()` の変更や bootstrapper の増減は見ない。
 *     見るのは `createApplication()` の**本体だけ**である
 *   - tests/Pest.php の**実行時の**挙動は見ない (字句として書かれていることだけを見る)。
 *     実際に flush が発火することは CachePayloadPlainDataGuardTest の負例が示す
 *   - レーンを新設したことは W2/W3 のレーン集合 exact-fit で赤くなるが、
 *     phpunit.xml の testsuite 構成そのものは見ない
 *   - **W4 の主張は「クラス本体の `use` 文」と「字句として書かれた `uses(...)`」の 2 形に限る**。
 *     関数名ごと動的にする形 (`call_user_func('uses', …)` / 変数関数) には沈黙するので、
 *     「対象 trait を適用する経路が 1 つも無い」とは読めない。
 *     `uses()` と**書いた**うえで引数を変数にした形は未解決として落とす
 *
 * 解析は PhpToken::tokenize (コメント・文字列リテラルは code token ではないので拾わない)。
 * regex にすると**この説明コメント自身**で偽赤になる。
 */

/**
 * vendor の `Illuminate\Foundation\Testing\TestCase::createApplication()` の正規化 token 列。
 * Laravel 更新で 1 token でも変わったら W5 が赤くなる。**それが目的**である。
 *
 * @var list<string>
 */
const CACHE_GUARD_VENDOR_CREATE_APPLICATION_TOKENS = [
    'public', 'function', 'createApplication', '(', ')', '{', '$app', '=', 'require', 'Application',
    '::', 'inferBasePath', '(', ')', '.', '\'/bootstrap/app.php\'', ';', '$this', '->', 'traitsUsedByTest',
    '=', 'class_uses_recursive', '(', 'static', '::', 'class', ')', ';', 'if', '(',
    'isset', '(', 'CachedState', '::', '$cachedConfig', ',', '$this', '->', 'traitsUsedByTest', '[',
    'WithCachedConfig', '::', 'class', ']', ')', ')', '{', '$this', '->', 'markConfigCached',
    '(', '$app', ')', ';', '}', 'if', '(', 'isset', '(', 'CachedState',
    '::', '$cachedRoutes', ',', '$this', '->', 'traitsUsedByTest', '[', 'WithCachedRoutes', '::', 'class',
    ']', ')', ')', '{', '$app', '->', 'booting', '(', 'fn', '(',
    ')', '=>', '$this', '->', 'markRoutesCached', '(', '$app', ')', ')', ';',
    '}', '$app', '->', 'make', '(', 'Kernel', '::', 'class', ')', '->',
    'bootstrap', '(', ')', ';', 'return', '$app', ';', '}',
];

/**
 * ローカルの `Tests\TestCase::createApplication()` の正規化 token 列。
 *
 * ★W5 は vendor 側の変更しか見ず、W1 は「結線が bootstrap より前にある」ことしか見ない。
 *   その 2 つだけだと、ローカルの写しから `$this->traitsUsedByTest` の代入・cached config 分岐・
 *   cached routes 分岐・`return $app` を消しても**両方とも緑のまま**になる。
 *
 * @var list<string>
 */
const CACHE_GUARD_LOCAL_CREATE_APPLICATION_TOKENS = [
    'public', 'function', 'createApplication', '(', ')', ':', 'Application', '{', '$app', '=',
    'require', 'Application', '::', 'inferBasePath', '(', ')', '.', '\'/bootstrap/app.php\'', ';', 'if',
    '(', '!', '$app', 'instanceof', 'Application', ')', '{', 'throw', 'new', 'RuntimeException',
    '(', '\'bootstrap/app.php が Application を返しませんでした\'', ')', ';', '}', 'PlainDataCacheGuard', '::', 'registerBeforeBootstrap', '(', '$app',
    ')', ';', '$this', '->', 'traitsUsedByTest', '=', 'class_uses_recursive', '(', 'static', '::',
    'class', ')', ';', 'if', '(', 'isset', '(', 'CachedState', '::', '$cachedConfig',
    ',', '$this', '->', 'traitsUsedByTest', '[', 'WithCachedConfig', '::', 'class', ']', ')',
    ')', '{', '$this', '->', 'markConfigCached', '(', '$app', ')', ';', '}',
    'if', '(', 'isset', '(', 'CachedState', '::', '$cachedRoutes', ',', '$this', '->',
    'traitsUsedByTest', '[', 'WithCachedRoutes', '::', 'class', ']', ')', ')', '{', '$app',
    '->', 'booting', '(', 'fn', '(', ')', '=>', '$this', '->', 'markRoutesCached',
    '(', '$app', ')', ')', ';', '}', '$app', '->', 'make', '(',
    'Kernel', '::', 'class', ')', '->', 'bootstrap', '(', ')', ';', 'return',
    '$app', ';', '}',
];

/**
 * ローカルの写しに足してよい差分 (offset は**ローカル列の index**、tokens は挿入された列)。
 *
 * ここから挿入を取り除くと vendor 期待列に**完全一致**しなければならない。
 * 部分列の除去だけだと別の位置に同じ列を置いても通るため、**位置まで固定する**。
 *
 * @var list<array{reason: string, offset: int, tokens: list<string>}>
 */
const CACHE_GUARD_LOCAL_ALLOWED_INSERTIONS = [
    [
        'reason' => '戻り値型の宣言 (vendor は docblock だけなので狭めていない)',
        'offset' => 5,
        'tokens' => [':', 'Application'],
    ],
    [
        'reason' => '戻り値の fail-closed 確認と、bootstrap 直前の結線 1 行',
        'offset' => 19,
        'tokens' => [
            'if', '(', '!', '$app', 'instanceof', 'Application', ')', '{', 'throw', 'new',
            'RuntimeException', '(', '\'bootstrap/app.php が Application を返しませんでした\'', ')', ';', '}',
            'PlainDataCacheGuard', '::', 'registerBeforeBootstrap', '(', '$app', ')', ';',
        ],
    ],
];

/**
 * tests/Pest.php で期待するレーン集合 (`->in(...)` の引数集合)。
 *
 * @var list<list<string>>
 */
const CACHE_GUARD_EXPECTED_LANES = [
    ['Architecture'],
    ['Browser'],
    ['Feature', 'Unit'],
];

/**
 * 使い始めたら override の前提が崩れる vendor の trait (完全修飾名)。
 *
 * @var list<string>
 */
const CACHE_GUARD_CACHED_STATE_TRAITS = [
    'Illuminate\Foundation\Testing\WithCachedConfig',
    'Illuminate\Foundation\Testing\WithCachedRoutes',
];

/**
 * 空白・コメント・開始タグを落とした token の文字列列。
 *
 * @return list<string>
 */
function cacheGuardNormalizedTokens(string $source): array
{
    /** @var list<PhpToken> $tokens */
    $tokens = PhpToken::tokenize($source);

    return array_values(array_map(
        static fn (PhpToken $token): string => $token->text,
        array_filter(
            $tokens,
            static fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG]),
        ),
    ));
}

/**
 * メソッド本体の正規化 token 列を反射で取り出す (fail-closed)。
 *
 * @return list<string>
 */
function cacheGuardMethodTokens(string $class, string $method): array
{
    $reflection = new ReflectionMethod($class, $method);

    $file = $reflection->getFileName();
    $start = $reflection->getStartLine();
    $end = $reflection->getEndLine();
    if ($file === false || $start === false || $end === false) {
        throw new RuntimeException("{$class}::{$method}() の定義位置を解決できません (内部関数か eval)");
    }

    $lines = file($file);
    if ($lines === false) {
        throw new RuntimeException("{$file} を読めません");
    }

    return cacheGuardNormalizedTokens(
        '<?php '.implode('', array_slice($lines, $start - 1, $end - $start + 1))
    );
}

/**
 * 合成入力から 1 メソッドの本体 token 列を切り出す (負例を「メソッド抽出 + 順序判定」の
 * 組で通すために要る。反射は実在クラスにしか使えない)。
 *
 * @return list<string> 見つからなければ空
 */
function cacheGuardMethodTokensFromSource(string $source, string $method): array
{
    $tokens = cacheGuardNormalizedTokens($source);

    $signature = cacheGuardSequencePosition($tokens, ['function', $method, '(']);
    if ($signature === null) {
        return [];
    }

    $open = null;
    for ($i = $signature; $i < count($tokens); $i++) {
        if ($tokens[$i] === '{') {
            $open = $i;
            break;
        }
    }
    if ($open === null) {
        return [];
    }

    $close = cacheGuardMatchingBrace($tokens, $open);
    if ($close === null) {
        return [];
    }

    return array_slice($tokens, $signature, $close - $signature + 1);
}

/**
 * `{` の対応する `}` の index。
 *
 * @param  list<string>  $tokens
 */
function cacheGuardMatchingBrace(array $tokens, int $open): ?int
{
    $depth = 0;
    $count = count($tokens);
    for ($i = $open; $i < $count; $i++) {
        if ($tokens[$i] === '{') {
            $depth++;
        } elseif ($tokens[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return $i;
            }
        }
    }

    return null;
}

/**
 * token 列 $needle が最初に現れる位置。無ければ null。
 *
 * @param  list<string>  $tokens
 * @param  list<string>  $needle
 */
function cacheGuardSequencePosition(array $tokens, array $needle, int $from = 0): ?int
{
    $limit = count($tokens) - count($needle);
    for ($i = $from; $i <= $limit; $i++) {
        if (array_slice($tokens, $i, count($needle)) === $needle) {
            return $i;
        }
    }

    return null;
}

/**
 * `->name(` に続くクロージャ / ブロックの `{ … }` の範囲 (両端の index)。
 *
 * @param  list<string>  $tokens
 * @return array{int, int}|null
 */
function cacheGuardBlockRange(array $tokens, array $needle, int $from = 0): ?array
{
    $position = cacheGuardSequencePosition($tokens, $needle, $from);
    if ($position === null) {
        return null;
    }

    $count = count($tokens);
    for ($i = $position; $i < $count; $i++) {
        if ($tokens[$i] === '{') {
            $close = cacheGuardMatchingBrace($tokens, $i);

            return $close === null ? null : [$i, $close];
        }
    }

    return null;
}

/**
 * `$from` 以降で**最初に現れる try 文**を解析し、**それ自身が finally を持つ場合だけ**返す。
 *
 * `try { … } catch (…) { … } finally { … }` の catch 群を読み飛ばし、
 * 直後が `finally {` である場合だけ組にして返す。最初の try が finally を持たなければ
 * その場で null を返す (後続の別の try-finally を借りてこないため = fail-closed)。
 *
 * @param  list<string>  $tokens
 * @return array{try: array{int, int}, finally: array{int, int}}|null
 */
function cacheGuardTryStatement(array $tokens, int $from): ?array
{
    $count = count($tokens);
    for ($i = $from; $i < $count; $i++) {
        if ($tokens[$i] !== 'try') {
            continue;
        }
        $tryOpen = $i + 1;
        if (($tokens[$tryOpen] ?? '') !== '{') {
            continue;
        }
        $tryClose = cacheGuardMatchingBrace($tokens, $tryOpen);
        if ($tryClose === null) {
            return null;
        }

        $cursor = $tryClose + 1;
        while (($tokens[$cursor] ?? '') === 'catch') {
            $parenOpen = $cursor + 1;
            if (($tokens[$parenOpen] ?? '') !== '(') {
                return null;
            }
            $depth = 0;
            $parenClose = null;
            for ($j = $parenOpen; $j < $count; $j++) {
                if ($tokens[$j] === '(') {
                    $depth++;
                } elseif ($tokens[$j] === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $parenClose = $j;
                        break;
                    }
                }
            }
            if ($parenClose === null || ($tokens[$parenClose + 1] ?? '') !== '{') {
                return null;
            }
            $catchClose = cacheGuardMatchingBrace($tokens, $parenClose + 1);
            if ($catchClose === null) {
                return null;
            }
            $cursor = $catchClose + 1;
        }

        if (($tokens[$cursor] ?? '') !== 'finally' || ($tokens[$cursor + 1] ?? '') !== '{') {
            return null; // この try 文は finally を持たない
        }
        $finallyClose = cacheGuardMatchingBrace($tokens, $cursor + 1);
        if ($finallyClose === null) {
            return null;
        }

        return ['try' => [$tryOpen, $tryClose], 'finally' => [$cursor + 1, $finallyClose]];
    }

    return null;
}

/**
 * 位置が範囲の**直下の文の先頭**か。
 *
 * 2 つを同時に満たすことを要求する。
 *  (1) 範囲の `{` から数えて入れ子の波括弧の中に無い (深さ 0)
 *  (2) 直前の token が**文の境界** (`{` / `}` / `;`) である
 *
 * ★(2) が無いと、波括弧を使わない制御構文 (`if (false) flush();`)・代替構文
 *   (`if (false): flush(); endif;`)・三項演算子・短絡評価の右辺がすべて深さ 0 で通ってしまう。
 *   どれも「無条件に実行される」ことを保証しない置き方である。
 *
 * @param  list<string>  $tokens
 * @param  array{int, int}|null  $range
 */
function cacheGuardIsDirectStatement(array $tokens, ?int $position, ?array $range): bool
{
    if (! cacheGuardIsInside($position, $range)) {
        return false;
    }
    /** @var array{int, int} $range */
    /** @var int $position */
    $depth = 0;
    for ($i = $range[0] + 1; $i < $position; $i++) {
        if ($tokens[$i] === '{') {
            $depth++;
        } elseif ($tokens[$i] === '}') {
            $depth--;
        }
    }
    if ($depth !== 0) {
        return false;
    }

    return in_array($tokens[$position - 1] ?? '', ['{', '}', ';'], true);
}

/**
 * `Guard::method(...)` が範囲の**直下の独立した式文**として置かれているか。
 *
 * 文の先頭であること (`cacheGuardIsDirectStatement`) に加えて、引数リストの閉じ括弧の
 * 直後が `;` であることまで見る (代入・連鎖・条件式の一部として書かれた形を除く)。
 *
 * @param  list<string>  $tokens
 * @param  array{int, int}|null  $range
 */
function cacheGuardIsStandaloneCall(array $tokens, ?int $position, ?array $range): bool
{
    if (! cacheGuardIsDirectStatement($tokens, $position, $range)) {
        return false;
    }
    /** @var int $position */
    $open = $position + 3; // Guard :: method ( の `(`
    if (($tokens[$open] ?? '') !== '(') {
        return false;
    }

    $depth = 0;
    $count = count($tokens);
    for ($i = $open; $i < $count; $i++) {
        if ($tokens[$i] === '(') {
            $depth++;
        } elseif ($tokens[$i] === ')') {
            $depth--;
            if ($depth === 0) {
                return ($tokens[$i + 1] ?? '') === ';';
            }
        }
    }

    return false;
}

/** 位置が範囲の**内側**にあるか。 */
function cacheGuardIsInside(?int $position, ?array $range): bool
{
    return $position !== null && $range !== null && $position > $range[0] && $position < $range[1];
}

/**
 * 「結線が bootstrap より**前**にある」ことの違反理由 (純関数。合成入力にも当てられる)。
 *
 * ★引数は**メソッド本体の token 列**である。ファイル全体を渡すと「別のメソッドで結線し、
 *   別のメソッドで bootstrap する」形を正常扱いしてしまう。
 *
 * @param  list<string>  $tokens
 * @return list<string>
 */
function cacheGuardBootstrapOrderViolations(array $tokens, string $label): array
{
    $wiring = cacheGuardSequencePosition($tokens, ['PlainDataCacheGuard', '::', 'registerBeforeBootstrap', '(']);
    $bootstrap = cacheGuardSequencePosition($tokens, ['->', 'bootstrap', '(', ')']);

    $violations = [];
    if ($wiring === null) {
        $violations[] = "{$label}: PlainDataCacheGuard::registerBeforeBootstrap() の呼び出しがありません";
    }
    if ($bootstrap === null) {
        $violations[] = "{$label}: ->bootstrap() の呼び出しがありません (走査対象を取り違えている)";
    }
    if ($wiring !== null && $bootstrap !== null && $wiring > $bootstrap) {
        $violations[] = "{$label}: 結線が bootstrap() より後にあります (起動中の書き込みを見逃す)";
    }

    return $violations;
}

/**
 * tests/Pest.php を `pest()->extend(TestCase::class)` 単位のレーンブロックへ割る。
 *
 * @return list<array{lanes: list<string>, tokens: list<string>}>
 */
function cacheGuardLaneBlocks(string $source): array
{
    $tokens = cacheGuardNormalizedTokens($source);
    $starts = [];
    $from = 0;
    while (($position = cacheGuardSequencePosition($tokens, ['pest', '(', ')', '->', 'extend'], $from)) !== null) {
        $starts[] = $position;
        $from = $position + 1;
    }

    $blocks = [];
    foreach ($starts as $index => $start) {
        $end = $starts[$index + 1] ?? count($tokens);
        $block = array_slice($tokens, $start, $end - $start);

        $lanes = [];
        $inPosition = cacheGuardSequencePosition($block, ['->', 'in', '(']);
        if ($inPosition !== null) {
            for ($i = $inPosition + 3; $i < count($block); $i++) {
                if ($block[$i] === ')') {
                    break;
                }
                if ($block[$i] === ',') {
                    continue;
                }
                $lanes[] = trim($block[$i], "'\"");
            }
        }
        sort($lanes);

        $blocks[] = ['lanes' => $lanes, 'tokens' => $block];
    }

    return $blocks;
}

/**
 * 1 レーンブロックの結線と後始末の違反理由 (純関数。合成入力にも当てられる)。
 *
 * ★**対応する波括弧を解決した範囲**で判定する。位置の前後比較だけだと、
 *   クロージャや try / finally の外へ出した形を素通しする。
 *
 * @param  list<string>  $block
 * @return list<string>
 */
function cacheGuardLaneWiringViolations(array $block, string $label): array
{
    $violations = [];

    $beforeEach = cacheGuardBlockRange($block, ['->', 'beforeEach', '(']);
    $afterEach = cacheGuardBlockRange($block, ['->', 'afterEach', '(']);
    if ($beforeEach === null) {
        $violations[] = "{$label}: beforeEach のクロージャを解決できません";
    }
    if ($afterEach === null) {
        $violations[] = "{$label}: afterEach のクロージャを解決できません";

        return $violations;
    }

    $assertInstalled = cacheGuardSequencePosition($block, ['PlainDataCacheGuard', '::', 'assertInstalled', '(']);
    if (! cacheGuardIsStandaloneCall($block, $assertInstalled, $beforeEach)) {
        $violations[] = "{$label}: beforeEach のクロージャの**直下**で PlainDataCacheGuard::assertInstalled() を呼んでいません"
            .' (条件分岐の中に入れると実行されない場合がある)';
    }

    // ★try と finally が**同じ try 文に属する**ことまで確認する。独立に探すと、
    //   「flush を持つ try (finally 無し)」と「reset を持つ別の try-finally」が
    //   別々にある形を通してしまい、flush が投げたときに reset へ到達しない。
    $statement = cacheGuardTryStatement($block, $afterEach[0]);
    // ★try 文そのものが afterEach クロージャの**直下**にあること。条件分岐の中へ入れると
    //   範囲としては内側でも 1 度も実行されない。
    // ★判定するのは `try` **キーワード**の位置である (`{` の位置だと直前が常に `try` になる)。
    if ($statement === null || ! cacheGuardIsDirectStatement($block, $statement['try'][0] - 1, $afterEach)) {
        $violations[] = "{$label}: afterEach の直下が try … finally の形になっていません"
            .' (同じ try 文の finally が要る / 条件分岐の中へ入れない)';

        return $violations;
    }
    $try = $statement['try'];
    $finally = $statement['finally'];

    $flush = cacheGuardSequencePosition($block, ['PlainDataCacheGuard', '::', 'flushAndFailIfStray', '(']);
    if (! cacheGuardIsStandaloneCall($block, $flush, $try)) {
        $violations[] = "{$label}: afterEach の try ブロックの直下で PlainDataCacheGuard::flushAndFailIfStray() を呼んでいません";
    }

    // ★flush が throw しても次テストへ accumulator を漏らさないために、reset は
    //   **finally ブロックの直下**でなければならない。
    $reset = cacheGuardSequencePosition($block, ['PlainDataCacheGuard', '::', 'reset', '(']);
    if (! cacheGuardIsStandaloneCall($block, $reset, $finally)) {
        $violations[] = "{$label}: afterEach の finally ブロックの直下で PlainDataCacheGuard::reset() を呼んでいません";
    }

    return $violations;
}

/**
 * 期待 token 列との完全一致を判定する (負例をこの関数に通すため純関数にしてある)。
 *
 * @param  list<string>  $actual
 * @param  list<string>  $expected
 * @return list<string>
 */
function cacheGuardTokenListViolations(array $actual, array $expected, string $label): array
{
    if ($actual === $expected) {
        return [];
    }

    return ["{$label}: token 列が期待値と一致しません (実測 "
        .count($actual).' token / 期待 '.count($expected).' token)'];
}

/**
 * ローカルの写しが「vendor 期待列 + 許可差分」であることの違反理由 (純関数)。
 *
 * @param  list<string>  $local
 * @return list<string>
 */
function cacheGuardLocalCopyViolations(array $local): array
{
    $violations = cacheGuardTokenListViolations(
        $local,
        CACHE_GUARD_LOCAL_CREATE_APPLICATION_TOKENS,
        'ローカルの写し',
    );

    $stripped = $local;
    foreach (array_reverse(CACHE_GUARD_LOCAL_ALLOWED_INSERTIONS) as $insertion) {
        if (array_slice($local, $insertion['offset'], count($insertion['tokens'])) !== $insertion['tokens']) {
            $violations[] = "許可差分「{$insertion['reason']}」が期待位置にありません";

            continue;
        }
        array_splice($stripped, $insertion['offset'], count($insertion['tokens']));
    }

    return array_merge($violations, cacheGuardTokenListViolations(
        $stripped,
        CACHE_GUARD_VENDOR_CREATE_APPLICATION_TOKENS,
        '許可差分を除いたローカルの写し',
    ));
}

/**
 * 名前空間の**本体の波括弧の深さ**。`namespace A;` なら 0、`namespace A { … }` なら 1。
 *
 * @param  list<PhpToken>  $tokens
 */
function cacheGuardNamespaceBodyDepth(array $tokens): int
{
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (! $tokens[$i]->is(T_NAMESPACE)) {
            continue;
        }
        for ($j = $i + 1; $j < $count; $j++) {
            if ($tokens[$j]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR])) {
                continue;
            }

            return $tokens[$j]->text === '{' ? 1 : 0;
        }
    }

    return 0;
}

/**
 * `use A\B\C;` / `use A\B\C as D;` / `use A\B\{C, D as E};` から alias => FQCN の表を作る。
 *
 * ★読むのは**名前空間スコープの取り込みだけ**である (波括弧の深さで判定する)。
 *   型宣言の本体に入った後の `use` は trait の取り込みで、混ぜると
 *   `use WithCachedConfig;` が自分自身へ解決して短名の負例が黙る。
 *   **「最初の型宣言で打ち切る」形は誤り**である — PHP は型宣言の**後ろ**にも
 *   名前空間スコープの取り込みを置けるため、後置の別名を丸ごと落としてしまう。
 *
 * @param  list<PhpToken>  $tokens
 * @return array<string, string>
 */
function cacheGuardUseMap(array $tokens): array
{
    $map = [];
    $count = count($tokens);
    $baseDepth = cacheGuardNamespaceBodyDepth($tokens);
    $depth = 0;

    for ($i = 0; $i < $count; $i++) {
        if ($tokens[$i]->text === '{') {
            $depth++;

            continue;
        }
        if ($tokens[$i]->text === '}') {
            $depth--;

            continue;
        }
        if (! $tokens[$i]->is(T_USE) || $depth !== $baseDepth) {
            continue;
        }

        $prefix = '';
        $pending = null;
        $isGroup = false;

        for ($j = $i + 1; $j < $count; $j++) {
            $token = $tokens[$j];
            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }
            if ($token->text === ';') {
                break;
            }
            if ($token->text === '{') {
                $isGroup = true;
                $prefix = $pending === null ? '' : rtrim($pending, '\\').'\\';
                $pending = null;

                continue;
            }
            if ($token->text === '}' || $token->text === ',') {
                if ($pending !== null) {
                    $map[cacheGuardShortName($pending)] = $prefix.$pending;
                    $pending = null;
                }
                if ($token->text === '}') {
                    break;
                }

                continue;
            }
            if ($token->is(T_AS)) {
                for ($k = $j + 1; $k < $count; $k++) {
                    if ($tokens[$k]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                        continue;
                    }
                    if ($tokens[$k]->is(T_STRING) && $pending !== null) {
                        $map[$tokens[$k]->text] = $prefix.$pending;
                        $pending = null;
                    }
                    $j = $k;
                    break;
                }

                continue;
            }
            if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                $pending = ltrim($token->text, '\\');

                continue;
            }
            if ($token->is(T_NS_SEPARATOR)) {
                continue; // グループ use の `Foo\{` の区切り
            }
        }

        if ($pending !== null && ! $isGroup) {
            $map[cacheGuardShortName($pending)] = $pending;
        }
    }

    return $map;
}

/** 完全修飾名の末尾要素。 */
function cacheGuardShortName(string $fqcn): string
{
    return str_contains($fqcn, '\\') ? substr((string) strrchr($fqcn, '\\'), 1) : $fqcn;
}

/**
 * 1 ファイルが cached config / cached routes の trait を**適用している**か。
 *
 * 見るのは 2 形である。
 *  (1) 型宣言より後の `use ...;` (trait の取り込み)
 *  (2) Pest の `uses(...::class)` (生成される TestCase が trait を使う)。
 *      **静的に解決できない引数 (`uses($trait)`) は `UNRESOLVED_USES(...)` として返す**
 *      = 呼び出し側の gate が落ちる (見逃さない)
 *
 * ★1 ファイルに複数の名前空間がある形は `UNRESOLVED_NAMESPACES(...)` として落とす。
 *   取り込み表を名前空間ごとに持ち分けない限り、別の名前空間の同名の別名で上書きできるためである。
 *
 * ★namespace 直下の取り込み (`use Illuminate\Foundation\Testing\WithCachedConfig;`) は
 *   対象にしない — tests/TestCase.php は override のために取り込む必要があるためである。
 *
 * @param  list<PhpToken>  $tokens
 * @param  array<string, string>  $useMap  alias => FQCN
 * @return list<string> 見つかった trait の完全修飾名
 */
function cacheGuardCachedStateTraitUses(array $tokens, array $useMap): array
{
    $found = [];
    $count = count($tokens);

    $resolve = static function (string $raw) use ($useMap): string {
        $name = ltrim($raw, '\\');

        return $useMap[$name] ?? $name;
    };

    // ★1 ファイルに複数の名前空間があると、取り込み表を持ち分けない限り
    //   別の名前空間の同名の別名で上書きできる。**解決できない形として落とす**。
    $namespaceDeclarations = 0;
    for ($i = 0; $i < $count; $i++) {
        if ($tokens[$i]->is(T_NAMESPACE)) {
            $namespaceDeclarations++;
        }
    }
    if ($namespaceDeclarations > 1) {
        $found[] = "UNRESOLVED_NAMESPACES({$namespaceDeclarations})";
    }

    // (2) Pest の uses(...::class)
    for ($i = 0; $i < $count; $i++) {
        if (! $tokens[$i]->is(T_STRING) || strtolower($tokens[$i]->text) !== 'uses') {
            continue;
        }
        for ($j = $i + 1; $j < $count; $j++) {
            $token = $tokens[$j];
            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }
            if ($token->text !== '(') {
                break;
            }
            for ($k = $j + 1; $k < $count; $k++) {
                if ($tokens[$k]->text === ')') {
                    break;
                }
                if ($tokens[$k]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                    continue;
                }
                if ($tokens[$k]->text === ',' || $tokens[$k]->is([T_DOUBLE_COLON, T_CLASS, T_NS_SEPARATOR])) {
                    continue;
                }
                if ($tokens[$k]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                    if (in_array($resolve($tokens[$k]->text), CACHE_GUARD_CACHED_STATE_TRAITS, true)) {
                        $found[] = $resolve($tokens[$k]->text);
                    }

                    continue;
                }

                // ★`uses($trait)` のように静的に決まらない引数は**未解決として落とす**
                //   (AGENTS.md 走査規約 (b))。通常の `uses(X::class, Y::class)` は
                //   すべて名前として書かれるので誤検出にならない。
                $found[] = 'UNRESOLVED_USES('.$tokens[$k]->text.')';
            }
            break;
        }
    }

    // (1) 型宣言の**本体の中**にある use (trait の取り込み)。
    //     名前空間スコープの取り込みと区別するため、波括弧の深さで判定する
    //     (型宣言の後ろにも名前空間スコープの取り込みを置けるため、位置では区別できない)。
    $baseDepth = cacheGuardNamespaceBodyDepth($tokens);
    $depth = 0;

    for ($i = 0; $i < $count; $i++) {
        if ($tokens[$i]->text === '{') {
            $depth++;

            continue;
        }
        if ($tokens[$i]->text === '}') {
            $depth--;

            continue;
        }
        if (! $tokens[$i]->is(T_USE) || $depth <= $baseDepth) {
            continue;
        }
        for ($j = $i + 1; $j < $count; $j++) {
            $token = $tokens[$j];
            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }
            if ($token->text === ';' || $token->text === '{' || $token->text === '(') {
                break; // `use (...)` の closure 形もここで抜ける
            }
            if ($token->text === ',') {
                continue;
            }
            if (! $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                continue;
            }
            if (in_array($resolve($token->text), CACHE_GUARD_CACHED_STATE_TRAITS, true)) {
                $found[] = $resolve($token->text);
            }
        }
    }

    return $found;
}

/** 走査対象を fail-closed で読む。 */
function cacheGuardReadSource(string $relative): string
{
    $absolute = base_path($relative);
    expect(is_file($absolute))->toBeTrue("{$relative} が実在しません (走査根の改名を疑う)");

    $source = file_get_contents($absolute);
    expect($source)->toBeString("{$relative} を読めません");

    return (string) $source;
}

// ---------------------------------------------------------------------------
// W1 / W6: 結線が bootstrap より前にある
// ---------------------------------------------------------------------------

test('W1: Tests\TestCase::createApplication() は bootstrap() より前に結線する', function (): void {
    expect(cacheGuardBootstrapOrderViolations(
        cacheGuardMethodTokens(TestCase::class, 'createApplication'),
        'Tests\TestCase::createApplication()',
    ))->toBe([]);
});

test('W6: 起動中の負例も同じ関数を同じメソッド内で bootstrap より前に呼ぶ', function (): void {
    // ★負例が別経路で結線していたら「同じ結線を通った」ことの証明にならない。
    //   ファイル全体ではなく**メソッド本体**を反射で切り出して見る
    //   (別メソッドで結線し別メソッドで bootstrap する形を正常扱いしないため)。
    expect(method_exists(IsolatedApplicationProbe::class, 'run'))->toBeTrue();

    expect(cacheGuardBootstrapOrderViolations(
        cacheGuardMethodTokens(IsolatedApplicationProbe::class, 'run'),
        'IsolatedApplicationProbe::run()',
    ))->toBe([]);
});

// ---------------------------------------------------------------------------
// W2 / W3: 全レーンの結線と後始末
// ---------------------------------------------------------------------------

test('W2/W3: tests/Pest.php の期待レーン集合ちょうどが結線と後始末を持つ', function (): void {
    $blocks = cacheGuardLaneBlocks(cacheGuardReadSource('tests/Pest.php'));

    $lanes = array_map(static fn (array $block): array => $block['lanes'], $blocks);
    $expected = CACHE_GUARD_EXPECTED_LANES;
    usort($lanes, static fn (array $a, array $b): int => implode(',', $a) <=> implode(',', $b));

    expect($lanes)->toBe($expected,
        'tests/Pest.php のレーン構成が期待と一致しません。レーンを増減したなら '
        .'CACHE_GUARD_EXPECTED_LANES も同じ変更で直し、新レーンにも guard の結線と後始末を入れてください。');

    foreach ($blocks as $block) {
        expect(cacheGuardLaneWiringViolations($block['tokens'], implode('+', $block['lanes'])))->toBe([]);
    }
});

// ---------------------------------------------------------------------------
// W4: vendor 追随の前提 (cached config / cached routes を使っていない)
// ---------------------------------------------------------------------------

test('W4: クラス本体の use 文と字句の uses() で WithCachedConfig / WithCachedRoutes を適用するテストが 0 件である', function (): void {
    // ★使い始めると createApplication() の写しが vendor と食い違い、
    //   cached 分岐の意味が変わる。使うときは override を写し直すこと。
    $root = base_path('tests');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    $users = [];
    $files = 0;
    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $absolute = $file->getRealPath();
        // ★解決できないパスを黙って除外しない (fail-closed)。
        expect($absolute)->toBeString('走査対象のパスを解決できません: '.$file->getPathname());
        if ($absolute === __FILE__) {
            continue; // 本 gate 自身 (検出したい語を負例の入力として持つ)
        }
        $files++;

        $source = file_get_contents((string) $absolute);
        expect($source)->toBeString('走査対象を読めません: '.$absolute);
        /** @var list<PhpToken> $tokens */
        $tokens = PhpToken::tokenize((string) $source);

        foreach (cacheGuardCachedStateTraitUses($tokens, cacheGuardUseMap($tokens)) as $trait) {
            $users[] = ltrim(str_replace(base_path(), '', (string) $absolute), '/').' → '.$trait;
        }
    }

    expect($files)->toBeGreaterThan(0, 'tests/ の走査が空振りしている');
    expect($users)->toBe([]);
});

test('W4 の正負コントロール: trait の適用を 5 形とも検出し、取り込みだけは検出しない', function (): void {
    $negatives = [
        '短名' => <<<'PHP'
        <?php
        use Illuminate\Foundation\Testing\WithCachedConfig;
        class P { use WithCachedConfig; }
        PHP,
        '別名' => <<<'PHP'
        <?php
        use Illuminate\Foundation\Testing\WithCachedRoutes as R;
        class P { use R; }
        PHP,
        '完全修飾名' => <<<'PHP'
        <?php
        class P { use \Illuminate\Foundation\Testing\WithCachedConfig; }
        PHP,
        'カンマ区切り' => <<<'PHP'
        <?php
        use Illuminate\Foundation\Testing\WithCachedConfig;
        class P { use Countable, WithCachedConfig; }
        PHP,
        'グループ use' => <<<'PHP'
        <?php
        use Illuminate\Foundation\Testing\{WithCachedConfig, WithCachedRoutes as R};
        class P { use R; }
        PHP,
        'Pest の uses()' => <<<'PHP'
        <?php
        use Illuminate\Foundation\Testing\WithCachedConfig;
        uses(WithCachedConfig::class);
        PHP,
    ];

    foreach ($negatives as $label => $fixture) {
        /** @var list<PhpToken> $probe */
        $probe = PhpToken::tokenize($fixture);
        expect(cacheGuardCachedStateTraitUses($probe, cacheGuardUseMap($probe)))
            ->toHaveCount(1, "{$label}: 負例を検出できていません");
    }

    // 正のコントロール: namespace 直下の取り込みだけなら検出しない (tests/TestCase.php が該当)。
    $importOnly = <<<'PHP'
    <?php
    use Illuminate\Foundation\Testing\WithCachedConfig;
    class P {
        public function run(): void {
            $used = WithCachedConfig::class;
        }
    }
    PHP;
    /** @var list<PhpToken> $probe */
    $probe = PhpToken::tokenize($importOnly);
    expect(cacheGuardCachedStateTraitUses($probe, cacheGuardUseMap($probe)))->toBe([]);

    // ★1 ファイルに複数の名前空間がある形も未解決として落とす
    $multipleNamespaces = <<<'PHP'
    <?php
    namespace First;
    use Illuminate\Foundation\Testing\WithCachedConfig as C;
    class P { use C; }
    namespace Second;
    use Vendor\Package\Unrelated as C;
    PHP;
    /** @var list<PhpToken> $multiple */
    $multiple = PhpToken::tokenize($multipleNamespaces);
    $multipleDetected = cacheGuardCachedStateTraitUses($multiple, cacheGuardUseMap($multiple));
    expect(implode(' / ', $multipleDetected))->toContain('UNRESOLVED_NAMESPACES');

    // ★静的に解決できない `uses($trait)` は未解決として落とす (見逃さない)
    $dynamicUses = <<<'PHP'
    <?php
    use Illuminate\Foundation\Testing\WithCachedConfig;
    $trait = WithCachedConfig::class;
    uses($trait);
    PHP;
    /** @var list<PhpToken> $dynamic */
    $dynamic = PhpToken::tokenize($dynamicUses);
    $detected = cacheGuardCachedStateTraitUses($dynamic, cacheGuardUseMap($dynamic));
    expect($detected)->toHaveCount(1);
    expect($detected[0])->toContain('UNRESOLVED_USES');

    // 正のコントロール: 名前で書かれた uses() は未解決にしない
    $staticUses = <<<'PHP'
    <?php
    use Illuminate\Foundation\Testing\RefreshDatabase;
    use Tests\TestCase;
    uses(TestCase::class, RefreshDatabase::class);
    PHP;
    /** @var list<PhpToken> $static */
    $static = PhpToken::tokenize($staticUses);
    expect(cacheGuardCachedStateTraitUses($static, cacheGuardUseMap($static)))->toBe([]);

    // ★型宣言の**後ろ**に置いた名前空間スコープの取り込みも読むこと
    //   (「最初の型宣言で打ち切る」形だと後置の別名を落として負例が黙る)
    $lateImport = <<<'PHP'
    <?php
    namespace Tests\Late;
    class Marker {}
    use Illuminate\Foundation\Testing\WithCachedRoutes as R;
    class P { use R; }
    PHP;
    /** @var list<PhpToken> $late */
    $late = PhpToken::tokenize($lateImport);
    expect(cacheGuardCachedStateTraitUses($late, cacheGuardUseMap($late)))->toHaveCount(1);

    // 取り込み表がグループ use と別名を解決できていること
    /** @var list<PhpToken> $groupUse */
    $groupUse = PhpToken::tokenize(<<<'PHP'
    <?php
    use Illuminate\Foundation\Testing\{WithCachedConfig, WithCachedRoutes as R};
    PHP);
    expect(cacheGuardUseMap($groupUse))->toBe([
        'WithCachedConfig' => 'Illuminate\Foundation\Testing\WithCachedConfig',
        'R' => 'Illuminate\Foundation\Testing\WithCachedRoutes',
    ]);
});

// ---------------------------------------------------------------------------
// W5 / W5b: vendor 本体とローカルの写しの token 完全一致
// ---------------------------------------------------------------------------

test('W5: vendor の createApplication() の token 列が期待値と完全一致する', function (): void {
    expect(cacheGuardTokenListViolations(
        cacheGuardMethodTokens(VendorTestCase::class, 'createApplication'),
        CACHE_GUARD_VENDOR_CREATE_APPLICATION_TOKENS,
        'vendor の createApplication()',
    ))->toBe([],
        'Laravel の createApplication() が変わりました。tests/TestCase.php の写しを'
        .'読み直して更新し、本 gate の期待 token 列も同じ変更で直してください。');
});

test('W5b: ローカルの写しが vendor 期待列 + 許可差分と完全一致する', function (): void {
    expect(cacheGuardLocalCopyViolations(cacheGuardMethodTokens(TestCase::class, 'createApplication')))
        ->toBe([],
            'tests/TestCase.php の createApplication() が期待と一致しません。'
            .'許可差分 (戻り値型 / fail-closed 確認 / 結線 1 行) 以外の変更を入れていないか、'
            .'vendor の写しから文を消していないか確認してください。');

    // #[\Override] は反射で別途見る (getStartLine から切り出したソースに属性行が入る保証が無い)。
    expect((new ReflectionMethod(TestCase::class, 'createApplication'))->getAttributes(Override::class))
        ->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// W7: 空振り検知
// ---------------------------------------------------------------------------

test('W7: 走査と検出器が空振りしていない', function (): void {
    expect(CACHE_GUARD_VENDOR_CREATE_APPLICATION_TOKENS)->not->toBe([]);
    expect(CACHE_GUARD_LOCAL_CREATE_APPLICATION_TOKENS)->not->toBe([]);
    expect(cacheGuardMethodTokens(VendorTestCase::class, 'createApplication'))->not->toBe([]);
    expect(cacheGuardMethodTokens(TestCase::class, 'createApplication'))->not->toBe([]);
    expect(cacheGuardLaneBlocks(cacheGuardReadSource('tests/Pest.php')))->toHaveCount(3);

    // 許可差分の合計が token 数の差と一致する (取りこぼした差分が無い)
    $inserted = array_sum(array_map(
        static fn (array $insertion): int => count($insertion['tokens']),
        CACHE_GUARD_LOCAL_ALLOWED_INSERTIONS,
    ));
    expect(count(CACHE_GUARD_LOCAL_CREATE_APPLICATION_TOKENS) - count(CACHE_GUARD_VENDOR_CREATE_APPLICATION_TOKENS))
        ->toBe($inserted);

    // 検出器が負例に反応する (実在ファイルの構成に依存させない)
    expect(cacheGuardBootstrapOrderViolations(
        cacheGuardNormalizedTokens('<?php $app->make(Kernel::class)->bootstrap();'), 'probe'
    ))->not->toBe([]);
    expect(cacheGuardLaneWiringViolations(cacheGuardNormalizedTokens('<?php pest()->extend(TestCase::class);'), 'probe'))
        ->not->toBe([]);
    expect(cacheGuardTokenListViolations(['a'], ['b'], 'probe'))->not->toBe([]);
    expect(cacheGuardLocalCopyViolations([]))->not->toBe([]);

    // メソッド抽出器そのものが生きている
    $extracted = cacheGuardMethodTokensFromSource(
        '<?php class P { public function run(): void { $a = 1; } }', 'run'
    );
    expect($extracted)->not->toBe([]);
    expect($extracted[0])->toBe('function');
    expect(cacheGuardMethodTokensFromSource('<?php class P {}', 'missing'))->toBe([]);
});

// ---------------------------------------------------------------------------
// W8: 負のコントロール
// ---------------------------------------------------------------------------

test('W8: 結線が bootstrap の後 / 無い / 別メソッドにある形を検出する', function (): void {
    $afterBootstrap = <<<'PHP'
    <?php
    class Probe {
        public function createApplication() {
            $app = require 'bootstrap/app.php';
            $app->make(Kernel::class)->bootstrap();
            PlainDataCacheGuard::registerBeforeBootstrap($app);
            return $app;
        }
    }
    PHP;
    expect(cacheGuardBootstrapOrderViolations(
        cacheGuardMethodTokensFromSource($afterBootstrap, 'createApplication'), 'fixture'
    ))->toHaveCount(1);

    $missing = <<<'PHP'
    <?php
    class Probe {
        public function createApplication() {
            $app = require 'bootstrap/app.php';
            $app->make(Kernel::class)->bootstrap();
            return $app;
        }
    }
    PHP;
    expect(cacheGuardBootstrapOrderViolations(
        cacheGuardMethodTokensFromSource($missing, 'createApplication'), 'fixture'
    ))->toHaveCount(1);

    // ★別メソッドで結線し別メソッドで bootstrap する形。**メソッド抽出 + 順序判定の組**で落ちる
    //   (ファイル全体を渡すと 0 件になってしまう形であり、それが W1/W6 が本体を切り出す理由である)。
    $splitWiring = <<<'PHP'
    <?php
    class Probe {
        public function wire($app) {
            PlainDataCacheGuard::registerBeforeBootstrap($app);
        }
        public function createApplication() {
            $app = require 'bootstrap/app.php';
            $app->make(Kernel::class)->bootstrap();
            return $app;
        }
    }
    PHP;
    expect(cacheGuardBootstrapOrderViolations(
        cacheGuardMethodTokensFromSource($splitWiring, 'createApplication'), 'method-scope'
    ))->toHaveCount(1);
    expect(cacheGuardBootstrapOrderViolations(
        cacheGuardNormalizedTokens($splitWiring), 'file-scope'
    ))->toBe([]);
});

test('W8: レーンの結線・後始末が崩れた 4 形を検出する', function (): void {
    $complete = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            PlainDataCacheGuard::assertInstalled($this->app);
        })
        ->afterEach(function (): void {
            try {
                PlainDataCacheGuard::flushAndFailIfStray();
            } finally {
                PlainDataCacheGuard::reset();
            }
        })
        ->in('Feature', 'Unit');
    PHP;

    $blocks = cacheGuardLaneBlocks($complete);
    expect($blocks)->toHaveCount(1);
    expect($blocks[0]['lanes'])->toBe(['Feature', 'Unit']);
    expect(cacheGuardLaneWiringViolations($blocks[0]['tokens'], 'fixture'))->toBe([]);

    $missingFlush = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            PlainDataCacheGuard::assertInstalled($this->app);
        })
        ->afterEach(function (): void {
            try {
                StrayHttpRequestGuard::flushAndFailIfStray();
            } finally {
                PlainDataCacheGuard::reset();
            }
        })
        ->in('Feature', 'Unit');
    PHP;

    // flush が try ブロックの**外** (afterEach の先頭) にある形
    $flushOutsideTry = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            PlainDataCacheGuard::assertInstalled($this->app);
        })
        ->afterEach(function (): void {
            PlainDataCacheGuard::flushAndFailIfStray();
            try {
                StrayHttpRequestGuard::flushAndFailIfStray();
            } finally {
                StrayHttpRequestGuard::reset();
                PlainDataCacheGuard::reset();
            }
        })
        ->in('Feature', 'Unit');
    PHP;

    // reset が finally ブロックの**外** (try の中) にある形
    $resetOutsideFinally = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            PlainDataCacheGuard::assertInstalled($this->app);
        })
        ->afterEach(function (): void {
            try {
                PlainDataCacheGuard::flushAndFailIfStray();
                PlainDataCacheGuard::reset();
            } finally {
                StrayHttpRequestGuard::reset();
            }
        })
        ->in('Feature', 'Unit');
    PHP;

    // assertInstalled が beforeEach クロージャの**外**にある形
    $assertOutsideBeforeEach = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            $this->withoutVite();
        })
        ->afterEach(function (): void {
            PlainDataCacheGuard::assertInstalled($this->app);
            try {
                PlainDataCacheGuard::flushAndFailIfStray();
            } finally {
                PlainDataCacheGuard::reset();
            }
        })
        ->in('Feature', 'Unit');
    PHP;

    $noTryFinally = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            PlainDataCacheGuard::assertInstalled($this->app);
        })
        ->afterEach(function (): void {
            PlainDataCacheGuard::flushAndFailIfStray();
            PlainDataCacheGuard::reset();
        })
        ->in('Feature', 'Unit');
    PHP;

    // ★flush を持つ try に finally が無く、reset は**別の** try-finally の中にある形。
    //   try と finally を独立に探すと通ってしまうが、flush が投げると catch の return で
    //   reset へ到達しない。
    $unrelatedFinally = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            PlainDataCacheGuard::assertInstalled($this->app);
        })
        ->afterEach(function (): void {
            try {
                PlainDataCacheGuard::flushAndFailIfStray();
            } catch (Throwable) {
                return;
            }

            try {
                StrayHttpRequestGuard::flushAndFailIfStray();
            } finally {
                PlainDataCacheGuard::reset();
            }
        })
        ->in('Feature', 'Unit');
    PHP;

    // ★try 文が条件分岐の中にある形。範囲としては afterEach の内側だが 1 度も実行されない。
    $tryInsideBranch = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            PlainDataCacheGuard::assertInstalled($this->app);
        })
        ->afterEach(function (): void {
            if (false) {
                try {
                    PlainDataCacheGuard::flushAndFailIfStray();
                } finally {
                    PlainDataCacheGuard::reset();
                }
            }
        })
        ->in('Feature', 'Unit');
    PHP;

    // ★assertInstalled が条件分岐の中にある形。同上。
    $assertInsideBranch = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            if (false) {
                PlainDataCacheGuard::assertInstalled($this->app);
            }
        })
        ->afterEach(function (): void {
            try {
                PlainDataCacheGuard::flushAndFailIfStray();
            } finally {
                PlainDataCacheGuard::reset();
            }
        })
        ->in('Feature', 'Unit');
    PHP;

    // ★波括弧を使わない制御構文 / 代替構文 / 短絡評価。いずれも波括弧の深さは 0 だが
    //   「無条件に実行される」ことを保証しない。
    $bracelessIf = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            if (false)
                PlainDataCacheGuard::assertInstalled($this->app);
        })
        ->afterEach(function (): void {
            try {
                PlainDataCacheGuard::flushAndFailIfStray();
            } finally {
                PlainDataCacheGuard::reset();
            }
        })
        ->in('Feature', 'Unit');
    PHP;

    $alternativeSyntax = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            PlainDataCacheGuard::assertInstalled($this->app);
        })
        ->afterEach(function (): void {
            try {
                if (false):
                    PlainDataCacheGuard::flushAndFailIfStray();
                endif;
            } finally {
                PlainDataCacheGuard::reset();
            }
        })
        ->in('Feature', 'Unit');
    PHP;

    $shortCircuit = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            PlainDataCacheGuard::assertInstalled($this->app);
        })
        ->afterEach(function (): void {
            try {
                PlainDataCacheGuard::flushAndFailIfStray();
            } finally {
                $shouldReset && PlainDataCacheGuard::reset();
            }
        })
        ->in('Feature', 'Unit');
    PHP;

    foreach ([
        '波括弧なしの if' => $bracelessIf,
        '代替構文の if' => $alternativeSyntax,
        '短絡評価の右辺' => $shortCircuit,
        'flush が無い' => $missingFlush,
        'flush が try の外' => $flushOutsideTry,
        'reset が finally の外' => $resetOutsideFinally,
        'assertInstalled が beforeEach の外' => $assertOutsideBeforeEach,
        'try / finally の形でない' => $noTryFinally,
        'reset が別の try 文の finally にある' => $unrelatedFinally,
        'try が条件分岐の中にある' => $tryInsideBranch,
        'assertInstalled が条件分岐の中にある' => $assertInsideBranch,
    ] as $label => $damaged) {
        expect($damaged)->not->toBe($complete, "{$label}: 合成入力が完全形と同じになっている");

        $blocks = cacheGuardLaneBlocks($damaged);
        expect(cacheGuardLaneWiringViolations($blocks[0]['tokens'], 'fixture'))
            ->not->toBe([], "{$label}: 検出できていません");
    }
});

test('W8: レーン集合が違う形を検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)->in('Feature');
    pest()->extend(TestCase::class)->in('Unit');
    PHP;

    $lanes = array_map(static fn (array $block): array => $block['lanes'], cacheGuardLaneBlocks($fixture));
    expect($lanes)->not->toBe(CACHE_GUARD_EXPECTED_LANES);
});

test('W8: vendor 本体の token 増減・順序入れ替えを判定関数が検出する', function (): void {
    $expected = CACHE_GUARD_VENDOR_CREATE_APPLICATION_TOKENS;

    $added = $expected;
    $added[] = ';';
    expect(cacheGuardTokenListViolations($added, $expected, 'fixture'))->not->toBe([]);

    $swapped = $expected;
    [$swapped[6], $swapped[7]] = [$swapped[7], $swapped[6]];
    expect(count($swapped))->toBe(count($expected)); // 数だけでは検出できないことの明示
    expect(cacheGuardTokenListViolations($swapped, $expected, 'fixture'))->not->toBe([]);

    expect(cacheGuardTokenListViolations($expected, $expected, 'fixture'))->toBe([]);
});

test('W8: ローカルの写しから既知の文を消した形を判定関数が検出する', function (): void {
    // ★W5 (vendor 側) と W1 (順序) だけでは緑のまま通ってしまう改変を、W5b が捕まえる。
    expect(cacheGuardLocalCopyViolations(CACHE_GUARD_LOCAL_CREATE_APPLICATION_TOKENS))->toBe([]);

    foreach ([
        'traitsUsedByTest の代入' => ['$this', '->', 'traitsUsedByTest', '=', 'class_uses_recursive'],
        'cached config 分岐' => ['WithCachedConfig', '::', 'class'],
        'cached routes 分岐' => ['WithCachedRoutes', '::', 'class'],
        'return $app' => ['return', '$app', ';'],
        '結線 1 行' => ['PlainDataCacheGuard', '::', 'registerBeforeBootstrap'],
    ] as $label => $needle) {
        $position = cacheGuardSequencePosition(CACHE_GUARD_LOCAL_CREATE_APPLICATION_TOKENS, $needle);
        expect($position)->not->toBeNull("{$label} が期待列にありません");

        $damaged = CACHE_GUARD_LOCAL_CREATE_APPLICATION_TOKENS;
        array_splice($damaged, (int) $position, count($needle));

        expect(cacheGuardLocalCopyViolations($damaged))->not->toBe([], "{$label}: 検出できていません");
    }
});
