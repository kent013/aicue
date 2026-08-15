<?php

declare(strict_types=1);

namespace App\Exceptions\Llm;

use App\Enums\Llm\UntrustedInputRejectionReason;
use RuntimeException;

/**
 * untrusted 入力を prompt に載せる前に拒否した (fail-closed)。
 *
 * ★ 例外 message に**入力の中身を載せない** (untrusted 文字列をログへ流さない)。
 *   載せてよいのはバイト数と上限値という数値だけである。
 */
final class UntrustedInputRejectedException extends RuntimeException
{
    private function __construct(
        public readonly UntrustedInputRejectionReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function tooLarge(int $actualBytes, int $limitBytes): self
    {
        return new self(
            UntrustedInputRejectionReason::TooLarge,
            "untrusted 入力が上限を超えています ({$actualBytes} > {$limitBytes} バイト)",
        );
    }

    public static function invalidEncoding(): self
    {
        return new self(
            UntrustedInputRejectionReason::InvalidEncoding,
            'untrusted 入力が有効な UTF-8 ではありません',
        );
    }
}
