<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

/**
 * 根拠に書かれた作業項目の番号 (`T<n>`) が TODO 台帳に実在するかを、境界付きで照合する。
 *
 * 素の部分文字列判定を使わないのが要点である。`T1` は `T10` にも `T100` にも一致してしまい、
 * 「実在する」と誤って通す。TODO 台帳は表なので、**表のセルとして**現れることを照合する。
 */
final class TodoLedgerReference
{
    /** `$reference` が `$todoMarkdown` の表の ID セルとして実在するか。 */
    public static function existsIn(string $reference, string $todoMarkdown): bool
    {
        $pattern = '/^\|\s*'.preg_quote($reference, '/').'\s*\|/mu';

        return preg_match($pattern, $todoMarkdown) === 1;
    }
}
