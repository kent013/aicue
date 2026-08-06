<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * `RateLimiter::for(...)` 登録の token 走査器。
 *
 * ★正規表現を使わない理由 (deny-by-default では誤合格が最悪の失敗モード):
 *   `RateLimiter::for(\n    'name',` のような改行入り呼び出しや
 *   `RateLimiter::for(self::NAME, …)` のような非リテラル引数を取りこぼしつつ、
 *   「検出した名前の集合が inventory と一致」は成功してしまう。
 *   token 走査で**全呼び出しを数え、分類できなかったものを明示的に fail させる**。
 *
 * ★コメント / 文字列リテラル中の記述で誤検出しないよう、判定は token 列上で行う。
 *
 * ★受理する呼び出し元の書き方は 2 通りだけ (それ以外は unresolved = 明示 fail):
 *   - `use Illuminate\Support\Facades\RateLimiter;` 済みの短縮名 `RateLimiter`
 *   - 完全修飾 `\Illuminate\Support\Facades\RateLimiter`
 *   名前空間内の非完全修飾 `Illuminate\Support\Facades\RateLimiter::for(...)` は
 *   PHP の解決規則では `App\Foo\Illuminate\…` を意味し **Laravel の Facade ではない**ため
 *   受理しない。alias 付き import (`use … as X;`) は、実際に `X::for()` の呼び出し元として
 *   使われたときだけ unresolved にする (import しただけで未使用なら fail させない)。
 *
 * ★`RateLimiter::for` の `for` は PHP の予約語のため **T_FOR** としてトークン化される
 *   (T_STRING ではない)。判定は token 型ではなく token のテキストで行う。
 */
final class RateLimiterRegistrationScanner
{
    /** 受理する Facade の完全修飾名。 */
    private const FACADE = 'Illuminate\Support\Facades\RateLimiter';

    /** Facade の短縮名 (import 済みのときだけ受理する)。 */
    private const FACADE_SHORT_NAME = 'RateLimiter';

    /**
     * PHP ソース中の `RateLimiter::for(...)` 呼び出しを走査する。
     *
     * @return array{names: list<string>, unresolved: list<string>}
     *                                                              names      = 第 1 引数がリテラル文字列だった limiter 名
     *                                                              unresolved = 解析できなかった呼び出しの位置 (`{path}:{line}: {理由}`)
     */
    public static function scan(string $source, string $relativePath): array
    {
        $tokens = token_get_all($source);
        [$importsFacade, $forbiddenAliases] = self::parseImports($tokens);

        $names = [];
        $unresolved = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $callerStatus = self::callerStatus($tokens[$i], $importsFacade, $forbiddenAliases);
            if ($callerStatus === null) {
                continue;
            }

            $doubleColon = self::nextSignificant($tokens, $i + 1);
            if ($doubleColon === null || ! self::isTokenType($tokens[$doubleColon], T_DOUBLE_COLON)) {
                continue;
            }

            $method = self::nextSignificant($tokens, $doubleColon + 1);
            if ($method === null || self::tokenText($tokens[$method]) !== 'for') {
                continue;
            }

            $paren = self::nextSignificant($tokens, $method + 1);
            if ($paren === null || self::tokenText($tokens[$paren]) !== '(') {
                continue;
            }

            $line = self::tokenLine($tokens, $i);
            $position = $relativePath.':'.$line;

            if ($callerStatus !== 'allowed') {
                $unresolved[] = $position.': 呼び出し元の書き方が規約外です ('.$callerStatus.')';

                continue;
            }

            $argument = self::nextSignificant($tokens, $paren + 1);
            $literal = $argument === null ? null : self::literalString($tokens[$argument]);
            if ($literal === null) {
                $unresolved[] = $position.': 第 1 引数がリテラル文字列ではありません';

                continue;
            }

            $names[] = $literal;
        }

