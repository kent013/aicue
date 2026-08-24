<?php

declare(strict_types=1);

namespace Tests\Support\RawEnv;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\PhpTokenScan;

/**
 * メソッド本体の**構造**を字句の位置関係だけで判定する純関数の走査器。
 *
 * 家系の正典 raw-env-snapshot-restore v1 の未決論点 q2 —
 * 「適用の途中で `putenv()` が失敗したときの巻き戻り」と
 * 「復元が最初の失敗で止まらないこと」は**動的には作れない**
 * (検証を通ったキーで `putenv()` を失敗させる状況をテストから作れず、
 *  失敗を注入する差し替え口を新設すると「本番では誰も使わない差し替え口」が増える) —
 * を、**構造の固定**で代えるために置く。
 *
 * ★**この判定は意図的に脆い**。`RawEnvSnapshot` の中身を書き換えると赤くなるのが正しい挙動である
 *   (「適用が try の外へ出ていないか」を人手のレビューに委ねないための pin)。
 *   赤くなったときは判定を緩めるのではなく、**構造が本当に変わってよいのか**を確認すること。
 * ★判定はトークン位置の比較だけで行い、行番号・インデント・整形 (Pint) には依存させない。
 *   構文解析ライブラリ (nikic/php-parser) は vendor に推移依存としてしか存在しないため使わない。
 *
 * ── 走査対象 ────────────────────────────────────────────────────────
 *
 *  `ReflectionMethod` の開始行〜終了行で切り出した断片を `<?php` を前置してから
 *  `Tests\Support\PhpTokenScan::normalize()` にかけたトークン列
 *  (空白・コメント・DocComment を除いた添字連番のリスト)。
 *  切り出した断片は `public static function …` から始まり PHP 開始タグを持たないため、
 *  前置しないと `T_INLINE_HTML` になる。同じ正規化を 2 本持たない。
 *
 * ── 保証しないもの (誇張しない) ─────────────────────────────────────────
 *
 *  - **メソッド本体の外にある構造**は見ない (呼び出し元・親クラス・trait)。
 *  - **呼び出し先の実装**は見ない (`self::apply()` の中で何が起きるかは対象外)。
 *  - **実行時に本当に巻き戻ることそのもの**は検査していない。それは動的には検査できず、
 *    だからこの走査がある。「構造がこの形である」以上のことを主張しない。
 *  - `if` の本体が波括弧で囲まれていない形 (`if (…) $x = 1;`) は**受理せず例外**にする
 *    (本リポジトリは Pint が波括弧を強制するため母集団に現れない)。
 *  - **母集団の非空は契約しない**。候補が 0 件でも例外にせず空を返す
 *    (非空を要求するのは検出器を**使う側**の gate / 契約テストである)。
 */
final class RawEnvGuardStructure
{
    /** 制御フローとして受け付ける token id (これ以外は fail-closed で例外)。 */
    private const array CONTROL_FLOW_TOKEN_IDS = [T_THROW, T_RETURN, T_BREAK, T_CONTINUE];

    /** インスタンス化しない (純関数の置き場)。 */
    private function __construct() {}

    /**
     * メソッド本体のトークン列を返す (fail-closed: メソッドが無い / 読めなければ例外)。
     *
     * @param  class-string  $class
     * @return list<array{id: int|null, text: string, line: int}>
     */
    public static function methodTokens(string $class, string $method): array
    {
        if (! method_exists($class, $method)) {
            throw new RuntimeException("method not found: {$class}::{$method}()");
        }

        $reflection = new ReflectionMethod($class, $method);
        $file = $reflection->getFileName();
        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();

        if ($file === false || $start === false || $end === false) {
            throw new RuntimeException("method source is not available: {$class}::{$method}()");
        }

        $lines = file($file);

        if ($lines === false) {
            throw new RuntimeException("method source file is not readable: {$file}");
        }

        return self::tokenize(implode('', array_slice($lines, $start - 1, $end - $start + 1)));
    }

    /**
     * メソッド本体の断片 (PHP 開始タグを持たない) をトークン列へ正規化する。
     *
     * @return list<array{id: int|null, text: string, line: int}>
     */
    public static function tokenize(string $methodSource): array
    {
        return PhpTokenScan::normalize('<?php '.$methodSource);
    }

