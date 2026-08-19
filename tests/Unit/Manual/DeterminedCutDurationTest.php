<?php

declare(strict_types=1);

use App\Enums\Manual\MaterialType;
use App\Models\Cut;
use App\Models\Take;
use App\Services\Manual\DeterminedCutDuration;

/*
 * カット 1 本の確定尺の式 (唯一の所在)。
 *
 * 決まり方は 2 通り (静止画は計画だけで決まる / 動画は採用済み ready テイクの duration_ms)。
 * それ以外は null (未確定。既定値で埋めない)。
 */

test('テイク無し + still カットは static_display_seconds × 1000', function (): void {
    $cut = Cut::factory()->make([
        'material_type' => MaterialType::Still->value,
        'static_display_seconds' => 8,
    ]);

    expect(DeterminedCutDuration::milliseconds($cut, null))->toBe(8_000);
});

test('テイク無し + still カットで static_display_seconds 未指定なら既定値を使う', function (): void {
    config()->set('manual.default_still_display_seconds', 6);
    $cut = Cut::factory()->make([
        'material_type' => MaterialType::Still->value,
        'static_display_seconds' => null,
    ]);

    expect(DeterminedCutDuration::milliseconds($cut, null))->toBe(6_000);
});

test('テイク無し + video カットは未確定 (null)', function (): void {
    $cut = Cut::factory()->make(['material_type' => MaterialType::Video->value]);

    expect(DeterminedCutDuration::milliseconds($cut, null))->toBeNull();
});

test('テイク無し + material_type 未指定 (NULL) は未確定 (null)', function (): void {
    $cut = Cut::factory()->make(['material_type' => null]);

    expect(DeterminedCutDuration::milliseconds($cut, null))->toBeNull();
});

test('テイクあり + 実効 still (cut=video / take=still の組み合わせを含む) は静止表示秒 × 1000', function (): void {
    $cut = Cut::factory()->make([
        'material_type' => MaterialType::Video->value,
        'static_display_seconds' => 4,
    ]);
    $take = Take::factory()->make(['material_type' => MaterialType::Still->value]);

    expect(DeterminedCutDuration::milliseconds($cut, $take))->toBe(4_000);
});

test('テイクあり + 実効 video + duration_ms 非 NULL はその値', function (): void {
    $cut = Cut::factory()->make(['material_type' => MaterialType::Video->value]);
    $take = Take::factory()->make([
        'material_type' => MaterialType::Video->value,
        'duration_ms' => 12_345,
    ]);

    expect(DeterminedCutDuration::milliseconds($cut, $take))->toBe(12_345);
});

test('テイクあり + 実効 video + duration_ms NULL は未確定 (既定値で埋めない)', function (): void {
    $cut = Cut::factory()->make(['material_type' => MaterialType::Video->value]);
    $take = Take::factory()->make([
        'material_type' => MaterialType::Video->value,
        'duration_ms' => null,
    ]);

    expect(DeterminedCutDuration::milliseconds($cut, $take))->toBeNull();
});
