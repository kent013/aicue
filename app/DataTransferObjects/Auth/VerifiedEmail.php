<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Auth;

use App\Support\EmailNormalizer;

/**
 * 確認を通したメールアドレス。
 *
 * ★**この型を経由したものだけを `users.email` へ書く**。素の文字列を
 *   昇格の確定へ渡す道を型で消す (「確認していないメールを昇格させる」経路を作らない)。
 */
final readonly class VerifiedEmail
{
    private function __construct(public string $value) {}

    /**
     * ★呼んでよいのは**確認トークンの照合を通した後**だけである。
     */
    public static function afterConfirmation(string $email): self
    {
        return new self(EmailNormalizer::normalize($email));
    }
}
