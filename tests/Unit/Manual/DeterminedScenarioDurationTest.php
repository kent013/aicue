<?php

declare(strict_types=1);

use App\Services\Manual\DeterminedScenarioDuration;
use Webmozart\Assert\InvalidArgumentException;

/*
 * シナリオ全体の確定尺の集計 (「いま尺が確定している分」の合計であって完成動画の見込み尺ではない)。
 * 未確定を 0 ms として足さない。1 本も確定していなければ合計は null。
 */

test('空配列は合計 null / 未確定 0 件', function (): void {
    $result = DeterminedScenarioDuration::fromCutDurations([]);

    expect($result->totalDurationMs)->toBeNull();
    expect($result->undeterminedCutCount)->toBe(0);
});

test('全件 null は合計 null / 未確定は件数ぶん', function (): void {
    $result = DeterminedScenarioDuration::fromCutDurations([null, null, null]);

    expect($result->totalDurationMs)->toBeNull();
    expect($result->undeterminedCutCount)->toBe(3);
});

test('混在は確定分だけ合計し未確定を数える', function (): void {
    $result = DeterminedScenarioDuration::fromCutDurations([1_000, null, 2_500]);

    expect($result->totalDurationMs)->toBe(3_500);
    expect($result->undeterminedCutCount)->toBe(1);
});

test('全件確定は合計 + 未確定 0 件', function (): void {
    $result = DeterminedScenarioDuration::fromCutDurations([1_000, 2_000]);

    expect($result->totalDurationMs)->toBe(3_000);
    expect($result->undeterminedCutCount)->toBe(0);
});

test('確定分が 0 ms だけなら合計は 0 (null にしない)', function (): void {
    $result = DeterminedScenarioDuration::fromCutDurations([0]);

    expect($result->totalDurationMs)->toBe(0);
    expect($result->undeterminedCutCount)->toBe(0);
});

test('負値混入は例外 (カット尺は負値になり得ない)', function (): void {
    DeterminedScenarioDuration::fromCutDurations([-1]);
})->throws(InvalidArgumentException::class);

test('桁溢れ境界 (PHP_INT_MAX の次の加算) は例外', function (): void {
    DeterminedScenarioDuration::fromCutDurations([PHP_INT_MAX, 1]);
})->throws(InvalidArgumentException::class);

test('PHP_INT_MAX 単体は許可される', function (): void {
    $result = DeterminedScenarioDuration::fromCutDurations([PHP_INT_MAX]);

    expect($result->totalDurationMs)->toBe(PHP_INT_MAX);
});
