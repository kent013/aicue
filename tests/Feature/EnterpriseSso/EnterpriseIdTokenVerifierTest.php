<?php

declare(strict_types=1);

use App\DataTransferObjects\EnterpriseSso\VerifiedIdTokenClaims;
use App\Enums\EnterpriseSso\FingerprintPurpose;
use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
use App\Models\OrganizationOidcConnection;
use App\Services\EnterpriseSso\EnterpriseIdTokenVerifier;
use App\Services\EnterpriseSso\OidcDiscoveryService;
use App\Support\EnterpriseSso\AttemptFingerprint;
use App\ValueObjects\EnterpriseSso\OidcIssuerUrl;
use Tests\Support\EnterpriseSso\FakeIdentityProvider;

/*
 * ID トークンの検証 (B3。deny-by-default)。
 */

const VERIFIER_CLIENT_ID = 'client-1234';
const VERIFIER_NONCE = 'nonce-value';

/**
 * 偽 IdP のトークンを検証する。`$mutate` で claim / 鍵 / alg を壊す。
 */
function verifyIdToken(FakeIdentityProvider $idp, ?string $rawToken = null): VerifiedIdTokenClaims
{
    $connection = OrganizationOidcConnection::factory()->create([
        'issuer' => $idp->issuer,
        'client_id' => VERIFIER_CLIENT_ID,
    ]);

    $discovery = app(OidcDiscoveryService::class);
    $metadata = $discovery->fetchMetadata(OidcIssuerUrl::fromString($idp->issuer));
    $jwks = $discovery->fetchJwks($metadata);

    return app(EnterpriseIdTokenVerifier::class)->verify(
        $connection,
        $metadata,
        $jwks,
        $rawToken ?? $idp->idToken(VERIFIER_CLIENT_ID, VERIFIER_NONCE),
        AttemptFingerprint::of(FingerprintPurpose::Nonce, VERIFIER_NONCE),
    );
}

test('正常なトークンから claim を取り出せる', function (): void {
    $idp = (new FakeIdentityProvider)->install();

    $claims = verifyIdToken($idp);

    expect($claims->subject)->toBe('sub-abc');
    expect($claims->issuer)->toBe($idp->issuer);
    expect($claims->claimedEmail)->toBe('worker@corp.example');
    expect($claims->name)->toBe('現場 太郎');
});

test('JWT の形が壊れているトークンを拒否する', function (string $token): void {
    $idp = (new FakeIdentityProvider)->install();

    expect(fn () => verifyIdToken($idp, $token))
        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
})->with([
    'セグメントが足りない' => ['a.b'],
    'base64url でない' => ['!!!.!!!.!!!'],
    'ヘッダが JSON でない' => ['bm90LWpzb24.e30.sig'],
    '空文字' => [''],
]);

test('ヘッダの alg / kid が規則に合わないトークンを拒否する', function (array $header): void {
    $idp = (new FakeIdentityProvider)->install();
    $token = base64UrlJson($header).'.'.base64UrlJson(['sub' => 'a']).'.c2ln';

    expect(fn () => verifyIdToken($idp, $token))
        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
})->with([
    'alg: none' => [['alg' => 'none', 'kid' => FakeIdentityProvider::KEY_ID]],
    'alg: HS256' => [['alg' => 'HS256', 'kid' => FakeIdentityProvider::KEY_ID]],
    'kid の欠落' => [['alg' => 'RS256']],
    'alg の欠落' => [['kid' => FakeIdentityProvider::KEY_ID]],
]);

test('IdP が広告していない alg のトークンを拒否する (許可集合と広告集合の両方に入ること)', function (): void {
    // アプリの許可集合には在るが、IdP は RS256 しか広告していない
    $idp = (new FakeIdentityProvider)->install();
    $token = base64UrlJson(['alg' => 'RS512', 'kid' => FakeIdentityProvider::KEY_ID]).'.'
        .base64UrlJson(['sub' => 'a']).'.c2ln';

    expect(fn () => verifyIdToken($idp, $token))
        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
});

test('JWKS の鍵が規則に合わないトークンを拒否する', function (array $keyOverrides): void {
    $key = [...FakeIdentityProvider::publicJwk(), ...$keyOverrides];
    $idp = (new FakeIdentityProvider)->withKeys([$key])->install();

    expect(fn () => verifyIdToken($idp))
        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
})->with([
    'kty が alg と不整合' => [['kty' => 'EC']],
    'use が sig でない' => [['use' => 'enc']],
    'key_ops に verify が無い' => [['key_ops' => ['encrypt']]],
]);

test('use と key_ops を持たない鍵は受理される (正のコントロール: optional の欠落で拒否しない)', function (): void {
    $key = FakeIdentityProvider::publicJwk();
    unset($key['use']);
    $idp = (new FakeIdentityProvider)->withKeys([$key])->install();

    expect(verifyIdToken($idp)->subject)->toBe('sub-abc');
});

