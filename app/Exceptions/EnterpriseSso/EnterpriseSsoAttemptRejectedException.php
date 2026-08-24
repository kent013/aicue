<?php

declare(strict_types=1);

namespace App\Exceptions\EnterpriseSso;

use App\Enums\EnterpriseSso\RejectionReason;
use RuntimeException;

/**
 * 企業 SSO の試行を拒否したことを表す例外。
 *
 * ★**構築子は理由の enum しか受け取らない**。`previous` を受け取れないので、
 *   vendor の例外を連鎖させて要求 body (認可コード / client secret / code_verifier) が
 *   ログへ展開される経路が**型で存在しない**。
 *   この形は tests/Architecture/EnterpriseSsoSecretExposureGateTest が構築子の引数で固定する。
 * ★message は理由の値そのものである (外部由来の文字列を混ぜない)。
 * ★利用者への応答は理由によらず**一様**である。区別は内部にしか無い。
 */
final class EnterpriseSsoAttemptRejectedException extends RuntimeException
{
    private function __construct(public readonly RejectionReason $reason)
    {
        parent::__construct($reason->value);
    }

    public static function of(RejectionReason $reason): self
    {
        return new self($reason);
    }
}
