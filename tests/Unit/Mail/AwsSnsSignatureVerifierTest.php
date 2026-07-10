<?php

declare(strict_types=1);

use App\Services\Mail\Sns\AwsSnsSignatureVerifier;
use App\Services\Mail\Sns\SnsSignatureInvalidException;
use App\Services\Mail\Sns\SnsVerificationUnavailableException;
use Aws\Sns\Message;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\Support\SnsTestData;

function makeSnsVerifier(): AwsSnsSignatureVerifier
{
    return new AwsSnsSignatureVerifier(app(HttpFactory::class));
}

test('cert ホストが不正なら署名不正 (恒久) で HTTP 取得すらしない', function (): void {
    Http::fake();
    $message = new Message(SnsTestData::notification('{}', [
        'SigningCertURL' => 'https://s3.amazonaws.com/SimpleNotificationService-x.pem',
    ]));

    expect(fn () => makeSnsVerifier()->verify($message))
        ->toThrow(SnsSignatureInvalidException::class);

    Http::assertNothingSent();
});

test('cert URL 境界: http / port / host / path / query を拒否', function (string $certUrl): void {
    Http::fake();
    $message = new Message(SnsTestData::notification('{}', ['SigningCertURL' => $certUrl]));

    expect(fn () => makeSnsVerifier()->verify($message))
        ->toThrow(SnsSignatureInvalidException::class);

    Http::assertNothingSent();
})->with([
    'http scheme' => ['http://sns.us-east-1.amazonaws.com/SimpleNotificationService-x.pem'],
    'non-443 port' => ['https://sns.us-east-1.amazonaws.com:8443/SimpleNotificationService-x.pem'],
    'wrong host' => ['https://sns.us-east-1.amazonaws.com.evil.com/SimpleNotificationService-x.pem'],
    'wrong path' => ['https://sns.us-east-1.amazonaws.com/evil.pem'],
    'with query' => ['https://sns.us-east-1.amazonaws.com/SimpleNotificationService-x.pem?a=1'],
]);

test('cert 取得失敗は一時障害 (Unavailable → 503 再試行)', function (): void {
    Http::fake([SnsTestData::CERT_URL => Http::response('', 500)]);
    $message = new Message(SnsTestData::notification('{}'));

    expect(fn () => makeSnsVerifier()->verify($message))
        ->toThrow(SnsVerificationUnavailableException::class);
});

test('cert 到達後に署名検証が落ちれば署名不正 (恒久)', function (): void {
    // cert URL は到達するが、中身は本物の証明書ではないため署名検証で落ちる。
    Http::fake([SnsTestData::CERT_URL => Http::response('-----BEGIN CERTIFICATE-----\nnot-a-real-cert\n-----END CERTIFICATE-----', 200)]);
    $message = new Message(SnsTestData::notification('{}'));

    expect(fn () => makeSnsVerifier()->verify($message))
        ->toThrow(SnsSignatureInvalidException::class);
});

test('正当な cert URL は HTTP 取得まで進む (Unavailable にならず署名段に到達)', function (): void {
    Http::fake([SnsTestData::CERT_URL => Http::response('cert-body', 200)]);
    $message = new Message(SnsTestData::notification('{}'));

    // 署名段で Invalid になる (到達はした = Unavailable ではない) ことで分類を確認。
    expect(fn () => makeSnsVerifier()->verify($message))
        ->toThrow(SnsSignatureInvalidException::class);

    Http::assertSent(fn ($request): bool => $request->url() === SnsTestData::CERT_URL);
});
