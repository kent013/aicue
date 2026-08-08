<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * 走査結果。**site (実行位置) と import (ファイルスコープの alias 宣言) を分けて返す**。
 *
 * ★`use` import は site ではない (PHP の `use` はクラス本体の外に書かれるため、site 扱いすると
 *   正規の import を持つ全ファイルが違反になる)。一方で「このファイルが決済名前空間を
 *   知っているか」のような**ファイル単位の文脈判定**には import が要る。よって捨てずに
 *   metadata として返す。
 */
final readonly class ReferenceScanResult
{
    /**
     * @param  list<ReferenceSite>  $sites
     * @param  array<string, string>  $imports  小文字 short name => FQCN (`use` 宣言の全件)
     */
    public function __construct(
        public array $sites,
        public array $imports,
    ) {}
}
