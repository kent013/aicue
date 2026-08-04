<?php

declare(strict_types=1);

// 一時調査スクリプト。AS / AW のフォント辞書 (Subtype / Encoding / ToUnicode 有無) を実測する。
// 「pdfparser の設定・API で正しく取れるか」(思考原則 1) の裏取り用。

require __DIR__.'/../../../vendor/autoload.php';

use Smalot\PdfParser\Font;
use Smalot\PdfParser\Parser as PdfParser;

$dir = __DIR__.'/../../../doc/reference/sample-sop';
foreach (['AS_作業手順書.pdf', 'AW_作業手順書 (1).pdf'] as $name) {
    echo str_repeat('=', 70)."\n$name\n";
    $document = (new PdfParser)->parseFile($dir.'/'.$name);
    $fonts = $document->getFonts();
    echo '  font count: '.count($fonts)."\n";
    foreach ($fonts as $id => $font) {
        /** @var Font $font */
        $h = $font->getHeader();
        $subtype = $h->has('Subtype') ? (string) $h->get('Subtype')->getContent() : '-';
        $base = $h->has('BaseFont') ? (string) $h->get('BaseFont')->getContent() : '-';
        $hasToUnicode = $h->has('ToUnicode') ? 'YES' : 'no';
        $enc = '-';
        if ($h->has('Encoding')) {
            $e = $h->get('Encoding');
            $enc = is_object($e) ? get_class($e) : gettype($e);
            if (method_exists($e, 'getContent') && is_string($e->getContent())) {
                $enc .= '('.$e->getContent().')';
            }
        }
        $tableSize = count($font->getDetails(false)) ? '' : '';
        printf("  [%s] Subtype=%s BaseFont=%s ToUnicode=%s Encoding=%s\n", $id, $subtype, $base, $hasToUnicode, $enc);
    }
}
