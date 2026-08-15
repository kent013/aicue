<?php

declare(strict_types=1);

use App\Support\Billing\PaymentGracePolicy;
use Carbon\CarbonImmutable;
use Webmozart\Assert\InvalidArgumentException;

/*
 * PaymentGracePolicy — 支払い失敗の猶予期限を決める唯一の正本。
 *
 * 猶予日数は config('billing.payment_grace_days') だけを読み、境界 (期限ちょうど) は
 * 「切れていない」側に倒す (利用者に有利な側)。
 */

function gracePolicy(): PaymentGracePolicy
{
    return app(PaymentGracePolicy::class);
}

test('graceDays は config を読む (再計算しない)', function (): void {
    config()->set('billing.payment_grace_days', 7);

    expect(gracePolicy()->graceDays())->toBe(7);
});

test('負の猶予日数は設定不備として例外で落とす', function (): void {
    config()->set('billing.payment_grace_days', -1);

    gracePolicy()->graceDays();
})->throws(InvalidArgumentException::class);

test('expiresAt は起点 + 猶予日数', function (): void {
    config()->set('billing.payment_grace_days', 14);
    $since = CarbonImmutable::parse('2026-08-01 09:00:00');

    expect(gracePolicy()->expiresAt($since)->toDateTimeString())->toBe('2026-08-15 09:00:00');
});

test('猶予中 (起点当日 / 13 日後) は切れていない', function (int $days): void {
    config()->set('billing.payment_grace_days', 14);
    $since = CarbonImmutable::parse('2026-08-01 09:00:00');

    expect(gracePolicy()->hasExpired($since, $since->addDays($days)))->toBeFalse();
})->with([0, 13]);

test('期限ちょうどは切れていない扱い (境界は利用者に有利な側へ倒す)', function (): void {
    config()->set('billing.payment_grace_days', 14);
    $since = CarbonImmutable::parse('2026-08-01 09:00:00');

    expect(gracePolicy()->hasExpired($since, $since->addDays(14)))->toBeFalse();
});

test('期限の 1 秒後から切れている', function (): void {
    config()->set('billing.payment_grace_days', 14);
    $since = CarbonImmutable::parse('2026-08-01 09:00:00');

    expect(gracePolicy()->hasExpired($since, $since->addDays(14)->addSecond()))->toBeTrue();
});

test('猶予 0 日の設定では起点の 1 秒後に切れる (即時遮断できる)', function (): void {
    config()->set('billing.payment_grace_days', 0);
    $since = CarbonImmutable::parse('2026-08-01 09:00:00');

    expect(gracePolicy()->hasExpired($since, $since))->toBeFalse()
        ->and(gracePolicy()->hasExpired($since, $since->addSecond()))->toBeTrue();
});
