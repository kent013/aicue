<?php

declare(strict_types=1);

use App\Enums\EmailSuppressionReason;
use App\Http\Controllers\Webhooks\SesNotificationController;
use App\Models\EmailSuppression;
use App\Services\Mail\Sns\SnsSignatureVerifier;
use Aws\Sns\SnsClient;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeSnsSignatureVerifier;
use Tests\Support\SnsTestData;

/**
 * SesNotificationController の挙動。署名検証は fake verifier で pass させ、
 * Controller の parse + 委譲 + allowlist + 冪等を検証する。
 */
beforeEach(function (): void {
    config(['services.ses.sns_topic_arns' => [SnsTestData::TOPIC_ARN]]);
    app()->instance(SnsSignatureVerifier::class, new FakeSnsSignatureVerifier);
});

/** @param array<string, mixed> $payload */
function postSnsCtl(array $payload): TestResponse
{
    return test()->call('POST', '/ses/notification', [], [], [], [], (string) json_encode($payload));
}

test('Permanent バウンスを reason=bounce で 1 行記録', function (): void {
    $payload = SnsTestData::notification(SnsTestData::bounceMessageJson('Permanent', ['Bounce@Example.com']));

    postSnsCtl($payload)->assertOk();

    $row = EmailSuppression::query()->sole();
    expect($row->email)->toBe('bounce@example.com') // normalize される
        ->and($row->reason)->toBe(EmailSuppressionReason::Bounce);
});

test('Transient バウンスは記録しない', function (): void {
    $payload = SnsTestData::notification(SnsTestData::bounceMessageJson('Transient', ['t@example.com']));

    postSnsCtl($payload)->assertOk();

    expect(EmailSuppression::query()->count())->toBe(0);
});

test('苦情を reason=complaint で記録', function (): void {
    $payload = SnsTestData::notification(SnsTestData::complaintMessageJson(['c@example.com']));

    postSnsCtl($payload)->assertOk();

    expect(EmailSuppression::query()->sole()->reason)->toBe(EmailSuppressionReason::Complaint);
});

test('同一アドレスの通知 2 回でも 1 行 (upsert 冪等)', function (): void {
    $payload = SnsTestData::notification(SnsTestData::bounceMessageJson('Permanent', ['dup@example.com']));

    postSnsCtl($payload)->assertOk();
    postSnsCtl($payload)->assertOk();

    expect(EmailSuppression::query()->where('email', 'dup@example.com')->count())->toBe(1);
});

test('allowlist 外の TopicArn は 403 で記録しない', function (): void {
    $payload = SnsTestData::notification(
        SnsTestData::bounceMessageJson('Permanent', ['x@example.com']),
        ['TopicArn' => 'arn:aws:sns:us-east-1:999:other'],
    );

    postSnsCtl($payload)->assertStatus(403);
    expect(EmailSuppression::query()->count())->toBe(0);
});

test('allowlist が空のときは全通知を拒否 (fail-closed)', function (): void {
    config(['services.ses.sns_topic_arns' => []]);

    postSnsCtl(SnsTestData::notification(SnsTestData::bounceMessageJson('Permanent', ['x@example.com'])))
        ->assertStatus(403);
    expect(EmailSuppression::query()->count())->toBe(0);
});

test('内側 SES JSON 不正は 200 破棄 (記録なし)', function (): void {
    $payload = SnsTestData::notification('{not-valid-json');

    postSnsCtl($payload)->assertOk();
    expect(EmailSuppression::query()->count())->toBe(0);
});

test('内側 JSON が object でない (scalar/list) も 200 破棄', function (string $inner): void {
    postSnsCtl(SnsTestData::notification($inner))->assertOk();
    expect(EmailSuppression::query()->count())->toBe(0);
})->with([
    'string' => ['"just a string"'],
    'number' => ['123'],
    'list' => ['["a","b"]'],
]);

test('未知 notificationType (Delivery) は 200 で無視', function (): void {
    $payload = SnsTestData::notification((string) json_encode(['notificationType' => 'Delivery']));

    postSnsCtl($payload)->assertOk();
    expect(EmailSuppression::query()->count())->toBe(0);
});

test('SubscriptionConfirmation は SDK confirmSubscription を呼ぶ (SubscribeURL 直 GET しない)', function (): void {
    $mock = Mockery::mock(SnsClient::class);
    $mock->shouldReceive('confirmSubscription')
        ->once()
        ->withArgs(fn (array $args): bool => $args['TopicArn'] === SnsTestData::TOPIC_ARN && isset($args['Token']));
    app()->instance(SnsClient::class, $mock);

    postSnsCtl(SnsTestData::subscriptionConfirmation())->assertOk();
});

test('middleware bypass で sns_message attribute 欠落なら 400', function (): void {
    // 署名 middleware を付けないテスト route で Controller 単体を叩く。
    Route::post('/_test/ses-no-mw', SesNotificationController::class);

    test()->call('POST', '/_test/ses-no-mw', [], [], [], [], (string) json_encode(
        SnsTestData::notification(SnsTestData::bounceMessageJson('Permanent', ['x@example.com'])),
    ))->assertStatus(400);
});
