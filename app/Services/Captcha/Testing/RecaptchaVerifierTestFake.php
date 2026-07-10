<?php

declare(strict_types=1);

namespace App\Services\Captcha\Testing;

use App\Services\Captcha\RecaptchaVerifier;

/**
 * Browser / E2E テスト lane 用 RecaptchaVerifier の fake。
 *
 * Google siteverify への実通信を避け、常に成功 (true) を返す。Browser テストでは
 * reCAPTCHA widget を実描画せず token も空になるため、サーバ側検証を素通りさせる。
 * container で `app()->instance(RecaptchaVerifier::class, new RecaptchaVerifierTestFake)`
 * のように差し替えて使う。
 */
final class RecaptchaVerifierTestFake extends RecaptchaVerifier
{
    public function verify(?string $token, ?string $ip): bool
    {
        return true;
    }
}
