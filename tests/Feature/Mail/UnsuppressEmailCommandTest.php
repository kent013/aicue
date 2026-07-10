<?php

declare(strict_types=1);

use App\Models\EmailSuppression;
use App\Services\Mail\EmailSuppressionService;

test('mail:unsuppress で抑止解除し、再び送信可能になる', function (): void {
    EmailSuppression::factory()->forEmail('blocked@example.com')->create();
    expect(app(EmailSuppressionService::class)->isSuppressed('blocked@example.com'))->toBeTrue();

    $this->artisan('mail:unsuppress', ['email' => 'Blocked@Example.com']) // normalize 経由で一致
        ->expectsOutputToContain('解除')
        ->assertSuccessful();

    expect(app(EmailSuppressionService::class)->isSuppressed('blocked@example.com'))->toBeFalse()
        ->and(EmailSuppression::query()->count())->toBe(0);
});

test('mail:unsuppress: 該当なしでも成功し警告を出す', function (): void {
    $this->artisan('mail:unsuppress', ['email' => 'none@example.com'])
        ->expectsOutputToContain('該当アドレスはありません')
        ->assertSuccessful();
});
