<?php

declare(strict_types=1);

// 一時調査スクリプト。AW のページ数と、ページ単位のテキスト量 / フォント数を確認する。

require __DIR__.'/../../../vendor/autoload.php';

use Smalot\PdfParser\Parser as PdfParser;

foreach (['AW_作業手順書 (1).pdf', 'AS_作業手順書.pdf', 'AP_オペレーション手順書.pdf', 'AT_作業手順書.pdf', '作業要領書.pdf'] as $name) {
    $document = (new PdfParser)->parseFile(__DIR__.'/../../../doc/reference/sample-sop/'.$name);
    $pages = $document->getPages();
    echo "== $name pages=".count($pages)."\n";
    foreach ($pages as $i => $page) {
        $t = trim(preg_replace('/\s+/u', '', $page->getText()) ?? '');
        printf("   page %d: fonts=%d textchars=%d head=%s\n", $i, count($page->getFonts()), mb_strlen($t, 'UTF-8'), mb_substr($t, 0, 40, 'UTF-8'));
        if ($i >= 4) {
            echo "   ...\n";
            break;
        }
    }
}
