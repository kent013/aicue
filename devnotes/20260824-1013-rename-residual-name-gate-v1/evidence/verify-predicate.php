<?php

declare(strict_types=1);
require '/workspace/vendor/autoload.php';
function base_path(string $p = ''): string
{
    return '/workspace'.($p === '' ? '' : '/'.$p);
}
require __DIR__.'/candidate-core.php';

$fail = 0;
function ok(bool $c, string $label): void
{
    global $fail;
    if (! $c) {
        $fail++;
        echo "FAIL: $label\n";
    } else {
        echo "ok: $label\n";
    }
}

// N-1
$t0 = microtime(true);
$violations = [];
foreach (bughuntNamingTrackedFiles() as $rel) {
    foreach (bughuntNamingViolationsIn($rel, bughuntNamingSourceOf($rel), BUGHUNT_NAMING_DECLARED_OCCURRENCES) as $v) {
        $violations[] = $v;
    }
}
printf("N-1 files=%d elapsed=%.2fs violations=%d\n", count(bughuntNamingTrackedFiles()), microtime(true) - $t0, count($violations));
foreach ($violations as $v) {
    echo "  - $v\n";
}
ok($violations === [], 'N-1 現 HEAD で緑');

// N-2
$files = bughuntNamingTrackedFiles();
ok(count($files) >= BUGHUNT_NAMING_MINIMUM_TRACKED_FILES, 'N-2 母集団下限');
foreach (BUGHUNT_NAMING_SENTINEL_PATHS as $s) {
    ok(in_array($s, $files, true), "N-2 参照側番兵 $s");
}
foreach (BUGHUNT_NAMING_CANONICAL_SENTINELS as $name => $path) {
    ok(in_array($path, $files, true), "N-2 家系名番兵が追跡下 $path");
    ok(bughuntNamingOffsetsOf(bughuntNamingSourceOf($path), $name) !== [], "N-2 正の対照: 内容に $name を見つける");
    ok(str_contains($path, $name), "N-2 正の対照: パス名に $name を見つける");
}

// N-3
ok(['devnotes/'] === BUGHUNT_NAMING_EXCLUDED_PREFIXES, 'N-3 除外接頭辞');
ok(array_keys(BUGHUNT_NAMING_CANONICAL_SENTINELS) === array_values(BUGHUNT_RETIRED_NAMES), 'N-3 家系名 1:1 番兵');
ok(array_keys(BUGHUNT_NAMING_DECLARED_OCCURRENCES) === ['docs/TODO-closed.md'], 'N-3 申告台帳のキー');
foreach (BUGHUNT_NAMING_DECLARED_OCCURRENCES as $path => $perName) {
    ok(in_array($path, $files, true), "N-3 申告先が追跡下 $path");
    $content = bughuntNamingSourceOf($path);
    foreach ($perName as $retired => $entries) {
        ok(array_key_exists($retired, BUGHUNT_RETIRED_NAMES), "N-3 申告の旧名が写像にある $retired");
        ok($entries !== [], "N-3 申告 0 件の登録が無い $retired");
        foreach ($entries as $e) {
            ok(mb_strlen($e['reason']) >= BUGHUNT_NAMING_MINIMUM_REASON_LENGTH, 'N-3 理由 30 文字以上: '.mb_substr($e['reason'], 0, 12));
            ok(count(bughuntNamingOffsetsOf($e['needle'], $retired)) === 1, 'N-3 needle が旧名を 1 回含む');
            ok(count(bughuntNamingOffsetsOf($content, $e['needle'])) === 1, 'N-3 needle が実物に 1 回ある');
        }
        ok(count($entries) === count(bughuntNamingOffsetsOf($content, $retired)), "N-3 申告本数 = 実出現数 ($retired)");
    }
}

