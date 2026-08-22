<?php

declare(strict_types=1);

namespace App\Services\Help;

/**
 * ツール 1 本のパラメータ。**表示用に正規化済み**の値だけを持つ。
 *
 * ★`type` は vendor が返した型 (文字列 or 文字列の配列) を `|` で連結した表示用文字列である。
 *   閉じた集合で弾かない (正典が名指しした設計判断)。
 */
final readonly class McpToolParameter
{
    /**
     * @param  non-empty-string  $name
     * @param  non-empty-string  $type
     */
    public function __construct(
        public string $name,
        public string $type,
        public bool $required,
        public string $description,
    ) {}
}
