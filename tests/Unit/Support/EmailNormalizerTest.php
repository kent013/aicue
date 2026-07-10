<?php

declare(strict_types=1);

use App\Support\EmailNormalizer;

test('前後空白を除去し小文字化する', function (): void {
    expect(EmailNormalizer::normalize('  User@Example.COM  '))->toBe('user@example.com');
});

test('既に正規形の email はそのまま返す', function (): void {
    expect(EmailNormalizer::normalize('user@example.com'))->toBe('user@example.com');
});

test('空文字・空白のみは空文字を返す', function (string $input): void {
    expect(EmailNormalizer::normalize($input))->toBe('');
})->with(['', '   ', "\t\n"]);

test('Unicode を transliterate しない (小文字化のみ)', function (): void {
    // Str::transliterate を使うと Üser → User に collapse し別 user と衝突するため、
    // Unicode はそのまま (小文字化のみ) 保持されることを固定する。
    expect(EmailNormalizer::normalize('Üser@Example.com'))->toBe('üser@example.com');
});