    /**
     * 指定 token id の出現位置をすべて返す。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return list<int>
     */
    public static function findTokens(array $tokens, int $id): array
    {
        $found = [];
        foreach ($tokens as $index => $token) {
            if ($token['id'] === $id) {
                $found[] = $index;
            }
        }

        return $found;
    }

    /**
     * 指定 token id が**ちょうど 1 件**であることを要求し、その本体の範囲を返す (fail-closed)。
     *
     * 「存在する」だけを見ると、`try` の内と外の両方に候補がある状態でも緑になる。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return array{int, int} [開き波括弧の添字, 閉じ波括弧の添字]
     */
    public static function soleBlockRange(array $tokens, int $id): array
    {
        $found = self::findTokens($tokens, $id);

        if (count($found) !== 1) {
            throw new RuntimeException(
                'expected exactly one occurrence of token id '.$id.', found '.count($found)
            );
        }

        return self::blockRange($tokens, $found[0]);
    }

    /**
     * キーワードの本体 `{ … }` のトークン範囲を返す (対応が取れなければ例外)。
     *
     * 文字列補間の `T_CURLY_OPEN` / `T_DOLLAR_OPEN_CURLY_BRACES` は開き括弧として同列に数える。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return array{int, int}
     */
    public static function blockRange(array $tokens, int $keywordIndex): array
    {
        $count = count($tokens);
        $open = null;

        for ($i = $keywordIndex + 1; $i < $count; $i++) {
            if (self::isBraceOpen($tokens[$i])) {
                $open = $i;

                break;
            }
        }

        if ($open === null) {
            throw new RuntimeException('block body not found after token index '.$keywordIndex);
        }

        $depth = 0;

        for ($i = $open; $i < $count; $i++) {
            if (self::isBraceOpen($tokens[$i])) {
                $depth++;

                continue;
            }

            if ($tokens[$i]['id'] === null && $tokens[$i]['text'] === '}') {
                $depth--;

                if ($depth === 0) {
                    return [$open, $i];
                }
            }
        }

        throw new RuntimeException('unbalanced braces starting at token index '.$open);
    }

    /**
     * 添字が指定範囲の**内側**にあるか (境界の波括弧そのものは含まない)。
     *
     * @param  array{int, int}  $range
     */
    public static function isWithin(array $range, int $index): bool
    {
        return $index > $range[0] && $index < $range[1];
    }

    /**
     * 指定範囲の内側にある添字だけを残す。
     *
     * @param  list<int>  $indexes
     * @param  array{int, int}  $range
     * @return list<int>
     */
    public static function indexesWithin(array $indexes, array $range): array
    {
        return array_values(array_filter(
            $indexes,
            static fn (int $index): bool => self::isWithin($range, $index),
        ));
    }

    /**
     * `foreach (<式> as …)` の形でその式を**直接**回している foreach の位置。
     *
     * ★式は**正規化済みのトークンの綴りの列**で渡す (`['$changes']` / `['$keys']` /
     *   `['$this', '->', 'state']`)。丸括弧を開いた最初のトークンから綴りが完全一致で連続し、
     *   次のトークンが `T_AS` であることを見る。
     *   `foreach (array_values($this->state) as …)` は最初のトークンが `array_values` なので
     *   候補に入らない (誤検出しない)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  list<string>  $expressionTexts
     * @return list<int>
     */
    public static function foreachOverExpression(array $tokens, array $expressionTexts): array
    {
        if ($expressionTexts === []) {
            throw new InvalidArgumentException('foreach expression must not be empty (fail-closed).');
        }

        $count = count($tokens);
        $found = [];

        foreach (self::findTokens($tokens, T_FOREACH) as $index) {
            $cursor = $index + 1;

            if ($cursor >= $count || $tokens[$cursor]['id'] !== null || $tokens[$cursor]['text'] !== '(') {
                continue;
            }

            $cursor++;
            $matched = true;

            foreach ($expressionTexts as $text) {
                if ($cursor >= $count || $tokens[$cursor]['text'] !== $text) {
                    $matched = false;

                    break;
                }

                $cursor++;
            }

            if (! $matched || $cursor >= $count || $tokens[$cursor]['id'] !== T_AS) {
                continue;
            }

            $found[] = $index;
        }

        return $found;
    }

