<?php

declare(strict_types=1);

namespace Tests\Support\Architecture;

use RuntimeException;
use Webmozart\Assert\Assert;

/**
 * arch 表明の**サーフェス**を pin するための走査器 (`ArchBaselineTest` の S4 用)。
 *
 * 本走査器は「どの関数が呼ばれているか」を答えない。利用側の契約は
 * 「**0 件**」または「**ちょうど 1 件**」という**件数**だけなので、
 * **末尾セグメントの一致で拾いすぎる方向へ倒せば名前解決は 1 行も要らない**。
 * したがって取り込み対応表・名前空間の把握・`T_NAME_RELATIVE` の特別扱い・
 * 複数 namespace 宣言の検出・「未解決」という結果型を**どれも持たない**
 * (到達できない結果型を収集しない = AGENTS.md 共通規約 (d))。
 * fail-closed は次の 2 つで担保する —
 * (1) トークン化できない入力は `RuntimeException` (`ArchTokenStream` の `TOKEN_PARSE`)、
 * (2) 判定が**拾いすぎる方向にしか倒れない** (名前空間を解決しないので
 * `A\B\call_user_func()` も拾う)。
 *
 * ★**比較の単位は「名前トークンを `\` で割ったセグメントの、大小無視の完全一致」である**
 *   (共通規約 (e))。部分文字列一致・正規表現の語境界には一切頼らない。
 *
 * ★**大文字小文字を無視する**。PHP の関数呼び出しもメソッド名も大小無視で成立するので、
 *   `\CALL_USER_FUNC(` や `->TOBEUSED()` を見逃すと迂回口になる。
 *   **`GlobalFunctionCallScanner` (S2) は逆に大小を区別する** — あちらは
 *   「Pest が検出する使用の証明」なので Pest の粒度 (完全一致) に揃える必要がある。
 *   **この非対称は意図的である**。
 *
 * ★**コメント (`T_COMMENT` / `T_DOC_COMMENT`) と文字列リテラルの中身は数えない**。
 *   識別子ではないからである。これは形式的な注記ではなく**現に効いている分岐**で、
 *   素の文字列検索で数えると `preset` が 1 件 (`ForbiddenStatementTokenInvariantTest` の
 *   docblock)、callable 語彙が 2 件 (`CacheGuardWiringGateTest` /
 *   `JobDeferralTerminationGateTest` の docblock) 一致して S4 は初日から赤くなる。
 *   この除外を共通規約 (b) の「未解決の黙殺」と取り違えないこと —
 *   **語彙を説明する散文は実行経路ではない**。
 *
 * ★**保証しないもの (検出力を主張しない構文)**: 可変関数 (`$f = 'call_user_func'; $f()`) /
 *   文字列連結で組み立てた名前 / `ReflectionFunction` / `ReflectionMethod` 経由の反射呼び出し。
 *   メンバ名を動的にして綴りを回避する形だけは {@see self::dynamicMemberSites()} の
 *   exact-fit 目録が別途塞ぐ。
 */
final class ArchSurfaceScanner
{
    /** インスタンス化しない (純関数の置き場)。 */
    private function __construct() {}

    /**
     * 名前を表すトークン (`\` で割って末尾セグメントを取る対象)。
     *
     * @var list<int>
     */
    private const array NAME_TOKENS = [
        T_STRING,
        T_NAME_QUALIFIED,
        T_NAME_FULLY_QUALIFIED,
        T_NAME_RELATIVE,
    ];

    /**
     * ファイル最上位に置かれると**以降の宣言を実行させない**トークン。
     *
     * @var list<int>
     */
    private const array ABORT_TOKENS = [
        T_RETURN,
        T_EXIT,
        T_THROW,
        T_GOTO,
        T_HALT_COMPILER,
    ];

