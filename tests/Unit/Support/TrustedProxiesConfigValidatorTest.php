<?php

declare(strict_types=1);

use App\Support\TrustedProxiesConfigValidator;

/*
 * production 起動時の TRUSTED_PROXIES 検証 (検査 1-5)。
 *
 * 検査の**順序**が load-bearing: `none` sentinel を書式検査より先に処理しないと、
 * `none` 自身が「config 段で落ちた不正値」として reject され、
 * 「プロキシ無し構成の明示宣言」という逃げ道が塞がってしまう。
 */

/** @param list<string> $raw */
function assertProxyValidationFails(array $proxies, array $raw, string $expectedFragment): void
{
    $validator = new TrustedProxiesConfigValidator;

    try {
        $validator->validateForProduction($proxies, $raw);
        expect(false)->toBeTrue('RuntimeException が投げられなかった');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain($expectedFragment);
    }
}

test('検査1: * / ** / prefix 0 の CIDR は全アドレス信頼として reject', function (string $wildcard): void {
    // `0.0.0.0/0` / `::/0` は書式として正当な CIDR だが全アドレスを含む = `*` と同値。
    // 書式検査だけでは通り抜けるため、専用の判定で最優先に落とす (impl-review R1 Critical)
    assertProxyValidationFails([], [$wildcard], 'Trusting every address');
})->with(['*', '**', '0.0.0.0/0', '::/0']);

test('検査1: prefix 0 の CIDR は実 hop と併記していても reject', function (): void {
    assertProxyValidationFails(['10.0.0.0/8'], ['10.0.0.0/8', '0.0.0.0/0'], 'Trusting every address');
});

test('検査1: * は他の値と併記していても reject (最優先で落とす)', function (): void {
    assertProxyValidationFails(['10.0.0.0/8'], ['10.0.0.0/8', '*'], 'Trusting every address');
});

test('検査2: none 単独は正常終了 (プロキシ無し構成の明示宣言)', function (): void {
    $validator = new TrustedProxiesConfigValidator;
    $validator->validateForProduction([], ['none']);

    // 例外が出なければ成功。空要素の混在 (末尾カンマ等) も trim/除外される
    $validator->validateForProduction([], ['none', '', '  ']);
    expect(true)->toBeTrue();
});

test('検査2: none + 他 token は曖昧宣言として reject', function (): void {
    assertProxyValidationFails(['10.0.0.0/8'], ['none', '10.0.0.0/8'], 'must be declared alone');
});

test('検査2: none 宣言なのに proxies が非空なら設定不整合として reject', function (): void {
    assertProxyValidationFails(['10.0.0.0/8'], ['none'], 'resolved proxy list is not empty');
});

test('検査3: REMOTE_ADDR は production では reject', function (): void {
    assertProxyValidationFails(['REMOTE_ADDR'], ['REMOTE_ADDR'], 'REMOTE_ADDR');
});

test('検査4: 書式不正は config 段の silent drop を表面化させて reject', function (): void {
    assertProxyValidationFails(
        ['10.0.0.0/8'],
        ['10.0.0.0/8', '999.999.999.999/99'],
        'invalid value "999.999.999.999/99"',
    );
});

test('検査5: 未設定 (空) は宣言漏れとして reject', function (): void {
    assertProxyValidationFails([], [], 'TRUSTED_PROXIES is not set');
    assertProxyValidationFails([], [''], 'TRUSTED_PROXIES is not set');
});

test('正常系: 実 hop の CIDR 列挙は通過する', function (): void {
    $validator = new TrustedProxiesConfigValidator;
    $validator->validateForProduction(
        ['10.0.0.0/8', '172.16.0.0/12', '2001:db8::/32'],
        ['10.0.0.0/8', '172.16.0.0/12', '2001:db8::/32'],
    );

    expect(true)->toBeTrue();
});
