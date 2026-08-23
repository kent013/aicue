<?php

declare(strict_types=1);

use App\Enums\EnterpriseSso\OidcSigningAlgorithm;
use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
use App\Services\EnterpriseSso\OidcDiscoveryService;
use App\ValueObjects\EnterpriseSso\OidcIssuerUrl;
use Illuminate\Support\Facades\Cache;
use Kent013\SsrfPin\Dtos\PinnedFailure;
use Kent013\SsrfPin\Enums\SsrfDenyReason;
use Tests\Support\EnterpriseSso\FakeIdentityProvider;

/*
 * 接続先情報と公開鍵の取得 (B1)。
 *
 * ★偽の IdP は **ssrf-pin が出荷している transport の seam** を差し替えるだけである。
 *   `UrlSafetyInspector` は本物が動くので、実装が pin 済み経路を通らなければ
 *   本 fake には 1 件も要求が届かない (= 到達の検証を兼ねる)。
 */

function discoveryService(): OidcDiscoveryService
{
    return app(OidcDiscoveryService::class);
}

function issuerOf(FakeIdentityProvider $idp): OidcIssuerUrl
{
    return OidcIssuerUrl::fromString($idp->issuer);
}

test('実装が PinnedHttpClient を通る (偽の transport に要求が届く)', function (): void {
    $idp = (new FakeIdentityProvider)->install();

    discoveryService()->fetchMetadata(issuerOf($idp));

    expect($idp->requests)->toHaveCount(1);
    expect($idp->requests[0]->url)->toBe($idp->issuer.'/.well-known/openid-configuration');
});

test('issuer が一致しない文書を拒否する', function (): void {
    $idp = (new FakeIdentityProvider)->withMetadata(['issuer' => 'https://evil.example.test'])->install();

    expect(fn () => discoveryService()->fetchMetadata(issuerOf($idp)))
        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
});

test('endpoint が別 origin でも受理する (実在の IdP を拒否しない)', function (): void {
    $idp = (new FakeIdentityProvider)->withMetadata([
        'jwks_uri' => 'https://keys.example.test/jwks',
    ])->install();

    expect(discoveryService()->fetchMetadata(issuerOf($idp))->jwksUri)
        ->toBe('https://keys.example.test/jwks');
});

test('endpoint に query が付いていても受理する (禁じる標準上の根拠が無い)', function (): void {
    $idp = (new FakeIdentityProvider)->withMetadata([
        'token_endpoint' => 'https://idp.example.test/token?tenant=a',
    ])->install();

    expect(discoveryService()->fetchMetadata(issuerOf($idp))->tokenEndpoint)
        ->toBe('https://idp.example.test/token?tenant=a');
});

test('endpoint が規則に合わない文書を拒否する', function (string $key, string $value): void {
    $idp = (new FakeIdentityProvider)->withMetadata([$key => $value])->install();

    expect(fn () => discoveryService()->fetchMetadata(issuerOf($idp)))
        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
})->with([
    'http の token endpoint' => ['token_endpoint', 'http://idp.example.test/token'],
    'userinfo つき' => ['token_endpoint', 'https://u:p@idp.example.test/token'],
    'fragment つき' => ['token_endpoint', 'https://idp.example.test/token#a'],
    '相対 URL' => ['jwks_uri', '/jwks'],
]);

test('パス付きの issuer で well-known の URL が正しく組み立つ', function (): void {
    $idp = (new FakeIdentityProvider('https://idp.example.test/tenant'))->install();

    discoveryService()->fetchMetadata(issuerOf($idp));

    expect($idp->requests[0]->url)
        ->toBe('https://idp.example.test/tenant/.well-known/openid-configuration');
});

test('client 認証方式の欠落は client_secret_basic として受理する (仕様の既定)', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    $metadata = $idp->metadata();
    unset($metadata['token_endpoint_auth_methods_supported']);
    $idp->withBody(json_encode($metadata, JSON_THROW_ON_ERROR));

    expect(discoveryService()->fetchMetadata(issuerOf($idp))->tokenEndpointAuthMethods)
        ->toHaveCount(1);
});

test('client 認証方式が明示されていて対応が無い IdP は拒否する', function (mixed $methods): void {
    $idp = (new FakeIdentityProvider)->withMetadata([
        'token_endpoint_auth_methods_supported' => $methods,
    ])->install();

    expect(fn () => discoveryService()->fetchMetadata(issuerOf($idp)))
        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
})->with([
    '空配列' => [[]],
    '未知値だけ' => [['private_key_jwt']],
]);

test('basic と post の混在では basic が先に来る (body 漏洩面が小さい方を優先)', function (): void {
    $idp = (new FakeIdentityProvider)->withMetadata([
        'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic'],
    ])->install();

    expect(discoveryService()->fetchMetadata(issuerOf($idp))->tokenEndpointAuthMethods[0]->value)
        ->toBe('client_secret_basic');
});