test('署名が一致しないトークンを拒否する', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    $token = $idp->idToken(VERIFIER_CLIENT_ID, VERIFIER_NONCE);
    // 署名部分だけ差し替える
    $segments = explode('.', $token);
    $segments[2] = rtrim(strtr(base64_encode('tampered-signature'), '+/', '-_'), '=');

    expect(fn () => verifyIdToken($idp, implode('.', $segments)))
        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
});

test('claim の型が違うトークンを拒否する', function (array $claims): void {
    $idp = (new FakeIdentityProvider)->withClaims($claims)->install();

    expect(fn () => verifyIdToken($idp))
        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
})->with([
    'iss が文字列でない' => [['iss' => 123]],
    'sub が文字列でない' => [['sub' => 123]],
    'nonce が文字列でない' => [['nonce' => 123]],
    'aud が数値' => [['aud' => 123]],
    'aud が文字列配列でない' => [['aud' => [1, 2]]],
    'exp が整数でない' => [['exp' => 'soon']],
    'iat が整数でない' => [['iat' => 'now']],
    'nbf が整数でない' => [['nbf' => 'later']],
]);

test('claim の値が規則に合わないトークンを拒否する', function (array $claims): void {
    $idp = (new FakeIdentityProvider)->withClaims($claims)->install();

    expect(fn () => verifyIdToken($idp))
        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
})->with([
    'iss が登録済み issuer と違う' => [['iss' => 'https://other.example.test']],
    'aud に自分がいない' => [['aud' => 'someone-else']],
    '複数 audience で azp が無い' => [['aud' => [VERIFIER_CLIENT_ID, 'other']]],
    'azp が一致しない' => [['aud' => [VERIFIER_CLIENT_ID, 'other'], 'azp' => 'other']],
    'azp が文字列でない' => [['azp' => 123]],
    'exp を過ぎている' => [['exp' => 1]],
    'iat が未来' => [['iat' => 4102444800]],
    'nbf が未来' => [['nbf' => 4102444800]],
    'sub が空' => [['sub' => '']],
    'nonce が一致しない' => [['nonce' => 'other-nonce']],
]);

test('exp / iat の欠落そのものを拒否する', function (array $removed): void {
    $idp = (new FakeIdentityProvider)->withoutClaims($removed)->install();

    expect(fn () => verifyIdToken($idp))
        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
})->with([
    'exp の欠落' => [['exp']],
    'iat の欠落' => [['iat']],
    'nonce の欠落' => [['nonce']],
]);

test('subject の長さの上限を超えるトークンを拒否する', function (): void {
    $idp = (new FakeIdentityProvider)->withClaims(['sub' => str_repeat('a', 256)])->install();

    expect(fn () => verifyIdToken($idp))
        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
});

test('subject に制御文字を含むトークンを拒否する', function (): void {
    $idp = (new FakeIdentityProvider)->withClaims(['sub' => "a\x1Fb"])->install();

    expect(fn () => verifyIdToken($idp))
        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
});

test('複数 audience でも azp が一致すれば受理する (正のコントロール)', function (): void {
    $idp = (new FakeIdentityProvider)
        ->withClaims(['aud' => [VERIFIER_CLIENT_ID, 'other'], 'azp' => VERIFIER_CLIENT_ID])
        ->install();

    expect(verifyIdToken($idp)->subject)->toBe('sub-abc');
});

test('未知の kid では鍵を 1 回だけ取り直す', function (): void {
    $idp = (new FakeIdentityProvider)->install();

    $connection = OrganizationOidcConnection::factory()->create([
        'issuer' => $idp->issuer,
        'client_id' => VERIFIER_CLIENT_ID,
    ]);

    $discovery = app(OidcDiscoveryService::class);
    $metadata = $discovery->fetchMetadata(OidcIssuerUrl::fromString($idp->issuer));

    // 先に「別の kid だけを持つ JWKS」を掴ませる
    $otherKey = [...FakeIdentityProvider::publicJwk(), 'kid' => 'other-key'];
    $idp->withKeys([$otherKey]);
    $staleJwks = $discovery->fetchJwks($metadata);
    $requestsBefore = count($idp->requests);

    // 取り直しでは本来の鍵が返る
    $idp->withKeys([FakeIdentityProvider::publicJwk()]);

    $claims = app(EnterpriseIdTokenVerifier::class)->verify(
        $connection,
        $metadata,
        $staleJwks,
        $idp->idToken(VERIFIER_CLIENT_ID, VERIFIER_NONCE),
        AttemptFingerprint::of(FingerprintPurpose::Nonce, VERIFIER_NONCE),
    );

    expect($claims->subject)->toBe('sub-abc');
    // 再取得はちょうど 1 回である
    expect(count($idp->requests) - $requestsBefore)->toBe(1);
});

test('取り直しても鍵が見つからなければ拒否する (再帰しない)', function (): void {
    $otherKey = [...FakeIdentityProvider::publicJwk(), 'kid' => 'other-key'];
    $idp = (new FakeIdentityProvider)->withKeys([$otherKey])->install();

    expect(fn () => verifyIdToken($idp))
        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
});

/** ヘッダ / payload を base64url の JSON にする。 */
function base64UrlJson(array $value): string
{
    return rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
}
