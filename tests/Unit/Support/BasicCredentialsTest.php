<?php

declare(strict_types=1);

use App\Support\EnterpriseSso\BasicCredentials;

/*
 * client_secret_basic の Authorization ヘッダ (B2 / RFC 6749 §2.3.1)。
 *
 * ★仕様は「application/x-www-form-urlencoded の規則で符号化してから `:` で連結し base64」である。
 *   自前の rawurlencode 連結にすると空白・`+`・`:`・非 ASCII で壊れる。
 */

test('RFC 6749 §2.3.1 のとおり符号化される', function (string $id, string $secret, string $expectedPair): void {
    $header = BasicCredentials::header($id, $secret);

    expect($header)->toStartWith('Basic ');
    expect(base64_decode(substr($header, 6), true))->toBe($expectedPair);
})->with([
    '素の値' => ['client', 'secret', 'client:secret'],
    // 空白は `+` (rawurlencode の %20 ではない)
    '空白を含む' => ['my client', 'my secret', 'my+client:my+secret'],
    // `+` はそのままだと空白と解釈されるので %2B へ
    'プラスを含む' => ['a+b', 'c+d', 'a%2Bb:c%2Bd'],
    // 区切りの `:` は必ず符号化する (でないと分割位置がずれる)
    'コロンを含む' => ['a:b', 'c:d', 'a%3Ab:c%3Ad'],
    '非 ASCII' => ['クライアント', 'ひみつ', '%E3%82%AF%E3%83%A9%E3%82%A4%E3%82%A2%E3%83%B3%E3%83%88:%E3%81%B2%E3%81%BF%E3%81%A4'],
]);

test('コロンを含む値でも復号側が分割位置を誤らない', function (): void {
    $header = BasicCredentials::header('a:b', 'c:d');
    $pair = (string) base64_decode(substr($header, 6), true);

    // 符号化済みなので `:` はちょうど 1 つしか現れない = 分割が一意である
    expect(substr_count($pair, ':'))->toBe(1);
});