test('署名方式の欠落・空・交わらない集合を拒否する (必須項目である)', function (mixed $algorithms): void {
    $idp = (new FakeIdentityProvider)->withMetadata([
        'id_token_signing_alg_values_supported' => $algorithms,
    ])->install();

    expect(fn () => discoveryService()->fetchMetadata(issuerOf($idp)))
        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
})->with([
    '空配列' => [[]],
    'none だけ' => [['none']],
    'HMAC だけ' => [['HS256']],
]);

test('3xx を成功として扱わない', function (): void {
    $idp = (new FakeIdentityProvider)->withStatus(302)->install();

    expect(fn () => discoveryService()->fetchMetadata(issuerOf($idp)))
        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
});

test('大きすぎる応答を拒否する', function (): void {
    $idp = (new FakeIdentityProvider)->withBody(str_repeat('x', 300000))->install();

    expect(fn () => discoveryService()->fetchMetadata(issuerOf($idp)))
        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
});

test('JSON でない / オブジェクトでない応答を拒否する', function (string $body): void {
    $idp = (new FakeIdentityProvider)->withBody($body)->install();

    expect(fn () => discoveryService()->fetchMetadata(issuerOf($idp)))
        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
})->with(['not json', '"a string"', '12']);

test('取得そのものの失敗は値で返り、固定の理由コードの例外になる', function (): void {
    $idp = (new FakeIdentityProvider)
        ->withTransportFailure(new PinnedFailure(SsrfDenyReason::InvalidHost, 'https://idp.example.test', 0))
        ->install();

    expect(fn () => discoveryService()->fetchMetadata(issuerOf($idp)))
        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
});

test('2 回目の取得はキャッシュから返る (外向きの取得が増えない)', function (): void {
    $idp = (new FakeIdentityProvider)->install();

    discoveryService()->fetchMetadata(issuerOf($idp));
    discoveryService()->fetchMetadata(issuerOf($idp));

    expect($idp->requests)->toHaveCount(1);
});

test('キャッシュ hit でも広告された署名方式が残る (B3 の共通部分が成立する)', function (): void {
    $idp = (new FakeIdentityProvider)->withMetadata([
        'id_token_signing_alg_values_supported' => ['RS256', 'ES256'],
    ])->install();

    discoveryService()->fetchMetadata(issuerOf($idp));
    $cached = discoveryService()->fetchMetadata(issuerOf($idp));

    expect($cached->idTokenSigningAlgorithms)->toHaveCount(2);
    expect($cached->advertises(OidcSigningAlgorithm::Es256))->toBeTrue();
});

test('壊れたキャッシュは forget して取り直す', function (mixed $payload): void {
    $idp = (new FakeIdentityProvider)->install();
    $issuer = issuerOf($idp);

    Cache::put('enterprise-sso:metadata:'.$issuer->cacheDigest(), $payload, 300);

    $metadata = discoveryService()->fetchMetadata($issuer);

    expect($metadata->issuer->value)->toBe($idp->issuer);
    expect($idp->requests)->toHaveCount(1);
})->with([
    '空配列' => [[]],
    '要素が足りない' => [['issuer' => 'https://idp.example.test']],
    '未知の署名方式' => [[
        'issuer' => 'https://idp.example.test',
        'authorization_endpoint' => 'https://idp.example.test/authorize',
        'token_endpoint' => 'https://idp.example.test/token',
        'jwks_uri' => 'https://idp.example.test/jwks',
        'auth_methods' => ['client_secret_basic'],
        'id_token_signing_algorithms' => ['HS256'],
    ]],
]);

test('公開鍵の取得もキャッシュされ、鍵は素の配列で保存される', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    $metadata = discoveryService()->fetchMetadata(issuerOf($idp));

    discoveryService()->fetchJwks($metadata);
    discoveryService()->fetchJwks($metadata);

    // discovery 1 回 + JWKS 1 回 (2 回目はキャッシュ)
    expect($idp->requests)->toHaveCount(2);

    /** @var array<string, array<string, string>> $cached */
    $cached = Cache::get('enterprise-sso:jwks:'.$metadata->issuer->cacheDigest());
    expect($cached)->toBeArray();
    expect($cached[FakeIdentityProvider::KEY_ID]['kty'])->toBe('RSA');
});

test('kid が重複する JWKS を拒否する', function (): void {
    $key = FakeIdentityProvider::publicJwk();
    $idp = (new FakeIdentityProvider)->withKeys([$key, $key])->install();
    $metadata = discoveryService()->fetchMetadata(issuerOf($idp));

    expect(fn () => discoveryService()->fetchJwks($metadata))
        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
});

