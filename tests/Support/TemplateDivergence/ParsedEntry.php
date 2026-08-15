<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

/**
 * 解析した逸脱の登録 1 件。
 *
 * `$metadata` が null なのは登録メタ表を解析できなかった場合で、そのときは
 * `ParsedLedger::$parseViolations` に理由が入っている (握り潰さない)。
 */
final readonly class ParsedEntry
{
    public function __construct(
        public int $id,
        public string $summary,
        public int $line,
        public ?EntryMetadata $metadata,
    ) {}

    /** 違反メッセージの見出し (どの登録の話かを 1 目で分かるようにする)。 */
    public function label(): string
    {
        return sprintf('D%d (%d 行目)', $this->id, $this->line);
    }
}
