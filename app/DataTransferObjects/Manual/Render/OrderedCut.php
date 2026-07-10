<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual\Render;

use App\Models\Cut;

/**
 * 表示順に並べたカット + 表示ラベル (手順N / 急所N-M)。
 * トリガーの採用テイク検証 (欠落ラベル一覧) とマニフェスト構築が共用する。
 */
final readonly class OrderedCut
{
    public function __construct(
        public Cut $cut,
        public string $label,
    ) {}
}
