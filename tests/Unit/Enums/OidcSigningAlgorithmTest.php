<?php

declare(strict_types=1);

use App\Enums\EnterpriseSso\OidcSigningAlgorithm;

/*
 * ID トークンの署名方式の許可集合 (A1)。
 *
 * ★許可集合を**型で**表しているので、「拒否の書き忘れ」という失敗様式そのものが無い。
 *   本テストはその形が保たれていることを負のコントロールで固定する。
 */

test('none と対称鍵 (HMAC) は enum の case に存在しない (負のコントロール)', function (string $algorithm): void {
    expect(OidcSigningAlgorithm::tryFrom($algorithm))->toBeNull();
})->with(['none', 'None', 'NONE', 'HS256', 'HS384', 'HS512']);

test('許可する 5 方式がちょうど登録されている', function (): void {
    expect(array_map(
        static fn (OidcSigningAlgorithm $algorithm): string => $algorithm->value,
        OidcSigningAlgorithm::cases(),
    ))->toBe(['RS256', 'RS384', 'RS512', 'ES256', 'ES384']);
});

test('kty と crv が alg と対応している (JWKS の整合検査の土台)', function (): void {
    expect(OidcSigningAlgorithm::Rs256->keyType())->toBe('RSA');
    expect(OidcSigningAlgorithm::Rs256->curve())->toBeNull();
    expect(OidcSigningAlgorithm::Es256->keyType())->toBe('EC');
    expect(OidcSigningAlgorithm::Es256->curve())->toBe('P-256');
    expect(OidcSigningAlgorithm::Es384->curve())->toBe('P-384');
});
