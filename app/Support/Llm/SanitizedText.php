<?php

declare(strict_types=1);

namespace App\Support\Llm;

/**
 * 無害化の結果。
 *
 * ★ 除去件数は観測用であり、**除去した文字そのものは保持しない**
 *   (untrusted 文字列をログや例外へ運ぶ経路を作らない)。
 */
final readonly class SanitizedText
{
    public function __construct(
        public string $text,
        public int $removedCharacters,
    ) {}
}