    /**
     * `$var->method(` の形の呼び出しの**開き丸括弧**の位置。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return list<int>
     */
    public static function methodCalls(array $tokens, string $variable, string $method): array
    {
        $count = count($tokens);
        $found = [];

        for ($i = 0; $i + 3 < $count; $i++) {
            if ($tokens[$i]['id'] !== T_VARIABLE || $tokens[$i]['text'] !== $variable) {
                continue;
            }

            if (! in_array($tokens[$i + 1]['id'], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;
            }

            if ($tokens[$i + 2]['id'] !== T_STRING || $tokens[$i + 2]['text'] !== $method) {
                continue;
            }

            if ($tokens[$i + 3]['id'] === null && $tokens[$i + 3]['text'] === '(') {
                $found[] = $i + 3;
            }
        }

        return $found;
    }

    /**
     * `self::method(` の形の呼び出しの**開き丸括弧**の位置。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return list<int>
     */
    public static function staticCalls(array $tokens, string $method): array
    {
        $count = count($tokens);
        $found = [];

        for ($i = 0; $i + 3 < $count; $i++) {
            if ($tokens[$i]['id'] !== T_STRING || $tokens[$i]['text'] !== 'self') {
                continue;
            }

            if ($tokens[$i + 1]['id'] !== T_DOUBLE_COLON) {
                continue;
            }

            if ($tokens[$i + 2]['id'] !== T_STRING || $tokens[$i + 2]['text'] !== $method) {
                continue;
            }

            if ($tokens[$i + 3]['id'] === null && $tokens[$i + 3]['text'] === '(') {
                $found[] = $i + 3;
            }
        }

        return $found;
    }

    /**
     * `new <クラス名>(` の形の生成の**開き丸括弧**の位置。
     *
     * ★クラス名は**宣言元ファイルの取り込みと名前空間を解いた完全修飾名**で突き合わせる
     *   (AGENTS.md 走査器共通規約 (a))。短名の末尾一致は使わない —
     *   `Vendor\RuntimeException` を `RuntimeException` と誤認するためである。
     * ★**保証しないもの**: 実行時に決まるクラス (`new $class(`) と
     *   `new static(` / `new self(` は候補に入らない。母集団が空になるので、
     *   「ちょうど 1 件」を要求する利用側 (`constructionArgumentMatches()`) は
     *   偽を返して赤くなる (fail-closed)。
     * ★宣言元ファイルの取り込みを解けない場合は**例外**になる (`classImports()` を参照)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  class-string  $declaringClass  そのメソッドを宣言しているクラス (取り込み表と名前空間の出所)
     * @param  class-string  $expected  期待する完全修飾クラス名
     * @return list<int>
     */
    public static function constructions(array $tokens, string $declaringClass, string $expected): array
    {
        $resolver = self::nameResolver($declaringClass);
        $count = count($tokens);
        $found = [];

        for ($i = 0; $i + 2 < $count; $i++) {
            if ($tokens[$i]['id'] !== T_NEW) {
                continue;
            }

            if (! self::isNamePart($tokens[$i + 1])) {
                continue;
            }

            $resolved = self::resolveClassName($resolver, $tokens[$i + 1]['text']);

            if (strtolower($resolved) !== strtolower(ltrim($expected, '\\'))) {
                continue;
            }

            if ($tokens[$i + 2]['id'] === null && $tokens[$i + 2]['text'] === '(') {
                $found[] = $i + 2;
            }
        }

        return $found;
    }

    /**
     * 宣言元ファイルのクラス取り込み表と名前空間 (完全修飾名の解決に使う)。
     *
     * @param  class-string  $class
     * @return array{namespace: string, imports: array<string, string>}
     */
    private static function nameResolver(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $file = $reflection->getFileName();

        if ($file === false) {
            throw new RuntimeException("class source is not available: {$class}");
        }

        $source = file_get_contents($file);

        if ($source === false) {
            throw new RuntimeException("class source file is not readable: {$file}");
        }

        return [
            'namespace' => $reflection->getNamespaceName(),
            'imports' => self::classImports($source, $reflection->getNamespaceName()),
        ];
    }

    /**
     * ソースと名前空間から「短名 => 完全修飾名」の取り込み表を作る (純関数)。
     *
     * ★見るのは**その名前空間の領域のトップレベルの `use` だけ**である。
     *   クラス本体の中の `use` (trait の取り込み) は波括弧の内側なので数えない —
     *   数えると trait 名がクラスの短名を上書きし、別クラスを期待クラスと誤解決できる。
     * ★**解決できない形は落とす (fail-closed)**。次のいずれも例外にする:
     *   名前空間宣言が 2 つ以上ある / 波括弧つきの名前空間である /
     *   宣言された名前空間が引数と食い違う / `use` の綴りを完全修飾名へ解けない
     *   (group use の中に `function` / `const` が混ざる形を含む) /
     *   同じ短名が別の完全修飾名へ 2 度取り込まれている。
     *   **無言で読み飛ばさない** (読み飛ばすと未解決が「取り込み無し」と同じ値に混ざる)。
     *
     * @return array<string, string>
     */
    public static function classImports(string $phpSource, string $namespace): array
    {
        $tokens = PhpTokenScan::normalize($phpSource);
        $count = count($tokens);
        $declared = [];
        $braced = false;

        foreach ($tokens as $index => $token) {
            if ($token['id'] !== T_NAMESPACE) {
                continue;
            }

            $cursor = $index + 1;
            $name = '';

            while ($cursor < $count && self::isNamePart($tokens[$cursor])) {
                $name .= $tokens[$cursor]['text'];
                $cursor++;
            }

            if ($cursor < $count && $tokens[$cursor]['id'] === null && $tokens[$cursor]['text'] === '{') {
                $braced = true;
            }

            $declared[] = trim($name, '\\');
        }

        if ($braced || count($declared) >= 2) {
            throw new RuntimeException(
                'class import resolution is not supported for files with braced or multiple namespaces (fail-closed).'
            );
        }

        $current = $declared === [] ? '' : $declared[0];

        if (strtolower($current) !== strtolower(trim($namespace, '\\'))) {
            throw new RuntimeException(
                "declared namespace [{$current}] does not match the expected namespace [{$namespace}]."
            );
        }

        $imports = [];
        $depth = 0;

        for ($i = 0; $i < $count; $i++) {
            if (self::isBraceOpen($tokens[$i])) {
                $depth++;

                continue;
            }

            if ($tokens[$i]['id'] === null && $tokens[$i]['text'] === '}') {
                $depth--;

                continue;
            }

            if ($depth !== 0 || $tokens[$i]['id'] !== T_USE || ! isset($tokens[$i + 1])) {
                continue;
            }

            // `use function` / `use const` / 閉包の `use (...)` は取り込みではない。
            if (in_array($tokens[$i + 1]['id'], [T_FUNCTION, T_CONST], true)) {
                continue;
            }

            if ($tokens[$i + 1]['id'] === null && $tokens[$i + 1]['text'] === '(') {
                continue;
            }

            $statement = [];

            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j]['id'] === null && $tokens[$j]['text'] === ';') {
                    break;
                }

                $statement[] = $tokens[$j];
            }

