<?php

declare(strict_types=1);

use App\Support\FakeStorageGate;
use Illuminate\Contracts\Foundation\Application;

/*
 * FakeStorageGate (predicate SSOT): fail-secure 二軸 (flag + env allowlist) を固定する。
 */

/** environment()/runningUnitTests() を差し替えた Application 上で gate を評価する */
function evaluateGate(string $environment, bool $runningUnitTests, bool $flag): bool
{
    config()->set('testing.fake_storage', $flag);

    $app = Mockery::mock(Application::class);
    $app->shouldReceive('environment')->withNoArgs()->andReturn($environment);
    $app->shouldReceive('runningUnitTests')->withNoArgs()->andReturn($runningUnitTests);

    return (new FakeStorageGate($app))->enabled();
}

test('flag off なら常に false (完全 no-op)', function (): void {
    expect(evaluateGate('bughunt.local', true, false))->toBeFalse();
    expect(evaluateGate('testing', true, false))->toBeFalse();
});

test('bughunt.local + flag → true', function (): void {
    expect(evaluateGate('bughunt.local', false, true))->toBeTrue();
});

test('testing + runningUnitTests + flag → true', function (): void {
    expect(evaluateGate('testing', true, true))->toBeTrue();
});

test('testing だが runningUnitTests=false + flag → false (HTTP 実行時の誤通過を封じる)', function (): void {
    expect(evaluateGate('testing', false, true))->toBeFalse();
});

test('local + flag → false (allowlist 外)', function (): void {
    expect(evaluateGate('local', true, true))->toBeFalse();
});

test('production + flag → false (allowlist 外)', function (): void {
    expect(evaluateGate('production', true, true))->toBeFalse();
});
