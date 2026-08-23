<?php

declare(strict_types=1);

use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
use App\ValueObjects\EnterpriseSso\OidcIssuerUrl;

/*
 * issuer の値オブジェクト (B1)。
 */

test('規則に合わない issuer は型の構築で拒否される', function (string $value): void {
    expect(OidcIssuerUrl::isValid($value))->toBeFalse();

    expect(fn () => OidcIssuerUrl::fromString($value))
        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
})->with([
    'http (平文で秘密が流れる)' => 'http://idp.example.test',
    'userinfo つき' => 'https://user:pass@idp.example.test',
    'query つき' => 'https://idp.example.test?tenant=a',
    'fragment つき' => 'https://idp.example.test#a',
    '相対 URL' => '/idp',
    'スキームなし' => 'idp.example.test',
    '空文字' => '',
    'host なし' => 'https://',
]);

test('https の絶対 URL は受理される', function (string $value): void {
    expect(OidcIssuerUrl::fromString($value)->value)->toBe($value);
})->with([
    'https://idp.example.test',
    'https://idp.example.test/tenant',
    'https://idp.example.test/tenant/',
    'https://idp.example.test:443/tenant',
]);

test('長さの上限を超える issuer は拒否される', function (): void {
    $tooLong = 'https://idp.example.test/'.str_repeat('a', OidcIssuerUrl::MAX_LENGTH);

    expect(OidcIssuerUrl::isValid($tooLong))->toBeFalse();
});

test('末尾スラッシュを正規化しない (別の issuer として扱う)', function (): void {
    $withoutSlash = OidcIssuerUrl::fromString('https://idp.example.test/tenant');
    $withSlash = OidcIssuerUrl::fromString('https://idp.example.test/tenant/');

    expect($withoutSlash->value)->not->toBe($withSlash->value);
    expect($withoutSlash->cacheDigest())->not->toBe($withSlash->cacheDigest());
});

test('well-known の URL は issuer のパスの後ろに付く', function (string $issuer, string $expected): void {
    expect(OidcIssuerUrl::fromString($issuer)->wellKnownUrl())->toBe($expected);
})->with([
    ['https://idp.example.test', 'https://idp.example.test/.well-known/openid-configuration'],
    ['https://idp.example.test/tenant', 'https://idp.example.test/tenant/.well-known/openid-configuration'],
    // 末尾スラッシュがあってもスラッシュを重ねない
    ['https://idp.example.test/tenant/', 'https://idp.example.test/tenant/.well-known/openid-configuration'],
]);

test('キャッシュキーの指紋に URL の平文が残らない', function (): void {
    $issuer = OidcIssuerUrl::fromString('https://idp.example.test/tenant');

    expect($issuer->cacheDigest())->toMatch('/\A[0-9a-f]{64}\z/');
    expect($issuer->cacheDigest())->not->toContain('idp.example.test');
});
