<?php

declare(strict_types=1);

use App\Security\RecentAuthWindow;

test('fresh: timeout 内の int timestamp は true', function (): void {
    expect(RecentAuthWindow::isFresh(time(), 900))->toBeTrue();
    expect(RecentAuthWindow::isFresh(time() - 100, 900))->toBeTrue();
});

test('stale: timeout 超過は false', function (): void {
    expect(RecentAuthWindow::isFresh(time() - 901, 900))->toBeFalse();
});

test('境界: ちょうど timeout 秒前は fresh (<=)', function (): void {
    expect(RecentAuthWindow::isFresh(time() - 900, 900))->toBeTrue();
});

test('未来 timestamp は fresh 扱いしない', function (): void {
    expect(RecentAuthWindow::isFresh(time() + 50, 900))->toBeFalse();
});

test('非 int (string / null / Carbon) は false', function (): void {
    expect(RecentAuthWindow::isFresh(null, 900))->toBeFalse();
    expect(RecentAuthWindow::isFresh((string) time(), 900))->toBeFalse();
    expect(RecentAuthWindow::isFresh(now(), 900))->toBeFalse();
});

test('timeout <= 0 は常に false (同秒内 true を避ける)', function (): void {
    expect(RecentAuthWindow::isFresh(time(), 0))->toBeFalse();
    expect(RecentAuthWindow::isFresh(time(), -1))->toBeFalse();
});

test('configuredTimeout は config を int 解決し、非 numeric は 900 fallback', function (): void {
    config(['auth.recent_auth_timeout' => 1200]);
    expect(RecentAuthWindow::configuredTimeout())->toBe(1200);

    config(['auth.recent_auth_timeout' => '600']);
    expect(RecentAuthWindow::configuredTimeout())->toBe(600);

    config(['auth.recent_auth_timeout' => 'not-a-number']);
    expect(RecentAuthWindow::configuredTimeout())->toBe(900);
});
