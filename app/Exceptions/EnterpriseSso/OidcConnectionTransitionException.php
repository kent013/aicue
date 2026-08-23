<?php

declare(strict_types=1);

namespace App\Exceptions\EnterpriseSso;

use App\Enums\EnterpriseSso\ConnectionTransitionRejection;
use RuntimeException;

/**
 * 接続の管理操作を拒否したことを表す例外。
 *
 * ★構築子は**理由の enum しか受け取らない** (`previous` を持たない)。
 *   秘密が例外の連鎖で展開される経路を型で消すのは
 *   {@see EnterpriseSsoAttemptRejectedException} と同じ思想である。
 */
final class OidcConnectionTransitionException extends RuntimeException
{
    private function __construct(public readonly ConnectionTransitionRejection $rejection)
    {
        parent::__construct($rejection->value);
    }

    public static function of(ConnectionTransitionRejection $rejection): self
    {
        return new self($rejection);
    }
}
