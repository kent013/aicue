<?php

declare(strict_types=1);

use App\Services\Captcha\RecaptchaVerifier;
use App\Services\Captcha\Testing\RecaptchaVerifierTestFake;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'services.recaptcha.secret_key' => 'test-secret',
        'app.url' => 'https://example.test',
    ]);
    $this->verifier = new RecaptchaVerifier;
});

test('success=true かつ hostname 一致で true を返す', function (): void {
    Http::fake(['*' => Http::response(['success' => true, 'hostname' => 'example.test'], 200)]);

    expect($this->verifier->verify('token', '127.0.0.1'))->toBeTrue();
});

test('success=false (invalid token) は fail-closed', function (): void {
    Http::fake(['*' => Http::response(['success' => false], 200)]);

    expect($this->verifier->verify('token', '127.0.0.1'))->toBeFalse();
});

test('空 token は Google を呼ばず fail-closed', function (): void {
    Http::fake();

    expect($this->verifier->verify(null, '127.0.0.1'))->toBeFalse();
    expect($this->verifier->verify('', '127.0.0.1'))->toBeFalse();

    Http::assertNothingSent();
});

test('secret 未設定は production では fail-closed', function (): void {
    config(['services.recaptcha.secret_key' => null]);
    $this->app['env'] = 'production';
    Http::fake();

    expect($this->verifier->verify('token', '127.0.0.1'))->toBeFalse();

    Http::assertNothingSent();
});

test('secret 未設定は production 以外では fail-open (開発を止めない)', function (): void {
    config(['services.recaptcha.secret_key' => null]);
    Http::fake();

    expect($this->verifier->verify('token', '127.0.0.1'))->toBeTrue();

    Http::assertNothingSent();
});

test('transport error (timeout) は fail-open', function (): void {
    Http::fake(function (): void {
        throw new ConnectionException('timeout');
    });

    expect($this->verifier->verify('token', '127.0.0.1'))->toBeTrue();
});

test('Google 5xx は fail-open', function (): void {
    Http::fake(['*' => Http::response('', 503)]);

    expect($this->verifier->verify('token', '127.0.0.1'))->toBeTrue();
});

test('4xx は判定不能だが fail-closed', function (): void {
    Http::fake(['*' => Http::response('', 400)]);

    expect($this->verifier->verify('token', '127.0.0.1'))->toBeFalse();
});

test('hostname 不一致は fail-closed', function (): void {
    Http::fake(['*' => Http::response(['success' => true, 'hostname' => 'evil.test'], 200)]);

    expect($this->verifier->verify('token', '127.0.0.1'))->toBeFalse();
});

test('期待ホスト解決時の hostname 欠落は fail-closed', function (): void {
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    expect($this->verifier->verify('token', '127.0.0.1'))->toBeFalse();
});

test('app.url 未設定時は hostname 検証をスキップ (success のみ採用)', function (): void {
    config(['app.url' => '']);
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    expect($this->verifier->verify('token', '127.0.0.1'))->toBeTrue();
});

test('テスト用 fake は実通信なしで常に true を返す', function (): void {
    Http::fake();

    $fake = new RecaptchaVerifierTestFake;

    expect($fake->verify(null, null))->toBeTrue();
    expect($fake->verify('token', '127.0.0.1'))->toBeTrue();

    Http::assertNothingSent();
});
