<?php

declare(strict_types=1);

namespace Tests\Support\Bughunt;

use InvalidArgumentException;
use RuntimeException;

/**
 * bug-hunt のシェルスクリプトから `cmd_*` 関数の窓を切り出す純関数 (**`cmd_` で始まる関数専用**)。
 *
 * 終端を「次の `^cmd_` 定義 (または末尾)」に取るため、`cmd_` 以外の関数へ使うと
 * 後続の関数を巻き込む。誤用を防ぐため、名前が `cmd_` で始まらなければ例外にする。
 *
 * 非貪欲な `\n\}` 終端は使わない: 関数本体がヒアドキュメント (`<<'PY'` 等) 内に
 * 行頭 `}` を持つと最短一致がそこで止まり、真の末尾を取り逃す。
 *
 * 見つからないときも例外にする (静かに空文字を返して緑にしない)。
 */
final class ShellFunctionWindow
{
    /**
     * `cmd_<名前>()` の定義行から次の `^cmd_` 定義 (または末尾) までを返す。
     */
    public static function ofCommand(string $source, string $commandFunction): string
    {
        if (! str_starts_with($commandFunction, 'cmd_')) {
            throw new InvalidArgumentException(
                "ofCommand() は cmd_ で始まる関数専用である (次の cmd_ 定義まで切り出すため): {$commandFunction}"
            );
        }

        $matches = [];
        // cmd_provision と cmd_provision_all を取り違えないよう `()` まで含めてアンカーする。
        $matched = preg_match(
            '/^'.preg_quote($commandFunction, '/').'\(\)[\s\S]*?(?=^cmd_|\z)/m',
            $source,
            $matches
        );

        if ($matched !== 1) {
            throw new RuntimeException("シェル関数の窓が見つからない: {$commandFunction}");
        }

        /** @var array{0: string} $matches */
        return $matches[0];
    }
}