test('鍵の再取得は最小間隔の内側では起きない (増幅を防ぐ)', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    $metadata = discoveryService()->fetchMetadata(issuerOf($idp));

    discoveryService()->refetchJwks($metadata, connectionId: 1);

    expect(fn () => discoveryService()->refetchJwks($metadata, connectionId: 1))
        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
});

test('鍵の再取得は接続単位のロックで直列化される (同時要求でも 1 回)', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    $metadata = discoveryService()->fetchMetadata(issuerOf($idp));
    $requestsBefore = count($idp->requests);

    // ★ロックを**先に他者が保持している**状態を作る。待たずに拒否されることが要点である
    //   (待つと未知 kid の連打で worker が占有される)。
    $holder = Cache::lock('enterprise-sso:jwks-refetch:1', 15);
    expect($holder->get())->toBeTrue();

    try {
        expect(fn () => discoveryService()->refetchJwks($metadata, connectionId: 1))
            ->toThrow(EnterpriseSsoAttemptRejectedException::class);
    } finally {
        $holder->release();
    }

    // ★拒否された側は外向きの取得を 1 件も行わない (増幅しない)
    expect(count($idp->requests))->toBe($requestsBefore);
});

test('ロックが解放されれば再取得できる (正のコントロール)', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    $metadata = discoveryService()->fetchMetadata(issuerOf($idp));

    $holder = Cache::lock('enterprise-sso:jwks-refetch:1', 15);
    $holder->get();
    $holder->release();

    expect(discoveryService()->refetchJwks($metadata, connectionId: 1)->has(FakeIdentityProvider::KEY_ID))
        ->toBeTrue();
});

test('接続が違えば互いの再取得を止めない (ロックは接続単位である)', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    $metadata = discoveryService()->fetchMetadata(issuerOf($idp));

    $holder = Cache::lock('enterprise-sso:jwks-refetch:1', 15);
    expect($holder->get())->toBeTrue();

    try {
        expect(discoveryService()->refetchJwks($metadata, connectionId: 2)->has(FakeIdentityProvider::KEY_ID))
            ->toBeTrue();
    } finally {
        $holder->release();
    }
});

test('key_ops は完全一致で判定する (notverify を verify とみなさない)', function (array $keyOps): void {
    $key = [...FakeIdentityProvider::publicJwk(), 'key_ops' => $keyOps];
    unset($key['use']);
    $idp = (new FakeIdentityProvider)->withKeys([$key])->install();
    $metadata = discoveryService()->fetchMetadata(issuerOf($idp));
    $jwks = discoveryService()->fetchJwks($metadata);

    expect(fn () => $jwks->keyFor(FakeIdentityProvider::KEY_ID, OidcSigningAlgorithm::Rs256))
        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
})->with([
    '接頭辞つき' => [['notverify']],
    '接尾辞つき' => [['verifying']],
    '大文字' => [['VERIFY']],
    '別の用途だけ' => [['sign']],
]);

test('重複した key_ops を拒否する (意味が無く malformed 寄りなので通さない)', function (): void {
    $key = [...FakeIdentityProvider::publicJwk(), 'key_ops' => ['verify', 'verify']];
    unset($key['use']);
    $idp = (new FakeIdentityProvider)->withKeys([$key])->install();
    $metadata = discoveryService()->fetchMetadata(issuerOf($idp));

    expect(fn () => discoveryService()->fetchJwks($metadata))
        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
});

test('key_ops に verify があれば受理する (正のコントロール)', function (array $keyOps): void {
    $key = [...FakeIdentityProvider::publicJwk(), 'key_ops' => $keyOps];
    unset($key['use']);
    $idp = (new FakeIdentityProvider)->withKeys([$key])->install();
    $metadata = discoveryService()->fetchMetadata(issuerOf($idp));

    expect(discoveryService()->fetchJwks($metadata)
        ->keyFor(FakeIdentityProvider::KEY_ID, OidcSigningAlgorithm::Rs256))
        ->toHaveKey('kty');
})->with([
    '単独' => [['verify']],
    '他と併記' => [['verify', 'wrapKey']],
]);

test('存在する既知の項目の型が違う鍵を拒否する (欠落として捨てない)', function (array $overrides): void {
    $key = [...FakeIdentityProvider::publicJwk(), ...$overrides];
    $idp = (new FakeIdentityProvider)->withKeys([$key])->install();
    $metadata = discoveryService()->fetchMetadata(issuerOf($idp));

    expect(fn () => discoveryService()->fetchJwks($metadata))
        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
})->with([
    'use が配列' => [['use' => ['sig']]],
    'alg が数値' => [['alg' => 256]],
    'kty が配列' => [['kty' => ['RSA']]],
    'key_ops が文字列' => [['key_ops' => 'verify']],
    'key_ops の要素が数値' => [['key_ops' => [1]]],
]);
