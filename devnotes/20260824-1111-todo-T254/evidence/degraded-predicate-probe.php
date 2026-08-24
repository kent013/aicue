<?php

declare(strict_types=1);

/*
 * テストファースト手順 2 の実測: 「突き合わせを申告の本数と実出現数の比較だけに落とすと、
 * N-4 の 12 ケースのうち緑になってしまうのはどれか」を全ケース一気に見る。
 *
 * 実行: php devnotes/20260824-1111-todo-T254/evidence/degraded-predicate-probe.php
 *
 * 期待 (詳細設計 §テストファースト手順 2): 退化版で「検出せず (= 緑)」になるのは (b) と (l) の 2 つだけ。
 */

const PROBE_RETIRED_NAMES = [
    'BughuntBillingSeeder' => 'BughuntStripeSyncSeeder',
    'FakeExternalsServiceProvider' => 'BughuntFakesServiceProvider',
];
const PROBE_SELF_PATH = 'tests/Architecture/BughuntNamingResidualTest.php';
const PROBE_EXCLUDED_PREFIXES = ['devnotes/'];

/** @return list<int> */
function probeOffsetsOf(string $haystack, string $needle): array
{
    $offsets = [];
    $from = 0;
    while (($at = strpos($haystack, $needle, $from)) !== false) {
        $offsets[] = $at;
        $from = $at + 1;
    }

    return $offsets;
}

function probeExcluded(string $relative): bool
{
    if ($relative === PROBE_SELF_PATH) {
        return true;
    }
    foreach (PROBE_EXCLUDED_PREFIXES as $prefix) {
        if (str_starts_with($relative, $prefix)) {
            return true;
        }
    }

    return false;
}

/**
 * 退化版: パス名の照合は持つが、内容は「申告の本数と実出現数の比較」だけ。
 *
 * @param  array<string, array<string, list<array{needle: string, reason: string}>>>  $declarations
 * @return list<string>
 */
function probeDegraded(string $relative, string $content, array $declarations): array
{
    if (probeExcluded($relative)) {
        return [];
    }

    $violations = [];
    foreach (PROBE_RETIRED_NAMES as $retired => $canonical) {
        if (str_contains($relative, $retired)) {
            $violations[] = "パス名: {$relative}";
        }
        $actual = probeOffsetsOf($content, $retired);
        $declaredCount = count($declarations[$relative][$retired] ?? []);
        if (count($actual) !== $declaredCount) {
            $violations[] = "件数不一致: {$relative} / {$retired}";
        }
    }

    return $violations;
}

/**
 * 正典 v1 の本実装 (出現位置の集合一致)。
 *
 * @param  array<string, array<string, list<array{needle: string, reason: string}>>>  $declarations
 * @return list<string>
 */
function probeCanonical(string $relative, string $content, array $declarations): array
{
    if (probeExcluded($relative)) {
        return [];
    }

    $violations = [];
    foreach (PROBE_RETIRED_NAMES as $retired => $canonical) {
        if (str_contains($relative, $retired)) {
            $violations[] = "パス名: {$relative}";
        }

        $actual = probeOffsetsOf($content, $retired);
        $declared = [];
        foreach ($declarations[$relative][$retired] ?? [] as $entry) {
            $inner = probeOffsetsOf($entry['needle'], $retired);
            if (count($inner) !== 1) {
                $violations[] = "周辺文字列が旧名をちょうど 1 回含まない: {$relative}";

                continue;
            }
            $hits = probeOffsetsOf($content, $entry['needle']);
            if (count($hits) !== 1) {
                $violations[] = "申告が出現を特定できない: {$relative}";

                continue;
            }
            $declared[] = $hits[0] + $inner[0];
        }
        sort($declared);

        if (array_values(array_diff($actual, $declared)) !== []) {
            $violations[] = "申告外の出現がある: {$relative}";
        }
        if (count($declared) !== count(array_unique($declared))) {
            $violations[] = "申告が同じ出現を二重に指している: {$relative}";
        }
    }

    return $violations;
}