    /**
     * ファイル最上位に置かれると**以降の宣言を条件付きにできる**制御構造の開始トークン。
     *
     * ★`declare` は入れない。`declare(strict_types=1);` は宣言を囲まないので、
     *   入れると全ファイルが 1 件を持つことになり「ちょうど 1 件」の契約が成り立たない。
     * ★`catch` / `finally` / `else` 系の**継続節**も入れない。継続節は必ず対応する
     *   開始トークン (`try` / `if`) を伴うので、開始側だけ見れば取りこぼさない。
     *
     * @var list<int>
     */
    private const array CONTROL_STRUCTURE_TOKENS = [
        T_IF,
        T_WHILE,
        T_DO,
        T_FOR,
        T_FOREACH,
        T_SWITCH,
        T_TRY,
        T_MATCH,
    ];

    /**
     * 直前に来ると「関数呼び出しではない (メンバ名・宣言)」と判定するトークン。
     *
     * @var list<int>
     */
    private const array DISQUALIFYING_PREVIOUS_TOKENS = [
        T_OBJECT_OPERATOR,
        T_NULLSAFE_OBJECT_OPERATOR,
        T_DOUBLE_COLON,
        T_FUNCTION,
        T_NEW,
        T_CONST,
        T_STRING,
        T_NAME_QUALIFIED,
        T_NAME_FULLY_QUALIFIED,
        T_NAME_RELATIVE,
    ];

    /**
     * 識別子トークンの完全一致 (大小無視) で出現位置を返す。
     *
     * ★**0 件でもキーを残す** (「対象名が消えた」と「出現が 0 件」を利用側が区別できるようにする)。
     *
     * @param  list<string>  $identifiers
     * @return array<string, list<array{line: int, index: int}>>
     */
    public static function identifierSites(string $source, array $identifiers): array
    {
        $sites = [];
        $lowered = [];
        foreach ($identifiers as $identifier) {
            $sites[$identifier] = [];
            $lowered[mb_strtolower($identifier)] = $identifier;
        }

        $tokens = ArchTokenStream::significantTokens($source, self::class);

        foreach ($tokens as $index => $token) {
            if ($token['id'] !== T_STRING) {
                continue;
            }

            $key = $lowered[mb_strtolower($token['text'])] ?? null;
            if ($key === null) {
                continue;
            }

            $sites[$key][] = ['line' => $token['line'], 'index' => $index];
        }

        return $sites;
    }

    /**
     * 指定した有意トークン位置から文末 `;` までの**綴り列**を返す (チェーンの完全一致照合用)。
     *
     * ★開始位置が有意トークン列の範囲外のとき、および `;` に達する前に EOF になったときは
     *   **黙って空列や EOF までの列を返さず例外**にする (fail-closed)。
     *
     * @return list<string>
     */
    public static function statementTokens(string $source, int $index): array
    {
        $tokens = ArchTokenStream::significantTokens($source, self::class);

        Assert::greaterThanEq($index, 0, "走査開始位置が範囲外である: {$index}");
        Assert::lessThan($index, count($tokens), "走査開始位置が範囲外である: {$index}");

        $statement = [];
        for ($cursor = $index, $total = count($tokens); $cursor < $total; $cursor++) {
            $statement[] = $tokens[$cursor]['text'];

            if (ArchTokenStream::isPunctuation($tokens, $cursor, ';')) {
                return $statement;
            }
        }

        throw new RuntimeException(
            self::class.": 位置 {$index} から文末 `;` に達しないまま EOF になった (切り出しに失敗)"
        );
    }

    /**
     * 指定位置の**直前** `$length` 個の有意トークンの綴り列を返す (チェーンを囲む構文の照合用)。
     *
     * ★範囲外は**黙って短い列を返さず例外**にする (fail-closed)。
     *
     * @return list<string>
     */
    public static function tokensBefore(string $source, int $index, int $length): array
    {
        $tokens = ArchTokenStream::significantTokens($source, self::class);

        Assert::greaterThanEq($length, 0, "取り出す長さが負である: {$length}");
        Assert::greaterThanEq($index - $length, 0, "走査開始位置が範囲外である: {$index} - {$length}");
        Assert::lessThanEq($index, count($tokens), "走査開始位置が範囲外である: {$index}");

        $texts = [];
        for ($cursor = $index - $length; $cursor < $index; $cursor++) {
            $texts[] = $tokens[$cursor]['text'];
        }

        return $texts;
    }

