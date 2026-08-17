<?php

declare(strict_types=1);

use App\Support\PasskeyConfigValidator;
use App\Support\PasskeyOriginCanonicalizer;

/*
 * パスキー (WebAuthn) 設定の production 起動時検証 (T166 / T216)。
 *
 * 検査するのは **書式と相互整合**まで。「その host を実際に運用しているか」
 * 「証明書があるか」は検査できない (誇張しない)。
 *
 * T216 以降、**正規形へ寄せるのは宣言側 (config/fortify.php) の責務**で、
 * 本 validator は「正規形からの逸脱を落とす」側に徹する。
 * 例外文には**位置と環境変数名だけ**を出し、設定の生値は出さない
 * (配備ログへ設定値を焼き付けないため)。
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

/** 違反時の例外メッセージを取り出す (例外が出なければテストを失敗させる) */
function passkeyConfigViolationMessage(
    string $relyingPartyId = 'app.example.com',
    ?array $allowedOrigins = null,
    ?array $rawAllowedOrigins = null,
): string {
    try {
        validatePasskeyConfig(
            relyingPartyId: $relyingPartyId,
            allowedOrigins: $allowedOrigins,
            rawAllowedOrigins: $rawAllowedOrigins,
        );
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }

    throw new LogicException('検証が例外を投げなかった (違反系のはずの入力が通っている)');
}

test('有効な設定は例外を投げない', function (): void {
    expect(fn () => validatePasskeyConfig())->not->toThrow(RuntimeException::class);
});

test('接続元が身元の識別子の下位ドメインでも通る', function (): void {
    expect(fn () => validatePasskeyConfig(allowedOrigins: ['https://pwa.app.example.com']))
        ->not->toThrow(RuntimeException::class);
});

test('接続元の port 付き宣言が通る (既定でない port)', function (): void {
    expect(fn () => validatePasskeyConfig(allowedOrigins: ['https://app.example.com:8443']))
        ->not->toThrow(RuntimeException::class);
});

test('punycode の身元の識別子と接続元は通る (国際化ドメインは punycode で書く)', function (): void {
    expect(fn () => validatePasskeyConfig(
        relyingPartyId: 'xn--p1ai.example.com',
        allowedOrigins: ['https://xn--p1ai.example.com'],
    ))->not->toThrow(RuntimeException::class);
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
    // 非 ASCII は punycode 変換をしない = 受理しない (変換結果を誰も検査できない層を作らない)。
    '非 ASCII (キリル文字の а)' => 'аpp.example.com',
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
    ))->toThrow(RuntimeException::class, 'entry #2 is empty');
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
    'path 付き' => 'https://app.example.com/path',
    'userinfo 付き' => 'https://user@app.example.com',
    'query 付き' => 'https://app.example.com?x=1',
    'fragment 付き' => 'https://app.example.com#f',
    '角括弧の IPv6' => 'https://[::1]',
    // punycode で書かせる方針のため、非 ASCII ホストは受理しない。
    '非 ASCII ホスト (キリル文字の а)' => 'https://аpp.example.com',
]);

/*
 * 末尾スラッシュは **宣言側 (config/fortify.php) が正規化して受理する**
 * (裁定 2026-08-04「末尾スラッシュは正規化受理で統一」)。
 * 宣言経路を通った値が末尾スラッシュを含まないことは
 * PasskeyOriginCanonicalizerTest / PasskeyOriginDeclarationTest が固定する。
 * 本 validator へ末尾スラッシュが届くのは「宣言側を通らない経路が設定した」場合だけで、
 * その値は webauthn-lib の strict 比較に一致せず全手続きを無言で失敗させるため落とす。
 */
test('末尾スラッシュが検証器まで届いたら例外 (宣言側を通らない経路の値)', function (): void {
    expect(fn () => validatePasskeyConfig(allowedOrigins: ['https://app.example.com/']))
        ->toThrow(RuntimeException::class, 'is invalid');
});

test('接続元の port が範囲外なら例外', function (string $origin): void {
    expect(fn () => validatePasskeyConfig(allowedOrigins: [$origin]))
        ->toThrow(RuntimeException::class, 'out-of-range port');
})->with([
    'port 0' => 'https://app.example.com:0',
    'port 70000' => 'https://app.example.com:70000',
]);

// --- 検査 5b: 正規形からの逸脱 (既定 port の明示) ---

test('既定 port を明示した接続元は例外 (ブラウザは既定 port を申告しない)', function (string $origin): void {
    expect(fn () => validatePasskeyConfig(allowedOrigins: [$origin]))
        ->toThrow(RuntimeException::class, 'not in canonical form');
})->with([
    'https の既定 port' => 'https://app.example.com:443',
    '下位ドメインの既定 port' => 'https://pwa.app.example.com:443',
]);

