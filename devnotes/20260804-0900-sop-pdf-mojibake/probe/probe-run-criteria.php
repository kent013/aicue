<?php

declare(strict_types=1);

/*
 * Codex impl-review round 2 の Warning (「CP1252 高位単バイト (© = 0xA9 / À = 0xC0 …) は
 * Shift_JIS では半角カナ 1 バイトなので、復号すると JAPANESE_PATTERN の FF66-FF9D に写り、
 * 『日本語が増えた + 比率 >= 0.10』を満たして正当テキストを誤変換しうる」) を実測で検証し、
 * 区間採用基準の候補を比較する probe。
 *
 * さらに round 3 で「短い CP1252 高位バイト列が偶然 CP932 の 2 バイト列として成立する」
 * 残存経路 (`àé` / `Àéé` 等) が指摘されたため、最低証拠数の条件も比較する。
 *
 * 比較する 4 基準:
 *   A (round 1) : japaneseCount(全体) が増える + japaneseRatio(全体) >= 0.10
 *   B           : 全角日本語 (半角カナ除外) が増える + japaneseRatio(全体) >= 0.10
 *   C (round 2) : 全角日本語 (半角カナ除外) が増える + japaneseRatio(全体) >= 0.50 (過半数)
 *   D (採用)    : C + 全角日本語の増加が 2 文字以上 (偶然の 1 件を化けと断定しない)
 *
 * 実行: php devnotes/20260804-0900-sop-pdf-mojibake/probe/probe-run-criteria.php
 */

require __DIR__.'/../../../vendor/autoload.php';

use Smalot\PdfParser\Parser as PdfParser;

const CP1252_RUN_PATTERN = '/[\x{0000}-\x{007F}\x{0081}\x{008D}\x{008F}\x{0090}\x{009D}'
    .'\x{00A0}-\x{00FF}\x{20AC}\x{201A}\x{0192}\x{201E}\x{2026}\x{2020}\x{2021}\x{02C6}'
    .'\x{2030}\x{0160}\x{2039}\x{0152}\x{017D}\x{2018}\x{2019}\x{201C}\x{201D}\x{2022}'
    .'\x{2013}\x{2014}\x{02DC}\x{2122}\x{0161}\x{203A}\x{0153}\x{017E}\x{0178}]+/u';

/** 日本語文字 (半角カナを含む。文書ゲート用) */
const JAPANESE_PATTERN = '/[\x{3001}-\x{303F}\x{3040}-\x{30FF}\x{3400}-\x{4DBF}'
    .'\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}\x{FF01}-\x{FF60}\x{FF66}-\x{FF9D}]/u';

/** 全角日本語 (半角カナ FF66-FF9D を除く = CP932 の 2 バイト列からしか出ない文字) */
const MULTIBYTE_JAPANESE_PATTERN = '/[\x{3001}-\x{303F}\x{3040}-\x{30FF}\x{3400}-\x{4DBF}'
    .'\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}\x{FF01}-\x{FF60}]/u';

const NON_SPACE_PATTERN = '/[^\s\x{3000}]/u';

function countBy(string $pattern, string $text): int
{
    $n = preg_match_all($pattern, $text);

    return is_int($n) ? $n : 0;
}

function ratio(string $text): float
{
    $d = countBy(NON_SPACE_PATTERN, $text);

    return $d === 0 ? 0.0 : countBy(JAPANESE_PATTERN, $text) / $d;
}

/** @return array{decoded: ?string, ratio: float, jaGain: int, mbGain: int} */
function analyzeRun(string $run): array
{
    $bytes = mb_convert_encoding($run, 'CP1252', 'UTF-8');
    if (mb_convert_encoding($bytes, 'UTF-8', 'CP1252') !== $run || ! mb_check_encoding($bytes, 'SJIS-win')) {
        return ['decoded' => null, 'ratio' => 0.0, 'jaGain' => 0, 'mbGain' => 0];
    }
    $decoded = mb_convert_encoding($bytes, 'UTF-8', 'SJIS-win');
    if (! mb_check_encoding($decoded, 'UTF-8')) {
        return ['decoded' => null, 'ratio' => 0.0, 'jaGain' => 0, 'mbGain' => 0];
    }

    return [
        'decoded' => $decoded,
        'ratio' => ratio($decoded),
        'jaGain' => countBy(JAPANESE_PATTERN, $decoded) - countBy(JAPANESE_PATTERN, $run),
        'mbGain' => countBy(MULTIBYTE_JAPANESE_PATTERN, $decoded) - countBy(MULTIBYTE_JAPANESE_PATTERN, $run),
    ];
}

