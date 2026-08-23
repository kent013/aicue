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
 *
 * ★**保証しないもの (誇張しない)**:
 *   - 実行時に組み立てる形 (`'/dash'.$suffix` / `'/' + name`) は 1 つのリテラルに見えないので
 *     連結後の値では判定できない。連結前の断片だけを見る。
 *   - script 側は言語の構文解析ではない。正規表現リテラル (`/…/g`) の中の引用符、
 *     Svelte の `<!-- -->` コメント、JSX/HTML 属性の引用符なし記法は
 *     **文字列として採られる / 採られないのどちらかに倒れる**。倒れる方向は
 *     「採る (過検出)」であり、見逃す方向ではない。
 *   - Python の三重引用符は、同じ引用符 3 つの連なりとして 2 つの空文字列 + 本体に割れる
 *     (本体は採られるので見逃さない)。
 */
final class SourceLiterals
{
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

    /** バイト位置から 1 起点の行番号を求める (ヒアドキュメント開始などの補助)。 */
    private static function lineAt(string $source, int $offset): int
    {
        return substr_count(substr($source, 0, $offset), "\n") + 1;
    }

    /**
     * script 系ソースの文字列リテラル。
     *
     * @param  bool  $hashComments  `#` を行コメントとして扱うか (Python)
     * @return list<array{line: int, offset: int, value: string}>
     */
    public static function script(string $source, bool $hashComments = false): array
    {
        $literals = [];
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
                while ($index < $length && $source[$index] !== "\n") {
                    $index++;
                }

                continue;
            }

            // ブロックコメント
            if ($char === '/' && $index + 1 < $length && $source[$index + 1] === '*') {
                $index += 2;
                while ($index + 1 < $length && ! ($source[$index] === '*' && $source[$index + 1] === '/')) {
                    if ($source[$index] === "\n") {
                        $line++;
                    }
                    $index++;
                }
                $index = min($index + 2, $length);

                continue;
            }

            if ($hashComments && $char === '#') {
                while ($index < $length && $source[$index] !== "\n") {
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

        return $literals;
    }
}
