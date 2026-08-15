<?php

declare(strict_types=1);

namespace Tests\Support\GlobalUse;

use PhpToken;

/**
 * PHP ソースから「グローバル名前空間での非複合名の import」を列挙する純関数。
 *
 * ★真値は **PHP 実行系の `php -l`** である。この走査器が違反と呼ぶ形は、
 *   `php -l` が「非複合名の use は効果が無い」と警告する形とちょうど同じでなければならない。
 *   一致していることは `PhpLintOracle` を使う自己検査が見本で固定する。
 *
 * ★**別名が付いた要素は違反ではない**。`use Foo as Bar;` に `php -l` は警告を出さない
 *   (別名が付いた import は実際に効くため)。要素ごとに別名の有無を持ち、
 *   付いていたら報告しない。
 *
 * ★**行番号の規則**: `php -l` は 1 つの use 文の中のどの要素についても
 *   「その文で最初に現れた名前トークンの行」で報告する (実測。例えば
 *   `use\n Foo as F,\n Bar;` の `Bar` は `Foo` の行で報告される)。
 *   照合できるように、走査器も 1 文の中では最初の名前トークンの行を共有する。
 *
 * ★**グローバル領域は 2 通りしかない** (実測で確定):
 *   (A) 名前空間の宣言がまったく無いファイルの全体
 *   (B) `namespace { … }` と書いた波括弧ブロックの中
 *   「波括弧ブロックを閉じた後の素のトップレベル」は言語が許さず
 *   (`No code may exist outside of namespace {}`)、セミコロン形の宣言は
 *   ファイル末尾までグローバルへ戻らない (名前なしのセミコロン形は構文として存在しない)。
 *   セミコロン形と波括弧形の混在も言語が許さない。よって追跡はこの 2 通りで足りる。
 *
 * ★**読めなかった宣言は黙って対象外にしない**。`namespace` の後が
 *   `;` でも `{` でもない形に当たったら `unresolved` として返し、gate を赤くする
 *   (fail-closed。静かに走査域が縮むのを防ぐ)。
 *
 * ★**保証しないもの (誇張しない)**: これは import 構文の完全なパーサではない。
 *   構文エラーになる入力に対する挙動は保証しない (見本は必ず構文として正しいことを
 *   自己検査が確かめる)。グループ use (`use A\B\{C, D};`) は前置きに必ず `\` を含むので
 *   非複合になりえず、中身は読まずに読み飛ばす。
 */
final class NonCompoundGlobalUseScanner
{
    /** 名前空間の宣言が無い。ファイル全体がグローバル領域である。 */
    private const string KIND_NONE = 'none';

    /** セミコロン形の宣言。以降ファイル末尾までグローバルへ戻らない。 */
    private const string KIND_SEMICOLON = 'semicolon';

    /** 波括弧形の宣言。ブロックの中だけがその名前空間である。 */
    private const string KIND_BRACKETED = 'bracketed';

    /**
     * 1 ファイル分の PHP ソースを走査する。
     *
     * @param  string  $source  PHP ソース
     * @param  string  $relative  失敗メッセージに載せる表示名
     * @return array{
     *     violations: list<array{name: string, line: int}>,
     *     hasGlobalRegion: bool,
     *     unresolved: list<string>,
     * }
     */
    public static function scan(string $source, string $relative): array
    {
        /** @var list<PhpToken> $tokens */
        $tokens = PhpToken::tokenize($source);
        $count = count($tokens);

        $violations = [];
        $unresolved = [];

        $kind = self::KIND_NONE;
        $namespaceName = '';
        $bodyDepth = 0;
        $blockOpenDepth = null;
        $depth = 0;

        // 名前なしの波括弧ブロック (`namespace { … }`) を 1 度でも開いたか。
        // ★グローバル領域の有無は「import を書ける場所があるか」で決める。
        //   セミコロン形の宣言より前の前置き部分も字面上はグローバルだが、
        //   そこに import は置けない (宣言は先頭の文でなければならない) ので数えない。
        $sawBracketedGlobal = false;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is(T_NAMESPACE)) {
                $declaration = self::readNamespaceDeclaration($tokens, $i);

                if ($declaration === null) {
                    $unresolved[] = sprintf(
                        '%s:%d → namespace 宣言の形を読めませんでした (前後のトークン: %s)',
                        $relative,
                        $token->line,
                        self::describeNeighbourhood($tokens, $i),
                    );

                    continue;
                }

                $namespaceName = $declaration['name'];

                if ($declaration['bracketed']) {
                    $kind = self::KIND_BRACKETED;
                    $blockOpenDepth = $depth;
                    $bodyDepth = $depth + 1;
                    $depth++; // 宣言の `{` はここで数える (下の波括弧処理へは渡さない)
                    $sawBracketedGlobal = $sawBracketedGlobal || $namespaceName === '';
                } else {
                    $kind = self::KIND_SEMICOLON;
                    $blockOpenDepth = null;
                    $bodyDepth = $depth;
                }

                $i = $declaration['cursor'];

                continue;
            }

            if ($token->text === '{') {
                $depth++;

                continue;
            }