/** @return array{string, string, string, string} 基準 A / B / C / D の復元結果 */
function repairAll(string $text): array
{
    $out = [];
    foreach ([['A', 'ja', 0.10, 1], ['B', 'mb', 0.10, 1], ['C', 'mb', 0.50, 1], ['D', 'mb', 0.50, 2]] as [$label, $gain, $min, $need]) {
        $out[$label] = preg_replace_callback(CP1252_RUN_PATTERN, function (array $m) use ($gain, $min, $need): string {
            $run = (string) $m[0];
            $a = analyzeRun($run);
            if ($a['decoded'] === null) {
                return $run;
            }
            $gained = $gain === 'ja' ? $a['jaGain'] : $a['mbGain'];

            return ($gained >= $need && $a['ratio'] >= $min) ? $a['decoded'] : $run;
        }, $text) ?? $text;
    }

    return [$out['A'], $out['B'], $out['C'], $out['D']];
}

echo "===== (1) Codex round 2 の指摘 (半角カナ帯) の再現 =====\n";
foreach (['©' => 0xA9, 'À' => 0xC0, 'Á' => 0xC1, '±' => 0xB1, '½' => 0xBD] as $ch => $byte) {
    $decoded = mb_convert_encoding(chr($byte), 'UTF-8', 'SJIS-win');
    printf("  %s (0x%02X) → SJIS-win 復号 = %s (U+%04X) / 日本語判定=%s 全角判定=%s\n",
        $ch, $byte, $decoded, mb_ord((string) $decoded, 'UTF-8'),
        countBy(JAPANESE_PATTERN, (string) $decoded) > 0 ? 'YES' : 'no',
        countBy(MULTIBYTE_JAPANESE_PATTERN, (string) $decoded) > 0 ? 'YES' : 'no');
}

echo "\n===== (2) 正当な日本語テキストに対する 4 基準の挙動 =====\n";
$legit = [
    '著作権表記' => '作業手順書 © 2026 株式会社サンプル 無断転載を禁ず。安全確認を徹底すること。',
    '著作権表記 (孤立)' => '作業手順書 © 株式会社サンプル',
    '仏語の混在 (créé)' => '作業手順書 この設備は 2020 年に créé された。ネジを締める。安全確認。',
    '度・単位記号' => '作業手順書 温度は 25° 前後、公差 ±0.5mm とする。ネジを締める。',
    '欧文人名の混在' => '作業手順書 担当 André Müller。ネジを締める。保護メガネを着用する。',
    'Café / Größe' => "作業手順書 (Café ラインの Größe 点検)\n1. ネジを締める。\n備考: à la carte は対象外。",
    // round 3 指摘: ASCII を挟まない高位バイト列は偶然 CP932 の 2 バイト列として成立しうる
    '高位バイト連続 àé' => '研削àé作業の手順書。ネジを締める。安全確認を徹底する。',
    '高位バイト連続 Àéé' => '研削Àéé作業の手順書。ネジを締める。安全確認を徹底する。',
    '高位バイト連続 ©éé' => '研削©éé作業の手順書。ネジを締める。安全確認を徹底する。',
];
foreach ($legit as $label => $text) {
    [$a, $b, $c, $d] = repairAll($text);
    printf("  %-24s A:%-6s B:%-6s C:%-6s D:%s\n", $label,
        $a === $text ? '不変' : '★変化★',
        $b === $text ? '不変' : '★変化★',
        $c === $text ? '不変' : '★変化★ → '.$c,
        $d === $text ? '不変' : '★変化★ → '.$d);
}

echo "\n===== (3) 実 PDF AS_作業手順書.pdf の区間統計 =====\n";
$as = (new PdfParser)->parseFile(__DIR__.'/../../../doc/reference/sample-sop/AS_作業手順書.pdf')->getText();
preg_match_all(CP1252_RUN_PATTERN, $as, $m);
$runs = $m[0];
printf("  区間数: %d\n", count($runs));
$adopt = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
$ratios = [];
$gains = [];
foreach ($runs as $run) {
    $a = analyzeRun($run);
    if ($a['decoded'] === null) {
        continue;
    }
    if ($a['jaGain'] > 0 && $a['ratio'] >= 0.10) {
        $adopt['A']++;
    }
    if ($a['mbGain'] > 0 && $a['ratio'] >= 0.10) {
        $adopt['B']++;
    }
    if ($a['mbGain'] > 0 && $a['ratio'] >= 0.50) {
        $adopt['C']++;
        $ratios[] = $a['ratio'];
        $gains[] = $a['mbGain'];
    }
    if ($a['mbGain'] >= 2 && $a['ratio'] >= 0.50) {
        $adopt['D']++;
    }
    if ($a['mbGain'] > 0 && $a['ratio'] < 0.50) {
        printf("  [C で落ちる区間] ratio=%.3f len=%d %s\n", $a['ratio'], mb_strlen($run),
            json_encode(mb_substr((string) $a['decoded'], 0, 40), JSON_UNESCAPED_UNICODE));
    }
}
printf("  採用区間数: A=%d B=%d C=%d D=%d\n", $adopt['A'], $adopt['B'], $adopt['C'], $adopt['D']);
if ($ratios !== []) {
    printf("  採用区間の ratio: min=%.3f max=%.3f / 全角日本語の増加: min=%d max=%d\n",
        min($ratios), max($ratios), min($gains), max($gains));
}

