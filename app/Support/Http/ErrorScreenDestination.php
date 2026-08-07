<?php

declare(strict_types=1);

namespace App\Support\Http;

/**
 * Error 画面の戻り先 1 件。**サーバ側で固定した値しか入らない**
 * (referer / intended / query / route parameter を一切読まない = open redirect が構造的に不成立)。
 */
final readonly class ErrorScreenDestination
{
    public function __construct(
        public string $label,
        public string $href,
    ) {}

    /** @return array{label: string, href: string} */
    public function toArray(): array
    {
        return ['label' => $this->label, 'href' => $this->href];
    }
}
