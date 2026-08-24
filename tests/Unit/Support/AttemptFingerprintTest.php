<?php

declare(strict_types=1);

use App\Enums\EnterpriseSso\FingerprintPurpose;
use App\Support\EnterpriseSso\AttemptFingerprint;

/*
 * 一時値の指紋の導出 (A1)。
 */

test('同じ入力でも用途が違えば別の指紋になる (domain separation の実挙動)', function (): void {
    $value = 'same-secret-value';

    $fingerprints = array_map(
        static fn (FingerprintPurpose $purpose): string => AttemptFingerprint::of($purpose, $value),
        FingerprintPurpose::cases(),
    );

    expect(array_unique($fingerprints))->toHaveCount(count(FingerprintPurpose::cases()));
});

test('同じ用途・同じ入力なら同じ指紋になる (決定的である)', function (): void {
    expect(AttemptFingerprint::of(FingerprintPurpose::State, 'abc'))
        ->toBe(AttemptFingerprint::of(FingerprintPurpose::State, 'abc'));
});

test('指紋は 16 進 64 文字である (DB の char(64) と対)', function (): void {
    $fingerprint = AttemptFingerprint::of(FingerprintPurpose::Nonce, 'abc');

    expect(strlen($fingerprint))->toBe(AttemptFingerprint::HEX_LENGTH);
    expect($fingerprint)->toMatch('/\A[0-9a-f]{64}\z/');
});

test('新しい一時値は毎回違い、URL に載せられる形である', function (): void {
    $first = AttemptFingerprint::newSecret();
    $second = AttemptFingerprint::newSecret();

    expect($first)->not->toBe($second);
    expect($first)->toMatch('/\A[A-Za-z0-9_-]+\z/');
});

test('FingerprintPurpose に永続する値の用途が無い (case を名指しで pin する)', function (): void {
    // ★足したら赤くなる。赤くなった時点で AttemptFingerprint の docblock が言う
    //   「APP_KEY 由来の鍵でよい」という根拠 (= 失効するのは短命な値だけ) の
    //   見直しがレビューに出る。
    expect(array_map(
        static fn (FingerprintPurpose $purpose): string => $purpose->value,
        FingerprintPurpose::cases(),
    ))->toBe([
        'enterprise-sso.state',
        'enterprise-sso.nonce',
        'enterprise-sso.browser-binding',
        'auth.email-promotion',
    ]);
});
