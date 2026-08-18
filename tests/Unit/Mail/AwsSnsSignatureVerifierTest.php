<?php

declare(strict_types=1);

use App\Services\Mail\Sns\AwsSnsSignatureVerifier;
use App\Services\Mail\Sns\SnsCertificateFetcher;
use App\Services\Mail\Sns\SnsSignatureInvalidException;
use App\Services\Mail\Sns\SnsVerificationUnavailableException;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Illuminate\Support\Facades\Http;
use Tests\Support\SnsTestData;

beforeEach(function (): void {
    useFreshSnsCertificateCacheStore();
    bindSnsDnsResolver(['203.0.113.10']);
});

function makeSnsVerifier(): AwsSnsSignatureVerifier
{
    return new AwsSnsSignatureVerifier(app(SnsCertificateFetcher::class));
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

test('cert URL 境界: http / port / host / path / query / credential / fragment を拒否', function (string $certUrl): void {
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
    'with credential' => ['https://user:pass@sns.us-east-1.amazonaws.com/SimpleNotificationService-x.pem'],
    'with fragment' => ['https://sns.us-east-1.amazonaws.com/SimpleNotificationService-x.pem#frag'],
]);

test('cert 取得失敗は一時障害 (Unavailable → 503 再試行)', function (): void {
    Http::fake([SnsTestData::CERT_URL => Http::response('', 500)]);
    $message = new Message(SnsTestData::notification('{}'));

    expect(fn () => makeSnsVerifier()->verify($message))
        ->toThrow(SnsVerificationUnavailableException::class);
});

test('cert 到達後に署名検証が落ちれば署名不正 (恒久)', function (): void {
    // 証明書は PEM として有効だが、封筒の Signature がダミー値なので署名段で落ちる。
    Http::fake([SnsTestData::CERT_URL => Http::response(SnsTestData::certificatePem(), 200)]);
    $message = new Message(SnsTestData::notification('{}'));

    expect(fn () => makeSnsVerifier()->verify($message))
        ->toThrow(SnsSignatureInvalidException::class);

    Http::assertSentCount(1);
});

test('正当な cert URL は HTTP 取得まで進む (Unavailable にならず署名段に到達)', function (): void {
    Http::fake([SnsTestData::CERT_URL => Http::response(SnsTestData::certificatePem(), 200)]);
    $message = new Message(SnsTestData::notification('{}'));

    // 署名段で Invalid になる (到達はした = Unavailable ではない) ことで分類を確認。
    expect(fn () => makeSnsVerifier()->verify($message))
        ->toThrow(SnsSignatureInvalidException::class);

    Http::assertSent(fn ($request): bool => $request->url() === SnsTestData::CERT_URL);
});

test('両キー同時送信は署名不正 (恒久) で HTTP 取得すらしない', function (): void {
    Http::fake();
    $message = new Message(SnsTestData::notification('{}', [
        'SigningCertUrl' => 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-evil.pem',
    ]));

    expect(fn () => makeSnsVerifier()->verify($message))
        ->toThrow(SnsSignatureInvalidException::class);

    Http::assertNothingSent();
});

test('lambda キー単独でも同じ cert URL を取りに行く', function (): void {
    Http::fake([SnsTestData::CERT_URL => Http::response(SnsTestData::certificatePem(), 200)]);
    $message = new Message(SnsTestData::lambdaStyleNotification('{}'));

    expect(fn () => makeSnsVerifier()->verify($message))
        ->toThrow(SnsSignatureInvalidException::class);

    Http::assertSent(fn ($request): bool => $request->url() === SnsTestData::CERT_URL);
});

test('署名検証が落ちたら証明書はキャッシュに載らない (要件 6)', function (): void {
    Http::fake([SnsTestData::CERT_URL => Http::response(SnsTestData::certificatePem(), 200)]);
    $message = new Message(SnsTestData::notification('{}'));

    expect(fn () => makeSnsVerifier()->verify($message))->toThrow(SnsSignatureInvalidException::class);
    expect(fn () => makeSnsVerifier()->verify($message))->toThrow(SnsSignatureInvalidException::class);

    // 載っていないので 2 回とも取りに行く
    Http::assertSentCount(2);
});

test('署名検証が通ったら証明書はキャッシュに載る (要件 6)', function (): void {
    $signed = SnsTestData::signedNotification('{}');
    Http::fake([SnsTestData::CERT_URL => Http::response($signed['pem'], 200)]);
    $message = new Message($signed['payload']);

    makeSnsVerifier()->verify($message);
    makeSnsVerifier()->verify($message);

    Http::assertSentCount(1);
});

test('vendor 契約: 両キーがあると SDK は lambda キーの値を取りに行く', function (): void {
    // ★両キー拒否という対策の**前提**そのもの。vendor が変わったらここが赤くなる。
    $lambdaUrl = 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-lambda99.pem';
    $payload = SnsTestData::notification('{}', ['SigningCertUrl' => $lambdaUrl]);

    $requested = null;
    $validator = new MessageValidator(function (string $url) use (&$requested): string {
        $requested = $url;

        // 証明書の中身や後続の署名検証に assertion を依存させないため、記録したら打ち切る。
        throw new DomainException('stop after recording');
    });

    expect(fn () => $validator->validate(new Message($payload)))->toThrow(DomainException::class);
    expect($requested)->toBe($lambdaUrl);
});
