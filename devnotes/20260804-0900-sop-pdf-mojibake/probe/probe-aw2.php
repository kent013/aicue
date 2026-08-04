<?php

declare(strict_types=1);

// 一時調査スクリプト。AW の各ページの Font リソースを列挙し、実際に本文描画に使われる
// フォントが Type0/Encoding/ToUnicode をどう持つかを確認する。

require __DIR__.'/../../../vendor/autoload.php';

use Smalot\PdfParser\Font;
use Smalot\PdfParser\Parser as PdfParser;

$file = __DIR__.'/../../../doc/reference/sample-sop/AW_作業手順書 (1).pdf';
$document = (new PdfParser)->parseFile($file);

foreach ($document->getPages() as $i => $page) {
    echo "--- page $i\n";
    foreach ($page->getFonts() as $key => $font) {
        /** @var Font $font */
        $h = $font->getHeader();
        $subtype = $h->has('Subtype') ? (string) $h->get('Subtype')->getContent() : '-';
        $enc = '-';
        if ($h->has('Encoding')) {
            $e = $h->get('Encoding');
            $enc = method_exists($e, 'getContent') && is_string($e->getContent()) ? $e->getContent() : get_class($e);
        }
        $det = $font->getDetails(false);
        printf("  %s Subtype=%s Encoding=%s ToUnicode=%s Base=%s\n",
            $key, $subtype, $enc, $h->has('ToUnicode') ? 'YES' : 'no',
            $det['BaseFont'] ?? '-');
    }
    if ($i >= 1) {
        break;
    }
}