            if (! self::collectClassImports($statement, $imports)) {
                throw new RuntimeException('unresolvable use statement in class source (fail-closed).');
            }
        }

        return $imports;
    }

    /**
     * `use …;` 1 文を短名 => 完全修飾名の対応表へ展開する (解けなければ false)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $statement
     * @param  array<string, string>  $imports
     */
    private static function collectClassImports(array $statement, array &$imports): bool
    {
        $prefix = '';
        $body = $statement;

        foreach ($statement as $position => $token) {
            if ($token['id'] === null && $token['text'] === '{') {
                $prefix = '';

                foreach (array_slice($statement, 0, $position) as $prefixToken) {
                    $prefix .= $prefixToken['text'];
                }

                $body = [];

                foreach (array_slice($statement, $position + 1) as $bodyToken) {
                    if ($bodyToken['id'] === null && $bodyToken['text'] === '}') {
                        break;
                    }

                    $body[] = $bodyToken;
                }

                break;
            }
        }

        $entries = [[]];

        foreach ($body as $token) {
            if ($token['id'] === null && $token['text'] === ',') {
                $entries[] = [];

                continue;
            }

            $entries[count($entries) - 1][] = $token;
        }

        foreach ($entries as $entry) {
            if ($entry === []) {
                continue;
            }

            $alias = null;
            $nameTokens = $entry;
            $entryCount = count($entry);

            if ($entryCount >= 3 && $entry[$entryCount - 2]['id'] === T_AS) {
                $alias = $entry[$entryCount - 1]['text'];
                $nameTokens = array_slice($entry, 0, $entryCount - 2);
            }

            $name = '';

            foreach ($nameTokens as $nameToken) {
                if (! self::isNamePart($nameToken)) {
                    return false;
                }

                $name .= $nameToken['text'];
            }

            $fullyQualified = trim($prefix.$name, '\\');

            if ($fullyQualified === '') {
                return false;
            }

            $segments = explode('\\', $fullyQualified);
            $alias ??= $segments[count($segments) - 1];
            $key = strtolower($alias);

            // 同じ短名が別の完全修飾名へ 2 度取り込まれている = 解決先が決まらない。
            if (isset($imports[$key]) && strtolower($imports[$key]) !== strtolower($fullyQualified)) {
                return false;
            }

            $imports[$key] = $fullyQualified;
        }

        return true;
    }

    /**
     * ソースに書かれた綴りを完全修飾名へ解く。
     *
     * 解ける形は 3 つである — 完全修飾 (`\Vendor\Thing`) / 現在の名前空間からの相対
     * (`namespace\Thing`) / 取り込み表かグローバル fallback で解ける非修飾・限定 (`Thing` / `Sub\Thing`)。
     * 相対参照で要素が続かない形は**例外** (fail-closed)。
     *
     * @param  array{namespace: string, imports: array<string, string>}  $resolver
     */
    private static function resolveClassName(array $resolver, string $spelling): string
    {
        if (str_starts_with($spelling, '\\')) {
            return ltrim($spelling, '\\');
        }

        $segments = explode('\\', $spelling);
        $first = strtolower($segments[0]);

        // `namespace\X` は現在の名前空間からの相対参照である (`namespace` は予約語なので
        // 本物の名前空間の要素にはならない。取り込み表より先に解く)。
        if ($first === 'namespace') {
            array_shift($segments);
            $rest = implode('\\', $segments);

            if ($rest === '') {
                throw new RuntimeException('unresolvable relative class name: '.$spelling);
            }

            return $resolver['namespace'] === '' ? $rest : $resolver['namespace'].'\\'.$rest;
        }

        if (isset($resolver['imports'][$first])) {
            $segments[0] = $resolver['imports'][$first];

            return implode('\\', $segments);
        }

        return $resolver['namespace'] === '' ? $spelling : $resolver['namespace'].'\\'.$spelling;
    }

    /**
     * 名前の一部として扱うトークンか。
     *
     * @param  array{id: int|null, text: string, line: int}  $token
     */
    private static function isNamePart(array $token): bool
    {
        return in_array(
            $token['id'],
            [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_NS_SEPARATOR],
            true,
        );
    }

    /**
     * 制御フローのトークンの出現位置。
     *
     * ★受け付けるのは `T_THROW` / `T_RETURN` / `T_BREAK` / `T_CONTINUE` の 4 つだけで、
     *   それ以外の token id は**例外**にする (fail-closed。指定の綴り間違いで
     *   「0 件だから合格」になるのを防ぐ)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return list<int>
     */
    public static function controlFlowTokens(array $tokens, int $tokenId): array
    {
        if (! in_array($tokenId, self::CONTROL_FLOW_TOKEN_IDS, true)) {
            throw new InvalidArgumentException(
                'controlFlowTokens() accepts only T_THROW / T_RETURN / T_BREAK / T_CONTINUE, got '.$tokenId
            );
        }

        return self::findTokens($tokens, $tokenId);
    }

    /**
     * `$var[] =` の形の追加の位置 (変数トークンの添字)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return list<int>
     */
    public static function variableAppends(array $tokens, string $variable): array
    {
        $count = count($tokens);
        $found = [];

        for ($i = 0; $i + 3 < $count; $i++) {
            if ($tokens[$i]['id'] !== T_VARIABLE || $tokens[$i]['text'] !== $variable) {
                continue;
            }

            if ($tokens[$i + 1]['text'] !== '[' || $tokens[$i + 2]['text'] !== ']') {
                continue;
            }

            if ($tokens[$i + 3]['id'] === null && $tokens[$i + 3]['text'] === '=') {
                $found[] = $i;
            }
        }

        return $found;
    }

    /**
     * `$var = <式>;` の形の代入の位置と右辺のトークンの綴りの列。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return list<array{index: int, rhs: list<string>}>
     */
    public static function variableAssignments(array $tokens, string $variable): array
    {
        $count = count($tokens);
        $found = [];

        for ($i = 0; $i + 1 < $count; $i++) {
            if ($tokens[$i]['id'] !== T_VARIABLE || $tokens[$i]['text'] !== $variable) {
                continue;
            }

            if ($tokens[$i + 1]['id'] !== null || $tokens[$i + 1]['text'] !== '=') {
                continue;
            }

            $found[] = ['index' => $i, 'rhs' => self::statementTokens($tokens, $i + 1)];
        }

        return $found;
    }

    /**
     * 指定位置の次のトークンから、深さ 0 の `;` までのトークンの綴りの列。
     *
     * ★`;` が見つからない場合は**例外** (fail-closed)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return list<string>
     */
    public static function statementTokens(array $tokens, int $index): array
    {
        $count = count($tokens);
        $depth = 0;
        $texts = [];

        for ($i = $index + 1; $i < $count; $i++) {
            $text = $tokens[$i]['text'];

            if (self::isBraceOpen($tokens[$i]) || ($tokens[$i]['id'] === null && in_array($text, ['(', '['], true))) {
                $depth++;
            } elseif ($tokens[$i]['id'] === null && in_array($text, ['}', ')', ']'], true)) {
                $depth--;
            } elseif ($tokens[$i]['id'] === null && $text === ';' && $depth === 0) {
                return $texts;
            }

            $texts[] = $text;
        }

        throw new RuntimeException('statement terminator not found after token index '.$index);
    }

    /**
     * 各 `if` の [条件のトークン範囲, 本体のトークン範囲]。
     *
     * ★条件の丸括弧が閉じない / 本体が波括弧でない形は**例外** (fail-closed)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return list<array{condition: array{int, int}, body: array{int, int}}>
     */
    public static function ifBlocks(array $tokens): array
    {
        $count = count($tokens);
        $blocks = [];

        foreach (self::findTokens($tokens, T_IF) as $index) {
            $open = $index + 1;

            if ($open >= $count || $tokens[$open]['id'] !== null || $tokens[$open]['text'] !== '(') {
                throw new RuntimeException('if condition is not parenthesised at token index '.$index);
            }

            $close = self::matchingParen($tokens, $open);
            $body = self::blockRange($tokens, $close);

            if ($body[0] !== $close + 1) {
                throw new RuntimeException('if body is not a brace block at token index '.$index);
            }

            $blocks[] = ['condition' => [$open + 1, $close - 1], 'body' => $body];
        }

        return $blocks;
    }

    /**
     * 呼び出し / 生成の丸括弧の中を最上位のカンマで割り、各引数のトークンの綴りの列を返す。
     *
     * ★`$callIndex` は**開き丸括弧の添字**である。括弧の対応が取れない場合は**例外** (fail-closed)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return list<list<string>>
     */
    public static function callArguments(array $tokens, int $callIndex): array
    {
        if ($tokens[$callIndex]['id'] !== null || $tokens[$callIndex]['text'] !== '(') {
            throw new RuntimeException('callArguments() expects the index of an opening parenthesis.');
        }

        $close = self::matchingParen($tokens, $callIndex);
        $arguments = [];
        $current = [];
        $depth = 0;

        for ($i = $callIndex + 1; $i < $close; $i++) {
            $text = $tokens[$i]['text'];

            if ($tokens[$i]['id'] === null && in_array($text, ['(', '['], true)) {
                $depth++;
            } elseif (self::isBraceOpen($tokens[$i])) {
                $depth++;
            } elseif ($tokens[$i]['id'] === null && in_array($text, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($tokens[$i]['id'] === null && $text === ',' && $depth === 0) {
                $arguments[] = $current;
                $current = [];

                continue;
            }

            $current[] = $text;
        }

        if ($current !== []) {
            $arguments[] = $current;
        }

        return $arguments;
    }

    /**
     * 「適用のループが指定ブロックの内側にあり、その本体に指定の静的呼び出しがちょうど 1 件ある」か。
     *
     * ★静的呼び出しをループ本体で数えるのが load-bearing である — 空のループを `try` に残して
     *   実際の適用を別の場所へ移す書き換えを、`foreach` の位置だけでは止められない。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  list<string>  $loopExpression
     * @param  array{int, int}  $blockRange
     */
    public static function applyLoopIsGuarded(array $tokens, array $loopExpression, array $blockRange, string $staticMethod): bool
    {
        $loops = self::foreachOverExpression($tokens, $loopExpression);

        if (count($loops) !== 1) {
            return false;
        }

        $body = self::blockRange($tokens, $loops[0]);

        if (! self::isWithin($blockRange, $loops[0]) || ! self::isWithin($blockRange, $body[1])) {
            return false;
        }

        return count(self::indexesWithin(self::staticCalls($tokens, $staticMethod), $body)) === 1;
    }

    /**
     * 復元が「ループ内で途中終了せず失敗を蓄積し、ループの後の 1 か所だけで送出する」構造か。
     *
     * 5 条 (「唯一の `throw` がループの外にある」だけでは、ループ内で `break` して抜ける形や、
     * 失敗を蓄積せず無条件に送出する形が通ってしまう):
     *
     *  1. 復元のループの本体に `throw` / `return` / `break` / `continue` が 1 件も無い
     *  2. `$accumulator[] = …` がループ本体にちょうど 1 件ある
     *  3. その追加が `$flagVariable === false` の条件分岐の**本体**にある
     *     (条件は綴りの列の**完全一致**で見る。包含だと結合していない条件を誤認する)
     *  4. ループの**後**の `$accumulator !== []` の条件分岐の本体に、**メソッド唯一の `throw`** がある
     *  5. その `throw` 以外に、メソッドを途中終了させるトークン (`return` / `throw`) が無い
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  list<string>  $loopExpression
     */
    public static function restoreStructureIsDeferred(array $tokens, array $loopExpression, string $accumulator, string $flagVariable): bool
    {
        $loops = self::foreachOverExpression($tokens, $loopExpression);

        if (count($loops) !== 1) {
            return false;
        }

        $body = self::blockRange($tokens, $loops[0]);

        // (1) ループ本体で途中終了しない
        foreach (self::CONTROL_FLOW_TOKEN_IDS as $id) {
            if (self::indexesWithin(self::controlFlowTokens($tokens, $id), $body) !== []) {
                return false;
            }
        }

        // (2) 失敗をループ本体で蓄積する
        $appends = self::indexesWithin(self::variableAppends($tokens, $accumulator), $body);

        if (count($appends) !== 1) {
            return false;
        }

        // (3) 蓄積が `$applied === false` の分岐の本体にある
        $blocks = self::ifBlocks($tokens);
        $failureBranches = array_values(array_filter(
            $blocks,
            fn (array $block): bool => self::conditionEquals($tokens, $block['condition'], [$flagVariable, '===', 'false']),
        ));

        if (count($failureBranches) !== 1 || ! self::isWithin($failureBranches[0]['body'], $appends[0])) {
            return false;
        }

        // (4) ループの後の `$failed !== []` の分岐に、メソッド唯一の throw がある
        $throws = self::controlFlowTokens($tokens, T_THROW);

        if (count($throws) !== 1 || $throws[0] < $body[1]) {
            return false;
        }

        $reportBranches = array_values(array_filter(
            $blocks,
            fn (array $block): bool => self::conditionEquals($tokens, $block['condition'], [$accumulator, '!==', '[', ']']),
        ));

        if (count($reportBranches) !== 1 || ! self::isWithin($reportBranches[0]['body'], $throws[0])) {
            return false;
        }

        // (5) 他に途中終了が無い
        return self::controlFlowTokens($tokens, T_RETURN) === [];
    }

    /**
     * 指定ブロックの中で `$var->method(…)` がちょうど 1 件あり、指定位置の引数が期待の綴り列か。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  array{int, int}  $blockRange
     * @param  list<string>  $expected
     */
    public static function methodCallArgumentMatches(
        array $tokens,
        array $blockRange,
        string $variable,
        string $method,
        int $argumentIndex,
        array $expected,
    ): bool {
        $calls = self::indexesWithin(self::methodCalls($tokens, $variable, $method), $blockRange);

        if (count($calls) !== 1) {
            return false;
        }

        return (self::callArguments($tokens, $calls[0])[$argumentIndex] ?? null) === $expected;
    }

    /**
     * 指定ブロックの中で `$var = <式>;` がちょうど 1 件あり、右辺が期待の綴り列か。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  array{int, int}  $blockRange
     * @param  list<string>  $expected
     */
    public static function variableAssignmentMatches(array $tokens, array $blockRange, string $variable, array $expected): bool
    {
        $assignments = array_values(array_filter(
            self::variableAssignments($tokens, $variable),
            fn (array $assignment): bool => self::isWithin($blockRange, $assignment['index']),
        ));

        if (count($assignments) !== 1) {
            return false;
        }

        return $assignments[0]['rhs'] === $expected;
    }

    /**
     * 指定ブロックの中の**唯一の** `throw` が、期待の綴り列を送出するか。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  array{int, int}  $blockRange
     * @param  list<string>  $expected
     */
    public static function soleThrowMatches(array $tokens, array $blockRange, array $expected): bool
    {
        $throws = self::indexesWithin(self::controlFlowTokens($tokens, T_THROW), $blockRange);

        if (count($throws) !== 1) {
            return false;
        }

        return self::statementTokens($tokens, $throws[0]) === $expected;
    }

    /**
     * `new <クラス名>(…)` がちょうど 1 件あり、指定位置の引数が期待の綴り列か。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  class-string  $declaringClass
     * @param  class-string  $expectedClass
     * @param  list<string>  $expected
     */
    public static function constructionArgumentMatches(
        array $tokens,
        string $declaringClass,
        string $expectedClass,
        int $argumentIndex,
        array $expected,
    ): bool {
        $constructions = self::constructions($tokens, $declaringClass, $expectedClass);

        if (count($constructions) !== 1) {
            return false;
        }

        return (self::callArguments($tokens, $constructions[0])[$argumentIndex] ?? null) === $expected;
    }

    /**
     * 条件のトークン範囲の綴りの列が、期待の列と**完全一致**するか。
     *
     * ★包含 (「変数と演算子と右辺らしい綴りがどこかに在る」) では判定にならない —
     *   `if (! $applied && $other === false)` にも 3 つとも現れるため、
     *   結合していない条件を `$applied === false` と誤認する。
     *   動的に検査できない性質の唯一の代替保証なので、**対応関係ごと**固定する。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  array{int, int}  $condition
     * @param  list<string>  $expectedTexts
     */
    public static function conditionEquals(array $tokens, array $condition, array $expectedTexts): bool
    {
        $texts = [];

        for ($i = $condition[0]; $i <= $condition[1]; $i++) {
            $texts[] = $tokens[$i]['text'];
        }

        return $texts === $expectedTexts;
    }

    /**
     * 開き丸括弧に対応する閉じ丸括弧の添字 (対応が取れなければ例外)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function matchingParen(array $tokens, int $openIndex): int
    {
        $count = count($tokens);
        $depth = 0;

        for ($i = $openIndex; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== null) {
                continue;
            }

            if ($tokens[$i]['text'] === '(') {
                $depth++;
            } elseif ($tokens[$i]['text'] === ')') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        throw new RuntimeException('unbalanced parentheses starting at token index '.$openIndex);
    }

    /**
     * 開き波括弧か (文字列補間の開き括弧も同列に数える)。
     *
     * @param  array{id: int|null, text: string, line: int}  $token
     */
    private static function isBraceOpen(array $token): bool
    {
        if ($token['id'] === null) {
            return $token['text'] === '{';
        }

        return in_array($token['id'], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true);
    }
}
