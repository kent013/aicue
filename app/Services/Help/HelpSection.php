<?php

declare(strict_types=1);

namespace App\Services\Help;

/**
 * ヘルプの 1 節 (manifest の 1 エントリ)。
 *
 * ★`generatorKey` が null なら手書きページ、非 null なら生成物である。
 *   「生成物かどうか」の判定はこの 1 か所だけが持つ (呼び出し側でパスの前綴りを見ない)。
 */
final readonly class HelpSection
{
    /**
     * @param  non-empty-string  $slug
     * @param  non-empty-string  $title
     * @param  non-empty-string  $path  `docs/help/` からの相対パス
     * @param  non-empty-string|null  $generatorKey
     */
    public function __construct(
        public string $slug,
        public string $title,
        public string $path,
        public ?string $generatorKey,
    ) {}

    public function isGenerated(): bool
    {
        return $this->generatorKey !== null;
    }
}