$seeder = 'BughuntBillingSeeder';
$provider = 'FakeExternalsServiceProvider';
$reason = '負のコントロール用の合成理由 (30 文字以上であることを N-3 と同じ規則で満たす)';
$ledger = [
    'docs/record.md' => [
        $seeder => [
            ['needle' => "T001 で {$seeder} を作った", 'reason' => $reason],
        ],
    ],
];
$body = "行 1: T001 で {$seeder} を作った\n行 2: ふつうの文\n";
$twoOccurrences = "行 1: T001 で {$seeder} を作った\n行 2: T002 で {$seeder} を消した\n";

/** @var list<array{0: string, 1: string, 2: string, 3: array<string, array<string, list<array{needle: string, reason: string}>>>, 4: bool}> $cases */
$cases = [
    ['(a) 申告どおり', 'docs/record.md', $body, $ledger, false],
    ['(b) 件数同じで出現すり替え', 'docs/record.md', "行 1: ふつうの文\n行 2: T002 で {$seeder} を消した\n", $ledger, true],
    ['(c) 申告外の出現が増えた', 'docs/record.md', $body."後から {$seeder}\n", $ledger, true],
    ['(d) 申告があるのに消えた', 'docs/record.md', "行 1: ふつうの文\n", $ledger, true],
    ['(e) 申告の無いファイル', 'app/Foo.php', "class Foo extends {$seeder} {}", $ledger, true],
    ['(f) パス名に旧名', "app/Providers/{$provider}.php", '', $ledger, true],
    ['(g1) 家系名 seeder', 'database/seeders/BughuntStripeSyncSeeder.php', 'class BughuntStripeSyncSeeder {}', $ledger, false],
    ['(g2) 家系名 provider', 'app/Providers/BughuntFakesServiceProvider.php', 'class BughuntFakesServiceProvider {}', $ledger, false],
    ['(h1) devnotes 除外', 'devnotes/x/y.md', "{$seeder} {$provider}", $ledger, false],
    ['(h2) 自ファイル除外', PROBE_SELF_PATH, "{$seeder} {$provider}", $ledger, false],
    ['(i) 周辺文字列が 2 回', 'docs/record.md', "行 1: T001 で {$seeder} を作った\n行 2: T001 で {$seeder} を作った\n", $ledger, true],
    ['(j) 同じ出現を二重申告', 'docs/record.md', $body, [
        'docs/record.md' => [$seeder => [
            ['needle' => "T001 で {$seeder} を作った", 'reason' => $reason],
            ['needle' => "T001 で {$seeder} を作った", 'reason' => $reason],
        ]],
    ], true],
    ['(k) 周辺文字列が旧名を 2 回含む', 'docs/record.md', "T001 で {$seeder} と {$seeder}\n", [
        'docs/record.md' => [$seeder => [
            ['needle' => "T001 で {$seeder} と {$seeder}", 'reason' => $reason],
        ]],
    ], true],
    ['(l) 2 申告が同じ出現・別の 1 件が未申告', 'docs/record.md', $twoOccurrences, [
        'docs/record.md' => [$seeder => [
            ['needle' => "T001 で {$seeder}", 'reason' => $reason],
            ['needle' => "で {$seeder} を作った", 'reason' => $reason],
        ]],
    ], true],
];

$greenUnderDegraded = [];

printf("%-40s %-12s %-12s %s\n", 'ケース', '期待', '退化版', '本実装');
foreach ($cases as [$label, $relative, $content, $declarations, $shouldDetect]) {
    $degraded = probeDegraded($relative, $content, $declarations) !== [];
    $canonical = probeCanonical($relative, $content, $declarations) !== [];

    if ($shouldDetect && ! $degraded) {
        $greenUnderDegraded[] = $label;
    }

    printf(
        "%-40s %-12s %-12s %s\n",
        $label,
        $shouldDetect ? '検出' : '沈黙',
        $degraded ? '検出' : '★沈黙',
        $canonical === $shouldDetect ? 'OK' : 'NG'
    );
}

echo "\n退化版 (件数比較だけ) で沈黙してしまうケース: ".implode(' / ', $greenUnderDegraded)."\n";
