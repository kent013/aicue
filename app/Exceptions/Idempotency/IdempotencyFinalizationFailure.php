<?php

declare(strict_types=1);

namespace App\Exceptions\Idempotency;

use RuntimeException;

/**
 * claim 行を確定できなかったことの**観測専用**例外 (throw せず report() にだけ渡す)。
 *
 * ⚠ **元例外を previous に連結しない**。連結すると外部生成の可変文字列 (例外 message) が
 * ログに載り、「載せてよい 5 項目だけ」という契約が壊れる (AGENTS.md の
 * 「例外 message はログに載せない」と同型の判断)。載せるのは
 * route 名 / actor 種別 / 期待した state / affected rows / 例外クラス名 の 5 つだけ。
 * **Idempotency-Key の値・request body・保存応答 body は載せない**。
 */
final class IdempotencyFinalizationFailure extends RuntimeException
{
    public static function make(
        string $routeName,
        string $actorKind,
        string $expectedState,
        int $affectedRows,
        ?string $causeClass = null,
    ): self {
        return new self(sprintf(
            'Idempotency finalization failed. route=%s actor_kind=%s expected_state=%s affected_rows=%d cause=%s',
            $routeName,
            $actorKind,
            $expectedState,
            $affectedRows,
            $causeClass ?? 'none',
        ));
    }
}