[$a, $b, $c, $d] = repairAll($as);
foreach (['A' => $a, 'B' => $b, 'C' => $c, 'D' => $d] as $label => $text) {
    $n = preg_replace('/\s+/u', ' ', $text) ?? $text;
    printf("  基準 %s: ja比率=%.3f / 'グラインダー研削作業'=%s / '保護メガネ'=%s\n", $label, ratio($n),
        str_contains($n, 'グラインダー研削作業') ? 'OK' : 'NG',
        str_contains($n, '保護メガネ') ? 'OK' : 'NG');
}

// AS の隠し OCR 層由来の正規日本語が保存されているか (基準 D)
foreach (['非鉄金属', 'レスト台', '砥石'] as $word) {
    printf("  OCR 層 '%s' 保存 (基準 D): %s\n", $word, str_contains($d, $word) ? 'OK' : 'NG');
}

echo "\n===== (4) 人工 SJIS 化けが 4 基準とも復元されること =====\n";
$moji = (string) mb_convert_encoding(
    (string) mb_convert_encoding('作業手順書 ネジを締める 安全確認 保護メガネ着用。', 'CP932', 'UTF-8'), 'UTF-8', 'CP1252');
[$a, $b, $c, $d] = repairAll($moji);
printf("  A:%s B:%s C:%s D:%s\n",
    str_contains($a, 'ネジを締める') ? 'OK' : 'NG',
    str_contains($b, 'ネジを締める') ? 'OK' : 'NG',
    str_contains($c, 'ネジを締める') ? 'OK' : 'NG',
    str_contains($d, 'ネジを締める') ? 'OK' : 'NG');

// 半角カナだけの化け (単バイト 0xA1-0xDF) は基準 B/C/D では復元されない。
// 実害: 半角カナのみで構成された区間は原文のまま = 「直せないものは触らない」設計方針どおり。
$kana = (string) mb_convert_encoding((string) mb_convert_encoding('ﾈｼﾞﾔｽﾞ', 'CP932', 'UTF-8'), 'UTF-8', 'CP1252');
[$a, $b, $c, $d] = repairAll($kana);
printf("  半角カナのみの化け: A:%s B:%s C:%s D:%s\n",
    $a === $kana ? '不変' : '復元', $b === $kana ? '不変' : '復元',
    $c === $kana ? '不変' : '復元', $d === $kana ? '不変' : '復元');

/*
 * ===== (5) round 4 の指摘への対応: 適用範囲の限定 (基準 E) =====
 *
 * round 4 で `àéàé` (= CP932 の 琺琺 と**バイト列が同一**) が指摘された。
 * 区間検証をいくら厳しくしても、CP932 の日本語とバイト列が一致する入力は原理的に弁別できない
 * (最低証拠数を N にしても `àé` を N 回並べれば通る = 際限のない軍拡になる)。
 *
 * 基準 E = 基準 D + **文書の前提条件**:
 *   復元は「そのままでは日本語本文ゲート (0.10) で拒否される文書」にのみ適用する。
 *   既に日本語として読める文書は 1 バイトも変更しない。
 * これにより正当なテキストの不変性が統計ではなく**構造**で保証される。
 */
echo "\n===== (5) 基準 E (= D + 文書前提条件 ja < 0.10 のときだけ復元) =====\n";

function repairE(string $text, float $gate = 0.10): string
{
    if (ratio($text) >= $gate) {
        return $text; // 既に日本語として読める = 復元の対象外
    }
    [, , , $d] = repairAll($text);

    return $d;
}

$adversarial = $legit + [
    'CP932 と同一バイト列 àéàé' => '研削àéàé作業の手順書。ネジを締める。安全確認を徹底する。',
    'CP932 と同一バイト列 àéàéàé' => '研削àéàéàé作業の手順書。ネジを締める。安全確認を徹底する。',
];
$ng = 0;
foreach ($adversarial as $label => $text) {
    [, , , $d] = repairAll($text);
    $e = repairE($text);
    $ng += $e === $text ? 0 : 1;
    printf("  %-26s ja=%.3f D:%-6s E:%s\n", $label, ratio($text),
        $d === $text ? '不変' : '★変化★', $e === $text ? '不変' : '★変化★ → '.$e);
}
printf("  → 正当な日本語テキストで基準 E が変化させた件数 = %d\n", $ng);

// 復元されるべきものが基準 E でも復元されること
printf("  実 PDF AS: ja(復元前)=%.3f → 復元適用=%s / 'グラインダー研削作業'=%s\n",
    ratio($as), ratio($as) < 0.10 ? 'YES' : 'NO',
    str_contains((string) preg_replace('/\s+/u', ' ', repairE($as)), 'グラインダー研削作業') ? 'OK' : 'NG');
printf("  人工 SJIS 化け: ja(復元前)=%.3f → 復元適用=%s / 'ネジを締める'=%s\n",
    ratio($moji), ratio($moji) < 0.10 ? 'YES' : 'NO',
    str_contains(repairE($moji), 'ネジを締める') ? 'OK' : 'NG');