/*
 * 正規化器との分担の境界。
 * 正規化器は**ホスト名の妥当性を判断しない**ので、正規化を通しても不正な値は
 * 検証器が拒否し続けなければならない (分担が拒否の抜け道を作っていないことの固定)。
 */
test('正規化を通した後でも不正なホストは拒否され続ける', function (string $origin): void {
    $canonical = PasskeyOriginCanonicalizer::canonicalize($origin);

    expect(fn () => validatePasskeyConfig(allowedOrigins: [$canonical]))
        ->toThrow(RuntimeException::class);
})->with([
    'ハイフン開始' => 'https://-app.example.com:443',
    '連続ドット' => 'https://app..example.com:443',
    '先頭ドット' => 'https://.example.com:443',
    '末尾ドット' => 'https://app.example.com.:443',
    'IPv4 リテラル' => 'https://192.0.2.1:443',
]);

test('ホストの字形が不正なら invalid host として落ちる', function (): void {
    expect(fn () => validatePasskeyConfig(allowedOrigins: ['https://-app.example.com']))
        ->toThrow(RuntimeException::class, 'has an invalid host');
});

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

// --- 例外文の位置表示 ---

test('例外文は 1 始まりの位置で違反箇所を指す (2 件目の違反は #2)', function (): void {
    $message = passkeyConfigViolationMessage(
        allowedOrigins: ['https://app.example.com', 'https://app.example.com:443'],
    );

    expect($message)->toContain('#2')->not->toContain('#1');
});

test('例外文は環境変数名を示す (運用者が自分の .env で特定できる)', function (): void {
    expect(passkeyConfigViolationMessage(allowedOrigins: ['https://app.example.com/path']))
        ->toContain('PASSKEYS_ALLOWED_ORIGINS');
});

/*
 * 例外文に設定の**生値**を載せない (配備ログへ設定値を焼き付けないため)。
 *
 * 値の丸ごとの一致だけを見ない — 部分的な漏れ (ホスト部だけが出る形) も露出であるため、
 * 接続元の文字列全体 / そのホスト部 / 身元の識別子 の 3 つを個別に検査する。
 */
test('違反系の例外文は設定の生値を含まない', function (
    string $relyingPartyId,
    ?array $allowedOrigins,
    ?array $rawAllowedOrigins,
    array $hidden,
): void {
    $message = passkeyConfigViolationMessage(
        relyingPartyId: $relyingPartyId,
        allowedOrigins: $allowedOrigins,
        rawAllowedOrigins: $rawAllowedOrigins,
    );

    foreach ($hidden as $secret) {
        expect($message)->not->toContain($secret);
    }
})->with([
    '書式違反 (path 付き)' => [
        'relyingPartyId' => 'app.example.com',
        'allowedOrigins' => ['https://app.example.com/path'],
        'rawAllowedOrigins' => null,
        'hidden' => ['https://app.example.com/path', 'app.example.com'],
    ],
    'ホスト字形の違反' => [
        'relyingPartyId' => 'app.example.com',
        'allowedOrigins' => ['https://-app.example.com'],
        'rawAllowedOrigins' => null,
        'hidden' => ['https://-app.example.com', '-app.example.com', 'app.example.com'],
    ],
    'port 範囲外' => [
        'relyingPartyId' => 'app.example.com',
        'allowedOrigins' => ['https://app.example.com:0'],
        'rawAllowedOrigins' => null,
        'hidden' => ['https://app.example.com:0', 'app.example.com'],
    ],
    '正規形からの逸脱 (既定 port)' => [
        'relyingPartyId' => 'app.example.com',
        'allowedOrigins' => ['https://app.example.com:443'],
        'rawAllowedOrigins' => null,
        'hidden' => ['https://app.example.com:443', 'app.example.com'],
    ],
    // 相互整合の違反は**接続元のホストと身元の識別子の両方**を隠す。
    '相互整合の違反' => [
        'relyingPartyId' => 'app.example.com',
        'allowedOrigins' => ['https://evil.example.net'],
        'rawAllowedOrigins' => null,
        'hidden' => ['https://evil.example.net', 'evil.example.net', 'app.example.com'],
    ],
    '身元の識別子が DNS 名でない' => [
        'relyingPartyId' => '-example.com',
        'allowedOrigins' => ['https://-example.com'],
        'rawAllowedOrigins' => null,
        'hidden' => ['-example.com'],
    ],
    '生の接続元列の空要素' => [
        'relyingPartyId' => 'app.example.com',
        'allowedOrigins' => ['https://app.example.com'],
        'rawAllowedOrigins' => ['https://app.example.com', ''],
        'hidden' => ['https://app.example.com'],
    ],
]);
