<?php

declare(strict_types=1);

test('N-1 追跡下の内容とパス名に旧名の残留が無く、記録は申告と厳密に一致する', function (): void {
    $violations = [];

    foreach (bughuntNamingTrackedFiles() as $relative) {
        $found = bughuntNamingViolationsIn(
            $relative,
            bughuntNamingSourceOf($relative),
            BUGHUNT_NAMING_DECLARED_OCCURRENCES
        );

        foreach ($found as $violation) {
            $violations[] = $violation;
        }
    }

    expect($violations)->toBe([]);
});

test('N-2 fail-closed: 走査が空振りしていない (母集団の下限・番兵・家系名の正の対照)', function (): void {
    $files = bughuntNamingTrackedFiles();

    expect(count($files))->toBeGreaterThanOrEqual(
        BUGHUNT_NAMING_MINIMUM_TRACKED_FILES,
        '追跡下ファイルの列挙が少なすぎます (git ls-files が期待どおり動いていない可能性)',
    );

    foreach (BUGHUNT_NAMING_SENTINEL_PATHS as $sentinel) {
        expect($files)->toContain($sentinel);
    }

    // 正の対照: 「旧名 0 件」が走査の故障による偽の緑でないことを、在るはずの家系名で確かめる。
    // 置き換え先が実在しかつ git 追跡下にあること (正典の要求) もここで満たす。
    foreach (BUGHUNT_NAMING_CANONICAL_SENTINELS as $canonical => $path) {
        expect($files)->toContain($path);

        expect(bughuntNamingOffsetsOf(bughuntNamingSourceOf($path), $canonical))->not->toBe(
            [],
            "家系名 {$canonical} が {$path} の内容で見つからない — 走査条件の陳腐化を疑う",
        );
        expect(str_contains($path, $canonical))->toBeTrue(
            "家系名 {$canonical} がパス名 {$path} で見つからない — 番兵の陳腐化を疑う",
        );
    }
});

test('N-3 申告台帳と除外の構造が意図どおり (台帳から実物への逆方向も見る)', function (): void {
    // 丸ごと除外の定義は 2 つちょうど (接頭辞 devnotes/ が 1 件 + 本テスト自身が 1 件)。
    expect(BUGHUNT_NAMING_EXCLUDED_PREFIXES)->toBe(['devnotes/'])
        ->and(BUGHUNT_NAMING_SELF_PATH)->toBe('tests/Architecture/BughuntNamingResidualTest.php');

    // 退役した名前は 2 つで、家系名と 1:1 に対応する。
    expect(BUGHUNT_RETIRED_NAMES)->toBe([
        'BughuntBillingSeeder' => 'BughuntStripeSyncSeeder',
        'FakeExternalsServiceProvider' => 'BughuntFakesServiceProvider',
    ]);

    // 置き換え先には 1 つずつ番兵がある (写像の値と番兵のキーが完全一致)。
    expect(array_keys(BUGHUNT_NAMING_CANONICAL_SENTINELS))->toBe(array_values(BUGHUNT_RETIRED_NAMES));

    // 申告台帳のキーは記録 1 冊ちょうど (docs/TODO.md は旧名 0 件なので登録を持たない)。
    expect(array_keys(BUGHUNT_NAMING_DECLARED_OCCURRENCES))->toBe(['docs/TODO-closed.md']);

    $files = bughuntNamingTrackedFiles();

    foreach (BUGHUNT_NAMING_DECLARED_OCCURRENCES as $path => $perRetiredName) {
        // ★`toContain` は可変長の needle を取るので**メッセージを渡さない** (第 2 引数は
        //   もう 1 つの needle として解釈される)。理由文を添える判定は真偽値へ落として書く。
        expect(in_array($path, $files, true))->toBeTrue(
            "申告した記録が追跡下に無い: {$path} — ファイルごと消えたなら申告も外すこと",
        );

        $content = bughuntNamingSourceOf($path);

        expect($perRetiredName)->not->toBe([], "旧名の項目を 1 つも持たない登録: {$path} — 行ごと外すこと");

        foreach ($perRetiredName as $retired => $entries) {
            expect(BUGHUNT_RETIRED_NAMES)->toHaveKey($retired);
            expect($entries)->not->toBe([], "申告 0 件の登録は意味を持たない: {$path} / {$retired} — 行ごと外すこと");

            foreach ($entries as $entry) {
                expect(mb_strlen($entry['reason']))->toBeGreaterThanOrEqual(
                    BUGHUNT_NAMING_MINIMUM_REASON_LENGTH,
                    "申告の理由が短すぎる: {$path} / {$retired}",
                );
                expect(bughuntNamingOffsetsOf($entry['needle'], $retired))->toHaveCount(
                    1,
                    "申告の周辺文字列が旧名をちょうど 1 回含まない: {$path} / {$retired} — {$entry['needle']}",
                );
                expect(bughuntNamingOffsetsOf($content, $entry['needle']))->toHaveCount(
                    1,
                    "申告の周辺文字列が実物にちょうど 1 回現れない: {$path} / {$retired} — {$entry['needle']}",
                );
            }

            // 件数は申告の本数から導く (別に pin を持たない)。
            expect(count($entries))->toBe(
                count(bughuntNamingOffsetsOf($content, $retired)),
                "申告の本数が実出現数と合わない: {$path} / {$retired}",
            );
        }
    }
});