    /**
     * 指定位置から**後ろ** `$length` 個の有意トークンの綴り列を返す (チェーンの**後置**の照合用)。
     *
     * ★{@see self::tokensBefore()} と対になる。前 (囲みのヘッダー) と中 (表明の文) だけを
     *   固定すると、**閉じたあとに実行修飾を後置する**形 (`})->skip();` / `})->todo();`) が
     *   1 つも検査に現れない。7 本は**登録されたまま評価されなくなる**ので、
     *   登録簿への問い合わせ (`ArchBaselineTest` の S4-3c) でも捕まらない。
     *   後置の綴りまで exact-fit で固定して初めて、生成文が閉じていることを言える。
     *
     * ★範囲外は**黙って短い列を返さず例外**にする (fail-closed)。
     *
     * @return list<string>
     */
    public static function tokensAfter(string $source, int $index, int $length): array
    {
        $tokens = ArchTokenStream::significantTokens($source, self::class);

        Assert::greaterThanEq($length, 0, "取り出す長さが負である: {$length}");
        Assert::greaterThanEq($index, 0, "走査開始位置が範囲外である: {$index}");
        Assert::lessThanEq($index + $length, count($tokens), "走査開始位置が範囲外である: {$index} + {$length}");

        $texts = [];
        for ($cursor = $index; $cursor < $index + $length; $cursor++) {
            $texts[] = $tokens[$cursor]['text'];
        }

        return $texts;
    }

    /**
     * 指定位置の時点で**開いたままの波括弧の深さ**を返す。
     *
     * ★「チェーンが波括弧の外側を持たないこと」を固定するために使う。
     *   `if (false) { … }` のような**実行されない位置**へチェーンを移す形は、
     *   綴り列の照合だけでは見抜けない (チェーン自身の綴りは変わらない) ため、
     *   囲みの深さを別に測る。
     * ★**保証しないもの (到達可能性ではない)**: 波括弧を持たない制御構文
     *   (`if (false)` の直後に改行して文を書く形 / `if (…): … endif;` の代替構文) や
     *   先行する `return` / `exit` は**深さに現れない**。
     *   本メソッドは「囲む波括弧が何段あるか」しか答えない。
     *   到達可能性を要求する利用側は、**別の手段でそれを証明すること**
     *   (`ArchBaselineTest` は S4-3c で Pest の登録簿へ実行時に問い合わせている)。
     * ★文字列補間の `{$x}` / `${x}` も**開き波括弧として数える** (対応する `}` が
     *   単一文字トークンとして現れるので、数えないと深さがずれる)。
     * ★**深さが負になる場合を扱う分岐は持たない**。入力は `TOKEN_PARSE` を通っており
     *   波括弧の対応は構文として保証されるので、その状態は到達しない
     *   (到達できない結果を作らない = 共通規約 (d))。トークン化できない入力は
     *   `ArchTokenStream` が例外にする。
     */
    public static function braceDepthAt(string $source, int $index): int
    {
        $tokens = ArchTokenStream::significantTokens($source, self::class);

        Assert::greaterThanEq($index, 0, "走査位置が範囲外である: {$index}");
        Assert::lessThanEq($index, count($tokens), "走査位置が範囲外である: {$index}");

        return self::braceDepths($tokens)[$index];
    }

