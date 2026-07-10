<?php

declare(strict_types=1);

use App\DataTransferObjects\Mail\SesBounceNotificationDto;
use App\Models\EmailSuppression;
use App\Services\Mail\EmailSuppressionService;
use Carbon\CarbonImmutable;
use Tests\Support\SnsTestData;

function suppressionService(): EmailSuppressionService
{
    return app(EmailSuppressionService::class);
}

/**
 * @param  list<string>  $emails
 * @return array<string, mixed>
 */
function bounceMessage(string $bounceType, array $emails): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(SnsTestData::bounceMessageJson($bounceType, $emails), true);

    return $decoded;
}

test('recordBounce: Permanent を記録、isSuppressed は normalize 経由で一致', function (): void {
    $dto = SesBounceNotificationDto::fromMessage(bounceMessage('Permanent', ['User@Example.com']));

    suppressionService()->recordBounce($dto, 'sns-1');

    expect(suppressionService()->isSuppressed('  user@example.COM '))->toBeTrue() // 大小・空白揺れ
        ->and(suppressionService()->isSuppressed('other@example.com'))->toBeFalse();
});

test('recordBounce: Transient は記録しない', function (): void {
    $dto = SesBounceNotificationDto::fromMessage(bounceMessage('Transient', ['t@example.com']));

    suppressionService()->recordBounce($dto, 'sns-1');

    expect(EmailSuppression::query()->count())->toBe(0);
});

test('2 回目 upsert で updated_at/suppressed_at/provider_message_id を更新し created_at は不変', function (): void {
    $t1 = CarbonImmutable::parse('2026-07-01 00:00:00');
    CarbonImmutable::setTestNow($t1);
    $dto1 = SesBounceNotificationDto::fromMessage(bounceMessage('Permanent', ['dup@example.com']));
    suppressionService()->recordBounce($dto1, 'msg-1');

    $createdAt = EmailSuppression::query()->sole()->created_at;

    $t2 = CarbonImmutable::parse('2026-07-02 12:00:00');
    CarbonImmutable::setTestNow($t2);
    $dto2 = SesBounceNotificationDto::fromMessage(bounceMessage('Permanent', ['dup@example.com']));
    suppressionService()->recordBounce($dto2, 'msg-2');

    $row = EmailSuppression::query()->sole();
    expect(EmailSuppression::query()->count())->toBe(1)
        ->and($row->provider_message_id)->toBe('msg-2')
        ->and($row->created_at?->equalTo($createdAt))->toBeTrue()
        ->and($row->updated_at?->greaterThan($createdAt))->toBeTrue();

    CarbonImmutable::setTestNow();
});
