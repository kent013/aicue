<?php

declare(strict_types=1);

use App\Support\EmailHash;

test('HMAC-SHA256 の hex 文字列を返す (平文 email を含まない)', function (): void {
    $hash = EmailHash::compute('user@example.com');

    expect($hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($hash)->not->toContain('user@example.com');
});

test('大文字小文字・前後空白の違いは同じ hash に収束する', function (): void {
    expect(EmailHash::compute('  User@Example.COM  '))
        ->toBe(EmailHash::compute('user@example.com'));
});

test('異なる email は異なる hash になる', function (): void {
    expect(EmailHash::compute('a@example.com'))
        ->not->toBe(EmailHash::compute('b@example.com'));
});

test('単純 sha256 ではなく app.key で keyed されている', function (): void {
    // key なし sha256 と一致しない = 辞書攻撃耐性の keyed hash であることを固定する。
    expect(EmailHash::compute('user@example.com'))
        ->not->toBe(hash('sha256', 'user@example.com'));
});
