<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Pest テストファイルから **`test(...)` / `it(...)` の第 1 引数の文字列リテラル**を抽出する純関数。
 *
 * ★単純な `str_contains()` にしない。test を改名しても旧名がコメントや別のリテラルに残れば
 *   「含む」は成立してしまい、「その名前の test が実在する」という保証にならない。
 * ★走査は `PhpTokenScan::normalize()` (コメント除去済み) に対して行い、
 *   `T_STRING(test|it)` + `(` + `T_CONSTANT_ENCAPSED_STRING` の並びだけを採る。
 * ★**対象は Pest のグローバル関数 `test()` / `it()` だけ**である。直前トークンが
 *   `T_OBJECT_OPERATOR` / `T_NULLSAFE_OBJECT_OPERATOR` / `T_DOUBLE_COLON` のものは
 *   メソッド呼び出し (`$object->test('x')` / `SomeClass::test('x')`) なので**除外する**。
 *   直前が `T_FUNCTION` (`function test(` の宣言) も除外する。
 * ★**保証しないもの**: 変数・ヒアドキュメント・連結で組み立てた test 名は抽出できない
 *   (本 repo の Architecture テストはすべて単一リテラルで書かれている)。
 */
final class PestTestNameScanner
{
    /** Pest のテスト宣言関数 (小文字比較)。 */
    private const array TEST_FUNCTIONS = ['test', 'it'];

    /**
     * @return list<string> 抽出した test 名 (クォート除去済み)
     */
    public static function names(string $phpSource): array
    {
        $tokens = PhpTokenScan::normalize($phpSource);
        $count = count($tokens);

        $names = [];
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token['id'] !== T_STRING || ! in_array(mb_strtolower($token['text']), self::TEST_FUNCTIONS, true)) {
                continue;
            }

            $previousId = $tokens[$i - 1]['id'] ?? null;
            if ($previousId === T_OBJECT_OPERATOR || $previousId === T_NULLSAFE_OBJECT_OPERATOR
                || $previousId === T_DOUBLE_COLON || $previousId === T_FUNCTION) {
                continue; // メソッド呼び出し / 関数宣言であって Pest のテスト宣言ではない
            }

            $open = $tokens[$i + 1] ?? null;
            if ($open === null || $open['id'] !== null || $open['text'] !== '(') {
                continue;
            }

            $argument = $tokens[$i + 2] ?? null;
            if ($argument === null || $argument['id'] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $names[] = self::unquote($argument['text']);
        }

        return $names;
    }

    /** 文字列リテラルのクォートを外す (エスケープは単純な stripcslashes ではなく最小限に留める)。 */
    private static function unquote(string $literal): string
    {
        $quote = $literal[0] ?? '';
        if ($quote !== "'" && $quote !== '"') {
            return $literal;
        }

        $inner = substr($literal, 1, -1);

        return $quote === "'"
            ? str_replace(["\\'", '\\\\'], ["'", '\\'], $inner)
            : str_replace(['\\"', '\\\\'], ['"', '\\'], $inner);
    }
}
