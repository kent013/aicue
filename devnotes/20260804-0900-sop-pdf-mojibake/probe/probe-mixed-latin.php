<?php

declare(strict_types=1);

/*
 * Codex impl-review round 1 の Warning 1 (「復元段が pdf 以外にも適用され、正当な CP1252 拡張文字を
 * 誤変換しうる」) を実測で検証する probe。
 *
 * SopTextExtractor の復元アルゴリズム (区間抽出 + 3 段検証) をそのまま写して、
 * 「正当な日本語 + CP1252 拡張文字 (Café / Größe / à la carte)」の混在入力に対して
 * 1 文字でも変化するかを確認する。
 *
 * 実行: php devnotes/20260804-0900-sop-pdf-mojibake/probe/probe-mixed-latin.php
 *
 * ⚠ この probe が写しているのは **round 1 時点の採用基準** (日本語文字数が増える + 比率 >= 0.10)。
 * この基準は「© / ± / ° などの CP1252 高位単バイトが CP932 では半角カナ 1 バイトに写る」
 * ケースを取りこぼしており、round 2 のレビューで指摘された。後継の実測は
 * probe-run-criteria.php を見ること (そちらが最終的に採用した基準を決めている)。
 */

const CP1252_RUN_PATTERN = '/[\x{0000}-\x{007F}\x{0081}\x{008D}\x{008F}\x{0090}\x{009D}'
    .'\x{00A0}-\x{00FF}\x{20AC}\x{201A}\x{0192}\x{201E}\x{2026}\x{2020}\x{2021}\x{02C6}'
    .'\x{2030}\x{0160}\x{2039}\x{0152}\x{017D}\x{2018}\x{2019}\x{201C}\x{201D}\x{2022}'
    .'\x{2013}\x{2014}\x{02DC}\x{2122}\x{0161}\x{203A}\x{0153}\x{017E}\x{0178}]+/u';

const JAPANESE_PATTERN = '/[\x{3001}-\x{303F}\x{3040}-\x{30FF}\x{3400}-\x{4DBF}'
    .'\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}\x{FF01}-\x{FF60}\x{FF66}-\x{FF9D}]/u';

const NON_SPACE_PATTERN = '/[^\s\x{3000}]/u';

function japaneseCount(string $text): int
{
    $count = preg_match_all(JAPANESE_PATTERN, $text);

    return is_int($count) ? $count : 0;
}

function japaneseRatio(string $text): float
{
    $assessable = preg_match_all(NON_SPACE_PATTERN, $text);
    if (! is_int($assessable) || $assessable === 0) {
        return 0.0;
    }

    return japaneseCount($text) / $assessable;
}

/** @return array{string, string} 復元結果と、区間ごとの判定トレース */
function repair(string $text, float $min = 0.10): array
{
    $trace = '';
    $repaired = preg_replace_callback(CP1252_RUN_PATTERN, function (array $m) use ($min, &$trace): string {
        $run = (string) $m[0];
        $bytes = mb_convert_encoding($run, 'CP1252', 'UTF-8');
        if (mb_convert_encoding($bytes, 'UTF-8', 'CP1252') !== $run) {
            $trace .= sprintf("  [不採用: CP1252 不可逆] %s\n", json_encode($run, JSON_UNESCAPED_UNICODE));

            return $run;
        }
        if (! mb_check_encoding($bytes, 'SJIS-win')) {
            $trace .= sprintf("  [不採用: SJIS-win 不正] %s\n", json_encode($run, JSON_UNESCAPED_UNICODE));

            return $run;
        }
        $decoded = mb_convert_encoding($bytes, 'UTF-8', 'SJIS-win');
        if (! mb_check_encoding($decoded, 'UTF-8')) {
            $trace .= sprintf("  [不採用: UTF-8 不正] %s\n", json_encode($run, JSON_UNESCAPED_UNICODE));

            return $run;
        }
        if (japaneseCount($decoded) <= japaneseCount($run)) {
            $trace .= sprintf("  [不採用: 日本語が増えない] %s → %s\n",
                json_encode($run, JSON_UNESCAPED_UNICODE), json_encode($decoded, JSON_UNESCAPED_UNICODE));

            return $run;
        }
        if (japaneseRatio($decoded) < $min) {
            $trace .= sprintf("  [不採用: 比率 %.3f < %.2f] %s\n", japaneseRatio($decoded), $min,
                json_encode($run, JSON_UNESCAPED_UNICODE));

            return $run;
        }
        $trace .= sprintf("  [採用] %s → %s\n",
            json_encode($run, JSON_UNESCAPED_UNICODE), json_encode($decoded, JSON_UNESCAPED_UNICODE));

        return $decoded;
    }, $text);

    return [is_string($repaired) ? $repaired : $text, $trace];
}

$cases = [
    '日本語 + CP1252 拡張 (Café)' => "作業手順書 (Café ラインの Größe 点検)\n"
        ."1. ネジを締める。トルクは 5Nm とする。\n"
        ."2. カバーを取り付ける。\n"
        .'備考: à la carte の設備は対象外とする。',
    '日本語 + 記号ダッシュ・引用符' => "作業手順書 — 「安全確認」\n1. ネジを締める (5Nm)。\n2. 保護メガネを着用する。",
    'CP1252 拡張のみ (独)' => 'Arbeitsanweisung: Schraube mit 5Nm anziehen. Größe prüfen. Für die Straße. Öl nachfüllen.',
    'CP1252 拡張のみ (仏)' => 'Mode opératoire: Serrer la vis à 5 Nm. Vérifier la référence arrière. Côté gauche.',
    '通貨・商標記号混在' => "作業手順書\n1. €120 の部材を使う。2. ™ 表示を確認する。3. ネジを締める。",
];

$ng = 0;
foreach ($cases as $label => $text) {
    [$out, $trace] = repair($text);
    $same = $out === $text;
    $ng += $same ? 0 : 1;
    printf("=== %s ===\n  変化: %s (ja %.3f → %.3f)\n%s\n", $label, $same ? 'なし' : '★あり★',
        japaneseRatio($text), japaneseRatio($out), $trace);
}

// 対照: 本物の SJIS 化けは復元されること (アルゴリズムが死んでいないことの確認)
$mojibake = mb_convert_encoding(
    (string) mb_convert_encoding('作業手順書 ネジを締める 安全確認', 'CP932', 'UTF-8'), 'UTF-8', 'CP1252');
[$out] = repair((string) $mojibake);
printf("=== 対照: 人工 SJIS 化け ===\n  復元: %s\n", $out === '作業手順書 ネジを締める 安全確認' ? 'OK' : "NG ({$out})");

printf("\n判定: 正当テキストで変化した件数 = %d (0 なら Warning 1 のリスクは実測で不成立)\n", $ng);