test('N-4 負のコントロール: 同じ述語が検出する / しないの境界', function (): void {
    $retired = array_keys(BUGHUNT_RETIRED_NAMES);
    $canonical = array_values(BUGHUNT_RETIRED_NAMES);
    $seeder = $retired[0];
    $provider = $retired[1];

    // 合成の申告台帳と合成の本文 (実ファイルの内容に依存させない)。
    $reason = '負のコントロール用の合成理由 (30 文字以上であることを N-3 と同じ規則で満たす)';
    $ledger = [
        'docs/record.md' => [
            $seeder => [
                ['needle' => "T001 で {$seeder} を作った", 'reason' => $reason],
            ],
        ],
    ];
    $body = "行 1: T001 で {$seeder} を作った\n行 2: ふつうの文\n";

    // (a) 申告どおりなら緑
    expect(bughuntNamingViolationsIn('docs/record.md', $body, $ledger))->toBe([]);

    // (b) ★v1 の主眼: 件数は同じだが出現箇所をすり替えた入力は赤になる
    //     (申告の周辺文字列が消え、別の位置に未申告の出現が生まれる = 2 件)
    $swapped = "行 1: ふつうの文\n行 2: T002 で {$seeder} を消した\n";
    $swappedViolations = bughuntNamingViolationsIn('docs/record.md', $swapped, $ledger);
    expect($swappedViolations)->toHaveCount(2);
    expect(implode("\n", $swappedViolations))->toContain('申告を足す・移す・外す');

    // (c) 申告外の出現が増えたら赤
    expect(bughuntNamingViolationsIn('docs/record.md', $body."後から {$seeder}\n", $ledger))->toHaveCount(1);

    // (d) 申告があるのに実物から消えたら赤
    expect(bughuntNamingViolationsIn('docs/record.md', "行 1: ふつうの文\n", $ledger))->toHaveCount(1);

    // (e) 申告の無いファイルの内容に旧名があれば赤 (deny-by-default)
    expect(bughuntNamingViolationsIn('app/Foo.php', "class Foo extends {$seeder} {}", $ledger))->toHaveCount(1);

    // (f) ★パス名に旧名を持つファイルは、内容が空でも赤
    expect(bughuntNamingViolationsIn("app/Providers/{$provider}.php", '', $ledger))->toHaveCount(1);

    // (g) 置き換え先 (家系名) は内容もパス名も誤検出しない
    expect(bughuntNamingViolationsIn("database/seeders/{$canonical[0]}.php", "class {$canonical[0]} {}", $ledger))->toBe([]);
    expect(bughuntNamingViolationsIn("app/Providers/{$canonical[1]}.php", "class {$canonical[1]} {}", $ledger))->toBe([]);

    // (h) 丸ごと除外した 2 つは沈黙する (保証の穴の実測)
    expect(bughuntNamingViolationsIn('devnotes/x/y.md', "{$seeder} {$provider}", $ledger))->toBe([]);
    expect(bughuntNamingViolationsIn(BUGHUNT_NAMING_SELF_PATH, "{$seeder} {$provider}", $ledger))->toBe([]);

    // (i) 周辺文字列が 2 回現れる (出現を特定できない) 場合も赤
    $twice = "行 1: T001 で {$seeder} を作った\n行 2: T001 で {$seeder} を作った\n";
    expect(bughuntNamingViolationsIn('docs/record.md', $twice, $ledger))->toHaveCount(2);

    // (j) 同じ出現を二重に申告したら赤
    $duplicated = [
        'docs/record.md' => [
            $seeder => [
                ['needle' => "T001 で {$seeder} を作った", 'reason' => $reason],
                ['needle' => "T001 で {$seeder} を作った", 'reason' => $reason],
            ],
        ],
    ];
    $duplicateViolations = bughuntNamingViolationsIn('docs/record.md', $body, $duplicated);
    expect($duplicateViolations)->toHaveCount(1);
    expect(implode("\n", $duplicateViolations))->toContain('二重に指している');

    // (k) 周辺文字列が旧名を 2 回含む (出現を 1 つに絞れていない) 申告は赤
    $ambiguous = [
        'docs/record.md' => [
            $seeder => [
                ['needle' => "T001 で {$seeder} と {$seeder}", 'reason' => $reason],
            ],
        ],
    ];
    $ambiguousViolations = bughuntNamingViolationsIn('docs/record.md', "T001 で {$seeder} と {$seeder}\n", $ambiguous);
    // 2 件は別の情報である — (1) 申告そのものが不正 / (2) その申告を採用できなかった結果として
    // 実出現が未申告になる。両方出す**診断方針を契約として固定する**。将来「原因の申告エラーが
    // あれば派生を抑制する」方針へ変えるなら、それは診断方針の変更なので期待件数も同じ変更で直す。
    expect($ambiguousViolations)->toHaveCount(2);
    expect(implode("\n", $ambiguousViolations))->toContain('ちょうど 1 回含まない');

    // (l) ★件数は一致するが 2 つの申告が同じ出現を指し、別の 1 件が未申告になる入力。
    //     件数の比較だけなら緑になるため、**出現位置の集合一致でなければ捕まらない**。
    //     この 1 ケースが「位置集合まで強める価値」の実測である。
    $twoOccurrences = "行 1: T001 で {$seeder} を作った\n行 2: T002 で {$seeder} を消した\n";
    $sameSpotTwice = [
        'docs/record.md' => [
            $seeder => [
                ['needle' => "T001 で {$seeder}", 'reason' => $reason],
                ['needle' => "で {$seeder} を作った", 'reason' => $reason],
            ],
        ],
    ];
    // 申告 2 件・実出現 2 件 = 件数は一致する (前提の確認)。
    expect(count($sameSpotTwice['docs/record.md'][$seeder]))
        ->toBe(count(bughuntNamingOffsetsOf($twoOccurrences, $seeder)));

    $sameSpotViolations = bughuntNamingViolationsIn('docs/record.md', $twoOccurrences, $sameSpotTwice);
    expect($sameSpotViolations)->toHaveCount(2);
    expect(implode("\n", $sameSpotViolations))->toContain('申告外の出現がある');
    expect(implode("\n", $sameSpotViolations))->toContain('二重に指している');
});

test('N-5 旧名のクラスは存在せず、家系名のクラスが存在する', function (): void {
    expect(class_exists('Database\Seeders\BughuntBillingSeeder'))->toBeFalse()
        ->and(class_exists('App\Providers\FakeExternalsServiceProvider'))->toBeFalse()
        ->and(class_exists('Database\Seeders\BughuntStripeSyncSeeder'))->toBeTrue()
        ->and(class_exists('App\Providers\BughuntFakesServiceProvider'))->toBeTrue();
});
