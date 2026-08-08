<?php

declare(strict_types=1);

namespace Tests\Support\ExternalSeam;

/**
 * 走査結果。**採用 site と抑制 site を別コレクションで保持する**
 * (抑制後に情報を復元する実装にしない)。
 *
 * `suppressed` は「規則には一致したが、同一ファイルに決済名前空間の参照が無いため
 * 落とした `->stripe()` の site」。これが 1 件でもあれば抑制規則が実際に働いている =
 * 偽陰性の口が開いているので gate が赤くなる。
 */
final readonly class ExternalSeamScanResult
{
    /**
     * @param  list<ExternalSeamSite>  $adopted
     * @param  list<ExternalSeamSite>  $suppressed
     */
    public function __construct(
        public array $adopted,
        public array $suppressed,
    ) {}
}
