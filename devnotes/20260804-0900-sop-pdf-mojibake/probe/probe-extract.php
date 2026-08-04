<?php

declare(strict_types=1);

// 一時調査スクリプト (devnotes 配下)。sample-sop 5 本を pdfparser で抽出し、
// バイト数 / 文字クラス分布 / CP1252→CP932 往復復元の可否を実測する。

require __DIR__.'/../../../vendor/autoload.php';

use Smalot\PdfParser\Parser as PdfParser;

$dir = __DIR__.'/../../../doc/reference/sample-sop';
$files = glob($dir.'/*.pdf');
sort($files);

/** 文字クラス分布 */
function classify(string $utf8): array
{
    $counts = ['ascii' => 0, 'latin1sup' => 0, 'cjk' => 0, 'kana' => 0, 'other' => 0, 'total' => 0];
    $len = mb_strlen($utf8, 'UTF-8');
    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($utf8, $i, 1, 'UTF-8');
        $cp = mb_ord($ch, 'UTF-8');
        if ($cp === false) {
            continue;
        }
        $counts['total']++;
        if ($cp < 0x80) {
            $counts['ascii']++;
        } elseif ($cp >= 0x80 && $cp <= 0xFF) {
            $counts['latin1sup']++;
        } elseif (($cp >= 0x4E00 && $cp <= 0x9FFF) || ($cp >= 0x3400 && $cp <= 0x4DBF)) {
            $counts['cjk']++;
        } elseif (($cp >= 0x3040 && $cp <= 0x30FF) || ($cp >= 0xFF66 && $cp <= 0xFF9D)) {
            $counts['kana']++;
        } else {
            $counts['other']++;
        }
    }

    return $counts;
}

foreach ($files as $file) {
    echo str_repeat('=', 70)."\n";
    echo basename($file)."\n";
    try {
        $text = (new PdfParser)->parseFile($file)->getText();
    } catch (Throwable $e) {
        echo "  EXCEPTION: ".get_class($e).': '.$e->getMessage()."\n";
        continue;
    }
    $trimmed = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    echo '  raw bytes: '.strlen($text).' / whitespace-collapsed bytes: '.strlen($trimmed)."\n";
    echo '  valid UTF-8: '.var_export(mb_check_encoding($text, 'UTF-8'), true)."\n";
    if ($trimmed === '') {
        continue;
    }
    $c = classify($trimmed);
    printf("  chars total=%d ascii=%d latin1sup=%d cjk=%d kana=%d other=%d (latin1sup ratio=%.3f)\n",
        $c['total'], $c['ascii'], $c['latin1sup'], $c['cjk'], $c['kana'], $c['other'],
        $c['total'] > 0 ? $c['latin1sup'] / $c['total'] : 0);
    echo '  head(raw): '.mb_substr($trimmed, 0, 80, 'UTF-8')."\n";

    // CP1252 往復復元 (UTF-8 -> CP1252 バイト -> CP932 として解釈)
    foreach (['CP932', 'EUC-JP', 'ISO-2022-JP'] as $target) {
        $bytes = @mb_convert_encoding($trimmed, 'CP1252', 'UTF-8');
        if (! is_string($bytes)) {
            echo "  [$target] CP1252 化に失敗\n";
            continue;
        }
        $restored = @mb_convert_encoding($bytes, 'UTF-8', $target);
        if (! is_string($restored) || ! mb_check_encoding($restored, 'UTF-8')) {
            echo "  [$target] 復元不可\n";
            continue;
        }
        $rc = classify($restored);
        printf("  [%s] cjk=%d kana=%d latin1sup=%d other=%d head=%s\n",
            $target, $rc['cjk'], $rc['kana'], $rc['latin1sup'], $rc['other'],
            mb_substr($restored, 0, 60, 'UTF-8'));
    }
}
