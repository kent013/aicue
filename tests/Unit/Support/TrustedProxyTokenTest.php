<?php

declare(strict_types=1);

use App\Support\TrustedProxyToken;

/*
 * TRUSTED_PROXIES の 1 token 判定。config 段の filter と起動時 validator が
 * **同じ関数**を使う前提なので、ここが正しくないと silent drop / 誤 reject の
 * どちらかが必ず起きる。
 */

test('単一 IP / CIDR / REMOTE_ADDR は信頼可能な token', function (string $token): void {
    expect(TrustedProxyToken::isTrustableAddress($token))->toBeTrue();
})->with([
    '10.0.0.0/8',
    '192.168.1.1',
    '172.16.0.0/12',
    '2001:db8::/32',
    '::1',
    '2001:db8::/128',
    TrustedProxyToken::REMOTE_ADDR,
]);

test('書式不正な token は信頼できない (正規表現の緩い判定に落ちない)', function (string $token): void {
    expect(TrustedProxyToken::isTrustableAddress($token))->toBeFalse();
})->with([
    '999.999.999.999/999',
    '10.0.0.0/33',
    '2001:db8::/129',
    '10.0.0.0/',
    '10.0.0.0/abc',
    '10.0.0.0/8/16',
    '*',
    '**',
    // prefix 長 0 の CIDR は全アドレス = `*` と同値 (impl-review R1 Critical)
    '0.0.0.0/0',
    '::/0',
    '0000:0000:0000:0000:0000:0000:0000:0000/0',
    'none',
    'example.com',
    '',
    ' ',
]);

test('isCidr は prefix 長の上限を IP バージョンごとに判定する', function (): void {
    expect(TrustedProxyToken::isCidr('10.0.0.0/32'))->toBeTrue()
        ->and(TrustedProxyToken::isCidr('10.0.0.0/33'))->toBeFalse()
        ->and(TrustedProxyToken::isCidr('2001:db8::/128'))->toBeTrue()
        ->and(TrustedProxyToken::isCidr('2001:db8::/129'))->toBeFalse()
        // prefix 無しの単一 IP は CIDR ではない (isTrustableAddress 側で許可される)
        ->and(TrustedProxyToken::isCidr('10.0.0.1'))->toBeFalse();
});

test('none sentinel は framework に渡す値ではない (空 list へ写すためのマーカー)', function (): void {
    expect(TrustedProxyToken::isTrustableAddress(TrustedProxyToken::NONE))->toBeFalse();
});

test('全アドレス等価の宣言 (* / ** / prefix 0 の CIDR) は isAllAddresses が true', function (string $token): void {
    expect(TrustedProxyToken::isAllAddresses($token))->toBeTrue();
    // framework へ渡す候補からも必ず外れる (fail-secure)
    expect(TrustedProxyToken::isTrustableAddress($token))->toBeFalse();
})->with(['*', '**', '0.0.0.0/0', '::/0', '0000:0000:0000:0000:0000:0000:0000:0000/0']);

test('実 hop の CIDR は isAllAddresses が false', function (string $token): void {
    expect(TrustedProxyToken::isAllAddresses($token))->toBeFalse();
})->with(['10.0.0.0/8', '10.0.0.1', '10.0.0.0/32', '2001:db8::/32', '2001:db8::/128', 'REMOTE_ADDR']);
