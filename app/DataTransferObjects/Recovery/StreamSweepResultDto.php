<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Recovery;

use App\Enums\Recovery\RecoveryOutcome;
use App\Enums\Recovery\RecoveryStream;

/**
 * stream 1 本の掃引結果。**任意メタデータの領域は持たせない**
 * (型で分からない領域を作ると主キー等が運用ログへ漏れる)。
 *
 * $limitReached は「上限に達し、かつ**未処理の候補が実在する**」ときだけ true にする
 * (ちょうど上限件数で候補が尽きた場合は false = 打ち切りではない)。
 */
final readonly class StreamSweepResultDto
{
    /** @param  array<value-of<RecoveryOutcome>, int<0, max>>  $outcomes */
    public function __construct(
        public RecoveryStream $stream,
        public bool $applied,
        public int $candidates,
        public array $outcomes,
        public int $failures,
        public bool $limitReached,
    ) {}

    public function count(RecoveryOutcome $outcome): int
    {
        return $this->outcomes[$outcome->value] ?? 0;
    }
}
