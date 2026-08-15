<?php

declare(strict_types=1);

use App\Support\PasskeyConfigValidator;

/*
 * パスキー (WebAuthn) 設定の production 起動時検証 (T166)。
 *
 * 検査するのは **書式と相互整合**まで。「その host を実際に運用しているか」
 * 「証明書があるか」は検査できない (誇張しない)。
 */

/** 有効な baseline を作り、指定引数だけ差し替えて検証を実行する */
function validatePasskeyConfig(
    string $relyingPartyId = 'app.example.com',
    ?array $allowedOrigins = null,
    ?array $rawAllowedOrigins = null,
    bool $userHandleSecretDeclared = true,
    ?string $userHandleSecret = null,
): void {
    $allowedOrigins ??= ['https://app.example.com'];
    $rawAllowedOrigins ??= $allowedOrigins;
    $userHandleSecret ??= str_repeat('a', 32);

    (new PasskeyConfigValidator)->validateForProduction(
        $relyingPartyId,
        array_values($allowedOrigins),
        array_values($rawAllowedOrigins),
        $userHandleSecretDeclared,
        $userHandleSecret,
    );
}

test('有効な設定は例外を投げない', function (): void {
    expect(fn () => validatePasskeyConfig())->not->toThrow(RuntimeException::class);
});

test('接続元が身元の識別子の下位ドメインでも通る', function (): void {
    expect(fn () => validatePasskeyConfig(allowedOrigins: ['https://pwa.app.example.com']))
        ->not->toThrow(RuntimeException::class);
});

test('接続元の port 付き宣言が通る', function (): void {
    expect(fn () => validatePasskeyConfig(allowedOrigins: ['https://app.example.com:8443']))
        ->not->toThrow(RuntimeException::class);
});

// --- 検査 1: 身元の識別子が空 ---

test('身元の識別子が空なら例外', function (): void {
    expect(fn () => validatePasskeyConfig(relyingPartyId: ''))
        ->toThrow(RuntimeException::class, 'relying party id is empty');
});

// --- 検査 2: 身元の識別子の DNS 名検査 (負のコントロール) ---

test('身元の識別子が production の DNS 名でないなら例外', function (string $relyingPartyId): void {
    expect(fn () => validatePasskeyConfig(
        relyingPartyId: $relyingPartyId,
        // 接続元は身元の識別子と一致させ、検査 6 ではなく検査 2 で落ちることを確かめる
        allowedOrigins: ['https://'.$relyingPartyId],
    ))->toThrow(RuntimeException::class, 'not an accepted production DNS name');
})->with([
    'localhost (単一ラベル)' => 'localhost',
    'IPv4 リテラル' => '192.0.2.1',
    'IPv4 の書き損じ (filter_var では IP と認められない)' => '192.168.001.001',
    'ハイフン開始' => '-example.com',
    'ハイフン終了' => 'example-.com',
    '連続ドット' => 'example..com',
    '先頭ドット' => '.example.com',
    '末尾ドット' => 'example.com.',
    '空白混じり' => 'exam ple.com',
    'IPv6 リテラル' => '2001:db8::1',
    // 宣言側 (config/fortify.php) は小文字化するので、ここに大文字が届くのは
    // 別経路が未正規化のまま設定した場合だけ。その値は webauthn-lib の strict 比較に
    // 一致せず全手続きを無言で失敗させるため、起動時に落とす。
    '大文字を含む (別経路の未正規化値)' => 'APP.example.com',
]);

/*
 * 既知の限界 (documented limitation)。
 * 本 validator は Public Suffix List を持たないため、`co.uk` のような public suffix を
 * 身元の識別子に置いた設定は通ってしまう (ブラウザ側は PSL を見るので実際の手続きは失敗する)。
 * PSL 判定のために依存を足すことはしない (誤設定の結果は「パスキーが使えない」であって
 * 権限昇格ではなく、設定するのは攻撃者ではなく運用者であるため)。
 * PSL 判定を入れたらこのテストが赤くなり、設計変更に気づける。
 */
test('既知の限界 (documented limitation): public suffix の身元識別子は通る', function (): void {
    expect(fn () => validatePasskeyConfig(
        relyingPartyId: 'co.uk',
        allowedOrigins: ['https://co.uk'],
    ))->not->toThrow(RuntimeException::class);
});

// --- 検査 3: 生の接続元列に空要素 ---

test('生の接続元列に空要素があれば例外 (有効値と併存していても落ちる)', function (): void {
    expect(fn () => validatePasskeyConfig(
        allowedOrigins: ['https://app.example.com'],
        rawAllowedOrigins: ['https://app.example.com', ''],
    ))->toThrow(RuntimeException::class, 'empty entry');
});

// --- 検査 4: 接続元が空 ---

test('接続元が 1 件も無ければ例外', function (): void {
    expect(fn () => validatePasskeyConfig(allowedOrigins: [], rawAllowedOrigins: []))
        ->toThrow(RuntimeException::class, 'allowed origins are empty');
});

// --- 検査 5: 接続元の書式 ---

test('接続元の書式が不正なら例外', function (string $origin): void {
    expect(fn () => validatePasskeyConfig(allowedOrigins: [$origin]))
        ->toThrow(RuntimeException::class, 'is invalid');
})->with([
    '平文 http' => 'http://app.example.com',
    'scheme が大文字' => 'HTTPS://app.example.com',
    'host が大文字' => 'https://APP.example.com',
    '末尾スラッシュ' => 'https://app.example.com/',
    'path 付き' => 'https://app.example.com/path',
    'userinfo 付き' => 'https://user@app.example.com',
    'query 付き' => 'https://app.example.com?x=1',
]);

test('接続元の port が範囲外なら例外', function (string $origin): void {
    expect(fn () => validatePasskeyConfig(allowedOrigins: [$origin]))
        ->toThrow(RuntimeException::class, 'out-of-range port');
})->with([
    'port 0' => 'https://app.example.com:0',
    'port 70000' => 'https://app.example.com:70000',
]);

// --- 検査 6: 身元の識別子と接続元の相互整合 ---

test('接続元が別ドメインなら例外', function (): void {
    expect(fn () => validatePasskeyConfig(allowedOrigins: ['https://evil.example.net']))
        ->toThrow(RuntimeException::class, 'does not belong to');
});

test('接尾辞が一致するだけの host は例外 (接尾辞一致だけの実装なら通ってしまう境界)', function (): void {
    // "notapp.example.com" は ".app.example.com" で終わらないので下位ドメインではない。
    expect(fn () => validatePasskeyConfig(allowedOrigins: ['https://notapp.example.com']))
        ->toThrow(RuntimeException::class, 'does not belong to');
});

// --- 検査 7: 導出鍵 ---

test('導出鍵が未宣言なら例外 (値が十分長くても落ちる)', function (): void {
    expect(fn () => validatePasskeyConfig(
        userHandleSecretDeclared: false,
        userHandleSecret: str_repeat('a', 64),
    ))->toThrow(RuntimeException::class, 'PASSKEYS_USER_HANDLE_SECRET is not set');
});

test('導出鍵が 32 文字未満なら例外', function (): void {
    expect(fn () => validatePasskeyConfig(userHandleSecret: str_repeat('a', 31)))
        ->toThrow(RuntimeException::class, 'shorter than 32 characters');
});

// --- 検査の順序 ---

test('複数違反があるときは最初の違反 (身元の識別子) を報告する', function (): void {
    expect(fn () => validatePasskeyConfig(
        relyingPartyId: '',
        userHandleSecretDeclared: false,
    ))->toThrow(RuntimeException::class, 'relying party id is empty');
});
