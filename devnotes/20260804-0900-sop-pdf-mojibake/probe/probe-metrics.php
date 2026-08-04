<?php

declare(strict_types=1);

// 一時調査スクリプト。判定指標 (日本語文字比率 / CP1252 往復同一性 / CP932 妥当性) を
// 実データ + 対照コーパスで実測し、閾値の根拠を得る。

require __DIR__.'/../../../vendor/autoload.php';

use Smalot\PdfParser\Parser as PdfParser;

/** SopTextExtractor::normalize と同じ正規化 */
function normalize(string $text): string
{
    $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\n{3,}/u', "\n\n", str_replace("\r\n", "\n", $text)) ?? $text;

    return trim($text);
}

/** 空白を除いた評価対象文字数と、日本語文字 (かな/漢字/全角記号) の数 */
function metrics(string $utf8): array
{
    $assessable = 0;
    $ja = 0;
    $cp1252only = true;
    $len = mb_strlen($utf8, 'UTF-8');
    for ($i = 0; $i < $len; $i++) {
        $cp = mb_ord(mb_substr($utf8, $i, 1, 'UTF-8'), 'UTF-8');
        if ($cp === false) {
            continue;
        }
        if ($cp === 0x20 || $cp === 0x09 || $cp === 0x0A || $cp === 0x0D || $cp === 0x3000) {
            continue;
        }
        $assessable++;
        $isJa = ($cp >= 0x3040 && $cp <= 0x30FF)          // かな
            || ($cp >= 0x4E00 && $cp <= 0x9FFF)            // CJK 統合漢字
            || ($cp >= 0x3400 && $cp <= 0x4DBF)            // 拡張 A
            || ($cp >= 0xF900 && $cp <= 0xFAFF)            // 互換漢字
            || ($cp >= 0x3001 && $cp <= 0x303F)            // 全角句読点
            || ($cp >= 0xFF01 && $cp <= 0xFF60)            // 全角英数記号
            || ($cp >= 0xFF66 && $cp <= 0xFF9D);           // 半角カナ
        if ($isJa) {
            $ja++;
        }
    }
    // CP1252 往復同一性 (= 全文字が CP1252 レパートリ内)
    $sub = mb_substitute_character();
    mb_substitute_character(0x3F);
    $bytes = @mb_convert_encoding($utf8, 'CP1252', 'UTF-8');
    $cp1252only = is_string($bytes) && @mb_convert_encoding($bytes, 'UTF-8', 'CP1252') === $utf8;
    mb_substitute_character($sub);

    return [
        'assessable' => $assessable,
        'ja' => $ja,
        'jaRatio' => $assessable > 0 ? $ja / $assessable : 0.0,
        'cp1252only' => $cp1252only,
        'cp932valid' => is_string($bytes) ? mb_check_encoding($bytes, 'CP932') : false,
        'bytes' => $bytes,
    ];
}

function report(string $label, string $text): void
{
    $m = metrics($text);
    printf("%-34s len=%5d assessable=%5d ja=%5d jaRatio=%.3f cp1252only=%s cp932valid=%s\n",
        $label, strlen($text), $m['assessable'], $m['ja'], $m['jaRatio'],
        $m['cp1252only'] ? 'Y' : 'n', $m['cp932valid'] ? 'Y' : 'n');

    // 修復候補
    if ($m['cp1252only'] && $m['cp932valid'] && is_string($m['bytes'])) {
        $repaired = @mb_convert_encoding($m['bytes'], 'UTF-8', 'CP932');
        if (is_string($repaired) && mb_check_encoding($repaired, 'UTF-8')) {
            $r = metrics($repaired);
            printf("  -> CP932 修復候補: assessable=%5d ja=%5d jaRatio=%.3f head=%s\n",
                $r['assessable'], $r['ja'], $r['jaRatio'], mb_substr(normalize($repaired), 0, 50, 'UTF-8'));
        }
    }
}

$dir = __DIR__.'/../../../doc/reference/sample-sop';
foreach (['AS_作業手順書.pdf', 'AW_作業手順書 (1).pdf'] as $n) {
    report('PDF '.$n, normalize((new PdfParser)->parseFile($dir.'/'.$n)->getText()));
}

// 対照コーパス
report('正当な日本語 SOP', normalize("作業手順書\n1. ネジを締める (トルク 5Nm)\n2. カバーを取り付ける\n安全: 保護メガネ着用"));
report('日本語 (数値・型番多め)', normalize("SOP-1234 Rev.2 部品 A-9981 を 12.5mm まで挿入\nBOLT M6x20 x4 本 締付 5.0Nm\n確認: OK / NG"));
report('英語 SOP', normalize("Work Instruction\n1. Tighten the screw to 5Nm.\n2. Attach the cover plate.\nSafety: wear protective goggles."));
report('ドイツ語 SOP', normalize("Arbeitsanweisung\n1. Schraube mit 5Nm anziehen. Größe prüfen.\n2. Abdeckung anbringen. Für Straße."));
report('フランス語 SOP', normalize("Mode opératoire\n1. Serrer la vis à 5 Nm. Vérifier la référence.\n2. Fixer le capot arrière."));