    /**
     * **ファイル最上位 (波括弧の深さ 0) で実行を打ち切る文**の位置を返す。
     *
     * ★これは「そのファイルのテスト宣言が実行されるか」を**ファイルの外から**確かめるための走査である。
     *   Pest のテストファイルは素のスクリプトなので、最上位に `return;` を 1 行置くだけで
     *   以降の宣言が**1 つも登録されなくなる**。その状態では**同じファイルに置いた自己検査も
     *   一緒に消える**ので、自己検査では原理的に検出できない
     *   (`ArchBaselineScannerTest` が別ファイルからこの走査を使って固定している)。
     *
     * 打ち切る文とするのは `return` / `exit` / `die` / `throw` / `goto` / `__halt_compiler` の 6 語である。
     *
     * ★**保証しないもの**: 無限ループ (`while (true);`) や、最上位で呼んだ関数の中から
     *   プロセスを終了させる形は検出しない。**この構文について検出力を主張しない**。
     *   閉じ括弧の内側 (関数・クロージャ・制御構造の本体) にある同じ語は**数えない** —
     *   そこの `return` はファイルの実行を打ち切らないからである。
     * ★**「打ち切る」形しか見ない**。宣言を**条件付きにして飛ばす**形
     *   (`if (false): … endif;` で丸ごと囲む) はここに現れないので、
     *   そちらは {@see self::topLevelControlStructureSites()} が受け持つ。
     *
     * @return list<array{name: string, line: int, index: int}>
     */
    public static function topLevelAbortSites(string $source): array
    {
        $tokens = ArchTokenStream::significantTokens($source, self::class);
        $depths = self::braceDepths($tokens);

        $sites = [];
        foreach ($tokens as $index => $token) {
            if ($token['id'] === null || ! in_array($token['id'], self::ABORT_TOKENS, true)) {
                continue;
            }

            if ($depths[$index] !== 0) {
                continue; // 関数・クロージャ・制御構造の本体。ファイルの実行は打ち切らない
            }

            $sites[] = ['name' => $token['text'], 'line' => $token['line'], 'index' => $index];
        }

        return $sites;
    }

    /**
     * **ファイル最上位 (波括弧の深さ 0) に置かれた制御構造の開始位置**を返す。
     *
     * ★これは {@see self::topLevelAbortSites()} の対になる走査である。あちらが見るのは
     *   `return;` のように**実行を打ち切る**形だけで、宣言を**条件付きにして飛ばす**形
     *   — ファイル全体を `if (false): … endif;` で囲む — は打ち切りトークンを 1 つも
     *   持たないため現れない。**波括弧の深さにも現れない** (代替構文は `{` を使わない)。
     *   その形は「宿主ファイルの最上位に制御構造がちょうど 1 つ (チェーン自身の `foreach`)
     *   しかない」という**件数の契約**でしか捕まえられない
     *   (`ArchBaselineScannerTest` の外部自己検査がその契約を持つ)。
     *
     * 数えるのは {@see self::CONTROL_STRUCTURE_TOKENS} の 8 語である。
     *
     * ★**保証しないもの**: 深さ 0 に無い制御構造 (関数・クロージャ・制御構造の本体) は
     *   数えない。波括弧つきで囲む形はここではなく**深さの検査**が受け持つ
     *   (囲まれた側の深さが 1 以上になる)。`goto` による飛び越しは
     *   {@see self::topLevelAbortSites()} 側の語彙にある。
     * ★**arrow function (`fn () => …`) の式の中は区別しない**。波括弧を持たないので、
     *   最上位に書かれた `fn () => match ($x) { … }` の `match` はここに 1 件として現れる。
     *   **拾いすぎる側の誤差**であり (見逃しではない)、宿主ファイルの
     *   「ちょうど 1 件」契約はこの形を持たないので実害がない。
     *   同じことが {@see self::topLevelAbortSites()} の `throw` にも当てはまる
     *   (`fn () => throw new …` は最上位の打ち切りとして数える)。
     *
     * @return list<array{name: string, line: int, index: int}>
     */
    public static function topLevelControlStructureSites(string $source): array
    {
        $tokens = ArchTokenStream::significantTokens($source, self::class);
        $depths = self::braceDepths($tokens);

        $sites = [];
        foreach ($tokens as $index => $token) {
            if ($token['id'] === null || ! in_array($token['id'], self::CONTROL_STRUCTURE_TOKENS, true)) {
                continue;
            }

            if ($depths[$index] !== 0) {
                continue; // 関数・クロージャ・制御構造の本体。最上位の宣言を条件付きにはできない
            }

            $sites[] = ['name' => $token['text'], 'line' => $token['line'], 'index' => $index];
        }

        return $sites;
    }

