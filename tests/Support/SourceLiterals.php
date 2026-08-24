<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * ソースコードから**文字列リテラルだけ**を取り出す純関数 (走査器の共通部品)。
 *
 * ★存在理由: 「撤去した名前がコードに残っていないか」を見る走査は、**コメントの言及**を
 *   参照と取り違えてはならない。撤去したことを説明する docblock は撤去の証拠であって
 *   復活ではない。同じ切り出しを走査ごとに書くと必ず食い違うので 1 本に集約する。
 *
 * ★**区切りの宣言** (走査器共通規約 (e)):
 *   - PHP: `token_get_all()` が返す `T_CONSTANT_ENCAPSED_STRING` /
 *     `T_ENCAPSED_AND_WHITESPACE` / `T_INLINE_HTML` を採る。前 2 つが文字列リテラル
 *     (補間つき二重引用符とヒアドキュメント本文を含む)、最後が PHP 開始タグの外の生テキストである
 *     (`.php` に混ざった生 HTML を落とさないため)。コメント (`T_COMMENT` / `T_DOC_COMMENT`) は採らない。
 *   - script (TypeScript / JavaScript / Svelte / Python): 自前の走査で
 *     `'` / `"` / `` ` `` に挟まれた範囲を採り、`//` 行コメント・ブロックコメント (`/`+`*` から `*`+`/` まで)・
 *     `#` 行コメント (Python) は読み飛ばす。**`//` の直前が `:` のときはコメントにしない**
 *     (`https://` を行コメントと誤読して行の残りを落とさないため)。
 *     正規表現リテラル (`/…/`) は**直前の意味のある文字が値の終わりでないとき**に限り
 *     読み飛ばす (`= /…/` `( /…/` `, /…/` `: /…/` `[ /…/` `return /…/`)。
 *
 * ★**保証しないもの (誇張しない)**:
 *   - 実行時に組み立てる形 (`'/dash'.$suffix` / `'/' + name`) は 1 つのリテラルに見えないので
 *     連結後の値では判定できない。連結前の断片だけを見る。
 *   - **script 側は言語の構文解析ではない**。正規表現リテラルの判定は上記の発見的規則であり、
 *     割り算との区別を完全には行わない。判定を誤ると引用符の対応がずれ、
 *     **見逃す方向にも倒れうる**。同様に Svelte の `<!-- -->` コメント・
 *     引用符なし HTML 属性・テンプレートリテラル内の入れ子も保証しない。
 *     利用側 gate はこの限界を**自分の検出力の主張から明示的に除く**こと。
 *   - Python の三重引用符は、同じ引用符 3 つの連なりとして 2 つの空文字列 + 本体に割れる
 *     (本体は採られるので見逃さない)。
 */
final class SourceLiterals
{
    /**
     * この語の直後の `/` は正規表現リテラルの開始である (値の終わりではない語)。
     *
     * @var list<string>
     */
    private const array REGEX_PRECEDING_KEYWORDS = [
        'return', 'typeof', 'instanceof', 'in', 'of', 'new', 'delete', 'void',
        'do', 'else', 'yield', 'await', 'case', 'throw',
    ];

    /** インスタンス化しない (純関数の置き場)。 */
    private function __construct() {}

