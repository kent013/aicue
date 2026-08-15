<?php

declare(strict_types=1);

namespace Tests\Support\Llm;

/** 窓口 gate が検出した 1 site (走査根からの相対パス + 行 + 種別 + 対象シンボル)。 */
final readonly class PromptWindowSite
{
    public function __construct(
        public string $path,
        public int $line,
        public PromptWindowRule $rule,
        public string $symbol,
    ) {}

    public function describe(): string
    {
        return "{$this->path}:{$this->line} [{$this->rule->value}] {$this->symbol}";
    }
}
