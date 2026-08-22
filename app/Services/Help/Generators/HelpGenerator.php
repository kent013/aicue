<?php

declare(strict_types=1);

namespace App\Services\Help\Generators;

/** ヘルプの節を実装から組み立てる生成器。 */
interface HelpGenerator
{
    /** manifest の `generator` と突き合わせるキー。 */
    public function key(): string;

    /** 生成した Markdown 本文 (末尾は改行 1 個で終わること)。 */
    public function generate(): string;
}
