<?php

declare(strict_types=1);

use App\Models\Cut;
use App\Models\VideoManual;
use App\Services\Manual\CutSequencer;

/*
 * カットの表示順 (step を sort_order 順 → 直後に配下 point を sort_order 順) と
 * 表示ラベル (手順N / 急所N-M) の導出 (施策 5)。
 */

test('表示順: step の sort_order 順 → 直後に配下 point の sort_order 順', function (): void {
    $manual = VideoManual::factory()->create();
    $step2 = Cut::factory()->forManual($manual)->withSortOrder(1)->create();
    $step1 = Cut::factory()->forManual($manual)->withSortOrder(0)->create();
    $point12 = Cut::factory()->asPointOf($step1)->withSortOrder(1)->create();
    $point11 = Cut::factory()->asPointOf($step1)->withSortOrder(0)->create();
    $point21 = Cut::factory()->asPointOf($step2)->withSortOrder(0)->create();

    $ordered = CutSequencer::orderedWithLabels($manual);

    expect(array_map(fn ($entry): int => $entry->cut->id, $ordered))->toBe([
        $step1->id, $point11->id, $point12->id, $step2->id, $point21->id,
    ]);
});

test('ラベル派生: 手順N / 急所N-M (step 番号は表示順の 1 始まり)', function (): void {
    $manual = VideoManual::factory()->create();
    $step1 = Cut::factory()->forManual($manual)->withSortOrder(0)->create();
    $step2 = Cut::factory()->forManual($manual)->withSortOrder(1)->create();
    Cut::factory()->asPointOf($step1)->withSortOrder(0)->create();
    Cut::factory()->asPointOf($step1)->withSortOrder(1)->create();
    Cut::factory()->asPointOf($step2)->withSortOrder(0)->create();

    $ordered = CutSequencer::orderedWithLabels($manual);

    expect(array_map(fn ($entry): string => $entry->label, $ordered))->toBe([
        '手順1', '急所1-1', '急所1-2', '手順2', '急所2-1',
    ]);
});

test('adoptedTake が eager load される (トリガー tx 内の N+1 回避)', function (): void {
    $manual = VideoManual::factory()->create();
    Cut::factory()->forManual($manual)->create();

    $ordered = CutSequencer::orderedWithLabels($manual);

    expect($ordered[0]->cut->relationLoaded('adoptedTake'))->toBeTrue();
});
