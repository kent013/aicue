<?php

declare(strict_types=1);

namespace Tests\Support\SurfaceRemoval;

/** 撤去語の出現 (どこに何行目で出たか)。 */
final readonly class Occurrence
{
    public function __construct(
        public string $relative,
        public int $line,
        /** 一致した run (診断用の原文)。 */
        public string $matched,
    ) {}

    /** 違反説明の 1 行 (gate の失敗メッセージ用)。 */
    public function describe(): string
    {
        return sprintf('%s:%d %s', $this->relative, $this->line, $this->matched);
    }
}
