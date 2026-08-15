<?php

declare(strict_types=1);

namespace Tests\Support\ForbiddenStatement;

use Tests\Support\PhpTokenScan;

/**
 * PHP ソースから「禁止する文」の出現位置を列挙する純関数。
 *
 * ★走査は既存の `Tests\Support\PhpTokenScan::normalize()`
 *   (空白 / コメント / DocComment を除いた添字連番のリスト) の上で行う。
 *   **同じ正規化を 2 本持たない**。
 * ★**何を禁止と呼ぶかは `ForbiddenStatementKind` が持ち、どこを走査するかは
 *   gate が持つ**。この走査器はどちらも知らない。
 *
 * ★**保証しないもの (誇張しない)**:
 *   - 名前の解決が要る出力 (書式つき出力 / 変数の内容の表示 / 標準出力への書き込み /
 *     文字列に入れた綴りを変数経由で呼ぶ形) には**沈黙する**。この検査は完全性を主張しない
 *   - Blade の `@php … @endphp` と二重波括弧の中は `token_get_all()` からは
 *     地の文 (`T_INLINE_HTML`) に見えるため届かない。
 *     **PHP 開始タグで開いた区間は見える** (実測)
 *   - ヒアドキュメント / ナウドキュメントの本文は 1 つの
 *     `T_ENCAPSED_AND_WHITESPACE` になり、中の綴りは見えない (実測)。
 *     これは本走査器の自己検査ファイルが自分自身を違反にしない理由でもある
 */
final class ForbiddenStatementScanner
{
    /**
     * 直前がこれなら無条件に名前位置とみなす (R1)。
     *
     * ★二重コロンは「直後に名前しか置けない」ことが PHP の文法から言えるので、
     *   直後の条件を課さなくても十分に狭い。逆に直後に来られるトークンの種類が
     *   多い (`(` `;` `,` `)` `=` 名前空間の区切り …) ため、列挙するとかえって穴を作る。
     * ★**属性のための規則は持たない**。属性名に予約語は書けず
     *   (実測: 属性名に出力する文の綴りを置くと Parse error)、属性の中で綴りが現れうるのは
     *   名前つき引数だけで、それは R6 が扱うためである。
     *   成立しない書き方のために規則を置くと検出力を無償で捨てることになる。
     *
     * @var list<int>
     */
    private const array NAME_ONLY_PREDECESSORS = [
        T_DOUBLE_COLON,   // 静的呼び出し / クラス定数の取得 / 第一級呼び出し可能 / トレイト取り込みの元メソッド指定 / 場合分けの値
    ];

    /**
     * 直前がこれらなら、**直後が指定のトークンのときに限り**名前位置とみなす
     * (R2 / R4 / R7)。
     *
     * ★字句走査は構文の妥当性を保証しないので、規則は狭いほどよい。
     *   直前だけで判定すると「構文として成立しない断片」でも黙ることになる。
     * ★可視性修飾子が現れるのは**トレイト取り込みの別名指定だけ**である
     *   (通常の宣言では間に `function` が入るので R2 になる)。
     * ★`T_CASE` の直後に単独のコロンを許さない。素の予約語は場合分けの値に書けず
     *   (実測: 定数として定義しても場合分けの値に素の綴りは置けず Parse error)、
     *   クラス定数経由の形は R1 が扱うためである。
     *
     * @var array<int, list<string>> トークン ID => 直後に許す単一文字トークン
     */
    private const array NAME_POSITION_PREDECESSORS = [
        T_FUNCTION => ['('],      // クラス / インターフェースのメソッド宣言
        T_CASE => ['=', ';'],     // 列挙の場合分け (値つき / 値なし)
        T_AS => [';'],            // トレイト取り込みの別名指定
        T_PUBLIC => [';'],        // トレイト取り込みの別名指定 (可視性つき)
        T_PROTECTED => [';'],     // 同上
        T_PRIVATE => [';'],       // 同上
    ];

    /**
     * @return list<ForbiddenStatementSite>
     */
    public static function sites(string $relativePath, string $phpSource): array
    {
        $tokens = PhpTokenScan::normalize($phpSource);
        $count = count($tokens);

        // R3 用。`T_CONST` からセミコロンまでの定数宣言区間だけ、
        // 直後が代入記号の綴りを名前位置とみなす。
        $inConstDeclaration = false;

        $sites = [];
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] === T_CONST) {
                $inConstDeclaration = true;
            } elseif ($tokens[$i]['id'] === null && $tokens[$i]['text'] === ';') {
                $inConstDeclaration = false;
            }

            $kind = ForbiddenStatementKind::fromTokenId($tokens[$i]['id']);
            if ($kind === null) {
                continue;
            }

            if ($kind->needsContextCheck() && self::isNamePosition($tokens, $i, $inConstDeclaration)) {
                continue;
            }

            $sites[] = new ForbiddenStatementSite($relativePath, $tokens[$i]['line'], $kind);
        }

        return $sites;
    }

    /**
     * 綴りが「文」ではなく「名前」として置かれている位置か。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function isNamePosition(array $tokens, int $index, bool $inConstDeclaration): bool
    {
        $previous = $tokens[$index - 1] ?? null;
        $previousId = $previous['id'] ?? null;
        $next = $tokens[$index + 1] ?? null;
        // 単一文字トークンは `id === null` で表現される (PhpTokenScan::normalize の契約)
        $nextChar = $next !== null && $next['id'] === null ? $next['text'] : null;

        // R1: 直後に名前しか置けない位置
        if ($previousId !== null && in_array($previousId, self::NAME_ONLY_PREDECESSORS, true)) {
            return true;
        }

        // R2 / R4 / R7: 直前と直後の組で狭める
        $allowedNext = $previousId === null ? null : (self::NAME_POSITION_PREDECESSORS[$previousId] ?? null);
        if ($allowedNext !== null && $nextChar !== null && in_array($nextChar, $allowedNext, true)) {
            return true;
        }

        // R2b: 参照を返すメソッドの宣言 (`function &echo(): mixed`)。
        //      直前が参照の記号で、その 1 つ前が `function`、直後が開き括弧のときだけ。
        //      ★`&` は `T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG` になる (実測)。
        if ($previousId === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG
            && ($tokens[$index - 2]['id'] ?? null) === T_FUNCTION
            && $nextChar === '(') {
            return true;
        }

        // R3: 定数宣言の区間 (`const` からセミコロンまで) で直後が代入記号なら定数名。
        //     ★直前のトークンで狭めない。型つきクラス定数 (`const string echo = 'x';` /
        //       `const ?string goto = null;` / `const A|string global = 'x';`) では
        //       直前が `T_CONST` ではなく型の綴りになるため (実測)。
        //     ★読点で繋いだ 2 つ目以降 (`const echo = 1, goto = 2;`) も同じ規則で覆う。
        //     ★定数の初期化式に文は書けない (PHP の定数式の制限) ので、この区間を
        //       名前位置扱いしても本物の文を取りこぼさない。配列リテラルの読点
        //       (`const X = [1, 2], Y = 3;`) は直後が代入記号にならないため一致しない。
        if ($inConstDeclaration && $nextChar === '=') {
            return true;
        }

        // R6: 名前つき引数は直後が単独のコロンになる。
        //     二重コロンは 1 つの `T_DOUBLE_COLON` トークンなので、ここには一致しない。
        return $nextChar === ':';
    }
}
