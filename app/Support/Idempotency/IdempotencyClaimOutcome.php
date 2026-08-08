<?php

declare(strict_types=1);

namespace App\Support\Idempotency;

use App\Enums\Idempotency\IdempotencyClaimStatus;
use App\Models\IdempotencyKey;
use Webmozart\Assert\Assert;

/**
 * claim 試行の結果 (status と対象行の組合せ不変条件を型で閉じる)。
 *
 * `__construct` は private で、named constructor 経由でしか作れない。
 * これにより「Replay なのに row が無い」「Claimed なのに row を持っている」といった
 * 無効な組合せを**構築できなくする** (呼び出し側で null 判定を書かないための境界)。
 */
final class IdempotencyClaimOutcome
{
    private function __construct(
        public readonly IdempotencyClaimStatus $status,
        private readonly ?IdempotencyKey $row,
    ) {}

    /** 自分が claim を取得した (行は自分が書いたので保持しない) */
    public static function claimed(): self
    {
        return new self(IdempotencyClaimStatus::Claimed, null);
    }

    public static function replay(IdempotencyKey $row): self
    {
        return new self(IdempotencyClaimStatus::Replay, $row);
    }

    public static function conflict(IdempotencyKey $row): self
    {
        return new self(IdempotencyClaimStatus::Conflict, $row);
    }

    public static function inProgress(IdempotencyKey $row): self
    {
        return new self(IdempotencyClaimStatus::InProgress, $row);
    }

    public static function indeterminate(IdempotencyKey $row): self
    {
        return new self(IdempotencyClaimStatus::Indeterminate, $row);
    }

    /** row を持つ status からのみ呼ぶ (Claimed で呼ぶのは配線ミス) */
    public function rowOrFail(): IdempotencyKey
    {
        Assert::notNull(
            $this->row,
            'IdempotencyClaimOutcome::rowOrFail() called on a status that carries no row.',
        );

        return $this->row;
    }
}
