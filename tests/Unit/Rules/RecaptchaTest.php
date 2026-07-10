<?php

declare(strict_types=1);

use App\Rules\Recaptcha;
use App\Services\Captcha\RecaptchaVerifier;
use Illuminate\Support\Facades\Validator;

/**
 * 検証結果を固定で返す verifier スタブを生成する。
 */
function fakeRecaptchaVerifier(bool $result): RecaptchaVerifier
{
    return new class($result) extends RecaptchaVerifier
    {
        public function __construct(private readonly bool $result) {}

        public function verify(?string $token, ?string $ip): bool
        {
            return $this->result;
        }
    };
}

test('verifier が成功を返せば validation を通す', function (): void {
    $validator = Validator::make(
        ['recaptcha_token' => 'token'],
        ['recaptcha_token' => [new Recaptcha(fakeRecaptchaVerifier(true), '127.0.0.1')]],
    );

    expect($validator->passes())->toBeTrue();
});

test('verifier が失敗を返せば validation エラー (retry 可能なエラー表示)', function (): void {
    $validator = Validator::make(
        ['recaptcha_token' => 'token'],
        ['recaptcha_token' => [new Recaptcha(fakeRecaptchaVerifier(false), '127.0.0.1')]],
    );

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('recaptcha_token'))->toBeTrue();
});

test('文字列以外の値は null token として verifier に渡す', function (): void {
    $spy = new class extends RecaptchaVerifier
    {
        public ?string $receivedToken = 'unset';

        public function verify(?string $token, ?string $ip): bool
        {
            $this->receivedToken = $token;

            return false;
        }
    };

    $validator = Validator::make(
        ['recaptcha_token' => ['array' => 'value']],
        ['recaptcha_token' => [new Recaptcha($spy, null)]],
    );

    expect($validator->fails())->toBeTrue();
    expect($spy->receivedToken)->toBeNull();
});
