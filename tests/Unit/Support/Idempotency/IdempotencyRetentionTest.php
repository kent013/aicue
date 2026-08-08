<?php

declare(strict_types=1);

use App\Support\Idempotency\IdempotencyRetention;
use Carbon\CarbonImmutable;

/*
 * 保持期間の SoT (config/idempotency.php) への型付き入口。
 * config の型崩れは Assert で fail-fast する (黙って既定値へ倒れない)。
 */

test('hours() は config の値を返す', function (): void {
    config(['idempotency.retention_hours' => 3]);

    expect(IdempotencyRetention::hours())->toBe(3);
});

test('hours() は非 int の config で失敗する', function (): void {
    config(['idempotency.retention_hours' => '24']);

    IdempotencyRetention::hours();
})->throws(InvalidArgumentException::class);

test('hours() は 0 以下の config で失敗する', function (): void {
    config(['idempotency.retention_hours' => 0]);

    IdempotencyRetention::hours();
})->throws(InvalidArgumentException::class);

test('expiresAt() は基準時刻 + hours を返す', function (): void {
    config(['idempotency.retention_hours' => 5]);
    $now = CarbonImmutable::parse('2026-08-09 10:00:00');

    expect(IdempotencyRetention::expiresAt($now)->toDateTimeString())
        ->toBe('2026-08-09 15:00:00');
});

test('既定の保持期間は 24 時間', function (): void {
    expect(IdempotencyRetention::hours())->toBe(24);
});
