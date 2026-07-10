<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\Captcha\RecaptchaVerifier;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * reCAPTCHA v2 invisible トークンをサーバ側で検証する rule。
 *
 * honeypot (silent success) と違い、検証失敗は validation エラーで返す
 * (実ユーザーが retry できるべきで、silent drop しない)。
 */
final class Recaptcha implements ValidationRule
{
    public function __construct(
        private readonly RecaptchaVerifier $verifier,
        private readonly ?string $ip,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $token = is_string($value) ? $value : null;

        if (! $this->verifier->verify($token, $this->ip)) {
            $fail('確認に失敗しました。お手数ですが、もう一度お試しください。');
        }
    }
}
