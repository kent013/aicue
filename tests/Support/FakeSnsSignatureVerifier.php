<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Mail\Sns\SnsSignatureVerifier;
use Aws\Sns\Message;

/**
 * Feature テスト用の SNS 署名検証 fake。
 *
 * 既定では検証成功 (pass)。`$throw` に例外を渡すと verify 時にそれを投げ、middleware の
 * ステータス出し分け (403 / 503) を検証できる。
 */
final class FakeSnsSignatureVerifier implements SnsSignatureVerifier
{
    public function __construct(private readonly ?\Throwable $throw = null) {}

    public function verify(Message $message): void
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }
    }
}
