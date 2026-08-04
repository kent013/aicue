<?php

declare(strict_types=1);

// 一時調査スクリプト。mbstring の CP1252 レパートリを 256 バイト全走査で確定し、
// PCRE 文字クラスとして表現できることを検証する (実装で正規表現化するための根拠)。

$map = [];
for ($b = 0; $b <= 0xFF; $b++) {
    $ch = @mb_convert_encoding(chr($b), 'UTF-8', 'CP1252');
    if (! is_string($ch) || ! mb_check_encoding($ch, 'UTF-8')) {
        echo sprintf("byte %02X: 変換不能\n", $b);

        continue;
    }
    $back = @mb_convert_encoding($ch, 'CP1252', 'UTF-8');
    $cp = mb_ord($ch, 'UTF-8');
    if ($back !== chr($b)) {
        printf("byte %02X: 往復不一致 (U+%04X -> %s)\n", $b, $cp, bin2hex((string) $back));
    }
    $map[$b] = $cp;
}

$nonLatin1 = array_filter($map, fn (int $cp): bool => $cp > 0xFF);
echo 'U+00FF 超の写像 ('.count($nonLatin1)."件):\n  ";
echo implode(' ', array_map(fn (int $cp): string => sprintf('U+%04X', $cp), $nonLatin1))."\n";

// PCRE クラスでの表現
$class = '[\x{0000}-\x{00FF}'.implode('', array_map(fn (int $cp): string => sprintf('\x{%04X}', $cp), $nonLatin1)).']';
echo "PCRE クラス:\n  $class\n";

// 妥当性: クラスに合致する文字集合 == 往復同一な文字集合 か (BMP 全走査)
$mismatch = 0;
for ($cp = 0; $cp <= 0xFFFF; $cp++) {
    if ($cp >= 0xD800 && $cp <= 0xDFFF) {
        continue;
    }
    $ch = mb_chr($cp, 'UTF-8');
    if (! is_string($ch)) {
        continue;
    }
    $b = @mb_convert_encoding($ch, 'CP1252', 'UTF-8');
    $roundTrip = is_string($b) && @mb_convert_encoding($b, 'UTF-8', 'CP1252') === $ch;
    $matches = (bool) preg_match('/^'.$class.'$/u', $ch);
    if ($roundTrip !== $matches) {
        if ($mismatch < 10) {
            printf("不一致 U+%04X roundTrip=%s class=%s\n", $cp, $roundTrip ? 'Y' : 'n', $matches ? 'Y' : 'n');
        }
        $mismatch++;
    }
}
echo "BMP 全走査の不一致: $mismatch 件\n";
