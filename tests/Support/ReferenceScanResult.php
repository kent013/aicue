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
     * @param  array<string, string>  $imports  小文字 short name => FQCN。
     *                                          **ファイルスコープの `use` のうちクラス / 名前空間の
     *                                          import だけ**が載る (クラス本体の trait 取り込みと
     *                                          `use function` / `use const` は載らない)。
     *                                          **ファイル全体を 1 つの表へ畳んだ結果**なので、
     *                                          namespace ブロックが複数あって同じ短縮名を使う場合は
     *                                          後のブロックが勝つ。名前解決そのものは
     *                                          ブロックごとの表で行っており、この表は使っていない
     */
    public function __construct(
        public array $sites,
        public array $imports,
    ) {}
}
