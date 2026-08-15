<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * PHP ソースが冒頭で `declare(strict_types=1);` を宣言しているかを判定する純関数。
 *
 * ★正規表現・部分文字列判定にしない。コメントや文字列リテラル中の
 *   `declare(strict_types=1)` という**記述**を宣言と誤認するため
 *   (負の対照で固定する)。走査は `PhpTokenScan::normalize()` (空白・コメント除去済み) に対して行う。
 *
 * ★**受理するのは正準形だけ**である:
 *     <?php  declare ( strict_types = 1 ) ;
 *   (キーワード・指令名の大小は無視。空白とコメントは透過)
 *   PHP 8.4 の実測では `01` / `0x1` / `0b1` / `declare(ticks=1, strict_types=1)` /
 *   同一 declare 内の重複指定 / 2 文目の declare も**実際には厳密化が効く**が、
 *   本判定器はこれらを**未宣言側に倒す** (安全側の乖離)。
 *   本 gate は PHP の意味論の再現ではなく、リポジトリ内の表記を 1 つに揃える規約検査だからである。
 *
 * ★**先頭の正準形だけでは終わらない — 後続の `strict_types` 再宣言があれば未宣言に倒す**。
 *   PHP 8.4 の実測では `declare(strict_types=1); declare(strict_types=0);` の実効は
 *   **strict のまま**だが (1 が 1 度でもあれば実効)、
 *   (a) 表記を 1 つに揃えるという本 gate の規約に反すること、
 *   (b) 「後に書いた方が勝つ」へ言語仕様が変わった場合に
 *       判定器 true / 実効 false という**逆向きの乖離 = fail-open** になること、
 *   の 2 つの理由で拒否する。`declare(ticks=1)` のように `strict_types` を含まない
 *   後続の declare は拒否しない (厳密化に関係しないため)。
 *
 * ★**逆向きの乖離は 1 件も許さない** — 「判定器は宣言済みと言うのに実際は厳密化されない」形が
 *   あると gate が嘘をつく。`StrictTypesDeclarationScannerTest` が
 *   `StrictTypesRuntimeProbe` (別プロセスで実際に型不一致が起きるかを測る) と
 *   突き合わせ、乖離の向きを機械的に固定する。
 */
final class StrictTypesDeclarationScanner
{
    /** 正準形の宣言 (失敗メッセージで提示する)。 */
    public const string CANONICAL_DECLARATION = 'declare(strict_types=1);';

    public static function declaresStrictTypes(string $phpSource): bool
    {
        $tokens = PhpTokenScan::normalize($phpSource);

        return self::hasCanonicalHead($tokens) && ! self::hasLaterStrictTypesDeclare($tokens);
    }

    /**
     * 冒頭が正準形か。
     *
     * [0] T_OPEN_TAG / [1] T_DECLARE / [2] '(' / [3] T_STRING(strict_types)
     * [4] '=' / [5] T_LNUMBER('1') / [6] ')' / [7] ';'
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function hasCanonicalHead(array $tokens): bool
    {
        if (count($tokens) < 8) {
            return false;
        }
        if ($tokens[0]['id'] !== T_OPEN_TAG || $tokens[1]['id'] !== T_DECLARE) {
            return false; // 先頭に inline HTML / shebang / 他の文があれば未宣言
        }
        if ($tokens[2]['text'] !== '(' || $tokens[3]['id'] !== T_STRING) {
            return false;
        }
        if (mb_strtolower($tokens[3]['text']) !== 'strict_types') {
            return false;
        }
        if ($tokens[4]['text'] !== '=' || $tokens[5]['id'] !== T_LNUMBER || $tokens[5]['text'] !== '1') {
            return false; // 値 0 / 01 / true / 式 はすべて未宣言側
        }

        return $tokens[6]['text'] === ')' && $tokens[7]['text'] === ';'; // ブロック形 `{` は未宣言側
    }

    /**
     * 冒頭の正準形より後ろに、`strict_types` を含む declare が現れるか。
     *
     * ★`'strict_types'` という**文字列リテラル**は T_CONSTANT_ENCAPSED_STRING であって
     *   T_STRING ではないため、配列リテラル (`['strict_types' => 1]`) は誤検出しない。
     * ★引数部の終端は**括弧の深さで追う**。`declare(ticks=(1), strict_types=1)` のように
     *   引数の中に括弧があると、最初の `)` で打ち切る実装では後続の `strict_types` を
     *   取りこぼす (= 見落としの向きの穴になる)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function hasLaterStrictTypesDeclare(array $tokens): bool
    {
        $count = count($tokens);
        for ($i = 8; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_DECLARE) {
                continue;
            }

            $depth = 0;
            for ($j = $i + 1; $j < $count; $j++) {
                $text = $tokens[$j]['text'];
                if ($text === '(') {
                    $depth++;

                    continue;
                }
                if ($text === ')') {
                    $depth--;
                    if ($depth <= 0) {
                        break; // この declare の引数部が閉じた
                    }

                    continue;
                }
                if ($tokens[$j]['id'] === T_STRING && mb_strtolower($text) === 'strict_types') {
                    return true;
                }
            }
        }

        return false;
    }
}