        return ['names' => $names, 'unresolved' => $unresolved];
    }

    /**
     * ディレクトリ配下の *.php を再帰走査して集計する。
     *
     * @return array{names: list<string>, unresolved: list<string>}
     */
    public static function scanDirectory(string $absoluteDirectory, string $relativeRoot): array
    {
        $names = [];
        $unresolved = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absoluteDirectory, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            if ($source === false) {
                $unresolved[] = $file->getPathname().':0: ファイルを読み取れません';

                continue;
            }

            $relative = $relativeRoot.'/'.ltrim(
                str_replace($absoluteDirectory, '', $file->getPathname()),
                DIRECTORY_SEPARATOR,
            );

            $result = self::scan($source, $relative);
            $names = array_merge($names, $result['names']);
            $unresolved = array_merge($unresolved, $result['unresolved']);
        }

        sort($names);
        sort($unresolved);

        return ['names' => $names, 'unresolved' => $unresolved];
    }

    /**
     * トップレベルの名前空間 import を解析する。
     *
     * `T_USE` は 3 用途 (名前空間 import / クロージャの lexical use / trait use) あるため、
     * **波括弧の深さ 0** かつ **直後が `(` でない** ものだけを名前空間 import とみなす。
     *
     * @param  array<int, string|array{0: int, 1: string, 2: int}>  $tokens
     * @return array{0: bool, 1: array<string, true>} [Facade を素で import 済みか, 禁止 alias 集合]
     */
    private static function parseImports(array $tokens): array
    {
        $count = count($tokens);
        $depth = 0;
        $importsFacade = false;
        /** @var array<string, true> $forbiddenAliases */
        $forbiddenAliases = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token)) {
                // 文字列内の `{$var}` / `${var}` も対応する `}` は生トークンのため深さに数える
                if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $depth++;
                }
                if ($token[0] !== T_USE || $depth !== 0) {
                    continue;
                }

                $parsed = self::parseUseStatement($tokens, $i);
                if ($parsed === null) {
                    continue;
                }
                if ($parsed['alias'] === null) {
                    $importsFacade = true;
                } else {
                    $forbiddenAliases[$parsed['alias']] = true;
                }

                continue;
            }

            if ($token === '{') {
                $depth++;
            } elseif ($token === '}') {
                $depth--;
            }
        }

        return [$importsFacade, $forbiddenAliases];
    }

    /**
     * `use` トークン位置から RateLimiter Facade の import かを判定する。
     *
     * group use (`use Illuminate\Support\Facades\{RateLimiter, Auth};`) は受理しない
     * (deny-by-default。その場合 `RateLimiter::for` は unresolved になる)。
     *
     * @param  array<int, string|array{0: int, 1: string, 2: int}>  $tokens
     * @return array{alias: string|null}|null Facade の import でなければ null
     */
    private static function parseUseStatement(array $tokens, int $useIndex): ?array
    {
        $count = count($tokens);
        $i = self::nextSignificant($tokens, $useIndex + 1);
        if ($i === null) {
            return null;
        }

        // クロージャの lexical use (`function ($x) use ($y) {}`) / `use function` / `use const`
        if (self::tokenText($tokens[$i]) === '('
            || self::isTokenType($tokens[$i], T_FUNCTION)
            || self::isTokenType($tokens[$i], T_CONST)) {
            return null;
        }

        $name = '';
        for (; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if (in_array($token[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $name .= $token[1];

                    continue;
                }
                if ($token[0] === T_AS) {
                    break;
                }

                return null;
            }

            if ($token === '\\') {
                $name .= '\\';

                continue;
            }

            if ($token === ';') {
                return ltrim($name, '\\') === self::FACADE ? ['alias' => null] : null;
            }

            // group use の `{` や複数 import の `,` は受理しない
            return null;
        }

        if (ltrim($name, '\\') !== self::FACADE) {
            return null;
        }

        // `as` の直後が alias 名
        $aliasIndex = self::nextSignificant($tokens, $i + 1);
        if ($aliasIndex === null || ! self::isTokenType($tokens[$aliasIndex], T_STRING)) {
            return null;
        }

        return ['alias' => self::tokenText($tokens[$aliasIndex])];
    }

    /**
     * token が `::for(` の呼び出し元候補かを判定する。
     *
     * @param  string|array{0: int, 1: string, 2: int}  $token
     * @param  array<string, true>  $forbiddenAliases
     * @return string|null 'allowed' / 理由文字列 (候補だが規約外) / null (候補ですらない)
     */
    private static function callerStatus(string|array $token, bool $importsFacade, array $forbiddenAliases): ?string
    {
        if (! is_array($token)) {
            return null;
        }

        $text = $token[1];

        if ($token[0] === T_NAME_FULLY_QUALIFIED) {
            return ltrim($text, '\\') === self::FACADE ? 'allowed' : null;
        }

        if ($token[0] === T_NAME_QUALIFIED) {
            // PHP の解決規則では現在の名前空間からの相対解決 = Facade ではない
            return self::lastSegment($text) === self::FACADE_SHORT_NAME
                ? '名前空間内の非完全修飾名は Facade を指しません: '.$text
                : null;
        }

        if ($token[0] !== T_STRING) {
            return null;
        }

        if ($text === self::FACADE_SHORT_NAME) {
            return $importsFacade
                ? 'allowed'
                : 'use '.self::FACADE.'; の import がありません (同名の別クラスの可能性)';
        }

        if (isset($forbiddenAliases[$text])) {
            return 'alias 経由の呼び出しです: '.$text;
        }

        return null;
    }

    /** 名前空間区切りの最終セグメント。 */
    private static function lastSegment(string $name): string
    {
        $parts = explode('\\', $name);

        return end($parts) ?: $name;
    }

    /**
     * 変数展開を含まない文字列リテラルを素の値へ復元する (できなければ null)。
     *
     * エスケープ解釈は行わず、**エスケープを含むリテラルは受理しない**
     * (limiter 名にエスケープが要る事態は規約違反であり、素通しさせない)。
     *
     * @param  string|array{0: int, 1: string, 2: int}  $token
     */
    private static function literalString(string|array $token): ?string
    {
        if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        $raw = $token[1];
        if (preg_match("/^'[^'\\\\]*'$/", $raw) === 1 || preg_match('/^"[^"\\\\$]*"$/', $raw) === 1) {
            return substr($raw, 1, -1);
        }

        return null;
    }

    /**
     * 空白・コメントを読み飛ばした次の token 位置。
     *
     * @param  array<int, string|array{0: int, 1: string, 2: int}>  $tokens
     */
    private static function nextSignificant(array $tokens, int $from): ?int
    {
        $count = count($tokens);
        for ($i = $from; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /** @param  string|array{0: int, 1: string, 2: int}  $token */
    private static function tokenText(string|array $token): string
    {
        return is_array($token) ? $token[1] : $token;
    }

    /** @param  string|array{0: int, 1: string, 2: int}  $token */
    private static function isTokenType(string|array $token, int $type): bool
    {
        return is_array($token) && $token[0] === $type;
    }

    /**
     * token の行番号 (生トークンには行情報が無いため直前の配列トークンから引く)。
     *
     * @param  array<int, string|array{0: int, 1: string, 2: int}>  $tokens
     */
    private static function tokenLine(array $tokens, int $index): int
    {
        for ($i = $index; $i >= 0; $i--) {
            if (is_array($tokens[$i])) {
                return $tokens[$i][2];
            }
        }

        return 0;
    }
}
