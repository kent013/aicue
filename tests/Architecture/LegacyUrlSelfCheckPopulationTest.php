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
 * ## 数え方 (**抽出方式からだけ**独立させる)
 *
 * **全文を 1 行ずつ**見て、根の一致と撤去 route 名の**出現数**を数える。
 * 本体の抽出方式 (コメントを外す / 入口の引数を外す / route 定義の URI を外す) は通さない —
 * ここで数えたいのは「見本が検出語を何個持っているか」であって、本体が何件検出するかではない。
 *
 * ★**独立しているのは抽出方式だけである** (誇張しない)。根の位置と境界の判定は本体の
 *   `matchesIn()` を共有しているので、**その判定の欠陥からは独立していない**。
 *   根の位置判定そのものの検出力は `LegacyOrganizationlessUrlAbsenceTest` の
 *   種別ごとの正例・負例が担う。
 *
 * ## この gate 自身は旧 URL 文字列を持たない
 *
 * 持つのは**パスと件数**だけである (旧 URL を書くと、この gate 自身が検出対象になる)。
 */

/** 自己検査専用のファイル名 (完全一致)。 */
const LEGACY_URL_SELF_CHECK_FILES = [
    'tests/Architecture/fixtures/legacy-url/allowed-paths.md',
    'tests/Architecture/fixtures/legacy-url/legacy-blade-source.txt',
    'tests/Architecture/fixtures/legacy-url/legacy-data-source.txt',
    'tests/Architecture/fixtures/legacy-url/legacy-paths.md',
    'tests/Architecture/fixtures/legacy-url/legacy-php-source.txt',
    'tests/Architecture/fixtures/legacy-url/legacy-script-source.txt',
    'tests/Architecture/fixtures/legacy-url/legacy-shadowed-builder.txt',
];

/** 各見本が持つ検出語の件数 (完全一致)。 */
const LEGACY_URL_SELF_CHECK_COUNTS = [
    'tests/Architecture/fixtures/legacy-url/allowed-paths.md' => 0,
    'tests/Architecture/fixtures/legacy-url/legacy-blade-source.txt' => 1,
    'tests/Architecture/fixtures/legacy-url/legacy-data-source.txt' => 3,
    'tests/Architecture/fixtures/legacy-url/legacy-paths.md' => 13,
    'tests/Architecture/fixtures/legacy-url/legacy-php-source.txt' => 5,
    'tests/Architecture/fixtures/legacy-url/legacy-script-source.txt' => 9,
    'tests/Architecture/fixtures/legacy-url/legacy-shadowed-builder.txt' => 1,
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
            // ★出現数で数える (1 行 1 件にすると同じ行へ 2 個目を足しても動かない)
            $hits += substr_count($line, LegacyUrlScanner::removedRouteName());
        }
        $counts[$file->relative] = $hits;
    }
    ksort($counts);

    $expected = LEGACY_URL_SELF_CHECK_COUNTS;
    ksort($expected);

    expect($counts)->toBe($expected);
});