            if ($token->text === '}') {
                $depth--;

                if ($kind === self::KIND_BRACKETED && $blockOpenDepth !== null && $depth === $blockOpenDepth) {
                    // 波括弧ブロックを出た。次の宣言が来るまでコードは置けない領域である。
                    $namespaceName = '';
                    $bodyDepth = $depth;
                    $blockOpenDepth = null;
                }

                continue;
            }

            $isGlobalImportRegion = $namespaceName === ''
                && $depth === $bodyDepth
                && ($kind !== self::KIND_BRACKETED || $blockOpenDepth !== null);

            if (! $token->is(T_USE) || ! $isGlobalImportRegion) {
                continue;
            }

            $cursor = self::nextSignificant($tokens, $i + 1);
            if ($cursor === null) {
                continue;
            }

            // クロージャの `use ($x)` は import ではない
            if ($tokens[$cursor]->text === '(') {
                continue;
            }

            // `use function` / `use const` の修飾を読み飛ばす (同じ警告が出るため対象に含める)
            if ($tokens[$cursor]->is([T_FUNCTION, T_CONST])) {
                $next = self::nextSignificant($tokens, $cursor + 1);
                if ($next === null) {
                    continue;
                }
                $cursor = $next;
            }

            $i = self::collectUseStatement($tokens, $cursor, $violations);
        }

        return [
            'violations' => $violations,
            'hasGlobalRegion' => $kind === self::KIND_NONE || $sawBracketedGlobal,
            'unresolved' => $unresolved,
        ];
    }

    /**
     * 1 つの use 文の import 要素を評価して violations へ積み、文末の添字を返す。
     *
     * @param  list<PhpToken>  $tokens
     * @param  list<array{name: string, line: int}>  $violations
     * @return int 走査を再開してよい添字 (文末の `;` / グループ use の `{` の直前)
     */
    private static function collectUseStatement(array $tokens, int $cursor, array &$violations): int
    {
        $count = count($tokens);

        $name = '';
        $aliased = false;
        $collecting = true;
        $statementLine = null;

        for ($j = $cursor; $j < $count; $j++) {
            $current = $tokens[$j];

            if ($current->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }

            if ($current->text === ';') {
                self::flush($name, $aliased, $statementLine, $violations);

                return $j;
            }

            if ($current->text === ',') {
                self::flush($name, $aliased, $statementLine, $violations);
                $name = '';
                $aliased = false;
                $collecting = true;

                continue;
            }

            if ($current->is(T_AS)) {
                // この要素は import として実際に効く = 違反ではない
                $aliased = true;
                $collecting = false;

                continue;
            }

            // グループ use (`use A\B\{C, D};`) の前置きは必ず `\` を含むので非複合になりえない。
            // 中身は読まず、波括弧の対応は外側の深さ追跡に任せる。
            if ($current->text === '{') {
                return $j - 1;
            }

            if (! $collecting) {
                continue;
            }

            if ($current->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR])) {
                $statementLine ??= $current->line;
                $name .= $current->text;
            }
        }

        return $count - 1;
    }

    /**
     * 収集済みの 1 要素を判定して violations へ積む。
     *
     * @param  list<array{name: string, line: int}>  $violations
     */
    private static function flush(string $name, bool $aliased, ?int $statementLine, array &$violations): void
    {
        if ($aliased || $statementLine === null) {
            return;
        }

        // 先頭の `\` は付いていても PHP は同じ警告を出す (実測) ので、除いてから段数を見る。
        $normalized = ltrim($name, '\\');

        if ($normalized === '' || str_contains($normalized, '\\')) {
            return;
        }

        $violations[] = ['name' => $normalized, 'line' => $statementLine];
    }

    /**
     * `namespace` トークンから宣言 1 つ分を読む。
     *
     * @param  list<PhpToken>  $tokens
     * @return array{name: string, bracketed: bool, cursor: int}|null cursor は宣言の最後 (`;` / `{`) の添字
     */
    private static function readNamespaceDeclaration(array $tokens, int $index): ?array
    {
        $cursor = self::nextSignificant($tokens, $index + 1);
        if ($cursor === null) {
            return null;
        }

        $name = '';
        while ($tokens[$cursor]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR])) {
            $name .= $tokens[$cursor]->text;
            $next = self::nextSignificant($tokens, $cursor + 1);
            if ($next === null) {
                return null;
            }
            $cursor = $next;
        }

        return match ($tokens[$cursor]->text) {
            ';' => ['name' => $name, 'bracketed' => false, 'cursor' => $cursor],
            '{' => ['name' => $name, 'bracketed' => true, 'cursor' => $cursor],
            default => null,
        };
    }

    /**
     * index 以降で最初の意味のあるトークンの添字。
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function nextSignificant(array $tokens, int $index): ?int
    {
        $count = count($tokens);

        for ($i = $index; $i < $count; $i++) {
            if (! $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                return $i;
            }
        }

        return null;
    }

    /**
     * 読めなかった位置の前後 3 トークンの字面 (赤くなったときの切り分け用)。
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function describeNeighbourhood(array $tokens, int $index): string
    {
        $from = max(0, $index - 3);
        $to = min(count($tokens) - 1, $index + 3);

        $pieces = [];
        for ($i = $from; $i <= $to; $i++) {
            $pieces[] = trim($tokens[$i]->text);
        }

        return implode(' ', array_filter($pieces, static fn (string $piece): bool => $piece !== ''));
    }
}
