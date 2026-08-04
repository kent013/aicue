<?php

declare(strict_types=1);

// 一時調査スクリプト。AW の Type0 親フォント (Encoding / CIDSystemInfo) を辞書から直接読む。

require __DIR__.'/../../../vendor/autoload.php';

use Smalot\PdfParser\Parser as PdfParser;

$file = __DIR__.'/../../../doc/reference/sample-sop/AW_作業手順書 (1).pdf';
$document = (new PdfParser)->parseFile($file);

$seen = 0;
foreach ($document->getObjects() as $id => $object) {
    $h = $object->getHeader();
    if (! $h->has('Type')) {
        continue;
    }
    $type = (string) $h->get('Type')->getContent();
    if ($type !== 'Font') {
        continue;
    }
    $subtype = $h->has('Subtype') ? (string) $h->get('Subtype')->getContent() : '-';
    if ($subtype !== 'Type0') {
        continue;
    }
    $enc = '-';
    if ($h->has('Encoding')) {
        $e = $h->get('Encoding');
        $enc = method_exists($e, 'getContent') && is_string($e->getContent()) ? $e->getContent() : get_class($e);
    }
    printf("[%s] Type0 Encoding=%s ToUnicode=%s\n", $id, $enc, $h->has('ToUnicode') ? 'YES' : 'no');
    if (++$seen > 8) {
        break;
    }
}
echo "Type0 total shown: $seen\n";

// CIDSystemInfo (Registry/Ordering) を持つ descendant を数える
$ord = [];
foreach ($document->getObjects() as $id => $object) {
    $h = $object->getHeader();
    if (! $h->has('CIDSystemInfo')) {
        continue;
    }
    $info = $h->get('CIDSystemInfo');
    $s = method_exists($info, 'getHeader') ? $info->getHeader() : null;
    $o = '?';
    if ($s && $s->has('Ordering')) {
        $o = (string) $s->get('Ordering')->getContent();
    } elseif (method_exists($info, 'getContent')) {
        $c = $info->getContent();
        $o = is_string($c) ? substr($c, 0, 60) : gettype($c);
    }
    $ord[$o] = ($ord[$o] ?? 0) + 1;
}
echo 'CIDSystemInfo Ordering 分布: '.json_encode($ord, JSON_UNESCAPED_UNICODE)."\n";