    /**
     * **ファイル最上位 (波括弧の深さ 0) で呼ばれている関数名**の重複なしの一覧 (小文字・昇順)。
     *
     * ★これは {@see self::topLevelAbortSites()} / {@see self::topLevelControlStructureSites()} に
     *   続く**3 つ目の短絡経路**を塞ぐための走査である。前 2 つは「宣言そのものを消す」形を見るが、
     *   Pest には**宣言を残したまま実行だけ止める**ファイル単位の仕掛けがある —
     *   最上位に `beforeEach(fn () => $this->markTestSkipped())` を 1 つ置くと、
     *   **そのファイルの全テストが登録も生成もされたまま skip される**。
     *   このとき綴り (ヘッダー・表明・後置)・波括弧の深さ・打ち切り・最上位の制御構造・
     *   各テストの登録内容 (`TestCaseMethodFactory` の状態) の**どれ 1 つも変わらない**。
     *   捕まえられるのは「宿主ファイルの最上位の**素の関数呼び出しが `test` だけ**である」
     *   という契約だけである。`uses()` / `pest()` / `describe()` / `covers()` など、
     *   ファイル単位で挙動を変える**素の関数**の入口はすべてこの 1 つの契約に含まれる
     *   (deny-by-default。禁止する名前の一覧を持たないので、Pest が入口を増やしても勝手に効く)。
     *
     * ★**数えるのは素の関数呼び出しだけ**である。`A::b()` と `$x->b()` は
     *   {@see self::calledNameAt()} が直前トークンで除外する。`foreach (ArchBaseline::ruleIds() as …)` が
     *   最上位にあっても数に入らないのはこのためである。
     *   ★**保証しないもの**: これは「メンバ呼び出しはファイル単位の仕掛けになり得ない」という
     *   一般的な主張ではない。static メソッドや共有オブジェクトのメソッドが Pest の
     *   ファイル単位の状態を変える API は設計可能である。**現行の Pest が持つ file-scoped 入口が
     *   素の関数呼び出しから始まる**という事実に依拠しているだけであり、
     *   メンバ呼び出し経由の入口は本走査の保証範囲外である。
     *
     * ★**保証しないもの**: 名前が静的に決まらない呼び出し (可変関数・反射) は数えない
     *   (走査器全体の共通の限界。クラス docblock の「保証しないもの」を参照)。
     *   arrow function (`fn () => beforeEach(…)`) の式の中は波括弧を持たないので
     *   **最上位として数える** — 拾いすぎる側の誤差である。
     *
     * @return list<string>
     */
    public static function topLevelCallNames(string $source): array
    {
        $tokens = ArchTokenStream::significantTokens($source, self::class);
        $depths = self::braceDepths($tokens);

        $names = [];
        for ($index = 0, $total = count($tokens); $index < $total; $index++) {
            if ($depths[$index] !== 0) {
                continue;
            }

            $name = self::calledNameAt($tokens, $index);
            if ($name === null) {
                continue;
            }

            $names[$name] = true;
        }

        $unique = array_keys($names);
        sort($unique);

        return $unique;
    }

