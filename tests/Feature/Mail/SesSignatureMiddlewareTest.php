<?php

declare(strict_types=1);

use App\Models\EmailSuppression;
use App\Services\Mail\Sns\SnsSignatureInvalidException;
use App\Services\Mail\Sns\SnsSignatureVerifier;
use App\Services\Mail\Sns\SnsVerificationUnavailableException;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeSnsSignatureVerifier;
use Tests\Support\SnsTestData;

/**
 * VerifySnsSignature middleware のステータス出し分け (400/403/503)。
 *
 * 末尾の 4 本は `app()->instance(SnsSignatureVerifier::class, …)` を**呼ばない**ことで
 * 実 verifier (AwsSnsSignatureVerifier + SnsCertificateFetcher) を通し、
 * DI / 自動解決 / 実証明書検証 / controller 到達までを end-to-end で固定する。
 */
beforeEach(function (): void {
    config(['services.ses.sns_topic_arns' => [SnsTestData::TOPIC_ARN]]);
    // `Cache::flush()` は使わない (middleware の throttle も cache を使うため)。
    useFreshSnsCertificateCacheStore();
    bindSnsDnsResolver(snsPublicCertHostIps());
});

/** @return array<string, mixed> */
function snsBouncePayload(string $email = 'bounce@example.com'): array
{
    return SnsTestData::notification(SnsTestData::bounceMessageJson('Permanent', [$email]));
}

/** @param array<string, mixed> $payload */
function postSns(array $payload): TestResponse
{
    return test()->call('POST', '/ses/notification', [], [], [], [], (string) json_encode($payload));
}

test('署名不正は 403 で抑止記録なし', function (): void {
    app()->instance(SnsSignatureVerifier::class, new FakeSnsSignatureVerifier(
        new SnsSignatureInvalidException('bad'),
    ));

    $response = postSns(snsBouncePayload());

    $response->assertStatus(403);
    expect(EmailSuppression::query()->count())->toBe(0);
});

test('一時障害は 503 (再試行可能、403 にしない) で抑止記録なし', function (): void {
    app()->instance(SnsSignatureVerifier::class, new FakeSnsSignatureVerifier(
        new SnsVerificationUnavailableException('cert fetch failed'),
    ));

    postSns(snsBouncePayload())->assertStatus(503);
    expect(EmailSuppression::query()->count())->toBe(0);
});

test('外側 JSON 不正 / list 配列は 400', function (): void {
    app()->instance(SnsSignatureVerifier::class, new FakeSnsSignatureVerifier);

    test()->call('POST', '/ses/notification', [], [], [], [], 'not-json')->assertStatus(400);
    test()->call('POST', '/ses/notification', [], [], [], [], '[1,2,3]')->assertStatus(400);
});

test('SNS envelope 構造不備 (必須キー欠落) は 400', function (): void {
    app()->instance(SnsSignatureVerifier::class, new FakeSnsSignatureVerifier);

    // Type だけで他必須キーがない → Message constructor が InvalidArgumentException。
    postSns(['Type' => 'Notification', 'TopicArn' => SnsTestData::TOPIC_ARN])->assertStatus(400);
});

test('実 verifier: 両キー同時送信は 403 で外向き通信をしない', function (): void {
    Http::fake();

    $payload = SnsTestData::notification(
        SnsTestData::bounceMessageJson('Permanent', ['bounce@example.com']),
        ['SigningCertUrl' => 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-evil.pem'],
    );

    postSns($payload)->assertStatus(403);

    Http::assertNothingSent();
    expect(EmailSuppression::query()->count())->toBe(0);
});

test('実 verifier: 証明書取得の HTTP 失敗は 503', function (): void {
    Http::fake([SnsTestData::CERT_URL => Http::response('', 500)]);

    postSns(snsBouncePayload())->assertStatus(503);

    expect(EmailSuppression::query()->count())->toBe(0);
});

test('実 verifier: SSRF 拒否は 403', function (): void {
    Http::fake();
    bindSnsDnsResolver(['10.0.0.5']);

    postSns(snsBouncePayload())->assertStatus(403);

    Http::assertNothingSent();
    expect(EmailSuppression::query()->count())->toBe(0);
});

test('実 verifier: 署名済みのバウンス通知は受理され抑止が記録される', function (): void {
    $signed = SnsTestData::signedNotification(
        SnsTestData::bounceMessageJson('Permanent', ['bounce@example.com']),
    );
    Http::fake([SnsTestData::CERT_URL => Http::response($signed['pem'], 200)]);

    postSns($signed['payload'])->assertStatus(200);
    expect(EmailSuppression::query()->count())->toBe(1);

    // (a) 2 回目も受理される / (b) 昇格が効いて外向き HTTP が増えない / (c) 重複作成しない
    postSns($signed['payload'])->assertStatus(200);
    Http::assertSentCount(1);
    expect(EmailSuppression::query()->count())->toBe(1);
});
