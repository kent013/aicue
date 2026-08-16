<?php

declare(strict_types=1);

use App\Enums\Manual\ManualProgress;
use App\Enums\Manual\VideoManualStatus;

/*
 * T197: 制作状態 5 値 → 一覧の状態 3 値の写像 (ManualProgress) の正本を固定する。
 *
 * DB を使わない純粋 enum テストなので Unit レーンに置く。
 * Inertia payload / 絞り込み挙動は Feature (ProjectShowManualsTest) が持つ。
 */

test('制作状態 5 値が一覧の状態 3 値へ写る (写像表)', function (): void {
    expect(ManualProgress::forStatus(VideoManualStatus::Draft))->toBe(ManualProgress::NotStarted)
        ->and(ManualProgress::forStatus(VideoManualStatus::Analyzing))->toBe(ManualProgress::InProgress)
        ->and(ManualProgress::forStatus(VideoManualStatus::Ready))->toBe(ManualProgress::InProgress)
        ->and(ManualProgress::forStatus(VideoManualStatus::Rendering))->toBe(ManualProgress::InProgress)
        ->and(ManualProgress::forStatus(VideoManualStatus::Published))->toBe(ManualProgress::Completed);
});

test('逆写像は漏れなく排他である (和 = 全 status / 重複なし)', function (): void {
    $union = [];
    foreach (ManualProgress::cases() as $progress) {
        foreach ($progress->statuses() as $status) {
            $union[] = $status->value;
        }
    }
    sort($union);
    $all = array_map(static fn (VideoManualStatus $status): string => $status->value, VideoManualStatus::cases());
    sort($all);

    expect($union)->toBe($all)                                  // 漏れなし
        ->and(count($union))->toBe(count(array_unique($union))); // 排他
});

test('statusValues() は statuses() の DB 値列と一致する', function (): void {
    foreach (ManualProgress::cases() as $progress) {
        expect($progress->statusValues())->toBe(
            array_map(static fn (VideoManualStatus $status): string => $status->value, $progress->statuses()),
        );
    }
});

test('一覧の状態は 3 値である (doc/04 の 3 値と件数一致)', function (): void {
    expect(ManualProgress::cases())->toHaveCount(3);
});