// N-4 負のコントロール (合成台帳)
$retired = array_keys(BUGHUNT_RETIRED_NAMES);
$canonical = array_values(BUGHUNT_RETIRED_NAMES);
[$seeder, $provider] = $retired;
$reason = '負のコントロール用の合成理由 (30 文字以上であること)';
$ledger = ['docs/record.md' => [$seeder => [['needle' => "T001 で {$seeder} を作った", 'reason' => $reason]]]];
$body = "行 1: T001 で {$seeder} を作った\n行 2: ふつうの文\n";
ok(bughuntNamingViolationsIn('docs/record.md', $body, $ledger) === [], 'N-4a 申告どおりなら緑');
$swapped = "行 1: ふつうの文\n行 2: T002 で {$seeder} を消した\n";
$m = bughuntNamingViolationsIn('docs/record.md', $swapped, $ledger);
ok(count($m) === 2, 'N-4b 件数同じで出現すり替え → 赤 2 件 (実際: '.count($m).')');
ok(str_contains(implode("\n", $m), '申告を足す・移す・外す'), 'N-4b 復旧手順を message に含む');
ok(count(bughuntNamingViolationsIn('docs/record.md', $body."後から {$seeder}\n", $ledger)) === 1, 'N-4c 申告外の出現 → 赤');
ok(count(bughuntNamingViolationsIn('docs/record.md', "行 1: ふつうの文\n", $ledger)) === 1, 'N-4d 申告があるのに消えた → 赤');
ok(count(bughuntNamingViolationsIn('app/Foo.php', "class Foo extends {$seeder} {}", $ledger)) === 1, 'N-4e 申告の無いファイルの内容 → 赤');
ok(count(bughuntNamingViolationsIn("app/Providers/{$provider}.php", '', $ledger)) === 1, 'N-4f パス名に旧名 → 赤');
ok(bughuntNamingViolationsIn("database/seeders/{$canonical[0]}.php", "class {$canonical[0]} {}", $ledger) === [], 'N-4g1 家系名は誤検出しない');
ok(bughuntNamingViolationsIn("app/Providers/{$canonical[1]}.php", "class {$canonical[1]} {}", $ledger) === [], 'N-4g2 家系名は誤検出しない');
ok(bughuntNamingViolationsIn('devnotes/x/y.md', "{$seeder} {$provider}", $ledger) === [], 'N-4h1 devnotes は沈黙');
ok(bughuntNamingViolationsIn(BUGHUNT_NAMING_SELF_PATH, "{$seeder} {$provider}", $ledger) === [], 'N-4h2 自ファイルは沈黙');
$twice = "行 1: T001 で {$seeder} を作った\n行 2: T001 で {$seeder} を作った\n";
$m2 = bughuntNamingViolationsIn('docs/record.md', $twice, $ledger);
ok(count($m2) === 2, 'N-4i needle が 2 回 → 赤 (実際: '.count($m2).')');
$dup = ['docs/record.md' => [$seeder => [
    ['needle' => "T001 で {$seeder} を作った", 'reason' => $reason],
    ['needle' => "T001 で {$seeder} を作った", 'reason' => $reason],
]]];
$m3 = bughuntNamingViolationsIn('docs/record.md', $body, $dup);
ok(count($m3) === 1 && str_contains($m3[0], '二重に指している'), 'N-4j 二重申告 → 赤 (実際: '.count($m3).')');
$bad = ['docs/record.md' => [$seeder => [['needle' => "T001 で {$seeder} と {$seeder}", 'reason' => $reason]]]];
$m4 = bughuntNamingViolationsIn('docs/record.md', "T001 で {$seeder} と {$seeder}\n", $bad);
ok(count($m4) >= 1 && str_contains($m4[0], 'ちょうど 1 回含まない'), 'N-4k needle が旧名を 2 回含む → 赤');
echo $fail === 0 ? "\nALL OK\n" : "\nFAILURES: $fail\n";

// (l) 件数は一致するが 2 申告が同じ出現を指し、別の 1 件が未申告 (位置集合強化の必要性の証明)
$twoBody = "行 1: T001 で {$seeder} を作った\n行 2: T002 で {$seeder} を消した\n";
$sameSpot = ['docs/record.md' => [$seeder => [
    ['needle' => "T001 で {$seeder}", 'reason' => $reason],
    ['needle' => "で {$seeder} を作った", 'reason' => $reason],
]]];
$m5 = bughuntNamingViolationsIn('docs/record.md', $twoBody, $sameSpot);
echo '  (l) count='.count($m5)."\n";
foreach ($m5 as $x) {
    echo "   * $x\n";
}
ok(count($m5) === 2, 'N-4l 件数一致・同一位置の二重申告 → 赤 2 件');
$byCount = count($sameSpot['docs/record.md'][$seeder]) === count(bughuntNamingOffsetsOf($twoBody, $seeder));
ok($byCount === true, 'N-4l 件数比較だけなら緑 (穴の実証)');
echo $fail === 0 ? "\nALL OK (with l)\n" : "\nFAILURES: $fail\n";