    /**
     * PHP ソースの文字列リテラル (と PHP タグ外の生テキスト)。
     *
     * @return list<array{line: int, offset: int, value: string}>
     */
    public static function php(string $source): array
    {
        $tokens = @token_get_all($source);
        $literals = [];
        $offset = 0;
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            $text = is_array($token) ? (string) $token[1] : (string) $token;

            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_INLINE_HTML], true)) {
                $literals[] = ['line' => (int) $token[2], 'offset' => $offset, 'value' => $text];
                $offset += strlen($text);

                continue;
            }

            // 補間つき二重引用符 / ヒアドキュメントは **1 つの値として組み直す**。
            // token 単位で拾うと "/organizations/{$slug}/projects" が "/projects" だけの
            // 断片に割れ、根の位置に無い記述を根と誤判定する。
            $isInterpolationStart = ($token === '"')
                || (is_array($token) && $token[0] === T_START_HEREDOC);
            if (! $isInterpolationStart) {
                $offset += strlen($text);

                continue;
            }

            $startLine = is_array($token) ? (int) $token[2] : self::lineAt($source, $offset);
            $startOffset = $offset;
            $offset += strlen($text);
            $index++;
            $value = '';
            $pendingPlaceholder = false;

            for (; $index < $count; $index++) {
                $inner = $tokens[$index];
                $innerText = is_array($inner) ? (string) $inner[1] : (string) $inner;

                $isEnd = ($token === '"' && $inner === '"')
                    || (is_array($inner) && $inner[0] === T_END_HEREDOC);
                if ($isEnd) {
                    $offset += strlen($innerText);
                    break;
                }

                if (is_array($inner) && $inner[0] === T_ENCAPSED_AND_WHITESPACE) {
                    $value .= $innerText;
                    $pendingPlaceholder = false;
                } elseif (! $pendingPlaceholder) {
                    // 補間部分は中身を見ない。**波括弧つきの置換子**へ畳む
                    // (組織セグメントの直後かどうかの判定が置換子の形に依存するため)
                    $value .= '{$}';
                    $pendingPlaceholder = true;
                }

                $offset += strlen($innerText);
            }

            $literals[] = ['line' => $startLine, 'offset' => $startOffset, 'value' => $value];
        }

        return $literals;
    }

    /**
     * その `/` が正規表現リテラルの開始か (発見的規則)。
     *
     * ★直前の意味のある文字が「値の終わり」(英数字 / `_` / `$` / `)` / `]` / `}` / 引用符)
     *   でなければ正規表現リテラルとみなす。JavaScript の字句規則の近似であり、
     *   `}` の後の正規表現などは割り算側へ倒れる (docblock に明記した限界)。
     */
    private static function opensRegexLiteral(string $source, int $index): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $char = $source[$i];
            if ($char === ' ' || $char === "\t" || $char === "\r" || $char === "\n") {
                continue;
            }

            if (ctype_alnum($char) || $char === '_' || $char === '$') {
                // 直前が識別子。**キーワードなら値ではない**ので正規表現の開始になりうる
                $start = $i;
                while ($start >= 0 && (ctype_alnum($source[$start]) || $source[$start] === '_' || $source[$start] === '$')) {
                    $start--;
                }
                $word = substr($source, $start + 1, $i - $start);

                return in_array($word, self::REGEX_PRECEDING_KEYWORDS, true);
            }

            return ! in_array($char, [')', ']', '}', '"', "'", '`'], true);
        }

        return true;
    }

    /** バイト位置から 1 起点の行番号を求める (ヒアドキュメント開始などの補助)。 */
    private static function lineAt(string $source, int $offset): int
    {
        return substr_count(substr($source, 0, $offset), "\n") + 1;
    }

    /**
     * script 系ソースの**コメントを空白へ潰した写し** (長さと位置は元と同一)。
     *
     * ★リテラルの前後関係を生ソースで見る判定 (「この呼び出しの引数か」等) は、
     *   コメントの中の文字列に騙されてはならない。位置を保ったまま潰すことで、
     *   呼び出し側は offset をそのまま使える。
     */
    public static function maskComments(string $source, bool $hashComments = false): string
    {
        return self::mask($source, self::commentSpans($source, $hashComments));
    }

    /**
     * script 系ソースの**文字列リテラルが占める範囲** (引用符を含む)。
     *
     * ★宣言 (import 等) を構文として読む判定は、**文字列の中に書かれた偽の宣言**にも
     *   騙されてはならない。ただし宣言そのものが文字列 (module 指定) を含むため、
     *   文字列を潰すのではなく「宣言の位置が文字列の中か」を問う形で使う。
     *
     * @return list<array{int, int}>
     */
    public static function stringSpans(string $source, bool $hashComments = false): array
    {
        $spans = [];
        foreach (self::walk($source, $hashComments)['literals'] as $literal) {
            // 引用符ごと数える (開始位置は引用符、値の長さ + 2 が最大)
            $spans[] = [$literal['offset'], min(strlen($source), $literal['offset'] + strlen($literal['value']) + 2)];
        }

        return $spans;
    }

    /**
     * 指定した範囲を空白へ潰す (改行は残す)。
     *
     * @param  list<array{int, int}>  $spans
     */
    private static function mask(string $source, array $spans): string
    {
        $masked = $source;
        foreach ($spans as [$start, $end]) {
            for ($i = $start; $i < $end; $i++) {
                if ($masked[$i] !== "\n") {
                    $masked[$i] = ' ';
                }
            }
        }

        return $masked;
    }

    /**
     * script 系ソースの文字列リテラル。
     *
     * @param  bool  $hashComments  `#` を行コメントとして扱うか (Python)
     * @return list<array{line: int, offset: int, value: string}>
     */
    public static function script(string $source, bool $hashComments = false): array
    {
        return self::walk($source, $hashComments)['literals'];
    }

    /**
     * script 系ソースのコメントの範囲 (開始 offset, 終了 offset)。
     *
     * @return list<array{int, int}>
     */
    private static function commentSpans(string $source, bool $hashComments): array
    {
        return self::walk($source, $hashComments)['comments'];
    }

    /**
     * script 系ソースを 1 度だけ走査し、**文字列リテラルとコメントの範囲を同時に**返す。
     *
     * ★同じ字句規則を 2 本持たないための単一の走査である
     *   (2 本あると「片方だけ直して食い違う」経路が生まれる)。
     *
     * @return array{literals: list<array{line: int, offset: int, value: string}>, comments: list<array{int, int}>}
     */
    private static function walk(string $source, bool $hashComments): array
    {
        $literals = [];
        $comments = [];
        $length = strlen($source);
        $line = 1;
        $index = 0;

        while ($index < $length) {
            $char = $source[$index];

            if ($char === "\n") {
                $line++;
                $index++;

                continue;
            }

            // 行コメント (`//`)。直前が `:` なら URL の一部なのでコメントにしない
            if ($char === '/' && $index + 1 < $length && $source[$index + 1] === '/'
                && ! ($index > 0 && $source[$index - 1] === ':')) {
                $commentStart = $index;
                while ($index < $length && $source[$index] !== "\n") {
                    $index++;
                }
                $comments[] = [$commentStart, $index];

                continue;
            }

            // ブロックコメント
            if ($char === '/' && $index + 1 < $length && $source[$index + 1] === '*') {
                $commentStart = $index;
                $index += 2;
                while ($index + 1 < $length && ! ($source[$index] === '*' && $source[$index + 1] === '/')) {
                    if ($source[$index] === "\n") {
                        $line++;
                    }
                    $index++;
                }
                $index = min($index + 2, $length);
                $comments[] = [$commentStart, $index];

                continue;
            }

            if ($hashComments && $char === '#') {
                $commentStart = $index;
                while ($index < $length && $source[$index] !== "\n") {
                    $index++;
                }
                $comments[] = [$commentStart, $index];

                continue;
            }

            // 正規表現リテラル。直前の意味のある文字が「値の終わり」でないときだけそう読む
            // (発見的規則。割り算との区別を完全には行わない = docblock に明記済み)
            if (! $hashComments && $char === '/' && self::opensRegexLiteral($source, $index)) {
                $index++;
                $inClass = false;
                while ($index < $length) {
                    $current = $source[$index];
                    if ($current === '\\') {
                        $index += 2;

                        continue;
                    }
                    if ($current === "\n") {
                        break; // 正規表現リテラルは改行を跨げない = 読み違いだった
                    }
                    if ($current === '[') {
                        $inClass = true;
                    } elseif ($current === ']') {
                        $inClass = false;
                    } elseif ($current === '/' && ! $inClass) {
                        $index++;
                        break;
                    }
                    $index++;
                }

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $startLine = $line;
                $startOffset = $index;
                $quote = $char;
                $index++;
                $value = '';
                while ($index < $length) {
                    $current = $source[$index];
                    if ($current === '\\' && $index + 1 < $length) {
                        $value .= $current.$source[$index + 1];
                        if ($source[$index + 1] === "\n") {
                            $line++;
                        }
                        $index += 2;

                        continue;
                    }
                    if ($current === $quote) {
                        $index++;
                        break;
                    }
                    // ★引用符が閉じないまま改行した場合は文字列ではないと判断して打ち切る
                    //   (単引用符を含む散文で行の残りを飲み込まないため)。
                    //   ただしテンプレートリテラルは複数行にまたがれるので続ける
                    if ($current === "\n" && $quote !== '`') {
                        break;
                    }
                    if ($current === "\n") {
                        $line++;
                    }
                    $value .= $current;
                    $index++;
                }
                $literals[] = ['line' => $startLine, 'offset' => $startOffset, 'value' => $value];

                continue;
            }

            $index++;
        }

        return ['literals' => $literals, 'comments' => $comments];
    }
}
