<?php

declare(strict_types=1);

use Tests\Support\LegacyUrl\LegacyUrlScanner;
use Tests\Support\LegacyUrl\LegacyUrlScanRoots;

/*
 * 「自己検査専用」分類のファイル名と検出語の一致件数を**完全一致で pin** する
 * (家系裁定 AG-037 / 施策 10)。
 *
 * ## なぜ別の gate なのか
 *
 * 旧 URL の残存検査は、検出語をわざと持つ見本 (負例 fixture) を母集団から外さざるを得ない。
 * 外しっぱなしにすると**そこへ書けば何でも通る**抜け道になるので、
 * 「どのファイルが、何件の検出語を持つか」を別の検査が固定する。
 * 増えても減っても赤になるので、見本を黙って増やすことも、
 * 実装のついでに旧 URL を見本ファイルへ退避することもできない。
 *
 * ## この gate 自身は旧 URL 文字列を持たない
 *
 * 持つのは**パスと件数**だけである (旧 URL を書くと、この gate 自身が検出対象になる)。
 */

/** 自己検査専用のファイル名 (完全一致)。 */
const LEGACY_URL_SELF_CHECK_FILES = [
    'tests/Architecture/fixtures/legacy-url/allowed-paths.md',
    'tests/Architecture/fixtures/legacy-url/legacy-paths.md',
    'tests/Architecture/fixtures/legacy-url/legacy-php-source.txt',
    'tests/Architecture/fixtures/legacy-url/legacy-script-source.txt',
];

/**
 * 各見本が持つ検出語の件数 (完全一致)。
 *
 * ★件数は**全文走査**で数える (見本の中身がどの言語かにかかわらず同じ数え方をする)。
 *   ソースの見本はコメントにも検出語を置いてあるので、リテラルだけを見る本体の数え方とは
 *   一致しない。ここで数えたいのは「見本が検出語を何個持っているか」である。
 */
const LEGACY_URL_SELF_CHECK_COUNTS = [
    'tests/Architecture/fixtures/legacy-url/allowed-paths.md' => 0,
    'tests/Architecture/fixtures/legacy-url/legacy-paths.md' => 12,
    'tests/Architecture/fixtures/legacy-url/legacy-php-source.txt' => 5,
    'tests/Architecture/fixtures/legacy-url/legacy-script-source.txt' => 5,
];

test('自己検査専用の分類は目録と完全一致する', function (): void {
    $classified = array_map(
        static fn (object $file): string => (string) $file->relative,
        LegacyUrlScanRoots::population()->selfCheckOnly,
    );
    sort($classified);

    expect($classified)->toBe(LEGACY_URL_SELF_CHECK_FILES);
});

test('自己検査専用の見本が持つ検出語の件数は完全一致 (増減のどちらでも赤)', function (): void {
    $counts = [];
    foreach (LegacyUrlScanRoots::population()->selfCheckOnly as $file) {
        $hits = 0;
        foreach (explode("\n", $file->contents) as $line) {
            $hits += count(LegacyUrlScanner::matchesIn($line));
            if (str_contains($line, LegacyUrlScanner::removedRouteName())) {
                $hits++;
            }
        }
        $counts[$file->relative] = $hits;
    }
    ksort($counts);

    $expected = LEGACY_URL_SELF_CHECK_COUNTS;
    ksort($expected);

    expect($counts)->toBe($expected);
});
