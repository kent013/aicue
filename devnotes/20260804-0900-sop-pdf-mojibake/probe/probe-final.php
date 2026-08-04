<?php

declare(strict_types=1);

// 一時調査スクリプト。詳細設計で採用するアルゴリズムそのもの (正規表現 + 区間単位判定) を
// 実データ・対照コーパスで最終検証する。

require __DIR__.'/../../../vendor/autoload.php';

use Smalot\PdfParser\Parser as PdfParser;

const CP1252_RUN = '/[\x{0000}-\x{007F}\x{0081}\x{008D}\x{008F}\x{0090}\x{009D}\x{00A0}-\x{00FF}'
    .'\x{20AC}\x{201A}\x{0192}\x{201E}\x{2026}\x{2020}\x{2021}\x{02C6}\x{2030}\x{0160}\x{2039}'
    .'\x{0152}\x{017D}\x{2018}\x{2019}\x{201C}\x{201D}\x{2022}\x{2013}\x{2014}\x{02DC}\x{2122}'
    .'\x{0161}\x{203A}\x{0153}\x{017E}\x{0178}]+/u';

const JA = '/[\x{3001}-\x{303F}\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}'
    .'\x{F900}-\x{FAFF}\x{FF01}-\x{FF60}\x{FF66}-\x{FF9D}]/u';

const NON_SPACE = '/[^\s\x{3000}]/u';

const THRESHOLD = 0.10;

function jaCount(string $s): int
{
    return preg_match_all(JA, $s);
}

function assessable(string $s): int
{
    return preg_match_all(NON_SPACE, $s);
}

function jaRatio(string $s): float
{
    $a = assessable($s);

    return $a > 0 ? jaCount($s) / $a : 0.0;
}

/** SJIS-win 誤解釈の復元 (区間単位) */
function repair(string $text): string
{
    $result = preg_replace_callback(CP1252_RUN, function (array $m): string {
        $run = $m[0];
        $bytes = mb_convert_encoding($run, 'CP1252', 'UTF-8');
        if (! is_string($bytes) || mb_convert_encoding($bytes, 'UTF-8', 'CP1252') !== $run) {
            return $run; // 非可逆 = この区間は CP1252 由来ではない
        }
        if (! mb_check_encoding($bytes, 'SJIS-win')) {
            return $run;
        }
        $decoded = mb_convert_encoding($bytes, 'UTF-8', 'SJIS-win');
        if (! is_string($decoded) || ! mb_check_encoding($decoded, 'UTF-8')) {
            return $run;
        }
        if (jaCount($decoded) <= jaCount($run) || jaRatio($decoded) < THRESHOLD) {
            return $run;
        }

        return $decoded;
    }, $text);

    return is_string($result) ? $result : $text;
}

function show(string $label, string $text): void
{
    $rep = repair($text);
    printf("%-24s before ja=%.3f (%d bytes) -> after ja=%.3f (%d bytes) 変化=%s gate=%s\n",
        $label, jaRatio($text), strlen($text), jaRatio($rep), strlen($rep),
        $rep === $text ? '無' : '有', jaRatio($rep) >= THRESHOLD ? 'PASS' : 'REJECT');
}

$dir = __DIR__.'/../../../doc/reference/sample-sop';
foreach (['AS_作業手順書.pdf', 'AW_作業手順書 (1).pdf', 'AP_オペレーション手順書.pdf', 'AT_作業手順書.pdf', '作業要領書.pdf'] as $n) {
    show('PDF '.mb_substr($n, 0, 8, 'UTF-8'), (new PdfParser)->parseFile($dir.'/'.$n)->getText());
}
show('正当な日本語', "作業手順書\n1. ネジを締める (トルク 5Nm)\n2. カバーを取り付ける\n安全: 保護メガネ着用");
show('日本語(型番多め)', "SOP-1234 Rev.2 部品 A-9981 を 12.5mm まで挿入\nBOLT M6x20 x4 本 締付 5.0Nm");
show('英語', "Work Instruction\n1. Tighten the screw to 5Nm.\n2. Attach the cover plate.");
show('ドイツ語', "Arbeitsanweisung: Schraube mit 5Nm anziehen. Größe prüfen. Für Straße. Öl. Weiß.");
show('フランス語', "Mode opératoire: Serrer la vis à 5 Nm. Vérifier la référence arrière. Côté.");
show('人工 SJIS 化け', (string) mb_convert_encoding((string) mb_convert_encoding("作業手順書 ネジを締める 安全確認", 'CP932', 'UTF-8'), 'UTF-8', 'CP1252'));

// AS の OCR 層日本語が保存されるか
$as = (new PdfParser)->parseFile($dir.'/AS_作業手順書.pdf')->getText();
$rep = repair($as);
foreach (['非鉄金属', 'レスト台', '砥石'] as $needle) {
    echo (str_contains($rep, $needle) ? '  [保存] ' : '  [欠落] ').$needle."\n";
}
printf("AS 復元後 bytes=%d (元 %d) / 上限 150000 に対し %.1f%%\n", strlen($rep), strlen($as), strlen($rep) / 150000 * 100);

// 性能
$big = str_repeat($as, 20);
$t0 = microtime(true);
repair($big);
printf("性能: %d bytes を %.3fs で処理\n", strlen($big), microtime(true) - $t0);
