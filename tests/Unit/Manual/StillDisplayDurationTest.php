<?php

declare(strict_types=1);

use App\Models\Cut;
use App\Services\Manual\StillDisplayDuration;

/*
 * 静止画カットの表示秒を決める式 (唯一の所在)。
 *
 * 以前は RenderPipeline が manual.preview_placeholder_seconds (= 採用テイク欠落 cut の
 * プレースホルダ尺) を流用していた。別概念なので撤去済みで、ここではその 2 値が
 * 独立していることも固定する。
 */

test('cuts.static_display_seconds が指定されていればその値', function (): void {
    $cut = Cut::factory()->make(['static_display_seconds' => 12]);

    expect(StillDisplayDuration::secondsFor($cut))->toBe(12);
});

test('未指定なら manual.default_still_display_seconds', function (): void {
    config()->set('manual.default_still_display_seconds', 7);
    $cut = Cut::factory()->make(['static_display_seconds' => null]);

    expect(StillDisplayDuration::secondsFor($cut))->toBe(7);
});

test('preview_placeholder_seconds を変えても静止画尺は変わらない (流用の撤去)', function (): void {
    config()->set('manual.default_still_display_seconds', 5);
    config()->set('manual.preview_placeholder_seconds', 41);
    $cut = Cut::factory()->make(['static_display_seconds' => null]);

    expect(StillDisplayDuration::secondsFor($cut))->toBe(5);
});

test('既定値は編集画面の入力範囲 (1〜60 秒) の内側にある', function (): void {
    // 既定値が範囲外だと「編集画面では入力できない尺」が既定になり、
    // 編集で直そうとしても同じ値へ戻せなくなる。
    $default = config()->integer('manual.default_still_display_seconds');

    expect($default)->toBeGreaterThanOrEqual(1);
    expect($default)->toBeLessThanOrEqual(60);
});
