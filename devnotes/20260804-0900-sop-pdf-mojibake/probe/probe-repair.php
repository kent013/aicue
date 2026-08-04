<?php

declare(strict_types=1);

// 一時調査スクリプト。区間単位 CP1252→CP932 復号アルゴリズムの実測検証。
// 目的: (a) AS が完全復元されること (b) OCR 層由来の正規日本語 63 文字が失われないこと
//       (c) 英/独/仏/正規日本語が壊れないこと。

require __DIR__.'/../../../vendor/autoload.php';

use Smalot\PdfParser\Parser as PdfParser;

/** 1 文字が CP1252 レパートリ内か (往復同一性で判定) */
function inCp1252(string $ch): bool
{
    $b = @mb_convert_encoding($ch, 'CP1252', 'UTF-8');

    return is_string($b) && @mb_convert_encoding($b, 'UTF-8', 'CP1252') === $ch;
}

/** 日本語文字数 / 空白除く文字数 */
function jaStats(string $utf8): array
{
    $a = 0;
    $j = 0;
    $len = mb_strlen($utf8, 'UTF-8');
    for ($i = 0; $i < $len; $i++) {
        $cp = mb_ord(mb_substr($utf8, $i, 1, 'UTF-8'), 'UTF-8');
        if ($cp === false || $cp === 0x20 || $cp === 0x09 || $cp === 0x0A || $cp === 0x0D || $cp === 0x3000) {
            continue;
        }
        $a++;
        if (($cp >= 0x3040 && $cp <= 0x30FF) || ($cp >= 0x4E00 && $cp <= 0x9FFF)
            || ($cp >= 0x3400 && $cp <= 0x4DBF) || ($cp >= 0xF900 && $cp <= 0xFAFF)
            || ($cp >= 0x3001 && $cp <= 0x303F) || ($cp >= 0xFF01 && $cp <= 0xFF60)
            || ($cp >= 0xFF66 && $cp <= 0xFF9D)) {
            $j++;
        }
    }

    return [$j, $a, $a > 0 ? $j / $a : 0.0];
}

/**
 * 区間単位復号: CP1252 レパートリ内の極大連続区間だけを CP932 として読み直す。
 * 区間ごとに「CP932 として妥当」かつ「復号後に日本語が出現する」場合のみ採用。
 */
function repair(string $text): array
{
    $len = mb_strlen($text, 'UTF-8');
    $out = '';
    $run = '';
    $adopted = 0;
    $rejected = 0;

    $flush = function () use (&$run, &$out, &$adopted, &$rejected): void {
        if ($run === '') {
            return;
        }
        $bytes = @mb_convert_encoding($run, 'CP1252', 'UTF-8');
        $ok = false;
        if (is_string($bytes) && mb_check_encoding($bytes, 'CP932')) {
            $decoded = @mb_convert_encoding($bytes, 'UTF-8', 'CP932');
            if (is_string($decoded) && mb_check_encoding($decoded, 'UTF-8')) {
                [$jBefore] = jaStats($run);
                [$jAfter] = jaStats($decoded);
                if ($jAfter > $jBefore) {
                    $out .= $decoded;
                    $ok = true;
                }
            }
        }
        if ($ok) {
            $adopted++;
        } else {
            $out .= $run;
            $rejected++;
        }
        $run = '';
    };

    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($text, $i, 1, 'UTF-8');
        if (inCp1252($ch)) {
            $run .= $ch;
        } else {
            $flush();
            $out .= $ch;
        }
    }
    $flush();

    return [$out, $adopted, $rejected];
}

function show(string $label, string $text): void
{
    [$j0, $a0, $r0] = jaStats($text);
    [$rep, $adopted, $rejected] = repair($text);
    [$j1, $a1, $r1] = jaStats($rep);
    printf("%-26s before ja=%4d/%5d (%.3f) -> after ja=%4d/%5d (%.3f) 区間 採用=%d 不採用=%d 変化=%s\n",
        $label, $j0, $a0, $r0, $j1, $a1, $r1, $adopted, $rejected, $rep === $text ? '無し' : 'あり');
    if ($rep !== $text) {
        echo '   head: '.mb_substr(trim(preg_replace('/\s+/u', ' ', $rep) ?? $rep), 0, 90, 'UTF-8')."\n";
    }
}

$dir = __DIR__.'/../../../doc/reference/sample-sop';
$as = (new PdfParser)->parseFile($dir.'/AS_作業手順書.pdf')->getText();
$aw = (new PdfParser)->parseFile($dir.'/AW_作業手順書 (1).pdf')->getText();

show('AS (mojibake PDF)', $as);
show('AW (glyph noise PDF)', $aw);
show('正当な日本語', "作業手順書\n1. ネジを締める (トルク 5Nm)\n2. カバーを取り付ける\n安全: 保護メガネ着用");
show('日本語 (型番多め)', "SOP-1234 Rev.2 部品 A-9981 を 12.5mm まで挿入\nBOLT M6x20 x4 本 締付 5.0Nm");
show('英語', "Work Instruction\n1. Tighten the screw to 5Nm.\n2. Attach the cover plate.");
show('ドイツ語', "Arbeitsanweisung\n1. Schraube mit 5Nm anziehen. Größe prüfen. Für Straße. Öl.");
show('フランス語', "Mode opératoire\n1. Serrer la vis à 5 Nm. Vérifier la référence arrière.");
show('SJIS 化けの人工再現', (string) mb_convert_encoding((string) mb_convert_encoding("作業手順書 ネジを締める 安全確認", 'CP932', 'UTF-8'), 'UTF-8', 'CP1252'));

// AS: OCR 層由来の正規日本語が保存されているか
[$rep] = repair($as);
$lost = 0;
foreach (['非鉄金属', 'レスト台', '砥石'] as $needle) {
    if (! str_contains($rep, $needle)) {
        $lost++;
        echo "  [欠落] $needle\n";
    }
}
echo "OCR 層日本語の欠落: $lost 件\n";
echo "--- AS 復元先頭 400 文字 ---\n";
echo mb_substr(trim(preg_replace('/\s+/u', ' ', $rep) ?? $rep), 0, 400, 'UTF-8')."\n";
