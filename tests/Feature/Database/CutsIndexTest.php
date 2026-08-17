<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/*
 * T202: cuts.video_manual_id の索引 (migration 2026_08_17_000000)。
 *
 * ★PostgreSQL は FK 列に索引を自動生成しない (MySQL/InnoDB とは異なる)。
 *   元の create migration は foreignId()->constrained() だけで索引を宣言しておらず、
 *   cuts を video_manual_id で引く経路 (カット本文検索の相関 EXISTS /
 *   撮影 PWA 一覧の withCount / シナリオ取得 / 削除時の cascade) が逐次走査になっていた。
 *
 * ★名前まで固定する理由: 「先頭列に video_manual_id を持つ索引が 1 本以上」だけだと
 *   **環境固有の手動索引が 1 本あるだけで緑になる**。migration が作った索引が実在することを
 *   見たいので Laravel 既定名で固定する。索引が黙って消えたら赤くなる。
 */

test('cuts に cuts_video_manual_id_index が存在し video_manual_id 単独である', function (): void {
    $indexes = collect(Schema::getIndexes('cuts'))
        ->keyBy(fn (array $index): string => (string) $index['name']);

    expect($indexes)->toHaveKey('cuts_video_manual_id_index');
    expect($indexes['cuts_video_manual_id_index']['columns'])->toBe(['video_manual_id']);
    // 一意ではない (1 manual に複数 cut がぶら下がる)
    expect($indexes['cuts_video_manual_id_index']['unique'])->toBeFalse();
});
