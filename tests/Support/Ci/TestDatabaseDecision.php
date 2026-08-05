<?php

declare(strict_types=1);

namespace Tests\Support\Ci;

/**
 * 分類結果 1 件。
 *
 * `reason` は dry-run 出力の説明責任のために**必ず具体的な文字列**で持つ
 * (「なぜ落とす / なぜ残す」を人間が読んで判断できないと `--apply` の承認ができない)。
 */
final readonly class TestDatabaseDecision
{
    public function __construct(
        public TestDatabaseCandidate $candidate,
        public TestDatabaseClassification $classification,
        public string $reason,
        public bool $shouldDrop,
    ) {}
}