    /**
     * 各トークン位置の**直前までに開いたままの波括弧の深さ**の表 (要素数はトークン数 + 1)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return list<int>
     */
    private static function braceDepths(array $tokens): array
    {
        $depth = 0;
        $depths = [0];

        foreach ($tokens as $token) {
            if ($token['id'] === T_CURLY_OPEN || $token['id'] === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif ($token['id'] === null && $token['text'] === '{') {
                $depth++;
            } elseif ($token['id'] === null && $token['text'] === '}') {
                $depth--;
            }

            $depths[] = $depth;
        }

        return $depths;
    }

    /**
     * **メンバ名の綴りが静的に決まらない**参照の位置を返す。
     *
     * 動的とするのは次の 5 形:
     *   (i) `->{expr}` / (ii) `?->{expr}` / (iii) `::{expr}` /
     *   (iv) `->$var` / `?->$var` / (v) `::$var` が**直後に `(` を伴う**形
     *        (PHP の可変静的メソッド呼び出し `A::$m()`)
     *
     * ★**`(` を伴わない `::$var` は動的ではない**。`self::$violations` のような
     *   **静的プロパティ参照**で、メンバ名 (`violations`) は綴りとして確定している。
     *   混ぜると `tests/` 全数の実測が 1 桁件から数十件へ膨らみ、増えた分はすべて
     *   arch と無関係な静的プロパティ参照になる。
     * ★`->` 側は**メソッド呼び出しとプロパティ参照を区別しない** (広く数える)。
     *   区別するのは `::` 側だけで、**判定を狭める唯一の場所**である。
     *
     * @return list<array{line: int, index: int}>
     */
    public static function dynamicMemberSites(string $source): array
    {
        $tokens = ArchTokenStream::significantTokens($source, self::class);
        $total = count($tokens);

        $sites = [];
        for ($index = 0; $index < $total; $index++) {
            $id = $tokens[$index]['id'];
            $nextId = $tokens[$index + 1]['id'] ?? null;

            if ($id === T_OBJECT_OPERATOR || $id === T_NULLSAFE_OBJECT_OPERATOR) {
                if (ArchTokenStream::isPunctuation($tokens, $index + 1, '{') || $nextId === T_VARIABLE) {
                    $sites[] = ['line' => $tokens[$index]['line'], 'index' => $index];
                }

                continue;
            }

            if ($id !== T_DOUBLE_COLON) {
                continue;
            }

            if (ArchTokenStream::isPunctuation($tokens, $index + 1, '{')) {
                $sites[] = ['line' => $tokens[$index]['line'], 'index' => $index];

                continue;
            }

            if ($nextId === T_VARIABLE && ArchTokenStream::isPunctuation($tokens, $index + 2, '(')) {
                $sites[] = ['line' => $tokens[$index]['line'], 'index' => $index];
            }
        }

        return $sites;
    }

    /**
     * 対象の関数名が**呼び出し位置**または**関数取り込み**として現れる箇所を返す。
     *
     * **名前解決は一切行わない**。末尾セグメントの一致 (大小無視) で拾いすぎる方向へ倒す。
     *
     * - `call`: 直後が `(` の名前トークン (`T_STRING` / `T_NAME_QUALIFIED` /
     *   `T_NAME_FULLY_QUALIFIED` / `T_NAME_RELATIVE`) で、`\` で割った**末尾セグメント**が
     *   対象名と大小無視で一致するもの。直前が `->` / `?->` / `::` / `function` / `new` /
     *   `const` / 名前トークンのいずれかなら**メンバ名・宣言なので拾わない**
     * - `import`: `use` 文 (先頭から `;` まで) に現れる**各名前トークンの末尾セグメント**が
     *   対象名と**大小無視の完全一致**をするもの。
     *   **部分文字列一致ではない** (共通規約 (e))。`use function A\mycall_user_func;` /
     *   `A\not_call_user_func` / `A\call_user_func_x` は**一致しない**。
     *   **構造を解かない**ので、カンマ区切り (`use function A\f, B\g as h;`)・
     *   group use (`use function A\{f, g as h};`)・mixed group use (`use A\{function f};`)・
     *   別名つき (`use function A\b as f;` の `f` はそれ自体が名前トークン) が
     *   **すべて同じ 1 本の規則で捕まる**。
     *   **名前空間側の中間セグメントは見ない** — 記号を取り込まないので判定に寄与せず、
     *   見ると `use Pest\Arch\Support\Composer;` のような正当なクラス取り込みを誤検出する
     *
     * ★**クロージャの `use ($x)` 句は取り込みではないので走査しない**。判定は
     *   「`use` の直後が `(` か」だけで、これは**迂回口にならない** —
     *   取り込み構文に「`use` の直後が `(`」の形は存在しないからである。
     *
     * ★`call` は**有意トークン列上の `index` を必ず含む**。利用側 (S4) はこの `index` を
     *   {@see self::statementTokens()} へ渡してチェーンを切り出すため、行番号だけでは
     *   実装できない (同じ行に複数の呼び出しがあると一意にならない)。
     *
     * ★`name` は**引数で渡した対象名をそのまま返す** (ソース上の綴りではない)。
     *   利用側は対象名でまとめるので、大小の揺れを持ち込まない。
     *
     * @param  list<string>  $functionNames  セグメントの完全一致で照合する対象 (小文字で書く)
     * @return list<array{status: 'call', name: string, line: int, index: int}
     *              |array{status: 'import', name: string, line: int}>
     */
    public static function functionNameSites(string $source, array $functionNames): array
    {
        $targets = [];
        foreach ($functionNames as $functionName) {
            $targets[mb_strtolower($functionName)] = $functionName;
        }

        $tokens = ArchTokenStream::significantTokens($source, self::class);
        $total = count($tokens);

        $sites = [];
        for ($index = 0; $index < $total; $index++) {
            $token = $tokens[$index];

            if ($token['id'] === T_USE) {
                foreach (self::importedNames($tokens, $index) as $imported) {
                    $target = $targets[$imported['name']] ?? null;
                    if ($target !== null) {
                        $sites[] = self::importSite($target, $imported['line']);
                    }
                }

                continue;
            }

            $name = self::calledNameAt($tokens, $index);
            if ($name === null) {
                continue;
            }

            $target = $targets[$name] ?? null;
            if ($target === null) {
                continue;
            }

            $sites[] = self::callSite($target, $token['line'], $index);
        }

        return $sites;
    }

    /** @return array{status: 'call', name: string, line: int, index: int} */
    private static function callSite(string $name, int $line, int $index): array
    {
        return ['status' => 'call', 'name' => $name, 'line' => $line, 'index' => $index];
    }

    /** @return array{status: 'import', name: string, line: int} */
    private static function importSite(string $name, int $line): array
    {
        return ['status' => 'import', 'name' => $name, 'line' => $line];
    }

    /**
     * 指定位置が関数呼び出しなら、その名前の**末尾セグメント (小文字)** を返す。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function calledNameAt(array $tokens, int $index): ?string
    {
        $token = $tokens[$index];
        if ($token['id'] === null || ! in_array($token['id'], self::NAME_TOKENS, true)) {
            return null;
        }

        if (! ArchTokenStream::isPunctuation($tokens, $index + 1, '(')) {
            return null;
        }

        $previousId = $tokens[$index - 1]['id'] ?? null;
        if ($previousId !== null && in_array($previousId, self::DISQUALIFYING_PREVIOUS_TOKENS, true)) {
            return null;
        }

        return self::lastSegment($token['text']);
    }

    /**
     * `use` 文 (先頭から `;` まで) に現れる名前トークンの**末尾セグメント (小文字)** を返す。
     *
     * ★**取り込まれる記号の名前は、必ずどれかの名前トークンの末尾セグメントとして現れる**。
     *   `use function A\arch;` は `A\arch` の末尾、`use function A\b as arch;` の別名 `arch` は
     *   それ自体が 1 つの名前トークン、group use (`use function A\{f, arch as g};`) と
     *   mixed group use (`use A\{function arch};`) では波括弧内の各要素が名前トークンになる。
     *   したがって末尾セグメントだけを見れば**取り込みの全形を捕まえられる**。
     * ★**名前空間側のセグメントは見ない**。中間セグメントは記号を取り込まないので
     *   判定に寄与せず、見ると `use Pest\Arch\Support\Composer;` (`Arch` セグメント) のような
     *   **正当なクラス取り込みを誤検出する** (Pint の `fully_qualified_strict_types` が
     *   完全修飾参照を取り込みへ書き換えるため、この形は実際に発生する)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return list<array{name: string, line: int}>
     */
    private static function importedNames(array $tokens, int $useIndex): array
    {
        // クロージャの `use ($captured)` 句は取り込みではない (取り込み構文に
        // 「use の直後が `(`」の形は存在しないので、この判定は迂回口にならない)。
        if (ArchTokenStream::isPunctuation($tokens, $useIndex + 1, '(')) {
            return [];
        }

        $names = [];
        for ($cursor = $useIndex + 1, $total = count($tokens); $cursor < $total; $cursor++) {
            $token = $tokens[$cursor];

            if (ArchTokenStream::isPunctuation($tokens, $cursor, ';')) {
                break;
            }

            if ($token['id'] === null || ! in_array($token['id'], self::NAME_TOKENS, true)) {
                continue;
            }

            $names[] = ['name' => self::lastSegment($token['text']), 'line' => $token['line']];
        }

        return $names;
    }

    /** 名前トークンの綴りを `\` で割った末尾セグメント (小文字)。 */
    private static function lastSegment(string $name): string
    {
        $segments = explode('\\', $name);

        return mb_strtolower($segments[count($segments) - 1]);
    }
}
