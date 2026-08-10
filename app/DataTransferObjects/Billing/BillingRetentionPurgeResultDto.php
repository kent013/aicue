<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

use App\Enums\Billing\BillingRetentionTarget;

/**
 * 保持期間 purge の 1 target 分の結果。
 *
 * **任意メタデータ領域 (`array<string, mixed>`) は持たせない** — 何が入るか型で分からない
 * 領域を作ると、そこに organization id やメールアドレスが載って運用ログへ漏れる。
 *
 * 件数の関係:
 *   candidates      = 起算済み・期限超過の件数 (purge 前)
 *   processed       = 実際に削除 (C2 では畳み込み) した件数
 *   failClosed      = 安全のため残した件数 (起算不能の異常 + 参照中で消せないもの)
 *   expiredRemaining = purge 後に残った起算済み・期限超過の件数
 *
 * **`failClosed` は「安全に残した」であって「規約を満たした」ではない**。
 * 規約 (最長 N 年) を満たしたと言えるのは `expiredRemaining === 0` のときだけである。
 */
final readonly class BillingRetentionPurgeResultDto
{
    public function __construct(
        public BillingRetentionTarget $target,
        public int $candidates,
        public int $processed,
        public int $failClosed,
        public int $unexpectedFailures,
        public int $expiredRemaining,
    ) {}

    /**
     * dry-run (1 行も消さない) の結果。
     *
     * 何も消していないのだから残存 = 候補である (楽観的に 0 と報告しない)。
     */
    public static function dryRun(
        BillingRetentionTarget $target,
        int $candidates,
        int $failClosed,
    ): self {
        return new self(
            target: $target,
            candidates: $candidates,
            processed: 0,
            failClosed: $failClosed,
            unexpectedFailures: 0,
            expiredRemaining: $candidates,
        );
    }

    public function hasFailClosedRecords(): bool
    {
        return $this->failClosed > 0;
    }

    public function hasUnexpectedFailures(): bool
    {
        return $this->unexpectedFailures > 0;
    }

    /**
     * 規約文面の公開 (PR-C3) に進んでよいか。
     *
     * **分類を問わず期限超過 0 件**が条件である。`failClosed` を除外して「安全に残したものは
     * 数えない」とすると、規約が宣言した年数を超えた記録が残ったまま「準拠した」と言えてしまう。
     */
    public function isPublicationReady(): bool
    {
        return $this->failClosed === 0
            && $this->unexpectedFailures === 0
            && $this->expiredRemaining === 0;
    }
}
